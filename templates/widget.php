<?php
//  Enqueque css and js 
wp_enqueue_script("wc_xpay_widget_js");
wp_enqueue_style("wc_xpay_widget_css");
wp_enqueue_script("wc_xpay_widget_payment");
?>
<div id="wc_xpay-wrapper">  
    <?php
    do_action('wc_xpay_before_payment_widget');
    ?> 
    <div id="wc_xpay_widget"></div>
    <div id="wc_xpay_payment-message" class="hidden"></div>
</div>