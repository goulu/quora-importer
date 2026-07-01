<?php
require_once __DIR__ . '/../../../../wp-load.php';
$importer = Quora_Importer::get_instance();

// We need to access private method using reflection or just copy the logic.
// Let's copy get_candidate_urls logic or use reflection to call it since it's private.

$invalid_ids = array(66, 72, 68, 52, 53);

// Helper function to call private method
function call_private_method($object, $method_name, $args = array()) {
    $reflection = new ReflectionClass(get_class($object));
    $method = $reflection->getMethod($method_name);
    $method->setAccessible(true);
    return $method->invokeArgs($object, $args);
}

foreach ($invalid_ids as $id) {
    $post = get_post($id);
    if (!$post) {
        echo "Post $id not found.\n";
        continue;
    }
    echo "========================================\n";
    echo "ID: $id | Title: '" . $post->post_title . "'\n";
    
    // Reconstruct candidates using the plugin's actual logic
    $title = $post->post_title;
    $title = preg_replace( '/\[\/?math\]/i', '', $title );
    
    $profile_slugs = array( 'Philippe-Guglielmetti', 'Dr-Goulu' );
    $nickname = get_user_meta( $post->post_author, 'nickname', true );
    if ( ! empty( $nickname ) ) {
        $normalized = remove_accents( $nickname );
        $cleaned_name = preg_replace( '/[^A-Za-z0-9_\-\s]/', '', $normalized );
        $ps = str_replace( array( ' ', '_' ), '-', $cleaned_name );
        $ps = preg_replace( '/-+/', '-', $ps );
        if ( ! in_array( $ps, $profile_slugs ) ) {
            array_unshift( $profile_slugs, $ps );
        }
    }
    
    $existing_url = get_post_meta( $post->ID, '_quora_url', true );
    $domain = 'fr.quora.com';
    if ( ! empty( $existing_url ) ) {
        $parsed = wp_parse_url( $existing_url );
        if ( ! empty( $parsed['host'] ) ) {
            $domain = $parsed['host'];
        }
    }
    
    $candidates = array();
    $add_slug_candidates = function( $t ) use ( &$candidates, $domain, $profile_slugs, $importer ) {
        foreach ( array( true, false ) as $replace_apostrophes ) {
            // Call private quora_slugify
            $slug = call_private_method($importer, 'quora_slugify', array($t, $replace_apostrophes));
            if ( empty( $slug ) ) {
                continue;
            }
            
            if ( stripos( $domain, 'quora.com' ) !== false && stripos( $domain, 'fr.quora.com' ) === false && stripos( $domain, 'www.quora.com' ) === false ) {
                $candidates[] = "https://{$domain}/{$slug}";
            } else {
                $candidates[] = "https://{$domain}/{$slug}";
                foreach ( $profile_slugs as $ps ) {
                    $candidates[] = "https://{$domain}/{$slug}/answer/{$ps}";
                }
            }
        }
    };
    
    if ( substr( $title, -3 ) !== '...' ) {
        $add_slug_candidates( $title );
        $punctuation_marks = array( ':', '.', '?', '!', '(' );
        foreach ( $punctuation_marks as $punc ) {
            $pos = strpos( $title, $punc );
            if ( $pos !== false && $pos > 10 ) {
                $truncated_title = trim( substr( $title, 0, $pos ) );
                $add_slug_candidates( $truncated_title );
            }
        }
    }
    
    $title_words = explode( ' ', $title );
    if ( count( $title_words ) > 10 ) {
        foreach ( array( 10, 15, 20, 25, 30 ) as $word_count ) {
            if ( count( $title_words ) >= $word_count ) {
                $prefix_title = implode( ' ', array_slice( $title_words, 0, $word_count ) );
                $add_slug_candidates( $prefix_title );
            }
        }
    }
    
    $content_text = trim( wp_strip_all_tags( $post->post_content ) );
    $content_text = preg_replace( '/\s+/', ' ', $content_text );
    $words = explode( ' ', $content_text );
    if ( count( $words ) > 1 ) {
        foreach ( array( 4, 6, 8, 10, 12, 14 ) as $word_count ) {
            if ( count( $words ) >= $word_count ) {
                $prefix_title = implode( ' ', array_slice( $words, 0, $word_count ) );
                $add_slug_candidates( $prefix_title );
            }
        }
    }
    
    $candidates = array_unique( array_filter( $candidates ) );
    echo "Generated " . count($candidates) . " candidates. Top 15 candidates:\n";
    $i = 0;
    foreach ($candidates as $cand) {
        echo "  - $cand\n";
        $i++;
        if ($i >= 15) break;
    }
}
