import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import * as hooks from '@wordpress/hooks';


import { getSetting } from '@woocommerce/settings';
import { XPay } from  './XPay'
const { useEffect } = window.wp.element;

const defaultLabel = 'XPay';
const settings = getSetting( 'xpay_data', {} );
const label = decodeEntities( settings.title ) || defaultLabel;
/**
 * Content component
 */
const Content = ( { eventRegistration, emitResponse } ) => {
	
	const { onCheckoutSuccess, onCheckoutFail } = eventRegistration;

	useEffect( () => {
		const unsubscribe = onCheckoutSuccess( processSuccess );
		return unsubscribe;
	}, [ onCheckoutSuccess ] );
	useEffect( () => {
		const unsubscribe = onCheckoutFail( processFailure );
		return unsubscribe;
	}, [ onCheckoutFail ] );
	return (
		<>
		{decodeEntities( settings.description || '' )}
		<XPay settings={settings}></XPay>
		</>);
};

const Label = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={ label } />;
};

/**
 * Generate confirm payment request to xpay.
 * @param {object} data 
 */
function processSuccess( data ) {
	
	let xpay_data =  JSON.parse(data.processingResponse.paymentDetails.xpay_data);
	
	try {
		let customer = xpay_data.customer ;
		const response = window.WC_XPAY.confirmPayment(
		  "card",
		  xpay_data.clientSecret,
		  customer,
		  xpay_data.encryptionKey
		)
		.then((res) => {

		  console.log(res);
		  // Payment confirmed, redirect to thankyou page.
		  if(res.error == false){
			  if ( -1 === xpay_data.redirect.indexOf( 'https://' ) || -1 === xpay_data.redirect.indexOf( 'http://' ) ) {
				  window.location = xpay_data.redirect;
			  } else {
				  window.location = decodeURI( xpay_data.redirect );
			  }
		  }

		})
		.catch((err)=>{ 
		  
			if( typeof err === 'object' ){
			  // Payment not successful.
			  hooks.doAction('xpayBlockPaymentError', err, data );
			  showMessage(err.message);
			}else{
			   showMessage(err);
			}
		  
		})
	   
	} catch (e) {
		console.log(e);
	}
}

/**
 * Get reason for failure and show message.
 * @param {object} data 
 */
function processFailure( data){

	let xpay_data =  JSON.parse(data.processingResponse.paymentDetails.xpay_data);
	let errorMessage = data.processingResponse.paymentDetails.messages;
	return {
		message: errorMessage,
	}
}

const options = {
	name: 'xpay',
	label:<Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	paymentMethodId: 'xpay',
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
};

registerPaymentMethod( options );

/**
 * Show error message on Xpay Widget.
 * @param {string} messageText 
 */
async function showMessage(messageText) {

	const messageContainer = document.querySelector("#wc_xpay_payment-message");	
	messageContainer.classList.remove("hidden");
	messageContainer.textContent = messageText;
	messageContainer.innerHTML  += '. <a class="xpay-try-again" style="cursor:pointer;color: red;" onClick="window.location.reload()">Retry</a>';
}