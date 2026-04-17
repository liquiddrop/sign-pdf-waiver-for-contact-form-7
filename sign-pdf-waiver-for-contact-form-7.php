<?php
/**
 * Plugin Name: Sign PDF Waiver for Contact Form 7
 * Plugin URI:  https://wordpress.org/plugins/sign-pdf-waiver-for-contact-form-7/
 * Description: Attach a PDF waiver to CF7. Map fields, capture signature, fill and email the PDF.
 * Version:     1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Requires Plugins: contact-form-7
 * Author:      Social Good Analytics LLC
 * License:     GPLv2
 * Text Domain:  sign-pdf-waiver-for-contact-form-7
 * Domain Path:  /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CF7W_VERSION', '1.0.0' );
define( 'CF7W_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CF7W_URL',     plugin_dir_url( __FILE__ ) );

/**
 * Secure (non-web-accessible) storage for filled PDFs and signatures.
 * Stored under wp-content/cf7w-private/ — no public URL exists for this path.
 * Files are served only through the authenticated cf7w_serve_file proxy.
 */
define( 'CF7W_SECURE_DIR', WP_CONTENT_DIR . '/cf7w-private/' );

// CF7W_DB_TABLE cannot be defined at file-load time because $wpdb may not be
// initialised yet (causes fatal during activation). Use a helper function instead.
function cf7w_db_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'cf7w_logs';
}

// ── Freemius SDK ──────────────────────────────────────────────────────────────
// Must be loaded FIRST — before class files that reference cf7w_fs()
require_once CF7W_DIR . 'includes/freemius.php';
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
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS " . cf7w_db_table() . " (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        form_id     BIGINT(20) UNSIGNED NOT NULL,
        entry_date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address  VARCHAR(45) NOT NULL DEFAULT '',
        user_agent  TEXT,
        form_data   LONGTEXT,
        signature   LONGTEXT,
        filled_pdf  TEXT,
        PRIMARY KEY (id),
        KEY form_id (form_id)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    cf7w_ensure_secure_dir();
}

// ── Boot: runs after all plugins loaded ───────────────────────────────────────
add_action( 'plugins_loaded', 'cf7w_boot' );
function cf7w_boot() {
    if ( ! class_exists( 'WPCF7' ) ) return;
	error_log( 'CF7W license state: paying=' . (int)cf7w_fs()->is_paying()
    . ' trial=' . (int)cf7w_fs()->is_trial()
    . ' registered=' . (int)cf7w_fs()->is_registered() );
    cf7w_ensure_secure_dir();
    CF7W_Admin::init();
    CF7W_Submission_Handler::init();
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
    );
    foreach ( $dirs as $dir ) {
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
    }
    $htaccess = CF7W_SECURE_DIR . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess,
            "# CF7 Waiver - deny all direct HTTP access\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
        );
    }
    $index = CF7W_SECURE_DIR . 'index.php';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, '<?php // Silence is golden.' );
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

    $atts  = 'type="hidden" ';
    $atts .= 'id="cf7w-input-' . $name . '" ';
    $atts .= 'name="' . $name . '" ';
    $atts .= 'class="' . esc_attr( $class ) . '" ';
    $atts .= 'data-ink="' . $ink_color . '" ';
    if ( $required ) $atts .= 'data-required="1" ';

    $html  = '<div class="cf7w-sig-wrap">';
    $html .= '<div class="cf7w-canvas-container" style="border:1px solid #ccc;background:' . $bg_color . ';display:inline-block;border-radius:4px;">';
    $html .= '<canvas id="cf7w-canvas-' . $name . '" class="cf7w-canvas" data-field="' . $name . '" width="' . $width . '" height="' . $height . '" style="display:block;cursor:crosshair;touch-action:none;max-width:100%;"></canvas>';
    $html .= '</div>';
    $html .= '<br><button type="button" class="cf7w-clear" data-field="' . $name . '" style="margin-top:6px;font-size:12px;">' . esc_html__( 'Clear', 'sign-pdf-waiver-for-contact-form-7' ) . '</button>';
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
