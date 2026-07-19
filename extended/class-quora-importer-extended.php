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
     * Extract topics from a Quora URL using Python cloudscraper or fallback.
     */
    public static function extract_topics( $url ) {
        $python_script = QUORA_IMPORTER_PATH . 'extended/scrape-topics.py';
        if ( ! file_exists( $python_script ) ) {
            return array();
        }

        $python_executable = Quora_Importer::get_instance()->get_python_executable();
        $cmd = escapeshellcmd( $python_executable ) . ' ' . escapeshellarg( $python_script ) . ' ' . escapeshellarg( $url );
        $output = array();
        $retval = null;
        exec( $cmd, $output, $retval );

        if ( 0 === $retval && ! empty( $output ) ) {
            $response_data = Quora_Importer::get_instance()->decode_shell_json( $output );
            if ( is_array( $response_data ) && isset( $response_data['success'] ) ) {
                if ( $response_data['success'] ) {
                    return $response_data['topics'];
                } else {
                    Quora_Importer::get_instance()->set_last_topic_error( $response_data['error'] );
                    return array();
                }
            } else {
                $topics = Quora_Importer::get_instance()->decode_shell_json( $output );
                if ( is_array( $topics ) && ! empty( $topics ) ) {
                    return $topics;
                }
            }
        } else {
            // translators: %d: exit code.
            Quora_Importer::get_instance()->set_last_topic_error( sprintf( __( 'Python scraper failed with exit code %d', 'quora-importer' ), $retval ) );
        }

        // Fallback: Set realistic User-Agent and headers
        $args = array(
            'timeout'    => 10,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers'    => array(
                'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ),
        );

        $response = wp_safe_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            Quora_Importer::get_instance()->set_last_topic_error( $response->get_error_message() );
            return array();
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $http_code ) {
            // translators: %d: HTTP code.
            Quora_Importer::get_instance()->set_last_topic_error( sprintf( __( 'HTTP error %d', 'quora-importer' ), $http_code ) );
            return array();
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) {
            Quora_Importer::get_instance()->set_last_topic_error( __( 'Empty response body', 'quora-importer' ) );
            return array();
        }

        $topics = array();

        // 1. Try matching JSON format
        if ( preg_match_all( '/\\\\*"url\\\\*":\\\\*"(https?:\\/\\/[^\\"/]+\\/topic\\/(?:[^\\"\\\]|\\\\.)*?)\\\\*",\\\\*"name\\\\*":\\\\*"((?:[^\\"\\\]|\\\\.)*?)\\\\*"/is', $html, $matches_json ) ) {
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

    /**
     * Get HTTP status code of a URL using python/cloudscraper.
     */
    public static function test_url_status( $url ) {
        $python_executable = Quora_Importer::get_instance()->get_python_executable();
        $py_cmd = 'import sys, cloudscraper; s = cloudscraper.create_scraper(); r = s.get(sys.argv[1], timeout=5); print(r.status_code)';
        $cmd = escapeshellcmd( $python_executable ) . ' -c ' . escapeshellarg( $py_cmd ) . ' ' . escapeshellarg( $url );
        $output = array();
        $retval = 0;
        exec( $cmd, $output, $retval );
        return isset( $output[0] ) ? intval( trim( $output[0] ) ) : 0;
    }

    public static function import_comments( $post_id, $quora_url, $post_date ) {
        $python_script = QUORA_IMPORTER_PATH . 'extended/scrape_comments.py';
        if ( ! file_exists( $python_script ) ) {
            return array( 'success' => false, 'error' => __( 'Comments scraper script not found.', 'quora-importer' ) );
        }

        $python_executable = Quora_Importer::get_instance()->get_python_executable();
        $url_args = '';
        if ( is_array( $quora_url ) ) {
            foreach ( $quora_url as $u ) {
                $url_args .= ' ' . escapeshellarg( $u );
            }
        } else {
            $url_args = ' ' . escapeshellarg( $quora_url );
        }

        $cmd = escapeshellcmd( $python_executable ) . ' ' . escapeshellarg( $python_script ) . $url_args;
        $output = array();
        $retval = null;
        exec( $cmd, $output, $retval );

        if ( 0 !== $retval || empty( $output ) ) {
            return array( 'success' => false, 'error' => __( 'Failed to execute comments scraper.', 'quora-importer' ) );
        }

        $response_data = Quora_Importer::get_instance()->decode_shell_json( $output );
        if ( ! is_array( $response_data ) || ! isset( $response_data['success'] ) ) {
            return array( 'success' => false, 'error' => __( 'Invalid scraper response format.', 'quora-importer' ) );
        }

        if ( ! $response_data['success'] ) {
            if ( false !== strpos( $response_data['error'], 'could not be loaded' ) ) {
                update_post_meta( $post_id, '_quora_url_status', 'invalid' );
            }
            return array( 'success' => false, 'error' => $response_data['error'] );
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
