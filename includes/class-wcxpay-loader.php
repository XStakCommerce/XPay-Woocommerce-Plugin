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
			$xpay_sdk_url  = 'https://js.xstak.com/xpay.js';
			if( !empty($xpay_settings) &&  isset($xpay_settings['sdk_url']) ){
			
				$xpay_sdk_url = $xpay_settings['sdk_url'];
			}
			?><script defer src="<?=$xpay_sdk_url?>"></script><?php
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
			$data = json_decode($body);

			$wc_xpay_gateway     = WC()->payment_gateways->payment_gateways()['xpay'];
			$webhook_hmac_secret = $wc_xpay_gateway->get_webhook_hmac_secret();

			$signature = hash_hmac('sha256',json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $webhook_hmac_secret );
	
			if( $signature_header == $signature){
				return true;
			}
	
			return false;

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
			if( $wc_xpay_gateway->debug_log == 'yes' ){
				$logger = wc_get_logger();
        		$log_entry = print_r($body, 1);
        		$logger->info( $log_entry, array( 'source' => WCXPAY_LOG_FILENAME ) );
			}
			
			if( !$data ){
				return new WP_Error( '400', 'Empty payload' );
			}

			if( $data->status == 'succeeded'){

				global $wpdb;
				$table = $wpdb->prefix.'postmeta';
				$prepare_guery = $wpdb->prepare( "SELECT post_id FROM $table where meta_key ='xpay_pi' and meta_value = '%s'", $data->payment_intent_id );
				$order_ids = $wpdb->get_col( $prepare_guery );
				
				if(empty($order_ids)){
					return new WP_Error( '404', 'Could not find order for the payment.' );
				}
				
				$order_id = $order_ids[0];
				$order = wc_get_order($order_id);

				if( !$order ){
					return new WP_Error( '404', 'Order not found.' );
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

			$xpay_pi         = sanitize_text_field( wp_unslash( $_POST['xpay_pi'] ) );
			$wc_xpay_gateway = WC()->payment_gateways->payment_gateways()['xpay'];
			$xpay_api        = new XPay_API( $wc_xpay_gateway->get_acccounId(), $wc_xpay_gateway->get_secret_key(), $wc_xpay_gateway->get_hmac_secret(),$wc_xpay_gateway->mode, $wc_xpay_gateway->debug_log );
			$intent_response = $xpay_api->get_intent( $xpay_pi );
			wp_send_json($intent_response,200);	
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
			$error_notification_recipients = $wc_xpay_gateway->error_notification_recipient;

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