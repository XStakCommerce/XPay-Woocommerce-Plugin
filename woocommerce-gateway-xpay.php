<?php
/**
 * Plugin Name: WooCommerce XPay Gateway
 * Description: Take credit card payments on your store using XPay.
 * Author: XStak
 * Author URI: https://www.xstak.com/
 * Version: 1.0.7
 * Text Domain: woocommerce-gateway-xpay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


define( 'WCXPAY_PLUGIN_DIR', __DIR__ );
define( 'WCXPAY_PLUGIN_NAME', 'WooCommerce Xpay Gateway' );
define( 'WCXPAY_PLUGIN_DIR_URL', plugin_dir_url( __FILE__ ) );
define( 'WCXPAY_ASSETS_DIR_URL', WCXPAY_PLUGIN_DIR_URL . 'assets/' );
define( 'WCXPAY_PLUGIN_SLUG', plugin_basename( __FILE__ ));

require_once 'env.php';
require_once WCXPAY_PLUGIN_DIR . '/helpers.php';

/**
 * Check if WooCommerce is activated.
 */
if ( true == wxwi_is_woo_active() ) {
	require_once WCXPAY_PLUGIN_DIR . '/includes/class-wcxpay-loader.php';
}
