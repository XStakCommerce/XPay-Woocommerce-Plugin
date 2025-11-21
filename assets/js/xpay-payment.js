document.addEventListener("DOMContentLoaded", function () {
  window.WC_XPAY = new Xpay(
    wc_xpay.publishableKey,
    wc_xpay.accountId,
    wc_xpay.hmacSecret
  );

  if (window.location.pathname.includes("/checkout")) {
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
        ":focus": {},
        ":hover": {},
        "::placeholder": {},
        "::selection": {},
      },
    };

    let checkInterval = null;
    let lastWidgetState = null; // Track if widget had children

    function startChecking() {
      // Don't start if already running
      if (checkInterval) return;

      checkInterval = setInterval(function () {
        const widget = document.querySelector("#wc_xpay_widget");

        if (!widget) return;

        const hasChildren = widget.hasChildNodes();

        // Initialize if widget is empty
        if (!hasChildren) {
          console.log("Initializing XPay widget...");
          WC_XPAY.element("#wc_xpay_widget", options);
        }

        // If widget now has children and is initialized, stop checking for a bit
        if (hasChildren && lastWidgetState === false) {
          console.log("XPay widget initialized, pausing checks...");
          stopChecking();
          // Resume checking after a delay (in case user switches payment methods)
          setTimeout(startChecking, 2000);
        }

        lastWidgetState = hasChildren;
      }, 500);
    }

    function stopChecking() {
      if (checkInterval) {
        clearInterval(checkInterval);
        checkInterval = null;
      }
    }

    // Start initial checking
    startChecking();

    // Listen for WooCommerce checkout updates (when payment method might change)
    jQuery(document.body).on("updated_checkout", function () {
      console.log("Checkout updated, resuming widget checks...");
      lastWidgetState = null;
      startChecking();
    });

    // Cleanup on page unload
    window.addEventListener("beforeunload", function () {
      stopChecking();
    });

    // Add event listener if the form exists
    var checkout_form = document.querySelector("form.checkout");
    if (checkout_form) {
      checkout_form.addEventListener(
        "checkout_place_order_success",
        function (e, args, checkout) {
          if (checkout.selectedPaymentMethod != "payment_method_xpay") {
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
                  document.dispatchEvent(
                    new CustomEvent("xpayPaymentError", { detail: [err, args] })
                  );
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
    } else {
      console.log("Checkout form not found.");
    }
  }
});

/**
 * Show error message on Xpay Widget.
 * @param {string} messageText
 */
async function showMessage(messageText) {
  const messageContainer = document.querySelector("#wc_xpay_payment-message");

  if (messageContainer) {
    messageContainer.classList.remove("hidden");
    messageContainer.textContent = messageText;
    messageContainer.innerHTML +=
      '. <a class="xpay-try-again" style="cursor:pointer;color: red;" onClick="window.location.reload()">Retry</a>';
  } else {
    console.log("#wc_xpay_payment-message not found.");
  }
}
