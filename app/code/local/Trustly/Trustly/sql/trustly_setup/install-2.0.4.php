<?php
$installer = $this;
$installer->startSetup();
if(!$installer->getConnection()->isTableExists($installer->getTable('trustly/ordermappings'))) {
	$table = $installer->getConnection()->newTable($installer->getTable('trustly/ordermappings'))
			->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array('unsigned' => true, 'nullable' => false, 'primary' => true, 'identity' => true), 'Dummy id')
			->addColumn('trustly_order_id', Varien_Db_Ddl_Table::TYPE_TEXT, 20, array('nullable' => false), 'Trustly Orderid')
			->addColumn('magento_increment_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, array('unsigned' => true, 'nullable' => false), 'Magento Increment id')
			->addColumn('datestamp', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(), 'Timestamp')
			->addColumn('lock_timestamp', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array('nullable' => true), 'Lock timestamp')
			->addColumn('lock_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array('unsigned' => true, 'nullable' => true), 'Lock process id')
			->setComment('Mapping between trustly OrderId information and the Magento orders')
			->addIndex($installer->getIdxName(
					$installer->getTable('trustly/ordermappings'),
					array('trustly_order_id'),
					Varien_Db_Adapter_Interface::INDEX_TYPE_INDEX
				), 
				array('trustly_order_id'), 
				array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_INDEX)
			);
	$installer->getConnection()->createTable($table);
}
$installer->endSetup();
?>
