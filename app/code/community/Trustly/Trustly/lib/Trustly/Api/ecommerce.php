<?php
/*
 * The MIT License (MIT)
 * 
 * Copyright (c) 2014 Trustly Group AB
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

class Trustly_Api_ECommerce extends Trustly_Api {
	var $api_apikey = NULL;

	public function __construct($host, $port, $is_https, $apikey) {
		parent::__construct($host, $port, $is_https);

		$this->api_apikey = $apikey;
	}

	public function urlPath($request=NULL) {
		$url = '/api/ECom';

		if(isset($request)) {
			$method = $request->getMethod();
			if(isset($method)) {
				$url .= '/' . $method;
			}
		}
		return $url;
	}

	public function verifyTrustlySignedResponse($response) {
		$method = $response->getMethod();
		$uuid = $response->getUUID();
		$signature = $response->getSignature();
		$data = $response->getResult();

		/* As the data in the ecommerce responses are flat, all the uuid, 
		 * signatures, method information is on the same level as the data 
		 * response. Remove theese from the data before checking the signature. */
		unset($data['uuid']);
		unset($data['signature']);
		unset($data['method']);

		return $this->verifyTrustlySignedData($method, $uuid, $signature, $data);
	}

	public function handleResponse($request, $body, $curl) {
		$response = new Trustly_Data_Response($body, $curl);

		if($this->verifyTrustlySignedResponse($response) !== TRUE) {
			throw new Trustly_SignatureException('Incomming message signature is not valid', $response);
		}

		return $response;
	}

	public function insertCredentials($request) {
		$request->set('apikey', $this->api_apikey);
		return TRUE;
	}

	/* Build an orderline suitable for the payment call using the supplied 
	 * information. Call once for each line of the order and stuff into an 
	 * array that is later supplied to the pay() call.
	 *
	 * Typically:
	 * $orderlines = array();
	 * $orderlines[] = $api->createOrderline(...);
	 * $orderlines[] = $api->createOrderline(...);
	 * ...
	 * $result = $api->pay(..., $orderlines);
	 * */
	public function createOrderline($description=NULL, $amount=NULL, 
		$currency=NULL, $vat=NULL, $quantity=1, $eancode=NULL) {

		$orderline = array();

		if(isset($description) && strlen($description)) {
			$orderline['description'] = Trustly_Data::ensureUTF8($description);
		}
		if(isset($amount)) {
			$orderline['amount'] = Trustly_Data::ensureUTF8($amount);
		}
		if(isset($currency) && strlen($currency)) {
			$orderline['currency'] = Trustly_Data::ensureUTF8($currency);
		}
		if(isset($vat)) {
			$orderline['vat'] = Trustly_Data::ensureUTF8($vat);
		}
		if(isset($quantity)) {
			$orderline['quantity'] = Trustly_Data::ensureUTF8($quantity);
		}
		if(isset($eancode) && strlen($eancode)) {
			$orderline['eancode'] = Trustly_Data::ensureUTF8($eancode);
		}

		/* No point in adding a orderline unless we have any usable data */
		if(count($orderline) == 0) {
			return NULL;
		}

		return $orderline;
	}

	public function pay($notificationurl, $enduserid, $messageid, 
            $locale=NULL, $amount=NULL, $currency=NULL, $country=NULL, $host=NULL,
            $returnurl=NULL, $templateurl=NULL, $urltarget=NULL,
			$email=NULL, $firstname=NULL, $lastname=NULL, $integrationmodule=NULL,
			$orderlines=NULL) {

		$request = new Trustly_Data_Request('Pay',
			array(
				'notificationurl' => $notificationurl,
				'enduserid' => $enduserid,
				'messageid' => $messageid,
				'locale' => $locale,
				'amount' => $amount,
				'currency' => $currency,
				'country' => $country,
				'host' => $host,
				'returnurl' => $returnurl,
				'templateurl' => $templateurl,
				'urltarget' => $urltarget,
				'email' => $email,
				'firstname' => $firstname,
				'lastname' => $lastname,
				'orderline' => $orderlines,
				'integrationmodule' => $integrationmodule
			)
		);
		return $this->call($request);
	}

    public function repay($orderid, $amount, $currency) {

		$request = new Trustly_Data_Request('Repay',
			array(
				'orderid' => $orderid,
				'amount' => $amount,
				'currency' => $currency
			)
		);

		return $this->call($request);
	}

	public function hello() {
		$request = new Trustly_Data_Request('Hello');
		return $this->call($request);
	}
}

?>
