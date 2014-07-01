<?php
/**
 * This class provides a model for a payment processor. To implement a
 * different credit card payment gateway, create a new class that extends
 * SEC_Credit_Card_Processors. The new class should implement
 * the following methods (at a minimum):
 *  - get_instance()
 *  - process_payment()
 *  - register()
 *  - get_payment_method()
 *
 * You may also want to register some settings for the Payment Options page
 */
 
class SEC_VirtualMerchant extends Group_Buying_Credit_Card_Processors {
	const API_ENDPOINT_SANDBOX = 'https://demo.myvirtualmerchant.com/VirtualMerchantDemo/process.do';
	const API_ENDPOINT_LIVE = 'https://www.myvirtualmerchant.com/VirtualMerchant/process.do';
	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';
	const API_USERNAME_OPTION = 'sec_virtualmerchant_username';
	const API_PASSWORD_OPTION = 'sec_virtualmerchant_password';
	const API_PIN_OPTION = 'sec_virtualmerchant_pin';
	const API_MODE_OPTION = 'sec_virtualmerchant_mode';
	const PAYMENT_METHOD = 'Credit (VirtualMerchant)';
	protected static $instance;
	private $api_mode = self::MODE_TEST;
	private $api_username = '';
	private $api_password = '';
	private $api_pin = '';
	
	public static function get_instance() {
		if ( !(isset(self::$instance) && is_a(self::$instance, __CLASS__)) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_api_url() {
		if ( $this->api_mode == self::MODE_LIVE ) {
			return self::API_ENDPOINT_LIVE;
		} else {
			return self::API_ENDPOINT_SANDBOX;
		}
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	protected function __construct() {
		parent::__construct();
		$this->api_username = get_option(self::API_USERNAME_OPTION, '');
		$this->api_password = get_option(self::API_PASSWORD_OPTION, '');
		$this->api_pin = get_option( self::API_PIN_OPTION, '' );
		$this->api_mode = get_option(self::API_MODE_OPTION, self::MODE_TEST);

		if ( is_admin() ) {
			add_action( 'init', array( get_class(), 'register_options') );
		}

		add_action('purchase_completed', array($this, 'complete_purchase'), 10, 1);

		// Limitations
		add_filter( 'group_buying_template_meta-boxes/deal-expiration.php', array( $this, 'display_exp_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta-boxes/deal-price.php', array( $this, 'display_price_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta-boxes/deal-limits.php', array( $this, 'display_limits_meta_box' ), 10 );
	}

	/**
	 * Hooked on init add the settings page and options.
	 *
	 */
	public static function register_options() {
		// Settings
		$settings = array(
			'sec_virtualmerchant_settings' => array(
				'title' => self::__( 'Authorize.net' ),
				'weight' => 200,
				'settings' => array(
					self::API_MODE_OPTION => array(
						'label' => self::__( 'Mode' ),
						'option' => array(
							'type' => 'radios',
							'options' => array(
								self::MODE_LIVE => self::__( 'Live' ),
								self::MODE_TEST => self::__( 'Sandbox' ),
								),
							'default' => get_option( self::API_MODE_OPTION, self::MODE_TEST )
							)
						),
					self::API_USERNAME_OPTION => array(
						'label' => self::__( 'VirtualMerchant ID' ),
						'option' => array(
							'type' => 'text',
							'default' => get_option( self::API_USERNAME_OPTION, '' )
							),
						'description' => self::__('ID as provided by Elavon')
						),
					self::API_PASSWORD_OPTION => array(
						'label' => self::__( 'VirtualMerchant user ID' ),
						'option' => array(
							'type' => 'text',
							'default' => get_option( self::API_PASSWORD_OPTION, '' )
							),
						'description' => self::__('ID as configured on VirtualMerchant (case sensitive)')
						),
					self::API_PIN_OPTION => array(
						'label' => self::__( 'VirtualMerchant PIN' ),
						'option' => array(
							'type' => 'text',
							'default' => get_option( self::API_PIN_OPTION, '' )
							),
						'description' => self::__('PIN as generated within VirtualMerchant (case sensitive)')
						)
					)
				)
			);
		do_action( 'gb_settings', $settings, Group_Buying_Payment_Processors::SETTINGS_PAGE );
	}

	public static function register() {
		self::add_payment_processor(__CLASS__, self::__('Virtual Merchant'));
	}

	/**
	 * Process a payment
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( $purchase->get_total($this->get_payment_method()) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase($purchase->get_id());
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance($payment_id);
				return $payment;
			}
		}

		$post_data = $this->nvp_data_array($checkout, $purchase);
		if ( self::DEBUG ) error_log( '----------Virtual Merchant DATA----------' . print_r( $post_data, true ) );
		$post_string = "";
		
		foreach ( $post_data as $key => $value ) {
			if ( $key == 'x_line_item' ) {
				$post_string .= "{$key}=".$value."&";
			} else {
				$post_string .= "{$key}=".urlencode( $value )."&";
			}
		}
		$post_string = rtrim( $post_string, "& " );
		if ( self::DEBUG ) error_log( "post_string: " . print_r( $post_string, true ) );

		$raw_response = wp_remote_post( $this->get_api_url(), array(
  			'method' => 'POST',
			'body' => $post_string,
			'timeout' => apply_filters( 'http_request_timeout', 15),
			'sslverify' => false
		));
		if ( is_wp_error( $raw_response ) ) {
			return FALSE;
		}
		$response = wp_remote_retrieve_body( $raw_response );
		if ( self::DEBUG ) error_log( '----------Virtual Merchant Response----------' . print_r($response, TRUE));

		if ( $post_data['ssl_result_format'] == 'HTML' ) {
			print $response;
		}
		
		// Build array from response
		$response_result = array();
		$response_values = explode( "\n", $response );
		foreach ($response_values as $value) {
			list($k, $v) = explode('=', $value);
			$response_result[ $k ] = $v;
		}
		if ( self::DEBUG ) error_log( '----------Virtual Merchant Response----------' . print_r($response_result, TRUE));

		// Check if there's an error.
		if ( isset( $response_result['errorMessage'] ) ) {
			$this->set_error_messages( $response_result['errorMessage'] );
			return FALSE;
		}

		if ( !isset( $response_result['ssl_result_message'] ) || $response_result['ssl_result_message'] != 'APPROVAL' ) {
			$this->set_error_messages( self::__('Declined. ID: ') . $response_result['ssl_txn_id'] );
			return FALSE;
		}

		$deal_info = array(); // creating purchased products array for payment below
		foreach ( $purchase->get_products() as $item ) {
			if ( isset($item['payment_method'][$this->get_payment_method()]) ) {
				if ( !isset($deal_info[$item['deal_id']]) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset($checkout->cache['shipping']) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}
		$payment_id = Group_Buying_Payment::new_payment(array(
			'payment_method' => $this->get_payment_method(),
			'purchase' => $purchase->get_id(),
			'amount' => $post_data['ssl_amount'],
			'data' => array(
				'txn_id' => $response_result['ssl_txn_id'],
				'api_response' => $response_result,
				'api_raw_response' => $response,
				'masked_cc_number' => $this->mask_card_number($this->cc_cache['cc_number']), // save for possible credits later
			),
			'deals' => $deal_info,
			'shipping_address' => $shipping_address,
		), Group_Buying_Payment::STATUS_AUTHORIZED);

		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance($payment_id);
		do_action('payment_authorized', $payment);
		$payment->set_data($response);
		return $payment;
	}

	/**
	 * Complete the purchase after the process_payment action, otherwise vouchers will not be activated.
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function complete_purchase( Group_Buying_Purchase $purchase ) {
		$items_captured = array(); // Creating simple array of items that are captured
		foreach ( $purchase->get_products() as $item ) {
			$items_captured[] = $item['deal_id'];
		}
		$payments = Group_Buying_Payment::get_payments_for_purchase($purchase->get_id());
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance($payment_id);
			do_action('payment_captured', $payment, $items_captured);
			do_action('payment_complete', $payment);
			$payment->set_status(Group_Buying_Payment::STATUS_COMPLETE);
		}
	}


	/**
	 * Grabs error messages from a Authorize response and displays them to the user
	 * @param array $response
	 * @param bool $display
	 * @return void
	 */
	private function set_error_messages( $response, $display = TRUE ) {
		if ( $display ) {
			self::set_message($response, self::MESSAGE_STATUS_ERROR);
		} else {
			error_log($response);
		}
	}

	/**
	 * Build the NVP data array for submitting the current checkout to Authorize as an Authorization request
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	private function nvp_data_array( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( self::DEBUG ) error_log( "checkout: " . print_r( $checkout->cache, true ) );
		$user = get_userdata($purchase->get_user());
		$NVP= array();

		$NVP['ssl_merchant_id'] = $this->api_username;
		$NVP['ssl_user_id'] = $this->api_password;
		$NVP['ssl_pin'] = $this->api_pin;

		$NVP['ssl_amount'] = gb_get_number_format($purchase->get_total($this->get_payment_method()));
		$NVP['ssl_transaction_type'] = 'ccsale';

		$NVP['ssl_card_number'] = $this->cc_cache['cc_number'];
		$NVP['ssl_exp_date'] = substr('0' . $this->cc_cache['cc_expiration_month'], -2) . substr($this->cc_cache['cc_expiration_year'], -2);
		$NVP['ssl_cvv2cvc2_indicator'] = 1;
		$NVP['ssl_cvv2cvc2'] = $this->cc_cache['cc_cvv'];

		$NVP['ssl_avs_address'] = $checkout->cache['billing']['street'];
		$NVP['ssl_avs_zip'] = $checkout->cache['billing']['postal_code'];
		$NVP['ssl_email'] = $user->user_email;


		$NVP['ssl_show_form'] = 'false';
		$NVP['ssl_invoice_number'] = $purchase->get_id();
		$NVP['ssl_result_format'] = 'ASCII';
		//$NVP['ssl_result_format'] = 'HTML';

		$NVP['ssl_cardholder_ip'] = ( $_SERVER['SERVER_ADDR'] != '127.0.0.1' ) ? $_SERVER['SERVER_ADDR'] : '107.170.247.98';

		$NVP['ssl_company'] = ( isset( $checkout->cache['billing']['company'] ) ) ? $checkout->cache['billing']['company'] : '';
		$NVP['ssl_first_name'] = $checkout->cache['billing']['first_name'];
		$NVP['ssl_last_name'] = $checkout->cache['billing']['last_name'];
		$NVP['ssl_city'] = $checkout->cache['billing']['city'];
		$NVP['ssl_state'] = $checkout->cache['billing']['zone'];
		$NVP['ssl_country'] = self::country_code( $checkout->cache['billing']['country'] );
		$NVP['ssl_phone'] = ( isset( $checkout->cache['billing']['phone'] ) ) ? $checkout->cache['billing']['phone'] : '';

		$NVP = apply_filters('sec_virtualmerchant_nvp_data', $NVP); 

		return $NVP;
	}


	public function display_exp_meta_box() {
		return dirname( __FILE__ ) . '/views/meta-boxes/exp-only.php';
	}

	public function display_price_meta_box() {
		return dirname( __FILE__ ) . '/views/meta-boxes/no-dyn-price.php';
	}

	public function display_limits_meta_box() {
		return dirname( __FILE__ ) . '/views/meta-boxes/no-tipping.php';
	}

	private static function country_code( $country = null ) {
		if ( null != $country ) {
			return $country;
		}
		return 'USA';
	}

}
SEC_VirtualMerchant::register();