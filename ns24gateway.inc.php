<?php

	/*
	 * NetProfile Class Library: NPOSMPGateway
	 * © Copyright 2010 Alex 'Unik' Unigovsky
	 *
	 * This file is part of NetProfile Class Library.
	 * NetProfile is free software: you can redistribute it and/or
	 * modify it under the terms of the GNU Affero General Public
	 * License as published by the Free Software Foundation, either
	 * version 3 of the License, or (at your option) any later
	 * version.
	 *
	 * NetProfile is distributed in the hope that it will be useful,
	 * but WITHOUT ANY WARRANTY; without even the implied warranty of
	 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	 * GNU Affero General Public License for more details.
	 *
	 * You should have received a copy of the GNU Affero General
	 * Public License along with NetProfile. If not, see
	 * <http://www.gnu.org/licenses/>.
	 */

	class NPNS24Gateway extends NPExternalOperationGateway
	{
		protected function findAccessEntity($account)
		{
			global $W3BOX;

			$xreq = new NPRequest();
			$xreq->addCondition($W3BOX->db->cond->eq(
				$W3BOX->db->quote_field('etype'),
				$W3BOX->db->quote_string('access')
			));
			$xreq->addCondition($W3BOX->db->cond->eq(
				$W3BOX->db->quote_field('nick'),
				$W3BOX->db->quote_string($account)
			));
			$xlist = NPAccessEntityFactory::getList($xreq);
			unset($xreq);
			$cnt = count($xlist);
			if($cnt === 0 || $cnt > 1)
				return NPAccessEntityFactory::get(50264);
			return $xlist[0];
		}

		protected function sendResponseXML(NPExternalOperation $xop = null, $code, $text)
		{
			global $W3BOX;

			$doc = new DOMDocument('1.0', 'UTF-8');
			$resp = $doc->createElement('pay-response');
			$doc->appendChild($resp);
			switch($code)
			{
				case '1':
					#info

					$el = $doc->createElement('balance');
					$el->appendChild($doc->createTextNode($xop->getStash()->getAmountRounded(2)));
					$resp->appendChild($el);

					$el = $doc->createElement('name');
					$el->appendChild($doc->createTextNode($xop->getEntity()));
					$resp->appendChild($el);

					$el = $doc->createElement('account');
					$el->appendChild($doc->createTextNode($xop->getEntity()));
					$resp->appendChild($el);

					$el = $doc->createElement('abonplata');
					$el->appendChild($doc->createTextNode('500'));
					$resp->appendChild($el);

					$el = $doc->createElement('min_amount');
					$el->appendChild($doc->createTextNode($xop->getProvider()->getMinDifference()));
					$resp->appendChild($el);

					$el = $doc->createElement('max_amount');
					$el->appendChild($doc->createTextNode($xop->getProvider()->getMaxDifference()));
					$resp->appendChild($el);

					$el = $doc->createElement('status_code');
					$el->appendChild($doc->createTextNode('21'));
					$resp->appendChild($el);
					
					$el = $doc->createElement('parameters');
					$el->appendChild($doc->createTextNode($text));
					$resp->appendChild($el);

					break;
				case '4':
					$el = $doc->createElement('pay_id');
					$el->appendChild($doc->createTextNode($xop->getExternalID()));
					$resp->appendChild($el);

					$el = $doc->createElement('amount');
					$el->appendChild($doc->createTextNode($xop->getDifference()));
					$resp->appendChild($el);

					$el = $doc->createElement('status_code');
					$el->appendChild($doc->createTextNode('22'));
					$resp->appendChild($el);

					$el = $doc->createElement('description');
					$el->appendChild($doc->createTextNode($text));
					$resp->appendChild($el);
					break;
					#pay
				case '7':
					#status
					$code = '11';
					$s = $xop->getState();
					if($s == 'CLR')
						$rcode = '111';
					elseif($s == 'CNC')
						$rcode = '130';
					elseif($s == 'NEW')
						$code = '-10';
					else
						$rcode = '120';


					if($code == '11')
					{
						$tr = $doc->createElement('transaction');
						$resp->appendChild($tr);

						$el = $doc->createElement('pay_id');
						$el->appendChild($doc->createTextNode($xop->getExternalID()));
						$tr->appendChild($el);

						$el = $doc->createElement('amount');
						$el->appendChild($doc->createTextNode($xop->getDifference()));
						$tr->appendChild($el);

						$el = $doc->createElement('status');
						$el->appendChild($doc->createTextNode($rcode));
						$tr->appendChild($el);

						$el = $doc->createElement('service_id');
						$el->appendChild($doc->createTextNode('11'));
						$tr->appendChild($el);

						$el = $doc->createElement('time_stamp');
						$el->appendChild($doc->createTextNode($xop->getTimestamp('d.m.Y H:m:s')));
						$tr->appendChild($el);

					}

					$el = $doc->createElement('status_code');
					$el->appendChild($doc->createTextNode($code));
					$resp->appendChild($el);
					break;
				default:
					$el = $doc->createElement('status_code');
					$el->appendChild($doc->createTextNode($code));
					$resp->appendChild($el);
					
					$el = $doc->createElement('parameters');
					$el->appendChild($doc->createTextNode($text));
					$resp->appendChild($el);


			}

			$now = new DateTime;
			$el = $doc->createElement('time_stamp');
			$el->appendChild($doc->createTextNode($now->format('d.m.Y H:m:s')));
			$resp->appendChild($el);

			$el = $doc->createElement('service_id');
			$el->appendChild($doc->createTextNode('11'));
			$resp->appendChild($el);


			$W3BOX->http->setctype('application/xml', 'UTF-8');
			$W3BOX->http->send();
			echo $doc->saveXML();
		}

		public function receiveRequest(NPExternalOperationProvider $p)
		{
			global $W3BOX;

			$W3BOX->http->filtervar('ACT', W3BOX_RM_REQUEST, W3BOX_RV_INTEGER);
			$W3BOX->http->filtervar('PAY_AMOUNT', W3BOX_RM_REQUEST, W3BOX_RV_STRING);
			$W3BOX->http->filtervar('PAY_ACCOUNT', W3BOX_RM_REQUEST, W3BOX_RV_STRING);
			$W3BOX->http->filtervar('PAY_ID', W3BOX_RM_REQUEST, W3BOX_RV_STRING);

			$act = $W3BOX->http->getvar('ACT');
			$amount = $W3BOX->http->getplain('PAY_AMOUNT');
			$account = $W3BOX->http->getplain('PAY_ACCOUNT');
			$pid = $W3BOX->http->getplain('PAY_ID');

			if(!$act)
				throw new NPExternalOperationException(self::STATE_ERR_NODATA, 'Unable to read command');
			if(!$pid)
				throw new NPExternalOperationException(self::STATE_ERR_NODATA, 'Unable to read transaction ID');


			$xop = null;
			try
			{
				$xreq = new NPExternalOperationRequest();
				$xreq->setProviderID($p->getID());
				$xreq->setExternalID($pid);
				$xlist = NPExternalOperationFactory::getList($xreq);
				unset($xreq);
				$cnt = count($xlist);
				if($cnt === 0)
					$xop = new NPExternalOperation();
				elseif($cnt > 1)
					throw new NPExternalOperationException(self::STATE_ERR_DUPLICATE, 'Duplicate transactions found');
				else
					$xop = $xlist[0];
				unset($xlist);

				switch($act)
				{
					case '1':
						#info
						$xop->setExternalID($pid);
						$xop->setProvider($p);
						if(!$account)
							throw new NPExternalOperationException(self::STATE_ERR_WRONG_USER, 'Unable to read transaction recipient', $xop);
						$xop->setExternalAccount($account);
						$obj = $this->findAccessEntity($account);
						if(!$obj)
							throw new NPExternalOperationException(self::STATE_ERR_NO_USER, 'Unable to find transaction recipient', $xop);
						$xop->setEntity($obj);
						$obj = $obj->getStash();
						if(!$obj)
							throw new NPExternalOperationException(self::STATE_ERR_NO_STASH, 'Unable to determine recipient\'s balance', $xop);
						$xop->setStash($obj);
						unset($obj);
						return $this->sendResponseXML($xop, 1, 'info');
						die();
						break;
					case '4':
						#pay
						if($xop->isFinal())
							throw new NPExternalOperationException(self::STATE_ERR_DUPLICATE, 'Duplicate transactions found');

						$xop->setExternalID($pid);
						$xop->setProvider($p);
						if(!$amount)
							throw new NPExternalOperationException(self::STATE_ERR_NO_DIFF, 'Unable to read transaction sum');
						$sum = NPLibrary::packMoney($amount);
						if(is_null($sum))
							throw new NPExternalOperationException(self::STATE_ERR_WRONG_DIFF, 'Invalid format for transaction amount');
						$xop->setDifference($sum);
						if(!$account)
							throw new NPExternalOperationException(self::STATE_ERR_WRONG_USER, 'Unable to read transaction recipient', $xop);
						$xop->setExternalAccount($account);
						$obj = $this->findAccessEntity($account);
						if(!$obj)
							throw new NPExternalOperationException(self::STATE_ERR_NO_USER, 'Unable to find transaction recipient', $xop);
						$xop->setEntity($obj);
						$obj = $obj->getStash();
						if(!$obj)
							throw new NPExternalOperationException(self::STATE_ERR_NO_STASH, 'Unable to determine recipient\'s balance', $xop);
						$xop->setStash($obj);
						unset($obj);
						$xop->setState('CLR');
						$xop->commit();
						return $this->sendResponseXML($xop, 4, 'pay');
						die();
						break;
					case '7':
						#status
						return $this->sendResponseXML($xop, 7, 'status');
						die();
						break;
					default:
				}
			}
			catch(Exception $e)
			{
				if($e instanceof NPExternalOperationException)
					throw $e;
				throw new NPExternalOperationException(self::STATE_ERR_UNKNOWN, $e->getMessage(), $xop);
			}

			return $xop;
		}

		public function sendResponse(NPExternalOperation $xop = null, $code = 0, $text = null)
		{
			if(!is_int($code))
				throw new NPArgumentException();
			if(is_null($text))
				$text = 'Unknown error';
			elseif(!is_string($text))
				throw new NPArgumentException();
			$rcode = 0;
			switch($code)
			{
				case self::STATE_OK:
					return;
					break;
				case self::STATE_ERR_UNAUTH:
					$rcode = -101;
					break;
				case self::STATE_ERR_NODATA:
					$rcode = -101;
					break;
				case self::STATE_ERR_NO_DATE:
					$rcode = -101;
					break;
				case self::STATE_ERR_NO_USER:
					$rcode = -40;
					break;
				case self::STATE_ERR_NO_STASH:
					$rcode = -41;
					break;
				case self::STATE_ERR_NO_DIFF:
					$rcode = -42;
					break;
				case self::STATE_ERR_WRONG_DATE:
					break;
				case self::STATE_ERR_WRONG_USER:
					$rcode = -40;
					break;
				case self::STATE_ERR_WRONG_DIFF:
					break;
				case self::STATE_ERR_DIFF_SMALL:
					$rcode = -42;
					break;
				case self::STATE_ERR_DIFF_LARGE:
					$rcode = -42;
					break;
				case self::STATE_ERR_DB:
					$rcode = -101;
					break;
				case self::STATE_ERR_DUPLICATE:
					$rcode = -100;
					break;
				case self::STATE_ERR_DISABLED:
					$rcode = -90;
					break;
				case self::STATE_ERR_UNKNOWN:
					$rcode = -101;
					break;
				default:
					throw new NPArgumentException();
					break;
			}
			return $this->sendResponseXML($xop, $rcode, $text);
		}
	}

?>
