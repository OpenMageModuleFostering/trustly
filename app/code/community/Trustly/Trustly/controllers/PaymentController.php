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

class Trustly_Trustly_PaymentController extends Mage_Core_Controller_Front_Action
{
	const LOG_FILE = 'trustly.log';

	public function getStandard()
	{
		return Mage::getSingleton('trustly/standard');
	}

	public function _expireSession()
	{
		if(!Mage::getSingleton('customer/session')->isLoggedIn()) {
			$this->_redirect('customer/account', array('_secure'=>Mage::app()->getStore()->isCurrentlySecure()));
			return true;
		}

		return false;
	}


	public function redirectAction()
	{
		Mage::log("redirectAction()", Zend_Log::DEBUG, self::LOG_FILE);
		$session = Mage::getSingleton('checkout/session');
		$quoteid = $session->getQuoteId();

		# Sanity check
		if(!$session->getLastRealOrderId()) {
			Mage::log("Attempting to reload the directpage without valid order", Zend_Log::DEBUG, self::LOG_FILE);
			$this->_redirect('checkout/cart', array('_secure'=>Mage::app()->getStore()->isCurrentlySecure()));
			return ;
		}

		Mage::log("Processing order for quote $quoteid", Zend_Log::DEBUG, self::LOG_FILE);

		# If the user reloads the payment page we should not create a new
		# order, instead we should reuse the existing order and iframe
		# redirection url. To prevent the user from cancelling the order,
		# adding more to the same quote and then piggyback on the same payment
		# use the fact that the increment id of the order will change when
		# doing this. If we get a new increment id from when the iframe was
		# loaded the last time then create a new order.
		$lastiframeurl = $session->getTrustlyIframeUrl();
		if($lastiframeurl) {
			$lastincrementid = $session->getTrustlyIncrementId();

			$currentorder = NULL;
			$currentincrementid = NULL;

			$lastrealorderid = $session->getLastRealOrderId();
			if($lastrealorderid) {
				$currentorder = Mage::getModel('sales/order')->loadByIncrementId($lastrealorderid);
			}

			if($currentorder and $currentorder->getId()) {
				# Yes, same as the lastrealorderid above, this just load:s it to make sure it is good. 
				$currentincrementid = $currentorder->getIncrementId();
			}

			if ($currentincrementid and $lastincrementid == $currentincrementid) {
				Mage::log("Reusing the iframeurl for increment $currentincrementid, reloading the redirect page?", Zend_Log::DEBUG, self::LOG_FILE);
			} else {
				$lastiframeurl = NULL;
			}
		}
		/* Clear all the error messages before we checkout, or previous errors might linger on */
		$session->getMessages(true);

		if(!$lastiframeurl) {
			$standard = Mage::getModel('trustly/standard');
			$redirectError = NULL;
			$response = NULL;
			try {
				$response = $standard->redirectProcess();
			} catch(Trustly_DataException $e) {
				Mage::log("Got Trustly_DataException when communicating with Trustly: " . (string)$e, Zend_Log::WARN, self::LOG_FILE);
				Mage::logException($e);
				$redirectError = Mage::helper('trustly')->__("Failed to communicate with Trustly.");
			} catch(Trustly_ConnectionException $e) {
				Mage::log("Got Trustly_ConnectionException when communicating with Trustly: " . (string)$e, Zend_Log::WARN, self::LOG_FILE);
				Mage::logException($e);
				$redirectError = Mage::helper('trustly')->__("Cannot connect to Trustly services.");
			} catch(Trustly_SignatureException $e) {
				Mage::log("Got Trustly_SignatureException when communicating with Trustly: " . (string)$e, Zend_Log::WARN, self::LOG_FILE);
				Mage::logException($e);
				$redirectError = Mage::helper('trustly')->__("Cannot verify the authenticity of Trustly communication.");
			}

			if (!isset($response)) {
				Mage::log("No response from redirectProcess()", Zend_Log::WARN, self::LOG_FILE);
				if(!isset($redirectError)) {
					$redirectError = Mage::helper('trustly')->__("Failed to communicate with Trustly.");
				}
			}

			if($redirectError) {
				$session->addError($redirectError);
				$this->cancelCheckoutOrder();
			} else {
				# We use this to keep track of the current quote we have
				# transformed into an order, use it when cancelling (to restore
				# quote) or when finishing order (to finalize quote).
				$session->setTrustlyQuoteId($quoteid);
				# We use these to handle the reload of the redirect action
				# page. Incrementid is the increment of the current order
				# displayed in the page and the url is the trustly iframe url
				# for this increment.
				$session->setTrustlyIncrementId($session->getLastRealOrderId());
				$session->setTrustlyIframeUrl($response);
			}
		}

		$this->loadLayout();
		try {
			$this->getLayout()->getBlock('head')->setTitle($this->__('Trustly payment'));
			$this->_initLayoutMessages('customer/session');
			$this->getLayout()->getBlock('content')->append($this->getLayout()->createBlock('trustly/redirect','trustly_iframe', array('template' => 'trustly/iframe.phtml')));
		} catch (Mage_Payment_Exception $e) {
			if ($e->getFields()) {
				$result['fields'] = $e->getFields();
			}
			$result['error'] = $e->getMessage();
		} catch (Mage_Core_Exception $e) {
			$result['error'] = $e->getMessage();
		} catch (Exception $e) {
			$result['error'] = $this->__('Unable to set payment method.');
		}
		if(isset($result['error'])) {
			Mage::log("Failed to load layload in redirectAction(): " . $result['error'], Zend_Log::WARN, self::LOG_FILE);

			throw new Mage_Payment_Model_Info_Exception($result['error']);
		}

		$session->unsQuoteId();
		$session->unsRedirectUrl();

		$this->renderLayout();
	}


	public function updateAction()
	{

		$api = Mage::helper('trustly')->getTrustlyAPI();

		if(!isset($api)) {
			Mage::log("Attempting to process a payment notification, but the Trustly module is not properly configured", Zend_Log::ERR, self::LOG_FILE);
			return ;
		}

		//  Invoming Trustly request
		$httpBody = file_get_contents('php://input');

		try {
			$notification = $api->handleNotification($httpBody);
		} catch(Trustly_JSONRPCVersionException $e) {
			Mage::log("Got incoming notification with invalid json rpc version (".$e.")", Zend_Log::WARN, self::LOG_FILE);
			return ;
		} catch(Trustly_SignatureException $e) {
			Mage::log("Got incoming notification with invalid signature (".$e."), message was ".$e->getBadData(), Zend_Log::WARN, self::LOG_FILE);
			return ;
		}

		if(isset($notification)) {

			$standard = Mage::getModel('trustly/standard');

			$order = Mage::getModel('sales/order');
			$orderMapping = Mage::getModel('trustly/ordermappings');

			$trustlyOrderId = $notification->getData('orderid');
			$trustlyNotificationId = $notification->getData('notificationid');
			$orderMapping->loadByTrustlyOrderId($trustlyOrderId);
			if($orderMapping) {
				$incrementId = $orderMapping->getMagentoIncrementId();
			}else {
					# If we cannot find the mapping here, check to see if the
					# enduserid seems to be a valid increment id, in old code this
					# used to be the case. So to handle the transition between the
					# old module and this one, allow for this.
					# We always use email for enduserid, so we will not match something by accident.
				$enduserid = $notification->getData('enduserid');

				if(preg_match('/^[0-9]+$/', $enduserid)) {
					Mage::log("Falling back to using enduserid as the incrementid for enduserid $enduserid", Zend_Log::WARN, self::LOG_FILE);
					$incrementId = $enduserid;
				}
			}

			if(!$incrementId) {
				Mage::helper('trustly')->sendResponseNotification($notification, true);

				Mage::getSingleton('checkout/session')->addError(Mage::helper('trustly')->__("Cannot find the relation of Trustly orderid %s for user %s.", $trustlyOrderId, $notification->getData('enduserid')));
				session_write_close();

				Mage::log("Could not find the mapping of Trustly orderid $trustlyOrderId in the incoming notification. incrementid $incrementId Enduser is ".$notification->getData('enduserid'), Zend_Log::WARN, self::LOG_FILE);

				return;
			}

			/* Due to race conditions we need to handle the processing
			 * for this order one at a time only. Otherwise we might
			 * end up processing the credit and pending
			 * notifications at the same time. */
			$increment_lockid = $orderMapping->lockIncrementForProcessing($incrementId);
			if($increment_lockid === false) {
				/* If we cannot lock this increment abort now, respond
				 * nothing we will get a new attempt later */
				Mage::log("Attempt to process already locked magento increment $incrementId", Zend_Log::DEBUG, self::LOG_FILE);
				session_write_close();
				return ;
			}

			$order->loadByIncrementId($incrementId);
			$realOrderId = $order->getRealOrderId();

			if (!$realOrderId) {
				Mage::helper('trustly')->sendResponseNotification($notification, true);

				Mage::getSingleton('checkout/session')->addError(Mage::helper('trustly')->__("Cannot find the order %s.", $orderId));
				session_write_close();

				Mage::log("Could not find the order with increment $incrementId (Trustly orderid $trustlyOrderId) in the incoming notification", Zend_Log::WARN, self::LOG_FILE);

				$orderMapping->unlockIncrementAfterProcessing($incrementId, $increment_lockid);

				return;
			}


			$_method = $notification->getMethod();
			$_amount = $notification->getData('amount');
			$_currency = $notification->getData('currency');

			$_grandTotal = Mage::helper('trustly')->getOrderAmount($order);
			$_order_currency_code = Mage::helper('trustly')->getOrderCurrencyCode($order);

			$trustly_payment = NULL;
			foreach ($order->getPaymentsCollection() as $_payment) {

				# We will add a transaction with the TxnID set to the Trustly OrderID, this will be the payment that is paid
				# We will also add a transaction for the pending notification (authorize)
				# We will also add a transaction for the credit notification (capture)
				if(!$_payment->isDeleted() and $_payment->getMethod() == 'trustly') {
					$trustly_payment = $_payment;
					break;
				}
			}

			if (is_null($trustly_payment)) {
				Mage::helper('trustly')->sendResponseNotification($notification, true);
				Mage::log(sprintf("Recieved payment notification for order %s, but payment method is %s, not Trustly", $incrementId, $trustly_payment->getMethod()), Zend_Log::WARN, self::LOG_FILE);
				$orderMapping->unlockIncrementAfterProcessing($incrementId, $increment_lockid);
				return ;
			}

			$order_transaction = $trustly_payment->getTransaction($trustlyOrderId);
			$notification_transaction = NULL;
			if(isset($order_transaction) && $order_transaction !== FALSE) {
				$order_trans_children = $order_transaction->getChildTransactions();
				if(isset($order_trans_children)) {
					foreach($order_trans_children as $tc) {
						if($tc->getTxnId() == $trustlyNotificationId) {
							$notification_transaction  = $tc;
							break;
						}
					}
				}
			}

				/* Check if we have processed this transaction before, if so
					* say we did fine, we obviously managed to save it...  */
			if(isset($notification_transaction)) {
				Mage::helper('trustly')->sendResponseNotification($notification, true);
				Mage::log(sprintf("Received notification %s already processed", $trustlyNotificationId), Zend_Log::DEBUG, self::LOG_FILE);
				$orderMapping->unlockIncrementAfterProcessing($incrementId, $increment_lockid);
				return ;
			}

			$order_invoice = NULL;
			foreach ( $order->getInvoiceCollection() as $invoice) {
				if($invoice->getTransactionId() == $trustlyOrderId) {
					$order_invoice = $invoice;
					break;
				}
			}

			$transactionSave = Mage::getModel('core/resource_transaction');

				/* We should always have a payment with the trustly method, if
					* we do not have one, create it we will need this regardless of the method for the notification */
			if(is_null($trustly_payment) || is_null($trustly_payment->getTxnType())) {
					/* Create a payment with our TrustlyOrderId as the
						* TransactionId, we will later add the current
						* notification as a child payment for this payment */
				$trustly_payment->resetTransactionAdditionalInfo();
				$trustly_payment->setTransactionId($trustlyOrderId);
				$trustly_payment->setParentTransactionId(NULL);
				$trustly_payment->setIsTransactionClosed(false);
				$trustly_payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, array('OrderId' => $trustlyOrderId));
				$order_transaction = $trustly_payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_ORDER);
				$transactionSave->addObject($order_transaction)
					->addObject($trustly_payment);
			}

				# Check to see if we have invoiced this order already, if
				# so do not do it again. We might be getting the same
				# notification over again.
			if(($_method == 'pending' || $_method == 'credit') && isset($order_invoice)) {
				Mage::log(sprintf("Recieved $_method notification for order with increment %s for orderid %s, but order already had an invoice, nothing done", $incrementId, $trustlyOrderId), Zend_Log::DEBUG, self::LOG_FILE);

			} elseif(($_method == 'pending' || $_method == 'credit') && !isset($order_invoice)) {
				if($_method == 'credit') {
					Mage::log(sprintf("Recieved a %s notification, no previous invoice could be found for Trustly orderid %s, magento order %s. No pending notification send before?! Creating one now", $_method, $trustlyOrderId, $incrementId), Zend_Log::WARN, self::LOG_FILE);
				}

					/* A credit notification without an invoice can mean one of two
					 * things, either the invoice has been removed or we did not
					 * receive the pending notification for this order. At this
					 * point we cannot really reject the money, a payment has been
					 * done, so create a new invoice and attach this payment to that invoice. */

					/* When we get the pending notification the user has
					 * completed his end of the payment. The payment can
					 * still fail and we are yet to be credited any funds.
					 * Create an invoice, but do not set it as paid just
					 *  yet. */

				$order_invoice = $order->prepareInvoice()
					->setTransactionId($trustlyOrderId)
					->addComment(Mage::helper('trustly')->__("Invoiced from Trustly payment"))
					->register();

				$order->addRelatedObject($order_invoice);
				$trustly_payment->setCreatedInvoice($order_invoice);

				$comment = Mage::helper('trustly')->__('Pending payment.');
				$comment .= '<br />' . Mage::helper('trustly')->__('Invoice for Trustly OrderId #%s created', $trustlyOrderId);

				$orderStatus = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;

				$order->setState($orderStatus, true, $comment, false);

				$transactionSave->addObject($order_invoice)
					->addObject($order)
					->addObject($trustly_payment);

				$trustly_payment->unsCreatedInvoice();

				Mage::log(sprintf("Recieved %s notification for order with increment %s for orderid %s", $_method, $incrementId, $trustlyOrderId), Zend_Log::INFO, self::LOG_FILE);

				if (((int)Mage::getModel('trustly/standard')->getConfigData('sendmailorderconfirmation')) == 1) {
					Mage::log('Sending new order email', Zend_Log::DEBUG, self::LOG_FILE);
					$order->sendNewOrderEmail();
				}
			}


			if($_method == 'pending') {
					/* We have no authorize for the payment (As we would simply
						* abort if we have gotten the pending before) so create
						* an authorization for the payment here and append */
				$notification_transaction = $this->addChildTransaction($trustly_payment,
					$trustlyNotificationId, $trustlyOrderId,
					Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH, true);

				$transactionSave->addObject($notification_transaction)
					->addObject($trustly_payment);
			} elseif($_method == 'credit') {
				/**
				 *  verify the amount sent and the currency_code, to prevent improper payments
				 */

				$_fmtExpectedAmount = Mage::helper('core')->currency($_grandTotal, true, false);
				$_fmtReceivedAmount = Mage::helper('core')->currency($_amount, true, false);
				if ($_fmtExpectedAmount != $_fmtExpectedAmount || $_order_currency_code != $_currency) {

					$comment = Mage::helper('trustly')->__('Invalid payment.');
					$comment .= "<br />" . Mage::helper('trustly')->__('Trustly orderid: %s', $notification->getData('orderid'));
					$comment .= "<br />" . Mage::helper('trustly')->__('Invoice: %s', $order_invoice->getIncrementId());
					$comment .= "<br />" . Mage::helper('trustly')->__('Amount received: %s %s', $_amount, $_currency);
					$comment .= "<br />" . Mage::helper('trustly')->__('Amount expected: %s %s', $_fmtExpectedAmount, $_order_currency_code);
					$comment .= "<br />" . Mage::helper('trustly')->__('Date of transaction: %s', date('Y-m-d H:i:s', strtotime($notification->getData('timestamp'))));

					$orderStatus = Mage_Sales_Model_Order::STATE_CANCELED;
					$order->setState($orderStatus, true, $comment, false);
					$transactionSave->addObject($order);
					$transactionSave->save();

					Mage::log(sprintf("Recieved invalid payment for order %s, got amount %s %s", $incrementId, $_amount, $_currency), Zend_Log::WARN, self::LOG_FILE);

					/* The response is wether or not the notification we
					 * recived and handled properly, not if we liked the
					 * contents of it */
					Mage::helper('trustly')->sendResponseNotification($notification, true);

					$orderMapping->unlockIncrementAfterProcessing($incrementId, $increment_lockid);
					return;
				}

				$trustly_payment->setAmountCharged($_amount);
				$order->setIsInProcess(true);

				$comment = Mage::helper('trustly')->__('Authorized payment.');
				$comment .= "<br />" . Mage::helper('trustly')->__('Trustly orderid: %s', $notification->getData('orderid'));
				$comment .= "<br />" . Mage::helper('trustly')->__('Invoice: %s', $order_invoice->getIncrementId());
				$comment .= "<br />" . Mage::helper('trustly')->__('Date of transaction: %s', date('Y-m-d H:i:s', strtotime($notification->getData('timestamp'))));
				$comment .= "<br />" . Mage::helper('trustly')->__('Notification id: %s', $notification->getData('notificationid'));
				$comment .= "<br />" . Mage::helper('trustly')->__('Payment amount: %s %s', $_amount, $_currency);

				/* Funds are credited to merchant account, we call this captured */
				$notification_transaction = $this->addChildTransaction($trustly_payment, $trustlyNotificationId, $trustlyOrderId,
					Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE, true);

				/* Lookup an invoice connected to this order with the amount we
				 * have gotten paid with, mark as paid, there should really be
				 * only one */
				$open = Mage_Sales_Model_Order_Invoice::STATE_OPEN;
				if ($order_invoice->getState() == $open && Mage::helper('trustly')->getOrderAmount($order_invoice) == $_amount) {
					$trustly_payment->capture($order_invoice);
					if ($order_invoice->getIsPaid()) {
						$order_invoice->pay();
					}
						/* Set the transaction ID again here, if we fiddle with
							* the invoice it will change the txnid to this
							* transaction otherwise */
					$order_invoice->setTransactionId($trustlyOrderId);

					/* There is an interesting feature in the Order Payment, if
						* we created the payment in this notification (i.e.
						* credit notification is the first one to arrive) then
						* the lookup of parent transaction will fail as
						* addTransaction() always looks up the transaction id
						* given and the lookup is cached (and it does not exist
						* so non-existance is cached. So... in this case,
						* workaround by loading parent manually and close it. */

				} else {
					Mage::log(sprintf("Could not find an invoice to pay for order %s, amount %s %s. Order invoice is state: %s, amount: %s %s, base amount %s %s",
							$incrementId, $_amount, $_currency,
							$order_invoice->getState(),
							$order_invoice->getGrandTotal(), $order_invoice->getOrderCurrencyCode(),
							$order_invoice->getBaseGrandTotal(), $order_invoice->getBaseCurrencyCode()),
						Zend_Log::WARN, self::LOG_FILE);
				}

				$orderStatus = Mage_Sales_Model_Order::STATE_PROCESSING;
				$order->setState($orderStatus, true, $comment, false);

				$transactionSave->addObject($order_invoice)
					->addObject($order)
					->addObject($trustly_payment);


				Mage::log("Recieved payment for order with increment $incrementId", Zend_Log::INFO, self::LOG_FILE);

			}elseif($_method == 'debit') {
				$comment = Mage::helper('trustly')->__('Payment failed, debit received.');
				$comment .= "<br />" . Mage::helper('trustly')->__('Trustly orderid: %s', $notification->getData('orderid'));
				$comment .= "<br />" . Mage::helper('trustly')->__('Date of transaction: %s', date('Y-m-d H:i:s', strtotime($notification->getData('timestamp'))));
				$comment .= "<br />" . Mage::helper('trustly')->__('Notification id: %s', $notification->getData('notificationid'));
				$comment .= "<br />" . Mage::helper('trustly')->__('Debit amount: %s %s', $_amount, $_currency);

					/* Normally the only debit amount that should be received is
					 * the full amount, but you never know.... */
				if ($order->getGrandTotal() == $_amount) {
					$creditmemo = Mage::getModel('sales/service_order', $order)
						->prepareCreditmemo()
						->setPaymentRefundDisallowed(true)
						->setAutomaticallyCreated(true)
						->register();

					$creditmemo->addComment($this->__('Credit memo has been created automatically'));
					$transactionSave->addObject($creditmemo);
				}

				/* Add a child transaction to the original payment issuing a refund for the order.  */
				$notification_transaction = $this->addChildTransaction($trustly_payment, $trustlyNotificationId, $trustlyOrderId,
					Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND, true);

				$order->setState($order->getStatus(), true, $comment);

				$transactionSave->addObject($order)
					->addObject($trustly_payment);

				Mage::log("Recieved debit for order with increment $incrementId of $_amount $_currency", Zend_Log::INFO, self::LOG_FILE);
			}elseif($_method == 'cancel') {

				/* Cancel will be sent when the payment will not be completed.
				 * The cancel method will always be sent in conjunction with a
				 * debit notification, in the debit notification we handle the
				 * montary changeback and cancel of the invoices */

				$notification_transaction = $this->addChildTransaction($trustly_payment, $trustlyNotificationId, $trustlyOrderId,
					Mage_Sales_Model_Order_Payment_Transaction::TYPE_VOID, true);

				$order->cancel();

				$transactionSave->addObject($order)
					->addObject($trustly_payment);

				Mage::log("Recieved cancel for order with increment $incrementId", Zend_Log::INFO, self::LOG_FILE);
			}

			if(isset($notification_transaction)) {
				if($notification_transaction->getShouldCloseParentTransaction()) {

					$parent_transaction = Mage::getModel('sales/order_payment_transaction')
						->setOrderPaymentObject($trustly_payment)
						->loadByTxnId($notification_transaction->getParentTransactionId());
					if(isset($parent_transaction) && $parent_transaction !== FALSE && $parent_transaction->getId()) {
						if (!$parent_transaction->getIsClosed()) {
							$parent_transaction->isFailsafe(false)->close(false);

							$transactionSave->addObject($parent_transaction);
						}
					}
				}
			}

			$transactionSave->save();
			Mage::helper('trustly')->sendResponseNotification($notification, true);

			$orderMapping->unlockIncrementAfterProcessing($incrementId, $increment_lockid);
		}
	}


	protected function addChildTransaction($payment, $trustlyNotificationId, $trustlyOrderId, $typeTarget, $closed = false)
	{
		$payment->resetTransactionAdditionalInfo();
		$payment->setTransactionId($trustlyNotificationId);
		$payment->setParentTransactionId($trustlyOrderId);
		$payment->setIsTransactionClosed($closed);
		return $payment->addTransaction($typeTarget);
	}


	public function  successAction()
	{
		Mage::log("successAction()", Zend_Log::DEBUG, self::LOG_FILE);

		$session = Mage::getSingleton('checkout/session');
		$session->setQuoteId($session->getTrustlyQuoteId(true));
		$session->getQuote()->setIsActive(false)->save();
		$session->unsTrustlyIncrementId();
		$session->unsTrustlyIframeUrl();

		$this->_redirect('checkout/onepage/success', array('_secure'=>true));
	}


	public function  failAction()
	{
		Mage::log("failAction()", Zend_Log::DEBUG, self::LOG_FILE);

		if($this->cancelCheckoutOrder()) {
			$session = Mage::getSingleton('checkout/session');
			$session->addSuccess(Mage::helper('trustly')->__('Trustly order has been canceled.'));
		}

		$this->_redirect('checkout/cart', array('_secure'=>Mage::app()->getStore()->isCurrentlySecure()));
	}


	public function  cancelAction()
	{
		Mage::log("cancelAction()", Zend_Log::DEBUG, self::LOG_FILE);

		if($this->cancelCheckoutOrder()) {
			$session = Mage::getSingleton('checkout/session');
			$session->addSuccess(Mage::helper('trustly')->__('Trustly order has been canceled.'));
		}

		$this->_redirect('checkout/cart', array('_secure'=>Mage::app()->getStore()->isCurrentlySecure()));
	}


	public function cancelCheckoutOrder()
	{
		$session = Mage::getSingleton('checkout/session');

		try {
			$orderId = $session->getLastOrderId();
			Mage::log("Attempting to cancel order $orderId", Zend_Log::DEBUG, self::LOG_FILE);
			$order = ($orderId) ? Mage::getModel('sales/order')->load($orderId) : false;

			$sess_quoteid = $session->getTrustlyQuoteId();
			if(!isset($sess_quoteid)) {
				$sess_quoteid = $session->getQuoteId();
			}

			if ($order && $order->getId() && $order->getQuoteId() == $sess_quoteid) {
				$incrementid = $order->getIncrementId();
				$order->cancel()->save();
				Mage::log("Cancel order $orderId, incrementid $incrementid", Zend_Log::INFO, self::LOG_FILE);

				$session->unsTrustlyQuoteId();
				$session->unsTrustlyIncrementId();
				$session->unsTrustlyIframeUrl();
				Mage::getModel('trustly/ordermappings')->unmapOrderIncrement($order->getIncrementId());
				$this->restoreQuote();
			} else {
				Mage::log(sprintf("No order found to cancel (order=%s, orderid=%s, orderquoteid=%s, sessionquoteid=%s)",
					($order?'YES':'NO'),
					($order->getId()?$order->getId():''),
					($order->getQuoteId()?$order->getQuoteId():''),
					($sess_quoteid?$sess_quoteid:'')),
				Zend_Log::WARN, self::LOG_FILE);
			}
		} catch (Mage_Core_Exception $e) {
			Mage::log("Got Mage_Core_Exception when cancelling order: " . $e->getMessage(), Zend_Log::WARN, self::LOG_FILE);
			$session->addError($e->getMessage());
			return false;
		} catch (Exception $e) {
			$session->addError(Mage::helper('trustly')->__('Unable to cancel Trustly order.'));
			Mage::log("Got Exception when cancelling order: " . $e->getMessage(), Zend_Log::WARN, self::LOG_FILE);
			Mage::logException($e);
			return false;
		}
		return true;
	}


	public function restoreQuote()
	{
		$session = Mage::getSingleton('checkout/session');
		$lastrealorderid = $session->getLastRealOrderId();

		if($lastrealorderid) {
			$order = Mage::getModel('sales/order')->loadByIncrementId($lastrealorderid);

			if ($order and $order->getId()) {
				$quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
				if ($quote->getId()) {
					Mage::log("Restoring session quote " . $order->getQuoteId(), Zend_Log::DEBUG, self::LOG_FILE);
					$quote->setIsActive(1);
					$quote->setReservedOrderId(null);
					$quote->save();

					$session->replaceQuote($quote);
					$session->unsLastRealOrderId();
					return true;
				}
			} else {
				Mage::log("Failed to restore session quote, order $lastrealorderid could not be loaded", Zend_Log::WARN, self::LOG_FILE);
			}
		} else {
			Mage::log("Failed to restore session quote, no last order defined", Zend_Log::DEBUG, self::LOG_FILE);
		}
		return false;
	}


	public function unmapQuote()
	{
		$session = Mage::getSingleton('checkout/session');

		$lastrealorderid = $session->getLastRealOrderId();
		$order = NULL;
		if($lastrealorderid) {
			$order = Mage::getModel('sales/order')->loadByIncrementId($lastrealorderid);

			if($order and $order->getId()) {
				# Yes, this is the same value as above ($lastrealorderid), just verify it's correctness first...
				$incrementid = $order->getIncrementId();
				if ($incrementid) {
					Mage::getModel('trustly/ordermappings')->unmapOrderIncrement($incrementid);
				}
			} else {
				Mage::log("Could not unmap quote, order $lastrealorderid could not be loaded", Zend_Log::WARN, self::LOG_FILE);
			}
		} else {
			Mage::log("Could not unmap quote, no last order defined", Zend_Log::DEBUG, self::LOG_FILE);
		}
		return false;
	}
}
/* vim: set noet cindent ts=4 ts=4 sw=4: */
