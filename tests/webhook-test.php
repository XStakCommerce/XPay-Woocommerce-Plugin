<?php
// Run: docker run --rm -v "$PWD:/p:ro" php:8.2-cli php -d zend.assertions=1 -d assert.exception=1 /p/tests/webhook-test.php
// Minimal WP/WC stubs so the webhook decision path can run outside WordPress.
define('ABSPATH', '/'); define('WCXPAY_PLUGIN_SLUG','x'); define('WCXPAY_LOG_FILENAME','x');
class WP_Error { public $code; function __construct($c,$m){ $this->code=$c; } }
class WP_REST_Response { public $d; function __construct($d){ $this->d=$d; } }
class FakeOrder { public $completed=[]; public $paid=false; function get_id(){return 7;} function is_paid(){return $this->paid;} function get_meta($k){ return $k==='xpay_pi' ? 'known_pi' : ''; } function add_order_note($n){} function payment_complete($t){ $this->completed[]=$t; } }
class FakeGateway { function get_option($k, $d = '') { return $d; } function get_webhook_hmac_secret(){ return 'whsec_test'; } }
class FakeWC { public $payment_gateways; function __construct(){ $this->payment_gateways=$this; } function payment_gateways(){ return ['xpay'=>new FakeGateway]; } }
function WC(){ static $w; return $w ?: $w=new FakeWC; }
$GLOBALS['order']=new FakeOrder;
function wc_get_order($id){ return $GLOBALS['order']; }
function apply_filters($h,$v){ return $v; }
function __($s,$d=''){ return $s; } function esc_html($s){ return $s; }
function add_action($h){ $GLOBALS['hooks'][]=$h; } function add_filter(){}
class Req { public $b; public $sig; function __construct($b,$sig=null){$this->b=$b;$this->sig=$sig;} function get_body(){ return $this->b; } function get_header($h){ return $this->sig; } }

require __DIR__ . '/../includes/class-wcxpay-loader.php';

class T extends WCXPAY_Loader {
    function get_order_ids_by_intent($pi){ return $pi==='known_pi' ? [7] : []; }
    public $st=''; public $asked=0;
    function get_intent_status($pi){ $this->asked++; return $this->st; }
}
$t=new T;
// 0. No payment-status AJAX hook, for logged-in or logged-out visitors (OraSec API VID-005).
assert(!array_intersect(['wp_ajax_wcxpay_check_payment','wp_ajax_nopriv_wcxpay_check_payment'],$GLOBALS['hooks']), 'status check AJAX hooks removed');
$body=fn($pi,$st='succeeded')=>json_encode(['status'=>$st,'payment_intent_id'=>$pi,'transaction_details'=>['_id'=>'txn1','status_message_from_bank'=>'ok']]);

// 1. Signature: computed over the exact raw body XPay sent.
$raw = '{"status":"succeeded","amount":100.50,"note":"a/b  "}'; // re-encoding would change these bytes
assert($t->authenicate_webhook(new Req($raw, hash_hmac('sha256',$raw,'whsec_test'))) === true, 'valid raw-body signature');
assert($t->authenicate_webhook(new Req($raw, 'bad')) === false, 'bad signature rejected');
assert($t->authenicate_webhook(new Req($raw)) === false, 'missing signature rejected');
assert($t->authenicate_webhook(new Req('', hash_hmac('sha256','','whsec_test'))) === false, 'empty body rejected');

// 2. Signed succeeded webhook -> order completed with the transaction id.
$r=$t->update_payment_status(new Req($body('known_pi')));
assert($r instanceof WP_REST_Response && $GLOBALS['order']->completed===['txn1'], 'succeeded webhook should complete order');

// 3. Already paid -> not completed again.
$GLOBALS['order']=new FakeOrder; $GLOBALS['order']->paid=true; $r=$t->update_payment_status(new Req($body('known_pi')));
assert($r instanceof WP_REST_Response && $GLOBALS['order']->completed===[], 'paid order must not be completed twice');

// 4. Non-succeeded status -> nothing completed.
$GLOBALS['order']=new FakeOrder; $t->update_payment_status(new Req($body('known_pi','requires_payment_method')));
assert($GLOBALS['order']->completed===[], 'failed payment must not complete order');

// 5. Unknown intent -> 404; malformed id -> 400 before any lookup.
$r=$t->update_payment_status(new Req($body('other_pi')));
assert($r instanceof WP_Error && $r->code==='404', 'unknown intent rejected');
$r=$t->update_payment_status(new Req($body('../evil')));
assert($r instanceof WP_Error && $r->code==='400', 'bad id rejected');

// 6. Order-received page message comes from the intent status on XPay; paid orders and unreachable XPay show nothing.
$page=function($st,$paid=false) use ($t){ $GLOBALS['order']=new FakeOrder; $GLOBALS['order']->paid=$paid; $t->st=$st; ob_start(); $t->show_payment_status(7); return ob_get_clean(); };
assert(strpos($page('succeeded'),'Payment received')!==false, 'succeeded message');
assert(strpos($page('requires_payment_method'),'Payment not completed')!==false, 'not completed message');
assert(strpos($page('requires_action'),'still being processed')!==false, 'pending message');
assert($page('')==='', 'no message when XPay unreachable');
$t->asked=0; assert($page('succeeded',true)==='' && $t->asked===0, 'paid order: no message, no XPay call');
assert($GLOBALS['order']->completed===[], 'order page never completes the order');

echo "all webhook checks passed\n";
