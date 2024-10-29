<?php
/* Plugin Name: Allinpay INTL WooCommerce plugin
 * Plugin URI: https://rudrastyh.com/woocommerce/payment-gateway-plugin.html
 * Description: Take credit card payments on your store.
 * Author: aipsg
 * Author URI: http://rudrastyh.com
 * Version: 1.1.0
 */

/*
 * 类本身，请注意，它挂载到plugins_loaded动作钩子内
 */

if (! defined ( 'ABSPATH' )){
	exit (); // Exit if accessed directly
}
add_filter( 'woocommerce_payment_gateways', 'allinpay_INTL_add_gateway_class' );

function allinpay_INTL_add_gateway_class( $gateways ) {
	$gateways[] = 'Allinpay_INTL'; // 在这里添加类名称
	return $gateways;
}

/*
 * 类本身，请注意，它挂载到plugins_loaded动作钩子内
 */
add_action( 'plugins_loaded', 'allinpay_INTL_init_gateway_class' );
function allinpay_INTL_init_gateway_class(){
	class Allinpay_INTL extends WC_Payment_Gateway {
		protected static $button_added = false;
		private static $_instance;
		public static function instance(){
			if(!self::$_instance){
				self::$_instance = new self();
			}
			return self::$_instance;	
		}
		public function __construct() {
			$this->supports = array( 'products','refunds' );
			$this->id = 'allinpay_intl';
			$this->icon =rtrim(plugin_dir_url(__FILE__),'/'). '/icon/allinpay164.png';
			$this->has_fields = false;
			$this->method_title = __('Allinpay INTL','woocommerce');
			$this->init_form_fields();
			$this->title        = $this->get_option ( 'title' );
			$this->method_description = $this->get_option('description');//'This is a payment gateway that uses RSA signature for payment security.';
			//$this->init_settings();
			$this->gateway_url = $this->get_option('trace_url');
			$this->merchant_id = $this->get_option( 'merchant_id' );
			$this->private_key = $this->get_option( 'private_key' );
			$this->public_key = $this->get_option( 'public_key' );
			$this->version = $this->get_option('version');
			$this->debug_log =$this->get_option('debug_log');
			if('yes'== $this->debug_log){
				$this->log = new WC_Logger();
			}
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action('woocommerce_api_allinpay_payment_callback', array($this,'allinpay_payment_callback'));
			add_action('woocommerce_api_allinpay_front_payment_callback', array($this,'allinpay_front_payment_callback'));
			add_filter('woocommerce_order_number', 'allinpay_woocommerce_order_number', 10, 2);
			add_action('woocommerce_checkout_update_order_meta', 'allinpay_save_order_number');
			add_action('woocommerce_order_status_refunded', array($this, 'allinpay_process_refund'));
			if ( !self::$button_added ) {
				add_action('woocommerce_order_item_add_action_buttons',array($this, 'add_allinpay_query_button'));
				self::$button_added = true;
			}
			add_action('admin_enqueue_scripts', array($this,'enqueue_allinpay_query_button_script'));
			add_action('wp_ajax_query_order', array($this,'allinpay_query'));
			add_action('wp_ajax_nopriv_query_order', array($this,'allinpay_query'));
			add_filter('woocommerce_gateway_description', 'custom_payment_descriptions', 10, 2);
			add_action('wp_enqueue_scripts',array($this,'add_allinpay_style'));

			$this->register_activation_hook();

		}
		public function register_activation_hook() {
			register_activation_hook(__FILE__, array($this, 'activation_function'));
		}

		function activation_function(){
			add_action('wp_ajax_query_order', array($this,'allinpay_query'));
			add_action('wp_ajax_nopriv_query_order', array($this,'allinpay_query'));
		}
		function add_allinpay_query_button($order)
		{
			$order_id = $order->get_id();
			$order_status = $order->get_status();
			// Display the button only if the order is in a specific status
			if ($order_status === 'pending' || $order_status==='processing') {
				$button_text = __('Order Query', 'custom-payment-integration');
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$showInfo = '<button type="button" class="button" id="allinpay_query_btn" data-order-id="' . $order_id . '">' . $button_text . '</button>';
				$allowed_html=array(
						'button'=> array(
							'type' => array(),
							'class' => array(),
							'id' => array(),
							'data-order-id' => array()
							)
						);
				echo wp_kses($showInfo,$allowed_html);
			}
		}

		function enqueue_allinpay_query_button_script($hook)
		{
			if ('post.php' !== $hook) {
				return;
			}

			$screen = get_current_screen();
			if ('shop_order' !== $screen->post_type) {
				return;
			}
			wp_enqueue_script('allinpay-query-button-script', rtrim(plugin_dir_url(__FILE__),'/').'/js/custom-query-button.js', array('jquery'), '1.7', true);
			wp_localize_script( 'allinpay-query-button-script', 'custom_script_vars', array(
						'nonce' => wp_create_nonce( 'allinpay_query' ),'ajaxurl'=> admin_url('admin-ajax.php')
						) );
			wp_enqueue_script( 'jquery' );
		}

		function allinpay_query()
		{
			check_ajax_referer( 'allinpay_query', 'nonce' );
			$this->allinpay_log('开始执行自定义查询操作');
			if (isset($_POST['order_id'])) {
				$order_id = intval($_POST['order_id']);
				$order = wc_get_order( $order_id );
				$query_data = array('version' => $this->version,
						'mchtId'=> $this->merchant_id,
						'transType'=> 'Query',
						'oriAccessOrderId'=> $order->get_meta('custom_order_number'),
						'signType' => 'RSA2'
						);
				$signature = $this->generate_signature($query_data);
				$query_data['sign'] = $signature;
				$respResult = $this->request($this->gateway_url,$query_data);
				$this->allinpay_log('查询接口返回的信息为:'.$respResult);
				$parseJson = json_decode($respResult,true);
				if($parseJson!=null){
					$status = $parseJson['status'];
					if($status == 'PAIED'){
						$transaction_id = $parseJson['orderId'];
						$order->payment_complete($transaction_id);
						$order->update_status('completed',__('Payment complete!!!','woocommerce'));
						wp_send_json_success('completed');
					}else if($status =='CLOSE' || $status=='REVOKED' || $status== 'COLSED'){
						$order->update_status('cancelled',__('order cancelled!!!','woocommerce'));
						wp_send_json_success('cancelled');
					}else if($status == 'FAILED' ){
						$order->update_status('failed',__('order failed!!!','woocommerce'));
						wp_send_json_success('failed');
					}else{
						wp_send_json_success('handler');
					}
				}
				wp_die();
			}
			wp_send_json_error('Invalid request.');
			wp_die();
		}
		function add_allinpay_style(){
			
			wp_register_style('allinpay-css-default',rtrim(plugin_dir_url(__FILE__),'/'). '/css/default.css');
			wp_enqueue_style('allinpay-css-default');
		}

		public function init_form_fields() {
			// Payment gateway settings initialization
			$this->form_fields = array(
					'enabled' => array(
						'title' => __('Enable/Disable','allinpay_intl'),
						'type' => 'checkbox',
						'label' => 'Enable Allinpay INTL',
						'default' => 'no'
						),
					'title' => array(
						'title' => 'Title',
						'type' => 'text',
						'description' => 'This controls the title which the user sees during checkout.',
						'default' => __('Allinpay INTL','allinpay_intl'),
						'desc_tip' => true

						),
					'description' => array(
						'title' => 'Description',
						'type' => 'textarea',
						'description' => 'This controls the description which the user sees during checkout.',
						'default' => 'Pay with Allinpay INTL',
						),
					'version' => array(
							'title' => 'Version',
							'type' => 'text',
							'description' => 'This controls the title which the user sees during checkout.',
							'default' => 'V2.0.0',
							'desc_tip' => true
							),

					'trace_url' => array(
							'title' => 'Trace Url',
							'type' => 'text',
							'description' => 'Enter the trace url provided by Payment Gateway.',
							'default' => '',
							'desc_tip' => true
							),
					'merchant_id' => array(
							'title' => 'Merchant ID',
							'type' => 'text',
							'description' => 'Enter your merchant ID provided by Payment Gateway.',
							'desc_tip' => true
							),
					'private_key' => array(
							'title' => 'Private Key',
							'type' => 'textarea',
							'description' => 'Enter your private key provided by  Payment Gateway.',
							'desc_tip' => true
							),
					'public_key' => array(
							'title' => 'Public Key',
							'type' => 'textarea',
							'description' => 'Enter your public key provided by  Payment Gateway.',
							'desc_tip' => true
							),

					'debug_log' => array(
							'title' => __('debug_log','allinpay_intl'),
							'type' => 'checkbox',
							'description' => __('Log payment events,such as trade status,inside<code>wp-content/uploads/wc-logs/</code> ','allinpay_intl'),
							'default' => 'no'
							)

						);
		}

		public function payment_fields(){
			if ( $this->method_description) {
				echo wpautop( wptexturize($this->method_description) );
			}
		}
		public function process_payment( $order_id ) {
			// Construct payment request data
			$order = wc_get_order( $order_id );
			$items = $order->get_items();
			$weldpay_items = array();
			$weldpay_item = array();
			foreach($items as $item){
				$weldpay_item = array(
						'sku' => $item['product_id'],
						'productName' => $item['name'],
						'price'=> $item['total'],
						'quantity'=> $item['quantity'],
						);
				$weldpay_items[]=json_encode($weldpay_item);
			}
			//var_dump($weldpay_items);
			$payment_data = array(
					'version' => $this->version,
					'mchtId' => $this->merchant_id,
					'transType' => 'CashierPay',
					//'accessOrderId' => $order->get_id(),
					'accessOrderId' => $order->get_meta('custom_order_number'),
					'currency' => $order->get_currency(),
					'amount' => $order->get_total(),
					'language' =>substr(get_locale(), 0, 2),
					//'payPageStyle' => 'DEFAULT',
					'email' =>  $order->get_billing_email(),
					'returnUrl' => WC()->api_request_url('allinpay_front_payment_callback'),
					'notifyUrl' =>  WC()->api_request_url('allinpay_payment_callback'),
					//'timeZone' //持卡人时区，可选

					'signType' => 'RSA2',//签名类型
					//'txnTitle' => $order->get_title(),
					//'txnDetail' => $order->get_title(),


					'shippingFirstName' => !empty($order->get_shipping_first_name()) ? $order->get_shipping_first_name():$order->get_billing_first_name(),
					//'shippingFirstName' => $order->get_shipping_first_name(),
					'shippingLastName' => !empty($order->get_shipping_last_name()) ? $order->get_shipping_last_name():$order->get_billing_last_name(),
					'shippingAddress1' => !empty($order->get_shipping_address_1())? $order->get_shipping_address_1():$order->get_billing_address_1(),
					//'shippingAddress2' => $order->get_shipping_address_2(), //可选
					'shippingCity' => !empty($order->get_shipping_city())? $order->get_shipping_city():$order->get_billing_city(),
					//'shippingState'=> $order->get_shipping_state(),
					'shippingState'=>!empty($order->get_shipping_state()) ? $order->get_shipping_state() :( !empty($order->get_shipping_city())?$order->get_shipping_city():$order->get_billing_city()),
					'shippingCountry'=>!empty($order->get_shipping_country())?$order->get_shipping_country():$order->get_billing_country(),
					'shippingZipCode' => !empty($order->get_shipping_postcode())?$order->get_shipping_postcode():$order->get_billing_postcode(),
					'shippingPhone' => $order->get_billing_phone(),

					'billingFirstName' => $order->get_billing_first_name(),
					'billingLastName' => $order->get_billing_last_name(),
					'billingAddress1' => $order->get_billing_address_1(),
					//'billingAddress2' => $order->get_billing_address_2(),
					'billingCity' => $order->get_billing_city(),
					'billingState' => $order->get_billing_state(),
					'billingCountry' => $order->get_billing_country(),
					'billingZipCode' => $order->get_billing_postcode(),
					'billingPhone' => $order->get_billing_phone());
			$productInfo =  '[' . implode(',', $weldpay_items) . ']';
			$payment_data['productInfo'] = $productInfo;
			// Generate signature using private key
			$signature = $this->generate_signature( $payment_data );
			// Add signature to payment request data
			$payment_data['sign'] = $signature;
			$respResult = $this->request($this->gateway_url,$payment_data);
			$this->allinpay_log('交易接口同步返回的参数为:'.$respResult);
			$parseJson = json_decode($respResult,true);
			if($parseJson ==null){
				$this->allinpay_log('解析同步返回交易报文失败!!!');
				wp_die();
			}
			if($parseJson['resultCode']!='0000'){
				wc_add_notice( __('Payment error:', 'woothemes') . $parseJson['resultDesc'], 'error' );
				return;
			}
			return array(
					'result' => 'success',
					'redirect' => $parseJson['payUrl']
				    );
		}
		// 将 iframe 插入页面,没啥用
		public function thankyou_page( $order_id ) {
			$order = wc_get_order($order_id);
		}

		public function process_refund($order_id, $amount = null, $reason = ''){
			$order = new WC_Order ( $order_id );
			if(!$order){
				return  new WP_Error( 'invalid_order', 'Invalid Order ID' );
			}
			if($order->get_status() !='completed' && $order->get_status()!='refunded'){
				return new WP_Error('invalid_order','Order status error,can not handler this');
			}
			$platId = $order->get_meta('custom_order_number');
			$refundOrderId = allinpay_order_id();
			if(!$platId){
				return new WP_Error('invalid_order','invalid customer order ID');
			}
			$payment_data = array(
					'version' => $this->version,
					'mchtId' => $this->merchant_id,
					'transType' => 'Refund',
					'accessOrderId' => $refundOrderId,
					'refundAmount' => $amount,
					'oriAccessOrderId'=> $platId,
					'signType' => 'RAS2'
					);

			$signature = $this->generate_signature($payment_data);
			$payment_data['sign'] = $signature;
			$this->allinpay_log('调用退款接口请求的参数为:'.$payment_data);
			$respResult = $this->request($this->gateway_url,$payment_data);
			$this->allinpay_log('退款接口返回的信息为:'.$respResult);
			$parseJson = json_decode($respResult,true);
			if($parseJson == null){
				$this->allinpay_log('解析退款接口返回的报文失败');
				return new WP_Error('order failed','refundOrderFailed');
			}
			if($parseJson['resultCode']!='0000'){
				return new WP_Error('order failed',$parseJson['resultDesc']);
			}
			return true;
		}

		private function toUrlParams(array $array, $isUrlEncode)
		{
			$buff = "";
			foreach ($array as $k => $v) {
				if ($v != "" && !is_array($v)) {
					$buff .= $k . "=";
					if ($isUrlEncode) {
						$buff .= urlencode($v);
					} else {
						$buff .= $v;
					}
					$buff .= "&";
				}
			}

			$buff = trim($buff, "&");
			return $buff;
		}
		private  function request($url, array $array)
		{
			// url encode request data
			$paramsStr = $this->toUrlParams($array, true);
			$args = array(
					'body' => $paramsStr,
					'timeout' => '30',
					'redirection' => '30',
					'headers' =>array(
						'content-type'=> 'application/x-www-form-urlencoded;charset=utf-8'
						)
				     );
			$this->allinpay_log("发送到渠道的请求参数为:".$paramsStr);
			$response = wp_remote_post($url,$args);
			$body     = wp_remote_retrieve_body( $response );
			return $body;
		}

		private function generate_signature( $data ) {
			ksort($data, SORT_STRING);
			$query_string = $this->toUrlParams($data,false);
			$this->allinpay_log('签名串的明文为:'.$query_string);
			// 签名生成的字符串
			$sign_data = utf8_encode($query_string);
			// 进行签名
			$signature = '';
			//$privateKey = "-----BEGIN RSA PRIVATE KEY-----\n".wordwrap($this->private_key, 64, "\n", true)."\n-----END RSA PRIVATE KEY-----";
			$privateKeyResource = openssl_pkey_get_private($this->private_key);
			if ($privateKeyResource === false) {
				$error = openssl_error_string();
				var_dump($error);
			}
			openssl_sign($query_string, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
			openssl_free_key( $privateKeyResource );
			return base64_encode( $signature );
		}
		private function build_query_string($data) {
			$query_string = '';
			foreach ($data as $key => $value) {
				$query_string .= $key . '=' . $value . '&';
			}
			// 去除末尾的 '&'
			$query_string = rtrim($query_string, '&');
			return $query_string;
		}
		//异步通知
		function allinpay_payment_callback() {
			if(!isset($_POST['resultCode'])){
				$this->allinpay_log('后台接口通知返回参数异常,resultCode为空!!!');
				return;
			}
			$resultCode = wc_clean($_POST['resultCode']);
			$resultDesc = wc_clean($_POST['resultDesc']);
			//get json request notify from snappay
			$customerNo = null;
			$order_id = null;
			if(isset($_POST['accessOrderId'])){
				$customerNo =wc_clean($_POST['accessOrderId']);
				if (strlen($customerNo) >= 14) {
					$order_id = substr($customerNo, 14);
				} else {
					wp_die();
				}
			}
			$order = wc_get_order($order_id);
			if($order==null || 'completed'==$order->get_status()){
				wp_die();
			}
			$notifyArray = array(
					'resultCode'=> $resultCode,
					'resultDesc' => $resultDesc,
					'instNo' => wc_clean($_POST['instNo']),
					'mchtId' => wc_clean($_POST['mchtId']),
					'accessOrderId' => $customerNo,
					'orderId' => wc_clean($_POST['orderId']),
					'cardNo' => wc_clean($_POST['cardNo']),
					'currency' => wc_clean($_POST['currency']),
					'amount' => wc_clean($_POST['amount']),
					'signType' => wc_clean($_POST['signType']),
					'payMethod' => wc_clean($_POST['payMethod']),
					);
			if(isset($_POST['payMethod'])){
				$notifyArray['payMethod'] = wc_clean($_POST['payMethod']);
			}
			if(isset($_POST['cardOrgn'])){
				$notifyArray['cardOrgn'] =wc_clean($_POST['cardOrgn']);
			}
			if(isset($_POST['localCurrency'])){
				$notifyArray['localCurrency'] =wc_clean($_POST['localCurrency']);
			}
			if(isset($_POST['localAmount'])){
				$notifyArray['localAmount'] =wc_clean($_POST['localAmount']);
			}
			if(isset($_POST['transTime'])){
				$notifyArray['transTime'] =wc_clean($_POST['transTime']);
			}
			$signature = $_POST['sign'];
			ksort($notifyArray);
			$signStr = $this->toUrlParams($notifyArray,false);
			$signResult = $this->verify_signature($signStr,$signature);
			if(!$signResult){
				$this->allinpay_log('异步通知订单验签失败,订单号:'.$order_id);
				$this->allinpay_log('验签的字符串为:'.$signStr);
				$this->allinpay_log('收到异步通知的报文为'.print_r($notifyArray,1));
				echo 'SUCCESS';
				wp_die();
			}
			$this->allinpay_log('收到异步通知的报文为'.print_r($notifyArray,1));
			// 根据回调参数进行相应的处理逻辑
			if ($notifyArray['resultCode'] === '0000') {
				// 更新订单状态为已完成
				$order = wc_get_order($order_id);
				$order->payment_complete($transaction_id);
				$custom_data = array(
						'localCurrency' => $notifyArray['localCurrency'],
						'localAmount' => $notifyArray['localAmount']
						);

				foreach ($custom_data as $key => $value) {
					$order->update_meta_data($key, $value);
				}
				$order->update_status( 'completed', __( 'Payment complete!!!.', 'woocommerce' ) );
				$order->save();
				echo 'SUCCESS';
				wp_die();
			} else {
				$custom_data = array(
						'resultCode' => $notifyArray['resultCode'],
						'resultDesc' => $notifyArray['resultDesc']
						);

				foreach ($custom_data as $key => $value) {
					$order->update_meta_data($key, $value);
				}
				$order->update_status( 'failed', $notifyArray['resultDesc'] );
				$order->save();
				echo 'SUCCESS';
				wp_die();
			}
		}
		function allinpay_front_payment_callback() {
			// 获取回调参数
			if(!isset($_GET['resultCode'])){
				$this->allinpay_log('前台通知返回参数异常,resultCode为空!!!');
				return;
			}
			$result_code = wc_clean($_GET['resultCode']);
			$customerNo= wc_clean($_GET['accessOrderId']); // 从回调参数中获取订单ID
			$order_id = substr($customerNo,14); // 从回调参数中获取订单ID
			$card_no = wc_clean($_GET['cardNo']);
			$result_desc = wc_clean($_GET['resultDesc']);
			$transaction_id = wc_clean($_GET['orderId']); // 从回调参数中获取交易ID
			// 根据回调参数进行相应的处理逻辑
			if ($result_code === '0000') {
				// 更新订单状态为处理中
				$order = wc_get_order($order_id);
				$order_status = $order->get_status();
				if($order->get_status() =='pending'){
					$order->update_status( 'processing', __( 'Payment processing.', 'woocommerce' ) );
				}
				// 处理支付成功后的逻辑，例如发送订单确认邮件、更新库存等
				$redirect_url = $order->get_checkout_order_received_url();
				wp_redirect($redirect_url);
				exit;
			} else {
				// 重定向用户到支付失败页面
				$this->allinpay_log('前端页面通知订单异常!,订单号'.$order_id);
				$redirect_url = wc_get_page_permalink('checkout') . '?payment_status=failure';
				wp_redirect($redirect_url);
				exit;
			}
		}

		private function verify_signature( $data, $signature ) {
			// 对数据进行 SHA256 哈希运算
			$public_key_resource = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" .  wordwrap($this->public_key, 64, "\n", true) .  "\n-----END PUBLIC KEY-----");
			//$data=utf8_encode($data);
			$public_key_resource = openssl_pkey_get_public($this->public_key);
			// 对签名进行解码（通常使用 Base64 解码）
			$decodedSignature = base64_decode($signature);
			// 验签
			$result = openssl_verify($data, $decodedSignature, $public_key_resource, OPENSSL_ALGO_SHA256);
			openssl_free_key( $public_key_resource );
			return $result === 1;
		}
		private function allinpay_log($message){
			if('yes'==$this->debug_log){
				if(is_array($message)|| is_object($message)){
					$this->log->add('allinpay_intl',print_r($message,1));
				}else{
					$this->log->add('allinpay_intl',$message);
				}
			}
		}


	}

	
		function custom_payment_descriptions($description, $payment_id) {
		    switch ($payment_id) {
		        case 'allinpay_intl':
		            $description .= '<p>This is the description for allInPay.</p>';
		            break;
		        // Add descriptions for other payment methods as needed
		        default:
		            break;
		    }
		    return $description;
		}

	function allinpay_woocommerce_order_number($order_number, $order)
	{
		if ($order->get_date_created() instanceof WC_DateTime && $order->get_date_created()->getTimestamp() > time()) {
			// 新订单，使用自定义订单号生成逻辑
			$custom_order_number = gmdate('YmdHis').$order->get_id(); // 这里将订单号设置为 "MY-订单ID"
			return $custom_order_number;
		}
		// 非新订单，保持原订单号不变
		return $order_number;
	}

	function allinpay_order_id(){
		$custom_order_number = gmdate('YmdHis'); // 自定义订单号生成逻辑
		return 'RF'.$custom_order_number;
	}
	function allinpay_save_order_number($order_id)
	{
		$order = wc_get_order($order_id);
		$custom_order_number = gmdate('YmdHis') . $order_id; // 自定义订单号生成逻辑
		$order->update_meta_data('custom_order_number', $custom_order_number);
		$order->save();
	}

	$my_plugin = new Allinpay_INTL();

}
?>
