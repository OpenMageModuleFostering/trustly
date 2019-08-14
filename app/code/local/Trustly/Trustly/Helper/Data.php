<?php

require_once (Mage::getModuleDir('', 'Trustly_Trustly') . DS . 'lib' . DS .'Trustly.php');

/**
*
*/
class Trustly_Trustly_Helper_Data extends Mage_Core_Helper_Abstract
{
	private $trustlyAPI = NULL;

	public function __construct(){
	}


	public function getTrustlyAPIHost()
	{
		if($this->getTrustlyIsLive()) {
			return 'trustly.com';
		}else{
			return 'test.trustly.com';
		}
	}


	public function getTrustlyIsLive()
	{
		if (Mage::getStoreConfigFlag('payment/trustly/urltrustly') == '1') {
			return TRUE;
		}else{
			return FALSE;
		}
	}

	public function getTrustlySecureNotifications()
	{
		if (Mage::getStoreConfigFlag('payment/trustly/httpnotifications') == '1') {
			return FALSE;
		}else{
			return TRUE;
		}
	}


	private function internalIPAdress($ip)
	{

		if (!empty($ip) && ip2long($ip)!=-1) {
			$reserved_ips = array (
				array('0.0.0.0','2.255.255.255'),
				array('10.0.0.0','10.255.255.255'),
				array('127.0.0.0','127.255.255.255'),
				array('169.254.0.0','169.254.255.255'),
				array('172.16.0.0','172.31.255.255'),
				array('192.0.2.0','192.0.2.255'),
				array('192.168.0.0','192.168.255.255'),
				array('255.255.255.0','255.255.255.255')
			);

			foreach ($reserved_ips as $r) {
				$min = ip2long($r[0]);
				$max = ip2long($r[1]);
				if ((ip2long($ip) >= $min) && (ip2long($ip) <= $max)) {
					return true;
				}
			}

			return false;
		} else {
			return true;
		}
	}


	public function getCustomerIp()
	{

		if (!$this->internalIPAdress(@$_SERVER["HTTP_CLIENT_IP"])) {
			return $_SERVER["HTTP_CLIENT_IP"];
		}

		foreach (explode(",",@$_SERVER["HTTP_X_FORWARDED_FOR"]) as $ip) {
			if (!$this->internalIPAdress(trim($ip))) {
				return $ip;
			}
		}

		if (!$this->internalIPAdress(@$_SERVER["HTTP_X_FORWARDED"])) {
			return $_SERVER["HTTP_X_FORWARDED"];
		} elseif (!$this->internalIPAdress(@$_SERVER["HTTP_FORWARDED_FOR"])) {
			return $_SERVER["HTTP_FORWARDED_FOR"];
		} elseif (!$this->internalIPAdress(@$_SERVER["HTTP_FORWARDED"])) {
			return $_SERVER["HTTP_FORWARDED"];
		} elseif (!$this->internalIPAdress(@$_SERVER["HTTP_X_FORWARDED"])) {
			return $_SERVER["HTTP_X_FORWARDED"];
		} else {
			return $_SERVER["REMOTE_ADDR"];
		}
	}


	public function getModuleDir()
	{
		return Mage::getModuleDir('', 'Trustly_Trustly');
	}


	public function getErrorMsg ($message, $number)
	{
		return $message . " (" . $number . ")";
	}


	public function getTrustlyAPI()
	{
		if(!isset($this->trustlyAPI)) {
			if($this->getTrustlyUsername() && $this->getTrustlyPassword() &&
				$this->getTrustlyPrivateKey()) {

					$this->trustlyAPI = new Trustly_Api_Signed(
						NULL,
						$this->getTrustlyUsername(),
						$this->getTrustlyPassword(),
						$this->getTrustlyAPIHost(),
						443,
						true);
					$this->trustlyAPI->useMerchantPrivateKey($this->getTrustlyPrivateKey());
			}
		}
		return $this->trustlyAPI;
	}


	public function getTrustlyPrivateKey()
	{
		if($this->getTrustlyIsLive()) {
			return Mage::getModel('trustly/standard')->getConfigData('merchantkey');
		} else {
			return Mage::getModel('trustly/standard')->getConfigData('merchantkeytest');
		}
	}


	public function getTrustlyUsername()
	{
		if($this->getTrustlyIsLive()) {
			return Mage::getModel('trustly/standard')->getConfigData('merchantusername');
		} else {
			return Mage::getModel('trustly/standard')->getConfigData('merchantusernametest');
		}
	}


	public function getTrustlyPassword()
	{
		if($this->getTrustlyIsLive()) {
			return Mage::getModel('trustly/standard')->getConfigData('merchantpassword');
		} else {
			return Mage::getModel('trustly/standard')->getConfigData('merchantpasswordtest');
		}
	}


	public function sendResponseNotification($notification, $success)
	{
		$api = $this->getTrustlyAPI();

		$response = $api->notificationResponse($notification, $success);
		header('Cache-Control: no-cache, must-revalidate');
		header('Content-type: application/json');
		echo $response->json();
	}


	public function getExtensionVersion()
	{
		return (string) Mage::getConfig()->getNode()->modules->Trustly_Trustly->version;
	}
}