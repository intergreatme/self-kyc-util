<?php
    /**
    * Payload inspector - A utility for viewing JSON payloads as part of the Self-KYC-Util.
    *
    * @author    James Lawson
    * @copyright 2019 IGM www.intergreatme.com
    * @note      This program is distributed in the hope that it will be useful - WITHOUT
    * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
    * FITNESS FOR A PARTICULAR PURPOSE.
    */
?>
<!DOCTYPE html>
<html>
    <head>
        <title>Payload Inspector | IGM</title>
        <link type="text/css" rel="stylesheet" href="../assets/mini.css/mini-dark.min.css"/>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            pre {
                white-space: pre-wrap; white-space: -moz-pre-wrap; white-space: -pre-wrap; white-space: -o-pre-wrap; word-wrap: break-word; color: gray;
            }
        </style>
    </head>
    <body>
        <form method="post" action="">
            <span>Insert the JSON payload here to decode it</span>
            <textarea name="payload" style="width:99%; height: 150px; font-family:monospace; font-size: 10px;"><?php echo !isset($_POST['clear']) ? isset($_POST['payload']) ? $_POST['payload'] : '' : '' ?></textarea>
            <br />
            <input type="submit" value="Inspect payload" /><input type="submit" name="clear" value="Clear payload"/>
            <hr />
        </form>
        <?php
            // we will write the code here, since it will make sense here for us to test with it
            $db = null;
            $payload = json_decode(json_decode($_POST['payload'], true)['payload'], true);
            switch($_GET['category_type']) {
                case 'completion':
                    if(isset($payload['kyc_result'])) {
                        if($payload['kyc_result']['result'] == 'PASS') {
                            $db['tx_status'] = 'completion_pass';
                        } else {
                            if(!isset($payload['kyc_result']['expired'])) {
                                $db['tx_status'] = 'completion_fail';
                                
                            } else {
                                $db['tx_status'] = 'completion_timeout';
                            }
                        }
                    }
                    break;
                case 'status':
                    if(isset($payload['status'])) {
                        switch($payload['status']) {
                            case 'STARTED':
                                $db['tx_status'] = 'status_started';
                                break;
                            case 'COMPLETE':
                                $db['tx_status'] = 'status_complete';
                                break;
                            case 'TIMEOUT':
                                $db['tx_status'] = 'status_timeout';
                                break;
                            case 'PROFILE':
                                $db['tx_status'] = 'status_profile';
                                break;
                            case 'PASS':
                                if($payalod['document_type'] == 'LIVELINESS') {
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
                            default:
                                $db['tx_status'] = 'status_default';
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
            }
            echo '<pre>'.print_r($db, true).'</pre>';

            if(isset($_POST['payload'])) {
                ob_start();
                // prettify the payload we received
                echo '<pre><h6 style="text-align:center"><span style="color: Red;">P</span><span style="color: Tomato;">r</span><span style="color: DarkOrange;">e</span><span style="color: Orange;">t</span><span style="color: Yellow;">t</span><span style="color: Khaki;">i</span><span style="color: PaleGreen;">f</span><span style="color: SteelBlue;">i</span><span style="color: PowderBlue;">e</span><span style="color: MediumOrchid;">d</span> <span style="color: MediumOrchid;">P</span><span style="color: SteelBlue;">a</span><span style="color: PaleGreen;">y</span><span style="color: Yellow;">l</span><span style="color: Orange;">o</span><span style="color: DarkOrange;">a</span><span style="color: Red;">d</span></h6><hr />';
                if(strpos($_POST['payload'], 'payload') !== false) {
                    $payload = json_decode(json_decode($_POST['payload'], true)['payload'], true);
                    if($payload !== null) {
                        echo '[payload] => ' . print_r($payload, true);
                        $time = json_decode(json_decode($_POST['payload'], true)['timestamp'], true);
                        echo '[timestamp-conversion] => '.strftime('%d-%m-%Y %T', $time / 1000);
                        echo '</pre>'; // close of the <pre> here
                        echo '<pre>Original Payload:'.PHP_EOL.print_r(json_decode($_POST['payload'], true), true).'</pre>';
                    } else {
                        echo 'Either that is not valid JSON, or it could not be decoded.</pre>';
                    }
                } else {
                    echo 'Oh no, that does not look like a valid payload.</pre>';
                    if(!empty($_POST['payload'])) {
                        echo '<pre>Original Payload:'.PHP_EOL.print_r(json_decode($_POST['payload'], true), true).'</pre>';
                    }
                }
                // show the original payload
                
                // end buffering and output
                $buffer = ob_get_contents();
                // we do not want to continue processing after getting the buffer
                ob_end_clean();
                // post-processing
                $to_find = array(
                    '[status] => STARTED',
                    '[status] => COMPLETE',
                    'VALID',
                    'IN<mark style="font-family: monospace;" class="tertiary">VALID</mark>',
                    'REQUIRED',
                    'REQUIRE_SUPPORT',
                    'DELAYED',
                    'DOCUMENT_VERIFIED',
                    'DOCUMENT_NOT_<mark style="font-family: monospace;" class="tertiary">VALID</mark>',
                    'DOCUMENT_<mark style="font-family: monospace;" class="tertiary">VALID</mark>',
                    '[message]',
                    'PASS',
                    'AUTO',
                    'FAIL',
                    'MANUAL',
                );
                // for easy copy-paste: '<mark class="tertiary">status</mark>',
                $to_replace = array(
                    '<mark style="font-family: monospace;">[status] => STARTED</mark>',
                    '<mark style="font-family: monospace;">[status] => COMPLETE</mark>',
                    '<mark style="font-family: monospace;" class="tertiary">VALID</mark>',
                    '<mark style="font-family: monospace;" class="secondary">INVALID</mark>',
                    '<mark style="font-family: monospace;" class="secondary">REQUIRED</mark>',
                    '<mark style="font-family: monospace;" class="secondary">REQUIRE_SUPPORT</mark>',
                    '<mark style="font-family: monospace; color: white; background-color: DarkOrange;">DELAYED</mark>',
                    '<mark style="font-family: monospace;">DOCUMENT_VERIFIED</mark>',
                    '<mark style="font-family: monospace;" class="secondary">DOCUMENT_NOT_VALID</mark>',
                    '<mark style="font-family: monospace;" class="tertiary">DOCUMENT_VALID</mark>',
                    '<mark style="font-family: monospace; color:white; background-color: DarkSlateBlue">[message]</mark>',
                    '<mark style="font-family: monospace;" class="tertiary">PASS</mark>',
                    '<mark style="font-family: monospace; color: white; background-color: DarkOrange;">AUTO</mark>',
                    '<mark style="font-family: monospace;" class="secondary">FAIL</mark>',
                    '<mark style="font-family: monospace; color: white; background-color: tomato;">MANUAL</mark>',
                );
                $buffer = str_replace($to_find, $to_replace, $buffer);
                echo $buffer;
            }
        ?>
    </body>
</html>
