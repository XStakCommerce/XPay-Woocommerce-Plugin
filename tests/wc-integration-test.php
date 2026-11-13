<?php
// Integration check against a real WordPress + WooCommerce install (any order store). Run from the plugin directory:
//   wp eval-file tests/wc-integration-test.php
// with PHP assertions on: WP_CLI_PHP_ARGS="-d zend.assertions=1 -d assert.exception=1"
// Verified on WC 6.0.0, 6.9.4 (PHP 7.4), 7.1.0, 7.9.0 (PHP 8.1), 8.0.0 (PHP 7.4 and 8.3), 9.8.0 and 11.2.1 (PHP 8.3), legacy posts store and HPOS (sync on and off). Creates throwaway orders/users: use a test site.
// Runs inside a real WordPress + WooCommerce via `wp eval-file`. Exercises the changed code on the active order store.
ob_start(); // keep headers_sent() false in CLI so wp_send_json still calls status_header() (section 10)
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

// 3. Ownership check: owner yes, guest no.
$uid = wp_create_user( 'c' . wp_rand(), 'pw', 'c' . wp_rand() . '@example.test' );
$order->set_customer_id( $uid ); $order->save();
wp_set_current_user( $uid ); assert( $loader->current_visitor_owns_order( $order ) === true, 'owner' );
wp_set_current_user( 0 );    assert( $loader->current_visitor_owns_order( $order ) === false, 'guest' );

// 4. Webhook signature check on a real WP_REST_Request.
$body = wp_json_encode( [ 'status' => 'succeeded', 'payment_intent_id' => $pi, 'transaction_details' => [ '_id' => 'txn_1', 'status_message_from_bank' => 'ok' ] ] );
$mk = function( $sig ) use ( $body ) { $r = new WP_REST_Request( 'POST', '/xpay/v1/update_payment/bank' ); $r->set_body( $body ); if ( $sig ) { $r->set_header( 'X-Signature', $sig ); } return $r; };
$good = hash_hmac( 'sha256', $body, 'whsec_test' ); // XPay signs the raw body
assert( $loader->authenicate_webhook( $mk( $good ) ) === true, 'valid signature' );
assert( $loader->authenicate_webhook( $mk( 'bad' ) ) === false, 'bad signature' );
assert( $loader->authenicate_webhook( $mk( null ) ) === false, 'missing signature' );

// 5. Webhook handler: a failed-payment webhook leaves the order pending; a succeeded one marks it paid.
$failed = new WP_REST_Request( 'POST', '/xpay/v1/update_payment/bank' ); $failed->set_body( str_replace( '"succeeded"', '"requires_payment_method"', $body ) );
$loader->update_payment_status( $failed );
assert( ! wc_get_order( $order->get_id() )->is_paid(), 'failed payment stays unpaid' );
$res = $loader->update_payment_status( $mk( $good ) );
assert( $res instanceof WP_REST_Response && wc_get_order( $order->get_id() )->is_paid(), 'succeeded -> paid' );
assert( wc_get_order( $order->get_id() )->get_transaction_id() === 'txn_1', 'transaction id stored' );

// 6. Route registration: bank route has a permission callback.
$routes = rest_get_server()->get_routes( 'xpay/v1' );
foreach ( [ '/xpay/v1/update_payment', '/xpay/v1/update_payment/bank' ] as $r ) {
	assert( ! empty( $routes[ $r ][0]['permission_callback'] ), "permission callback on $r" );
}

// 7. HPOS compatibility declared.
if ( 'HPOS' === $mode && class_exists( '\Automattic\WooCommerce\Internal\Features\FeaturesController' ) ) { // absent on the experimental pre-7.1 HPOS
	$fc = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
	$c  = $fc->get_compatible_plugins_for_feature( 'custom_order_tables' );
	assert( in_array( 'xpay/woocommerce-gateway-xpay.php', $c['compatible'], true ), 'HPOS compat: ' . wp_json_encode( $c ) );
}
// 8. Guest session ownership through a real Store API (blocks) checkout attempt. XPay HTTP is stubbed to fail, which is enough:
//    the draft order and its session key are created before payment runs.
add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
add_filter( 'pre_http_request', function () { return [ 'response' => [ 'code' => 400 ], 'headers' => [], 'body' => '{"success":false,"message":"stub"}' ]; } );
wp_set_current_user( 0 );
if ( ! WC()->session ) { WC()->initialize_session(); }
WC()->session->set( 'store_api_draft_order', null ); WC()->session->set( 'order_awaiting_payment', null );
$product = new WC_Product_Simple(); $product->set_name( 'T' ); $product->set_regular_price( '10' ); $product->set_virtual( true ); $product->save();
WC()->cart->empty_cart(); WC()->cart->add_to_cart( $product->get_id() );
$addr = [ 'first_name' => 'A', 'last_name' => 'B', 'address_1' => '1 St', 'city' => 'LA', 'state' => 'CA', 'postcode' => '90001', 'country' => 'US', 'email' => 'g@example.test', 'phone' => '1' ];
$req = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
$req->set_body_params( [ 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'xpay' ] );
$res = rest_do_request( $req );
if ( 404 === $res->get_status() ) { // WooCommerce before 7.x shipped the unversioned Store API namespace
	$req = new WP_REST_Request( 'POST', '/wc/store/checkout' ); $req->set_body_params( [ 'billing_address' => $addr, 'shipping_address' => $addr, 'payment_method' => 'xpay' ] ); $res = rest_do_request( $req );
}
$draft_id = (int) WC()->session->get( 'store_api_draft_order' );
assert( $draft_id > 0, 'store api draft order set; status ' . $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
assert( $loader->current_visitor_owns_order( wc_get_order( $draft_id ) ) === true, 'guest owns blocks draft order' );
assert( $loader->current_visitor_owns_order( $order ) === false, 'guest does not own another order' );

// 9. Guest session ownership through classic checkout order creation.
WC()->session->set( 'store_api_draft_order', null );
if ( WC()->cart->is_empty() ) { WC()->cart->add_to_cart( $product->get_id() ); }
WC()->session->set( 'order_awaiting_payment', null );
$classic_id = WC()->checkout()->create_order( [ 'payment_method' => 'xpay', 'billing_first_name' => 'A', 'billing_email' => 'g@example.test', 'billing_country' => 'US' ] );
assert( ! is_wp_error( $classic_id ), 'classic create_order: ' . ( is_wp_error( $classic_id ) ? $classic_id->get_error_message() : '' ) );
// WC_Checkout::process_order_payment() is what stores order_awaiting_payment before handing off to the gateway (XPay stubbed to fail).
$pop = new ReflectionMethod( WC_Checkout::class, 'process_order_payment' ); $pop->setAccessible( true ); $pop->invoke( WC()->checkout(), $classic_id, 'xpay' );
assert( (int) WC()->session->get( 'order_awaiting_payment' ) === (int) $classic_id, 'classic checkout sets order_awaiting_payment' );
assert( $loader->current_visitor_owns_order( wc_get_order( $classic_id ) ) === true, 'guest owns classic order' );

// 10. AJAX endpoint end to end: nonce, ownership, minimal response. wp_die is intercepted so the script survives.
add_filter( 'wp_doing_ajax', '__return_true' ); // wp_send_json only routes through wp_die (interceptable) in AJAX context
add_filter( 'status_header', function ( $h, $code ) { $GLOBALS['xpay_status'] = $code; return $h; }, 10, 2 );
$die = function () { return function ( $m, $t = '', $a = [] ) { if ( null === $GLOBALS['xpay_status'] && ! empty( $a['response'] ) ) { $GLOBALS['xpay_status'] = $a['response']; } throw new RuntimeException( 'die' ); }; };
add_filter( 'wp_die_handler', $die, 999 ); add_filter( 'wp_die_ajax_handler', $die, 999 );
$ajax = function ( $pi, $nonce ) use ( $loader ) {
	$_POST = [ 'xpay_pi' => $pi, 'nonce' => $nonce ]; $_REQUEST = $_POST; $GLOBALS['xpay_status'] = null;
	ob_start(); try { $loader->check_payment_intent_status(); } catch ( RuntimeException $e ) {} $out = ob_get_clean();
	return [ $GLOBALS['xpay_status'], $out ];
};
$classic = wc_get_order( $classic_id ); $classic->update_meta_data( 'xpay_pi', 'pi_classic_' . $classic_id ); $classic->save();
list( $st, $out ) = $ajax( 'pi_classic_' . $classic_id, wp_create_nonce( 'wcxpay_check_payment' ) );
assert( 200 === $st && json_decode( $out, true ) === [ 'success' => false ], "owner with stubbed XPay failure: $st $out" );
list( $st, $out ) = $ajax( $pi, wp_create_nonce( 'wcxpay_check_payment' ) );
assert( 403 === $st, "non-owner must be refused: $st $out" );
list( $st, $out ) = $ajax( 'pi_classic_' . $classic_id, 'badnonce' );
assert( 403 === $st, "bad nonce must be refused: $st $out" );
list( $st, $out ) = $ajax( '../x', wp_create_nonce( 'wcxpay_check_payment' ) );
assert( 400 === $st, "bad id must be refused: $st $out" );

echo "ALL CHECKS PASSED ($mode)\n";
ob_end_flush();
