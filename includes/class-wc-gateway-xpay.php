<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Xpay class.
 *
 * @extends WC_Payment_Gateway
 */

if ( ! class_exists( 'WC_Gateway_Xpay' ) ) {

    class WC_Gateway_Xpay extends WC_Payment_Gateway {


        /**
         * Constructor for the gateway.
         */
        public function __construct() {

            // Setup general properties.
            $this->setup_properties();

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();
            
            foreach ( $this->settings as $setting_key => $value ) {
				$this->$setting_key = $value;
			}
            
            // Get settings.
            //$this->title =  'Card Payments';
            $this->testmode = 'yes' === $this->get_option('testmode', 'no');
            $this->enable_for_methods = $this->get_option('enable_for_methods', []);
            $this->enable_for_virtual = $this->get_option('enable_for_virtual', 'yes') === 'yes';
			$this->supports           = [ 'products' ];
				
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('admin_enqueue_scripts', [ $this, 'admin_assets' ] );
			add_action('wp_enqueue_scripts', [ $this, 'payment_scripts' ] );
	

        }

        /**
         * Setup general properties for the gateway.
         */
        protected function setup_properties() {

            $this->id = 'xpay';
            $this->icon = apply_filters('woocommerce_xpay_icon', '');
            $this->method_title = __('Card Payments', 'woocommerce-gateway-xpay');
            $this->method_description = __('Have your customers pay with credit card using XPay.', 'woocommerce-gateway-xpay');
            $this->description = __( 'Payment Via XPay', 'woocommerce-gateway-xpay' );
            $this->has_fields = true;
            $this->supports   = [
                'products'
            ];
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields() {

			$this->form_fields =  apply_filters('wc_xpay_settings', array(
				'enabled'            => array(
					'title'   => __( 'Enable / Disable', 'woocommerce-gateway-xpay' ),
					'label'   => __( 'Enable this payment gateway', 'woocommerce-gateway-xpay' ),
					'type'    => 'checkbox',
					'default' => 'no',
				),
				'title' => array(
					'title'       => __( 'Title', 'woocommerce-gateway-xpay' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-xpay' ),
					'default'     => 'Card Payments',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Description', 'woocommerce-gateway-xpay' ),
					'type'        => 'textarea',
					'description' => __(  'Payment method description that the customer will see on your checkout.', 'woocommerce-gateway-xpay' ),
					'default'     => __( 'Payment Via XPay', 'woocommerce-gateway-xpay' ),
					'desc_tip'    => true,
				),
				'account_id' => array(
					'title' => __( 'Account ID', 'woocommerce-gateway-xpay' ),
					'type'  => 'text',
					'description' => __( 'Accound ID from Xpay .', 'woocommerce-gateway-xpay' ),
					'desc_tip'    => true,
				),
				'sdk_url' => array(
					'title'       => __( 'SDK URL', 'woocommerce-gateway-xpay' ),
					'type'        => 'text',
					'description' => __( 'Add the URL of xpay SDK, make sure to add correct url as per XPAY account (staging or live).', 'woocommerce-gateway-xpay' ),
					'default'     => 'https://js.xstak.com/xpay.js',
					'desc_tip'    => true,
					'custom_attributes'  => array(
						'required'   => 'required',
						'pattern'    => '^https:\/\/js\.xstak\.com(?:\/v2)?\/xpay(?:-stage)?\.js'
					)
				),
				'mode'            => array(
					'title'   => __( 'Mode', 'woocommerce-gateway-xpay' ),
					'type'    => 'select',
					'description' => __('Mode of XPay account.', 'woocommerce-gateway-xpay' ),
					'options' => array(
						'TEST' => 'TEST',
						'LIVE' => 'LIVE',
					),
					'css'     => 'width:200px;',
					'default' => 'TEST',
					'desc_tip'    => true,
				),
				'publishable_key' => array(
					'title' => __( 'Publishable Key', 'woocommerce-gateway-xpay' ),
					'type'  => 'text',
					'description' => __('Public key from XPay account. Settings > Store Info > Key Details > Api Keys', 'woocommerce-gateway-xpay' ),
					'desc_tip'    => true,
				),
				'secret_key'     => array(
					'title' => __( 'Secret Key', 'woocommerce-gateway-xpay' ),
					'type'  => 'password',
					'description' => __('Secret key from XPay account. Settings > Store Info > Key Details > Api Keys', 'woocommerce-gateway-xpay' ),
					'desc_tip'    => true,
				),
				'hmac_secret' => array(
					'title' => __( 'API HMAC Secret', 'woocommerce-gateway-xpay' ),
					'type'  => 'password',
					'description' => __('API HMAC Secret from XPay account. Settings > Store Info > Key Details > HMAC Keys', 'woocommerce-gateway-xpay' ),
					'desc_tip'    => true,
				),
				'webhook_hmac_secret' => array(
					'title' => __( 'Webhook HMAC Secret', 'woocommerce-gateway-xpay' ),
					'type'  => 'password',
					'description' => __('Webhook HMAC Secret from XPay account. Settings > Store Info > Key Details > HMAC Keys', 'woocommerce-gateway-xpay' ),
					'desc_tip'    => true,
				),
				'test_publishable_key'     => array(
					'title' => __( 'Test Publishable Key', 'woocommerce-gateway-xpay' ),
					'type'  => 'text',
					'description' => __('Public key from XPay account. Settings > Store Info > Key Details > Api Keys', 'woocommerce-gateway-xpay' ),
					'desc_tip'    => true,
				),
				'test_secret_key'     => array(
					'title' => __( 'Test Secrey Key', 'woocommerce-gateway-xpay' ),
					'type'  => 'password',
					'description' => __('Secret key from XPay account. Settings > Store Info > Key Details > Api Keys', 'woocommerce-gateway-xpay' ),
					'desc_tip'    => true,
				),
				'test_hmac_secret' => array(
					'title' => __( 'Test API HMAC Secret', 'woocommerce-gateway-xpay' ),
					'type'  => 'password',
					'description' => __('API HMAC Secret from XPay account. Settings > Store Info > Key Details > HMAC Keys', 'woocommerce-gateway-xpay' ),
					'desc_tip'    => true,
				),
				'test_webhook_hmac_secret' => array(
					'title' => __( 'Test Webhook HMAC Secret', 'woocommerce-gateway-xpay' ),
					'type'  => 'password',
					'description' => __('Webhook HMAC Secret from XPay account. Settings > Store Info > Key Details > HMAC Keys', 'woocommerce-gateway-xpay' ),
					'desc_tip'    => true,
				),
				'debug_log' => array(
					'title' => __( 'Enable debug log', 'woocommerce-gateway-xpay' ),
					'type'  => 'checkbox',
				),
				'error_notification_recipient' => array(
					'title'       => __( 'Error notification Recipient(s)', 'woocommerce-gateway-xpay' ),
					'type'        => 'text',
					'description' => __( 'Enter email addresses (comma separated) to receive error notification. Leave blank if not needed.', 'woocommerce-gateway-xpay' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				),
				'webhook_url' => array(
					'title'       => __( 'Webhook URL ', 'woocommerce-gateway-xpay' ),
					'type'        => 'text',
					'description' => __( 'Copy and paste Webhook URL to your XPay dashboard <a class="xpay_webhook_url" style="cursor:pointer;" title="click to copy"><span class="dashicons dashicons-admin-page"></span></a>', 'woocommerce-gateway-xpay' ),
					'default'     => __( site_url().'/wp-json/xpay/v1/update_payment/', 'woocommerce-gateway-xpay' ),
					'custom_attributes' => array( 'readonly' => true),
					'css' => 'border: none !important;'
				),
			));
		}

        /**
         * Payment form on the checkout page.
         */
        public function payment_fields() {
			
			$description = $this->get_description();
			if ( $description ) {
				echo wpautop( wptexturize( $description ) ); // @codingStandardsIgnoreLine.
			}
            // here comes xpay form.
			require_once WCXPAY_PLUGIN_DIR . '/templates/widget.php';
        
        }

        /**
         * Process the payment
         * @param int  $order_id Reference.
         * 
         * @throws Exception If payment will not be accepted.
	     * @return array|void
         */
        public function process_payment( $order_id ) {

            $order = wc_get_order($order_id);
			$intent_response = $this->create_intent( $order );
			$this->log($intent_response);
			$message = '';
			if( !$intent_response['success']  ){
            	$message = $intent_response['message'];
				wc_add_notice( "Error: ".$intent_response['message'].', Please contact site administrator.' ,'error');
				
			}

			$data =  [];

			$success = 'fail';
			// save payment intent fir order
			if(  $intent_response['success']  ){
				$success               = 'success';
				$pi_data               = $intent_response['data'];
				$data['id']            =  $pi_data['_id'];
				$data['clientSecret' ] = $pi_data['pi_client_secret'];
				$data['encryptionKey'] = $pi_data['encryptionKey']; 
				$data['customer']      = [ 'name' => htmlspecialchars(trim($order->get_billing_first_name().''.$order->get_billing_last_name()))];
				$data['redirect']      = $this->get_return_url( $order );
                $message = '<div class="wc-block-components-notice-banner is-info woocommerce-info">Please wait while we process your payment.</div>';
			}

            // Return the xpay data with a redirect
            return array(
				'result'    => $success,
				'redirect'  => false,
				'xpay_data' => json_encode($data),
				'messages'  => $message
            );
        }

		/**
         * Prepare payment intent data and create payment intent for the order.
         * @param WC_Order $order woocommerce order object .
         * 
	     * @return array
         */
		public function create_intent( $order ){

			function filter_empty($array) {
				foreach ($array as $key => $value) {
					if (is_array($value)) {
						$array[$key] = filter_empty($value);
					}
					if (empty($array[$key]) && $array[$key] !== '0') {
						unset($array[$key]);
					}
				}
				return $array;
			}

			function process_string($string) {
				// Remove content within parentheses
				$string = preg_replace('/\s*\([^)]*\)/', '', $string);
				// Capitalize the first character of each word
				return ucwords(trim($string));
			}
		
			$pi_data = get_post_meta( $order->get_id(), 'xpay_pi_data', true);
			if( !empty($pi_data) ){
				$this->log('getting saved intent.');
				return [
					'success' => true,
					'data'    => $pi_data
				];
			}

			$states = WC()->countries->get_states( $order->get_billing_country() );
			$state  = ! empty( $states[ $order->get_billing_state() ] ) ? $states[ $order->get_billing_state() ] : '';

			$order_data = [
				'amount' => $order->get_total(),
				'currency' => apply_filters('wc_xpay_intent_currency', get_woocommerce_currency(), $order),
				'payment_method_types' => "card",
				'customer' => [
					'email' => $order->get_billing_email(),
					'name' => htmlspecialchars(trim($order->get_billing_first_name().' '.$order->get_billing_last_name())),
					'phone' => $order->get_billing_phone(),
				],
				'shipping' => [
					'address1' => htmlspecialchars(($order->get_billing_address_1())),
					'city'     => htmlspecialchars($order->get_billing_city()),
					'country'  => htmlspecialchars(process_string(strtolower(WC()->countries->countries[$order->get_billing_country()]))),
					'province' => htmlspecialchars(ucwords(strtolower($state))),
					'zip' => $order->get_billing_postcode()
				],
				'metadata' =>[
					'order_reference' => $order->get_id()
				]
			];
			

			$filtered_order_data = filter_empty($order_data);

			$data = (object)apply_filters('wc_xpay_create_intent_data', $filtered_order_data);

			
			$xpay_api = new XPay_API( $this->get_acccounId(), $this->get_secret_key(), $this->get_hmac_secret(), $this->mode, $this->debug_log );
			$intent_response = $xpay_api->create_intent( $data );

			if(  $intent_response['success']  ){
				$this->log('Intent created.');
				$pi_data = $intent_response['data'];
				$pi_id   = $pi_data['_id'];

				update_post_meta( $order->get_id(), 'xpay_pi', $pi_id);
				update_post_meta( $order->get_id(), 'xpay_pi_data', $pi_data);

				$note = "Intent created on XPay ".$pi_id;
				$order->add_order_note($note);
			}
			return $intent_response;
		}

		/**
         *  Get account ID.
         * @return string $accountId 
         */
		function get_acccounId(){
			$accountId = $this->account_id;
			return $accountId;
		}

		/**
         * Get public/publishable key.
         * @return string $publishable_key
         */
		function get_public_key(){

			$publishable_key = $this->publishable_key;
			if( $this->mode == 'TEST' ){
				$publishable_key = $this->test_publishable_key;
			}
			return $publishable_key;
		}
	
		/**
         * Get secret key
         * @return string $secret_key 
         */
		function get_secret_key(){

			$secret_key = $this->secret_key;
			if( $this->mode == 'TEST' ){
				$secret_key = $this->test_secret_key;
			}
			return $secret_key;
		}
		
		/**
         * Get API HMACH secret.
         * @return string $hmach_secret api hmach secret.
         */
		function get_hmac_secret(){
	
			$hmach_secret = $this->hmac_secret;
			if( $this->mode == 'TEST' ){
				$hmach_secret = $this->test_hmac_secret;
			}
			return $hmach_secret;
		}
		
		/**
         * Get HMACH secret for the webhook.
         * @return string  $webhook_hmach_secret webhook hmach secret.
         */
		function get_webhook_hmac_secret(){
	
			$webhook_hmach_secret = $this->webhook_hmac_secret;
			if( $this->mode == 'TEST' ){
				$webhook_hmach_secret = $this->test_webhook_hmac_secret;
			}
			return $webhook_hmach_secret;
		}

        /**
         * Add javascript for gateway settings page.
         * @param string  $hook page hook.
         */
        public function admin_assets( $hook ){

            if ( 'woocommerce_page_wc-settings' != $hook ) {
                return;
            }
            if( isset($_GET['section']) &&  $_GET['section'] == 'xpay' ){
                wp_enqueue_script( 'wcxpay-admin-script', WCXPAY_ASSETS_DIR_URL . '/admin/js/settings.js', array( 'jquery' ), rand() );
            }
            
        }

		/**
         * Add assets for checkout page.
         * @param string  $hook page hook.
         */
		public function payment_scripts() {
			
			$path = "/frontend/build/static";
			wp_enqueue_script("wc_xpay_widget_js", WCXPAY_ASSETS_DIR_URL.$path."/js/main.js", array('jquery'), rand(), false);
			wp_enqueue_script("wc_xpay_widget_payment", WCXPAY_ASSETS_DIR_URL."js/xpay-payment.js", array('jquery'), rand(), false);

			$xpay_config = [
				'publishableKey' => $this->get_public_key(),
				'accountId'      => $this->get_acccounId(),
				'hmacSecret'     => $this->get_hmac_secret(),
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
			];
			wp_localize_script(
				'wc_xpay_widget_payment',
				'wc_xpay',
				$xpay_config
			);
			//wp_register_style("wc_xpay_widget_css", WCXPAY_ASSETS_DIR_URL.$path."/css/main.css", array(), "1.0", "all");
			wp_enqueue_style("wc_xpay_widget_css", WCXPAY_ASSETS_DIR_URL.$path."/css/main.css", array(), "1.0", "all");
		}
        
		/**
         * Log response when syncing product
         * @param string|object|array $response
         */
        function log($response) { 
            
            if( $this->debug_log == 'no' ){
                return;
            }
            $logger = wc_get_logger();
            $log_entry = print_r($response, 1);
            $logger->info( $log_entry, array( 'source' => WCXPAY_LOG_FILENAME ) );
        }

    }
}