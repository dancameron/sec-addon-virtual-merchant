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
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
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
		$this->api_username = get_option( self::API_USERNAME_OPTION, '' );
		$this->api_password = get_option( self::API_PASSWORD_OPTION, '' );
		$this->api_pin = get_option( self::API_PIN_OPTION, '' );
		$this->api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );

		if ( is_admin() ) {
			add_action( 'init', array( get_class(), 'register_options' ) );
		}

		add_action( 'purchase_completed', array( $this, 'complete_purchase' ), 10, 1 );

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
						'description' => self::__( 'ID as provided by Elavon' )
					),
					self::API_PASSWORD_OPTION => array(
						'label' => self::__( 'VirtualMerchant user ID' ),
						'option' => array(
							'type' => 'text',
							'default' => get_option( self::API_PASSWORD_OPTION, '' )
						),
						'description' => self::__( 'ID as configured on VirtualMerchant (case sensitive)' )
					),
					self::API_PIN_OPTION => array(
						'label' => self::__( 'VirtualMerchant PIN' ),
						'option' => array(
							'type' => 'text',
							'default' => get_option( self::API_PIN_OPTION, '' )
						),
						'description' => self::__( 'PIN as generated within VirtualMerchant (case sensitive)' )
					)
				)
			)
		);
		do_action( 'gb_settings', $settings, Group_Buying_Payment_Processors::SETTINGS_PAGE );
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'Virtual Merchant' ) );
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( $purchase->get_total( $this->get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		$post_data = $this->nvp_data_array( $checkout, $purchase );
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
				'timeout' => apply_filters( 'http_request_timeout', 15 ),
				'sslverify' => false
			) );
		if ( is_wp_error( $raw_response ) ) {
			return FALSE;
		}
		$response = wp_remote_retrieve_body( $raw_response );
		if ( self::DEBUG ) error_log( '----------Virtual Merchant Response----------' . print_r( $response, TRUE ) );

		if ( $post_data['ssl_result_format'] == 'HTML' ) {
			print $response;
		}

		// Build array from response
		$response_result = array();
		$response_values = explode( "\n", $response );
		foreach ( $response_values as $value ) {
			list( $k, $v ) = explode( '=', $value );
			$response_result[ $k ] = $v;
		}
		if ( self::DEBUG ) error_log( '----------Virtual Merchant Response----------' . print_r( $response_result, TRUE ) );

		// Check if there's an error.
		if ( isset( $response_result['errorMessage'] ) ) {
			$this->set_error_messages( $response_result['errorMessage'] );
			return FALSE;
		}

		if ( !isset( $response_result['ssl_result_message'] ) || $response_result['ssl_result_message'] != 'APPROVAL' ) {
			$this->set_error_messages( self::__( 'Declined. ID: ' ) . $response_result['ssl_txn_id'] );
			return FALSE;
		}

		$deal_info = array(); // creating purchased products array for payment below
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][$this->get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}
		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => $this->get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => $post_data['ssl_amount'],
				'data' => array(
					'txn_id' => $response_result['ssl_txn_id'],
					'api_response' => $response_result,
					'api_raw_response' => $response,
					'masked_cc_number' => $this->mask_card_number( $this->cc_cache['cc_number'] ), // save for possible credits later
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_AUTHORIZED );

		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );
		$payment->set_data( $response );
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
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			do_action( 'payment_captured', $payment, $items_captured );
			do_action( 'payment_complete', $payment );
			$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
		}
	}


	/**
	 * Grabs error messages from a Authorize response and displays them to the user
	 *
	 * @param array   $response
	 * @param bool    $display
	 * @return void
	 */
	private function set_error_messages( $response, $display = TRUE ) {
		if ( $display ) {
			self::set_message( $response, self::MESSAGE_STATUS_ERROR );
		} else {
			error_log( $response );
		}
	}

	/**
	 * Build the NVP data array for submitting the current checkout to Authorize as an Authorization request
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	private function nvp_data_array( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( self::DEBUG ) error_log( "checkout: " . print_r( $checkout->cache, true ) );
		$user = get_userdata( $purchase->get_user() );
		$NVP= array();

		$NVP['ssl_merchant_id'] = $this->api_username;
		$NVP['ssl_user_id'] = $this->api_password;
		$NVP['ssl_pin'] = $this->api_pin;

		$NVP['ssl_amount'] = gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) );
		$NVP['ssl_transaction_type'] = 'ccsale';

		$NVP['ssl_card_number'] = $this->cc_cache['cc_number'];
		$NVP['ssl_exp_date'] = substr( '0' . $this->cc_cache['cc_expiration_month'], -2 ) . substr( $this->cc_cache['cc_expiration_year'], -2 );
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

		$NVP = apply_filters( 'sec_virtualmerchant_nvp_data', $NVP );

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
		$countries = array(
			"AF" => array( "AFGHANISTAN", "AF", "AFG", "004" ),
			"AL" => array( "ALBANIA", "AL", "ALB", "008" ),
			"DZ" => array( "ALGERIA", "DZ", "DZA", "012" ),
			"AS" => array( "AMERICAN SAMOA", "AS", "ASM", "016" ),
			"AD" => array( "ANDORRA", "AD", "AND", "020" ),
			"AO" => array( "ANGOLA", "AO", "AGO", "024" ),
			"AI" => array( "ANGUILLA", "AI", "AIA", "660" ),
			"AQ" => array( "ANTARCTICA", "AQ", "ATA", "010" ),
			"AG" => array( "ANTIGUA AND BARBUDA", "AG", "ATG", "028" ),
			"AR" => array( "ARGENTINA", "AR", "ARG", "032" ),
			"AM" => array( "ARMENIA", "AM", "ARM", "051" ),
			"AW" => array( "ARUBA", "AW", "ABW", "533" ),
			"AU" => array( "AUSTRALIA", "AU", "AUS", "036" ),
			"AT" => array( "AUSTRIA", "AT", "AUT", "040" ),
			"AZ" => array( "AZERBAIJAN", "AZ", "AZE", "031" ),
			"BS" => array( "BAHAMAS", "BS", "BHS", "044" ),
			"BH" => array( "BAHRAIN", "BH", "BHR", "048" ),
			"BD" => array( "BANGLADESH", "BD", "BGD", "050" ),
			"BB" => array( "BARBADOS", "BB", "BRB", "052" ),
			"BY" => array( "BELARUS", "BY", "BLR", "112" ),
			"BE" => array( "BELGIUM", "BE", "BEL", "056" ),
			"BZ" => array( "BELIZE", "BZ", "BLZ", "084" ),
			"BJ" => array( "BENIN", "BJ", "BEN", "204" ),
			"BM" => array( "BERMUDA", "BM", "BMU", "060" ),
			"BT" => array( "BHUTAN", "BT", "BTN", "064" ),
			"BO" => array( "BOLIVIA", "BO", "BOL", "068" ),
			"BA" => array( "BOSNIA AND HERZEGOVINA", "BA", "BIH", "070" ),
			"BW" => array( "BOTSWANA", "BW", "BWA", "072" ),
			"BV" => array( "BOUVET ISLAND", "BV", "BVT", "074" ),
			"BR" => array( "BRAZIL", "BR", "BRA", "076" ),
			"IO" => array( "BRITISH INDIAN OCEAN TERRITORY", "IO", "IOT", "086" ),
			"BN" => array( "BRUNEI DARUSSALAM", "BN", "BRN", "096" ),
			"BG" => array( "BULGARIA", "BG", "BGR", "100" ),
			"BF" => array( "BURKINA FASO", "BF", "BFA", "854" ),
			"BI" => array( "BURUNDI", "BI", "BDI", "108" ),
			"KH" => array( "CAMBODIA", "KH", "KHM", "116" ),
			"CM" => array( "CAMEROON", "CM", "CMR", "120" ),
			"CA" => array( "CANADA", "CA", "CAN", "124" ),
			"CV" => array( "CAPE VERDE", "CV", "CPV", "132" ),
			"KY" => array( "CAYMAN ISLANDS", "KY", "CYM", "136" ),
			"CF" => array( "CENTRAL AFRICAN REPUBLIC", "CF", "CAF", "140" ),
			"TD" => array( "CHAD", "TD", "TCD", "148" ),
			"CL" => array( "CHILE", "CL", "CHL", "152" ),
			"CN" => array( "CHINA", "CN", "CHN", "156" ),
			"CX" => array( "CHRISTMAS ISLAND", "CX", "CXR", "162" ),
			"CC" => array( "COCOS (KEELING) ISLANDS", "CC", "CCK", "166" ),
			"CO" => array( "COLOMBIA", "CO", "COL", "170" ),
			"KM" => array( "COMOROS", "KM", "COM", "174" ),
			"CG" => array( "CONGO", "CG", "COG", "178" ),
			"CK" => array( "COOK ISLANDS", "CK", "COK", "184" ),
			"CR" => array( "COSTA RICA", "CR", "CRI", "188" ),
			"CI" => array( "COTE D'IVOIRE", "CI", "CIV", "384" ),
			"HR" => array( "CROATIA (local name: Hrvatska)", "HR", "HRV", "191" ),
			"CU" => array( "CUBA", "CU", "CUB", "192" ),
			"CY" => array( "CYPRUS", "CY", "CYP", "196" ),
			"CZ" => array( "CZECH REPUBLIC", "CZ", "CZE", "203" ),
			"DK" => array( "DENMARK", "DK", "DNK", "208" ),
			"DJ" => array( "DJIBOUTI", "DJ", "DJI", "262" ),
			"DM" => array( "DOMINICA", "DM", "DMA", "212" ),
			"DO" => array( "DOMINICAN REPUBLIC", "DO", "DOM", "214" ),
			"TL" => array( "EAST TIMOR", "TL", "TLS", "626" ),
			"EC" => array( "ECUADOR", "EC", "ECU", "218" ),
			"EG" => array( "EGYPT", "EG", "EGY", "818" ),
			"SV" => array( "EL SALVADOR", "SV", "SLV", "222" ),
			"GQ" => array( "EQUATORIAL GUINEA", "GQ", "GNQ", "226" ),
			"ER" => array( "ERITREA", "ER", "ERI", "232" ),
			"EE" => array( "ESTONIA", "EE", "EST", "233" ),
			"ET" => array( "ETHIOPIA", "ET", "ETH", "210" ),
			"FK" => array( "FALKLAND ISLANDS (MALVINAS)", "FK", "FLK", "238" ),
			"FO" => array( "FAROE ISLANDS", "FO", "FRO", "234" ),
			"FJ" => array( "FIJI", "FJ", "FJI", "242" ),
			"FI" => array( "FINLAND", "FI", "FIN", "246" ),
			"FR" => array( "FRANCE", "FR", "FRA", "250" ),
			"FX" => array( "FRANCE, METROPOLITAN", "FX", "FXX", "249" ),
			"GF" => array( "FRENCH GUIANA", "GF", "GUF", "254" ),
			"PF" => array( "FRENCH POLYNESIA", "PF", "PYF", "258" ),
			"TF" => array( "FRENCH SOUTHERN TERRITORIES", "TF", "ATF", "260" ),
			"GA" => array( "GABON", "GA", "GAB", "266" ),
			"GM" => array( "GAMBIA", "GM", "GMB", "270" ),
			"GE" => array( "GEORGIA", "GE", "GEO", "268" ),
			"DE" => array( "GERMANY", "DE", "DEU", "276" ),
			"GH" => array( "GHANA", "GH", "GHA", "288" ),
			"GI" => array( "GIBRALTAR", "GI", "GIB", "292" ),
			"GR" => array( "GREECE", "GR", "GRC", "300" ),
			"GL" => array( "GREENLAND", "GL", "GRL", "304" ),
			"GD" => array( "GRENADA", "GD", "GRD", "308" ),
			"GP" => array( "GUADELOUPE", "GP", "GLP", "312" ),
			"GU" => array( "GUAM", "GU", "GUM", "316" ),
			"GT" => array( "GUATEMALA", "GT", "GTM", "320" ),
			"GN" => array( "GUINEA", "GN", "GIN", "324" ),
			"GW" => array( "GUINEA-BISSAU", "GW", "GNB", "624" ),
			"GY" => array( "GUYANA", "GY", "GUY", "328" ),
			"HT" => array( "HAITI", "HT", "HTI", "332" ),
			"HM" => array( "HEARD ISLAND & MCDONALD ISLANDS", "HM", "HMD", "334" ),
			"HN" => array( "HONDURAS", "HN", "HND", "340" ),
			"HK" => array( "HONG KONG", "HK", "HKG", "344" ),
			"HU" => array( "HUNGARY", "HU", "HUN", "348" ),
			"IS" => array( "ICELAND", "IS", "ISL", "352" ),
			"IN" => array( "INDIA", "IN", "IND", "356" ),
			"ID" => array( "INDONESIA", "ID", "IDN", "360" ),
			"IR" => array( "IRAN, ISLAMIC REPUBLIC OF", "IR", "IRN", "364" ),
			"IQ" => array( "IRAQ", "IQ", "IRQ", "368" ),
			"IE" => array( "IRELAND", "IE", "IRL", "372" ),
			"IL" => array( "ISRAEL", "IL", "ISR", "376" ),
			"IT" => array( "ITALY", "IT", "ITA", "380" ),
			"JM" => array( "JAMAICA", "JM", "JAM", "388" ),
			"JP" => array( "JAPAN", "JP", "JPN", "392" ),
			"JO" => array( "JORDAN", "JO", "JOR", "400" ),
			"KZ" => array( "KAZAKHSTAN", "KZ", "KAZ", "398" ),
			"KE" => array( "KENYA", "KE", "KEN", "404" ),
			"KI" => array( "KIRIBATI", "KI", "KIR", "296" ),
			"KP" => array( "KOREA, DEMOCRATIC PEOPLE'S REPUBLIC OF", "KP", "PRK", "408" ),
			"KR" => array( "KOREA, REPUBLIC OF", "KR", "KOR", "410" ),
			"KW" => array( "KUWAIT", "KW", "KWT", "414" ),
			"KG" => array( "KYRGYZSTAN", "KG", "KGZ", "417" ),
			"LA" => array( "LAO PEOPLE'S DEMOCRATIC REPUBLIC", "LA", "LAO", "418" ),
			"LV" => array( "LATVIA", "LV", "LVA", "428" ),
			"LB" => array( "LEBANON", "LB", "LBN", "422" ),
			"LS" => array( "LESOTHO", "LS", "LSO", "426" ),
			"LR" => array( "LIBERIA", "LR", "LBR", "430" ),
			"LY" => array( "LIBYAN ARAB JAMAHIRIYA", "LY", "LBY", "434" ),
			"LI" => array( "LIECHTENSTEIN", "LI", "LIE", "438" ),
			"LT" => array( "LITHUANIA", "LT", "LTU", "440" ),
			"LU" => array( "LUXEMBOURG", "LU", "LUX", "442" ),
			"MO" => array( "MACAU", "MO", "MAC", "446" ),
			"MK" => array( "MACEDONIA, THE FORMER YUGOSLAV REPUBLIC OF", "MK", "MKD", "807" ),
			"MG" => array( "MADAGASCAR", "MG", "MDG", "450" ),
			"MW" => array( "MALAWI", "MW", "MWI", "454" ),
			"MY" => array( "MALAYSIA", "MY", "MYS", "458" ),
			"MV" => array( "MALDIVES", "MV", "MDV", "462" ),
			"ML" => array( "MALI", "ML", "MLI", "466" ),
			"MT" => array( "MALTA", "MT", "MLT", "470" ),
			"MH" => array( "MARSHALL ISLANDS", "MH", "MHL", "584" ),
			"MQ" => array( "MARTINIQUE", "MQ", "MTQ", "474" ),
			"MR" => array( "MAURITANIA", "MR", "MRT", "478" ),
			"MU" => array( "MAURITIUS", "MU", "MUS", "480" ),
			"YT" => array( "MAYOTTE", "YT", "MYT", "175" ),
			"MX" => array( "MEXICO", "MX", "MEX", "484" ),
			"FM" => array( "MICRONESIA, FEDERATED STATES OF", "FM", "FSM", "583" ),
			"MD" => array( "MOLDOVA, REPUBLIC OF", "MD", "MDA", "498" ),
			"MC" => array( "MONACO", "MC", "MCO", "492" ),
			"MN" => array( "MONGOLIA", "MN", "MNG", "496" ),
			"MS" => array( "MONTSERRAT", "MS", "MSR", "500" ),
			"MA" => array( "MOROCCO", "MA", "MAR", "504" ),
			"MZ" => array( "MOZAMBIQUE", "MZ", "MOZ", "508" ),
			"MM" => array( "MYANMAR", "MM", "MMR", "104" ),
			"NA" => array( "NAMIBIA", "NA", "NAM", "516" ),
			"NR" => array( "NAURU", "NR", "NRU", "520" ),
			"NP" => array( "NEPAL", "NP", "NPL", "524" ),
			"NL" => array( "NETHERLANDS", "NL", "NLD", "528" ),
			"AN" => array( "NETHERLANDS ANTILLES", "AN", "ANT", "530" ),
			"NC" => array( "NEW CALEDONIA", "NC", "NCL", "540" ),
			"NZ" => array( "NEW ZEALAND", "NZ", "NZL", "554" ),
			"NI" => array( "NICARAGUA", "NI", "NIC", "558" ),
			"NE" => array( "NIGER", "NE", "NER", "562" ),
			"NG" => array( "NIGERIA", "NG", "NGA", "566" ),
			"NU" => array( "NIUE", "NU", "NIU", "570" ),
			"NF" => array( "NORFOLK ISLAND", "NF", "NFK", "574" ),
			"MP" => array( "NORTHERN MARIANA ISLANDS", "MP", "MNP", "580" ),
			"NO" => array( "NORWAY", "NO", "NOR", "578" ),
			"OM" => array( "OMAN", "OM", "OMN", "512" ),
			"PK" => array( "PAKISTAN", "PK", "PAK", "586" ),
			"PW" => array( "PALAU", "PW", "PLW", "585" ),
			"PA" => array( "PANAMA", "PA", "PAN", "591" ),
			"PG" => array( "PAPUA NEW GUINEA", "PG", "PNG", "598" ),
			"PY" => array( "PARAGUAY", "PY", "PRY", "600" ),
			"PE" => array( "PERU", "PE", "PER", "604" ),
			"PH" => array( "PHILIPPINES", "PH", "PHL", "608" ),
			"PN" => array( "PITCAIRN", "PN", "PCN", "612" ),
			"PL" => array( "POLAND", "PL", "POL", "616" ),
			"PT" => array( "PORTUGAL", "PT", "PRT", "620" ),
			"PR" => array( "PUERTO RICO", "PR", "PRI", "630" ),
			"QA" => array( "QATAR", "QA", "QAT", "634" ),
			"RE" => array( "REUNION", "RE", "REU", "638" ),
			"RO" => array( "ROMANIA", "RO", "ROU", "642" ),
			"RU" => array( "RUSSIAN FEDERATION", "RU", "RUS", "643" ),
			"RW" => array( "RWANDA", "RW", "RWA", "646" ),
			"KN" => array( "SAINT KITTS AND NEVIS", "KN", "KNA", "659" ),
			"LC" => array( "SAINT LUCIA", "LC", "LCA", "662" ),
			"VC" => array( "SAINT VINCENT AND THE GRENADINES", "VC", "VCT", "670" ),
			"WS" => array( "SAMOA", "WS", "WSM", "882" ),
			"SM" => array( "SAN MARINO", "SM", "SMR", "674" ),
			"ST" => array( "SAO TOME AND PRINCIPE", "ST", "STP", "678" ),
			"SA" => array( "SAUDI ARABIA", "SA", "SAU", "682" ),
			"SN" => array( "SENEGAL", "SN", "SEN", "686" ),
			"RS" => array( "SERBIA", "RS", "SRB", "688" ),
			"SC" => array( "SEYCHELLES", "SC", "SYC", "690" ),
			"SL" => array( "SIERRA LEONE", "SL", "SLE", "694" ),
			"SG" => array( "SINGAPORE", "SG", "SGP", "702" ),
			"SK" => array( "SLOVAKIA (Slovak Republic)", "SK", "SVK", "703" ),
			"SI" => array( "SLOVENIA", "SI", "SVN", "705" ),
			"SB" => array( "SOLOMON ISLANDS", "SB", "SLB", "90" ),
			"SO" => array( "SOMALIA", "SO", "SOM", "706" ),
			"ZA" => array( "SOUTH AFRICA", "ZA", "ZAF", "710" ),
			"ES" => array( "SPAIN", "ES", "ESP", "724" ),
			"LK" => array( "SRI LANKA", "LK", "LKA", "144" ),
			"SH" => array( "SAINT HELENA", "SH", "SHN", "654" ),
			"PM" => array( "SAINT PIERRE AND MIQUELON", "PM", "SPM", "666" ),
			"SD" => array( "SUDAN", "SD", "SDN", "736" ),
			"SR" => array( "SURINAME", "SR", "SUR", "740" ),
			"SJ" => array( "SVALBARD AND JAN MAYEN ISLANDS", "SJ", "SJM", "744" ),
			"SZ" => array( "SWAZILAND", "SZ", "SWZ", "748" ),
			"SE" => array( "SWEDEN", "SE", "SWE", "752" ),
			"CH" => array( "SWITZERLAND", "CH", "CHE", "756" ),
			"SY" => array( "SYRIAN ARAB REPUBLIC", "SY", "SYR", "760" ),
			"TW" => array( "TAIWAN, PROVINCE OF CHINA", "TW", "TWN", "158" ),
			"TJ" => array( "TAJIKISTAN", "TJ", "TJK", "762" ),
			"TZ" => array( "TANZANIA, UNITED REPUBLIC OF", "TZ", "TZA", "834" ),
			"TH" => array( "THAILAND", "TH", "THA", "764" ),
			"TG" => array( "TOGO", "TG", "TGO", "768" ),
			"TK" => array( "TOKELAU", "TK", "TKL", "772" ),
			"TO" => array( "TONGA", "TO", "TON", "776" ),
			"TT" => array( "TRINIDAD AND TOBAGO", "TT", "TTO", "780" ),
			"TN" => array( "TUNISIA", "TN", "TUN", "788" ),
			"TR" => array( "TURKEY", "TR", "TUR", "792" ),
			"TM" => array( "TURKMENISTAN", "TM", "TKM", "795" ),
			"TC" => array( "TURKS AND CAICOS ISLANDS", "TC", "TCA", "796" ),
			"TV" => array( "TUVALU", "TV", "TUV", "798" ),
			"UG" => array( "UGANDA", "UG", "UGA", "800" ),
			"UA" => array( "UKRAINE", "UA", "UKR", "804" ),
			"AE" => array( "UNITED ARAB EMIRATES", "AE", "ARE", "784" ),
			"GB" => array( "UNITED KINGDOM", "GB", "GBR", "826" ),
			"US" => array( "UNITED STATES", "US", "USA", "840" ),
			"UM" => array( "UNITED STATES MINOR OUTLYING ISLANDS", "UM", "UMI", "581" ),
			"UY" => array( "URUGUAY", "UY", "URY", "858" ),
			"UZ" => array( "UZBEKISTAN", "UZ", "UZB", "860" ),
			"VU" => array( "VANUATU", "VU", "VUT", "548" ),
			"VA" => array( "VATICAN CITY STATE (HOLY SEE)", "VA", "VAT", "336" ),
			"VE" => array( "VENEZUELA", "VE", "VEN", "862" ),
			"VN" => array( "VIET NAM", "VN", "VNM", "704" ),
			"VG" => array( "VIRGIN ISLANDS (BRITISH)", "VG", "VGB", "92" ),
			"VI" => array( "VIRGIN ISLANDS (U.S.)", "VI", "VIR", "850" ),
			"WF" => array( "WALLIS AND FUTUNA ISLANDS", "WF", "WLF", "876" ),
			"EH" => array( "WESTERN SAHARA", "EH", "ESH", "732" ),
			"YE" => array( "YEMEN", "YE", "YEM", "887" ),
			"YU" => array( "YUGOSLAVIA", "YU", "YUG", "891" ),
			"ZR" => array( "ZAIRE", "ZR", "ZAR", "180" ),
			"ZM" => array( "ZAMBIA", "ZM", "ZMB", "894" ),
			"ZW" => array( "ZIMBABWE", "ZW", "ZWE", "716" ),
		);
		if ( null != $country ) {
			$c_codes = $countries[$country];
			return $c_codes[2];
		}
		return 'USA';
	}

}
SEC_VirtualMerchant::register();