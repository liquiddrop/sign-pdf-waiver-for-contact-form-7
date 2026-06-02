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
        add_action( 'wp_ajax_cf7w_delete_submission', array( __CLASS__, 'ajax_delete_submission' ) );
		if ( cf7w_fs()->can_use_premium_code__premium_only() ) {
			add_action( 'admin_post_cf7w_bulk_export', array( 'CF7W_Premium', 'handle_bulk_export' ) );
			add_action( 'wp_ajax_cf7w_batch_delete_submissions', array( 'CF7W_Premium', 'ajax_batch_delete_submissions' ) );
			add_action( 'admin_notices', array( 'CF7W_Premium', 'notice_verify_page' ) );
		} // @endif can_use_premium_code__premium_only
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
	
		
        $pdf_url  = $settings['pdf_url']       ?? '';
        $pdf_id   = $settings['pdf_attach_id'] ?? 0;
        $notify   = $settings['notify_email']  ?? get_option( 'admin_email' );
        $attach_pdf = ! empty( $settings['attach_pdf'] );
		$add_audit = ! empty( $settings['add_audit'] );
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


<!-- Step 3 – PDF Filename, Storage-->
<div class="cf7w-admin-section">
  <h3><?php esc_html_e( 'Step 3 – PDF Filename &amp; Storage', 'sign-pdf-waiver-for-contact-form-7' ); ?></h3>

  <p class="description">
    <?php esc_html_e( 'Pattern for the saved PDF filename. Use {PDF Field Name} tokens to include field values. Built-in: {date}, {time}, {form_id}.', 'sign-pdf-waiver-for-contact-form-7' ); ?><br>
    <?php esc_html_e( 'Example: form_{Full Name}_{date} → form_John_Smith_2024-01-15.pdf', 'sign-pdf-waiver-for-contact-form-7' ); ?>
  </p>
  <input type="text" name="cf7w_pdf_filename_scheme"
         value="<?php echo esc_attr( $filename_scheme ); ?>"
         class="regular-text"
         placeholder="form_{form_id}_{date}">

  <br><br><strong><?php esc_html_e( 'Storage', 'sign-pdf-waiver-for-contact-form-7' ); ?></strong><br><br>
  <br>
  <label>
    <input type="checkbox" name="cf7w_save_signature" id="cf7w_save_signature" value="1" <?php checked( $save_signature ); ?>>
    <?php esc_html_e( 'Save signature image to server', 'sign-pdf-waiver-for-contact-form-7' ); ?>
  </label>
  <p class="description" style="margin-top:4px;"><?php esc_html_e( 'When unchecked, the signature PNG is deleted from the server after the PDF has been generated.', 'sign-pdf-waiver-for-contact-form-7' ); ?></p>

</div>

<!-- Step 4 – Premium -->
<div class="cf7w-admin-section">
  <h3><?php esc_html_e( 'Step 4 – Premium', 'sign-pdf-waiver-for-contact-form-7' ); ?></h3>

  <?php if ( cf7w_fs()->can_use_premium_code__premium_only() ) : ?>
    
	<?php $create_url = admin_url(
        'post-new.php?post_type=page&post_title=Verify+PDF&content=[cf7w_verify]'
    ); ?>
	
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row" style="width:200px;">
          <?php esc_html_e( 'Email PDF Attachment', 'sign-pdf-waiver-for-contact-form-7' ); ?>
          <span style="background:#f0b849;color:#000;font-size:10px;font-weight:700;
                       padding:1px 5px;border-radius:3px;margin-left:4px;">
            ⭐ PREMIUM
          </span>
        </th>
        <td>
          <label>
            <input type="checkbox" name="cf7w_attach_pdf" value="1"
                   <?php checked( $attach_pdf ); ?>>
            <?php esc_html_e( 'Attach filled PDF to Contact Forms\'s outgoing emails', 'sign-pdf-waiver-for-contact-form-7' ); ?>
          </label>
          <p class="description">
            <?php esc_html_e( 'Attaches the completed PDF to the admin notification and submitter confirmation emails configured in the Mail tab.', 'sign-pdf-waiver-for-contact-form-7' ); ?>
          </p>
        </td>
      </tr>
	  <tr>
        <th scope="row" style="width:220px;">
          <?php esc_html_e( 'Save filled PDF', 'sign-pdf-waiver-for-contact-form-7' ); ?>
        </th>
        <td>
          <label>
            <input type="checkbox" name="cf7w_save_pdf" value="1"
                   <?php checked( $settings['save_pdf'] ?? true ); ?>>
            <?php esc_html_e( 'Save filled PDF to server storage', 'sign-pdf-waiver-for-contact-form-7' ); ?>
          </label>
          <p class="description">
            <?php esc_html_e( 'When unchecked, the filled pdf is deleted from the server immediately after emails are sent. The database record will still exist', 'sign-pdf-waiver-for-contact-form-7' ); ?>
			<p class="description" style="margin-top:12px;">
			  <strong><?php esc_html_e( 'Note:', 'sign-pdf-waiver-for-contact-form-7' ); ?></strong>
			  <?php esc_html_e( 'If neither &quot;Save filled PDF&quot; nor &quot;Attach PDF to email&quot; are enabled, no record of the filled PDF will be kept.', 'sign-pdf-waiver-for-contact-form-7' ); ?>
		    </p>
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row">
          <?php esc_html_e( 'Email Certificate PDF attachment', 'sign-pdf-waiver-for-contact-form-7' ); ?>
          <span style="background:#f0b849;color:#000;font-size:10px;font-weight:700;
                       padding:1px 5px;border-radius:3px;margin-left:4px;">
            ⭐ PREMIUM
          </span>
        </th>
        <td>
		  <label>
            <input type="checkbox" name="cf7w_add_audit" value="1"
                   <?php checked( $add_audit ); ?>>
            <?php esc_html_e( 'Attach certificate of completion PDF to Contact Forms\'s outgoing emails', 'sign-pdf-waiver-for-contact-form-7' ); ?>
          </label>
          <p class="description">
            <?php esc_html_e( 'Attaches a certificate of completion PDF to the admin notification and submitter confirmation emails configured in the Mail tab.', 'sign-pdf-waiver-for-contact-form-7' ); ?>
          </p>
		  <p class="description" style="margin-top:12px;">
			  <strong><?php esc_html_e( 'Note:', 'sign-pdf-waiver-for-contact-form-7' ); ?></strong>
			  <?php esc_html_e( 'A certificate of completion is always generated and saved to the database', 'sign-pdf-waiver-for-contact-form-7' ); ?>
		    </p>
        </td>
      </tr>
      <tr>
		<p class="description" style="margin-top:12px;">
            <strong><?php esc_html_e( 'Verification page where users can upload the signed pdf and verify it hasn\'t changed.', 'sign-pdf-waiver-for-contact-form-7' ); ?></strong>
            &nbsp;
            <a href="<?php echo esc_url( $create_url ); ?>" class="button button-small">
                <?php esc_html_e( 'Create Verification Page', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </a>
            &nbsp;
            <em style="font-size:12px;color:#666;">
                <?php esc_html_e( 'Add [cf7w_verify] to any page manually if you prefer.', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </em>
        </p>
      </tr>	  
    </table>

  <?php else : // @else can_use_premium_code__premium_only ?>

    <div style="background:#f0f6fc;border:1px solid #c3d9ed;border-radius:4px;
                padding:16px 18px;line-height:1.6;">
      <p style="margin:0 0 10px;font-size:13px;font-weight:700;">
        ⭐ <?php esc_html_e( 'Premium Delivery Features', 'sign-pdf-waiver-for-contact-form-7' ); ?>
      </p>
      <p style="margin:0 0 4px;font-size:13px;">
        <?php esc_html_e( 'Upgrade to unlock:', 'sign-pdf-waiver-for-contact-form-7' ); ?>
      </p>
      <ul style="margin:4px 0 12px;padding-left:20px;font-size:13px;">
        <li><?php esc_html_e( 'Attach signed PDF to admin and submitter emails', 'sign-pdf-waiver-for-contact-form-7' ); ?></li>
		<li><?php esc_html_e( 'Tamper-proof pdfs with added verification page shortcode', 'sign-pdf-waiver-for-contact-form-7' ); ?></li>
		<li><?php esc_html_e( 'Generate court ready Certificate of Completion with audit trail', 'sign-pdf-waiver-for-contact-form-7' ); ?></li>
		<li><?php esc_html_e( 'Bulk export/delete to fit buisness workflow', 'sign-pdf-waiver-for-contact-form-7' ); ?></li>
      </ul>
      <?php if ( function_exists( 'cf7w_fs' ) ) : ?>
        <a href="<?php echo esc_url( cf7w_fs()->get_upgrade_url() ); ?>"
           class="button button-primary" style="font-size:13px;">
          <?php esc_html_e( 'Upgrade to Premium', 'sign-pdf-waiver-for-contact-form-7' ); ?>
        </a>
      <?php endif; ?>
    </div>

  <?php endif; // @endif can_use_premium_code__premium_only ?>

</div><!-- Step 4 -->

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
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- individual sub-fields are sanitized below during foreach iteration
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
			'add_audit'                => ! empty( $_POST['cf7w_add_audit'] ),
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
			// premium settings — saved for all users, only used when licensed
			'external_storage'         => ( static function() {
											$val = sanitize_key( wp_unslash( $_POST['cf7w_external_storage'] ?? '' ) );
											return in_array( $val, array( '', 'google_drive', 'dropbox' ), true ) ? $val : '';
										} )(),
        ) );
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
	
	// delete a submission from the database and get rid of the pdf
	public static function ajax_delete_submission(): void {
        check_ajax_referer( 'cf7w_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Access denied.' ) );
        }

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'Invalid submission ID.' ) );
        }

        global $wpdb;

        // Get the filled PDF path before deleting the row so we can remove the file
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT filled_pdf FROM ' . cf7w_db_table() . ' WHERE id = %d LIMIT 1',
            $id
        ) );

        // Delete the DB row
        $deleted = $wpdb->delete(
            cf7w_db_table(),
            array( 'id' => $id ),
            array( '%d' )
        );

        if ( ! $deleted ) {
            wp_send_json_error( array( 'message' => 'Submission not found.' ) );
        }

        // Delete the filled PDF file from disk if it exists
        if ( $row && ! empty( $row->filled_pdf ) && file_exists( $row->filled_pdf ) ) {
            @unlink( $row->filled_pdf );
        }

        wp_send_json_success( array( 'message' => 'Submission deleted.' ) );
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
   <?php if ( cf7w_fs()->can_use_premium_code__premium_only() ) : ?>
   <?php 
	$verify_page_url = '';
	global $wpdb;
	$verify_page_id = $wpdb->get_var(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		 AND post_type = 'page'
		 AND post_content LIKE '%cf7w_verify%'
		 LIMIT 1"
	);
	if ( $verify_page_id ) {
		$verify_page_url = get_permalink( $verify_page_id );
	}
	if ( $verify_page_url ) : ?>
	  <p style="margin:4px 0 16px;font-size:13px;color:#50575e;">
		<?php esc_html_e( 'Verification page:', 'sign-pdf-waiver-for-contact-form-7' ); ?>
		<a href="<?php echo esc_url( $verify_page_url ); ?>" target="_blank">
		  <?php echo esc_url( $verify_page_url ); ?>
		</a>
		<span style="color:#aaa;margin-left:6px;font-size:11px;">
		  <?php esc_html_e( '— share this URL with signers who need to verify their document', 'sign-pdf-waiver-for-contact-form-7' ); ?>
		</span>
	  </p>
	<?php endif;?>
	<?php endif; // @endif can_use_premium_code__premium_only ?>

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
	<?php if ( cf7w_fs()->can_use_premium_code__premium_only() ) : ?>
	  <label style="font-size:13px;cursor:pointer;display:flex;align-items:center;gap:6px;">
        <input type="checkbox" id="cf7w-select-all" style="vertical-align:middle;margin:0;">
        <?php esc_html_e( 'Select All', 'sign-pdf-waiver-for-contact-form-7' ); ?>
      </label>
	  <div style="display:flex;align-items:center;gap:10px;">
		  <button type="button" id="cf7w-batch-download" class="button button-primary" disabled>
			<?php esc_html_e( 'Export as ZIP', 'sign-pdf-waiver-for-contact-form-7' ); ?>
			(<span id="cf7w-selected-count">0</span>)
		  </button>
		  <button type="button" id="cf7w-batch-delete" class="button" style="color:#b32d2e;border-color:#b32d2e;" disabled>
		    <?php esc_html_e( 'Delete Selected', 'sign-pdf-waiver-for-contact-form-7' ); ?>
			(<span id="cf7w-selected-count">0</span>)
		  </button>
	  </div>
	<?php else : // @else can_use_premium_code__premium_only ?>
	  <div style="margin-bottom:12px;">
	    <?php if ( function_exists( 'cf7w_fs' ) ) : ?>
		  <a href="<?php echo esc_url( cf7w_fs()->get_upgrade_url() ); ?>" style="margin-left:8px;margin-right:8px;">
			<?php esc_html_e( ' Upgrade to enable bulk export and delete ', 'sign-pdf-waiver-for-contact-form-7' ); ?>
		  </a>
		<?php endif; ?>
		<span class="button button-primary" style="opacity:0.5;cursor:default;">
		  <?php esc_html_e( 'Export as ZIP (Premium)', 'sign-pdf-waiver-for-contact-form-7' ); ?>
		  (<span id="cf7w-selected-count">0</span>)
		</span>
		<button type="button" id="cf7w-batch-delete" class="button" style="color:#b32d2e;border-color:#b32d2e;" disabled>
		    <?php esc_html_e( ' Delete Selected ', 'sign-pdf-waiver-for-contact-form-7' ); ?>
			(<span id="cf7w-selected-count">0</span>)
		</button>
	  </div>
	<?php endif; // @endif can_use_premium_code__premium_only ?>
    
  </div>

  <table class="wp-list-table widefat fixed striped cf7w-sub-table">
    <colgroup>
      <?php if ( cf7w_fs()->can_use_premium_code__premium_only() ) : // @if can_use_premium_code__premium_only ?>
      <col class="col-checkbox">
      <?php endif; // @endif can_use_premium_code__premium_only ?>
      <col class="col-id">
      <col class="col-form">
      <col class="col-date">
      <col class="col-ip">
      <col><!-- data -->
      <col class="col-pdf">
      <?php if ( cf7w_fs()->can_use_premium_code__premium_only() ) : // @if can_use_premium_code__premium_only ?>
      <col class="col-cert">
      <?php endif; // @endif can_use_premium_code__premium_only ?>
      <col class="col-actions">
    </colgroup>
    <thead><tr>
      <?php if ( cf7w_fs()->can_use_premium_code__premium_only() ) : // @if can_use_premium_code__premium_only ?>
      <th class="col-checkbox">
        <input type="checkbox" class="cf7w-select-all-header"
               title="<?php esc_attr_e( 'Select All', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
      </th>
      <?php endif; // @endif can_use_premium_code__premium_only ?>
      <th class="col-id">#</th>
      <th class="col-form"><?php esc_html_e( 'Form',   'sign-pdf-waiver-for-contact-form-7' ); ?></th>
      <th class="col-date"><?php esc_html_e( 'Date',   'sign-pdf-waiver-for-contact-form-7' ); ?></th>
      <th class="col-ip"><?php   esc_html_e( 'IP',     'sign-pdf-waiver-for-contact-form-7' ); ?></th>
      <th><?php                  esc_html_e( 'Fields', 'sign-pdf-waiver-for-contact-form-7' ); ?></th>
      <th class="col-pdf"><?php  esc_html_e( 'PDF',    'sign-pdf-waiver-for-contact-form-7' ); ?></th>
      <?php if ( cf7w_fs()->can_use_premium_code__premium_only() ) : // @if can_use_premium_code__premium_only ?>
      <th class="col-cert"><?php esc_html_e( 'Certificate', 'sign-pdf-waiver-for-contact-form-7' ); ?></th>
      <?php endif; // @endif can_use_premium_code__premium_only ?>
      <th class="col-actions"><?php esc_html_e( 'Actions', 'sign-pdf-waiver-for-contact-form-7' ); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ( $rows as $row ) :
        $data     = json_decode( $row->form_data, true ) ?: array();
        $pdf_abs  = $row->filled_pdf ?? '';
        $pdf_url  = $pdf_abs ? str_replace( WP_CONTENT_DIR, content_url(), $pdf_abs ) : '';
        $cert_abs = $row->cert_pdf  ?? '';
        $cert_url = $cert_abs ? str_replace( WP_CONTENT_DIR, content_url(), $cert_abs ) : '';
        $hl       = $search ? preg_quote( $search, '/' ) : '';
    ?>
    <tr data-id="<?php echo (int) $row->id; ?>"
        data-pdf-url="<?php echo esc_attr( $pdf_url ); ?>">

      <?php if ( cf7w_fs()->can_use_premium_code__premium_only() ) : // @if can_use_premium_code__premium_only ?>
      <td class="col-checkbox">
        <input type="checkbox" class="cf7w-row-select"
               value="<?php echo (int) $row->id; ?>">
      </td>
      <?php endif; // @endif can_use_premium_code__premium_only ?>

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
            $is_long    = mb_strlen( $val ) > 120;
            $safe_lbl   = esc_html( $lbl );
            $safe_val   = esc_html( $val );
            $safe_short = esc_html( mb_substr( $val, 0, 120 ) ) . '…';
            if ( $hl ) {
                $safe_lbl   = preg_replace( '/(' . $hl . ')/i', '<mark>$1</mark>', $safe_lbl );
                $safe_val   = preg_replace( '/(' . $hl . ')/i', '<mark>$1</mark>', $safe_val );
                $safe_short = preg_replace( '/(' . $hl . ')/i', '<mark>$1</mark>', $safe_short );
            }
        ?>
          <div class="cf7w-sub-field">
            <span class="cf7w-sub-lbl"><?php echo $safe_lbl; ?></span>
            <span class="cf7w-sub-val">
              <?php if ( $is_long ) : ?>
                <span class="cf7w-short"><?php echo $safe_short; ?>
                  <button type="button" class="cf7w-expand-btn">more</button>
                </span>
                <span class="cf7w-full" style="display:none"><?php echo $safe_val; ?>
                  <button type="button" class="cf7w-collapse-btn">less</button>
                </span>
              <?php else : ?>
                <?php echo $safe_val; ?>
              <?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </td>

      <!-- PDF column -->
      <td class="col-pdf">
        <?php if ( $pdf_url ) : ?>
          <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
            <a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank"
               class="cf7w-view-btn"
               title="<?php esc_attr_e( 'View PDF', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
              &#128065; <?php esc_html_e( 'View', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </a>
            <a href="<?php echo esc_url( $pdf_url ); ?>" download
               class="cf7w-dl-btn"
               title="<?php esc_attr_e( 'Download PDF', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
              &#8595; <?php esc_html_e( 'Download', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </a>
          </div>
        <?php else : ?>
          <span style="color:#aaa;">&#8212;</span>
        <?php endif; ?>
      </td>

      <!-- Certificate column — premium only -->
      <?php if ( cf7w_fs()->can_use_premium_code__premium_only() ) : // @if can_use_premium_code__premium_only ?>
      <td class="col-cert">
        <?php if ( $cert_url ) : ?>
          <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
            <a href="<?php echo esc_url( $cert_url ); ?>" target="_blank"
               class="cf7w-view-btn"
               title="<?php esc_attr_e( 'View Certificate', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
              &#128065; <?php esc_html_e( 'View', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </a>
            <a href="<?php echo esc_url( $cert_url ); ?>" download
               class="cf7w-dl-btn"
               title="<?php esc_attr_e( 'Download Certificate', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
              &#8595; <?php esc_html_e( 'Download', 'sign-pdf-waiver-for-contact-form-7' ); ?>
            </a>
          </div>
        <?php else : ?>
          <span style="color:#aaa;">&#8212;</span>
        <?php endif; ?>
      </td>
      <?php endif; // @endif can_use_premium_code__premium_only ?>

      <!-- Actions column — delete button for all users -->
      <td class="col-actions">
        <button type="button"
                class="cf7w-delete-btn button"
                data-id="<?php echo (int) $row->id; ?>"
                style="color:#b32d2e;border-color:#b32d2e;font-size:11px;
                       padding:2px 8px;white-space:nowrap;"
                title="<?php esc_attr_e( 'Delete this submission', 'sign-pdf-waiver-for-contact-form-7' ); ?>">
          &#128465; <?php esc_html_e( 'Delete', 'sign-pdf-waiver-for-contact-form-7' ); ?>
        </button>
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

        if ( $on_cf7w ) {
            wp_enqueue_script(
                'cf7w-submissions',
                CF7W_URL . 'assets/js/submissions.js',
                array(),
                CF7W_VERSION,
                true
            );
            wp_localize_script( 'cf7w-submissions', 'CF7W_Admin', array(
                'nonce'                 => wp_create_nonce( 'cf7w_admin_nonce' ),
                'bulk_export_nonce'     => wp_create_nonce( 'cf7w_bulk_export' ),
                'admin_post_url'        => admin_url( 'admin-post.php' ),
                'is_premium'            => function_exists( 'cf7w_fs' ) && cf7w_fs()->can_use_premium_code__premium_only() ? 1 : 0,
                'i18n' => array(
                    'confirm_delete_single' => __( 'Delete this submission? This cannot be undone.', 'sign-pdf-waiver-for-contact-form-7' ),
                    'confirm_delete_batch'  => __( 'Delete the selected submissions? This cannot be undone.', 'sign-pdf-waiver-for-contact-form-7' ),
                    'deleting'              => __( 'Deleting…', 'sign-pdf-waiver-for-contact-form-7' ),
                    'delete_label'          => __( 'Delete', 'sign-pdf-waiver-for-contact-form-7' ),
                    'delete_selected_label' => __( 'Delete Selected', 'sign-pdf-waiver-for-contact-form-7' ),
                    'delete_failed'         => __( 'Delete failed.', 'sign-pdf-waiver-for-contact-form-7' ),
                    'network_error'         => __( 'Network error. Please try again.', 'sign-pdf-waiver-for-contact-form-7' ),
                    'select_first'          => __( 'Please select submissions first.', 'sign-pdf-waiver-for-contact-form-7' ),
                ),
            ) );
        }

        wp_enqueue_style( 'cf7w-admin-style', CF7W_URL . 'assets/css/admin.css', array(), CF7W_VERSION );
    }
	
}
