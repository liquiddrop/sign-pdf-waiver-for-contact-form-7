<?php
/**
 * Plugin Name: Sign PDF Waiver for Contact Form 7
 * Plugin URI:  https://wordpress.org/plugins/sign-pdf-waiver-for-contact-form-7/
 * Description: Attach a PDF waiver to CF7. Map fields, capture signature, fill and email the PDF.
 * Version:     1.0.3
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: contact-form-7
 * Author:      Social Good Analytics LLC
 * License:     GPLv2
 * Text Domain:  sign-pdf-waiver-for-contact-form-7
 * Domain Path:  /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CF7W_VERSION', '1.0.3' );
define( 'CF7W_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CF7W_URL',     plugin_dir_url( __FILE__ ) );
define( 'CF7W_DB_VERSION', '1.2' ); // increment this only when schema changes

/**
 * Secure (non-web-accessible) storage for filled PDFs and signatures.
 * Stored under wp-content/uploads/sign-pdf-waiver-for-contact-form-7/ — no public URL exists for this path.
 * Files are served only through the authenticated cf7w_serve_file proxy.
 */
define( 'CF7W_SECURE_DIR', wp_upload_dir()['basedir'] . '/sign-pdf-waiver-for-contact-form-7/' );

// CF7W_DB_TABLE cannot be defined at file-load time because $wpdb may not be
// initialised yet (causes fatal during activation). Use a helper function instead.
function cf7w_db_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'cf7w_logs';
}

// ── Freemius SDK ──────────────────────────────────────────────────────────────
// Must be loaded FIRST — before class files that reference cf7w_fs()
require_once CF7W_DIR . 'includes/freemius.php';
if ( cf7w_fs()->can_use_premium_code__premium_only() ) {
    require_once CF7W_DIR . 'includes/class-premium__premium_only.php';
} // @endif can_use_premium_code__premium_only
require_once CF7W_DIR . 'includes/class-license.php';
require_once CF7W_DIR . 'includes/class-pdf-parser.php';
require_once CF7W_DIR . 'includes/class-pdf-filler.php';
require_once CF7W_DIR . 'includes/class-submission-handler.php';
require_once CF7W_DIR . 'admin/class-admin.php';

// ── File deletion helper ─────────────────────────────────────────────────────
// Wraps WP_Filesystem::delete() so the rest of the plugin never calls unlink()
// directly, satisfying WordPress.WP.AlternativeFunctions.unlink_unlink.
function cf7w_delete_file( string $path ): bool {
    global $wp_filesystem;
    if ( empty( $wp_filesystem ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    if ( $wp_filesystem && $wp_filesystem->exists( $path ) ) {
        return (bool) $wp_filesystem->delete( $path );
    }
    return false;
}

// ── Activation ────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'cf7w_activate' );
function cf7w_activate() {
    cf7w_run_dbdelta();
}

function cf7w_run_dbdelta(): void {
    global $wpdb;
    $table = cf7w_db_table();

    // Check if doc_hash column already exists before trying to add it
    $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'doc_hash'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN doc_hash VARCHAR(64) NOT NULL DEFAULT ''" );
        error_log( 'CF7W: Added doc_hash column to ' . $table );
    } else {
        error_log( 'CF7W: doc_hash column already exists in ' . $table );
    }
	
	// Add cert_pdf column if missing
    $col2 = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'cert_pdf'" );
    if ( empty( $col2 ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN cert_pdf TEXT NOT NULL DEFAULT ''" );
    }

    // Keep dbDelta for fresh installs only
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        form_id     BIGINT(20) UNSIGNED NOT NULL,
        entry_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address  VARCHAR(45) NOT NULL DEFAULT '',
        user_agent  TEXT,
        form_data   LONGTEXT,
        signature   LONGTEXT,
        filled_pdf  TEXT,
        doc_hash    VARCHAR(64) NOT NULL DEFAULT '',
		cert_pdf    TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        KEY form_id (form_id)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ── Boot: runs after all plugins loaded ───────────────────────────────────────
add_action( 'plugins_loaded', 'cf7w_boot' );
function cf7w_boot() {
    if ( ! class_exists( 'WPCF7' ) ) return;
	
	error_log( 'CF7W db_version stored=' . get_option( 'cf7w_db_version', '0' ) . ' plugin=' . CF7W_VERSION );
    // Version-triggered DB upgrade — runs once when version changes.
    // dbDelta() safely adds missing columns without touching existing data.
	if ( get_option( 'cf7w_db_version', '0' ) !== CF7W_DB_VERSION ) {
        cf7w_run_dbdelta();
        update_option( 'cf7w_db_version', CF7W_DB_VERSION );
    }
    cf7w_ensure_secure_dir();
    CF7W_Admin::init();
    CF7W_Submission_Handler::init();
	
	add_filter( 'wpcf7_form_hidden_fields', 'cf7w_add_submission_nonce' );
	
	if ( cf7w_fs()->can_use_premium_code__premium_only() ) {
      	// Verification shortcode — front-end only, no CF7 dependency
		add_shortcode( 'cf7w_verify', 'cf7w_verify_shortcode' );

		// AJAX handlers for the verification form
		add_action( 'wp_ajax_cf7w_verify_document',        'cf7w_ajax_verify_document' );
		add_action( 'wp_ajax_nopriv_cf7w_verify_document', 'cf7w_ajax_verify_document' );
    } // @endif can_use_premium_code__premium_only
}


function cf7w_add_submission_nonce( array $fields ): array {
	$fields['cf7w_submission_nonce'] = wp_create_nonce( 'cf7w_submission' );
	return $fields;
}

/**
 * Create secure storage directories and protective files if missing.
 * Called on activation and on every boot so the folder is always present.
 */
function cf7w_ensure_secure_dir(): void {
$dirs = array(
        CF7W_SECURE_DIR,
        CF7W_SECURE_DIR . 'filled/',
        CF7W_SECURE_DIR . 'signatures/',
        CF7W_SECURE_DIR . 'debug/',
        CF7W_SECURE_DIR . 'certificates/',   // add this if using Issue 1 fix
    );
    foreach ( $dirs as $dir ) {
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
    }

    // Bootstrap WP_Filesystem
    global $wp_filesystem;
    if ( empty( $wp_filesystem ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    $htaccess = CF7W_SECURE_DIR . '.htaccess';
    if ( ! $wp_filesystem->exists( $htaccess ) ) {
        $wp_filesystem->put_contents(
            $htaccess,
            "# CF7 Waiver - deny all direct HTTP access\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
            FS_CHMOD_FILE
        );
    }

    $index = CF7W_SECURE_DIR . 'index.php';
    if ( ! $wp_filesystem->exists( $index ) ) {
        $wp_filesystem->put_contents( $index, '<?php // Silence is golden.', FS_CHMOD_FILE );
    }
}

// ── Secure file-serve proxy ────────────────────────────────────────────────────
// Serves filled PDFs and signatures to logged-in admins only.
// Files live outside the web root — no direct URL exists.
add_action( 'wp_ajax_cf7w_serve_file', 'cf7w_serve_file' );
function cf7w_serve_file() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
    if ( ! check_ajax_referer( 'cf7w_serve_file', 'nonce', false ) ) wp_die( 'Bad request', 400 );

    $rel = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
    if ( ! $rel ) wp_die( 'Missing file parameter', 400 );

    $abs         = realpath( CF7W_SECURE_DIR . $rel );
    $secure_real = realpath( CF7W_SECURE_DIR );
    if ( ! $abs || ! $secure_real || strpos( $abs, $secure_real . DIRECTORY_SEPARATOR ) !== 0 ) {
        wp_die( 'File not found', 404 );
    }
    if ( ! is_file( $abs ) ) wp_die( 'File not found', 404 );

    $ext  = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
    $mime = ( $ext === 'pdf' ) ? 'application/pdf' : ( ( $ext === 'png' ) ? 'image/png' : 'application/octet-stream' );

    header( 'Content-Type: '        . $mime );
    header( 'Content-Length: '      . filesize( $abs ) );
    header( 'Content-Disposition: inline; filename="' . basename( $abs ) . '"' );
    header( 'Cache-Control: private, no-store' );
    header( 'X-Content-Type-Options: nosniff' );

    // Use WP_Filesystem to read the file contents rather than readfile() directly.
    global $wp_filesystem;
    if ( empty( $wp_filesystem ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file output, escaping would corrupt the file
    echo $wp_filesystem->get_contents( $abs );
    exit;
}

// ── Register [cf7w_signature] tag for RENDERING ───────────────────────────────
// wpcf7_init fires on 'init' (before plugins_loaded), so we hook it here at
// file-load time so it is queued before CF7 fires the action on init.
add_action( 'wpcf7_init', 'cf7w_register_signature_tag', 10 );
function cf7w_register_signature_tag() {
    wpcf7_add_form_tag(
        array( 'cf7w_signature', 'cf7w_signature*' ),
        'cf7w_signature_tag_handler',
        array( 'name-attr' => true )
    );
}

// ── Register [cf7w_signature] in the CF7 TAG GENERATOR panel ─────────────────
// wpcf7_admin_init fires on 'admin_init', which IS after plugins_loaded, so we
// can safely nest this inside plugins_loaded — but we register it at top level
// too just to be explicit.
add_action( 'wpcf7_admin_init', 'cf7w_register_tag_generator', 18 );
function cf7w_register_tag_generator() {
    if ( ! class_exists( 'WPCF7_TagGenerator' ) ) return;
    $tg = WPCF7_TagGenerator::get_instance();
    $tg->add(
        'cf7w_signature',
        __( 'Signature', 'sign-pdf-waiver-for-contact-form-7' ),
        'cf7w_tag_generator_panel',
        array( 'version' => '2' )
    );
}

// Renders the tag-generator dialog — matches digital-signature-for-cf7 layout exactly
function cf7w_tag_generator_panel( $contact_form, $args = '' ) {
    $args = wp_parse_args( $args, array() );
    ?>
    <header class="description-box">
        <h3><?php esc_html_e( 'Signature form-tag generator', 'sign-pdf-waiver-for-contact-form-7' ); ?></h3>
        <p><?php esc_html_e( 'Generate a form-tag for a canvas signature field. The drawn signature is captured as a PNG and submitted with the form.', 'sign-pdf-waiver-for-contact-form-7' ); ?></p>
    </header>

    <div class="control-box">

        <fieldset>
            <legend><?php esc_html_e( 'Field type', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <input type="hidden" data-tag-part="basetype" value="cf7w_signature">
            <label>
                <input type="checkbox" data-tag-part="type-suffix" value="*">
                <?php esc_html_e( 'This is a required field.', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </label>
        </fieldset>

        <fieldset>
            <legend><?php esc_html_e( 'Name', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <input type="text" data-tag-part="name" pattern="[A-Za-z][A-Za-z0-9_\-]*" class="oneline">
        </fieldset>

        <fieldset>
            <legend><?php esc_html_e( 'Ink Color', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <input type="color" data-tag-part="option" data-tag-option="color:" value="#000000">
        </fieldset>

        <fieldset>
            <legend><?php esc_html_e( 'Background Color', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <input type="color" data-tag-part="option" data-tag-option="backcolor:" value="#f9f9f9">
        </fieldset>

        <fieldset>
            <legend><?php esc_html_e( 'Width (px)', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <input type="number" data-tag-part="option" data-tag-option="width:" value="400" min="100" max="2000" class="oneline">
        </fieldset>

        <fieldset>
            <legend><?php esc_html_e( 'Height (px)', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <input type="number" data-tag-part="option" data-tag-option="height:" value="160" min="40" max="800" class="oneline">
        </fieldset>
		
		<fieldset>
            <legend><?php esc_html_e( 'E-Sign Consent', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <label>
                <input type="checkbox" data-tag-part="option"
                       data-tag-option="consent:" value="1">
                <?php esc_html_e( 'Show "I agree to sign electronically" checkbox', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </label>
            <div style="margin-top:6px;">
                <label style="display:block;font-size:12px;margin-bottom:3px;">
                    <?php esc_html_e( 'Consent label text (optional):', 'sign-pdf-waiver-for-contact-form-7' ); ?>
                </label>
                <input type="text"
                       data-tag-part="option"
                       data-tag-option="consent_label:"
                       class="oneline"
                       placeholder="<?php esc_attr_e( 'I agree to sign this document electronically.', 'sign-pdf-waiver-for-contact-form-7' ); ?>"
                       style="width:100%;">
            </div>
        </fieldset>

        <fieldset>
            <legend><?php esc_html_e( 'Id attribute', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <input type="text" data-tag-part="option" data-tag-option="id:" class="oneline idvalue">
        </fieldset>

        <fieldset>
            <legend><?php esc_html_e( 'Class attribute', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <input type="text" data-tag-part="option" data-tag-option="class:" class="oneline classvalue" pattern="[A-Za-z0-9_\-\s]*">
        </fieldset>

    </div>

    <div class="insert-box">
        <div class="flex-container">
            <input type="text" class="code" readonly="readonly" onfocus="this.select();" data-tag-part="tag">
            <div class="submitbox">
                <input type="button" class="button button-primary insert-tag" value="<?php esc_attr_e( 'Insert Tag', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
            </div>
        </div>
        <p class="mail-tag-tip">
            <label for="<?php echo esc_attr( $args['content'] . '-mailtag' ); ?>">
                <?php
                // translators: %s is replaced with the mail tag HTML element shown in the CF7 Mail tab.
                echo wp_kses(
                    sprintf(
                        esc_html__( 'To use the value in a mail field, insert the corresponding mail-tag (%s) in the Mail tab.', 'sign-pdf-waiver-for-contact-form-7' ),
                        '<strong><span class="mail-tag"></span></strong>'
                    ),
                    array( 'strong' => array(), 'span' => array() )
                );
                ?>
            </label>
        </p>
    </div>
    <?php
}

// ── [cf7w_signature] HTML renderer ────────────────────────────────────────────
function cf7w_signature_tag_handler( $tag ) {
    if ( empty( $tag->name ) ) return '';

    $name      = esc_attr( $tag->name );
    $error     = wpcf7_get_validation_error( $tag->name );
    $class     = wpcf7_form_controls_class( $tag->type ) . ' cf7w-signature-field';
    $required  = ( false !== strpos( $tag->type, '*' ) );
    $ink_color = esc_attr( $tag->get_option( 'color',     '', true ) ?: '#000000' );
    $bg_color  = esc_attr( $tag->get_option( 'backcolor', '', true ) ?: '#f9f9f9' );
    $width     = max( 100, (int) ( $tag->get_option( 'width',  '', true ) ?: 400 ) );
    $height    = max( 40,  (int) ( $tag->get_option( 'height', '', true ) ?: 160 ) );

    // Consent checkbox options
    $show_consent   = (bool) $tag->get_option( 'consent', '', true );
    $consent_label  = $tag->get_option( 'consent_label', '', true );
    if ( ! $consent_label ) {
        $consent_label = __( 'I agree to sign this document electronically.', 'sign-pdf-waiver-for-contact-form-7' );
    }
    $consent_name  = 'cf7w_consent_' . $name;
    $consent_error = $show_consent ? wpcf7_get_validation_error( $consent_name ) : '';

    $atts  = 'type="hidden" ';
    $atts .= 'id="cf7w-input-' . $name . '" ';
    $atts .= 'name="' . $name . '" ';
    $atts .= 'class="' . esc_attr( $class ) . '" ';
    $atts .= 'data-ink="' . $ink_color . '" ';
    if ( $required ) $atts .= 'data-required="1" ';

    $html  = '<div class="cf7w-sig-wrap">';

    // Consent checkbox — rendered ABOVE the canvas
    if ( $show_consent ) {
        $html .= '<div class="cf7w-consent-wrap" style="margin-bottom:10px;">';
        $html .= '<label class="cf7w-consent-label" style="display:flex;align-items:flex-start;'
               . 'gap:8px;font-size:13px;line-height:1.5;cursor:pointer;">';
        $html .= '<span class="wpcf7-form-control-wrap" data-name="' . esc_attr( $consent_name ) . '" '
               . 'style="flex-shrink:0;margin-top:2px;">';
        $html .= '<input type="checkbox" '
               . 'id="cf7w-consent-' . $name . '" '
               . 'name="' . esc_attr( $consent_name ) . '" '
               . 'value="1" '
               . 'class="cf7w-consent-checkbox" '
               . 'data-sig-field="' . $name . '" '
               . 'style="width:16px;height:16px;accent-color:#2563eb;cursor:pointer;">';
        $html .= $consent_error;
        $html .= '</span>';
        $html .= '<span>' . esc_html( $consent_label ) . '</span>';
        $html .= '</label>';
        $html .= '</div>';
    }

    // Signature canvas
    $html .= '<div class="cf7w-canvas-container" style="border:1px solid #ccc;background:'
           . $bg_color . ';display:inline-block;border-radius:4px;">';
    $html .= '<canvas id="cf7w-canvas-' . $name . '" '
           . 'class="cf7w-canvas" '
           . 'data-field="' . $name . '" '
           . 'width="' . $width . '" '
           . 'height="' . $height . '" '
           . 'style="display:block;cursor:crosshair;touch-action:none;max-width:100%;"></canvas>';
    $html .= '</div>';
    $html .= '<br><button type="button" class="cf7w-clear" data-field="' . $name . '" '
           . 'style="margin-top:6px;font-size:12px;">'
           . esc_html__( 'Clear', 'sign-pdf-waiver-for-contact-form-7' )
           . '</button>';
    $html .= '<span class="wpcf7-form-control-wrap" data-name="' . $name . '">';
    $html .= '<input ' . $atts . '>';
    $html .= $error;
    $html .= '</span></div>';

    return $html;
}

// ── Front-end assets ───────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'cf7w_frontend_assets' );
function cf7w_frontend_assets() {
    if ( ! class_exists( 'WPCF7' ) ) return;
    wp_enqueue_script( 'cf7w-signature', CF7W_URL . 'assets/js/signature.js', array( 'jquery' ), CF7W_VERSION, true );
    wp_enqueue_style(  'cf7w-style',     CF7W_URL . 'assets/css/waiver.css',  array(),          CF7W_VERSION );
}

// ════════════════════════════════════════════════════════════════════════════════
// [cf7w_pdf] — embeds the form's waiver PDF inline so signers can read it
// Usage: [cf7w_pdf height:600]  (height in px, optional; default 500)
// ════════════════════════════════════════════════════════════════════════════════

add_action( 'wpcf7_init', 'cf7w_register_pdf_embed_tag', 10 );
function cf7w_register_pdf_embed_tag() {
    wpcf7_add_form_tag( 'cf7w_pdf', 'cf7w_pdf_tag_handler' );
}

add_action( 'wpcf7_admin_init', 'cf7w_register_pdf_embed_tag_generator', 18 );
function cf7w_register_pdf_embed_tag_generator() {
    if ( ! class_exists( 'WPCF7_TagGenerator' ) ) return;
    $tg = WPCF7_TagGenerator::get_instance();
    $tg->add(
        'cf7w_pdf',
        __( 'PDF Embed', 'sign-pdf-waiver-for-contact-form-7' ),
        'cf7w_pdf_tag_generator_panel',
        array( 'version' => '2' )
    );
}

function cf7w_pdf_tag_generator_panel( $contact_form, $args = '' ) {
    $args    = wp_parse_args( $args, array() );
    $content = $args['content'] ?? '';
    ?>
    <header class="description-box">
        <h3><?php esc_html_e( 'PDF Embed form-tag generator', 'sign-pdf-waiver-for-contact-form-7' ); ?></h3>
        <p><?php esc_html_e( 'Embeds this form\'s waiver PDF inline so visitors can read it before signing. The PDF is taken from the Waiver settings for this form.', 'sign-pdf-waiver-for-contact-form-7' ); ?></p>
    </header>

    <div class="control-box">

        <fieldset>
            <legend><?php esc_html_e( 'Field type', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <input type="hidden" data-tag-part="basetype" value="cf7w_pdf">
        </fieldset>

        <fieldset>
            <legend><?php esc_html_e( 'Height (px)', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <input type="number"
                   data-tag-part="option"
                   data-tag-option="height:"
                   value="500" min="100" max="2000"
                   class="oneline cf7w-pdf-height">
        </fieldset>

        <fieldset>
            <legend><?php esc_html_e( 'Width', 'sign-pdf-waiver-for-contact-form-7' ); ?></legend>
            <label>
                <input type="radio"
                       name="<?php echo esc_attr( $content ); ?>-width-mode"
                       class="cf7w-pdf-width-radio"
                       value="full" checked>
                <?php esc_html_e( 'Full width (100%)', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </label>
            <br>
            <label style="margin-top:4px;display:inline-flex;align-items:center;gap:6px;">
                <input type="radio"
                       name="<?php echo esc_attr( $content ); ?>-width-mode"
                       class="cf7w-pdf-width-radio"
                       value="custom">
                <?php esc_html_e( 'Custom:', 'sign-pdf-waiver-for-contact-form-7' ); ?>
                <input type="number"
                       data-tag-part="option"
                       data-tag-option="width:"
                       value="" min="100" max="2000"
                       class="oneline cf7w-pdf-width-px"
                       style="width:70px;"
                       disabled>
                <?php esc_html_e( 'px', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </label>
        </fieldset>

    </div>

    <div class="insert-box">
        <div class="flex-container">
            <input type="text" class="code" readonly="readonly" onfocus="this.select();" data-tag-part="tag">
            <div class="submitbox">
                <input type="button" class="button button-primary insert-tag" value="<?php esc_attr_e( 'Insert Tag', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
            </div>
        </div>
    </div>
    <?php
}

function cf7w_pdf_tag_handler( $tag ) {
    // Get the contact form and look up its waiver PDF setting
    $cf7 = wpcf7_get_current_contact_form();
    if ( ! $cf7 ) return '';

    $settings   = get_post_meta( $cf7->id(), '_cf7w_settings', true ) ?: array();
    $attach_id  = $settings['pdf_attach_id'] ?? 0;
    $pdf_url    = '';

    if ( $attach_id ) {
        $pdf_url = wp_get_attachment_url( $attach_id );
    }
    if ( ! $pdf_url ) {
        $pdf_url = $settings['pdf_url'] ?? '';
    }
    if ( ! $pdf_url ) {
        return '<p style="color:#a00;">' . esc_html__( '[cf7w_pdf] — no PDF configured for this form. Set one in the Waiver tab.', 'sign-pdf-waiver-for-contact-form-7' ) . '</p>';
    }

    $height = max( 100, (int) ( $tag->get_option( 'height', '', true ) ?: 500 ) );
    $width_opt = $tag->get_option( 'width', '', true );
    $width  = $width_opt ? max( 100, (int) $width_opt ) . 'px' : '100%';

    $pdf_url_esc = esc_url( $pdf_url );

    // Use an <object> with <iframe> fallback — works across browsers including mobile.
    // Add #toolbar=0 to suppress the browser PDF toolbar (Chrome/Edge).
    $src = $pdf_url_esc . '#toolbar=0&navpanes=0&scrollbar=1';

    $html  = '<div class="cf7w-pdf-embed" style="width:' . esc_attr( $width ) . ';margin-bottom:12px;">';
    $html .= '<object data="' . $src . '" type="application/pdf"'
           . ' width="100%" height="' . esc_attr( $height ) . 'px"'
           . ' style="border:1px solid #ccc;border-radius:4px;display:block;">';
    // Fallback for browsers that can't display inline PDFs (e.g. some mobile)
    $html .= '<iframe src="' . esc_url( 'https://docs.google.com/viewer?url=' . urlencode( $pdf_url ) . '&embedded=true' ) . '"'
           . ' width="100%" height="' . esc_attr( $height ) . 'px"'
           . ' style="border:1px solid #ccc;border-radius:4px;display:block;" loading="lazy">';
    $html .= '<p style="padding:12px;">'
           . esc_html__( 'Your browser cannot display this PDF.', 'sign-pdf-waiver-for-contact-form-7' )
           . ' <a href="' . $pdf_url_esc . '" target="_blank">'
           . esc_html__( 'Download and read it here', 'sign-pdf-waiver-for-contact-form-7' )
           . '</a> ' . esc_html__( 'before signing.', 'sign-pdf-waiver-for-contact-form-7' )
           . '</p>';
    $html .= '</iframe>';
    $html .= '</object>';
    $html .= '</div>';

    return $html;
}

if ( cf7w_fs()->can_use_premium_code__premium_only() ) {
// ════════════════════════════════════════════════════════════════════════════
// [cf7w_verify] shortcode — front-end document verification page
// Usage: add [cf7w_verify] to any WordPress page
// ════════════════════════════════════════════════════════════════════════════

function cf7w_verify_shortcode(): string {
    ob_start();
    ?>
    <div class="cf7w-verify-wrap" style="max-width:560px;margin:0 auto;font-family:inherit;">

        <h2 style="font-size:1.4em;margin-bottom:8px;">
            <?php esc_html_e( 'Verify a Signed Document', 'sign-pdf-waiver-for-contact-form-7' ); ?>
        </h2>
        <p style="color:#555;margin-bottom:20px;font-size:14px;">
			<?php esc_html_e(
				'Enter the Log ID and upload the PDF exactly as you received it, '
				. ' do not modify the file. The pdf will be compared against '
				. 'our records to confirm the document has not been altered since signing.',
				'sign-pdf-waiver-for-contact-form-7'
			); ?>
		</p>

        <div id="cf7w-verify-form">

            <div style="margin-bottom:14px;">
                <label for="cf7w-log-id"
                       style="display:block;font-weight:600;font-size:14px;margin-bottom:4px;">
                    <?php esc_html_e( 'Log ID', 'sign-pdf-waiver-for-contact-form-7' ); ?>
                </label>
                <input type="number" id="cf7w-log-id" min="1"
                       style="width:100%;padding:9px 12px;border:1px solid #d1d5db;
                              border-radius:5px;font-size:15px;box-sizing:border-box;"
                       placeholder="<?php esc_attr_e( 'e.g. 42', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
            </div>

            <div style="margin-bottom:14px;">
                <label for="cf7w-verify-file"
                       style="display:block;font-weight:600;font-size:14px;margin-bottom:4px;">
                    <?php esc_html_e( 'Signed PDF File', 'sign-pdf-waiver-for-contact-form-7' ); ?>
                </label>
                <input type="file" id="cf7w-verify-file" accept=".pdf,application/pdf"
                       style="width:100%;padding:9px 0;font-size:14px;">
            </div>

            <button type="button" id="cf7w-verify-btn"
                    style="background:#2563eb;color:#fff;border:none;border-radius:6px;
                           padding:11px 28px;font-size:15px;font-weight:600;cursor:pointer;">
                <?php esc_html_e( 'Verify Document', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </button>

        </div><!-- #cf7w-verify-form -->

        <div id="cf7w-verify-result" style="display:none;margin-top:20px;padding:16px 18px;
             border-radius:6px;font-size:14px;line-height:1.6;"></div>

    </div><!-- .cf7w-verify-wrap -->

    <script>
    (function(){
        var btn    = document.getElementById('cf7w-verify-btn');
        var result = document.getElementById('cf7w-verify-result');
		
		(function(){
			// Pre-fill Log ID from URL query param if present
			var params = new URLSearchParams( window.location.search );
			var preId  = params.get('log_id');
			if ( preId ) {
				var inp = document.getElementById('cf7w-log-id');
				if ( inp ) inp.value = preId;
			}
			// ... rest of existing script
		})();

        btn.addEventListener('click', function() {
            var logId = document.getElementById('cf7w-log-id').value.trim();
            var file  = document.getElementById('cf7w-verify-file').files[0];

            if ( ! logId || isNaN( parseInt( logId, 10 ) ) ) {
                showResult( 'error', '<?php echo esc_js( __( 'Please enter a valid Log ID.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>' );
                return;
            }
            if ( ! file ) {
                showResult( 'error', '<?php echo esc_js( __( 'Please select a PDF file.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>' );
                return;
            }
            if ( file.type !== 'application/pdf' && ! file.name.match(/\.pdf$/i) ) {
                showResult( 'error', '<?php echo esc_js( __( 'Please select a PDF file.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>' );
                return;
            }

            btn.disabled    = true;
            btn.textContent = '<?php echo esc_js( __( 'Verifying\u2026', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>';
            result.style.display = 'none';

            var formData = new FormData();
            formData.append( 'action',   'cf7w_verify_document' );
            formData.append( 'nonce',    '<?php echo esc_js( wp_create_nonce( 'cf7w_verify' ) ); ?>' );
            formData.append( 'log_id',   logId );
            formData.append( 'pdf_file', file );

            fetch( '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST',
                body:   formData,
            })
            .then( function(r){ return r.json(); } )
            .then( function(data) {
                btn.disabled    = false;
                btn.textContent = '<?php echo esc_js( __( 'Verify Document', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>';
                if ( data.success ) {
                    showResult( 'success', data.data.message, data.data.detail );
                } else {
                    showResult( 'error', data.data.message, data.data.detail || '' );
                }
            })
            .catch( function(err) {
                btn.disabled    = false;
                btn.textContent = '<?php echo esc_js( __( 'Verify Document', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>';
                showResult( 'error', '<?php echo esc_js( __( 'Network error. Please try again.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>' );
            });
        });

        function showResult( type, message, detail ) {
            var isOk = ( type === 'success' );
            result.style.display    = 'block';
            result.style.background = isOk ? '#ecfdf5' : '#fef2f2';
            result.style.border     = '1px solid ' + ( isOk ? '#6ee7b7' : '#fca5a5' );
            result.style.color      = isOk ? '#065f46' : '#991b1b';
            result.innerHTML = '<strong style="font-size:15px;">'
                + ( isOk ? '&#10003; ' : '&#10007; ' )
                + escHtml( message )
                + '</strong>'
                + ( detail ? '<br><span style="font-size:13px;opacity:0.85;">'
                    + escHtml( detail ) + '</span>' : '' );
        }

        function escHtml( s ) {
            return String(s)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ── AJAX handler ──────────────────────────────────────────────────────────────
function cf7w_ajax_verify_document(): void {
    // Nonce check — open to public (nopriv) but still CSRF-protected
    if ( ! isset( $_POST['nonce'] )
      || ! wp_verify_nonce( $_POST['nonce'], 'cf7w_verify' ) ) {
        wp_send_json_error( array(
            'message' => __( 'Security check failed. Please refresh and try again.', 'sign-pdf-waiver-for-contact-form-7' ),
        ) );
    }

    $log_id = absint( $_POST['log_id'] ?? 0 );
    if ( ! $log_id ) {
        wp_send_json_error( array( 'message' => __( 'Invalid Log ID.', 'sign-pdf-waiver-for-contact-form-7' ) ) );
    }

    // Look up the stored hash for this submission
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        'SELECT id, form_id, entry_date, doc_hash FROM ' . cf7w_db_table()
        . ' WHERE id = %d LIMIT 1',
        $log_id
    ) );

    if ( ! $row ) {
        wp_send_json_error( array(
            'message' => __( 'No submission found for that Log ID.', 'sign-pdf-waiver-for-contact-form-7' ),
            'detail'  => __( 'Check the Log ID on your audit trail page and try again.', 'sign-pdf-waiver-for-contact-form-7' ),
        ) );
    }

    if ( empty( $row->doc_hash ) ) {
        wp_send_json_error( array(
            'message' => __( 'This submission does not have a stored document hash.', 'sign-pdf-waiver-for-contact-form-7' ),
            'detail'  => __( 'The audit trail feature may not have been enabled when this document was signed.', 'sign-pdf-waiver-for-contact-form-7' ),
        ) );
    }

    // Validate the uploaded file
    if ( empty( $_FILES['pdf_file'] ) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( array(
            'message' => __( 'File upload failed.', 'sign-pdf-waiver-for-contact-form-7' ),
            'detail'  => __( 'Please try again with a smaller file or check your connection.', 'sign-pdf-waiver-for-contact-form-7' ),
        ) );
    }

    $tmp_path = $_FILES['pdf_file']['tmp_name'] ?? '';
    if ( ! $tmp_path || ! is_uploaded_file( $tmp_path ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid file upload.', 'sign-pdf-waiver-for-contact-form-7' ) ) );
    }

    // Verify it is actually a PDF (check magic bytes)
    $fh = fopen( $tmp_path, 'rb' );
    $magic = fread( $fh, 4 );
    fclose( $fh );
    if ( $magic !== '%PDF' ) {
        wp_send_json_error( array(
            'message' => __( 'The uploaded file does not appear to be a valid PDF.', 'sign-pdf-waiver-for-contact-form-7' ),
        ) );
    }

    // Read the uploaded file and hash it
    $pdf_bytes    = file_get_contents( $tmp_path );
    $upload_hash  = hash( 'sha256', $pdf_bytes );
    $stored_hash  = $row->doc_hash;

    // Format submission metadata for the success message
    $form_name    = get_the_title( $row->form_id ) ?: 'Form #' . $row->form_id;
    $entry_date   = date_i18n( 'j F Y \a\t g:ia', strtotime( $row->entry_date ) );

    if ( hash_equals( $stored_hash, $upload_hash ) ) {
        // hash_equals() is timing-attack safe
        wp_send_json_success( array(
            'message' => __( 'Document verified — this PDF has not been altered.', 'sign-pdf-waiver-for-contact-form-7' ),
            'detail'  => sprintf(
                /* translators: 1: form name, 2: submission date */
                __( 'Submission #%1$d | Form: %2$s | Signed: %3$s', 'sign-pdf-waiver-for-contact-form-7' ),
                $log_id,
                $form_name,
                $entry_date
            ),
        ) );
    } else {
        wp_send_json_error( array(
            'message' => __( 'Verification failed — this PDF does not match our records.', 'sign-pdf-waiver-for-contact-form-7' ),
            'detail'  => __( 'The document may have been modified after signing, or this is not the correct file for this Log ID. Ensure you have removed the audit trail page before uploading.', 'sign-pdf-waiver-for-contact-form-7' ),
        ) );
    }
}
} // @endif can_use_premium_code__premium_only
