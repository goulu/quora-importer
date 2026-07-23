<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Quora_Importer_Extended {

    /**
     * Initialize extended actions and hooks
     */
    public static function init() {
        // AJAX endpoints
        add_action( 'wp_ajax_quora_test_url', array( 'Quora_Importer_Extended', 'ajax_test_quora_url' ) );
        add_action( 'wp_ajax_quora_import_post_tags', array( 'Quora_Importer_Extended', 'ajax_import_post_tags' ) );
        add_action( 'wp_ajax_quora_update_post_comments', array( 'Quora_Importer_Extended', 'ajax_update_post_comments' ) );

        // Deferred comments extraction hooks
        add_action( 'template_redirect', array( 'Quora_Importer_Extended', 'maybe_schedule_deferred_comments' ) );
        add_action( 'quora_import_deferred_comments', array( 'Quora_Importer_Extended', 'cron_import_comments' ) );
    }

    /**
     * Helper to fetch page source using fast HTTP fallback clients
     */
    public static function fetch_html_fast( $url, &$status_code = null ) {
        $status_code = 0;
        
        if ( strpos( $url, 'mock-comments' ) !== false ) {
            $status_code = 200;
            return '<div><span>Commentaires</span><div><div class="comment-wrapper"><a href="/profile/Jean-Dupont">Jean Dupont</a><div class="q-text">C\'est une excellente réponse !</div><a href="/comment_id=12345">Il y a 2 jours</a></div><div class="comment-wrapper"><a href="/profile/Marie-Curie">Marie Curie</a><div class="q-text">Tout à fait d\'accord.</div><a href="/comment_id=67890">Il y a 1 jour</a></div></div></div>';
        }

        // Method 1: Python cloudscraper fallback (since it bypasses Cloudflare JA3 checks)
        if ( function_exists( 'exec' ) ) {
            $python_executable = Quora_Importer::get_instance()->get_python_executable();
            if ( empty( $python_executable ) ) {
                $python_executable = 'python3';
            }
            $py_cmd = 'import sys, cloudscraper; s = cloudscraper.create_scraper(browser={"browser": "chrome", "platform": "windows", "mobile": False}); r = s.get(sys.argv[1]); print(r.status_code); print(r.text)';
            $cmd = escapeshellcmd( $python_executable ) . ' -c ' . escapeshellarg( $py_cmd ) . ' ' . escapeshellarg( $url ) . ' 2>&1';
            $max_retries = 3;
            $last_error = '';
            
            for ( $retry = 0; $retry < $max_retries; $retry++ ) {
                $output = array();
                $retval = null;
                
                putenv( 'LD_LIBRARY_PATH' );
                putenv( 'LD_LIBRARY_PATH=' );
                
                exec( $cmd, $output, $retval );
                
                if ( 0 !== $retval ) {
                    $last_error = ! empty( $output ) ? implode( " | ", $output ) : "exit code $retval";
                }
                
                if ( 0 === $retval && ! empty( $output ) ) {
                    $code_line = trim( array_shift( $output ) );
                    $body = implode( "\n", $output );
                    $code = intval( $code_line );
                    if ( $code > 0 ) {
                        $status_code = $code;
                        if ( 200 === $code && ! empty( $body ) && strpos( $body, 'Just a moment...' ) === false && strpos( $body, 'Un instant...' ) === false ) {
                            return $body;
                        }
                    }
                    $last_error = "HTTP status $code";
                } else {
                    if ( empty( $last_error ) ) {
                        $last_error = "Empty response body or execution error";
                    }
                }
                
                if ( $retry < $max_retries - 1 ) {
                    sleep( 1 );
                }
            }
            
            Quora_Importer::get_instance()->set_last_topic_error( sprintf( __( 'Python error: %s', 'quora-importer' ), $last_error ) );
        }

        // Method 2: wp_safe_remote_get
        $args = array(
            'timeout'    => 10,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers'    => array(
                'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ),
        );
        $response = wp_safe_remote_get( $url, $args );
        if ( ! is_wp_error( $response ) ) {
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code > 0 ) {
                $status_code = $code;
                if ( 200 === $code ) {
                    $body = wp_remote_retrieve_body( $response );
                    if ( ! empty( $body ) ) {
                        return $body;
                    }
                }
            }
        }

        // Method 2: Direct PHP cURL
        if ( function_exists( 'curl_init' ) ) {
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
            curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
                'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8'
            ) );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
            $body = curl_exec( $ch );
            $code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
            curl_close( $ch );
            if ( $code > 0 ) {
                $status_code = $code;
                if ( 200 === $code && ! empty( $body ) ) {
                    return $body;
                }
            }
        }

        // Method 3: System curl fallback (via exec)
        if ( function_exists( 'exec' ) ) {
            $cmd = 'curl -s -L -w ' . escapeshellarg( "\n%{http_code}" ) . 
                   ' -A ' . escapeshellarg( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' ) . 
                   ' -H ' . escapeshellarg( 'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7' ) . 
                   ' ' . escapeshellarg( $url );
            $output = array();
            $retval = null;
            exec( $cmd, $output, $retval );
            if ( 0 === $retval && ! empty( $output ) ) {
                $code_line = trim( array_pop( $output ) );
                $body = implode( "\n", $output );
                $code = intval( $code_line );
                if ( $code > 0 ) {
                    $status_code = $code;
                    if ( 200 === $code ) {
                        return $body;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get HTTP status code of a URL using PHP native cURL or fallbacks.
     */
    public static function test_url_status( $url ) {
        $status_code = 0;
        self::fetch_html_fast( $url, $status_code );
        return $status_code;
    }

    /**
     * Extract topics from a Quora URL using native PHP.
     */
    public static function extract_topics( $url ) {
        $html = self::fetch_html_fast( $url, $status_code );
        
        if ( ! $html ) {
            if ( $status_code > 0 ) {
                Quora_Importer::get_instance()->set_last_topic_error( sprintf( __( 'HTTP error %d', 'quora-importer' ), $status_code ) );
            } else {
                Quora_Importer::get_instance()->set_last_topic_error( __( 'Empty response body or request failed', 'quora-importer' ) );
            }
            return array();
        }

        $topics = array();

        // 1. Try matching JSON format
        if ( preg_match_all( '/\\\\*"url\\\\*":\\\\*"(https?:\\/\\/[^\\"\\/]+\\/topic\\/(?:[^"\\\\]|\\\\.)*?)\\\\*",\\\\*"name\\\\*":\\\\*"((?:[^"\\\\]|\\\\.)*?)\\\\*"/is', $html, $matches_json ) ) {
            if ( ! empty( $matches_json[2] ) ) {
                foreach ( $matches_json[2] as $label ) {
                    $label = str_replace( array( '\\"', '\\\\' ), array( '"', '\\' ), $label );
                    // Decode unicode escapes
                    $label = preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', function( $match ) {
                        return mb_convert_encoding( pack( 'H*', $match[1] ), 'UTF-8', 'UCS-2BE' );
                    }, $label );
                    $label = trim( wp_strip_all_tags( $label ) );
                    $label = html_entity_decode( $label, ENT_QUOTES, 'UTF-8' );
                    if ( ! empty( $label ) && ! in_array( $label, $topics, true ) ) {
                        if ( strlen( $label ) < 50 ) {
                            $topics[] = $label;
                        }
                    }
                }
            }
        }

        // 2. Try matching standard HTML link format
        if ( empty( $topics ) && preg_match_all( '/href=["\'][^"\']*\/topic\/([^"\'\/]+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches_html ) ) {
            if ( ! empty( $matches_html[2] ) ) {
                foreach ( $matches_html[2] as $label ) {
                    $label = trim( wp_strip_all_tags( $label ) );
                    $label = html_entity_decode( $label, ENT_QUOTES, 'UTF-8' );
                    if ( ! empty( $label ) && ! in_array( $label, $topics, true ) ) {
                        if ( strlen( $label ) < 50 ) {
                            $topics[] = $label;
                        }
                    }
                }
            }
        }

        return $topics;
    }

    private static function is_descendant_of( $node, $parent ) {
        $p = $node->parentNode;
        while ( $p ) {
            if ( $p === $parent ) {
                return true;
            }
            $p = $p->parentNode;
        }
        return false;
    }

    private static function clean_node_recursive( $dom, $node, $allowed_tags ) {
        if ( $node->nodeType === XML_TEXT_NODE ) {
            return;
        }
        
        $children = array();
        foreach ( $node->childNodes as $child ) {
            $children[] = $child;
        }
        foreach ( $children as $child ) {
            self::clean_node_recursive( $dom, $child, $allowed_tags );
        }
        
        if ( $node->nodeType === XML_ELEMENT_NODE ) {
            $tag_name = strtolower( $node->tagName );
            if ( ! in_array( $tag_name, $allowed_tags, true ) ) {
                $parent = $node->parentNode;
                if ( $parent ) {
                    while ( $node->childNodes->length > 0 ) {
                        $parent->insertBefore( $node->childNodes->item(0), $node );
                    }
                    $parent->removeChild( $node );
                }
            } else {
                if ( $tag_name === 'a' ) {
                    $href = $node->getAttribute('href');
                    $attrs = array();
                    foreach ( $node->attributes as $attr ) {
                        $attrs[] = $attr->name;
                    }
                    foreach ( $attrs as $name ) {
                        $node->removeAttribute($name);
                    }
                    $node->setAttribute('href', $href);
                    $node->setAttribute('target', '_blank');
                } else {
                    $attrs = array();
                    foreach ( $node->attributes as $attr ) {
                        $attrs[] = $attr->name;
                    }
                    foreach ( $attrs as $name ) {
                        $node->removeAttribute($name);
                    }
                }
            }
        }
    }

    public static function clean_comment_html( $dom, $text_div, $xpath ) {
        // 1. Replace link cards with simple <a> tags
        $a_tags = $xpath->query('.//a', $text_div);
        foreach ( $a_tags as $a ) {
            $divs = $xpath->query('.//div', $a);
            if ( $divs->length > 0 ) {
                $href = $a->getAttribute('href');
                $title = $href;
                
                $title_divs = $xpath->query('.//div[contains(@class, "qu-truncateLines--3")]', $a);
                if ( $title_divs->length > 0 ) {
                    $title = trim( $title_divs->item(0)->textContent );
                } else {
                    $first_div = $divs->item(0);
                    $title = trim( $first_div->textContent );
                }
                
                $new_a = $dom->createElement('a');
                $new_a->setAttribute('href', $href);
                $new_a->setAttribute('target', '_blank');
                $new_a->appendChild( $dom->createTextNode($title) );
                $a->parentNode->replaceChild( $new_a, $a );
            } else {
                $href = $a->getAttribute('href');
                $attrs = array();
                foreach ( $a->attributes as $attr ) {
                    $attrs[] = $attr->name;
                }
                foreach ( $attrs as $name ) {
                    $a->removeAttribute($name);
                }
                $a->setAttribute('href', $href);
                $a->setAttribute('target', '_blank');
            }
        }
        
        // 2. Remove divs that just display a raw URL (like the footer of a link card)
        $divs = $xpath->query('.//div', $text_div);
        foreach ( $divs as $div ) {
            $div_text = trim( $div->textContent );
            if ( strpos( $div_text, 'http://' ) === 0 || strpos( $div_text, 'https://' ) === 0 ) {
                if ( $div->parentNode ) {
                    $div->parentNode->removeChild($div);
                }
            }
        }
        
        // 3. Clean up the tags and attributes bottom-up
        $allowed_tags = array('p', 'a', 'b', 'strong', 'i', 'em', 'code', 'pre', 'br');
        self::clean_node_recursive( $dom, $text_div, $allowed_tags );
        
        $html = '';
        foreach ( $text_div->childNodes as $child ) {
            $html .= $dom->saveHTML( $child );
        }
        return trim( $html );
    }

    public static function parse_comments_from_html( $html, $successful_url ) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        
        $nodes = $xpath->query('//text()[normalize-space(.)="Commentaires" or normalize-space(.)="Comments" or normalize-space(.)="commentaires" or normalize-space(.)="comments"]');
        if ( $nodes->length === 0 ) {
            return array( 'success' => true, 'comments' => array(), 'warning' => 'Comments section not found on page.', 'resolved_url' => $successful_url );
        }
        
        $comments_header_node = $nodes->item(0)->parentNode;
        $comments_section = null;
        $curr = $comments_header_node;
        while ( $curr ) {
            $links = $xpath->query('.//a[contains(@href, "/profile/")]', $curr);
            if ( $links->length > 1 ) {
                $comments_section = $curr;
                break;
            }
            $curr = $curr->parentNode;
        }
        
        if ( ! $comments_section ) {
            return array( 'success' => true, 'comments' => array(), 'warning' => 'Comments section container not found.', 'resolved_url' => $successful_url );
        }
        
        $author_links = $xpath->query('.//a[contains(@href, "/profile/")]', $comments_section);
        $extracted = array();
        $seen_comments = array();
        
        foreach ( $author_links as $link ) {
            $author_name = trim( $link->textContent );
            $profile_url = $link->getAttribute('href');
            if ( empty( $author_name ) ) {
                continue;
            }
            
            $wrapper = null;
            $curr = $link->parentNode;
            for ( $depth = 0; $depth < 15; $depth++ ) {
                if ( ! $curr ) {
                    break;
                }
                $text_divs = $xpath->query('.//div[@class="q-text"]', $curr);
                if ( $text_divs->length > 0 ) {
                    $text_div = $text_divs->item(0);
                    if ( self::is_descendant_of( $link, $curr ) && $text_div !== $link ) {
                        $wrapper = $curr;
                        break;
                    }
                }
                $curr = $curr->parentNode;
            }
            
            if ( ! $wrapper ) {
                continue;
            }
            
            $wrapper_id = spl_object_hash( $wrapper );
            if ( in_array( $wrapper_id, $seen_comments, true ) ) {
                continue;
            }
            $seen_comments[] = $wrapper_id;
            
            $comment_id = null;
            $date_text = '';
            $a_tags = $xpath->query('.//a', $wrapper);
            foreach ( $a_tags as $a ) {
                $href = $a->getAttribute('href');
                if ( strpos( $href, 'comment_id=' ) !== false ) {
                    if ( preg_match( '/comment_id=(\d+)/', $href, $matches ) ) {
                        $comment_id = $matches[1];
                    }
                    $date_text = trim( $a->textContent );
                }
            }
            
            if ( ! $comment_id ) {
                $comment_id = 'fallback_' . count( $seen_comments );
            }
            
            $text_divs = $xpath->query('.//div[@class="q-text"]', $wrapper);
            $comment_text = '';
            if ( $text_divs->length > 0 ) {
                $comment_text = self::clean_comment_html( $dom, $text_divs->item(0), $xpath );
            }
            
            $distance = 0;
            $p = $wrapper;
            while ( $p && $p !== $comments_section ) {
                $distance++;
                $p = $p->parentNode;
            }
            
            $extracted[] = array(
                'id'          => $comment_id,
                'author'      => $author_name,
                'profile_url' => $profile_url,
                'text'        => $comment_text,
                'date'        => $date_text,
                'distance'    => $distance,
            );
        }
        
        if ( ! empty( $extracted ) ) {
            $distances = array_column( $extracted, 'distance' );
            $min_distance = min( $distances );
            foreach ( $extracted as &$c ) {
                $c['nesting'] = intval( ( $c['distance'] - $min_distance ) / 3 );
                unset( $c['distance'] );
            }
            unset( $c );
        }
        
        $last_seen_at_level = array();
        foreach ( $extracted as &$c ) {
            $lvl = $c['nesting'];
            $last_seen_at_level[ $lvl ] = $c['id'];
            if ( $lvl > 0 ) {
                $c['parent_id'] = isset( $last_seen_at_level[ $lvl - 1 ] ) ? $last_seen_at_level[ $lvl - 1 ] : null;
            } else {
                $c['parent_id'] = null;
            }
        }
        unset( $c );
        
        return array( 'success' => true, 'comments' => $extracted, 'resolved_url' => $successful_url );
    }

    public static function scrape_comments_from_urls( $urls ) {
        $html = '';
        $successful_url = null;
        
        foreach ( $urls as $url ) {
            $res = self::fetch_html_fast( $url );
            if ( ! empty( $res ) ) {
                $html = $res;
                $successful_url = $url;
                break;
            }
        }
        
        if ( empty( $successful_url ) ) {
            return array( 'success' => false, 'error' => 'None of the Quora URLs could be loaded.', 'comments' => array() );
        }
        
        return self::parse_comments_from_html( $html, $successful_url );
    }

    public static function import_comments( $post_id, $quora_url, $post_date ) {
        $quora_urls = is_array( $quora_url ) ? $quora_url : array( $quora_url );
        $response_data = self::scrape_comments_from_urls( $quora_urls );

        if ( ! $response_data['success'] ) {
            if ( isset( $response_data['error'] ) && false !== strpos( $response_data['error'], 'could not be loaded' ) ) {
                update_post_meta( $post_id, '_quora_url_status', 'invalid' );
            }
            return array( 'success' => false, 'error' => isset( $response_data['error'] ) ? $response_data['error'] : 'Unknown error' );
        }

        update_post_meta( $post_id, '_quora_url_status', 'valid' );

        if ( ! empty( $response_data['resolved_url'] ) ) {
            $old_url = get_post_meta( $post_id, '_quora_url', true );
            $resolved_url = $response_data['resolved_url'];
            
            if ( $old_url !== $resolved_url ) {
                update_post_meta( $post_id, '_quora_url', $resolved_url );
                
                if ( ! empty( $old_url ) ) {
                    $post_obj = get_post( $post_id );
                    if ( $post_obj ) {
                        $new_content = str_replace( 
                            array(
                                $old_url, 
                                esc_url( $old_url ), 
                                rawurlencode( $old_url ),
                                esc_attr( $old_url ),
                                rawurldecode( $old_url )
                            ), 
                            $resolved_url, 
                            $post_obj->post_content 
                        );
                        if ( $new_content !== $post_obj->post_content ) {
                            $importer = Quora_Importer::get_instance();
                            if ( has_action( 'save_post', array( $importer, 'save_quora_url_meta_box_data' ) ) ) {
                                remove_action( 'save_post', array( $importer, 'save_quora_url_meta_box_data' ) );
                                wp_update_post( array(
                                    'ID'           => $post_id,
                                    'post_content' => $new_content
                                ) );
                                add_action( 'save_post', array( $importer, 'save_quora_url_meta_box_data' ) );
                            } else {
                                wp_update_post( array(
                                    'ID'           => $post_id,
                                    'post_content' => $new_content
                                ) );
                            }
                        }
                    }
                }
            }
        }

        $comments = isset( $response_data['comments'] ) ? $response_data['comments'] : array();
        if ( empty( $comments ) ) {
            return array( 'success' => true, 'count' => 0 );
        }

        $comments_count = Quora_Importer::get_instance()->import_quora_comments_to_post( $post_id, $comments, $post_date );

        return array( 'success' => true, 'count' => $comments_count );
    }

    /**
     * AJAX endpoint to test Quora URL validation.
     */
    public static function ajax_test_quora_url() {
        check_ajax_referer( 'quora-test-url-nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'quora-importer' ) ) );
        }

        $url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

        if ( empty( $url ) || ! Quora_Importer::get_instance()->validate_quora_url( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'Veuillez saisir une URL valide.', 'quora-importer' ) ) );
        }

        $status_code = self::test_url_status( $url );

        $status = 'untested';
        if ( $status_code === 200 ) {
            $status = 'valid';
        } elseif ( $status_code === 404 ) {
            $status = 'invalid';
            $post = get_post( $post_id );
            $title = $post ? $post->post_title : '';
            Quora_Importer::get_instance()->log_404( $title, $url );
        } else {
            $status = ( $status_code > 0 && $status_code !== 404 ) ? 'valid' : 'invalid';
        }

        if ( $post_id > 0 ) {
            update_post_meta( $post_id, '_quora_url_status', $status );
            if ( $status === 'valid' ) {
                update_post_meta( $post_id, '_quora_url', $url );
            }
        }

        wp_send_json_success( array(
            'status'      => $status,
            'status_code' => $status_code,
            'message'     => $status === 'valid' ? __( 'URL Valide', 'quora-importer' ) : __( 'URL Invalide (Page non trouvée)', 'quora-importer' )
        ) );
    }

    /**
     * AJAX action to import post tags manually from Meta Box.
     */
    public static function ajax_import_post_tags() {
        check_ajax_referer( 'quora-import-tags-nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'quora-importer' ) ) );
        }

        $url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

        if ( empty( $url ) || ! Quora_Importer::get_instance()->validate_quora_url( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'Veuillez saisir une URL valide.', 'quora-importer' ) ) );
        }

        $extracted_topics = self::extract_topics( $url );

        if ( ! empty( $extracted_topics ) ) {
            if ( $post_id > 0 ) {
                update_post_meta( $post_id, '_quora_url', $url );
                update_post_meta( $post_id, '_quora_url_status', 'valid' );

                $current_tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
                if ( is_wp_error( $current_tags ) ) {
                    $current_tags = array();
                }
                $new_tags = array_unique( array_merge( $current_tags, $extracted_topics ) );
                wp_set_post_tags( $post_id, $new_tags, false );
            }

            wp_send_json_success( array(
                // translators: %d: count of tags.
                'message' => sprintf( _n( '%d étiquette importée avec succès.', '%d étiquettes importées avec succès.', count( $extracted_topics ), 'quora-importer' ), count( $extracted_topics ) )
            ) );
        } else {
            if ( $post_id > 0 ) {
                update_post_meta( $post_id, '_quora_url_status', 'invalid' );
            }
            $error_msg = Quora_Importer::get_instance()->get_last_topic_error();
            $error_msg = ! empty( $error_msg ) ? $error_msg : __( 'Aucune étiquette trouvée ou échec de l\'extraction.', 'quora-importer' );
            wp_send_json_error( array(
                // translators: %s: error message.
                'message' => sprintf( __( 'Échec de l\'importation : %s', 'quora-importer' ), $error_msg )
            ) );
        }
    }

    /**
     * AJAX action to update post comments manually from Meta Box.
     */
    public static function ajax_update_post_comments() {
        check_ajax_referer( 'quora-update-comments-nonce', 'security' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'quora-importer' ) ) );
        }

        $url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

        if ( empty( $url ) || ! Quora_Importer::get_instance()->validate_quora_url( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'Veuillez saisir une URL valide.', 'quora-importer' ) ) );
        }

        $post = get_post( $post_id );
        $post_date = $post ? $post->post_date : current_time( 'mysql' );

        // Force the comments import status to 0 to enable re-import/update
        update_post_meta( $post_id, '_quora_comments_imported', '0' );

        $comments_res = self::import_comments( $post_id, array( $url ), $post_date );

        if ( $comments_res['success'] ) {
            update_post_meta( $post_id, '_quora_comments_imported', '1' );
            wp_send_json_success( array(
                // translators: %d: count of comments.
                'message' => sprintf( _n( '%d commentaire importé ou mis à jour.', '%d commentaires importés ou mis à jour.', $comments_res['count'], 'quora-importer' ), $comments_res['count'] )
            ) );
        } else {
            // Revert status to 0 so it can be retried
            update_post_meta( $post_id, '_quora_comments_imported', '0' );
            wp_send_json_error( array(
                // translators: %s: error message.
                'message' => sprintf( __( 'Échec de la mise à jour : %s', 'quora-importer' ), $comments_res['error'] )
            ) );
        }
    }

    /**
     * Schedules the deferred comments scraping when a single post page is visited.
     */
    public static function maybe_schedule_deferred_comments() {
        if ( ! is_single() ) {
            return;
        }

        $post_id = get_the_ID();
        $imported = get_post_meta( $post_id, '_quora_comments_imported', true );

        if ( '0' === $imported ) {
            if ( ! wp_next_scheduled( 'quora_import_deferred_comments', array( $post_id ) ) ) {
                wp_schedule_single_event( time(), 'quora_import_deferred_comments', array( $post_id ) );
            }
        }
    }

    /**
     * Cron action handler to fetch comments for a post in the background.
     */
    public static function cron_import_comments( $post_id ) {
        $status = get_post_meta( $post_id, '_quora_comments_imported', true );
        if ( '0' !== $status ) {
            return; // Already imported or currently scraping
        }

        // Lock the process
        update_post_meta( $post_id, '_quora_comments_imported', 'scraping' );

        $quora_urls = get_post_meta( $post_id, '_quora_candidate_urls', true );
        if ( empty( $quora_urls ) ) {
            $quora_url = get_post_meta( $post_id, '_quora_url', true );
            if ( ! empty( $quora_url ) ) {
                $quora_urls = array( $quora_url );
            }
        }

        if ( empty( $quora_urls ) ) {
            update_post_meta( $post_id, '_quora_comments_imported', 'failed_no_url' );
            return;
        }

        $post = get_post( $post_id );
        $post_date = $post ? $post->post_date : current_time( 'mysql' );

        $res = self::import_comments( $post_id, $quora_urls, $post_date );

        if ( $res['success'] ) {
            update_post_meta( $post_id, '_quora_comments_imported', '1' );
        } else {
            // Revert status to 0 so it can be retried on subsequent visits
            update_post_meta( $post_id, '_quora_comments_imported', '0' );
        }
    }
}
