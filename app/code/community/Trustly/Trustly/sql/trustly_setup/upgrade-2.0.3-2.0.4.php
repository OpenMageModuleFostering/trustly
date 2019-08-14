<?php
$installer = $this;
$connection = $installer->getConnection();
$ordermappingstable = $installer->getTable('trustly/ordermappings');

$installer->startSetup();

$connection->modifyColumn(
	$ordermappingstable,
	'trustly_order_id',
	array(
		'type'		=> Varien_Db_Ddl_Table::TYPE_TEXT,
		'length'	=> 20,
		'nullable'	=> false,
		'comment'	=> 'Trustly Orderid'
	)
);

$connection->addColumn(
	$ordermappingstable,
	'lock_timestamp',
	array(
		'type'		=> Varien_Db_Ddl_Table::TYPE_TIMESTAMP,
		'nullable'	=> true,
		'comment'	=> 'Lock timestamp'
	)
);

$connection->addColumn(
	$ordermappingstable,
	'lock_id',
	array(
		'type'		=> Varien_Db_Ddl_Table::TYPE_INTEGER,
		'unsigned'	=> true,
		'nullable'	=> true,
		'comment'	=> 'Lock process id'
	)
);

$installer->endSetup();
?>
