jQuery("document").ready(function ($) {
  const WC_XPAY = new Xpay(
    wc_xpay.publishableKey,
    wc_xpay.accountId,
    wc_xpay.hmacSecret
  );

  /*
   * Initializing Payment widget on checkout page.
   */
  $(document.body).on("updated_checkout", function (e, data) {
    setTimeout(function () {
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

      if ($("#wc_xpay_widget").is(":empty")) {
        const app = WC_XPAY.element("#wc_xpay_widget", options);
      } else {
        console.log("XPay already initialized.");
      }
    }, 500);
  });

  /*
   * Confirm payment on placing order.
   */
  var checkout_form = $("form.checkout");
  checkout_form.on(
    "checkout_place_order_success",
    function (e, args, checkout) {
      const selectedPaymentMethod = document.querySelector(
        'input[name="payment_method"]:checked'
      );

      if (
        (checkout != undefined &&
          checkout?.selectedPaymentMethod !== "payment_method_xpay") ||
        (checkout == undefined && selectedPaymentMethod?.value !== "xpay")
      ) {
        return;
      }
      if (typeof args.xpay_data == "undefined") {
        return false;
      }
      args.xpay_data = JSON.parse(args.xpay_data);
      try {
        let customer = args.xpay_data.customer;
        const response = WC_XPAY.confirmPayment(
          "card",
          args.xpay_data.clientSecret,
          customer,
          args.xpay_data.encryptionKey
        )
          .then((res) => {
            console.log(res);
            // Payment confirmed, redirect to thankyou page.
            if (res.error == false) {
              if (
                -1 === args.xpay_data.redirect.indexOf("https://") ||
                -1 === args.xpay_data.redirect.indexOf("http://")
              ) {
                window.location = args.xpay_data.redirect;
              } else {
                window.location = decodeURI(args.xpay_data.redirect);
              }
            }
          })
          .catch((err) => {
            if (typeof err === "object") {
              // Payment not successful.
              jQuery(document).trigger("xpayPaymentError", [err, args]);
              showMessage(err.message);
            } else {
              showMessage(err);
            }
          });
      } catch (e) {
        console.log(e);
      }
      console.log(true);
      return true;
    }
  );
});

/**
 * Show error message on Xpay Widget.
 * @param {string} messageText
 */
async function showMessage(messageText) {
  const messageContainer = document.querySelector("#wc_xpay_payment-message");

  messageContainer.classList.remove("hidden");
  messageContainer.textContent = messageText;
  messageContainer.innerHTML +=
    '. <a class="xpay-try-again" style="cursor:pointer;color: red;" onClick="window.location.reload()">Retry</a>';
}
