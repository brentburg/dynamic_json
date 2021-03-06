<?php

	require_once(TOOLKIT . '/class.gateway.php');
	require_once(TOOLKIT . '/class.xsltprocess.php');
	require_once(CORE . '/class.cacheable.php');
	
	if(isset($this->dsParamURL)) $this->dsParamURL = $this->__processParametersInString($this->dsParamURL, $this->_env, true, true);
	if(isset($this->dsParamXPATH)) $this->dsParamXPATH = $this->__processParametersInString($this->dsParamXPATH, $this->_env);

	$stylesheet = new XMLElement('xsl:stylesheet');
	$stylesheet->setAttributeArray(array('version' => '1.0', 'xmlns:xsl' => 'http://www.w3.org/1999/XSL/Transform'));

	$output = new XMLElement('xsl:output');
	$output->setAttributeArray(array('method' => 'xml', 'version' => '1.0', 'encoding' => 'utf-8', 'indent' => 'yes', 'omit-xml-declaration' => 'yes'));
	$stylesheet->appendChild($output);

	$template = new XMLElement('xsl:template');
	$template->setAttribute('match', '/');

	$instruction = new XMLElement('xsl:copy-of');

	## Namespaces
	if(isset($this->dsParamFILTERS) && is_array($this->dsParamFILTERS)){
		foreach($this->dsParamFILTERS as $name => $uri) $instruction->setAttribute('xmlns' . ($name ? ":$name" : NULL), $uri);
	}

	## XPath
	$instruction->setAttribute('select', $this->dsParamXPATH);

	$template->appendChild($instruction);
	$stylesheet->appendChild($template);

	$stylesheet->setIncludeHeader(true);

	$xsl = $stylesheet->generate(true);

	$cache_id = md5($this->dsParamURL . serialize($this->dsParamFILTERS) . $this->dsParamXPATH);

	$cache = new Cacheable(Symphony::Database());
	
	$cachedData = $cache->check($cache_id);
	
	$writeToCache = false;
	$valid = true;
	$result = NULL;
	$creation = DateTimeObj::get('c');
	
	$timeout = 6;
	if(isset($this->dsParamTIMEOUT)){
		$timeout = (int)max(1, $this->dsParamTIMEOUT);
	}
	
	if((!is_array($cachedData) || empty($cachedData)) || (time() - $cachedData['creation']) > ($this->dsParamCACHE * 60)){
		if(Mutex::acquire($cache_id, $timeout, TMP)){
			
			$start = precision_timer();		
			
			$ch = new Gateway;

			$ch->init();
			$ch->setopt('URL', $this->dsParamURL);
			$ch->setopt('TIMEOUT', $timeout);
			$xml = $ch->exec();
			$writeToCache = true;
			
			$end = precision_timer('STOP', $start);
			
			$info = $ch->getInfoLast();
						
			Mutex::release($cache_id, TMP);
			
			// check to see if it is JSON and convert it to XML
			if (preg_match('/(json|javascript)/i', $info['content_type']))
			{
				require_once EXTENSIONS.'/dynamic_json/lib/class.json_to_xml.php';
				$xml = Json_to_xml::convert($xml);
			}
			
			$xml = trim($xml);

			if((int)$info['http_code'] != 200 || !preg_match('/(xml|plain|text|json|javascript)/i', $info['content_type'])){
				
				$writeToCache = false;
				
				if(is_array($cachedData) && !empty($cachedData)){ 
					$xml = trim($cachedData['data']);
					$valid = false;
					$creation = DateTimeObj::get('c', $cachedData['creation']);
				}
				
				else{
					$result = new XMLElement($this->dsParamROOTELEMENT);
					$result->setAttribute('valid', 'false');
					
					if($end > $timeout){
						$result->appendChild(
							new XMLElement('error', 
								sprintf('Request timed out. %d second limit reached.', $timeout)
							)
						);
					}
					else{
						$result->appendChild(
							new XMLElement('error', 
								sprintf('Status code %d was returned. Content-type: %s', $info['http_code'], $info['content_type'])
							)
						);
					}
					
					return $result;
				}
			}

			elseif(strlen($xml) > 0 && !General::validateXML($xml, $errors, false, new XsltProcess)){
					
				$writeToCache = false;
				
				if(is_array($cachedData) && !empty($cachedData)){ 
					$xml = trim($cachedData['data']);
					$valid = false;
					$creation = DateTimeObj::get('c', $cachedData['creation']);
				}
				
				else{
					$result = new XMLElement($this->dsParamROOTELEMENT);
					$result->setAttribute('valid', 'false');
					//$result->appendChild(new XMLElement('error', __('XML returned is invalid.')));
					$result->appendChild(new XMLElement('error', __('<![CDATA['.$xml.']]>')));
				}
				
			}
			
			elseif(strlen($xml) == 0){
				$this->_force_empty_result = true;
			}
			
		}
		
		elseif(is_array($cachedData) && !empty($cachedData)){ 
			$xml = trim($cachedData['data']);
			$valid = false;
			$creation = DateTimeObj::get('c', $cachedData['creation']);
			if(empty($xml)) $this->_force_empty_result = true;
		}
		
		else $this->_force_empty_result = true;
		
	}
	
	else{
		$xml = trim($cachedData['data']);
		$creation = DateTimeObj::get('c', $cachedData['creation']);
	}
	
		
	if(!$this->_force_empty_result && !is_object($result)):
	
		$result = new XMLElement($this->dsParamROOTELEMENT);

		$proc = new XsltProcess;
		$ret = $proc->process($xml, $xsl);

		if($proc->isErrors()){
			
			$result->setAttribute('valid', 'false');
			$error = new XMLElement('error', __('XML returned is invalid.'));
			$result->appendChild($error);
			
			$messages = new XMLElement('messages');
			
			foreach($proc->getError() as $e){
				if(strlen(trim($e['message'])) == 0) continue;
				$messages->appendChild(new XMLElement('item', General::sanitize($e['message'])));
			}
			$result->appendChild($messages);
			
		}
		
		elseif(strlen(trim($ret)) == 0){
			$this->_force_empty_result = true;
		}
		
		else{
			
			if($writeToCache) $cache->write($cache_id, $xml);
			
			$result->setValue(self::CRLF . preg_replace('/([\r\n]+)/', '$1	', $ret));
			$result->setAttribute('status', ($valid === true ? 'fresh' : 'stale'));
			$result->setAttribute('creation', $creation);
			
		}
		
	endif;
