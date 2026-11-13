import './App.css';
import { XPay } from '@xstak/xpay-element-stage';
import { Payment } from './payment';

function XPayPayment() {

  // TODO: These credentials are hardcoded for now
  return (  
  <XPay xpay={{ publishableKey: '', accountId: '' }}>
     {/* <Payment /> */}
  </XPay>
 )}
export default XPayPayment;
