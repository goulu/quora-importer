<?php
$json_file = __DIR__ . '/quora_urls.json';
$decoded = json_decode( file_get_contents( $json_file ), true );
$keywords = array('attentat', 'senateur', 'sénateur', 'Boucha', 'teletravailleur', 'tord', 'Adam', 'darwinienne', 'Zoroastre');
foreach ( $decoded as $u ) {
    foreach ($keywords as $kw) {
        if ( stripos( $u, $kw ) !== false ) {
            echo "Found matching URL for keyword '$kw': $u\n";
        }
    }
}
