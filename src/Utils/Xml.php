<?php
/**
 * This file is part of the kis-cli package.
 *
 * (c) Ole Loots <ole@monochrom.net>
 *
 * For the full license information, please view the LICENSE
 * file that was distributed with this source code.
 */


/**
 * XML to PHP Array conversion utils. 
 * 
 * This class was generated with the help of ChatGPT. 
 * 
 */

namespace Mono\KisCLI\Utils;

use SimpleXMLElement;

class Xml {

    public static function xmlToArray(string $xmlString) : array {
        // Load the XML string into a SimpleXMLElement object
        $xml = simplexml_load_string($xmlString);
    
        // Convert the SimpleXMLElement object to an array recursively
        $array = self::xmlToArrayRecursive($xml);
    
        return $array;
    }
    
    private static function xmlToArrayRecursive(SimpleXMLElement $xml) : array {
        
        $array = [];
    
        // Iterate through each child element of the current XML node
        foreach ($xml->children() as $key => $child) {
            $childArray = [];
    
            // If the child element has further children, recursively convert it to an array
            if ($child->count() > 0) {
                $childArray = self::xmlToArrayRecursive($child);
            } else {
                // Otherwise, treat the node as a value
                $childArray = (array)$child;
                if (isset($child['type'])) {
                    $childArray['type'] = (string)$child['type'];
                }
                if (isset($child['version'])) {
                    $childArray['version'] = (string)$child['version'];
                }
                if (isset($child['enabled'])) {
                    $childArray['enabled'] = (string)$child['enabled'];
                }
            }
    
            // If the current node already exists in the array, convert it to an array of nodes
            if (array_key_exists($key, $array)) {
                if (!is_array($array[$key]) || !isset($array[$key][0])) {
                    $array[$key] = [$array[$key]];
                }
                $array[$key][] = $childArray;
            } else {
                // Otherwise, add the node directly to the array
                $array[$key] = $childArray;
            }
        }
    
        // Extract attributes of the current XML node and add them to the array
        foreach ($xml->attributes() as $key => $value) {
            $array['@attributes'][$key] = (string)$value;
        }
    
        return $array;
    }
}