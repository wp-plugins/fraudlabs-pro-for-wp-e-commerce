<?php
/**
 * Plugin Name: FraudLabs Pro for WP e-Commerce
 * Plugin URI: http://www.fraudlabspro.com
 * Description: This plugin is an add-on for WP e-Commerce plugin that help you to screen your order transaction, such as credit card transaction, for online fraud.
 * Version: 1.0.0
 * Author: FraudLabs Pro
 * Author URI: http://www.fraudlabspro.com
 */
class FraudLabsPro_WP_ECommerce {

	private $plugin_name, $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'wpsc_fraudlabspro';
		$this->plugin_name = basename(dirname(__FILE__));
		add_action('admin_menu', array(&$this,
			'admin_page'
		));
		add_action('wpsc_pre_submit_gateway', array(&$this,
			'screen_order'
		));
		add_action('wpsc_billing_details_bottom', array(&$this,
			'render_fraud_report'
		));
	}

	private function fix_case($s) {
		$s = ucwords(strtolower($s));
		$s = preg_replace_callback("/( [ a-zA-Z]{1}')([a-zA-Z0-9]{1})/s", create_function('$matches', 'return $matches[1].strtoupper($matches[2]);') , $s);
		return $s;
	}

	public function admin_options() {
		if (is_admin()) {
			$enabled = (isset($_POST['save']) && isset($_POST['enabled'])) ? 1 : ((isset($_POST['save']) && !isset($_POST['enabled'])) ? 0 : get_option('fraudlabspro_enabled'));
			$apiKey = (isset($_POST['apiKey'])) ? $_POST['apiKey'] : get_option('fraudlabspro_api_key');
			$riskScore = (isset($_POST['riskScore'])) ? $_POST['riskScore'] : get_option('fraudlabspro_score');
			$testIP = (isset($_POST['testIP'])) ? $_POST['testIP'] : get_option('fraudlabspro_test_ip');
			echo '
			<div class="wrap">
				<h2>FraudLabs Pro for WP e-Commerce</h2>
				<p>&nbsp;</p>';
			if (isset($_POST['save'])) {
				if (!preg_match('/^[0-9]+$/', $riskScore)) {
					echo '<p style="color:#cc0000">Please provide a valid risk score.</p>';
				} elseif (!empty($testIP) && !filter_var($testIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
					echo '<p style="color:#cc0000">Please provide a valid test IP.</p>';
				} else {
					update_option('fraudlabspro_enabled', $enabled);
					update_option('fraudlabspro_api_key', $apiKey);
					update_option('fraudlabspro_score', $riskScore);
					update_option('fraudlabspro_test_ip', $testIP);
					echo '<p style="color:#666600">Changes have been successfully saved.</p>';
				}
			}
			echo '
			<form method="post">
				<input type="hidden" name="save" value="true" />
				<p>
					<input type="checkbox" name="enabled" id="flpEnabled"' . (($enabled) ? ' checked' : '') . '>
					<label for="flpEnabled">Enabled</label>
				</p>
				<p>
					<label style="width:100px;display:inline-block"><b>API Key: </b></label>
					<input type="text" name="apiKey" value="' . $apiKey . '" maxlength="32" size="50" />
					<div style="font-size:11px;color:#535353">You can register for a free license key at <a href="http://www.fraudlabspro.com/sign-up" target="_blank">http://www.fraudlabspro.com/sign-up</a> if you do not have one.</div>
				</p>
				<p>
					<label style="width:100px;display:inline-block"><b>Risk Score: </b></label>
					<input type="text" name="riskScore" value="' . $riskScore . '" maxlength="2" size="5" />
					<div style="font-size:11px;color:#535353">Reject the transaction if the calculated risk score higher than this value.</div>
				</p>
				<p>
					<label style="width:100px;display:inline-block"><b>Test IP: </b></label>
					<input type="text" name="testIP" value="' . $testIP . '" maxlength="15" size="15" />
					<div style="font-size:11px;color:#535353">Simulate visitor IP. Clear this value for production run.</div>
				</p>
				<p>
					<input type="submit" value="Save Settings" />
				</p>
			</div>';
		}
	}

	public function screen_order(&$merchant_instance) {
		global $wpdb, $wpsc_cart;
		if (!get_option('fraudlabspro_enabled')) return;
		$qty = 0;
		foreach ($merchant_instance->cart_items as $key => $value) {
			$qty+= $value['quantity'];
		}
		$request = array(
			'key' => get_option('fraudlabspro_api_key') ,
			'format' => 'json',
			'ip' => (get_option('fraudlabspro_test_ip')) ? get_option('fraudlabspro_test_ip') : $_SERVER['REMOTE_ADDR'],
			'bill_city' => $merchant_instance->cart_data['billing_address']['city'],
			'bill_state' => $merchant_instance->cart_data['billing_address']['state'],
			'bill_zip_code' => $merchant_instance->cart_data['billing_address']['post_code'],
			'bill_country' => $merchant_instance->cart_data['billing_address']['country'],
			'ship_addr' => $merchant_instance->cart_data['shipping_address']['address'],
			'ship_city' => $merchant_instance->cart_data['shipping_address']['city'],
			'ship_state' => $merchant_instance->cart_data['shipping_address']['state'],
			'ship_zip_code' => $merchant_instance->cart_data['shipping_address']['post_code'],
			'ship_country' => $merchant_instance->cart_data['shipping_address']['country'],
			'email_domain' => substr($merchant_instance->cart_data['email_address'], strpos($merchant_instance->cart_data['email_address'], '@') + 1) ,
			'phone' => $merchant_instance->cart_data['billing_address']['phone'],
			'email_hash' => $this->hash_string($merchant_instance->cart_data['email_address']) ,
			'user_order_id' => $merchant_instance->purchase_id,
			'amount' => $merchant_instance->cart_data['total_price'],
			'quantity' => $qty,
			'currency' => $merchant_instance->cart_data['store_currency'],
			'payment_mode' => 'others'
		);
		$url = 'https://api.fraudlabspro.com/v1/order/screen?' . http_build_query($request);
		for ($i = 0; $i < 3; $i++) {
			if (is_null($json = json_decode(@file_get_contents($url))) === FALSE) {
				$wpdb->insert($this->table_name, array(
					'purchase_id' => $merchant_instance->purchase_id,
					'is_country_match' => $json->is_country_match,
					'is_high_risk_country' => $json->is_high_risk_country,
					'distance_in_km' => $json->distance_in_km,
					'distance_in_mile' => $json->distance_in_mile,
					'ip_address' => (get_option('fraudlabspro_test_ip')) ? get_option('fraudlabspro_test_ip') : $_SERVER['REMOTE_ADDR'],
					'ip_country' => $json->ip_country,
					'ip_region' => $json->ip_region,
					'ip_city' => $json->ip_city,
					'ip_continent' => $json->ip_continent,
					'ip_latitude' => $json->ip_latitude,
					'ip_longitude' => $json->ip_longitude,
					'ip_timezone' => $json->ip_timezone,
					'ip_elevation' => $json->ip_elevation,
					'ip_domain' => $json->ip_domain,
					'ip_mobile_mnc' => $json->ip_mobile_mnc,
					'ip_mobile_mcc' => $json->ip_mobile_mcc,
					'ip_mobile_brand' => $json->ip_mobile_brand,
					'ip_netspeed' => $json->ip_netspeed,
					'ip_isp_name' => $json->ip_isp_name,
					'ip_usage_type' => $json->ip_usage_type,
					'is_free_email' => $json->is_free_email,
					'is_new_domain_name' => $json->is_new_domain_name,
					'is_proxy_ip_address' => $json->is_proxy_ip_address,
					'is_bin_found' => $json->is_bin_found,
					'is_bin_country_match' => $json->is_bin_country_match,
					'is_bin_name_match' => $json->is_bin_name_match,
					'is_bin_phone_match' => $json->is_bin_phone_match,
					'is_bin_prepaid' => $json->is_bin_prepaid,
					'is_address_ship_forward' => $json->is_address_ship_forward,
					'is_bill_ship_city_match' => $json->is_bill_ship_city_match,
					'is_bill_ship_state_match' => $json->is_bill_ship_state_match,
					'is_bill_ship_country_match' => $json->is_bill_ship_country_match,
					'is_bill_ship_postal_match' => $json->is_bill_ship_postal_match,
					'is_ip_blacklist' => $json->is_ip_blacklist,
					'is_email_blacklist' => $json->is_email_blacklist,
					'is_credit_card_blacklist' => $json->is_credit_card_blacklist,
					'is_device_blacklist' => $json->is_device_blacklist,
					'is_user_blacklist' => $json->is_user_blacklist,
					'fraudlabspro_score' => $json->fraudlabspro_score,
					'fraudlabspro_distribution' => $json->fraudlabspro_distribution,
					'fraudlabspro_status' => $json->fraudlabspro_status,
					'fraudlabspro_id' => $json->fraudlabspro_id,
					'fraudlabspro_error_code' => $json->fraudlabspro_error_code,
					'fraudlabspro_message' => $json->fraudlabspro_message,
					'fraudlabspro_credits' => $json->fraudlabspro_credits,
					'api_key' => get_option('fraudlabspro_api_key')
				));
				$wpsc_cart->empty_cart();
				break;
			}
		}
		if ((int)$json->fraudlabspro_score > get_option('fraudlabspro_score')) {
			wp_redirect(get_option('transact_url'));
			exit();
		}
	}

	public function render_fraud_report() {
		global $wpdb;
		if (isset($_POST['approve'])) {
			$request = array(
				'key' => get_option('fraudlabspro_api_key') ,
				'action' => 'APPROVE',
				'id' => $_POST['transactionId'],
				'format' => 'json'
			);
			$url = 'https://api.fraudlabspro.com/v1/order/feedback?' . http_build_query($request);
			for ($i = 0; $i < 3; $i++) {
				if (is_null($json = json_decode(@file_get_contents($url))) === FALSE) {
					if ($json->fraudlabspro_error_code == '' || $json->fraudlabspro_error_code == '304') {
						$wpdb->query('UPDATE `' . $this->table_name . '` SET `fraudlabspro_status`="APPROVE" WHERE `purchase_id`=' . $_GET['id']);
					}
					break;
				}
			}
		}
		if (isset($_POST['reject'])) {
			$request = array(
				'key' => get_option('fraudlabspro_api_key') ,
				'action' => 'REJECT',
				'id' => $_POST['transactionId'],
				'format' => 'json'
			);
			$url = 'https://api.fraudlabspro.com/v1/order/feedback?' . http_build_query($request);
			for ($i = 0; $i < 3; $i++) {
				if (is_null($json = json_decode(@file_get_contents($url))) === FALSE) {
					if ($json->fraudlabspro_error_code == '' || $json->fraudlabspro_error_code == '304') {
						$wpdb->query('UPDATE `' . $this->table_name . '` SET `fraudlabspro_status`="REJECT" WHERE `purchase_id`=' . $_GET['id']);
					}
					break;
				}
			}
		}
		$result = $wpdb->get_results('SELECT * FROM `' . $this->table_name . '` WHERE `purchase_id`=' . $_GET['id']);
		echo '<script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>';
		if (count($result) > 0) {
			$row = $result[0];
			$table = '
			<style type="text/css">
				.fraudlabspro{border:1px solid #ccced7;border-collapse:collapse;margin:auto;padding:4px;table-layout:fixed;width:100%}
				.fraudlabspro td{border-bottom:1px solid #ccced7;border-left:1px solid #ccced7;padding:5px 0 0 5px;text-align:left;white-space:nowrap;font-size:11px}
			</style>

			<table class="fraudlabspro">
				<col width="80">
				<col width="100">
				<col width="140">
				<col width="140">
				<col width="140">';
			$location = array();
			if (strlen($row->ip_country) == 2) {
				$location = array(
					$this->fix_case($row->ip_continent) ,
					$row->ip_country,
					$this->fix_case($row->ip_region) ,
					$this->fix_case($row->ip_city)
				);
				$location = array_unique($location);
			}
			$table.= '
				<tr>
					<td rowspan="3">
						<center><b>Score</b> <a href="javascript:;" title="Overall score between 0 and 100. 100 is the highest risk. 0 is the lowest risk.">[?]</a>
						<p style="font-size:3em">' . $row->fraudlabspro_score . '</p></center>
					</td>
					<td>
						<b>IP Address</b>
						<p>' . $row->ip_address . '</p>
					</td>
					<td colspan="3">
						<b>IP Location</b> <a href="javascript:;" title="Estimated location of the IP address.">[?]</a>
						<p>' . implode(', ', $location) . ' <a href="http://www.geolocation.com/' . $row->ip_address . '" target="_blank">[Map]</a></p>
					</td>
				</tr>
				<tr>
					<td>
						<b>IP Net Speed</b> <a href="javascript:;" title="Connection speed.">[?]</a>
						<p>' . $row->ip_netspeed . '</p>
					</td>
					<td colspan="3">
						<b>IP ISP Name</b> <a href="javascript:;" title="Estimated ISP of the IP address.">[?]</a>
						<p>' . $row->ip_isp_name . '</p>
					</td>
				</tr>';
			switch ($row->fraudlabspro_status) {
				case 'REVIEW':
					$color = 'ffcc00';
					break;

				case 'REJECT':
					$color = 'cc0000';
					break;

				case 'APPROVE':
					$color = '336600';
					break;
			}
			$table.= '
				<tr>
					<td>
						<b>IP Domain</b> <a href="javascript:;" title="Estimated domain name of the IP address.">[?]</a>
						<p>' . $row->ip_domain . '</p>
					</td>
					<td>
						<b>IP Usage Type</b> <a href="javascript:;" title="Estimated usage type of the IP address. ISP, Commercial, Residential.">[?]</a>
						<p>' . ((empty($row->ip_usage_type)) ? '-' : $row->ip_usage_type) . '</p>
					</td>
					<td>
						<b>IP Time Zone</b> <a href="javascript:;" title="Estimated timezone of the IP address.">[?]</a>
						<p>' . $row->ip_timezone . '</p>
					</td>
					<td>
						<b>IP Distance</b> <a href="javascript:;" title="Distance from IP address to Billing Location.">[?]</a>
						<p>' . (($row->distance_in_km) ? ($row->distance_in_km . ' KM / ' . $row->distance_in_mile . ' Miles') : '-') . '</p>
					</td>
				</tr>
				<tr>
					<td rowspan="3">
						<center><b>Status</b> <a href="javascript:;" title="FraudLabs Pro status.">[?]</a>
						<p style="color:#' . $color . ';font-size:2.5em;font-weight:bold">' . $row->fraudlabspro_status . '</p></center>
					</td>
					<td>
						<b>IP Latitude</b> <a href="javascript:;" title="Estimated latitude of the IP address.">[?]</a>
						<p>' . $row->ip_latitude . '</p>
					</td>
					<td>
						<b>IP Longitude</b> <a href="javascript:;" title="Estimated longitude of the IP address.">[?]</a>
						<p>' . $row->ip_longitude . '</p>
					</td>
					<td colspan="2">&nbsp;</td>
				</tr>
				<tr>
					<td>
						<b>High Risk</b> <a href="javascript:;" title="Whether IP address or billing address country is in the latest high risk list.">[?]</a>
						<p>' . (($row->is_high_risk_country == 'Y') ? 'Yes' : (($row->is_high_risk_country == 'N') ? 'No' : '-')) . '</p>
					</td>
					<td>
						<b>Free Email</b> <a href="javascript:;" title="Whether e-mail is from free e-mail provider.">[?]</a>
						<p>' . (($row->is_free_email == 'Y') ? 'Yes' : (($row->is_free_email == 'N') ? 'No' : '-')) . '</p>
					</td>
					<td>
						<b>Ship Forward</b> <a href="javascript:;" title="Whether shipping address is in database of known mail drops.">[?]</a>
						<p>' . (($row->is_address_ship_forward == 'Y') ? 'Yes' : (($row->is_address_ship_forward == 'N') ? 'No' : '-')) . '</p>
					</td>
					<td>
						<b>Using Proxy</b> <a href="javascript:;" title="Whether IP address is from Anonymous Proxy Server.">[?]</a>
						<p>' . (($row->is_proxy_ip_address == 'Y') ? 'Yes' : (($row->is_proxy_ip_address == 'N') ? 'No' : '-')) . '</p>
					</td>
				</tr>
				<tr>
					<td>
						<b>BIN Found</b> <a href="javascript:;" title="Whether the BIN information matches our BIN list.">[?]</a>
						<p>' . (($row->is_bin_found == 'Y') ? 'Yes' : (($row->is_bin_found == 'N') ? 'No' : '-')) . '</p>
					</td>
					<td>
						<b>Email Blacklist</b> <a href="javascript:;" title="Whether the email address is in our blacklist database.">[?]</a>
						<p>' . (($row->is_email_blacklist == 'Y') ? 'Yes' : (($row->is_email_blacklist == 'N') ? 'No' : '-')) . '</p>
					</td>
					<td>
						<b>Credit Card Blacklist</b> <a href="javascript:;" title="Whether the credit card is in our blacklist database.">[?]</a>
						<p>' . (($row->is_credit_card_blacklist == 'Y') ? 'Yes' : (($row->is_credit_card_blacklist == 'N') ? 'No' : '-')) . '</p>
					</td>
					<td>&nbsp;</td>
				</tr>
				<tr>
					<td colspan="5">
						<b>Message</b> <a href="javascript:;" title="FraudLabs Web service message response.">[?]</a>
						<p>' . (($row->fraudlabspro_message) ? $row->fraudlabspro_error_code . ':' . $row->fraudlabspro_message : '-') . '</p>
				</tr>
				<tr>
					<td colspan="5">
						<b>Link</b>
						<p><a href="http://www.fraudlabspro.com/merchant/transaction-details/' . $row->fraudlabspro_id . '" target="_blank">http://www.fraudlabspro.com/merchant/transaction-details/' . $row->fraudlabspro_id . '</a></p>
				</tr>
				</table>';
			if ($row->fraudlabspro_status == 'REVIEW') {
				$table.= '
				<form method="post">
					<p align="center">
					<input type="hidden" name="transactionId" value="' . $row->fraudlabspro_id . '" >
					<input type="submit" name="approve" id="approve-order" value="Approve" style="padding:10px 5px; background:#22aa22; border:1px solid #ccc; min-width:100px; cursor: pointer;" />
					<input type="submit" name="reject" id="reject-order" value="Reject" style="padding:10px 5px; background:#cd2122; border:1px solid #ccc; min-width:100px; cursor: pointer;" />
					</p>
				</form>';
			}
			echo '
			<script>
			$(function(){
				$("#post-body").append(\'<div class="metabox-holder"><div class="postbox"><h3>FraudLabs Pro Details</h3><blockquote>' . preg_replace('/[\n]*/is', '', str_replace('\'', '\\\'', $table)) . '</blockquote></div></div>\');
			});
			</script>';
		} else {
			echo '
			<script>
			$(function(){
				$("#post-body").append(\'<div class="metabox-holder"><div class="postbox"><h3>FraudLabs Pro Details</h3><blockquote>This order has not been screened by FraudLabs Pro.</blockquote></div></div>\');
			});
			</script>';
		}
	}

	private function hash_string($s) {
		$hash = 'fraudlabspro_' . $s;
		for ($i = 0; $i < 65536; $i++) $hash = sha1('fraudlabspro_' . $hash);
		return $hash;
	}

	public function admin_page() {
		add_options_page('FraudLabs Pro', 'FraudLabs Pro', 10, 'fraudlabs-pro', array(&$this,
			'admin_options'
		));
	}

	public function activate() {
		global $wpdb;
		$sql = 'CREATE TABLE `' . $wpdb->prefix . 'wpsc_fraudlabspro' . '` (
			`purchase_id` BIGINT(20) NOT NULL COLLATE \'utf8_bin\',
			`is_country_match` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_high_risk_country` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`distance_in_km` VARCHAR(10) NOT NULL COLLATE \'utf8_bin\',
			`distance_in_mile` VARCHAR(10) NOT NULL COLLATE \'utf8_bin\',
			`ip_address` VARCHAR(15) NOT NULL COLLATE \'utf8_bin\',
			`ip_country` VARCHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`ip_continent` VARCHAR(20) NOT NULL COLLATE \'utf8_bin\',
			`ip_region` VARCHAR(21) NOT NULL COLLATE \'utf8_bin\',
			`ip_city` VARCHAR(21) NOT NULL COLLATE \'utf8_bin\',
			`ip_latitude` VARCHAR(21) NOT NULL COLLATE \'utf8_bin\',
			`ip_longitude` VARCHAR(21) NOT NULL COLLATE \'utf8_bin\',
			`ip_timezone` VARCHAR(10) NOT NULL COLLATE \'utf8_bin\',
			`ip_elevation` VARCHAR(10) NOT NULL COLLATE \'utf8_bin\',
			`ip_domain` VARCHAR(50) NOT NULL COLLATE \'utf8_bin\',
			`ip_mobile_mnc` VARCHAR(100) NOT NULL COLLATE \'utf8_bin\',
			`ip_mobile_mcc` VARCHAR(100) NOT NULL COLLATE \'utf8_bin\',
			`ip_mobile_brand` VARCHAR(100) NOT NULL COLLATE \'utf8_bin\',
			`ip_netspeed` VARCHAR(10) NOT NULL COLLATE \'utf8_bin\',
			`ip_isp_name` VARCHAR(50) NOT NULL COLLATE \'utf8_bin\',
			`ip_usage_type` VARCHAR(30) NOT NULL COLLATE \'utf8_bin\',
			`is_free_email` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_new_domain_name` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_proxy_ip_address` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_bin_found` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_bin_country_match` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_bin_name_match` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_bin_phone_match` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_bin_prepaid` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_address_ship_forward` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_bill_ship_city_match` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_bill_ship_state_match` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_bill_ship_country_match` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_bill_ship_postal_match` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_ip_blacklist` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_email_blacklist` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_credit_card_blacklist` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_device_blacklist` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`is_user_blacklist` CHAR(2) NOT NULL COLLATE \'utf8_bin\',
			`fraudlabspro_score` CHAR(3) NOT NULL COLLATE \'utf8_bin\',
			`fraudlabspro_distribution` CHAR(3) NOT NULL COLLATE \'utf8_bin\',
			`fraudlabspro_status` CHAR(10) NOT NULL COLLATE \'utf8_bin\',
			`fraudlabspro_id` CHAR(15) NOT NULL COLLATE \'utf8_bin\',
			`fraudlabspro_error_code` CHAR(3) NOT NULL COLLATE \'utf8_bin\',
			`fraudlabspro_message` VARCHAR(50) NOT NULL COLLATE \'utf8_bin\',
			`fraudlabspro_credits` VARCHAR(10) NOT NULL COLLATE \'utf8_bin\',
			`api_key` CHAR(32) NOT NULL COLLATE \'utf8_bin\',
			INDEX `idx_purchase_id` (`purchase_id`)
		) COLLATE=\'utf8_bin\' ENGINE=MyISAM';
		$wpdb->query($sql);
		// Initial default settings
		update_option('fraudlabspro_enabled', 1);
		update_option('fraudlabspro_api_key', '');
		update_option('fraudlabspro_score', 60);
		update_option('fraudlabspro_test_ip', '');
	}

	public function uninstall() {
		global $wpdb;
		$wpdb->query('DROP TABLE `' . $wpdb->prefix . 'wpsc_fraudlabspro' . '`');
		// Remove all settings
		delete_option('fraudlabspro_enabled');
		delete_option('fraudlabspro_api_key');
		delete_option('fraudlabspro_score');
		delete_option('fraudlabspro_test_ip');
	}
}
// Initial class
$FraudLabsPro_WP_ECommerce = new FraudLabsPro_WP_ECommerce();

register_activation_hook(__FILE__, array(
	'FraudLabsPro_WP_ECommerce',
	'activate'
));

register_uninstall_hook(__FILE__, array(
	'FraudLabsPro_WP_ECommerce',
	'uninstall'
));
?>