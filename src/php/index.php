<?php
	/**
	* Self-KYC-Util - A utility end point for handling responses from IGM. We use this internally in our dev environment
	* to emulate our customers endpoints. You can search for 'Required: ' to add the information you need to configure this script.
	*
	* @author    James Lawson
	* @copyright 2019 IGM www.intergreatme.com
	* @note      This program is distributed in the hope that it will be useful - WITHOUT
	* ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
	* FITNESS FOR A PARTICULAR PURPOSE.
	*/

	date_default_timezone_set('Africa/Johannesburg');
	header('Content-Type: application/json');
	
	// variables for our sql insert
	$db['errors']		= null;
	$db['http_headers'] 	= json_encode(getallheaders(), true);
	$db['raw_post_input'] 	= null;
	$db['openssl_result'] 	= null;
	$db['tx_id'] 		= null;
	$db['origin_tx_id'] 	= null;
	$db['tx_status'] 	= 'error';
	$db['tx_timestamp'] 	= null;
	$db['company'] 		= isset($_GET['name']) ? $_GET['name'] : null; // we use this at IGM to differentiate certain customers
	$db['config_id']	= ""; // Required: add your config ID
	/*
	** IGM has four services that emit data that a customer needs to receive and handle.
	** Feedback -  any feedback messages sent to the customer while they are going through the Self-KYC journey
	** Status - any status messages about server-side processing occurring with the user
	** Validation - when a profile has all of its data in a validated state, but has not yet been verified
	** Completion - when a KYC is complete, the information from the KYC is sent to this endpoint
	** 
	** We can dynamically handle different category types (feedback, status, validation, completion)
	** i.e.: api/?completion will associate the incoming payload to the 'Completion' category
	*/
	$db['category_type'] = !empty($_GET) ? explode('/', array_keys($_GET)[0])[0] : null;
	// We want to exclude other types of categories (or can easily add them in here)
	switch($db['category_type']) {
		case 'completion':
		case 'status':
		case 'feedback': 
		case 'validation':
			break;
		default:
			$db['errors'] .= 'Correct GET parameter missing, URI should be api/?completion, api/?status or api/?feedback.';
			WriteToSql($db);
			exit;
		break;
	}
	// get the POST JSON string
	$data = file_get_contents('php://input');
	if($data == null) {
		$db['errors'] .= 'No data received from HTTP POST.';
	}
	// ensure we have a config ID else we do not know which key to pull from the server. You will get this from IGM.
	if($db['config_id'] == null) {
		$db['errors'] .= 'No Config ID detected, unable to access key.';
	}
	// ensure we have a company, else the INSERT doesn't look nice
	if($db['company'] == null) {
		$db['errors'] .= 'No company detected, please ensure you have the right company parameter.';
	}
	if($db['errors'] != null) {
		WriteToSql($db);
		exit;
	}

	// uncompress stream, assuming it is gz compressed
	if($data != null) {
		if(gzdecode($data) !== null) {
			$data = gzdecode($data);
		}
	}
		
	// Decode data into JSON object
	$object = json_decode($data, true);
	// store this json for th db
	$db['raw_post_input'] = $data;
	// we get a timestamp from the transaction. because services are called async it means that you need to rely on the server time
	// to ensure you get your transactions ordered properly.
	$db['tx_timestamp'] = $object['timestamp'];

	/*
	** Some of the payloads coming through can look similar to one another, as we have tried to standardise the payload JSON.
	** As a result of this, some customers have a difficult time figuring out what transaction status' are being sent to them. 
	** This creates an easy-to-search identification marker.
	*/
	$payload = json_decode(json_decode($data, true)['payload'], true);
	switch($db['category_type']) {
		case 'completion': // when the completion API fires
			if(isset($payload['kyc_result'])) {
				if($payload['kyc_result']['result'] == 'PASS') {
					$db['tx_status'] = 'completion_pass';
				} else {
					if(!isset($payload['kyc_result']['expired'])) {
						$db['tx_status'] = 'completion_fail';
					} else {
						// timeouts are configurable, generally a started transaction will timeout in 7 days
						$db['tx_status'] = 'completion_timeout';
					}
				}
			}
			break;
		case 'status': // when a status update fires
			if(isset($payload['status'])) {
				switch($payload['status']) {
					case 'STARTED': // can be broken into normal status coming through, or that the liveliness status has come through
						if(isset($payload['document_type']) && $payload['document_type'] == 'LIVELINESS') {
							$db['tx_status'] = 'status_liveliness_started';
						} else {
							$db['tx_status'] = 'status_started';
						}
						break;
					case 'COMPLETE': // the status of this profile is complete, please view the Completion API for the result.
									 // Reminder: only the completion API has the KYC result in it, do not use a status update to activate anything on your side
						$db['tx_status'] = 'status_complete';
						break;
					case 'TIMEOUT': // important to note that you can get a status update for a timeout and a completion API for timeout
						$db['tx_status'] = 'status_timeout';
						break;
					case 'PROFILE':
						$db['tx_status'] = 'status_profile';
						break;
					case 'PASS':
						if($payload['document_type'] == 'LIVELINESS') {
							$db['tx_status'] = 'status_liveliness_pass';
						} else {
							$db['tx_status'] = 'status_pass';
						}
						break;
					case 'FAIL':
						if($payload['document_type'] == 'LIVELINESS') {
							$db['tx_status'] = 'status_liveliness_fail';
						} else {
							$db['tx_status'] = 'status_fail';
						}
						break;
					default: // handle any of the other status types
						if(isset($payload['status'])) {
							$db['tx_status'] = 'status_'.strtolower($payload['status']);
						} else {
							$db['tx_status'] = 'status_unknown';
						}
						break;
				}
			} else {
				if(isset($payload['type'])) {
					$db['tx_status'] = 'status_'.strtolower($payload['type']);
				}
			}
		break;
		case 'feedback':
			if(isset($payload['document_type'])) {
				$db['tx_status'] = 'feedback_'.strtolower($payload['document_type']);
			} else {
				$db['tx_status'] = 'feedback_unknown';
			}
			break;
		case 'validation':
			$db['tx_status'] = 'validation';
			break;

	}

	// we need to make use of a key file, so if we don't have it, download it from IGM server
	// Required: change the path to where you want your cache of the key to go.
	$enc_key_path = '../.encryptionKeys/';
    if(!file_exists($enc_key_path.$db['config_id'].'.pem'))
    {
    	$pub_path = 'https://dev.intergreatme.com/kyc/za/api/integration/signkey/'.$db['config_id'];
    	file_put_contents($enc_key_path.$db['config_id'].'.pem', file_get_contents($pub_path));
    }
    // else we have it and can decode the information to retrieve the public key and load its details.
    $pub_json = json_decode(file_get_contents($enc_key_path.$db['config_id'].'.pem'), true);
    $pub = openssl_get_publickey($pub_json['public_key']);
    $pub_key_details = openssl_pkey_get_details($pub);
	// getting the verification signature
	$verification_signature = base64_decode($object['signature']);
	// get the result from the verification
    $db['openssl_result'] = openssl_verify($object['payload'].$object['timestamp'], $verification_signature, $pub, OPENSSL_ALGO_SHA512);
	// if we fail to verify the signature
	if($db['openssl_result'] !== 1)
	{
		$db['errors'] .= 'Unable to verify signature: Openssl said: '.openssl_error_string().'.';
		WriteToSql($db);
		exit;
	}
	// need to use the private key to create a signature in our response to the server
	// you will need to supply your own key here, of which IGM will require your public key
	$signature = null;
	$password = ''; // Required: add the password to unlock your key - get this from IGM
	$data = file_get_contents($enc_key_path.'igm_kyc_dev_verify.pfx'); // the path to your private key
	openssl_pkcs12_read($data, $certs, $password);
	$pri = openssl_pkey_get_private($certs['pkey'], $password);
    $pri_key_details = openssl_pkey_get_details($pri);
	// sign the message
	openssl_sign($object['payload'].$object['timestamp'], $signature, $pri, OPENSSL_ALGO_SHA512);
	// push the signature response to the server
	$sig['signature'] = base64_encode($signature);
	// send it back to the server
	echo json_encode($sig, true);
	// write the transaction to the database
	WriteToSql($db);

	function WriteToSql($db)
	{
		// make necessary modifications
		if($db['raw_post_input'] != null)
		{
			$object = json_decode($db['raw_post_input'], true);
			$object = json_decode($object['payload'], true);
			$db['tx_id'] = $object['tx_id'];
			$db['origin_tx_id'] = $object['origin_tx_id'];
		}
		if($db['errors'] != null)
		{
			$db['errors'] = substr($db['errors'], 0, strripos($db['errors'], '.'));
			$db['errors'] = json_encode(array('errors' => explode('.', $db['errors'])), true);
			echo $db['errors'];
		}
		$db['openssl_result'] = $db['openssl_result'] == 0 ? 'false' : 'true';
		// create connection
		$dbh = new PDO('sqlite:../.database/self-kyc-util.sqlite3');
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$dbh->exec("CREATE TABLE IF NOT EXISTS TRANSACTIONS (
			id INTEGER PRIMARY KEY,
			category_type TEXT,
			company TEXT,
			config_id TEXT,
			origin_tx_id TEXT,
			tx_id TEXT,
			http_headers TEXT, 
			raw_post_input TEXT,
			openssl_result TEXT,
			tx_status TEXT,
			errors TEXT,
			tx_timestamp TEXT,
			time DATETIME DEFAULT CURRENT_TIMESTAMP)");
		// create SQL statement
		// going to do this into a database
		$keys = null; 
		$values = null;
		$sql = 'INSERT INTO TRANSACTIONS (';
		
		foreach($db as $key => $value)
		{
			$keys .= $key.', ';
			$values .= ':'.$key.', ';
		}
		$sql .= substr($keys, 0, strlen($keys) - 2);
		$sql .= ') VALUES (';
		$sql .= substr($values, 0, strlen($values) - 2).')';
		// add record to DB
		$stmt = $dbh->prepare($sql);
		$stmt->execute($db);
	}
?>
