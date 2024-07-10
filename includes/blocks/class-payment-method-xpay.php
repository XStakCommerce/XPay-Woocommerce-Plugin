<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if( class_exists('WCXPAY_BlockPaymentMethod')){
    return;
}

final class WCXPAY_BlockPaymentMethod extends AbstractPaymentMethodType {


	/**
	 * The gateway instance.
	 *
	 */
    private $gateway;

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
    protected $name = 'xpay';


    /**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_xpay_settings', [] );
		$this->gateway  = new WC_Gateway_Xpay();
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
    public function get_payment_method_script_handles() {
		
		$script_path       = 'assets/js/frontend/blocks.js';
		$script_asset_path = WCXPAY_PLUGIN_DIR_URL. '/assets/js/frontend/blocks.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: array(
				'dependencies' => array(),
				'version'      => '1.1.0'
			);
		$script_url        = WCXPAY_PLUGIN_DIR_URL . $script_path;

		wp_register_script(
			'wc-xpay-payment-block',
			$script_url,
			$script_asset[ 'dependencies' ],
			$script_asset[ 'version' ],
			true
		);

		$path = "/frontend/build/static";
		wp_register_script("wc_xpay_widget_js", WCXPAY_ASSETS_DIR_URL.$path."/js/main.js", array(), rand(), false);
		wp_register_script("wc_xpay_block_widget_payment", WCXPAY_ASSETS_DIR_URL."/js/block-xpay-payment.js", array('jquery'), rand(), false);

		$xpay_config = [
			'publishableKey' => $this->gateway->get_public_key(),
			'accountId'      => $this->gateway->get_acccounId(),
			'hmacSecret'     => $this->gateway->get_hmac_secret(),
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
		];
		wp_localize_script(
			'wc_xpay_block_widget_payment',
			'wc_xpay',
			$xpay_config
		);
		return [ 'wc_xpay_widget_js','wc_xpay_block_widget_payment','wc-xpay-payment-block' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 * @return array
	 */
    public function get_payment_method_data() {
		return [
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] )
		];
	}
}
new WCXPAY_BlockPaymentMethod();