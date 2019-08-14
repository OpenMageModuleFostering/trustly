<?php
class Trustly_Trustly_Model_Mysql4_Ordermappings extends Mage_Core_Model_Mysql4_Abstract{

	protected function _construct()
	{
		# This is a bit silly, I would ragther here use the 
		# truslty_order_id as the primary key for this table, but as magento 
		# will promptly do an update rather then an insert if the primary key 
		# for the table is set (as it will be when we insert new records) i 
		# need to invent a dummy id that is generated and never used. It also 
		# borks the ->load method as useless for me. Thank you for this 
		# abstraction...
		$this->_init('trustly/ordermappings', 'id');
	}
}
?>
