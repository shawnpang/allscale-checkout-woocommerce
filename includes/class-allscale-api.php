<?php

if (!defined('ABSPATH')) {
    exit;
}

class Allscale_API {

    private $api_key;
    private $api_secret;
    private $base_url;

    private static $currency_map = [
        'USD' => 1,   'AED' => 2,   'AFN' => 3,   'ALL' => 4,   'AMD' => 5,
        'ANG' => 6,   'AOA' => 7,   'ARS' => 8,   'AUD' => 9,   'AWG' => 10,
        'AZN' => 11,  'BAM' => 12,  'BBD' => 13,  'BDT' => 14,  'BGN' => 15,
        'BHD' => 16,  'BIF' => 17,  'BMD' => 18,  'BRL' => 19,  'BSD' => 20,
        'BTN' => 21,  'BWP' => 22,  'BYN' => 23,  'BZD' => 24,  'CAD' => 27,
        'CHF' => 29,  'CLP' => 30,  'CNY' => 31,  'COP' => 32,  'CRC' => 33,
        'CZK' => 36,  'DKK' => 38,  'DOP' => 39,  'DZD' => 40,  'EGP' => 41,
        'ETB' => 43,  'EUR' => 44,  'FJD' => 45,  'GBP' => 48,  'GEL' => 49,
        'GHS' => 51,  'GMD' => 53,  'GTQ' => 55,  'GYD' => 56,  'HKD' => 57,
        'HNL' => 58,  'HRK' => 59,  'HTG' => 60,  'HUF' => 61,  'IDR' => 62,
        'ILS' => 63,  'INR' => 64,  'IQD' => 65,  'ISK' => 67,  'JMD' => 68,
        'JOD' => 69,  'JPY' => 72,  'KES' => 73,  'KGS' => 74,  'KHR' => 75,
        'KRW' => 76,  'KWD' => 77,  'KYD' => 78,  'KZT' => 79,  'LAK' => 80,
        'LBP' => 81,  'LKR' => 82,  'LRD' => 83,  'LSL' => 84,  'MAD' => 87,
        'MDL' => 88,  'MGA' => 89,  'MKD' => 90,  'MMK' => 91,  'MXN' => 92,
        'MYR' => 93,  'MZN' => 94,  'NAD' => 95,  'NGN' => 96,  'NIO' => 97,
        'NOK' => 98,  'NPR' => 99,  'NZD' => 101, 'OMR' => 102, 'PAB' => 103,
        'PEN' => 104, 'PGK' => 105, 'PHP' => 106, 'PKR' => 107, 'PLN' => 108,
        'PYG' => 109, 'QAR' => 110, 'RON' => 111, 'RSD' => 112, 'RUB' => 113,
        'RWF' => 114, 'SAR' => 115, 'SBD' => 116, 'SCR' => 117, 'SEK' => 119,
        'SGD' => 126, 'SLL' => 121, 'SOS' => 122, 'SRD' => 123, 'STN' => 124,
        'SZL' => 125, 'THB' => 127, 'TJS' => 128, 'TMT' => 129, 'TND' => 130,
        'TOP' => 131, 'TRY' => 132, 'TTD' => 133, 'TWD' => 134, 'TZS' => 135,
        'UAH' => 136, 'UGX' => 137, 'UYU' => 139, 'UZS' => 140, 'VES' => 141,
        'VND' => 142, 'VUV' => 143, 'WST' => 144, 'XAF' => 145, 'XCD' => 147,
        'XOF' => 149, 'XPF' => 150, 'YER' => 152, 'ZAR' => 153, 'ZMW' => 154,
    ];

    public function __construct($api_key, $api_secret, $sandbox = true) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        $this->base_url = $sandbox
            ? 'https://openapi-sandbox.allscale.io'
            : 'https://openapi.allscale.io';
    }

    /**
     * Map ISO 4217 currency code to Allscale integer enum.
     */
    public static function get_currency_code($iso_code) {
        $iso_code = strtoupper($iso_code);
        return isset(self::$currency_map[$iso_code]) ? self::$currency_map[$iso_code] : null;
    }

    /**
     * Check if a currency is supported.
     */
    public static function is_currency_supported($iso_code) {
        return self::get_currency_code($iso_code) !== null;
    }

    /**
     * Test API connectivity.
     */
    public function ping() {
        return $this->request('GET', '/v1/test/ping');
    }

    /**
     * Create a checkout intent.
     */
    public function create_checkout_intent($currency_code, $amount_cents, $extra = []) {
        $body = [
            'currency' => $currency_code,
            'amount_cents' => $amount_cents,
        ];

        if (!empty($extra['order_id'])) {
            $body['order_id'] = (string) $extra['order_id'];
        }
        if (!empty($extra['order_description'])) {
            $body['order_description'] = $extra['order_description'];
        }
        if (!empty($extra['extra'])) {
            $body['extra'] = $extra['extra'];
        }

        return $this->request('POST', '/v1/checkout_intents/', $body);
    }

    /**
     * Get checkout intent status (lightweight).
     */
    public function get_checkout_intent_status($intent_id) {
        return $this->request('GET', '/v1/checkout_intents/' . urlencode($intent_id) . '/status');
    }

    /**
     * Make a signed API request.
     */
    private function request($method, $path, $body = null) {
        $timestamp = (string) time();
        $nonce = wp_generate_uuid4();
        $body_str = ($body !== null) ? wp_json_encode($body) : '';
        $body_hash = hash('sha256', $body_str);

        $canonical = implode("\n", [
            $method,
            $path,
            '',  // query string (empty for our use cases)
            $timestamp,
            $nonce,
            $body_hash,
        ]);

        $signature = base64_encode(
            hash_hmac('sha256', $canonical, $this->api_secret, true)
        );

        $headers = [
            'X-API-Key'    => $this->api_key,
            'X-Timestamp'  => $timestamp,
            'X-Nonce'      => $nonce,
            'X-Signature'  => 'v1=' . $signature,
            'Content-Type' => 'application/json',
        ];

        $url = $this->base_url . $path;

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];

        if ($body !== null) {
            $args['body'] = $body_str;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code >= 200 && $status_code < 300 && isset($response_body['payload'])) {
            return [
                'success' => true,
                'data'    => $response_body['payload'],
            ];
        }

        return [
            'success' => false,
            'error'   => isset($response_body['error_message'])
                ? $response_body['error_message']
                : 'API request failed with status ' . $status_code,
            'code'    => isset($response_body['error_code']) ? $response_body['error_code'] : null,
        ];
    }
}
