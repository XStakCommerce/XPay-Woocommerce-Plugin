<?php
// Integration check against a real WordPress + WooCommerce install (any order store). Run from the plugin directory:
//   wp eval-file tests/wc-integration-test.php
// with PHP assertions on: WP_CLI_PHP_ARGS="-d zend.assertions=1 -d assert.exception=1"
// Verified on WC 6.0.0, 6.9.4 (PHP 7.4), 7.1.0, 7.9.0 (PHP 8.1), 8.0.0 (PHP 7.4 and 8.3), 9.8.0 and 11.2.1 (PHP 8.3), legacy posts store and HPOS (sync on and off). Creates throwaway orders/users: use a test site.
// Runs inside a real WordPress + WooCommerce via `wp eval-file`. Exercises the changed code on the active order store.
$mode = ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) ? 'HPOS' : 'CPT';
echo "Order store: $mode\n";
update_option( 'woocommerce_xpay_settings', [ 'enabled' => 'yes', 'mode' => 'TEST', 'test_webhook_hmac_secret' => 'whsec_test', 'debug_log' => 'no' ] );
WC()->payment_gateways()->init(); // rebuild gateway objects so they read the settings saved above
$loader = new WCXPAY_Loader();
$pi = 'pi_' . strtolower( $mode ) . '_' . wp_rand();

// 1. Meta round trip through the CRUD API (gateway create_intent path).
$order = wc_create_order();
$order->update_meta_data( 'xpay_pi', $pi );
$order->update_meta_data( 'xpay_pi_data', [ '_id' => $pi, 'pi_client_secret' => 'cs' ] );
$order->save();
$re = wc_get_order( $order->get_id() );
assert( $re->get_meta( 'xpay_pi_data' )['_id'] === $pi, 'meta round trip' );

// 2. Order lookup by intent id on this store.
$ids = $loader->get_order_ids_by_intent( $pi );
assert( array_map( 'intval', $ids ) === [ $order->get_id() ], 'lookup: ' . wp_json_encode( $ids ) );
assert( $loader->get_order_ids_by_intent( 'pi_unknown' ) === [], 'unknown lookup' );

// 3. Webhook signature check on a real WP_REST_Request.
$body = wp_json_encode( [ 'status' => 'succeeded', 'payment_intent_id' => $pi, 'transaction_details' => [ '_id' => 'txn_1', 'status_message_from_bank' => 'ok' ] ] );
$mk = function( $sig ) use ( $body ) { $r = new WP_REST_Request( 'POST', '/xpay/v1/update_payment/bank' ); $r->set_body( $body ); if ( $sig ) { $r->set_header( 'X-Signature', $sig ); } return $r; };
$good = hash_hmac( 'sha256', $body, 'whsec_test' ); // XPay signs the raw body
assert( $loader->authenicate_webhook( $mk( $good ) ) === true, 'valid signature' );
assert( $loader->authenicate_webhook( $mk( 'bad' ) ) === false, 'bad signature' );
assert( $loader->authenicate_webhook( $mk( null ) ) === false, 'missing signature' );

// 4. Webhook handler: a failed-payment webhook leaves the order pending; a succeeded one marks it paid.
$failed = new WP_REST_Request( 'POST', '/xpay/v1/update_payment/bank' ); $failed->set_body( str_replace( '"succeeded"', '"requires_payment_method"', $body ) );
$loader->update_payment_status( $failed );
assert( ! wc_get_order( $order->get_id() )->is_paid(), 'failed payment stays unpaid' );
$res = $loader->update_payment_status( $mk( $good ) );
assert( $res instanceof WP_REST_Response && wc_get_order( $order->get_id() )->is_paid(), 'succeeded -> paid' );
assert( wc_get_order( $order->get_id() )->get_transaction_id() === 'txn_1', 'transaction id stored' );

// 5. Route registration: bank route has a permission callback.
$routes = rest_get_server()->get_routes( 'xpay/v1' );
foreach ( [ '/xpay/v1/update_payment', '/xpay/v1/update_payment/bank' ] as $r ) {
	assert( ! empty( $routes[ $r ][0]['permission_callback'] ), "permission callback on $r" );
}

// 6. HPOS compatibility declared.
if ( 'HPOS' === $mode && class_exists( '\Automattic\WooCommerce\Internal\Features\FeaturesController' ) ) { // absent on the experimental pre-7.1 HPOS
	$fc = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
	$c  = $fc->get_compatible_plugins_for_feature( 'custom_order_tables' );
	assert( in_array( 'xpay/woocommerce-gateway-xpay.php', $c['compatible'], true ), 'HPOS compat: ' . wp_json_encode( $c ) );
}
// 7. No public payment-status AJAX action (OraSec API VID-005): neither the logged-in nor the logged-out hook exists.
assert( false === has_action( 'wp_ajax_nopriv_wcxpay_check_payment' ), 'nopriv status check removed' );
assert( false === has_action( 'wp_ajax_wcxpay_check_payment' ), 'status check removed' );

echo "ALL CHECKS PASSED ($mode)\n";
