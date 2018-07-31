<?php
	
	header('Content-Type: application/json');

	$post = file_get_contents( 'php://input' );
	$post = json_decode($post,true);

	
	function escapeJsonString($value) {
		$escapers = array("\\", "/", "\"", "\n", "\r", "\t", "\x08", "\x0c");
		$replacements = array("\\\\", "\\/", "\\\"", "\\n", "\\r", "\\t", "\\f", "\\b");
		$result = str_replace($escapers, $replacements, $value);
		return $result;
	}
	
	//Check the request and session variables for the date and time values. 
	$searchterm = $post['request']['intent']['slots']['title']['value'];
	if(empty($searchterm)){
		$searchterm = $post['session']['attributes']['searchterm'];
	}
	
	$mms = $post['request']['intent']['slots']['mms']['value'];
	if(empty($mms)){
		$mms = $post['session']['attributes']['mms'];
	}
	
	$campusid = $post['request']['intent']['slots']['campusid']['value'];
	if(empty($campusid)){
		$campusid = $post['session']['attributes']['campusid'];
	}
	
	
	if(empty($searchterm) && empty($mms)){
		//First prompt. 
		echo'{
				"version": "1.0",
				"response": {
					"outputSpeech": {
							"type": "PlainText","text": "What book are you looking for within the J. Murrey Atkins Library? For example: Say: Search for Harry Potter."
					},
					"shouldEndSession": false
				}
			}'; 
	}
	elseif(!empty($searchterm) && empty($mms) && empty($campusid)){
		$ch = curl_init();
		$url = "https://api-na.hosted.exlibrisgroup.com/primo/v1/search?q=any,contains,".urlencode($searchterm)."&lang=eng&sort=rank&offset=0&limit=10&tab=LibraryCatalog&vid={INST_CODE_HERE}:{INST_CODE_HERE}&scope=MyInstitution&qInclude=facet_rtype,exact,books&apikey=API-Key-HERE";
		curl_setopt($ch, CURLOPT_URL, $url . $queryParams);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		$response = curl_exec($ch);
		curl_close($ch);
		
		//Turn JSON into array.
		$book = json_decode($response,true);
		
		//Get top level json parts of interest.
		$book_display_info = $book['docs'][0]['pnx']['display'];
		$book_delivery_info = $book['docs'][0]['delivery']['holding'][0];
		$book_adddata_info = $book['docs'][0]['pnx']['addata'];
		
		//Pull out the fields that we want. 
		$author = $book_display_info['creator'][0];
		$year = $book_display_info['creationdate'][0];
		$subject = $book_display_info['subject'][0];
		$language = $book_display_info['language'][0];
		$title = $book_display_info['title'][0];
		$material_type = $book_display_info['type'][0];
		$publisher = $book_display_info['publisher'][0];
		$availability = $book_delivery_info['availabilityStatus'];
		$book_desc = $book_display_info['description'][0];
		$mms = $book_display_info['mms'][0];
		$shelving_location = $book_delivery_info['subLocation'];
		$floor_only = explode("--",$shelving_location)[1];
		$floor_only = (empty($floor_only) ? $shelving_location : $floor_only);
		$callnumber = $book_delivery_info['callNumber'];
		$cover = "https://secure.syndetics.com/index.php?isbn=".str_replace("-","",$book_adddata_info['isbn'][0])."/lc.gif&client={INST_CODE_HERE}";
		
		//Build the text for the card. 
		$description = "Title: $title\n\nYear: $year\nShelving Location: $shelving_location\nCall Number: $callnumber\nAvailability: $availability\nSubject: $subject";
		
		
		//Debugging -- not used in prod.
		file_put_contents("output/info.txt",$response);
		
		
		//Response based on availability.
		if($availability=="available"){
			$responseText = '"I found '.$searchterm.'. It is located on the '.$floor_only.'. Would you like to place it on hold?"';
			$sessionResponse = '"shouldEndSession": false';
		}
		else{
			$responseText = '"I found '.$searchterm.'. It is normally located on the '.$floor_only.', but it is currently unavailable."';
			$sessionResponse = '"shouldEndSession": true';
		}
		
		
		//Show response...
		echo'{
				"version": "1.0",
				"response": {
					"outputSpeech": {
							"type": "PlainText","text": '.$responseText.'
					},
					"card": {
					  "type": "Standard",
					  "title": "'.escapeJsonString($title).'",
					  "text": "'.escapeJsonString($description).'",
					  "image": {
						"smallImageUrl": "'.$cover.'",
						"largeImageUrl": "'.$cover.'"
					  }
					},
					'.$sessionResponse.'
				},
				"sessionAttributes": {
					"searchterm": "'.$searchterm.'",
					"mms": "'.$mms.'"
				}
			}'; 
			
			
	}
	elseif(!empty($searchterm) && !empty($mms) && empty($campusid)){
		
		//They want to place a hold, let's get their ID. 
		echo'{
				"version": "1.0",
				"response": {
					"outputSpeech": {
							"type": "PlainText","text": "What is your campus ID number? You can find this on the bottom right of your ID card. Please say your ID digit by digit. For example, 8 0 0 1 2 3 4 5 6"
					},
					"shouldEndSession": false
				},
				"sessionAttributes": {
					"searchterm": "'.$searchterm.'",
					"mms": "'.$mms.'"
				}
			}'; 

	
	}
	elseif(!empty($searchterm) && !empty($mms) && !empty($campusid)){
		
		//They want to place a hold and we already have their ID. Place the hold. 
		
		$ch = curl_init();
		$url = 'https://api-eu.hosted.exlibrisgroup.com/almaws/v1/bibs/{mms_id}/requests';
		$templateParamNames = array('{mms_id}');
		$templateParamValues = array(urlencode($mms));
		$url = str_replace($templateParamNames, $templateParamValues, $url);
		$queryParams = '?' . urlencode('user_id') . '=' .
		urlencode($campusid) . '&' . urlencode('user_id_type') . '=' .
		urlencode('all_unique') . '&' . urlencode('apikey') . '=' .
		urlencode('API-Key-HERE');
		curl_setopt($ch, CURLOPT_URL, $url . $queryParams);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_POSTFIELDS,'{"request_type": "HOLD","pickup_location": "{INST_CODE_HERE}", "pickup_location_type": "LIBRARY","pickup_location_library": "{INST_CODE_HERE}", "comment": "This hold is created by the Atkins Search Alexa Skill."}');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		$response = curl_exec($ch);
		curl_close($ch);

		//var_dump($response);
		
		//Debugging -- not used in prod.
		file_put_contents("output/info_hold.txt",$response);
		
	
		
		
		echo'{
				"version": "1.0",
				"response": {
					"outputSpeech": {
							"type": "PlainText","text": "Your hold has been placed. You will recieve an email when your item is available for pickup on the hold shelf. Thank you for using Atkins Library."
					},
					"shouldEndSession": true
				},
				"sessionAttributes": {
					"searchterm": "'.$searchterm.'",
					"mms": "'.$mms.'",
					"campusid": "'.$campusid.'"
				}
			}'; 
	
	}
