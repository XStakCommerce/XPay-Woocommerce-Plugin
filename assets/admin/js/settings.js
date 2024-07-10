jQuery(document).ready(function ($) {
   
    /*
     * Switch live/test credential fields when payment mode is change.
     */
    $('#woocommerce_xpay_mode').on('change', function(e) {  

        let current_mode = $(this).val();
        if( current_mode == 'TEST' ){
            jQuery('#woocommerce_xpay_publishable_key').closest('tr').hide();
            jQuery('#woocommerce_xpay_secret_key').closest('tr').hide();
            jQuery('#woocommerce_xpay_hmac_secret').closest('tr').hide();
            jQuery('#woocommerce_xpay_webhook_hmac_secret').closest('tr').hide();

            jQuery('#woocommerce_xpay_test_publishable_key').closest('tr').show();
            jQuery('#woocommerce_xpay_test_secret_key').closest('tr').show();
            jQuery('#woocommerce_xpay_test_hmac_secret').closest('tr').show();
            jQuery('#woocommerce_xpay_test_webhook_hmac_secret').closest('tr').show();
        } else {
            jQuery('#woocommerce_xpay_test_publishable_key').closest('tr').hide();
            jQuery('#woocommerce_xpay_test_secret_key').closest('tr').hide();
            jQuery('#woocommerce_xpay_test_hmac_secret').closest('tr').hide();
            jQuery('#woocommerce_xpay_test_webhook_hmac_secret').closest('tr').hide();

            jQuery('#woocommerce_xpay_publishable_key').closest('tr').show();
            jQuery('#woocommerce_xpay_secret_key').closest('tr').show();
            jQuery('#woocommerce_xpay_hmac_secret').closest('tr').show();
            jQuery('#woocommerce_xpay_webhook_hmac_secret').closest('tr').show();
        }
    }).trigger('change');

    /*
     * Show/Hide Webhook URL when gateway is enabled/disabled.
     */
    $('#woocommerce_xpay_enabled').on('change', function(){
    
        if( $(this).is(':checked') ){
            jQuery('#woocommerce_xpay_webhook_url').closest('tr').show();
        } else {
            jQuery('#woocommerce_xpay_webhook_url').closest('tr').hide();
        }
    }).trigger('change');

    /*
     * Copy Webhook URL  to clipboard.
     */
    $('.xpay_webhook_url').on('click', function(){

        var copyText = document.getElementById("woocommerce_xpay_webhook_url");
        copyText.select();
        copyText.setSelectionRange(0, 99999); // For mobile devices

        navigator.clipboard.writeText(copyText.value);
    });
   
});
