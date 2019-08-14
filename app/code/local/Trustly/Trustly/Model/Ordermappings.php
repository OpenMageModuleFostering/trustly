<?php
class Trustly_Trustly_Model_Ordermappings extends Mage_Core_Model_Abstract 
{
	protected function _construct()
	{
		$this->_init('trustly/ordermappings');
	}   

	public function loadByTrustlyOrderId($trustlyOrderId)
	{
		return $this->load($trustlyOrderId, 'trustly_order_id');
	}
}
?>
