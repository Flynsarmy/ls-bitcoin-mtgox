<?

	class FlynsarmyMtGox_MtGox_Payment extends Shop_PaymentType
	{
		protected $response;
		protected $response_raw;

		const CACHE_DURATION = 60;

		const
			API_ORDER_CREATE = '1/generic/private/merchant/order/create',
			API_INFO         = '1/generic/private/info',
			API_TICKER       = '1/BTC%s/ticker';

		protected $_supportedCurrencies = array(
			'EUR', 'PLN', 'JPY', 'USD', 'AUD', 'CAD', 'GBP', 'CHF',
			'RUB', 'SEK', 'DKK', 'HKD', 'CNY', 'SGD', 'THB', 'NZD',
			'NOK',
		);

		/**
		 * Returns information about the payment type
		 * Must return array: array(
		 *		'name'=>'Authorize.net',
		 *		'custom_payment_form'=>false,
		 *		'offline'=>false,
		 *		'pay_offline_message'=>null
		 * ).
		 * Use custom_paymen_form key to specify a name of a partial to use for building a back-end
		 * payment form. Usually it is needed for forms which ACTION refer outside web services,
		 * like PayPal Standard. Otherwise override build_payment_form method to build back-end payment
		 * forms.
		 * If the payment type provides a front-end partial (containing the payment form),
		 * it should be called in following way: payment:name, in lower case, e.g. payment:authorize.net
		 *
		 * Set index 'offline' to true to specify that the payments of this type cannot be processed online
		 * and thus they have no payment form. You may specify a message to display on the payment page
		 * for offline payment type, using 'pay_offline_message' index.
		 *
		 * @return array
		 */
		public function get_info()
		{
			return array(
				'name' => "Mt.Gox",
				'description'=>'Bitcoin protocol implementation using Mt.Gox',
				//'has_receipt_page'=>false,
				'custom_payment_form'=>'backend_payment_form.htm'
			);
		}

		/**
		 * Builds the payment type administration user interface
		 * For drop-down and radio fields you should also add methods returning
		 * options. For example, of you want to have Sizes drop-down:
		 * public function get_sizes_options();
		 * This method should return array with keys corresponding your option identifiers
		 * and values corresponding its titles.
		 *
		 * @param $host_obj ActiveRecord object to add fields to
		 * @param string $context Form context. In preview mode its value is 'preview'
		 */
		public function build_config_ui($host_obj, $context = null)
		{
			//$host_obj->add_field('test_mode', 'Sandbox Mode')->tab('Configuration')->renderAs(frm_onoffswitcher)->comment('Use the Merchant Warrior Test Environment to try out Website Payments.', 'above');

			if ($context !== 'preview')
			{
				$host_obj->add_field('api_key', 'API Key')->tab('Configuration')->renderAs(frm_text)->validation()->fn('trim')->required();
				$host_obj->add_field('api_secret_key', 'API Secret Key')->tab('Configuration')->renderAs(frm_text)->validation()->fn('trim')->required();
			}

			$host_obj->add_field('auto_sell', 'Automatically sell received bitcoins', 'left')->tab('Configuration')->renderAs(frm_checkbox)->comment('Automatically sell received bitcoins at market price');
			$host_obj->add_field('receive_email', 'Receive an email on completed transaction', 'right')->tab('Configuration')->renderAs(frm_checkbox)->comment('Receive an email for each successful payment');
			$host_obj->add_field('instant_only', 'Only allow transactions that will settle instantly')->tab('Configuration')->renderAs(frm_checkbox)->comment('This will restrict transactions to only MtGox users');
			//$host_obj->add_field('multipay', 'Allow multiple payments on the same transaction ID', 'right')->tab('Configuration')->renderAs(frm_checkbox);

			$host_obj->add_field('payment_desc', 'Payment description')->tab('Configuration')->renderAs(frm_text)->comment('A small description that will appear on the payment page (defaults to "Payment to <user_login>")', 'above')->validation()->fn('trim');

			$host_obj->add_field('cancel_page', 'Cancel Page')->tab('Configuration')->renderAs(frm_dropdown)->formElementPartial(PATH_APP.'/modules/shop/controllers/partials/_page_selector.htm')->comment('Page which the customer’s browser is redirected to if payment is cancelled.', 'above')->emptyOption('<please select a page>');

			$host_obj->add_field('order_status', 'Order Status')->tab('Configuration')->renderAs(frm_dropdown)->comment('Select status to assign the order in case of successful payment.', 'above', true);
		}

		public function get_order_status_options($current_key_value = -1)
		{
			if ($current_key_value == -1)
				return Shop_OrderStatus::create()->order('name')->find_all()->as_array('name', 'id');

			return Shop_OrderStatus::create()->find($current_key_value)->name;
		}

		public function get_cancel_page_options($current_key_value = -1)
		{
			if ($current_key_value == -1)
				return Cms_Page::create()->order('title')->find_all()->as_array('title', 'id');

			return Cms_Page::create()->find($current_key_value)->title;
		}

		/**
		 * Validates configuration data before it is saved to database
		 * Use host object field_error method to report about errors in data:
		 * $host_obj->field_error('max_weight', 'Max weight should not be less than Min weight');
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 */
		public function validate_config_on_save($host_obj)
		{
			if ( $host_obj->enabled )
			{
				// Validate the API and secret key entered with Mt.Gox
				if ( !$this->isValidConnection($host_obj->api_key, $host_obj->api_secret_key) )
				{
					if ( $this->response['token'] == 'login_error_invalid_rest_key' )
						$host_obj->field_error('api_key', "Error, invalid API or Secret key entered." );
					else
						$host_obj->field_error('api_key', "Error (".$this->response['token']."): ".$this->response['error'] );
				}
			}
		}

		/**
		 * Validates configuration data after it is loaded from database
		 * Use host object to access fields previously added with build_config_ui method.
		 * You can alter field values if you need
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 */
		public function validate_config_on_load($host_obj)
		{
		}

		/**
		 * Initializes configuration data when the payment method is first created
		 * Use host object to access and set fields previously added with build_config_ui method.
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 */
		public function init_config_data($host_obj)
		{

		}

		public function get_return_page_options($current_key_value = -1)
		{
			if ($current_key_value == -1)
				return Cms_Page::create()->order('title')->find_all()->as_array('title', 'id');

			return Cms_Page::create()->find($current_key_value)->title;
		}

		/**
		 * Processes payment using passed data
		 * @param array $data Posted payment form data
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 * @param $order Order object
		 */
		public function process_payment_form($data, $host_obj, $order, $backend = false)
		{
			$this->build_config_ui( $host_obj );

			$request = array();

			$request['amount'] = round($order->total, 2);
			$request['currency'] = Shop_CurrencySettings::get()->code;
			$request['ipn'] = Phpr::$request->getRootUrl().root_url('/ls_flynsarmymtgox_mtgox_ipn/'.$order->order_hash);
			if ( !$backend )
			{
				$request['return_success'] = Phpr::$request->getRootUrl().root_url('/ls_flynsarmymtgox_mtgox_autoreturn/'.$order->order_hash);

				$cancel_page = $this->get_cancel_page($host_obj);
				if ($cancel_page)
				{
					$request['cancel_return'] = Phpr::$request->getRootUrl().root_url($cancel_page->url);
					if ($cancel_page->action_reference == 'shop:pay')
						$request['return_failure'] .= '/'.$order->order_hash;
					elseif ($cancel_page->action_reference == 'shop:order')
						$request['return_failure'] .= '/'.$order->id;
				}
			}
			else
			{
				$request['return_success'] = Phpr::$request->getRootUrl().root_url('/ls_flynsarmymtgox_mtgox_autoreturn/'.$order->order_hash.'/backend');
				//	$request['return'] = Phpr::$request->getRootUrl().url('shop/orders/preview/'.$order->id.'?'.uniqid());
				$request['return_failure'] = Phpr::$request->getRootUrl().url('shop/orders/pay/'.$order->id.'?'.uniqid());
			}

			if ( $host_obj->payment_desc ) $request['description'] = $host_obj->payment_desc;
			if ( $host_obj->auto_sell ) $request['autosell'] = 1;
			if ( $host_obj->receive_email ) $request['email'] = 1;
			//if ( $host_obj->multipay ) $request['multipay'] = 1;
			if ( $host_obj->instant_only ) $request['instant_only'] = 1;

			foreach($request as $key=>$value)
			{
				$request[$key] = str_replace("\n", ' ', $value);
			}

			if ( !$this->isSupportedCurrency($request['currency']) )
			{
				$this->log_payment_attempt(
					$order,
					'Unsupported currency code: ' . $request['currency'],
					0,
					$request,
					array()
				);
				throw new Phpr_ApplicationException("Bitcoin currently does not support the " . $request['currency'] . " currency. Please contact the site owner and reference your order ID of " . $order->id.'.');
			}

			$result = $this->sendQuery($host_obj->api_key, $host_obj->api_secret_key, self::API_ORDER_CREATE, $request);

			if ( !$result || isset($result['result']) && $result['result'] == 'error' )
			{
				$this->log_payment_attempt(
					$order,
					'Failed to create Mt.Gox API order',
					0,
					$request,
					is_array($this->response) ? $this->response : array(),
					$this->response_raw
				);

				throw new Phpr_ApplicationException("An error occurred while creating your order. Please contact the site owner and reference your order ID of " . $order->id.'.');
			}

			$this->log_payment_attempt(
				$order,
				'Mt.Gox API order created',
				1,
				$request,
				is_array($this->response['return']) ? $this->response['return'] : array(),
				$this->response_raw
			);

			Phpr::$response->redirect( $result['return']['payment_url']);
		}

		/**
		 * Registers a hidden page with specific URL. Use this method for cases when you
		 * need to have a hidden landing page for a specific payment gateway. For example,
		 * PayPal needs a landing page for the auto-return feature.
		 * Important! Payment module access point names should have the ls_ prefix.
		 * @return array Returns an array containing page URLs and methods to call for each URL:
		 * return array('ls_paypal_autoreturn'=>'process_paypal_autoreturn'). The processing methods must be declared
		 * in the payment type class. Processing methods must accept one parameter - an array of URL segments
		 * following the access point. For example, if URL is /ls_paypal_autoreturn/1234 an array with single
		 * value '1234' will be passed to process_paypal_autoreturn method
		 */
		public function register_access_points()
		{
			return array(
				'ls_flynsarmymtgox_mtgox_autoreturn'=>'process_autoreturn',
				'ls_flynsarmymtgox_mtgox_ipn'=>'process_ipn'
			);
		}

		protected function get_cancel_page($host_obj)
		{
			$cancel_page = $host_obj->cancel_page;
			$page_info = Cms_PageReference::get_page_info($host_obj, 'cancel_page', $host_obj->cancel_page);
			if (is_object($page_info))
				$cancel_page = $page_info->page_id;

			if (!$cancel_page)
				return null;

			return Cms_Page::create()->find($cancel_page);
		}

		public function process_ipn($params)
		{
			try
			{
				$order = null;

				/*
				 * Find order and load paypal settings
				 */

				sleep(5);

				$order_hash = array_key_exists(0, $params) ? $params[0] : null;
				if (!$order_hash)
					throw new Phpr_ApplicationException('Order not found');

				$order = Shop_Order::create()->find_by_order_hash($order_hash);
				if (!$order)
					throw new Phpr_ApplicationException('Order not found.');

				if (!$order->payment_method)
					throw new Phpr_ApplicationException('Payment method not found.');

				$order->payment_method->define_form_fields();
				$payment_method_obj = $order->payment_method->get_paymenttype_object();

				if (!($payment_method_obj instanceof FlynsarmyMtGox_MtGox_Payment))
					throw new Phpr_ApplicationException('Invalid payment method.');

				$payment_method_obj->build_config_ui($order->payment_method);

				$raw_post_data = file_get_contents("php://input");
				$is_valid_ipn = $payment_method_obj->is_valid_ipn(
					$raw_post_data,
					isset($_SERVER['HTTP_REST_SIGN']) ? $_SERVER['HTTP_REST_SIGN'] : '',
					$order->payment_method->api_secret_key
				);
				if ( !$is_valid_ipn )
					throw new Phpr_ApplicationException("Invalid IPN.");

				$status	  = post('status');
				$transaction_id   = post('payment_id');

				if (!$order->payment_processed(false))
				{
					switch ($status) {
						case 'paid':
							if ($order->set_payment_processed())
							{
								Shop_OrderStatusLog::create_record($order->payment_method->order_status, $order);
								$this->log_payment_attempt($order, 'Successful payment', 1, array(), $_POST, '');
								if( isset($transaction_id) && strlen($transaction_id) )
									$this->update_transaction_status($order->payment_method, $order, $transaction_id, 'Processed', 'processed');
							}
							break;
						case 'partial':
							$this->log_payment_attempt($order, 'Partial payment: '.format_currency(post('amount_valid')), 0, array(), $_POST, '');
							break;
						case 'cancelled':
							$this->log_payment_attempt($order, 'Payment cancelled', 0, array(), $_POST, '');
							break;
						default:
							$this->log_payment_attempt($order, 'Payment failed', 0, array(), $_POST, '');
							break;
					}
				}

				$log = sprintf("IPN Success: Params: %s,  GET: %s, POST: %s",
					print_r($params, true),
					print_r(Phpr::$request->get_fields, true),
					print_r($_POST, true)
				);
				FlynsarmyMtGox_Module::log($log);

				echo '[OK]';
				exit;
			}
			catch (Exception $ex)
			{
				if ($order)
					$this->log_payment_attempt($order, $ex->getMessage(), 0, array(), $_POST, null);

				$log = sprintf("IPN Error: ERROR: %s, Params: %s,  GET: %s, POST: %s",
					print_r($params, true),
					print_r(Phpr::$request->get_fields, true),
					print_r($_POST, true),
					$ex->getMessage()
				);
				FlynsarmyMtGox_Module::log($log);

				throw new Phpr_ApplicationException($ex->getMessage());
			}
		}

		public function process_autoreturn($params)
		{
			try
			{
				$order = null;

				$response = null;

				/*
				 * Find order and load paypal settings
				 */

				$order_hash = array_key_exists(0, $params) ? $params[0] : null;
				if (!$order_hash)
					throw new Phpr_ApplicationException('Order not found');

				$order = Shop_Order::create()->find_by_order_hash($order_hash);
				if (!$order)
					throw new Phpr_ApplicationException('Order not found.');

				if (!$order->payment_method)
					throw new Phpr_ApplicationException('Payment method not found.');

				$order->payment_method->define_form_fields();
				$payment_method_obj = $order->payment_method->get_paymenttype_object();

				if (!($payment_method_obj instanceof FlynsarmyMtGox_MtGox_Payment))
					throw new Phpr_ApplicationException('Invalid payment method.');

				$is_backend = array_key_exists(1, $params) ? $params[1] == 'backend' : false;

				if (!$is_backend)
				{
					$return_page = $order->payment_method->receipt_page;
					if ($return_page)
						Phpr::$response->redirect(root_url($return_page->url.'/'.$order->order_hash).'?utm_nooverride=1');
					else
						throw new Phpr_ApplicationException('Bitcoin Receipt page is not found.');
				} else
				{
					Phpr::$response->redirect(url('/shop/orders/payment_accepted/'.$order->id.'?utm_nooverride=1&nocache'.uniqid()));
				}
			}
			catch (Exception $ex)
			{
				if ($order)
					$this->log_payment_attempt($order, $ex->getMessage(), 0, array(), Phpr::$request->get_fields, $response);

				throw new Phpr_ApplicationException($ex->getMessage());
			}
		}

		/**
		 * This function is called before a CMS page deletion.
		 * Use this method to check whether the payment method
		 * references a page. If so, throw Phpr_ApplicationException
		 * with explanation why the page cannot be deleted.
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 * @param Cms_Page $page Specifies a page to be deleted
		 */
		public function page_deletion_check($host_obj, $page)
		{
			if ($host_obj->cancel_page == $page->id)
				throw new Phpr_ApplicationException('Page cannot be deleted because it is used in Bitcoin payment method as a cancel page.');
		}

		/**
		 * This function is called before an order status deletion.
		 * Use this method to check whether the payment method
		 * references an order status. If so, throw Phpr_ApplicationException
		 * with explanation why the status cannot be deleted.
		 * @param $host_obj ActiveRecord object containing configuration fields values
		 * @param Shop_OrderStatus $status Specifies a status to be deleted
		 */
		public function status_deletion_check($host_obj, $status)
		{
			if ($host_obj->order_status == $status->id)
				throw new Phpr_ApplicationException('Status cannot be deleted because it is used in Bitcoin payment method.');
		}

		/**
		* This function is used internally to determine the order total as calculated by Bitcoin
		* Used because LS stores order item prices with more precision than is sent to Bitcoin.
		* which occasionally leads to the order totals not matching
		*/
		// private function get_paypal_total($order, $host_obj)
		// {
		// 	if ($host_obj->skip_itemized_data)
		// 		return $order->total;

		// 	$order_total = 0;
		// 	//add up individual order items
		// 	foreach ($order->items as $item)
		// 	{
		// 		$item_price = round($item->unit_total_price, 2);
		// 		$order_total = $order_total + ($item->quantity * $item_price);
		// 	}

		// 	//add shipping quote + tax
		// 	$order_total = $order_total + $order->shipping_quote;
		// 	if ($order->shipping_tax > 0)
		// 		$order_total = $order_total + $order->shipping_tax;

		// 	//order items tax
		// 	$cart_tax = round($order->goods_tax, 2);
		// 	$order_total = $order_total + $cart_tax;

		// 	return $order_total;
		// }

		public function extend_transaction_preview($payment_method_obj, $controller, $transaction)
		{

		}














		/**
		 * Checks whether a POST request is signed correctly.
		 *
		 * @param  string  $raw_post_data
		 * @param  string  $signature
		 *
		 * @return boolean                Success on correct signing
		 */
		function is_valid_ipn( $raw_post_data, $signature, $api_secret_key ) {
			$good_sign = hash_hmac('sha512', $raw_post_data, base64_decode($api_secret_key), true);
			$sign = base64_decode($signature);

			return ($sign == $good_sign);
		}


		/**
		 * Ensure that the connection is valid with the given API key + secret
		 *
		 * @param string $key    mtgox key
		 * @param string $secret mtgox secret key
		 *
		 * @return boolean
		 */
		public function isValidConnection( $key, $secret )
		{
			$response = $this->mtgoxQuery( $key, $secret, self::API_INFO );
			return $response['result'] === 'success';
		}

		/**
		 * Send data to specific mtgox api url
		 *
		 * @staticvar null $ch
		 *
		 * @param string $key    mtgox key
		 * @param string $secret mtgox secret key
		 * @param string $path   mtgox api path
		 * @param array  $req	data to be sent
		 * @see https://en.bitcoin.it/wiki/MtGox/API/HTTP/v1
		 *
		 * @return array
		 */
		protected function mtgoxQuery( $key, $secret, $path, array $req = array() )
		{
			$mt = explode(' ', microtime());
			$req['nonce'] = $mt[1] . substr($mt[0], 2, 6);

			if ($key && $secret) {
				$postData = http_build_query($req, '', '&');
				$headers = array(
					'Rest-Key: ' . $key,
					'Rest-Sign: ' . base64_encode(
						hash_hmac('sha512', $postData, base64_decode($secret), TRUE)
					 ),
				);
			}

			static $ch = NULL;
			if (is_null($ch)) {
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt(
					$ch, CURLOPT_USERAGENT,
					'Mozilla/4.0 (compatible; MtGox PHP client; ' . php_uname('s') . '; PHP/' . phpversion() . ')'
				);
			}
			curl_setopt($ch, CURLOPT_URL, 'https://data.mtgox.com/api/' . $path);

			if ($key && $secret) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			}

			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

			$res = curl_exec($ch);
			$this->response_raw = $res;

			if ($res === FALSE) {
				$msg = 'Could not get reply: ' . curl_error($ch);
				FlynsarmyMtGox_Module::log( $msg );
				Phpr::$session->flash['error'] = $msg;
				$this->response = $res;
				return false;
			}

			$dec = json_decode($res, TRUE);
			if (!$dec) {
				$msg = 'Invalid data received, please make sure connection is working and requested API exists';
				FlynsarmyMtGox_Module::log( $msg );
				Phpr::$session->flash['error'] = $msg;
				$this->response = $res;
				return false;
			}

			$this->response = $dec;
			return $dec;
		}

		public function sendQuery($api_key, $api_secret_key, $path, array $req = array(), $auth = true)
		{
			$_bitcoinKey	= null;
			$_bitcoinSecret = null;

			if ($auth) {
				$_bitcoinKey	= $api_key;
				$_bitcoinSecret = $api_secret_key;;
			}

			return $this->mtgoxQuery($_bitcoinKey, $_bitcoinSecret, $path, $req);
		}

		/**
		 * Check if the currency is supported by MtGox
		 *
		 * @param string $currency currency code (EUR, USD...)
		 *
		 * @return boolean
		 */
		protected function isSupportedCurrency($currency)
		{
			return in_array($currency, $this->_supportedCurrencies);
		}

		/**
		 * Get the BTC rate for the current currency
		 *
		 * @return float
		 */
		public function getCurrencyRate()
		{
			$currencyCode = Shop_CurrencySettings::get()->code;

			// do not convert bitcoins obviously
			if ($currencyCode === 'BTC') {
				return false;
			}

			if (!$this->isSupportedCurrency($currencyCode)) {
				// fail silently, log the error
				FlynsarmyMtGox_Module::log( 'Currency not supported: ' . $currencyCode . '(supported currencies: ' . implode(',', $this->_supportedCurrencies). ')' );
				return false;
			}

			$cacheTag	 = 'FlynsarmyBTC_BTC_rate_' . $currencyCode;
			$cache = Core_CacheBase::create();
			$currencyRate = $cache->get( $cacheTag );
			if (!$currencyRate) {
				$response = $this->sendQuery($this->api_key, $this->api_secret_key, sprintf(self::API_TICKER, $currencyCode), array(), false);

				// something's wrong
				if (!$response OR !isset($response['result'])) {
					return false;
				}

				if ( $response['result'] !== 'success' ) {
					FlynsarmyMtGox_Module::log( 'Could not retrieve the currency rate' );
					return false;
				}

				$currencyRate = $response['return']['avg']['value'];
				$cache->set($cacheTag, $currencyRate, self::CACHE_DURATION);
			}

			return $currencyRate;
		}
	}

?>