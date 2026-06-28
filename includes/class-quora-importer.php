<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Quora_Importer {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action( 'admin_init', array( $this, 'register_wordpress_importer' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_assets' ) );
        
        // AJAX endpoints
        add_action( 'wp_ajax_quora_upload_file', array( $this, 'ajax_upload_file' ) );
        add_action( 'wp_ajax_quora_import_item', array( $this, 'ajax_import_item' ) );
        add_action( 'wp_ajax_quora_import_cleanup', array( $this, 'ajax_import_cleanup' ) );
    }
    
    /**
     * Register the importer in Outils > Importer
     */
    public function register_wordpress_importer() {
        register_importer(
            'quora',
            'Quora',
            __( 'Importer un blog Quora à partir d\'un fichier ZIP ou index.html.', 'quora-importer' ),
            array( $this, 'dispatch' )
        );
    }
    
    /**
     * Enqueue CSS and JS for the importer page
     */
    public function admin_enqueue_assets( $hook ) {
        if ( 'admin.php' === $hook && isset( $_GET['import'] ) && 'quora' === $_GET['import'] ) {
            wp_enqueue_style( 'quora-importer-style', QUORA_IMPORTER_URL . 'assets/css/style.css', array(), QUORA_IMPORTER_VERSION );
            wp_enqueue_script( 'quora-importer-script', QUORA_IMPORTER_URL . 'assets/js/import.js', array( 'jquery' ), QUORA_IMPORTER_VERSION, true );
            
            wp_localize_script( 'quora-importer-script', 'quoraImporter', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'quora-import-nonce' ),
                'strings'  => array(
                    'error_upload'   => __( 'Erreur lors du téléversement du fichier.', 'quora-importer' ),
                    'error_parse'    => __( 'Erreur lors de l\'analyse du fichier.', 'quora-importer' ),
                    'importing'      => __( 'Importation en cours...', 'quora-importer' ),
                    'completed'      => __( 'Importation terminée avec succès !', 'quora-importer' ),
                    'uploading'      => __( 'Téléversement en cours...', 'quora-importer' ),
                    'processing_zip' => __( 'Décompression de l\'archive et analyse du contenu...', 'quora-importer' ),
                )
            ) );
        }
    }
    
    /**
     * Main route dispatcher for the importer page
     */
    public function dispatch() {
        $this->render_header();
        $this->render_body();
        $this->render_footer();
    }
    
    private function render_header() {
        ?>
        <div class="wrap quora-importer-wrap">
            <header class="quora-importer-header">
                <div class="quora-logo-badge">Q</div>
                <h1><?php _e( 'Importateur de Blog Quora', 'quora-importer' ); ?></h1>
                <p class="description"><?php _e( 'Migrez facilement vos réponses, brouillons et publications d\'espace Quora vers WordPress.', 'quora-importer' ); ?></p>
            </header>
            
            <div class="quora-importer-card" id="quora-importer-container">
        <?php
    }
    
    private function render_footer() {
        ?>
            </div> <!-- .quora-importer-card -->
            <footer class="quora-importer-footer-note">
                <p>Quora Importer Plugin &bull; Version <?php echo QUORA_IMPORTER_VERSION; ?> &bull; Par Philippe Guglielmetti & Antigravity</p>
            </footer>
        </div> <!-- .wrap -->
        <?php
    }
    
    private function render_body() {
        ?>
        <!-- STEP 1: UPLOAD SCREEN -->
        <div id="quora-step-upload" class="quora-step active">
            <div class="quora-dropzone" id="quora-dropzone">
                <div class="quora-dropzone-inner">
                    <span class="dashicons dashicons-upload quora-upload-icon"></span>
                    <h3><?php _e( 'Téléversez votre fichier d\'exportation', 'quora-importer' ); ?></h3>
                    <p><?php _e( 'Glissez-déposez votre fichier ZIP d\'exportation Quora (ou votre fichier index.html) ici, ou cliquez pour parcourir.', 'quora-importer' ); ?></p>
                    <button class="button button-primary button-hero quora-browse-btn" type="button"><?php _e( 'Sélectionner le fichier', 'quora-importer' ); ?></button>
                    <input type="file" id="quora-file-input" accept=".zip,.html" style="display: none;" />
                </div>
            </div>
            
            <div class="quora-help-card">
                <h4><span class="dashicons dashicons-info"></span> <?php _e( 'Comment obtenir votre fichier d\'exportation Quora ?', 'quora-importer' ); ?></h4>
                <ol>
                    <li><?php _e( 'Connectez-vous à Quora et allez dans vos <strong>Paramètres de compte</strong>.', 'quora-importer' ); ?></li>
                    <li><?php _e( 'Sous l\'onglet <strong>Vie privée (Privacy)</strong>, cliquez sur <strong>Télécharger vos données (Request Archive)</strong>.', 'quora-importer' ); ?></li>
                    <li><?php _e( 'Vous recevrez un e-mail de Quora contenant un lien pour télécharger votre archive ZIP.', 'quora-importer' ); ?></li>
                    <li><?php _e( 'Téléversez cette archive ZIP directement ici pour préserver toutes vos réponses et vos images !', 'quora-importer' ); ?></li>
                </ol>
            </div>
        </div>
        
        <!-- LOADING SPINNER / STATUS -->
        <div id="quora-step-loading" class="quora-step">
            <div class="quora-loading-spinner">
                <div class="quora-spinner-ring"></div>
                <h3 id="quora-loading-message"><?php _e( 'Téléversement en cours...', 'quora-importer' ); ?></h3>
                <p id="quora-loading-subtext"><?php _e( 'Veuillez patienter pendant le traitement du fichier.', 'quora-importer' ); ?></p>
            </div>
        </div>

        <!-- STEP 2: CONFIGURATION OPTIONS SCREEN -->
        <div id="quora-step-options" class="quora-step">
            <h3 class="step-title"><?php _e( 'Configuration de l\'importation', 'quora-importer' ); ?></h3>
            <p class="step-intro" id="quora-import-stats-summary"></p>
            
            <form id="quora-import-options-form">
                <input type="hidden" id="quora-session-id" name="session_id" value="" />
                
                <div class="quora-form-section">
                    <h4><?php _e( '1. Paramètres des articles', 'quora-importer' ); ?></h4>
                    <div class="quora-form-row">
                        <label for="quora-post-author"><?php _e( 'Auteur des articles importés :', 'quora-importer' ); ?></label>
                        <select name="author_id" id="quora-post-author">
                            <?php 
                            $users = get_users();
                            $current_user_id = get_current_user_id();
                            foreach ( $users as $user ) {
                                $nickname = get_user_meta( $user->ID, 'nickname', true );
                                $display_text = ! empty( $nickname ) ? $nickname : $user->display_name;
                                ?>
                                <option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $user->ID, $current_user_id ); ?>>
                                    <?php echo esc_html( $display_text ); ?>
                                </option>
                                <?php
                            }
                            ?>
                        </select>
                    </div>
                    <div class="quora-form-row">
                        <label for="quora-min-chars-publish"><?php _e( 'Publier si le texte fait plus de :', 'quora-importer' ); ?></label>
                        <div>
                            <input type="number" id="quora-min-chars-publish" name="min_chars_publish" value="500" min="0" />
                            <span class="help-desc" style="display: block; margin-top: 4px;"><?php _e( 'caractères (les originaux publiés plus courts, ou brouillons, seront importés en Brouillon).', 'quora-importer' ); ?></span>
                        </div>
                    </div>
                    
                    <div class="quora-form-row">
                        <label for="quora-link-position"><?php _e( 'Lien vers Quora :', 'quora-importer' ); ?></label>
                        <select name="link_position" id="quora-link-position">
                            <option value="none" selected><?php _e( 'Non', 'quora-importer' ); ?></option>
                            <option value="top"><?php _e( 'En haut de l\'article', 'quora-importer' ); ?></option>
                            <option value="bottom"><?php _e( 'En bas de l\'article', 'quora-importer' ); ?></option>
                        </select>
                    </div>
                    
                    <div class="quora-form-row" id="quora-link-template-row" style="display: none;">
                        <label for="quora-link-template"><?php _e( 'Format du lien (HTML) :', 'quora-importer' ); ?></label>
                        <input type="text" id="quora-link-template" name="link_template" value='<a href="$link$" target="_blank">voir sur Quora </a >' style="width: 100%; max-width: 400px;" />
                    </div>
                </div>
                
                <div class="quora-form-section">
                    <h4><?php _e( '2. Types de contenu à importer', 'quora-importer' ); ?></h4>
                    <div id="quora-content-types-checkboxes" class="quora-checkbox-grid">
                        <!-- Filled dynamically by JavaScript -->
                    </div>
                </div>
                
                <div class="quora-form-section">
                    <h4><?php _e( '3. Médias et Images', 'quora-importer' ); ?></h4>
                    <div class="quora-form-row checkbox-row">
                        <input type="checkbox" name="import_images" id="quora-import-images" value="1" checked />
                        <label for="quora-import-images">
                            <strong><?php _e( 'Téléverser les images dans la bibliothèque WordPress', 'quora-importer' ); ?></strong>
                            <span class="help-desc"><?php _e( 'Extrait les images locales de l\'archive ZIP et les insère proprement dans vos médias.', 'quora-importer' ); ?></span>
                        </label>
                    </div>
                    
                    <div class="quora-form-row checkbox-row">
                        <input type="checkbox" name="set_featured" id="quora-set-featured" value="1" checked />
                        <label for="quora-set-featured">
                            <strong><?php _e( 'Définir la première image comme image mise en avant', 'quora-importer' ); ?></strong>
                            <span class="help-desc"><?php _e( 'Assigne automatiquement la première image trouvée dans l\'article comme vignette.', 'quora-importer' ); ?></span>
                        </label>
                    </div>
                </div>
                
                <div class="quora-form-actions">
                    <button type="button" class="button button-secondary button-large" id="quora-cancel-to-upload"><?php _e( 'Annuler', 'quora-importer' ); ?></button>
                    <button type="submit" class="button button-primary button-large" id="quora-submit-import"><?php _e( 'Lancer l\'importation', 'quora-importer' ); ?></button>
                </div>
            </form>
        </div>
        
        <!-- STEP 3: PROGRESS SCREEN -->
        <div id="quora-step-progress" class="quora-step">
            <h3 class="step-title" id="quora-progress-title"><?php _e( 'Importation en cours...', 'quora-importer' ); ?></h3>
            
            <!-- Running status elements -->
            <div id="quora-progress-running-section">
                <div class="quora-progress-wrapper">
                    <div class="quora-progress-bar-container">
                        <div class="quora-progress-bar" id="quora-import-progress-bar" style="width: 0%;"></div>
                    </div>
                    <div class="quora-progress-stats">
                        <span id="quora-progress-percentage">0%</span>
                        <span id="quora-progress-fraction">0 / 0</span>
                    </div>
                </div>
            </div>
            
            <!-- Summary status elements (shown when finished) -->
            <div id="quora-progress-finished-section" style="display: none;">
                <div class="quora-summary-success-icon" style="margin: 10px 0 20px 0; text-align: center;">
                    <span class="dashicons dashicons-saved" style="font-size: 60px; width: 60px; height: 60px;"></span>
                </div>
                <p class="step-intro" style="text-align: center; margin-bottom: 20px;"><?php _e( 'Votre blog Quora a été importé avec succès. Voici le résumé :', 'quora-importer' ); ?></p>
                
                <div class="quora-summary-stats-grid" style="margin-bottom: 25px;">
                    <div class="summary-stat-card">
                        <span class="stat-num" id="summary-stat-imported">0</span>
                        <span class="stat-label"><?php _e( 'Articles importés', 'quora-importer' ); ?></span>
                    </div>
                    <div class="summary-stat-card">
                        <span class="stat-num" id="summary-stat-skipped">0</span>
                        <span class="stat-label"><?php _e( 'Articles ignorés', 'quora-importer' ); ?></span>
                    </div>
                    <div class="summary-stat-card">
                        <span class="stat-num" id="summary-stat-images">0</span>
                        <span class="stat-label"><?php _e( 'Images téléversées', 'quora-importer' ); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="quora-console-log-header">
                <span><?php _e( 'Journal d\'importation', 'quora-importer' ); ?></span>
                <span class="status-indicator live pulsing" id="quora-log-status-indicator"><?php _e( 'En direct', 'quora-importer' ); ?></span>
            </div>
            <div class="quora-console-log" id="quora-console-log">
                <!-- Dynamically populated by AJAX logs -->
            </div>
            
            <div class="quora-form-actions" id="quora-progress-actions">
                <button type="button" class="button button-link-delete" id="quora-abort-import"><?php _e( 'Arrêter l\'importation', 'quora-importer' ); ?></button>
            </div>
            
            <div class="quora-form-actions" id="quora-finished-actions" style="display: none; justify-content: center; gap: 15px; margin-top: 20px;">
                <a href="<?php echo admin_url( 'edit.php' ); ?>" class="button button-primary button-large"><?php _e( 'Voir tous les articles', 'quora-importer' ); ?></a>
                <a href="<?php echo admin_url( 'admin.php?import=quora' ); ?>" class="button button-secondary button-large"><?php _e( 'Importer un autre fichier', 'quora-importer' ); ?></a>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX action to upload and extract ZIP / HTML
     */
    public function ajax_upload_file() {
        check_ajax_referer( 'quora-import-nonce', 'nonce' );
        
        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permissions insuffisantes.', 'quora-importer' ) ) );
        }
        
        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Aucun fichier reçu.', 'quora-importer' ) ) );
        }
        
        $file = $_FILES['file'];
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        
        if ( ! in_array( $ext, array( 'zip', 'html' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Extension de fichier invalide. Utilisez .zip ou .html.', 'quora-importer' ) ) );
        }
        
        // Setup temp folder inside WordPress uploads
        $upload_dir = wp_upload_dir();
        $temp_root = $upload_dir['basedir'] . '/quora-importer-temp';
        
        if ( ! file_exists( $temp_root ) ) {
            wp_mkdir_p( $temp_root );
            // Security: add index.php
            file_put_contents( $temp_root . '/index.php', '<?php // Silence is golden' );
        }
        
        $session_id = uniqid( 'session_' );
        $session_dir = $temp_root . '/' . $session_id;
        
        if ( ! wp_mkdir_p( $session_dir ) ) {
            wp_send_json_error( array( 'message' => __( 'Impossible de créer le dossier temporaire.', 'quora-importer' ) ) );
        }
        
        // Move uploaded file to session dir
        $uploaded_file_path = $session_dir . '/' . basename( $file['name'] );
        if ( ! move_uploaded_file( $file['tmp_name'], $uploaded_file_path ) ) {
            wp_send_json_error( array( 'message' => __( 'Échec du déplacement du fichier téléversé.', 'quora-importer' ) ) );
        }
        
        $has_images = false;
        $extracted_dir = $session_dir;
        $index_html_path = '';
        
        if ( 'zip' === $ext ) {
            // Unzip the file
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
            
            $unzip_result = unzip_file( $uploaded_file_path, $session_dir );
            
            // Delete the zip to save space
            @unlink( $uploaded_file_path );
            
            if ( is_wp_error( $unzip_result ) ) {
                $this->recursive_rmdir( $session_dir );
                wp_send_json_error( array( 'message' => __( 'Échec de la décompression ZIP : ', 'quora-importer' ) . $unzip_result->get_error_message() ) );
            }
            
            // Search for index.html recursively in extracted folder
            $index_html_path = $this->find_file_recursive( $session_dir, 'index.html' );
            if ( $index_html_path ) {
                $extracted_dir = dirname( $index_html_path );
                if ( file_exists( $extracted_dir . '/images' ) && is_dir( $extracted_dir . '/images' ) ) {
                    $has_images = true;
                }
            } else {
                $this->recursive_rmdir( $session_dir );
                wp_send_json_error( array( 'message' => __( 'Impossible de trouver le fichier index.html dans le fichier ZIP.', 'quora-importer' ) ) );
            }
        } else {
            $index_html_path = $uploaded_file_path;
        }
        
        // Parse the index.html
        $posts = $this->parse_html_file( $index_html_path );
        if ( false === $posts || empty( $posts ) ) {
            $this->recursive_rmdir( $session_dir );
            wp_send_json_error( array( 'message' => __( 'Aucun article éligible n\'a été trouvé dans le fichier index.html.', 'quora-importer' ) ) );
        }
        
        // Get statistics
        $types_count = array();
        foreach ( $posts as $post ) {
            $type = $post['type'];
            if ( ! isset( $types_count[$type] ) ) {
                $types_count[$type] = 0;
            }
            $types_count[$type]++;
        }
        
        // Try to guess default author name from folder
        $guessed_author = $this->extract_author_from_folder( basename( $extracted_dir ) );
        
        // Save manifest JSON
        $manifest = array(
            'session_id'    => $session_id,
            'extracted_dir' => $extracted_dir,
            'has_images'    => $has_images,
            'posts'         => $posts
        );
        
        file_put_contents( $session_dir . '/manifest.json', wp_json_encode( $manifest ) );
        
        wp_send_json_success( array(
            'session_id'     => $session_id,
            'total_posts'    => count( $posts ),
            'post_types'     => $types_count,
            'guessed_author' => $guessed_author,
            'has_images'     => $has_images
        ) );
    }
    
    /**
     * AJAX action to import a single post item by index
     */
    public function ajax_import_item() {
        check_ajax_referer( 'quora-import-nonce', 'nonce' );
        
        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permissions insuffisantes.', 'quora-importer' ) ) );
        }
        
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] ) : '';
        $item_index = isset( $_POST['item_index'] ) ? intval( $_POST['item_index'] ) : -1;
        $author_id = isset( $_POST['author_id'] ) ? intval( $_POST['author_id'] ) : get_current_user_id();
        $min_chars_publish = isset( $_POST['min_chars_publish'] ) ? intval( $_POST['min_chars_publish'] ) : 500;
        $import_images = ! empty( $_POST['import_images'] );
        $set_featured = ! empty( $_POST['set_featured'] );
        $link_position = isset( $_POST['link_position'] ) ? sanitize_text_field( $_POST['link_position'] ) : 'none';
        $link_template = isset( $_POST['link_template'] ) ? wp_unslash( $_POST['link_template'] ) : '';
        
        $enabled_types = isset( $_POST['enabled_types'] ) ? array_map( 'sanitize_text_field', $_POST['enabled_types'] ) : array();
        
        if ( empty( $session_id ) || $item_index < 0 ) {
            wp_send_json_error( array( 'message' => __( 'Paramètres d\'importation invalides.', 'quora-importer' ) ) );
        }
        
        $upload_dir = wp_upload_dir();
        $session_dir = $upload_dir['basedir'] . '/quora-importer-temp/' . $session_id;
        $manifest_file = $session_dir . '/manifest.json';
        
        if ( ! file_exists( $manifest_file ) ) {
            wp_send_json_error( array( 'message' => __( 'Session d\'importation expirée ou introuvable.', 'quora-importer' ) ) );
        }
        
        $manifest = json_decode( file_get_contents( $manifest_file ), true );
        if ( ! isset( $manifest['posts'][$item_index] ) ) {
            wp_send_json_error( array( 'message' => __( 'Article introuvable dans la session.', 'quora-importer' ) ) );
        }
        
        $post = $manifest['posts'][$item_index];
        $type = $post['type'];
        
        // Check if type is enabled
        if ( ! in_array( $type, $enabled_types ) ) {
            wp_send_json_success( array(
                'status'  => 'skipped',
                'title'   => $this->get_post_title( $post, $type ),
                'message' => sprintf( __( 'Type de post "%s" ignoré selon vos filtres.', 'quora-importer' ), $type )
            ) );
        }
        
        // Prepare title
        $title = $this->get_post_title( $post, $type );
        $title = preg_replace( '/\[\/?math\]/i', '$', $title );
        
        // Check if post already exists (avoid duplicates)
        $existing = get_page_by_title( $title, OBJECT, 'post' );
        if ( $existing ) {
            wp_send_json_success( array(
                'status'  => 'skipped',
                'title'   => $title,
                'message' => __( 'Cet article semble déjà exister dans WordPress.', 'quora-importer' )
            ) );
        }
        
        // Date parsing
        $raw_date = ! empty( $post['Creation time'] ) ? $post['Creation time'] : '';
        $timestamp = $this->parse_quora_date( $raw_date );
        
        $post_date = date( 'Y-m-d H:i:s', $timestamp );
        $post_date_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
        
        // Clean URL redirects in HTML
        $content = ! empty( $post['Content'] ) ? $post['Content'] : '';
        $content = preg_replace( '/\[\/?math\]/i', '$', $content );
        $content = $this->process_html_links( $content );
        
        // Set post status based on condition
        $status = 'draft'; // Default to draft
        $is_original_draft = ( strpos( strtolower( $type ), 'brouillon' ) !== false || strpos( strtolower( $type ), 'draft' ) !== false );
        
        if ( ! $is_original_draft ) {
            $plain_text = wp_strip_all_tags( $content );
            $char_count = mb_strlen( trim( $plain_text ), 'UTF-8' );
            if ( $char_count > $min_chars_publish ) {
                $status = 'publish';
            }
        }
        
        // Sideload images if requested
        $images_imported = 0;
        $featured_image_id = 0;
        
        if ( $import_images && $manifest['has_images'] ) {
            $sideload_result = $this->sideload_content_images( $content, $manifest['extracted_dir'], $set_featured, $post_date );
            $content = $sideload_result['content'];
            $images_imported = $sideload_result['count'];
            $featured_image_id = $sideload_result['featured_id'];
        }
        
        // Add link to Quora if requested
        if ( $link_position !== 'none' && ! empty( $link_template ) ) {
            $quora_url = $this->generate_quora_url( $post, $manifest['extracted_dir'], $author_id );
            if ( ! empty( $quora_url ) ) {
                $link_html = str_replace( '$link$', $quora_url, $link_template );
                if ( $link_position === 'top' ) {
                    $content = $link_html . "\n\n" . $content;
                } elseif ( $link_position === 'bottom' ) {
                    $content = $content . "\n\n" . $link_html;
                }
            }
        }
        
        // Build post data
        $post_data = array(
            'post_title'     => wp_slash( $title ),
            'post_content'   => wp_slash( $content ),
            'post_status'    => $status,
            'post_author'    => $author_id,
            'post_date'      => $post_date,
            'post_date_gmt'  => $post_date_gmt,
            'post_type'      => 'post',
        );
        
        $post_id = wp_insert_post( $post_data );
        
        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => sprintf( __( 'Échec de création du post : %s', 'quora-importer' ), $post_id->get_error_message() ) ) );
        }
        
        // Associate attachments with the post
        if ( ! empty( $sideload_result['attachment_ids'] ) ) {
            foreach ( $sideload_result['attachment_ids'] as $attach_id ) {
                wp_update_post( array(
                    'ID'          => $attach_id,
                    'post_parent' => $post_id
                ) );
            }
        }
        
        // Add categories & tags
        $categories = array();
        $tags = array();
        
        // Map Categories based on type
        if ( $type === 'Répondre' || $type === 'Answer' ) {
            $categories[] = $this->get_or_create_term( 'Quora Answers', 'category' );
        } elseif ( $type === 'Brouillon de réponse' || $type === 'Answer Draft' ) {
            $categories[] = $this->get_or_create_term( 'Quora Drafts', 'category' );
        } elseif ( in_array( $type, array( "Envoi d'espace", "Élément d'espace", 'Space post', 'Space share' ) ) ) {
            $categories[] = $this->get_or_create_term( 'Quora Space Posts', 'category' );
        } else {
            $categories[] = $this->get_or_create_term( 'Quora Export', 'category' );
        }
        
        // Category for Space Name
        if ( ! empty( $post['Space name'] ) ) {
            $categories[] = $this->get_or_create_term( $post['Space name'], 'category' );
        }
        
        // Tag for language
        if ( ! empty( $post['Content language'] ) ) {
            $tags[] = $post['Content language'];
        }
        
        $tags[] = 'Quora';
        
        wp_set_post_categories( $post_id, $categories );
        wp_set_post_tags( $post_id, $tags, true );
        
        // Set featured image
        if ( $featured_image_id > 0 ) {
            set_post_thumbnail( $post_id, $featured_image_id );
        } else {
            $default_image_id = $this->get_default_quora_image_id();
            if ( $default_image_id > 0 ) {
                set_post_thumbnail( $post_id, $default_image_id );
            }
        }
        
        wp_send_json_success( array(
            'status'          => 'imported',
            'title'           => $title,
            'post_id'         => $post_id,
            'images_imported' => $images_imported,
            'message'         => sprintf( __( 'Article importé avec succès (ID: %d, images: %d).', 'quora-importer' ), $post_id, $images_imported )
        ) );
    }
    
    /**
     * AJAX action to clean up temporary unzipped files
     */
    public function ajax_import_cleanup() {
        check_ajax_referer( 'quora-import-nonce', 'nonce' );
        
        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permissions insuffisantes.', 'quora-importer' ) ) );
        }
        
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] ) : '';
        
        if ( empty( $session_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Session d\'importation invalide.', 'quora-importer' ) ) );
        }
        
        $upload_dir = wp_upload_dir();
        $session_dir = $upload_dir['basedir'] . '/quora-importer-temp/' . $session_id;
        
        if ( file_exists( $session_dir ) ) {
            $this->recursive_rmdir( $session_dir );
            wp_send_json_success( array( 'message' => __( 'Nettoyage terminé avec succès.', 'quora-importer' ) ) );
        } else {
            wp_send_json_success( array( 'message' => __( 'Aucun dossier à nettoyer.', 'quora-importer' ) ) );
        }
    }
    
    /* ==========================================================================
       HELPERS & PARSING LOGIC
       ========================================================================== */
    
    /**
     * Recursively find a file with a specific name in a folder
     */
    private function find_file_recursive( $dir, $filename ) {
        $it = new RecursiveDirectoryIterator( $dir );
        $display = new RecursiveIteratorIterator( $it );
        foreach ( $display as $file ) {
            if ( $file->getFilename() === $filename ) {
                return $file->getPathname();
            }
        }
        return false;
    }
    
    /**
     * Parses the Quora index.html and returns structured post array
     */
    public function parse_html_file( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }
        
        $html = file_get_contents( $file_path );
        
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        
        // Force UTF-8 encoding
        $dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>' );
        libxml_clear_errors();
        
        $h2s = $dom->getElementsByTagName( 'h2' );
        $posts = array();
        
        $target_types = array(
            'Répondre', 'Brouillon de réponse', "Envoi d'espace", "Élément d'espace", 'Brouillon de publication',
            'Answer', 'Answer Draft', 'Space post', 'Space share', 'Post Draft', 'Draft'
        );
        
        foreach ( $h2s as $h2 ) {
            $type = trim( $h2->textContent );
            
            $is_target = false;
            foreach ( $target_types as $target ) {
                if ( strcasecmp( $type, $target ) === 0 || html_entity_decode( $type ) === $target ) {
                    $is_target = true;
                    break;
                }
            }
            
            if ( ! $is_target ) {
                continue;
            }
            
            $post_data = array(
                'type'             => $type,
                'Question'         => '',
                'Title'            => '',
                'Content'          => '',
                'Creation time'    => '',
                'Content language' => '',
                'Space name'       => '',
            );
            
            $curr = $h2->nextSibling;
            while ( $curr && $curr->nodeName !== 'h2' ) {
                if ( $curr->nodeName === 'div' ) {
                    $strongs = $curr->getElementsByTagName( 'strong' );
                    if ( $strongs->length > 0 ) {
                        $strong = $strongs->item( 0 );
                        $label = trim( rtrim( trim( $strong->textContent ), ':' ) );
                        
                        if ( $label === 'Post content' ) {
                            $label = 'Content';
                        }
                        if ( $label === 'Post title' || $label === 'Title' ) {
                            $label = 'Title';
                        }
                        if ( $label === 'Last updated' || $label === 'Time' || $label === 'Creation time' ) {
                            $label = 'Creation time';
                        }
                        
                        $spans = $curr->getElementsByTagName( 'span' );
                        if ( $spans->length > 0 ) {
                            $span = $spans->item( 0 );
                            if ( $label === 'Content' ) {
                                $value = '';
                                foreach ( $span->childNodes as $child ) {
                                    $value .= $dom->saveHTML( $child );
                                }
                            } else {
                                $value = trim( $span->textContent );
                            }
                        } else {
                            $value = trim( str_replace( $strong->textContent, '', $curr->textContent ) );
                        }
                        $post_data[$label] = $value;
                    }
                }
                $curr = $curr->nextSibling;
            }
            
            $posts[] = $post_data;
        }
        
        return $posts;
    }
    
    /**
     * Get or generate a clean title for the post
     */
    private function get_post_title( $post, $type ) {
        foreach ( array( 'Question', 'Title' ) as $key ) {
            if ( ! empty( $post[$key] ) ) {
                $title = trim( $post[$key] );
                $title = preg_replace( '/\s+/', ' ', $title );
                return $title;
            }
        }
        
        if ( ! empty( $post['Content'] ) ) {
            $content = $post['Content'];
            
            $doc = new DOMDocument();
            libxml_use_internal_errors( true );
            $doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>' );
            libxml_clear_errors();
            
            $anchors = $doc->getElementsByTagName( 'a' );
            if ( $anchors->length > 0 ) {
                $first_a = $anchors->item( 0 );
                $anchor_text = trim( $first_a->textContent );
                if ( ! empty( $anchor_text ) ) {
                    if ( strpos( $content, $doc->saveHTML( $first_a ) ) < 50 ) {
                        return $anchor_text;
                    }
                }
            }
            
            $text = trim( strip_tags( $content ) );
            $text = preg_replace( '/\s+/', ' ', $text );
            if ( mb_strlen( $text ) > 80 ) {
                return mb_substr( $text, 0, 80 ) . '...';
            }
            if ( ! empty( $text ) ) {
                return $text;
            }
        }
        
        return 'Untitled ' . $type;
    }
    
    /**
     * Parse Quora export date string into a Unix timestamp
     */
    private function parse_quora_date( $date_str ) {
        $date_str = trim( $date_str );
        if ( empty( $date_str ) ) {
            return time();
        }
        
        $date_str = preg_replace( '/\s+/', ' ', $date_str );
        $timestamp = strtotime( $date_str );
        
        if ( ! $timestamp ) {
            // Fallback for custom layouts
            return time();
        }
        
        return $timestamp;
    }
    
    /**
     * Clean Quora redirect wrappers
     */
    private function process_html_links( $content ) {
        if ( empty( $content ) ) {
            return '';
        }
        
        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>' );
        libxml_clear_errors();
        
        $anchors = $doc->getElementsByTagName( 'a' );
        $changed = false;
        
        foreach ( $anchors as $a ) {
            $href = $a->getAttribute( 'href' );
            if ( ! empty( $href ) && strpos( $href, 'quora.com/_/redirect' ) !== false ) {
                $query = parse_url( $href, PHP_URL_QUERY );
                if ( $query ) {
                    $params = array();
                    parse_str( $query, $params );
                    if ( ! empty( $params['url'] ) ) {
                        $clean_url = urldecode( $params['url'] );
                        $a->setAttribute( 'href', $clean_url );
                        $changed = true;
                    }
                }
            }
        }
        
        if ( $changed ) {
            // Return only body contents to prevent html wrapper tags
            $body = $doc->getElementsByTagName( 'body' )->item( 0 );
            $new_content = '';
            foreach ( $body->childNodes as $child ) {
                $new_content .= $doc->saveHTML( $child );
            }
            return $new_content;
        }
        
        return $content;
    }
    
    /**
     * Sideload images from content html and replace local sources with WordPress URLs
     */
    private function sideload_content_images( $content, $extracted_dir, $set_featured, $post_date ) {
        $result = array(
            'content'        => $content,
            'count'          => 0,
            'featured_id'    => 0,
            'attachment_ids' => array()
        );
        
        if ( empty( $content ) ) {
            return $result;
        }
        
        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>' );
        libxml_clear_errors();
        
        $imgs = $doc->getElementsByTagName( 'img' );
        if ( $imgs->length === 0 ) {
            return $result;
        }
        
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        
        $images_folder = $extracted_dir . '/images/';
        $count = 0;
        $featured_id = 0;
        $changed = false;
        
        // Cache to prevent duplicate uploads in the same post/session
        static $session_images_cache = array();
        
        foreach ( $imgs as $img ) {
            $src = $img->getAttribute( 'src' );
            if ( empty( $src ) ) {
                continue;
            }
            
            // Extract file name
            $filename = basename( $src );
            
            // Check if it's a local reference (starts with images/ or contains qimg-)
            $is_local = false;
            $local_path = '';
            
            if ( strpos( $src, 'images/' ) === 0 ) {
                $is_local = true;
                $local_path = $extracted_dir . '/' . $src;
            } elseif ( strpos( $src, 'qimg-' ) !== false ) {
                $is_local = true;
                $local_path = $images_folder . $filename;
            }
            
            if ( ! $is_local || ! file_exists( $local_path ) ) {
                continue;
            }
            
            $attachment_id = 0;
            $attachment_url = '';
            
            if ( isset( $session_images_cache[$local_path] ) ) {
                $attachment_id = $session_images_cache[$local_path]['id'];
                $attachment_url = $session_images_cache[$local_path]['url'];
            } else {
                // Get correct image extension (since Quora export images have no extension)
                $ext = $this->get_image_extension( $local_path );
                
                // Sideload the image
                // 1. Copy file to temp location because media_handle_sideload moves/deletes it
                $tmp_dir = get_temp_dir();
                $tmp_file = $tmp_dir . uniqid( 'quora_img_' ) . '_' . $filename . $ext;
                
                if ( copy( $local_path, $tmp_file ) ) {
                    $file_array = array(
                        'name'     => $filename . $ext,
                        'tmp_name' => $tmp_file,
                    );
                    
                    // Filter to customize the upload directory using the post date
                    $upload_dir_filter = function( $uploads ) use ( $post_date ) {
                        $timestamp = strtotime( $post_date );
                        if ( $timestamp ) {
                            $year = date( 'Y', $timestamp );
                            $month = date( 'm', $timestamp );
                            $uploads['subdir'] = '/' . $year . '/' . $month;
                            $uploads['path']   = $uploads['basedir'] . $uploads['subdir'];
                            $uploads['url']    = $uploads['baseurl'] . $uploads['subdir'];
                        }
                        return $uploads;
                    };
                    
                    add_filter( 'upload_dir', $upload_dir_filter );
                    
                    // Sideload the attachment (unassociated with post_id first, we assign manually after post is created)
                    $attachment_id = media_handle_sideload( $file_array, 0 );
                    
                    remove_filter( 'upload_dir', $upload_dir_filter );
                    
                    if ( ! is_wp_error( $attachment_id ) ) {
                        // Update the attachment's date in database to match the post date
                        wp_update_post( array(
                            'ID'            => $attachment_id,
                            'post_date'     => $post_date,
                            'post_date_gmt' => get_gmt_from_date( $post_date )
                        ) );
                        
                        $attachment_url = wp_get_attachment_url( $attachment_id );
                        $session_images_cache[$local_path] = array(
                            'id'  => $attachment_id,
                            'url' => $attachment_url
                        );
                        $count++;
                    } else {
                        @unlink( $tmp_file );
                    }
                }
            }
            
            if ( $attachment_id > 0 && ! empty( $attachment_url ) ) {
                $result['attachment_ids'][] = $attachment_id;
                $img->setAttribute( 'src', $attachment_url );
                $changed = true;
                
                // Add wp-image class to help WordPress styling
                $classes = $img->getAttribute( 'class' );
                $classes = trim( $classes . ' aligncenter size-full wp-image-' . $attachment_id );
                $img->setAttribute( 'class', $classes );
                
                // Track first image for featured
                if ( $set_featured && 0 === $featured_id ) {
                    $featured_id = $attachment_id;
                }
            }
        }
        
        if ( $changed ) {
            $body = $doc->getElementsByTagName( 'body' )->item( 0 );
            $new_content = '';
            foreach ( $body->childNodes as $child ) {
                $new_content .= $doc->saveHTML( $child );
            }
            $result['content'] = $new_content;
        }
        
        $result['count'] = $count;
        $result['featured_id'] = $featured_id;
        
        return $result;
    }
    
    /**
     * Detect the correct image file extension based on mime type or file signature.
     */
    private function get_image_extension( $path ) {
        if ( function_exists( 'mime_content_type' ) ) {
            $mime = @mime_content_type( $path );
            if ( $mime ) {
                $map = array(
                    'image/jpeg'    => '.jpg',
                    'image/jpg'     => '.jpg',
                    'image/png'     => '.png',
                    'image/gif'     => '.gif',
                    'image/webp'    => '.webp',
                    'image/svg+xml' => '.svg',
                );
                if ( isset( $map[$mime] ) ) {
                    return $map[$mime];
                }
            }
        }
        
        // Fallback: check file signature (magic numbers)
        $handle = @fopen( $path, 'rb' );
        if ( $handle ) {
            $bytes = fread( $handle, 12 );
            fclose( $handle );
            if ( false !== $bytes ) {
                if ( strpos( $bytes, "\xff\xd8\xff" ) === 0 ) {
                    return '.jpg';
                }
                if ( strpos( $bytes, "\x89PNG\r\n\x1a\n" ) === 0 ) {
                    return '.png';
                }
                if ( strpos( $bytes, 'GIF87a' ) === 0 || strpos( $bytes, 'GIF89a' ) === 0 ) {
                    return '.gif';
                }
                if ( strpos( $bytes, 'RIFF' ) === 0 && strpos( substr( $bytes, 8, 4 ), 'WEBP' ) === 0 ) {
                    return '.webp';
                }
            }
        }
        
        return '.png'; // Default fallback
    }
    
    /**
     * Get or create a term (category/tag) and return its ID
     */
    private function get_or_create_term( $name, $taxonomy ) {
        $term = get_term_by( 'name', $name, $taxonomy );
        if ( $term ) {
            return (int) $term->term_id;
        }
        
        $new_term = wp_insert_term( $name, $taxonomy );
        if ( ! is_wp_error( $new_term ) ) {
            return (int) $new_term['term_id'];
        }
        
        return 0;
    }
    
    /**
     * Helper to extract author name from a folder name (e.g. "Contenu_Philippe_Guglielmetti_11")
     */
    private function extract_author_from_folder( $folder_name ) {
        if ( strpos( $folder_name, 'Contenu_' ) === 0 ) {
            $name = substr( $folder_name, 8 );
            $name = preg_replace( '/_\d+$/', '', $name ); // Strip trailing digits
            $name_parts = explode( '_', $name );
            return implode( ' ', $name_parts );
        }
        return '';
    }
    
    /**
     * Helper to generate the original Quora URL of a post
     */
    private function generate_quora_url( $post, $extracted_dir, $author_id = 0 ) {
        // Base domain based on language
        $lang = ! empty( $post['Content language'] ) ? strtolower( $post['Content language'] ) : 'français';
        $domain = ( strpos( $lang, 'fran' ) !== false ) ? 'fr.quora.com' : 'www.quora.com';
        
        // Author profile name
        $profile_slug = '';
        
        // Try getting nickname from selected WP author
        if ( ! empty( $author_id ) ) {
            $user_data = get_userdata( $author_id );
            if ( $user_data ) {
                $nickname = get_user_meta( $author_id, 'nickname', true );
                if ( empty( $nickname ) ) {
                    $nickname = $user_data->display_name;
                }
                if ( ! empty( $nickname ) ) {
                    $normalized = remove_accents( $nickname );
                    $cleaned_name = preg_replace( '/[^A-Za-z0-9_\-\s]/', '', $normalized );
                    $profile_slug = str_replace( array( ' ', '_' ), '-', $cleaned_name );
                    $profile_slug = preg_replace( '/-+/', '-', $profile_slug );
                }
            }
        }
        
        // Fallback to folder name
        if ( empty( $profile_slug ) ) {
            $folder_name = basename( $extracted_dir );
            if ( strpos( $folder_name, 'Contenu_' ) === 0 ) {
                $name = substr( $folder_name, 8 );
                $name = preg_replace( '/_\d+$/', '', $name ); // Strip trailing digits
                $profile_slug = str_replace( '_', '-', $name );
            }
        }
        
        // Check post type
        $type = ! empty( $post['type'] ) ? $post['type'] : '';
        $is_answer = ( strpos( strtolower( $type ), 'répondre' ) !== false || strpos( strtolower( $type ), 'answer' ) !== false );
        
        // Get question or title
        $title = '';
        if ( ! empty( $post['Question'] ) ) {
            $title = $post['Question'];
        } elseif ( ! empty( $post['Title'] ) ) {
            $title = $post['Title'];
        }
        
        if ( empty( $title ) && ! empty( $post['Content'] ) ) {
            $title = $this->get_post_title( $post, $type );
        }
        
        $title_slug = $this->quora_slugify( $title );
        
        if ( $is_answer && ! empty( $profile_slug ) && ! empty( $title_slug ) ) {
            return "https://{$domain}/{$title_slug}/answer/{$profile_slug}";
        }
        
        // Space posts
        if ( ! empty( $post['Space name'] ) ) {
            $space_slug = sanitize_title( $post['Space name'] );
            if ( ! empty( $title_slug ) ) {
                return "https://{$space_slug}.quora.com/{$title_slug}";
            }
            return "https://{$space_slug}.quora.com";
        }
        
        // General fallback
        if ( ! empty( $title_slug ) ) {
            return "https://{$domain}/{$title_slug}";
        }
        
        return "https://{$domain}";
    }

    /**
     * Reconstruct a Quora-compatible slug that preserves case and accents.
     */
    private function quora_slugify( $title ) {
        if ( empty( $title ) ) {
            return '';
        }
        
        // Strip [math] and [/math] tags if present
        $title = preg_replace( '/\[\/?math\]/i', '', $title );
        
        // Strip everything except letters, numbers, spaces, and hyphens (preserving accents and case)
        $slug = preg_replace( '/[^\p{L}\p{N}\s\-]/u', '', $title );
        
        // Replace spaces/tabs and consecutive hyphens with a single hyphen
        $slug = preg_replace( '/[\s\-]+/u', '-', $slug );
        $slug = trim( $slug, '-' );
        
        // Truncate slug to 190 UTF-8 characters to match Quora answer URLs
        if ( mb_strlen( $slug, 'UTF-8' ) > 190 ) {
            $slug = mb_substr( $slug, 0, 190, 'UTF-8' );
            $last_hyphen = mb_strrpos( $slug, '-', 0, 'UTF-8' );
            if ( $last_hyphen !== false ) {
                $slug = mb_substr( $slug, 0, $last_hyphen, 'UTF-8' );
            }
        }
        
        // URL-encode all accented and special characters while preserving hyphens
        return rawurlencode( $slug );
    }

    /**
     * Get or upload the default Quora image and return its attachment ID.
     */
    private function get_default_quora_image_id() {
        // Check if we already have it cached in an option to avoid DB searches
        $cached_id = get_option( 'quora_importer_default_image_id' );
        if ( $cached_id ) {
            // Verify the attachment still exists
            $post_status = get_post_status( $cached_id );
            if ( $post_status === 'inherit' ) {
                return (int) $cached_id;
            }
        }

        // Check if the attachment already exists in the media library by title
        $query = new WP_Query( array(
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'title'       => 'Quora Default',
            'posts_per_page' => 1,
        ) );

        if ( $query->have_posts() ) {
            $attachment_id = $query->posts[0]->ID;
            update_option( 'quora_importer_default_image_id', $attachment_id );
            return (int) $attachment_id;
        }

        // Upload the image since it does not exist
        $plugin_dir = plugin_dir_path( dirname( __FILE__ ) );
        $image_path = $plugin_dir . 'assets/quora.png';

        if ( ! file_exists( $image_path ) ) {
            return 0;
        }

        // Sideload/copy to upload directory
        $upload_dir = wp_upload_dir();
        $filename = 'quora-logo.png';
        
        // If a file with the same name exists, get a unique filename
        $destination_filename = wp_unique_filename( $upload_dir['path'], $filename );
        $destination = $upload_dir['path'] . '/' . $destination_filename;

        if ( ! copy( $image_path, $destination ) ) {
            return 0;
        }

        $filetype = wp_check_filetype( basename( $destination ), null );

        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => 'Quora Default',
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attachment_id = wp_insert_attachment( $attachment, $destination );

        if ( ! is_wp_error( $attachment_id ) ) {
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            $attach_data = wp_generate_attachment_metadata( $attachment_id, $destination );
            wp_update_attachment_metadata( $attachment_id, $attach_data );
            update_option( 'quora_importer_default_image_id', $attachment_id );
            return (int) $attachment_id;
        }

        return 0;
    }

    /**
     * Helper to delete a folder and all its contents recursively
     */
    private function recursive_rmdir( $dir ) {
        if ( is_dir( $dir ) ) {
            $objects = scandir( $dir );
            foreach ( $objects as $object ) {
                if ( $object !== '.' && $object !== '..' ) {
                    if ( is_dir( $dir . '/' . $object ) && ! is_link( $dir . '/' . $object ) ) {
                        $this->recursive_rmdir( $dir . '/' . $object );
                    } else {
                        @unlink( $dir . '/' . $object );
                    }
                }
            }
            @rmdir( $dir );
        }
    }
}
