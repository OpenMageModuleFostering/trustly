<?php

class Trustly_Trustly_Block_Redirect extends Mage_Core_Block_Template
{
	public function getIframe()
	{
		$session = Mage::getSingleton('checkout/session');
		$url = $session->getUrlTrustly();

		if (!isset($url) || empty($url)) {
			return false;
		} else {
			return $url;
		}
	}
}
