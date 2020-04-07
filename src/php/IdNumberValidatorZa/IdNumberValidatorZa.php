<?php
    /**
	* IdNumberValidatorZa - Converted IGM Java code of our South African ID number validator into a static class.
	*
	* @author    James Lawson
	* @copyright 2019 IGM www.intergreatme.com
	* @note      This program is distributed in the hope that it will be useful - WITHOUT
	* ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
	* FITNESS FOR A PARTICULAR PURPOSE.
	*/

    class IdNumberValidatorZa {
        public static function IsIdNumberValid($rsaIdNumber, $dateOfBirth = null, $gender = null) {

            $rsaIdNumber = trim($rsaIdNumber);
            $rsaIdNumber = str_replace(' ', '', $rsaIdNumber);

            if(IdNumberValidatorZa::doBasicIdValidation($rsaIdNumber)) {
                return false;
            }
            // adjust for future dates by subtracting 100 years, if necessary
            $parsedDateOfBirth = new DateTime(substr($rsaIdNumber, 0, 2).'-'.substr($rsaIdNumber, 2, 2).'-'.substr($rsaIdNumber, 4, 2));
            if($parsedDateOfBirth > new DateTime()) {
                $parsedDateOfBirth->modify('-100 year'); 
            }
            if((new DateTime(substr($dateOfBirth, 0, 2).'-'.substr($dateOfBirth, 2, 2).'-'.substr($dateOfBirth, 4, 2))) != $parsedDateOfBirth) {
                error_log('RSA ID number Date of Birth does not match supplied Date of Birth', 0);
                return false;
            }
            $gender = strtoupper($gender);
            if($gender != null && ($gender == 'F' || $gender == 'M')) {
                if($rsaIdNumber[6] > 4 && $gender == 'F' || $rsaIdNumber[6] < 5 && $gender = 'M') {
                    error_log('RSA ID number gender does not match suplied gender', 0);
                    return false;
                }
            }
            return !IdNumberValidatorZa::checkModulus($rsaIdNumber);
        }

        public static function checkModulus($rsaIdNumber) {
            $idx = 1;
            $accum = 0;
            while($idx < strlen($rsaIdNumber)) {
                $digit = $rsaIdNumber[$idx - 1];
                if($idx % 2 == 0) {
                    $evenMulti = $digit * 2;
                    $accum += ((int)($evenMulti / 10) + ($evenMulti % 10));
                } else {
                    $accum += $digit;
                }
                $idx++;
            }
            $lowDigit = $accum % 10;
            $lastDigit = $rsaIdNumber[strlen($rsaIdNumber) - 1];
            $checkDigit = $lowDigit == 0 ? 0 : 10 - $lowDigit;
            error_log('lastDigit = '.$lastDigit.' check digit: '.$checkDigit);
            if($lastDigit != $checkDigit) {
                error_log('RSA ID number check digit mismatch. Check digit: '.$checkDigit.', Last digit: '.$lastDigit, 0);
                return true;
            }
            return false;
        }

        private static function doBasicIdValidation($rsaIdNumber) {
            if($rsaIdNumber == null || empty($rsaIdNumber)) {
                error_log('No RSA ID number specified', 0);
                return true;
            }

            if(strlen($rsaIdNumber) != 13) {
                error_log('RSA ID number has insufficient characters. Length: {'.strlen($rsaIdNumber).'}', 0);
                return true;
            }
            // rsaIdPosPatHasOnlyNumeric
            $matches = array();
            preg_match('/^[0-9]+?$/', $rsaIdNumber, $matches);
            if(count($matches) == 0) {
                error_log('RSA ID number has non-numeric characters', 0);
                return true;
            }
            // rsaIdNegPatHasOnlyRepeatedNumeric
            $matches = array();
            preg_match('/^1+?$|^2+?$|^3+?$|^4+?$|^5+?$|^6+?$|^7+?$|^8+?$|^9+?$|^0+?$/', $rsaIdNumber, $matches);
            if(count($matches) > 0) {
                error_log('RSA ID number has only a single repeated character', 0);
                return true;
            }
            // rsaIdNegPatHasStartFourZeroes
            $matches = array();
            preg_match('/^0000.*?$/', $rsaIdNumber, $matches);
            if(count($matches) > 0 || substr($rsaIdNumber, 0, 4) == 0000) {
                error_log('RSA ID number starts with four zeroes');
                return true;
            }
            return false;
        }
    }
?>
