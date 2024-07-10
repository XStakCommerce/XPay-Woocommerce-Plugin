<?php

if (!defined('ABSPATH'))
    exit;

if (!class_exists('XPay_API')):

    class XPay_API {

     //   private $api_url = 'https://xstak-pay.xstak.com/public/';
        private $api_url = 'https://xstak-pay-stg.xstak.com/public/'; 
        private $debug_log;
        private $account_id;
        private $secret_key;
        private $hmach_secret;

        /**
         * Constructor
         *  @param string $account_id
         * @param string $secret_key
         * @param string $hmach_secret
         * @param string $mode TEST/LIVE
         * @param string $debug_log   
         */
        function __construct( $account_id, $secret_key, $hmach_secret, $mode , $debug_log = 'no') {
            
            $this->account_id   = $account_id;
            $this->secret_key = $secret_key;
            $this->hmach_secret = $hmach_secret;
            $this->debug_log    = $debug_log;

            if( $mode == 'LIVE'){
                $this->api_url = 'https://xstak-pay.xstak.com/public/';
            }
        }

        /**
         * Create payment intent for the order.
         * @param object $data intent data.
         * 
	     * @return array
         */
        function create_intent( $data ){

            $signature = hash_hmac('sha256',json_encode( $data, JSON_UNESCAPED_SLASHES), $this->hmach_secret );
			$url = "v1/payment/intent";
			$method  = 'POST';
			$headers = [
				"x-api-key" =>  $this->secret_key,
				"Content-Type" => "application/json",
				"x-signature" => $signature,
				"x-account-id" => $this->account_id,
			];

            $response_data = $this->send_request( $url, 'POST', $headers, json_encode($data)  );
            return $response_data;

        }

        function get_intent( $pi_id ){

            $headers = [
                "x-api-key" => $this->secret_key,
                "x-account-id" => $this->account_id,
            ];
            $url = 'v1/payment/intent/details/'.$pi_id;
          
            $response_data = $this->send_request( $url, 'GET', $headers, []  );
            return $response_data;
        }

        /**
         * Send request to XPay api.
         * @param string $endpoint
         * @param string $method
         * @param array $headers 
         * @param array|string $data 
         * 
         * reuturn array
         */
        function send_request( $endpoint, $method, $headers , $data = [] ){

            $url = $this->api_url.$endpoint;
           
            $response = wp_remote_post( $url, array(
                'method' =>  $method,
                'headers' => $headers,
                'body' => $data
            ));
           
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $response = ['success' => false, 'message'=> $error_message ];
                return $response;
            }else {
                $response_data = json_decode(wp_remote_retrieve_body($response), true);
                if( $response_data['success'] == false ) {
                    do_action('wc_xpay_api_error', $response_data, $url, $headers, $data );
                }
                return $response_data;
            }

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

endif;
