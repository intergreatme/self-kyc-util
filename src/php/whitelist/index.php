<?php
    /**
    * Whitelist creator - A utility end point for handling app-to-server request/response cycle for starting a whitelist
    * transaction on the IGM platform. The sample code demonstrates how an organisation might invoke whitelist transactions
    * from their own infrastructure.
    *
    * @author    James Lawson
    * @copyright 2019 IGM www.intergreatme.com
    * @note      This program is distributed in the hope that it will be useful - WITHOUT
    * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
    * FITNESS FOR A PARTICULAR PURPOSE.
    */

    // server configurations
    date_default_timezone_set('Africa/Johannesburg');
    header('Content-Type: application/json');

    // variables for our sql insert - going to keep these the same 
    $db['errors']           = null;
    $db['http_headers']     = json_encode(getallheaders(), true);
    $db['raw_post_input']   = null;
    $db['tx_id']            = null;
    $db['origin_tx_id']     = null;
    // create the origin_tx_id for this transaction
    if (function_exists('com_create_guid') === true) {
        $db['origin_tx_id'] = trim(com_create_guid(), '{}');
    }
    else {
        $db['origin_tx_id'] = sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }
    // conver to lowercase - this is not mandatory!
    $db['origin_tx_id'] = strtolower($db['origin_tx_id']);
    // get our GET param
    // company is only required for our use case, you can probably remove it
    $db['company'] = isset($_GET['name']) ? $_GET['name'] : null;
    $db['config_id'] = ''; // REQUIRED: Add your config ID here
    $db['category_type'] = 'whitelist';
    $db['tx_status'] = 'whitelist_start';
    // Get the input from the POST
    $object = file_get_contents('php://input');
    // Sanity checks, quick escapes
    if($object == null) {
        $db['errors'] .= 'No data received from HTTP POST. Did you send us anything.';
        WriteToSQLite($db);
        exit;
    }
    // at minimum, we need a config so that we know which key to use.
    if($db['config_id'] == null) {
        $db['errors'] .= 'No config ID provided as GET parameter, unable to access keys for cryptographic functions.';
        WriteToSqlite($db);
        exit;
    }

    // get the payload from the existing object
    $object = json_decode($object, true);
    // we want to add our origin_tx_id to this request now
    $object['payload'] = json_encode(
        array_merge(
            array('origin_tx_id' => $db['origin_tx_id']), 
            json_decode($object['payload'], true)
        )
    );

    $object['timestamp'] = time() * 1000;
    // store this json
    $db['raw_post_input'] = json_encode($object);

    // ENCRYPTION - we need to sign our data to send to the server
    $signature = null;
    $password = ''; // You will need to add the password
    $enc_key_path = '../.encryptionKeys/'; // You might want to change this
    $data = file_get_contents($enc_key_path.'igm_kyc_dev_verify.pfx');
    openssl_pkcs12_read($data, $certs, $password);
    $pri = openssl_pkey_get_private($certs['pkey'], $password);
    $pri_key_details = openssl_pkey_get_details($pri);
    // sign the message
    openssl_sign($object['payload'].$object['timestamp'], $signature, $pri, OPENSSL_ALGO_SHA512);
    $object['signature'] = base64_encode($signature);

    // for debug purposes:
    //echo '<pre style="white-space: pre-wrap; white-space: -moz-pre-wrap; white-space: -pre-wrap; white-space: -o-pre-wrap; word-wrap: break-word; color: gray;">JSON to SEND:'.PHP_EOL.json_encode($object).'</pre>';

    // setup cURL to POST this to the server
    $url = 'https://dev.intergreatme.com/kyc/za/api/integration/whitelist/'.$db['config_id'];
    //echo 'URL: '.$url.PHP_EOL;
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL,$url);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($object));
    curl_setopt($curl, CURLOPT_VERBOSE, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        ));

    $server_output = curl_exec($curl);
    $info = curl_getinfo($curl);

    curl_close ($curl);
    // Further processing ...
    if ($info['http_code'] == 200) {
        $db['tx_id'] = json_decode(json_decode($server_output, true)['payload'], true)['tx_id'];
        echo substr($server_output, 0, strpos($server_output, '","timestamp"')).'"}';
        // echo PHP_EOL.'Here is the payload I received from you: '.PHP_EOL.print_r($object, true);
        WriteToSQLite($db);
    }
    else {
        $db['errors'] = 'The server responded with some kind of error: '.$server_output.'.';
        // echo 'Here is the payload I received from you: '.PHP_EOL.print_r($object, true);
        WriteToSqlite($db);
    }

    /*
    ** WriteToSQLite function
    */
    function WriteToSQLite($db)
    {
        // make necessary modifications
        if($db['raw_post_input'] != null)
        {
            $object = json_decode($db['raw_post_input'], true);
            $object = json_decode($object['payload'], true);
        }
        if($db['errors'] != null)
        {
            $db['errors'] = substr($db['errors'], 0, strripos($db['errors'], '.'));
            $db['errors'] = json_encode(array('errors' => explode('.', $db['errors'])), true);
                // echo out the errors
            echo $db['errors'];
        }
        //$db['openssl_result'] = $db['openssl_result'] == 0 ? 'false' : 'true';
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
