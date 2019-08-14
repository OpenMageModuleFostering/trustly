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

	public function lockIncrementForProcessing($incrementId) {
		$lockid = mt_rand(0, 2147483647);

		$table = $this->getMainTable();
		$wa = $this->_getWriteAdapter();
		$ra = $this->_getReadAdapter();

		$update_where = $wa->quoteInto('magento_increment_id = ? AND (lock_timestamp IS NULL OR lock_timestamp < NOW() - INTERVAL 1 minute)', $incrementId);
		$update = $wa->update($table, array('lock_timestamp' => new Zend_Db_Expr('NOW()'), 'lock_id' => $lockid), $update_where);

		$verify_where = $ra->quoteInto('magento_increment_id = ?', $incrementId);
		$select = $ra->select()->from($table)->reset('columns')->columns(array('lock_id'))->where($verify_where);
		$verify_lockid = $ra->fetchOne($select);

		if($verify_lockid == $lockid) {
			return $lockid;
		}
		return FALSE;
	}

	public function unlockIncrementAfterProcessing($incrementId, $lockid) {
		$table = $this->getMainTable();
		$wa = $this->_getWriteAdapter();

		$update_where = $wa->quoteInto('magento_increment_id = ?', $incrementId) . ' AND ' .
			$wa->quoteInto('lock_id = ?', $lockid);

		$update = $wa->update($table, array('lock_timestamp' => NULL, 'lock_id' => NULL), $update_where);

		return TRUE;
	}
}
/* vim: set noet cindent sts=4 ts=4 sw=4: */
