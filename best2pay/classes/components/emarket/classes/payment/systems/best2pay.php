<?php
	class best2payPayment extends payment {

		public function validate() {
			return true;
		}

		public function process($template = null) {
			$this->order->order();
            $formAction = $this->getBest2PayLink();
			$param = array();
			$param['formAction'] 	= $formAction;
            list($templateString) = emarket::loadTemplates(
				"emarket/payment/best2pay/" . $template,
				"form_block"
			);
			return emarket::parseTemplate($templateString, $param);
		}

        private function getBest2PayLink() {
            $testmode = $this->object->getValue('test_mode');
            if ($testmode){
                $best2pay_url ='https://test.best2pay.net';
            } else {
                $best2pay_url = 'https://pay.best2pay.net';
            }
            $url = $best2pay_url.'/webapi/Register';
            $id = $this->order->getNumber();
            $sector=$this->object->getValue('sector_id');
            $sum = number_format($this->order->getActualPrice(), 2, '.', '');
            $amount = round($sum * 100);
            $cmsController = cmsController::getInstance();
            $emarket = $cmsController->getModule('emarket');
            $currency = $emarket->getDefaultCurrency();
            $currencyName = ($currency instanceof iUmiObject) ? $currency->getValue('codename') : 'RUB';
            if (!in_array($currency, array('RUB', 'EUR', 'USD'))) {
                $currencyName = 'RUB';
            }
            if ($currencyName==='RUB'){
                $currency=643;
            } else if ($currencyName==='USD'){
                $currency=840;
            } else if ($currencyName==='EUR'){
                $currency=978;
            } else {
                throw new Exception('Wrong currency');
            }
            $password=$this->object->getValue('password');
            $desc='Оплата заказа'.' '.$id;
            $customer = umiObjectsCollection::getInstance()->getObject($this->order->getCustomerId());
            $email = $customer->getValue("e-mail");
            $signature  = base64_encode(md5($sector . $amount . $currency . $password));
            $protocol = !empty( $_SERVER['HTTPS'] ) ? 'https://' : 'http://';
            $www = $protocol . $cmsController->getCurrentDomain()->getHost();
            $answerUrl = $www . '/emarket/gateway/' . $this->order->getId();
            $data = array(
                'sector' => $sector,
                'reference' => $id,
                'amount' => $amount,
                'description' => $desc,
                'email' => $email,
                'currency' => $currency,
                'mode' => 1,
                'signature' => $signature,
                'url' => $answerUrl,
                'failurl' => $answerUrl,

            );
            $options = array(
                'http' => array(
                    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query($data),
                ),
            );
            $context  = stream_context_create($options);
            $best2pay_id = file_get_contents($url, false, $context);
            if (intval($best2pay_id) == 0) {
                throw new Exception('error register order');
            }
            $signature = base64_encode(md5($sector . $best2pay_id . $password));
            $link  = $best2pay_url
                . '/webapi/Purchase'
                . '?sector=' .$sector
                . '&id=' . $best2pay_id
                . '&signature=' . $signature;
            return $link;
        }

		public function poll() {
            $operationId = getRequest('operation');
            $orderId = getRequest('id');
            $sectorId=$this->object->getValue('sector_id');
            $password=$this->object->getValue('password');
            $signature = base64_encode(md5($sectorId . $orderId . $operationId  . $password));
            $testmode = $this->object->getValue('test_mode');
            if ($testmode){
                $best2pay_url ='https://test.best2pay.net';
            } else {
                $best2pay_url = 'https://pay.best2pay.net';
            }
            $url = $best2pay_url.'/webapi/Operation';
            $context = stream_context_create(array(
                'http' => array(
                    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query(array(
                        'sector' => $sectorId,
                        'id' => $orderId,
                        'operation' => $operationId,
                        'signature' => $signature
                    )),
                )
            ));
            $xml = file_get_contents($url, false, $context);
            if (!$xml)
                throw new Exception("Empty data");
            $xml = simplexml_load_string($xml);
            if (!$xml)
                throw new Exception("Non valid XML was received");
            $response = json_decode(json_encode($xml), true);
            if (!$response)
                throw new Exception("Non valid XML was received");

            $tmp_response = (array)$response;
            unset($tmp_response["signature"]);
            $signature = base64_encode(md5(implode('', $tmp_response) . $password));
            if ($signature !== $response['signature']){
                throw new Exception("Invalid signature");
            }
            $cmsController = cmsController::getInstance();
            $protocol = !empty( $_SERVER['HTTPS'] ) ? 'https://' : 'http://';
            $www = $protocol . $cmsController->getCurrentDomain()->getHost();
            $successUrl = empty( $this->object->ok_url ) ? $www . '/emarket/purchase/result/successful/' : $this->_http( $this->object->ok_url );
            $failUrl = empty( $this->object->ko_url ) ? $www . '/emarket/purchase/result/fail/' : $this->_http( $this->object->ko_url );
            $amount=$response['amount'];
            $amount = (float) $amount;
            $amount = $amount/100;
           	$orderActualPrice = (float) $this->order->getActualPrice();
            if ($orderActualPrice != $amount) {
                throw new Exception("Wrong order amount");
            }
            if (($response['type'] == 'PURCHASE' || $response['type'] == 'PURCHASE_BY_QR' || $response['type'] == 'AUTHORIZE') && $response['state'] == 'APPROVED'){
               	$this->order->setPaymentStatus('accepted');
                $this->order->payment_document_num = $operationId;
                $this->order->commit();
                if ($emarket = $cmsController->getModule("emarket")) {
                    $emarket->redirect($successUrl);
                }
            } else {
                $this->order->setPaymentStatus('declined');
                $this->order->payment_document_num = $operationId;
                $this->order->commit();
                if ($emarket = $cmsController->getModule("emarket")) {
                    $emarket->redirect($failUrl);
                }
            }
		}
	};
?>