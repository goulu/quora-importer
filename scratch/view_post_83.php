<?php
require_once __DIR__ . '/../../../../wp-load.php';
require_once __DIR__ . '/../includes/class-quora-importer.php';

function validate_quora_url( $url ) {
    if ( empty( $url ) ) {
        return false;
    }
    if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return true;
    }
    $encoded_url = preg_replace_callback( '/[^\x21-\x7E]/', function( $matches ) {
        return rawurlencode( $matches[0] );
    }, $url );
    return (bool) filter_var( $encoded_url, FILTER_VALIDATE_URL );
}

$url = 'https://reponsesfrequentes.quora.com/Gravi-gravité-gravitation-champ-gravitationnel-graviton';
echo "Standard filter_var: " . (filter_var($url, FILTER_VALIDATE_URL) ? "VALID" : "INVALID") . "\n";
echo "validate_quora_url: " . (validate_quora_url($url) ? "VALID" : "INVALID") . "\n";
