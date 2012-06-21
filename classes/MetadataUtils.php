<?php
/**
 * MetadataUtils Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link
 */

/**
 * MetadataUtils Class
 *
 * This class contains a collection of static helper functions for metadata processing
 *
 */
class MetadataUtils
{
    /**
     * Convert ISBN-10 (without dashes) to ISBN-13
     *
     * @param string $isbn
     * @return boolean|string
     * @access public
     */
    static public function isbn10to13($isbn)
    {
        if (!preg_match('{^([0-9]{9})[0-9xX]$}', $isbn, $matches)) {
            # number is not 10 digits
            return false;
        }

        $sum_of_digits = 38 + 3 * ($isbn{0} + $isbn{2} + $isbn{4} + $isbn{6} + $isbn{8}) +
        $isbn{1} + $isbn{3} + $isbn{5} + $isbn{7};

        $check_digit = (10 - ($sum_of_digits % 10)) % 10;

        return '978' . $matches[1] . $check_digit;
    }

    /**
     * Convert coordinates in [EWSN]DDDMMSS format to decimal
     *
     * @param string $value
     * @return number
     * @access public
     */
    static public function coordinateToDecimal($value)
    {
        if ($value === '') {
            return (float)NAN;
        }
        if (preg_match('/^([eEwWnNsS])(\d{3})(\d{2})(\d{2})/', $value, $matches)) {
            $dec = $matches[2] + $matches[3] / 60 + $matches[4] / 3600;
            if (in_array($matches[1], array('w', 'W', 's', 'S'))) {
                return -$dec;
            }
            return $dec;
        }
        return (float)$value;
    }

    /**
     * Create a normalized title key for dedup
     *
     * @param string $title
     * @return string
     * @access public
     */
    static public function createTitleKey($title)
    {
        $words = explode(' ', $title);
        $longWords = 0;
        $key = '';
        $keyLen = 0;
        foreach ($words as $word) {
            $key .= $word;
            $wordLen = mb_strlen($word);
            if ($wordLen > 3) {
                ++$longWords;
            }
            $keyLen += $wordLen; // significant chars
            if ($longWords > 2 || $keyLen > 25) {
                break;
            }
        }
        return MetadataUtils::normalize($key);
    }

    /**
     * Normalize a string for comparison
     *
     * @param string $str
     * @return string
     * @access public
     */
    static public function normalize($str)
    {
        $unwanted_array = array('Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', /*'Ä'=>'A', 'Å'=>'A',*/ 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
			                    'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', /*'Ö'=>'O',*/ 'Ø'=>'O', 'Ù'=>'U',
			                    'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', /*'ä'=>'a', 'å'=>'a',*/ 'æ'=>'a', 'ç'=>'c',
			                    'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
        /*'ö'=>'o',*/ 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ü'=>'u', 'ý'=>'y', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' );
        $str = strtr($str, $unwanted_array);
        $str = utf8_decode($str);
        $str = preg_replace('/[\x00-\x1F]/', '', $str);
        $str = preg_replace('/[\x21-\x2F]/', '', $str);
        $str = preg_replace('/[\x7B-\xC3]/', '', $str);
        $str = preg_replace('/[\xC6-\xD5]/', '', $str);
        $str = preg_replace('/[\xD7-\xE3]/', '', $str);
        $str = preg_replace('/[\xE6-\xF5]/', '', $str);
        $str = preg_replace('/[\xF7-\xFF]/', '', $str);

        $str = str_replace('  ', ' ', $str);
        $str = strtolower(trim($str));
        return utf8_encode($str);
    }

    /**
     * Try to match two authors with at least last name and initial letter of first name
     *
     * @param string $a1  LastName FirstName
     * @param string $a2  LastName FirstName
     * @return bool
     * @access public
     */
    static public function authorMatch($a1, $a2)
    {
        if ($a1 == $a2) {
            return true;
        }
        $a1l = mb_strlen($a1);
        $a2l = mb_strlen($a2);
        if ($a1l < 6 || $a2l < 6) {
            return false;
        }

        if (strncmp($a1, $a2, min($a1l, $a2l)) === 0) {
            return true;
        }

        $a1a = explode(' ', $a1);
        $a2a = explode(' ', $a2);

        for ($i = 0; $i < min(count($a1a), count($a2a)); $i++) {
            if ($a1a[$i] != $a2a[$i]) {
                // First word needs to match
                if ($i == 0) {
                    return false;
                }
                // Otherwise at least the initial letter must match
                if (substr($a1a[$i], 0, 1) != substr($a2a[$i], 0, 1)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Check whether the string contains trailing punctuation characters
     *
     * @param string $str
     * @return string
     */
    static public function hasTrailingPunctuation($str)
    {
        return preg_match('/[\/:;\,=\(]+\s*$/', $str);
    }

    /**
     * Strip trailing spaces and punctuation characters from a string
     *
     * @param string $str
     * @return string
     */
    static public function stripTrailingPunctuation($str)
    {
        $str = preg_replace('/[\s\/:;\,=\(]+$/', '', $str);
        return $str;
    }
    
    /**
     * Case-insensitive array_unique
     * 
     * @param array $array
     * @return array
     */
    static public function array_iunique($array) 
    {
        return array_intersect_key($array,
            array_unique(array_map('mb_strtolower', $array)));
    } 
    
    /**
     * Try to find the important numeric part from a record ID to sort by 
     * 
     * @param  string $id
     * @return string sort key
     */
    static public function createIdSortKey($id) 
    {
        if (preg_match('/(\d+)$/', $id, $matches)) {
            return $matches[1];
        }
        return $id;
    }
    
    /**
     * Validate a date in ISO8601 format.
     *
     * @param  string $date
     */
    function validateISO8601Date($date)
    {
    	if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/', $date, $parts) == true) {
    		$time = gmmktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
    
    		$input_time = strtotime($date);
    		if ($input_time === false) return false;
    
    		return $input_time == $time;
    	} else {
    		return false;
    	}
    }
    
    /**
     * Map: resolve the given key using the supplied mapping table
     *
     * @param string $table
     * @param string $key
     * @access public
     */
    public function map($table, $key, $lowercase = true) {
      // Note: "global $mappings" didn't work for some reason so used $GLOBALS
      $mappings = $GLOBALS['mappings'];
      if(isset($mappings[$table]))
        return $mappings[$table][$lowercase ? strtolower($key): $key];
      return false;
    }
}
