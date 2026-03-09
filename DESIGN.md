# Allscale Checkout WooCommerce Plugin — Design Document

## Architecture Overview

The plugin follows standard WordPress/WooCommerce plugin conventions: a main entry point file that registers a WooCommerce payment gateway class, supported by helper classes for API communication, webhook handling, and admin settings.

```
allscale-checkout/
├── allscale-checkout.php              # Plugin entry point (bootstrap, hooks, activation)
├── includes/
│   ├── class-allscale-gateway.php     # WooCommerce payment gateway (extends WC_Payment_Gateway)
│   ├── class-allscale-api.php         # Allscale API client (HMAC signing, HTTP requests)
│   ├── class-allscale-webhook.php     # Webhook receiver and signature verification
│   └── class-allscale-logger.php      # Logging wrapper (uses WC_Logger)
├── assets/
│   ├── css/
│   │   └── allscale-admin.css         # Admin settings page styling
│   └── images/
│       └── allscale-logo.png          # Logo for checkout display
├── templates/
│   ├── checkout-redirect.php          # "Redirecting to Allscale..." interstitial page
│   └── payment-info.php              # Order thank-you page payment details
├── languages/
│   └── allscale-checkout.pot          # Translation template
├── README.md
├── DESIGN.md
├── LICENSE
└── .gitignore
```

## Component Design

### 1. Plugin Entry Point (`allscale-checkout.php`)

**Responsibilities:**
- Plugin header metadata (name, version, description, author, license)
- Check for WooCommerce dependency on activation
- Register the payment gateway class with WooCommerce's `woocommerce_payment_gateways` filter
- Load text domain for translations
- Register activation/deactivation hooks
- Enqueue admin assets

**Key hooks:**
```php
add_filter('woocommerce_payment_gateways', 'add_allscale_gateway');
add_action('plugins_loaded', 'init_allscale_gateway');
register_activation_hook(__FILE__, 'allscale_activate');
```

### 2. Payment Gateway (`class-allscale-gateway.php`)

Extends `WC_Payment_Gateway` — this is the core class that WooCommerce interacts with.

**Properties (admin-configurable):**
| Setting | Type | Description |
|---------|------|-------------|
| `enabled` | yes/no | Enable/disable the gateway |
| `title` | text | Name shown at checkout (e.g., "Pay with Crypto") |
| `description` | textarea | Description shown at checkout |
| `api_key` | text | Allscale public API key |
| `api_secret` | password | Allscale API secret (stored encrypted) |
| `environment` | select | `sandbox` or `production` |
| `currency` | select | Fiat currency for pricing (USD, EUR, etc.) |
| `debug` | yes/no | Enable debug logging |

**Key methods:**

| Method | Purpose |
|--------|---------|
| `__construct()` | Set gateway ID, title, load settings, init webhook listener |
| `init_form_fields()` | Define admin settings fields |
| `process_payment($order_id)` | Create Allscale checkout intent, return redirect URL |
| `get_icon()` | Return Allscale logo for checkout display |
| `is_available()` | Check if gateway is configured and enabled |
| `process_admin_options()` | Validate and save settings, encrypt API secret |

**Payment flow in `process_payment()`:**
```
1. Load WC_Order by $order_id
2. Build checkout intent payload:
   - currency: mapped to Allscale integer enum
   - amount_cents: order total × 100
   - order_id: WC order number
   - order_description: summary of items
   - extra: { wc_order_id, return_url, cancel_url }
3. Call Allscale API to create checkout intent
4. Store allscale_checkout_intent_id as order meta
5. Mark order as "pending payment"
6. Return { result: 'success', redirect: checkout_url }
```

### 3. API Client (`class-allscale-api.php`)

Handles all communication with the Allscale API, including HMAC-SHA256 request signing.

**Configuration:**
- Base URLs: `https://openapi-sandbox.allscale.io` (sandbox), `https://openapi.allscale.io` (production)
- Timeout: 30 seconds
- User-Agent: `AllscaleWooCommerce/{version}`

**Methods:**

| Method | Purpose |
|--------|---------|
| `create_checkout_intent($params)` | POST /v1/checkout_intents/ |
| `get_checkout_intent($id)` | GET /v1/checkout_intents/{id} |
| `get_checkout_intent_status($id)` | GET /v1/checkout_intents/{id}/status |
| `ping()` | GET /v1/test/ping (connectivity test) |
| `sign_request($method, $path, $query, $body)` | Generate HMAC-SHA256 signature |

**HMAC signing implementation:**
```
canonical_string = join("\n", [
    HTTP_METHOD,        // "POST"
    URL_PATH,           // "/v1/checkout_intents/"
    QUERY_STRING,       // "" for POST
    TIMESTAMP,          // Unix seconds
    NONCE,              // wp_generate_uuid4()
    SHA256(BODY)        // hash of JSON body, or hash of "" for GET
])

signature = base64_encode(hmac_sha256(api_secret, canonical_string))

Headers:
  X-API-Key: {api_key}
  X-Timestamp: {timestamp}
  X-Nonce: {nonce}
  X-Signature: v1={signature}
```

**HTTP transport:** Uses `wp_remote_post()` / `wp_remote_get()` (WordPress HTTP API) — no external dependencies needed.

### 4. Webhook Handler (`class-allscale-webhook.php`)

Receives POST callbacks from Allscale when payment status changes.

**Endpoint registration:**
```php
// Registers at: https://yoursite.com/wc-api/allscale_checkout
add_action('woocommerce_api_allscale_checkout', [$this, 'handle_webhook']);
```

**Webhook processing flow:**
```
1. Read raw POST body and headers
2. Extract: X-Webhook-Id, X-Webhook-Timestamp, X-Webhook-Nonce, X-Webhook-Signature
3. Validate timestamp is within ±5 minutes
4. Build webhook canonical string:
   "allscale:webhook:v1\nPOST\n/wc-api/allscale_checkout\n\n{webhook_id}\n{timestamp}\n{nonce}\n{sha256_body}"
5. Compute expected signature using HMAC-SHA256 with API secret
6. Timing-safe comparison (hash_equals) of signatures
7. Parse JSON body
8. Look up WC order by allscale_checkout_intent_id (stored in order meta)
9. Update order status based on Allscale status
10. Return HTTP 200
```

**Status mapping:**

| Allscale Status | Code | WooCommerce Action |
|----------------|------|--------------------|
| CONFIRMED | 20 | `$order->payment_complete()` → Processing |
| ON_CHAIN | 10 | Add order note ("Payment detected on-chain, awaiting confirmation") |
| FAILED | -1 | `$order->update_status('failed')` |
| REJECTED | -2 | `$order->update_status('failed')` + note about KYT rejection |
| UNDERPAID | -3 | `$order->update_status('on-hold')` + note with paid vs expected amount |
| CANCELED | -4 | `$order->update_status('cancelled')` |

**Security measures:**
- Timing-safe signature comparison (`hash_equals()`)
- Timestamp validation (reject if >5 min drift)
- Nonce deduplication via WordPress transients (10-minute TTL)
- Idempotency: don't re-process if order is already in a terminal state

### 5. Logger (`class-allscale-logger.php`)

Thin wrapper around `WC_Logger` for consistent log formatting.

```php
// Usage: Allscale_Logger::info('Checkout intent created', ['intent_id' => '...']);
// Logs to: WooCommerce → Status → Logs → allscale-checkout-{date}
```

Log levels: `debug`, `info`, `warning`, `error`. Only logs when debug mode is enabled (except errors, which always log).

## Data Flow Diagrams

### Checkout Flow

```
Customer                WooCommerce              Allscale API           Allscale Checkout Page
   │                        │                        │                        │
   ├─ Place order ─────────>│                        │                        │
   │                        ├─ POST /checkout_intents ──>│                    │
   │                        │<── checkout_url ───────────┤                    │
   │                        ├─ Store intent_id meta  │                        │
   │                        ├─ Mark "pending payment"│                        │
   │<── Redirect to URL ────┤                        │                        │
   │                        │                        │                        │
   ├─ (customer pays) ──────────────────────────────────────────────────────>│
   │                        │                        │                        │
   │                        │<── Webhook POST ───────┤                        │
   │                        ├─ Verify signature      │                        │
   │                        ├─ Update order status   │                        │
   │                        │                        │                        │
   │<── Redirect back ──────────────────────────────────────────────────────┤
   │                        │                        │                        │
   ├─ View order confirm ──>│                        │                        │
```

### Return URL Handling

After payment, Allscale redirects the customer back. The return URL is the WooCommerce order-received (thank you) page:

```
https://yoursite.com/checkout/order-received/{order_id}/?key={order_key}
```

The plugin passes this URL via the `extra` field when creating the checkout intent. On the thank-you page, the plugin can optionally poll the Allscale API to show real-time payment status if the webhook hasn't arrived yet.

## Currency Mapping

The plugin maintains a mapping from ISO 4217 currency codes to Allscale integer enums:

```php
private static $currency_map = [
    'USD' => 1,   'AUD' => 9,   'BRL' => 19,  'CAD' => 27,
    'CNY' => 31,  'EUR' => 44,  'GBP' => 48,  'HKD' => 57,
    'INR' => 63,  'JPY' => 72,  'KRW' => 76,  'MXN' => 92,
    'NZD' => 101, 'SGD' => 126, 'CHF' => 29,  // ... etc.
];
```

The plugin auto-detects the WooCommerce store currency and maps it. If the currency isn't supported, the gateway disables itself and shows a notice.

## Security Considerations

1. **API secret storage** — Encrypted using `wp_encrypt()` (WP 6.1+) or stored with WordPress options API as a fallback. Never exposed in HTML source or logs.
2. **Server-side only** — All API signing happens in PHP on the server. No secrets are ever sent to the browser.
3. **Webhook verification** — Full HMAC-SHA256 signature verification with timing-safe comparison.
4. **Nonce deduplication** — Prevents replay attacks on webhooks.
5. **Amount validation** — The plugin verifies the webhook's `amount_cents` matches the WC order total before marking as paid.
6. **HTTPS required** — The plugin warns if the site doesn't use HTTPS (webhooks should only go to HTTPS endpoints).

## Error Handling

| Scenario | Behavior |
|----------|----------|
| Allscale API unreachable | Show error to customer, don't create order. Log error. |
| Invalid API credentials | Gateway disables itself, admin notice shown. |
| Webhook signature invalid | Return HTTP 401, log warning. |
| Webhook for unknown order | Return HTTP 200 (acknowledge), log warning. |
| Customer returns before webhook | Thank-you page polls status API every 5s for up to 2 minutes. |
| Duplicate webhook | Idempotent — if order already completed, just return 200. |

## Testing Strategy

1. **Sandbox mode** — Use Allscale sandbox environment for end-to-end testing.
2. **Ping test** — Admin settings page has a "Test Connection" button that calls GET /v1/test/ping.
3. **Webhook test** — Admin page shows the webhook URL and a button to send a test webhook.
4. **WooCommerce test mode** — Works alongside WooCommerce's built-in test/staging modes.

## Future Enhancements (Out of Scope for v1)

- Block-based checkout support (WooCommerce Blocks)
- Subscription/recurring payments
- Partial refund handling
- Multi-currency with automatic conversion
- Payment status admin dashboard widget
- On-chain transaction details in order view (tx hash, explorer link)
