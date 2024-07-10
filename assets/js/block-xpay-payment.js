jQuery('document').ready(function($ ){

    const WC_XPAY = new Xpay( wc_xpay.publishableKey, wc_xpay.accountId, wc_xpay.hmacSecret);

    window.WC_XPAY = WC_XPAY; // to be utilized in payment block.
   /*
   * Initializing Payment widget on checkout page.
   */
    wp.hooks.addAction( 'experimental__woocommerce_blocks-checkout-render-checkout-form', 'xpay-checkout-block', function( data ) {
        setTimeout(initializeXpayPaymentWidget,500);
    }); 

    wp.hooks.addAction( `experimental__woocommerce_blocks-checkout-set-active-payment-method`, 'xpay-checkout-block', function( paymentMethod ) {
      if( paymentMethod.value == 'xpay'){
        setTimeout(initializeXpayPaymentWidget,500);
      }
    }); 

    function initializeXpayPaymentWidget(){
      const options = {
        override: true,
        fields: {
            creditCard: {
              placeholder: "1234 1234 1234 1234",
              label: "Enter your credit card",
            },
            exp: {
              placeholder: "Exp. Date",
            },
        },
        style: {
            ".input": {},
            ".invalid": {},
            ".label": {},
            ".input:focus": {
              "border-color": "blue",
              "box-shadow": "none",
            },
            ":hover": {},
            "::placeholder": {},
            "::selection": {},
        },
    };

    if ( $('#wc_xpay_widget').length == 0) {
        return;
    } 
    else if( $('#wc_xpay_widget').is(':empty') ) {
        const app = WC_XPAY.element("#wc_xpay_widget", options);
    } else {
        console.log('XPay already initialized.');
    }
  }

  /*
   * Handle Confirm Payment errors.
   */
  wp.hooks.addAction( `xpayBlockPaymentError`, 'xpay-checkout-block', function( error, args) {
      let xpay_data = JSON.parse(args.processingResponse.paymentDetails.xpay_data);

      //Method is already attached, no need of card anymore. 
      if( error.message === "Payment Method is already attached"){
          $.ajax({
              type: 'POST',
              url: wc_xpay.ajax_url,
              data: {
                'action': 'wcxpay_check_payment',
                'xpay_pi': xpay_data.id
              },
              success: function(response)  {

                if( response.success == true){
                    if ( -1 === xpay_data.redirect.indexOf( 'https://' ) || -1 === xpay_data.redirect.indexOf( 'http://' ) ) {
                        window.location = xpay_data.redirect;
                    } else {
                        window.location = decodeURI( xpay_data.redirect );
                    }
                }
              },
              error:  function( err){
                console.log('err');
              }
          });  
      }else{
        $('button.wc-block-components-checkout-place-order-button').prop('disabled', false);
        $('button.wc-block-components-checkout-place-order-button .wc-block-components-button__text').text('Place Order');
      }
  }); 
});

async function showMessage(messageText) {
    const messageContainer = document.querySelector("#wc_xpay_payment-message");
    messageContainer.classList.remove("hidden");
    messageContainer.textContent = messageText;
    messageContainer.innerHTML  += '. <a class="xpay-try-again" style="cursor:pointer;color: red;" onClick="window.location.reload()">Retry</a>';
}