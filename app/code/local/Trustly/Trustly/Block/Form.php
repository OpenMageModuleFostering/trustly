<?php

/**
 *   information at checkout within the payment method
 */
class Trustly_Trustly_Block_Form extends Mage_Payment_Block_Form
{

	protected function _construct()
	{
		$locale = Mage::app()->getLocale();
		$mark = Mage::getConfig()->getBlockClassName('core/template');
		$mark = new $mark;
		$mark->setTemplate('trustly/mark.phtml')
			->setPaymentAcceptanceMarkHref('https://trustly.com/whatistrustly/');
		$this->setTemplate('trustly/form.phtml')
			->setMethodTitle('')
			->setMethodLabelAfterHtml($mark->toHtml())
			;
		return parent::_construct();
	}

}
