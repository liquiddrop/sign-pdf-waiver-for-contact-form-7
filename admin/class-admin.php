<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF7W_Admin {

    public static function init(): void {
        add_action( 'admin_menu',              array( __CLASS__, 'add_menu' ) );
        add_filter( 'wpcf7_editor_panels',     array( __CLASS__, 'add_cf7_panel' ) );
        add_action( 'wpcf7_save_contact_form', array( __CLASS__, 'save_cf7_form' ) );
        add_action( 'admin_enqueue_scripts',   array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_cf7w_get_cf7_fields',    array( __CLASS__, 'ajax_get_cf7_fields' ) );
        add_action( 'wp_ajax_cf7w_get_pdf_info',       array( __CLASS__, 'ajax_get_pdf_info' ) );
        add_action( 'wp_ajax_cf7w_delete_submissions', array( __CLASS__, 'ajax_delete_submissions' ) );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────
    public static function add_menu(): void {
        add_submenu_page(
            'wpcf7',
            __( 'Form Submissions', 'sign-pdf-waiver-for-contact-form-7' ),
            __( 'Form Submissions', 'sign-pdf-waiver-for-contact-form-7' ),
            'manage_options',
            'cf7w-submissions',
            array( __CLASS__, 'render_submissions_page' )
        );
    }

    // ── CF7 editor panel tab ──────────────────────────────────────────────────
    public static function add_cf7_panel( array $panels ): array {
        $panels['cf7w-pdf-form-panel'] = array(
            'title'    => __( 'PDF Form', 'sign-pdf-waiver-for-contact-form-7' ),
            'callback' => array( __CLASS__, 'render_panel' ),
        );
        return $panels;
    }

    // ── Panel HTML ────────────────────────────────────────────────────────────
    public static function render_panel( $cf7_form ): void {
        $post_id  = is_object( $cf7_form ) ? $cf7_form->id() : absint( $cf7_form );
        $settings = get_post_meta( $post_id, '_cf7w_settings', true ) ?: array();

        // ── License gate ──────────────────────────────────────────────────────────
        $is_licensed = CF7W_License::is_active();
        $status      = CF7W_License::status_label();
        $upgrade_url = function_exists( 'cf7w_fs' ) ? cf7w_fs()->get_upgrade_url() : '#';
        $trial_url   = function_exists( 'cf7w_fs' ) ? cf7w_fs()->get_trial_url()   : '#';

        if ( ! $is_licensed ) {
            ?>
            <div class="notice notice-warning inline" style="margin:0 0 16px;padding:12px 16px;border-left-color:#f0b849;">
                <p style="margin:0;font-size:13px;">
                    <strong>CF7 PDF Waiver — License Required</strong><br>
                    PDF watermarking is enabled. Status: <strong><?php echo esc_html( $status ); ?></strong>
                    &nbsp;|&nbsp;
                    <a href="<?php echo esc_url( $trial_url ); ?>" class="button button-primary" style="margin-left:8px;">
                        Start Free 14-Day Trial
                    </a>
                    <a href="<?php echo esc_url( $upgrade_url ); ?>" class="button" style="margin-left:6px;">
                        Buy License
                    </a>
                </p>
            </div>
            <?php
        }
	
		
        $pdf_url  = $settings['pdf_url']       ?? '';
        $pdf_id   = $settings['pdf_attach_id'] ?? 0;
        $notify   = $settings['notify_email']  ?? get_option( 'admin_email' );
        $attach_pdf = ! empty( $settings['attach_pdf'] );
        $sig_page = $settings['sig_page']      ?? 1;
        $sig_x    = $settings['sig_x']         ?? 50;
        $sig_y    = $settings['sig_y']         ?? 80;
        $sig_w    = $settings['sig_w']         ?? 200;
        $sig_h    = $settings['sig_h']         ?? 60;
        $filename_scheme  = $settings['pdf_filename_scheme'] ?? '';
        $save_pdf         = isset( $settings['save_pdf'] ) ? (bool) $settings['save_pdf'] : true;
        $save_signature   = isset( $settings['save_signature'] ) ? (bool) $settings['save_signature'] : true;

        // Get CF7 fields from the saved form object (server-side render)
        $cf7_fields = self::tags_to_fields(
            is_object( $cf7_form ) && method_exists( $cf7_form, 'scan_form_tags' )
                ? $cf7_form->scan_form_tags()
                : array()
        );

        wp_nonce_field( 'cf7w_save_meta_' . $post_id, 'cf7w_meta_nonce' );
        ?>		
<div class="cf7w-admin-box" id="cf7w-panel-root">

<!-- Step 1 – PDF -->
<div class="cf7w-admin-section">
  <h3><?php esc_html_e( 'Step 1 – Upload PDF', 'sign-pdf-waiver-for-contact-form-7' ); ?></h3>
  <input type="hidden" id="cf7w_pdf_attach_id" name="cf7w_pdf_attach_id" value="<?php echo esc_attr( $pdf_id ); ?>">
  <input type="hidden" id="cf7w_pdf_url"       name="cf7w_pdf_url"       value="<?php echo esc_url( $pdf_url ); ?>">
  <p id="cf7w-current-pdf" <?php echo $pdf_url ? '' : 'style="display:none;"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded static attribute string, no user input ?>>
    <strong><?php esc_html_e( 'Current PDF:', 'sign-pdf-waiver-for-contact-form-7' ); ?></strong>
    <a id="cf7w-current-pdf-link" href="<?php echo esc_url( $pdf_url ); ?>" target="_blank"><?php echo esc_html( basename( $pdf_url ) ); ?></a>

  </p>
  <button type="button" id="cf7w-upload-btn" class="button button-secondary">
    <?php echo $pdf_url ? esc_html__( 'Replace PDF', 'sign-pdf-waiver-for-contact-form-7' ) : esc_html__( 'Upload PDF', 'sign-pdf-waiver-for-contact-form-7' ); ?>
  </button>

  <div id="cf7w-upload-status" style="margin-top:8px;"></div>
</div>

<!-- Step 2 — Visual Placement -->
<?php
$vp_placements = $settings['visual_placements'] ?? array();
// Visual placement is always on
?>
<input type="hidden" name="cf7w_visual_placement_enabled" value="1">
<div class="cf7w-admin-section" id="cf7w-step2-section">
  <h3><?php esc_html_e( 'Step 2 — Visual Placement', 'sign-pdf-waiver-for-contact-form-7' ); ?></h3>

  <!-- ===== VISUAL PLACEMENT PANE ===== -->
  <div id="cf7w-pane-visual">

    <!-- Saved placements as hidden inputs (written back by JS on save) -->
    <div id="cf7w-vp-inputs">
      <input type="hidden" name="cf7w_vp_iframe_w" id="cf7w-vp-iframe-w-input" value="<?php echo esc_attr( $settings['vp_iframe_w'] ?? 800 ); ?>">
      <input type="hidden" name="cf7w_vp_iframe_h" id="cf7w-vp-iframe-h-input" value="<?php echo esc_attr( $settings['vp_iframe_h'] ?? 1000 ); ?>">
      <?php foreach ( $vp_placements as $idx => $vp ) : ?>
        <?php foreach ( array( 'cf7_field','page','x','y','w','h','font_size','canvas_w','canvas_h' ) as $k ) : ?>
          <input type="hidden" name="cf7w_vp[<?php echo absint( $idx ); ?>][<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $vp[ $k ] ?? '' ); ?>">
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>

    <?php if ( ! $pdf_url ) : ?>
      <div class="notice notice-warning inline" style="margin:0 0 10px;"><p><?php esc_html_e( 'Upload a PDF in Step 1 first, then save the form.', 'sign-pdf-waiver-for-contact-form-7' ); ?></p></div>
    <?php endif; ?>
    <?php if ( $pdf_url && empty( $cf7_fields ) ) : ?>
      <div class="notice notice-warning inline" style="margin:0 0 10px;"><p><?php esc_html_e( 'Add CF7 fields to your Form tab and save first.', 'sign-pdf-waiver-for-contact-form-7' ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! empty( $cf7_fields ) ) : ?>

    <!-- Contact Form 7 Fields -->
    <div style="margin-bottom:16px;">
      <strong style="font-size:13px;"><?php esc_html_e( 'Contact Form 7 Fields', 'sign-pdf-waiver-for-contact-form-7' ); ?></strong>
      <span class="description" style="margin-left:8px;"><?php esc_html_e( 'Click a field to place it on the current PDF page.', 'sign-pdf-waiver-for-contact-form-7' ); ?></span>
      <div id="cf7w-vp-palette" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;">
        <?php foreach ( $cf7_fields as $f ) : ?>
          <button type="button" class="button cf7w-vp-add-field"
                  data-field="<?php echo esc_attr( $f['name'] ); ?>"
                  data-type="<?php echo esc_attr( $f['type'] ); ?>"
                  style="font-size:12px;">
            + <?php echo esc_html( $f['name'] ); ?> <em style="color:#777;">[<?php echo esc_html( $f['type'] ); ?>]</em>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Controls toolbar -->
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
      <button type="button" class="button button-primary" id="cf7w-vp-refresh">&#8635; Reload PDF</button>
      <span style="margin-left:12px;color:#50575e;font-size:12px;"><?php esc_html_e( 'Width:', 'sign-pdf-waiver-for-contact-form-7' ); ?></span>
      <select id="cf7w-vp-width" style="width:90px;">
        <option value="600">600px</option>
        <option value="800" selected>800px</option>
        <option value="1000">1000px</option>
        <option value="1200">1200px</option>
      </select>
    </div>

    <!-- PDF viewer -->
    <div id="cf7w-vp-wrap" style="position:relative;display:inline-block;border:2px solid #d1d5db;border-radius:4px;overflow:hidden;background:#525659;cursor:default;min-width:300px;min-height:80px;">
      <div id="cf7w-vp-frame-container"></div>
      <div id="cf7w-vp-overlay" style="position:absolute;top:0;left:0;pointer-events:none;"></div>
    </div>

    <!-- Font size + background controls -->
    <div style="margin-top:12px;display:flex;align-items:center;gap:8px;">
      <label for="cf7w-vp-fontsize" style="font-size:13px;font-weight:600;"><?php esc_html_e( 'Default font size (pt):', 'sign-pdf-waiver-for-contact-form-7' ); ?></label>
      <input type="number" id="cf7w-vp-fontsize" name="cf7w_vp_font_size" value="<?php echo esc_attr( $settings['vp_font_size'] ?? 12 ); ?>" min="6" max="72" class="small-text">
      <span class="description"><?php esc_html_e( 'Applied to newly-added boxes.', 'sign-pdf-waiver-for-contact-form-7' ); ?></span>
    </div>
    <div style="margin-top:12px;display:flex;align-items:center;gap:8px;">
      <label for="cf7w-vp-bgcolor" style="font-size:13px;font-weight:600;"><?php esc_html_e( 'Text background:', 'sign-pdf-waiver-for-contact-form-7' ); ?></label>
      <select id="cf7w-vp-bgcolor" name="cf7w_vp_bg_color" style="font-size:13px;">
        <option value="transparent" <?php selected( $settings['vp_bg_color'] ?? 'transparent', 'transparent' ); ?>><?php esc_html_e( 'Transparent', 'sign-pdf-waiver-for-contact-form-7' ); ?></option>
        <option value="white"       <?php selected( $settings['vp_bg_color'] ?? 'transparent', 'white' ); ?>><?php esc_html_e( 'White',       'sign-pdf-waiver-for-contact-form-7' ); ?></option>
        <option value="yellow"      <?php selected( $settings['vp_bg_color'] ?? 'transparent', 'yellow' ); ?>><?php esc_html_e( 'Yellow',      'sign-pdf-waiver-for-contact-form-7' ); ?></option>
        <option value="cyan"        <?php selected( $settings['vp_bg_color'] ?? 'transparent', 'cyan' ); ?>><?php esc_html_e( 'Cyan',        'sign-pdf-waiver-for-contact-form-7' ); ?></option>
      </select>
      <span class="description"><?php esc_html_e( 'Background box drawn behind each text stamp.', 'sign-pdf-waiver-for-contact-form-7' ); ?></span>
    </div>
    <?php endif; ?>

  </div><!-- #cf7w-pane-visual -->

</div><!-- #cf7w-step2-section -->


<!-- Step 3 – PDF Filename, Storage &amp; Delivery -->
<div class="cf7w-admin-section">
  <h3><?php esc_html_e( 'Step 3 – PDF Filename, Storage &amp; Delivery', 'sign-pdf-waiver-for-contact-form-7' ); ?></h3>

  <p class="description">
    <?php esc_html_e( 'Pattern for the saved PDF filename. Use {PDF Field Name} tokens to include field values. Built-in: {date}, {time}, {form_id}.', 'sign-pdf-waiver-for-contact-form-7' ); ?><br>
    <?php esc_html_e( 'Example: form_{Full Name}_{date} → form_John_Smith_2024-01-15.pdf', 'sign-pdf-waiver-for-contact-form-7' ); ?>
  </p>
  <input type="text" name="cf7w_pdf_filename_scheme"
         value="<?php echo esc_attr( $filename_scheme ); ?>"
         class="regular-text"
         placeholder="form_{form_id}_{date}">

  <br><br><strong><?php esc_html_e( 'Storage', 'sign-pdf-waiver-for-contact-form-7' ); ?></strong><br><br>
  <label>
    <input type="checkbox" name="cf7w_save_pdf" id="cf7w_save_pdf" value="1" <?php checked( $save_pdf ); ?>>
    <?php esc_html_e( 'Save filled PDF to server after sending', 'sign-pdf-waiver-for-contact-form-7' ); ?>
  </label>
  <p class="description" style="margin-top:4px;"><?php esc_html_e( 'When unchecked, the filled PDF is deleted from the server immediately after emails are sent. The PDF will only exist if it is attached to an outgoing email.', 'sign-pdf-waiver-for-contact-form-7' ); ?></p>

  <br>
  <label>
    <input type="checkbox" name="cf7w_save_signature" id="cf7w_save_signature" value="1" <?php checked( $save_signature ); ?>>
    <?php esc_html_e( 'Save signature image to server', 'sign-pdf-waiver-for-contact-form-7' ); ?>
  </label>
  <p class="description" style="margin-top:4px;"><?php esc_html_e( 'When unchecked, the signature PNG is deleted from the server after the PDF has been generated.', 'sign-pdf-waiver-for-contact-form-7' ); ?></p>

  <br><strong><?php esc_html_e( 'Delivery', 'sign-pdf-waiver-for-contact-form-7' ); ?></strong><br><br>
  <label>
    <input type="checkbox" name="cf7w_attach_pdf" id="cf7w_attach_pdf" value="1" <?php checked( $attach_pdf ); ?>>
    <?php esc_html_e( 'Attach filled PDF to CF7\'s outgoing emails', 'sign-pdf-waiver-for-contact-form-7' ); ?>
  </label>
  <p class="description" style="margin-top:4px;"><?php esc_html_e( 'When checked, the completed PDF is automatically attached to every email CF7 sends for this form (admin notification and, if configured, the submitter confirmation).', 'sign-pdf-waiver-for-contact-form-7' ); ?></p>

  <p class="description" style="margin-top:12px;">
    <strong><?php esc_html_e( 'Note:', 'sign-pdf-waiver-for-contact-form-7' ); ?></strong>
    <?php esc_html_e( 'If neither &quot;Save filled PDF&quot; nor &quot;Attach PDF to email&quot; are enabled, no record of the filled PDF will be kept.', 'sign-pdf-waiver-for-contact-form-7' ); ?>
  </p>

</div>

</div><!-- .cf7w-admin-box -->
        <?php

        // Pass CF7 field data to JS via inline script on the already-enqueued handle
        // (avoids a raw <script> block that some CF7 versions render as visible text)
        $js = 'window.CF7W_CF7Fields='    . wp_json_encode( array_values( $cf7_fields ) ) . ';'
            . 'window.CF7W_FormId='       . (int) $post_id . ';'
            . 'window.CF7W_PdfUrl='       . wp_json_encode( $pdf_url ) . ';'
            . 'window.CF7W_VpPlacements=' . wp_json_encode( array_values( $vp_placements ) ) . ';'
            . 'window.CF7W_VpEnabled=true;'
            . 'window.CF7W_VpFontSize='   . (int) ( $settings['vp_font_size'] ?? 12 ) . ';'
            . 'window.CF7W_VpIframeW='    . (int) ( $settings['vp_iframe_w']  ?? 800 ) . ';'
            . 'window.CF7W_VpIframeH='    . (int) ( $settings['vp_iframe_h']  ?? 1000 ) . ';';
        wp_add_inline_script( 'cf7w-admin', $js );
    }


    // ── Save ──────────────────────────────────────────────────────────────────
    public static function save_cf7_form( $cf7_form ): void {
        $post_id = is_object( $cf7_form ) ? $cf7_form->id() : absint( $cf7_form );
        if (
            ! isset( $_POST['cf7w_meta_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cf7w_meta_nonce'] ) ), 'cf7w_save_meta_' . $post_id )
            || ! current_user_can( 'manage_options' )
        ) return;

        // Visual placements — sanitize every sub-field on extraction so PHPCS
        // can trace sanitization from the $_POST access through to storage.
        $vp_list = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via wp_verify_nonce before this point is reached
        $raw_vp_post = isset( $_POST['cf7w_vp'] ) ? (array) wp_unslash( $_POST['cf7w_vp'] ) : array();
        foreach ( $raw_vp_post as $vp_raw_item ) {
            if ( ! is_array( $vp_raw_item ) ) continue;
            $cf7f = sanitize_key( $vp_raw_item['cf7_field'] ?? '' );
            if ( ! $cf7f ) continue;
            $vp_list[] = array(
                'cf7_field' => $cf7f,
                'page'      => max( 1, absint( sanitize_text_field( $vp_raw_item['page'] ?? 1 ) ) ),
                'x'         => floatval( sanitize_text_field( $vp_raw_item['x'] ?? 0 ) ),
                'y'         => floatval( sanitize_text_field( $vp_raw_item['y'] ?? 0 ) ),
                'w'         => max( 20, floatval( sanitize_text_field( $vp_raw_item['w'] ?? 150 ) ) ),
                'h'         => max( 10, floatval( sanitize_text_field( $vp_raw_item['h'] ?? 20 ) ) ),
                'font_size' => max( 6, absint( sanitize_text_field( $vp_raw_item['font_size'] ?? 12 ) ) ),
                'canvas_w'  => max( 0, floatval( sanitize_text_field( $vp_raw_item['canvas_w'] ?? 0 ) ) ),
                'canvas_h'  => max( 0, floatval( sanitize_text_field( $vp_raw_item['canvas_h'] ?? 0 ) ) ),
            );
        }

        $allowed_bg  = array( 'transparent', 'white', 'yellow', 'cyan' );
        $vp_bg_color = sanitize_key( wp_unslash( $_POST['cf7w_vp_bg_color'] ?? 'transparent' ) );
        if ( ! in_array( $vp_bg_color, $allowed_bg, true ) ) { $vp_bg_color = 'transparent'; }

        update_post_meta( $post_id, '_cf7w_settings', array(
            'pdf_attach_id'            => absint( wp_unslash( $_POST['cf7w_pdf_attach_id'] ?? 0 ) ),
            'pdf_url'                  => esc_url_raw( wp_unslash( $_POST['cf7w_pdf_url'] ?? '' ) ),
            'notify_email'             => sanitize_email( wp_unslash( $_POST['cf7w_notify_email'] ?? '' ) ),
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- checkbox presence check only, no string value extracted
            'attach_pdf'               => ! empty( $_POST['cf7w_attach_pdf'] ),
            'save_pdf'                 => ! empty( $_POST['cf7w_save_pdf'] ),
            'save_signature'           => ! empty( $_POST['cf7w_save_signature'] ),
            'pdf_filename_scheme'      => sanitize_text_field( wp_unslash( $_POST['cf7w_pdf_filename_scheme'] ?? '' ) ),
            'visual_placement_enabled' => true,
            'visual_placements'        => $vp_list,
            'vp_font_size'             => max( 6, absint( wp_unslash( $_POST['cf7w_vp_font_size'] ?? 12 ) ) ),
            'vp_bg_color'              => $vp_bg_color,
            'flatten_pdf'              => ! empty( $_POST['cf7w_flatten_pdf'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- checkbox presence check only
            'vp_iframe_w'              => max( 400, absint( wp_unslash( $_POST['cf7w_vp_iframe_w'] ?? 800 ) ) ),
            'vp_iframe_h'              => max( 400, absint( wp_unslash( $_POST['cf7w_vp_iframe_h'] ?? 1000 ) ) ),
        ) );
    }

    // ── AJAX: delete submissions ──────────────────────────────────────────────
    public static function ajax_delete_submissions(): void {
        check_ajax_referer( 'cf7w_delete_submissions', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ) );

        $raw_ids = isset( $_POST['ids'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['ids'] ) ) : array();
        $ids     = array_map( 'absint', $raw_ids );
        $ids     = array_filter( $ids ); // remove zeros

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'No IDs provided' ) );
        }

        global $wpdb;
        $deleted = 0;

        foreach ( $ids as $id ) {
            // Load the row so we can clean up associated files.
            // Table name comes from $wpdb->prefix via cf7w_db_table() — not user input.
            $fetch_table = esc_sql( cf7w_db_table() );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $fetch_table is esc_sql() sanitised; pre-delete fetch must be fresh
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT filled_pdf, signature FROM {$fetch_table} WHERE id = %d", $id ) );

            if ( ! $row ) continue;

            // Delete the filled PDF file if it exists.
            if ( ! empty( $row->filled_pdf ) && file_exists( $row->filled_pdf ) ) {
                cf7w_delete_file( $row->filled_pdf );
            }

            // Delete the signature PNG file if it exists.
            // The signature column stores the file path (not base64) for rows
            // saved after this version. Only unlink if it looks like a file path.
            if ( ! empty( $row->signature )
                && strpos( $row->signature, 'data:' ) === false
                && file_exists( $row->signature ) ) {
                cf7w_delete_file( $row->signature );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- delete operation; cache is flushed immediately after the loop via wp_cache_flush_group
            $result = $wpdb->delete( cf7w_db_table(), array( 'id' => $id ), array( '%d' ) );
            if ( $result ) $deleted++;
        }

        // Invalidate the submissions list cache so the next page load is fresh.
        wp_cache_flush_group( 'cf7w_submissions' );

        wp_send_json_success( array( 'deleted' => $deleted ) );
    }

    // ── AJAX: get PDF page info (page count + dimensions for visual placement) ─
    public static function ajax_get_pdf_info(): void {
        check_ajax_referer( 'cf7w_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $attach_id = absint( wp_unslash( $_POST['attach_id'] ?? 0 ) );
        $file_path = $attach_id ? get_attached_file( $attach_id ) : '';
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            wp_send_json_error( array( 'message' => 'File not found' ) );
        }

        // Parse page count and dimensions from PDF binary
        $pages = self::pdf_get_page_info( $file_path );
        wp_send_json_success( array( 'pages' => $pages ) );
    }

    /**
     * Extract page count and MediaBox dimensions from a PDF file.
     * Returns array of [ 'width' => float, 'height' => float ] (pts) per page.
     */
    private static function pdf_get_page_info( string $path ): array {
        $raw = file_get_contents( $path );
        if ( ! $raw ) return array( array( 'width' => 612, 'height' => 792 ) );

        // Find all /MediaBox arrays — use the last one for each page as default
        $boxes = array();
        preg_match_all( '/\/MediaBox\s*\[\s*([\d.+-]+)\s+([\d.+-]+)\s+([\d.+-]+)\s+([\d.+-]+)\s*\]/', $raw, $mm );
        foreach ( $mm[1] as $i => $x1 ) {
            $boxes[] = array(
                'width'  => round( abs( floatval( $mm[3][$i] ) - floatval( $x1 ) ), 2 ),
                'height' => round( abs( floatval( $mm[4][$i] ) - floatval( $mm[2][$i] ) ), 2 ),
            );
        }
        if ( empty( $boxes ) ) {
            $boxes = array( array( 'width' => 612, 'height' => 792 ) );
        }

        // Count /Page objects (not /Pages)
        preg_match_all( '/\/Type\s*\/Page\b(?!\s*s)/', $raw, $pm );
        $page_count = max( 1, count( $pm[0] ) );

        // Pad or trim boxes array to page_count, reusing last known box
        $result = array();
        for ( $i = 0; $i < $page_count; $i++ ) {
            $result[] = $boxes[ $i ] ?? end( $boxes );
        }
        return $result;
    }

    // ── AJAX: get CF7 fields from live form body ───────────────────────────────
    // Mirrors WPCF7_Pdf_Forms::wp_ajax_query_cf7_fields() exactly:
    // receives the current form body text from JS, creates a temp
    // WPCF7_ContactForm, sets its form property, calls scan_form_tags().
    public static function ajax_get_cf7_fields(): void {
        check_ajax_referer( 'cf7w_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $form_body = isset( $_POST['form_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['form_body'] ) ) : '';
        $form_id   = absint( wp_unslash( $_POST['form_id'] ?? 0 ) );
        $fields    = self::scan_cf7_fields( $form_body, $form_id );

        wp_send_json_success( array( 'cf7_fields' => array_values( $fields ) ) );
    }

    // ── Scan CF7 fields from a live form body string ──────────────────────────
    // Uses the same technique as PDF Forms Filler for CF7:
    // create a template, set its form body, then scan_form_tags().
    private static function scan_cf7_fields( string $form_body, int $form_id = 0 ): array {
        // Try from live body first
        if ( $form_body && class_exists( 'WPCF7_ContactForm' ) ) {
            try {
                $cf7 = WPCF7_ContactForm::get_template();
                $props = $cf7->get_properties();
                $props['form'] = $form_body;
                $cf7->set_properties( $props );
                $tags = $cf7->scan_form_tags();
                if ( ! empty( $tags ) ) return self::tags_to_fields( $tags );
            } catch ( Exception $e ) {
                // fall through
            }
        }
        // Fallback: load saved form
        if ( $form_id && function_exists( 'wpcf7_contact_form' ) ) {
            $cf7 = wpcf7_contact_form( $form_id );
            if ( $cf7 ) return self::tags_to_fields( $cf7->scan_form_tags() );
        }
        return array();
    }

    // Convert CF7 tag objects to name/type array, skipping non-input tags
    private static function tags_to_fields( array $tags ): array {
        $skip   = array( 'submit', 'captchac', 'captchar', 'recaptcha', 'honeypot', 'hidden', '' );
        $fields = array();
        foreach ( $tags as $tag ) {
            $name = $tag->name ?? '';
            $type = $tag->basetype ?? '';
            if ( ! $name || in_array( $type, $skip, true ) || isset( $fields[ $name ] ) ) continue;
            $fields[ $name ] = array( 'name' => $name, 'type' => $type );
        }
        return $fields;
    }

    // ── Submissions page ─────────────────────────────────────────────────────
    public static function render_submissions_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied' );
		
		// License status banner
        if ( function_exists( 'cf7w_fs' ) ) {
            $fs = cf7w_fs();
            if ( $fs->is_trial() ) {
                echo '<div class="notice notice-info inline" style="margin:16px 0;">'
                   . '<p>⏳ Using free trial<strong> watermark </strong>with reappear when trial ends. '
                   . '<a href="' . esc_url( $fs->get_account_url() ) . '" class="button button-primary" style="margin-left:8px;">Upgrade Now</a>'
                   . '</p></div>';
            } elseif ( ! CF7W_License::is_active() ) {
                echo '<div class="notice notice-error inline" style="margin:16px 0;">'
                   . '<p>⚠️ CF7 PDF Waiver is <strong>inactive</strong> — PDF watermarking is active. '
                   . '<a href="' . esc_url( $fs->get_upgrade_url() ) . '" class="button button-primary" style="margin-left:8px;">Reactivate</a>'
                   . '</p></div>';
            }
        }

        global $wpdb;
        $table = cf7w_db_table();

        // Verify nonce if the search form was submitted; skip on first page load
        // (no nonce present means a fresh unfiltered load, which is safe).
        if ( isset( $_GET['cf7w_search_nonce'] ) ) {
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['cf7w_search_nonce'] ) ), 'cf7w_submissions_search' );
        }

        $search  = sanitize_text_field( wp_unslash( $_GET['s']       ?? '' ) );
        $form_id = absint( wp_unslash( $_GET['form_id']              ?? 0 ) );
        $per     = 25;
        $page    = max( 1, absint( wp_unslash( $_GET['paged']        ?? 1 ) ) );
        $offset  = ( $page - 1 ) * $per;

        $wheres = array( '1=1' );
        $args   = array();
        if ( $form_id ) { $wheres[] = 'form_id = %d'; $args[] = $form_id; }
        if ( $search )  {
            $wheres[] = '(form_data LIKE %s OR ip_address LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $args[] = $like; $args[] = $like;
        }
        $where_sql = implode( ' AND ', $wheres );

        // Build cache key from the query parameters so each unique filter set
        // gets its own cache entry. Cache group is non-persistent (per-request).
        $cache_key   = 'cf7w_submissions_' . md5( $where_sql . serialize( $args ) . $page );
        $cache_group = 'cf7w_submissions';
        $cached      = wp_cache_get( $cache_key, $cache_group );

        if ( false !== $cached ) {
            $total    = $cached['total'];
            $rows     = $cached['rows'];
            $form_ids = $cached['form_ids'];
        } else {
            // Build each query concretely so static analysis can verify every
            // placeholder matches its argument count with no dynamic fragments.
            $t = esc_sql( $table ); // trusted: $wpdb->prefix only, never user input

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- NoCaching satisfied by wp_cache_set below; DirectQuery intentional
            if ( $form_id && $search ) {
                $like   = '%' . $wpdb->esc_like( $search ) . '%';
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is esc_sql() on $wpdb->prefix
                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE form_id = %d AND (form_data LIKE %s OR ip_address LIKE %s)", $form_id, $like, $like ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE form_id = %d AND (form_data LIKE %s OR ip_address LIKE %s) ORDER BY entry_date DESC LIMIT %d OFFSET %d", $form_id, $like, $like, $per, $offset ) );
            } elseif ( $form_id ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is esc_sql() on $wpdb->prefix
                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE form_id = %d", $form_id ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE form_id = %d ORDER BY entry_date DESC LIMIT %d OFFSET %d", $form_id, $per, $offset ) );
            } elseif ( $search ) {
                $like   = '%' . $wpdb->esc_like( $search ) . '%';
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is esc_sql() on $wpdb->prefix
                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE form_data LIKE %s OR ip_address LIKE %s", $like, $like ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE form_data LIKE %s OR ip_address LIKE %s ORDER BY entry_date DESC LIMIT %d OFFSET %d", $like, $like, $per, $offset ) );
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is esc_sql() on $wpdb->prefix
                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t}", array() ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY entry_date DESC LIMIT %d OFFSET %d", $per, $offset ) );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is esc_sql() on $wpdb->prefix
            $form_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT form_id FROM {$t} ORDER BY form_id", array() ) );

            wp_cache_set( $cache_key, array(
                'total'    => $total,
                'rows'     => $rows,
                'form_ids' => $form_ids,
            ), $cache_group );
        }
        $base_url = admin_url( 'admin.php?page=cf7w-submissions' );
        $pages    = ceil( $total / $per );
        ?>
<div class="wrap cf7w-submissions-wrap">
  <h1 style="display:flex;align-items:center;gap:12px;">
    <?php esc_html_e( 'Form Submissions', 'sign-pdf-waiver-for-contact-form-7' ); ?>
    <span style="font-size:13px;font-weight:400;background:#2271b1;color:#fff;border-radius:10px;padding:2px 9px;"><?php echo esc_html( $total ); ?></span>
  </h1>

  <!-- Search bar -->
  <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
    <input type="hidden" name="page" value="cf7w-submissions">
    <?php wp_nonce_field( 'cf7w_submissions_search', 'cf7w_search_nonce' ); ?>
    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
           placeholder="<?php esc_attr_e( 'Search fields or IP…', 'sign-pdf-waiver-for-contact-form-7' ); ?>"
           style="min-width:200px;" class="regular-text">
    <select name="form_id" onchange="this.form.submit()">
      <option value=""><?php esc_html_e( '— All forms —', 'sign-pdf-waiver-for-contact-form-7' ); ?></option>
      <?php foreach ( $form_ids as $fid ) : ?>
        <option value="<?php echo (int) $fid; ?>" <?php selected( $form_id, $fid ); ?>>
          <?php echo esc_html( get_the_title( $fid ) ?: 'Form #' . $fid ); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
    <?php if ( $search || $form_id ) : ?>
      <a href="<?php echo esc_url( $base_url ); ?>" class="button button-link"><?php esc_html_e( 'Clear', 'sign-pdf-waiver-for-contact-form-7' ); ?></a>
    <?php endif; ?>
  </form>

  <?php if ( empty( $rows ) ) : ?>
    <p><?php $search ? esc_html_e( 'No submissions matched.', 'sign-pdf-waiver-for-contact-form-7' ) : esc_html_e( 'No submissions yet.', 'sign-pdf-waiver-for-contact-form-7' ); ?></p>
  <?php else : ?>

  <!-- Batch actions bar -->
  <div class="cf7w-batch-actions" style="margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <label style="font-size:13px;cursor:pointer;display:flex;align-items:center;gap:6px;">
      <input type="checkbox" id="cf7w-select-all" style="vertical-align:middle;margin:0;">
      <?php esc_html_e( 'Select All', 'sign-pdf-waiver-for-contact-form-7' ); ?>
    </label>
    <button type="button" id="cf7w-batch-download" class="button button-primary" disabled>
      <?php esc_html_e( 'Download Selected PDFs', 'sign-pdf-waiver-for-contact-form-7' ); ?>
      (<span id="cf7w-selected-count">0</span>)
    </button>
    <button type="button" id="cf7w-batch-delete" class="button" style="color:#b32d2e;border-color:#b32d2e;" disabled>
      <?php esc_html_e( 'Delete Selected', 'sign-pdf-waiver-for-contact-form-7' ); ?>
    </button>
  </div>

  <table class="wp-list-table widefat fixed striped cf7w-sub-table">
    <colgroup>
      <col class="col-checkbox">
      <col class="col-id">
      <col class="col-form">
      <col class="col-date">
      <col class="col-ip">
      <col><!-- data -->
      <col class="col-pdf">
    </colgroup>
    <thead><tr>
      <th class="col-checkbox">
        <input type="checkbox" class="cf7w-select-all-header" title="<?php esc_attr_e( 'Select All', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
      </th>
      <th class="col-id">#</th>
      <th class="col-form"><?php esc_html_e( 'Form', 'sign-pdf-waiver-for-contact-form-7' ); ?></th>
      <th class="col-date"><?php esc_html_e( 'Date', 'sign-pdf-waiver-for-contact-form-7' ); ?></th>
      <th class="col-ip"><?php esc_html_e( 'IP', 'sign-pdf-waiver-for-contact-form-7' ); ?></th>
      <th><?php esc_html_e( 'Fields', 'sign-pdf-waiver-for-contact-form-7' ); ?></th>
      <th class="col-pdf"><?php esc_html_e( 'PDF', 'sign-pdf-waiver-for-contact-form-7' ); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ( $rows as $row ) :
        $data    = json_decode( $row->form_data, true ) ?: array();
        $pdf_abs = $row->filled_pdf ?? '';
        $pdf_url = '';
        if ( $pdf_abs && file_exists( $pdf_abs ) ) {
            $rel     = ltrim( str_replace( realpath( CF7W_SECURE_DIR ), '', realpath( $pdf_abs ) ), DIRECTORY_SEPARATOR );
            $pdf_url = add_query_arg( array(
                'action' => 'cf7w_serve_file',
                'file'   => rawurlencode( $rel ),
                'nonce'  => wp_create_nonce( 'cf7w_serve_file' ),
            ), admin_url( 'admin-ajax.php' ) );
        }
        $hl      = $search ? preg_quote( $search, '/' ) : '';
    ?>
    <tr data-id="<?php echo (int) $row->id; ?>" data-pdf-url="<?php echo esc_attr( $pdf_url ); ?>">
      <td class="col-checkbox">
        <input type="checkbox" class="cf7w-row-select" value="<?php echo (int) $row->id; ?>">
      </td>
      <td class="col-id"><?php echo (int) $row->id; ?></td>
      <td class="col-form"><?php echo esc_html( get_the_title( $row->form_id ) ?: 'Form #' . $row->form_id ); ?></td>
      <td class="col-date"><?php echo esc_html( date_i18n( 'M j Y g:ia', strtotime( $row->entry_date ) ) ); ?></td>
      <td class="col-ip"><code style="font-size:11px;"><?php echo esc_html( $row->ip_address ); ?></code></td>
      <td>
        <?php if ( empty( $data ) ) : ?>
          <em style="color:#aaa;"><?php esc_html_e( 'none', 'sign-pdf-waiver-for-contact-form-7' ); ?></em>
        <?php else : ?>
        <div class="cf7w-sub-fields">
        <?php foreach ( $data as $lbl => $val ) :
            $is_long  = mb_strlen( $val ) > 120;
            $safe_lbl = esc_html( $lbl );
            $safe_val = esc_html( $val );
            $safe_short = esc_html( mb_substr( $val, 0, 120 ) ) . '…';
            if ( $hl ) {
                $safe_lbl   = preg_replace( '/(' . $hl . ')/i', '<mark>$1</mark>', $safe_lbl );
                $safe_val   = preg_replace( '/(' . $hl . ')/i', '<mark>$1</mark>', $safe_val );
                $safe_short = preg_replace( '/(' . $hl . ')/i', '<mark>$1</mark>', $safe_short );
            }
        ?>
          <?php
          // Only <mark> is allowed — added by the search-highlight preg_replace above.
          $allowed_hl = array( 'mark' => array() );
          ?>
          <div class="cf7w-sub-field">
            <span class="cf7w-sub-lbl"><?php echo wp_kses( $safe_lbl, $allowed_hl ); ?></span>
            <span class="cf7w-sub-val">
              <?php if ( $is_long ) : ?>
                <span class="cf7w-short"><?php echo wp_kses( $safe_short, $allowed_hl ); ?> <button type="button" class="cf7w-expand-btn">more</button></span>
                <span class="cf7w-full" style="display:none"><?php echo wp_kses( $safe_val, $allowed_hl ); ?> <button type="button" class="cf7w-collapse-btn">less</button></span>
              <?php else : ?>
                <?php echo wp_kses( $safe_val, $allowed_hl ); ?>
              <?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </td>
      <td class="col-pdf">
        <?php if ( $pdf_url ) : ?>
          <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
            <a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" class="cf7w-view-btn" title="<?php esc_attr_e( 'View PDF', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
              👁 <?php esc_html_e( 'View', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </a>
            <a href="<?php echo esc_url( $pdf_url ); ?>" download class="cf7w-dl-btn" title="<?php esc_attr_e( 'Download PDF', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
              ↓ <?php esc_html_e( 'Download', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </a>
          </div>
        <?php else : ?>
          <span style="color:#aaa;">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ( $pages > 1 ) :
      $paged_args = array_filter( array( 'page' => 'cf7w-submissions', 's' => $search, 'form_id' => $form_id ?: null ) );
  ?>
  <div class="tablenav" style="margin-top:12px;">
    <?php echo wp_kses_post( paginate_links( array(
        'base'      => add_query_arg( 'paged', '%#%', $base_url ),
        'format'    => '',
        'add_args'  => $paged_args,
        'current'   => $page,
        'total'     => $pages,
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;',
    ) ) ); ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<script>
(function(){
  // Expand/collapse long field values
  document.querySelectorAll('.cf7w-expand-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var val = btn.closest('.cf7w-sub-val');
      val.querySelector('.cf7w-short').style.display = 'none';
      val.querySelector('.cf7w-full').style.display  = '';
    });
  });
  document.querySelectorAll('.cf7w-collapse-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var val = btn.closest('.cf7w-sub-val');
      val.querySelector('.cf7w-full').style.display  = 'none';
      val.querySelector('.cf7w-short').style.display = '';
    });
  });

  var selectAllTop    = document.getElementById('cf7w-select-all');
  var selectAllHeader = document.querySelector('.cf7w-select-all-header');
  var rowCheckboxes   = document.querySelectorAll('.cf7w-row-select');
  var selectedCountEl = document.getElementById('cf7w-selected-count');
  var batchDownloadBtn = document.getElementById('cf7w-batch-download');
  var batchDeleteBtn   = document.getElementById('cf7w-batch-delete');

  function updateSelectedCount() {
    var count = document.querySelectorAll('.cf7w-row-select:checked').length;
    if (selectedCountEl) selectedCountEl.textContent = count;
    if (batchDownloadBtn) batchDownloadBtn.disabled = count === 0;
    if (batchDeleteBtn)   batchDeleteBtn.disabled   = count === 0;

    var allChecked  = rowCheckboxes.length > 0 && count === rowCheckboxes.length;
    var someChecked = count > 0 && count < rowCheckboxes.length;
    if (selectAllTop)    { selectAllTop.checked = allChecked;    selectAllTop.indeterminate = someChecked; }
    if (selectAllHeader) { selectAllHeader.checked = allChecked; selectAllHeader.indeterminate = someChecked; }
  }

  function toggleAll(checked) {
    rowCheckboxes.forEach(function(cb){ cb.checked = checked; });
    updateSelectedCount();
  }

  if (selectAllTop)    selectAllTop.addEventListener('change',    function(){ toggleAll(this.checked); });
  if (selectAllHeader) selectAllHeader.addEventListener('change', function(){ toggleAll(this.checked); });
  rowCheckboxes.forEach(function(cb){ cb.addEventListener('change', updateSelectedCount); });

  // ── Batch download ─────────────────────────────────────────────────────────
  // Collects all selected rows. Rows with no PDF are silently skipped.
  // Uses a HEAD request to verify the file still exists before triggering the
  // download — if it fails (404/error) that entry is skipped gracefully.
  if (batchDownloadBtn) {
    batchDownloadBtn.addEventListener('click', function() {
      var selected = [];
      document.querySelectorAll('.cf7w-row-select:checked').forEach(function(cb) {
        var row    = cb.closest('tr');
        var pdfUrl = row.getAttribute('data-pdf-url');
        selected.push({ id: row.getAttribute('data-id'), url: pdfUrl || '' });
      });

      if (selected.length === 0) {
        alert('<?php echo esc_js( __( 'Please select at least one entry.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>');
        return;
      }

      var withPdf    = selected.filter(function(r){ return r.url !== ''; });
      var withoutPdf = selected.length - withPdf.length;

      if (withPdf.length === 0) {
        alert('<?php echo esc_js( __( 'None of the selected entries have a saved PDF.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>');
        return;
      }

      // Download sequentially with HEAD check — skip missing files gracefully
      var idx = 0;
      function downloadNext() {
        if (idx >= withPdf.length) {
          if (withoutPdf > 0) {
            alert('<?php echo esc_js( __( 'Download complete.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?> ' +
                  withoutPdf + ' <?php echo esc_js( __( 'selected entry/entries had no PDF and were skipped.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>');
          }
          return;
        }
        var item = withPdf[idx++];
        fetch(item.url, { method: 'HEAD' })
          .then(function(r) {
            if (r.ok) {
              var link = document.createElement('a');
              link.href = item.url;
              link.download = 'submission-' + item.id + '.pdf';
              document.body.appendChild(link);
              link.click();
              document.body.removeChild(link);
            }
            // Whether ok or not, continue to next after a short delay
            setTimeout(downloadNext, 400);
          })
          .catch(function() {
            setTimeout(downloadNext, 400);
          });
      }
      downloadNext();
    });
  }

  // ── Batch delete ───────────────────────────────────────────────────────────
  if (batchDeleteBtn) {
    batchDeleteBtn.addEventListener('click', function() {
      var ids = [];
      document.querySelectorAll('.cf7w-row-select:checked').forEach(function(cb) {
        ids.push(parseInt(cb.value, 10));
      });
      if (ids.length === 0) return;

      var msg = '<?php echo esc_js( __( 'Delete', 'sign-pdf-waiver-for-contact-form-7' ) ); ?> ' + ids.length +
                ' <?php echo esc_js( __( 'entry/entries? This cannot be undone.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>';
      if (!confirm(msg)) return;

      batchDeleteBtn.disabled = true;
      batchDeleteBtn.textContent = '<?php echo esc_js( __( 'Deleting…', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>';

      var formData = new FormData();
      formData.append('action', 'cf7w_delete_submissions');
      formData.append('nonce',  '<?php echo esc_js( wp_create_nonce( 'cf7w_delete_submissions' ) ); ?>');
      ids.forEach(function(id){ formData.append('ids[]', id); });

      fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
        method: 'POST',
        body:   formData
      })
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (data.success) {
          // Remove deleted rows from the DOM
          ids.forEach(function(id) {
            var row = document.querySelector('tr[data-id="' + id + '"]');
            if (row) row.remove();
          });
          // Update the total count badge
          var badge = document.querySelector('.cf7w-submissions-wrap h1 span');
          if (badge) {
            var current = parseInt(badge.textContent, 10);
            badge.textContent = Math.max(0, current - data.data.deleted);
          }
          updateSelectedCount();
        } else {
          alert('<?php echo esc_js( __( 'Delete failed. Please try again.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>');
        }
        batchDeleteBtn.disabled = false;
        batchDeleteBtn.textContent = '<?php echo esc_js( __( 'Delete Selected', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>';
      })
      .catch(function() {
        alert('<?php echo esc_js( __( 'Delete failed. Please try again.', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>');
        batchDeleteBtn.disabled = false;
        batchDeleteBtn.textContent = '<?php echo esc_js( __( 'Delete Selected', 'sign-pdf-waiver-for-contact-form-7' ) ); ?>';
      });
    });
  }

  updateSelectedCount();
}());
</script>
        <?php
    }

    // ── Assets ────────────────────────────────────────────────────────────────
    public static function enqueue_assets( string $hook ): void {
        $screen = get_current_screen();
        if ( ! $screen ) return;
        $on_cf7  = false !== strpos( $hook, 'wpcf7' ) || false !== strpos( $screen->id, 'wpcf7' );
        $on_cf7w = false !== strpos( $hook, 'cf7w' );
        if ( ! $on_cf7 && ! $on_cf7w ) return;

        if ( $on_cf7 ) {
            wp_enqueue_media();
            wp_enqueue_script( 'jquery-ui-sortable' );

            // PDF.js bundled locally — assets/vendor/pdfjs/pdf.min.js
            // Download from: https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js
            wp_enqueue_script( 'pdfjs', CF7W_URL . 'assets/vendor/pdfjs/pdf.min.js', array(), '3.11.174', true );
            wp_enqueue_script( 'cf7w-admin', CF7W_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable', 'pdfjs' ), CF7W_VERSION, true );
            wp_localize_script( 'cf7w-admin', 'CF7W_Admin', array(
                'ajax_url'          => admin_url( 'admin-ajax.php' ),
                'nonce'             => wp_create_nonce( 'cf7w_admin_nonce' ),
                'upload_title'      => __( 'Select PDF', 'sign-pdf-waiver-for-contact-form-7' ),
                'upload_button'     => __( 'Use this PDF', 'sign-pdf-waiver-for-contact-form-7' ),
                'parsing'           => __( 'Parsing PDF…', 'sign-pdf-waiver-for-contact-form-7' ),
                'parse_error'       => __( 'Could not extract fields. Add rows manually.', 'sign-pdf-waiver-for-contact-form-7' ),
                'media_unavailable' => __( 'Media library unavailable.', 'sign-pdf-waiver-for-contact-form-7' ),
                'replace_pdf'       => __( 'Replace PDF', 'sign-pdf-waiver-for-contact-form-7' ),
                'pdfjs_worker_url'  => CF7W_URL . 'assets/vendor/pdfjs/pdf.worker.min.js',
            ) );
        }

        wp_enqueue_style( 'cf7w-admin-style', CF7W_URL . 'assets/css/admin.css', array(), CF7W_VERSION );
    }
}
