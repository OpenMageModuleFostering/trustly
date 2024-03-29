<?php
/**
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
 */

class Trustly_Data_JSONRPCNotificationRequest extends Trustly_Data {
	var $notification_body = NULL;

	public function __construct($notification_body) {

		$this->notification_body = $notification_body;
		$payload = json_decode($notification_body, TRUE);

		parent::__construct($payload);

		if($this->getVersion() != '1.1') {
			throw new Trustly_JSONRPCVersionException('JSON RPC Version '. $this->getVersion() .'is not supported');
		}
	}

	public function getParams($name=NULL) {
		if(!isset($this->payload['params'])) {
			return NULL;
		}
		$params = $this->payload['params'];
		if(isset($name)) {
			if(isset($params[$name])) {
				return $params[$name];
			}
		} else {
			return $params;
		}
		return NULL;
	}

	public function getData($name=NULL) {
		if(!isset($this->payload['params']['data'])) {
			return NULL;
		}
		$data = $this->payload['params']['data'];
		if(isset($name)) {
			if(isset($data[$name])) {
				return $data[$name];
			}
		} else {
			return $data;
		}
		return NULL;
	}

	public function getUUID() {
		return $this->getParams('uuid');
	}

	public function getMethod() {
		return $this->get('method');
	}

	public function getSignature() {
		return $this->getParams('signature');
	}

	public function getVersion() {
		return $this->get('version');
	}
}
/* vim: set noet cindent ts=4 ts=4 sw=4: */
