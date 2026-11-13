<?php
// Run: docker run --rm -v "$PWD:/p:ro" php:8.2-cli php -d zend.assertions=1 -d assert.exception=1 /p/tests/webhook-test.php
// Minimal WP/WC stubs so the webhook decision path can run outside WordPress.
define('ABSPATH', '/'); define('WCXPAY_PLUGIN_SLUG','x'); define('WCXPAY_LOG_FILENAME','x');
class WP_Error { public $code; function __construct($c,$m){ $this->code=$c; } }
class WP_REST_Response { public $d; function __construct($d){ $this->d=$d; } }
class FakeOrder { public $completed=[]; public $paid=false; function get_id(){return 7;} function is_paid(){return $this->paid;} function add_order_note($n){} function payment_complete($t){ $this->completed[]=$t; } }
class FakeGateway { function get_option($k, $d = '') { return $d; } function get_webhook_hmac_secret(){ return 'whsec_test'; } }
class FakeWC { public $payment_gateways; function __construct(){ $this->payment_gateways=$this; } function payment_gateways(){ return ['xpay'=>new FakeGateway]; } }
function WC(){ static $w; return $w ?: $w=new FakeWC; }
$GLOBALS['order']=new FakeOrder;
function wc_get_order($id){ return $GLOBALS['order']; }
function apply_filters($h,$v){ return $v; }
function add_action(){} function add_filter(){}
class Req { public $b; public $sig; function __construct($b,$sig=null){$this->b=$b;$this->sig=$sig;} function get_body(){ return $this->b; } function get_header($h){ return $this->sig; } }

require __DIR__ . '/../includes/class-wcxpay-loader.php';

class T extends WCXPAY_Loader {
    function get_order_ids_by_intent($pi){ return $pi==='known_pi' ? [7] : []; }
}
$t=new T; $body=fn($pi,$st='succeeded')=>json_encode(['status'=>$st,'payment_intent_id'=>$pi,'transaction_details'=>['_id'=>'txn1','status_message_from_bank'=>'ok']]);

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

echo "all webhook checks passed\n";
