<?php
namespace Wolf\Speedaf\sdk;
defined( 'ABSPATH' ) || exit; // block direct access.

require('CryptoServices.php');
use Exception;
use Wolf\Speedaf\sdk as api;

class ApiServices {
	
	private $crypto_services = null;
	private $config = null;
	
	public function __construct()
	{
		$this->crypto_services = new api\CryptoServices();
		$this->config = require('Configuration.php');
		$this->encryption_algorithm =  "des-cbc";
		$this->config = require('Configuration.php');
		$this->secret_key = $this->config['secret_key'];
		$ivArray = array(0x12, 0x34, 0x56, 0x78, 0x90, 0xAB, 0xCD, 0xEF);
        $iv = null;
        foreach($ivArray as $element){ $iv .= CHR($element); }
		$this->initilization_vector = $iv;
	}
	//returns current time in milliseconds
	private function getCurrentTimestamp()
	{
            list($msec, $sec) = explode(' ', microtime());
            $timestamp = ceil((floatval($msec) + floatval($sec)) * 1000);
			return $timestamp;
	}

	/*
		By bill code 
		By Address
		$data :
		[
    		 "billCode" => "77130065353256"

		]

	*/

	public function getSoringCodeByWaybillNumber($data)
	{
		//validate the sorting code parameters
		$url = $this->config['sorting_code_by_waybill_path'];
		$result = $this->retrieve_data($data, $url);
		
		return $result;
	}

	public function print(array $data)
	{
		//validate the print parameters
		
		$url = $this->config['print_path'];
		$result = $this->retrieve_data($data, $url);
		return $result;	
	}


	/**
	 * get track info
	 *
	 * @param array $data
	 * @return void
	 */
	public function track(array $data)
	{
		//validate the track parameters
		
		$url = $this->config['track_path'];
		$result = $this->retrieve_data($data, $url);
		return $result;	
	}


	/**
	 * push track subscribe 
	 * @param array $data 
	 * @return mixed 
	 * @throws Exception 
	 */

	public function track_subscribe(array $data) {
		$url = $this->config['track_subscribe'];
		$result = $this->retrieve_data($data, $url);
		return $result;	
	}

	/**
	 * 
	 * @param mixed $data 
	 * @param bool $batch 
	 * @return mixed 
	 * @throws Exception 
	 */
	public function createOrder($data,$batch = false)
	{
		$allowedCurrencies = $this->config['allowedCurrencies'];

		if(!array_key_exists($data['acceptCountryCode'],$allowedCurrencies)) throw new \Exception(_('Current country is not supported','speedaf-express-for-woocommerce'));

		//Validate order parameters
		$url =  $batch ? $this->config['batch_create_order_path']: $this->config['create_order_path'];
		$result = $this->retrieve_data($data, $url);

		//you can process b4 return
		return $result;
	}

	public function updateOrder($data) {
		//Validate order parameters
		$url =  $this->config['update_order_path'];
		$result = $this->retrieve_data($data, $url);

		//you can process b4 return
		return $result;
	}

	/**
	 * 
	 * @param mixed $data 
	 * @return mixed 
	 * @throws Exception 
	 */
	public function cancel($data) {
		$url = $this->config['cancel_order_path'];
		return $this->retrieve_data($data,$url);
	}

	/**
	 * 
	 * @param array $data 
	 * @return mixed 
	 * @throws Exception 
	 */

	public function getCustomerCode(array $data){
		$url = $this->config['get_customer_code'];
		return $this->retrieve_data($data,$url);

	}
	

	/**
	 * 
	 * @param mixed $data 
	 * @param mixed $url 
	 * @return mixed 
	 * @throws Exception 
	 */
	private function retrieve_data($data, $url)
	{
		//Todo handle network errors
		$timeline = $this->getCurrentTimestamp();
		
		//handle encryption exceptions
		$encrypted_data = $this->crypto_services->encrypt($data, $timeline);
	

		$post_url = $this->config['base_path'].$url.'?timestamp=' . $timeline . '&appCode=' . $this->config['app_code'];
		// return $post_url;
		//You can use another Network client like guzzle here
     $options = [
		'body' => $encrypted_data,
		'headers' => [
            'Content-Type' => 'application/json',
			'Content-Length' => strlen($encrypted_data)
		],
		'timeout'     => 60,
		'httpversion' => '1.0',
		'sslverify'   => false,
		'data_format' => 'body',
	 ];
	
      $response = wp_remote_post($post_url,$options);
	  if ( is_wp_error( $response ) ) {
		throw new Exception($response->get_error_message());
	  } 
	  $result = wp_remote_retrieve_body($response);
	  unset($response);
		//handle descryption exception

		//Incoming data is in Json format. it needs to be in arrat format.
		//the array has keys like 'success' (boolean), 'data' a base 64 string
		$result_in_array_form =  json_decode($result, true);
 
		if(!isset($result_in_array_form['success'])){
			throw new Exception("Request failed");
		}
		if($result_in_array_form['success'] == false){
			
			$error_messages = require('ErrorCodes.php');
			//error 500 unkor ?
			throw new Exception($result_in_array_form['error']['message']);
		}

		$result_data = $this->crypto_services->decrypt($result_in_array_form);
		return $result_data;
		
	}

		
	

}