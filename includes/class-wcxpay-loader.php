<?php
/**
 * WCXPAY loader Class File.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

if ( ! class_exists( 'WCXPAY_Loader' ) ) {

	/**
	 * Saw class.
	 */
	class WCXPAY_Loader {

		/**
		 * Constructor.
		 */
		public function __construct() {

			
			add_action('plugins_loaded', [ $this, 'includes' ] );
			add_filter('woocommerce_payment_gateways', [$this, 'add_payment_gateway']);
            add_filter('plugin_action_links_' . WCXPAY_PLUGIN_SLUG, array($this, 'xpay_settings_link'));
			add_action('woocommerce_blocks_loaded', array( $this,'add_woocommerce_blocks_support') );
			add_action('wp_head', array( $this, 'add_xpay_sdk_script'));
			add_action('rest_api_init', function () {
				register_rest_route( 'xpay/v1', 'update_payment', array(
				  'methods' => 'POST',
				  'callback' => array( $this, 'update_payment_status'),
				  'permission_callback' => array( $this, 'authenicate_webhook'),
				) );
				register_rest_route( 'xpay/v1', 'update_payment/bank', array(
				  'methods' => 'POST',
				  'callback' => array( $this, 'update_payment_status'),
				  'permission_callback' => array( $this, 'authenicate_webhook'),
				) );
			});
			
			add_action( 'wp_ajax_wcxpay_check_payment', array( $this, 'check_payment_intent_status' ) );
			add_action( 'wp_ajax_nopriv_wcxpay_check_payment', array( $this, 'check_payment_intent_status' ) );

			add_action('wc_xpay_api_error', [$this, 'send_error_notification'], 10, 4);
		}

		/**
         * Include all classes.
         */

		function includes(){

			require_once WCXPAY_PLUGIN_DIR .'/includes/class-wc-gateway-xpay.php';
            require_once WCXPAY_PLUGIN_DIR .'/includes/blocks/class-payment-method-xpay.php';
			require_once 'class-xpay-api.php';
		}

		/**
		 * Add the gateways to WooCommerce.
		 * @param array  $gateways array of payment gateway.
		 * 
		 * return array
		 */
		function add_payment_gateway( $gateways ) {

        //    include_once 'class-wc-gateway-xpay.php';
            $gateways[] = 'WC_Gateway_Xpay';
            return $gateways;
        }

		/**
		 * Add plugin action links.
		 * @param array  $links array of plugin actions.
		 * 
		 * return array
		 */
        function xpay_settings_link( $links ) {

            $url = esc_url(add_query_arg(
                    'page',
                    'wc-settings&tab=checkout&section=xpay',
                    get_admin_url() . 'admin.php'
                ));
            $settings_link = "<a href='$url'>" . __('Configure') . '</a>';
            
            array_push(
                $links,
                $settings_link
            );
            return $links;
        }

		
        /**
         * Registers WooCommerce Blocks integration.
         *
         */
        function add_woocommerce_blocks_support() {

            if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
				
                add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function( PaymentMethodRegistry $payment_method_registry ) {
                  $payment_method_registry->register( new WCXPAY_BlockPaymentMethod );
                } );
				
            }
        } 
		
		/**
		 * This is an external library/sdk that is needed to run XPay.
		 */
		function add_xpay_sdk_script(){

			$xpay_settings = get_option('woocommerce_xpay_settings', []);
			$mode          = isset( $xpay_settings['mode'] ) ? $xpay_settings['mode'] : 'TEST';
			$xpay_sdk_url  = ( 'LIVE' === $mode ) ? 'https://js.xstak.com/v5/xpay.js' : 'https://js.xstak.com/v5/xpay-stage.js';
			?><script defer src="<?php echo esc_url( $xpay_sdk_url ); ?>"></script><?php
		}

		

		/**
		 *  Authenticate the webhook request.
		 *  @param WP_REST_Request $request
		 * 
		 * @return bool 
		 */
		function authenicate_webhook( $request ){
			
			$signature_header  = $request->get_header('X-Signature');

			$body = $request->get_body();

			$wc_xpay_gateway     = WC()->payment_gateways->payment_gateways()['xpay'];
			$webhook_hmac_secret = $wc_xpay_gateway->get_webhook_hmac_secret();

			if ( empty( $webhook_hmac_secret ) || empty( $signature_header ) || '' === $body ) {
				return false;
			}

			// XPay signs the exact bytes it sends, so sign the raw body (re-encoding the JSON can change it).
			$signature = hash_hmac( 'sha256', $body, $webhook_hmac_secret );

			return hash_equals( $signature, (string) $signature_header );

		}
		/**
		 *  Webhook callback function to update order payment status.
		 *  @param WP_REST_Request $request
		 * 
		 * @return WP_REST_Response 
		 */
		function update_payment_status( $request ){

			$body = $request->get_body();
			$data = json_decode($body);
		
			// Log webhook payload data if enabled
			$wc_xpay_gateway = WC()->payment_gateways->payment_gateways()['xpay'];
			if( $wc_xpay_gateway->get_option( 'debug_log' ) == 'yes' ){
				$logger = wc_get_logger();
        		$log_entry = print_r($body, 1);
        		$logger->info( $log_entry, array( 'source' => WCXPAY_LOG_FILENAME ) );
			}
			
			if( !$data ){
				return new WP_Error( '400', 'Empty payload' );
			}

			if( $data->status == 'succeeded'){

				$xpay_pi = isset( $data->payment_intent_id ) ? (string) $data->payment_intent_id : '';
				if( ! preg_match( '/^[A-Za-z0-9_-]+$/', $xpay_pi ) ){
					return new WP_Error( '400', 'Invalid payment intent id.' );
				}

				$order_ids = $this->get_order_ids_by_intent( $xpay_pi );
				
				if(empty($order_ids)){
					return new WP_Error( '404', 'Could not find order for the payment.' );
				}
				
				$order_id = $order_ids[0];
				$order = wc_get_order($order_id);

				if( !$order ){
					return new WP_Error( '404', 'Order not found.' );
				}

				if( $order->is_paid() ){
					return new WP_REST_Response([ 'success' => true, 'message' => 'Order already paid.' ] );
				}

				$transaction         = $data->transaction_details;
				$xpay_transaction_id = $transaction->_id;
				$note                = apply_filters( 'wc_xpay_successful_payment_note', 'Response from bank: '.$transaction->status_message_from_bank, $data, $order );
				$order->add_order_note($note);
				$order->payment_complete( $xpay_transaction_id );

				return new WP_REST_Response([
					'success' => true,
					'message' => 'Order payment updated successfully.'
				] );

            }
        }

		/**
         * Callback handler of check PI status.
         */
		function check_payment_intent_status(){

			check_ajax_referer( 'wcxpay_check_payment', 'nonce' );

			$xpay_pi = isset( $_POST['xpay_pi'] ) ? sanitize_text_field( wp_unslash( $_POST['xpay_pi'] ) ) : '';
			if( ! preg_match( '/^[A-Za-z0-9_-]+$/', $xpay_pi ) ){
				wp_send_json( [ 'success' => false ], 400 );
			}

			$order_ids = $this->get_order_ids_by_intent( $xpay_pi );
			$order     = empty( $order_ids ) ? false : wc_get_order( $order_ids[0] );
			if( ! $order || ! $this->current_visitor_owns_order( $order ) ){
				wp_send_json( [ 'success' => false ], 403 );
			}

			wp_send_json( [ 'success' => ! empty( $this->fetch_intent( $xpay_pi ) ) ], 200 );
		}

		/**
		 * Order ids that carry the given payment intent id.
		 * @param string $xpay_pi
		 *
		 * @return array
		 */
		function get_order_ids_by_intent( $xpay_pi ){
			// wc_get_orders works on both the legacy posts store and HPOS.
			return wc_get_orders( [ 'limit' => 1, 'return' => 'ids', 'meta_key' => 'xpay_pi', 'meta_value' => $xpay_pi ] );
		}

		/**
		 * The visitor may only query the order they are currently paying for, or one they own.
		 * @param WC_Order $order
		 *
		 * @return bool
		 */
		function current_visitor_owns_order( $order ){
			$session_order_ids = [];
			if( WC()->session ){
				$session_order_ids = [ WC()->session->get( 'order_awaiting_payment' ), WC()->session->get( 'store_api_draft_order' ) ];
			}
			if( in_array( $order->get_id(), array_map( 'intval', array_filter( $session_order_ids ) ), true ) ){
				return true;
			}
			return is_user_logged_in() && $order->get_customer_id() === get_current_user_id();
		}

		/**
		 * Look the intent up on XPay with the merchant secret key.
		 * @param string $xpay_pi
		 *
		 * @return array intent data, empty when XPay does not know the intent.
		 */
		function fetch_intent( $xpay_pi ){
			$wc_xpay_gateway = WC()->payment_gateways->payment_gateways()['xpay'];
			$xpay_api        = new XPay_API( $wc_xpay_gateway->get_acccounId(), $wc_xpay_gateway->get_secret_key(), $wc_xpay_gateway->get_hmac_secret(), $wc_xpay_gateway->get_option( 'mode', 'TEST' ), $wc_xpay_gateway->get_option( 'debug_log' ) );
			$intent_response = $xpay_api->get_intent( $xpay_pi );
			return ( ! empty( $intent_response['success'] ) && ! empty( $intent_response['data'] ) ) ? (array) $intent_response['data'] : [];
		}

		/**
		 * Send email notification of the XPay API errors if configured.
		 * @param array $response
		 * @param string $request_url
		 * @param array $headets
		 * @param string|array $body
		 * 
		 */
		function send_error_notification( $response, $request_url, $headers, $body  ){
			
			$wc_xpay_gateway = WC()->payment_gateways->payment_gateways()['xpay'];
			$error_notification_recipients = $wc_xpay_gateway->get_option( 'error_notification_recipient' );

			if( empty($error_notification_recipients)){
				return ;  // No notification recipients.
			}
			$error_emails = explode( ',',$error_notification_recipients);
			$filtered_error_emails = array_filter( $error_emails, function( $v ) { return  filter_var($v, FILTER_VALIDATE_EMAIL); } );
			
			$message = "A new XPay API error occured on the site.\n\n" ;
			$message .=print_r($response, 1);
			$message .= "================\n";
			$message .= "REQUEST DETAILS:\n";
			$message .= "================\n";
			$message .= "URL: ".$request_url."\n";
			$message .= "Headers: ".print_r($headers,1)."\n";
			$message .= "Body: ".print_r($body,1)."\n";
			wp_mail(implode(',',$filtered_error_emails), 'New XPAY API error', $message);
			
		}
	}
}
new WCXPAY_Loader();