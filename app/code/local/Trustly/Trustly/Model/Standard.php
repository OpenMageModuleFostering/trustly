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
 *
 */


class Trustly_Trustly_Model_Standard extends Mage_Payment_Model_Method_Abstract
{
	const LOG_FILE = 'trustly.log';

	/**
	 * unique internal payment method identifier
	 *
	 * @var string [a-z0-9_]
	 */

	protected $_code  = 'trustly';

	/**
	 * Is this payment method a gateway (online auth/charge) ?
	 */
	protected $_isGateway               = true;

	/**
	 * Can authorize online?
	 */
	protected $_canAuthorize            = true;

	/**
	 * Can capture funds online?
	 */
	protected $_canCapture              = true;

	/**
	 * Can capture partial amounts online?
	 */
	protected $_canCapturePartial       = true;

	/**
	 * Can refund online?
	 */
	protected $_canRefund               = true;

	/**
	 * Can void transactions online?
	 */
	protected $_canVoid                 = true;

	/**
	 * Can use this payment method in administration panel?
	 */
	protected $_canUseInternal          = true;

	/**
	 * Can show this payment method as an option on checkout payment page?
	 */
	protected $_canUseCheckout          = true;

	/**
	 * Is this payment method suitable for multi-shipping checkout?
	 */
	protected $_canUseForMultishipping  = false;

	/**
	 * Can save credit card information for future processing?
	 */
	protected $_canSaveCc = false;

	protected $_formBlockType = 'trustly/form';


	const	PAYMENT_TYPE_AUTH	=	'AUTHORIZATION';
	const	PAYMENT_TYPE_SALE	=	'SALE';
	const   CODE_REFUND         =   '3';



	public function authorize(varien_object $payment, $amount)
	{
	}


	public function capture (Varien_Object $payment, $amount)
	{
	}


	/**
	 * Get session namespace
	 */
	public function getSession()
	{
		return Mage::getSingleton('trustly/session');
	}


	/**
	 * Get checkout namespace
	 */
	public function getCheckout()
	{
		return Mage::getSingleton('checkout/session');
	}


	/**
	 * Get actual quote
	 */
	public function getQuote()
	{
		return $this->getCheckout()->getQuote();
	}


	public function getOrderPlaceRedirectUrl()
	{
		return Mage::getUrl('trustly/payment/redirect', array('_secure' => true));
	}


	private function _getOrderLines($api, $order)
	{
		$orderlines = array();
		foreach ($order->getAllItems() as $item) {
			if (!$item->getParentItem()) {
				/* The EAN code here is really not standard and there is no 
				 * standard field. But if present (with the guessed name) we will 
				 * get it and fetching a non-existant one will not blow up, simply return null.  */
				$orderline = $api->createOrderline(
					$item->getName(),
					$item->getPrice(),
					$order->getBaseCurrencyCode(),
					$item->getBaseTaxAmount(),
					$item->getQtyOrdered(),
					$item->getEan());
				$orderlines[] = $orderline;
			}
		}

		return $orderlines;
	}


	public function redirectProcess()
	{
		Mage::log("redirectProcess()", Zend_Log::DEBUG, self::LOG_FILE);
		$api = Mage::helper('trustly')->getTrustlyAPI();

		if(!isset($api)) {
			Mage::log("Attempting to process a payment, but the Trustly module is not properly configured yet", Zend_Log::ERR, self::LOG_FILE);
			Mage::getSingleton('core/session')->addError(Mage::helper('trustly')->__('Trustly payment module has not been configured.'));
			return false;
		}

		$orderId = $this->getCheckout()->getLastRealOrderId();
		$order = Mage::getModel('sales/order')->loadByIncrementId($orderId);

		$session = Mage::getSingleton('checkout/session');

		$notificationUrl = Mage::getUrl('trustly/payment/update');

		$endUserId = strtolower($order->getCustomerEmail());

		$messageId = $order->getRealOrderId();

		$billingAddress = $order->getBillingAddress();
		$shippingAddress = $order->getShippingAddress();

		$telephone = $billingAddress->getTelephone();
		if(!$telephone) {
			$telephone = $shippingAddress->getTelephone();
		}

			// Country is is the ISO 3166-1 country codes
		$countryId = $billingAddress->getCountryId();
		if(!$countryId) {
			$countryId = $shippingAddress->getCountryId();
		}

		$versionString = sprintf("Magento %s/%s %s", 
			Mage::getVersion(), Mage::app()->getFrontController()->getRequest()->getModuleName(),
			Mage::helper('trustly')->getExtensionVersion());

		$successurl = Mage::getUrl('trustly/payment/success');
		$failurl = Mage::getUrl('trustly/payment/fail');

		$response = $api->deposit(
			$notificationUrl,
			$endUserId,
			$messageId,
			Mage::app()->getLocale()->getLocaleCode(),
			number_format($order->getBaseGrandTotal() ,2 ,"." ,""),
			$order->getBaseCurrencyCode(),
			$countryId,
			$billingAddress->getTelephone(),
			$order->getCustomerFirstname(),
			$order->getCustomerLastname(),
			NULL, //NationalIdentificationNumber
			$order->getStore()->getWebsite()->getName(),
			Mage::helper('trustly')->getCustomerIp(),
			$successurl,
			$failurl,
			NULL, //TemplateURL
			'_top',
			NULL, //SuggestedMinAmount
			NULL, //SuggestedMaxAmount
			$versionString
		);

		if (!isset($response)) {
			Mage::log("Could not connect to Trustly (no response)", Zend_Log::WARN, self::LOG_FILE);
            Mage::getSingleton('core/session')->addError(Mage::helper('trustly')->__('Could not connect to Trustly.'));
			return false;
		} elseif (isset($response) && $response->isSuccess()) {
			$url = $response->getData('url');
			$trustlyOrderId = $response->getData('orderid');

			Mage::log("Got response with orderid $trustlyOrderId from Trustly, redirecting user to url: $url", Zend_Log::DEBUG, self::LOG_FILE);

			Mage::getSingleton('checkout/session')->setData('orderid_trustly', $trustlyOrderId);

			$incrementId = $order->getIncrementId();
			$orderMapping = Mage::getModel('trustly/ordermappings');
			$omData = array(
				'trustly_order_id' => $trustlyOrderId, 
				'magento_increment_id' => $incrementId,
				'datestamp' => Varien_Date::now(),
			);
			$orderMapping->setData($omData);
			$orderMapping->save();
			Mage::log("Saved mapping between Trustly orderid $trustlyOrderId and increment id $incrementId", Zend_Log::DEBUG, self::LOG_FILE);

			return $url;
		} else {
			$code = $response->getErrorCode();
			$message = $response->getErrorMessage();

			if ($message) {
				Mage::log(sprintf("Trustly pay call failed: %s - %s", $code, $message), Zend_Log::WARN, self::LOG_FILE);
				$_msjError = Mage::helper('trustly')->getErrorMsg($message, $code);
				Mage::getSingleton('core/session')->addError(Mage::helper('trustly')->__($_msjError));
				return false;
			} else {
				Mage::log("Trustly pay call failed without returning a proper error", Zend_Log::WARN, self::LOG_FILE);
				Mage::getSingleton('core/session')->addError(Mage::helper('trustly')->__('Error processing Trustly communication.'));
				return false;
			}
		}

		Mage::log("Trustly pay call failed without returning a proper error", Zend_Log::WARN, self::LOG_FILE);
		return false;
	}
}
