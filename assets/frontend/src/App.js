import './App.css';
import { XPay } from '@xstak/xpay-element-stage';
import { Payment } from './payment';

function XPayPayment() {

  // TODO: These credentials are hardcoded for now
  return (  
  <XPay xpay={{ publishableKey: 'xpay_pk_test_a706b69e678f44a99ee9f2ee03963f158f665a1b7b8c60ac5ccf217d9b5e024a', accountId: "c28721b349294cad", hmacSecret:"e18efac678237ee63ac2ccc44325eaae9e17ad77aeafb2e50166c4ad3e7f2c68"}}>
     {/* <Payment /> */}
  </XPay>
 )}
export default XPayPayment;
