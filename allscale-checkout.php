<?php
/**
 * Plugin Name: Allscale Checkout for WooCommerce
 * Description: Accept crypto payments via Allscale. Customers pay in fiat, you receive USDT stablecoin instantly.
 * Version: 1.0.0
 * Author: Shawn Pang
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ALLSCALE_CHECKOUT_VERSION', '1.0.0');
define('ALLSCALE_CHECKOUT_PATH', plugin_dir_path(__FILE__));

add_action('plugins_loaded', 'allscale_checkout_init');

function allscale_checkout_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>Allscale Checkout</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-allscale-api.php';
    require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-allscale-webhook.php';
    require_once ALLSCALE_CHECKOUT_PATH . 'includes/class-allscale-gateway.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'Allscale_Gateway';
        return $gateways;
    });
}
