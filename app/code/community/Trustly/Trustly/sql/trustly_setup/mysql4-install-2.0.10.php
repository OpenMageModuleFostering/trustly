<?php
$installer = $this;

$installer->run("
CREATE TABLE IF NOT EXISTS `{$installer->getTable('trustly/ordermappings')}` (
    `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, 
    `trustly_order_id` varchar(20) NOT NULL,
    `magento_increment_id` bigint(20) UNSIGNED,
    `datestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lock_timestamp` timestamp NULL,
    `lock_id` int(10) unsigned NULL,
    PRIMARY KEY (`id`),
    INDEX index_trustly_ordermapping_presta_cartid (`trustly_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");
