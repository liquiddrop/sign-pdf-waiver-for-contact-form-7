<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( cf7w_fs()->can_use_premium_code__premium_only() ) {
    require_once CF7W_DIR . 'includes/class-premium__premium_only.php';
} // @endif can_use_premium_code__premium_only

/**
 * CF7W_Submission_Handler
 *
 * Hooks into wpcf7_before_send_mail — the same approach used by PDF Forms Filler.
 * Reads posted data via $submission->get_posted_data(), fills the PDF, attaches it,
 * emails it to admin + submitter, and logs to DB.
 */
class CF7W_Submission_Handler {

    public static function init(): void {
        // Hook into CF7's mail pipeline — runs after validation, before sending
        add_action( 'wpcf7_before_send_mail', array( __CLASS__, 'process' ), 10, 3 );

        // Validate the signature field isn't empty when required
        add_filter( 'wpcf7_validate_cf7w_signature*', array( __CLASS__, 'validate_signature' ), 10, 2 );
		
		// Validate the consent checkbox for any signature field that has consent enabled.
        // CF7 doesn't know about cf7w_consent_* fields so we hook into wpcf7_validate
        // which fires for every submission and lets us add custom invalidations.
        add_filter( 'wpcf7_validate', array( __CLASS__, 'validate_consent' ), 10, 2 );
    }

    // ── Signature validation ───────────────────────────────────────────────────
    public static function validate_signature( $result, $tag ) {
        $value = isset( $_POST[ $tag->name ] ) ? sanitize_text_field( trim( wp_unslash( $_POST[ $tag->name ] ) ) ) : '';
        if ( empty( $value ) || $value === 'data:,' ) {
            $result->invalidate( $tag, __( 'Please provide your signature.', 'sign-pdf-waiver-for-contact-form-7' ) );
        }
        return $result;
    }
	
	// ── Consent checkbox validation ────────────────────────────────────────────
    // Fires for every CF7 submission. Checks every cf7w_consent_* POST key
    // and invalidates the corresponding signature field if unchecked.
    public static function validate_consent( $result, $tags ) {
        foreach ( $tags as $tag ) {
            if ( $tag->basetype !== 'cf7w_signature' ) continue;
            if ( ! $tag->get_option( 'consent', '', true ) ) continue;

            $consent_name = 'cf7w_consent_' . $tag->name;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified in process(); validate_consent() is a CF7 validation filter that runs inside CF7's own nonce-verified pipeline.
            $checked      = ! empty( $_POST[ $consent_name ] ) && '1' === sanitize_key( wp_unslash( $_POST[ $consent_name ] ) );

            if ( ! $checked ) {
                // Invalidate against the consent field name so CF7 shows
                // the error next to the checkbox, not the signature canvas.
                $result->invalidate( $tag, __( 'You must agree to sign electronically before signing.', 'sign-pdf-waiver-for-contact-form-7' ) );
            }
        }
        return $result;
    }

    // ── Main hook ──────────────────────────────────────────────────────────────
    // Verify plugin-owned nonce before reading any $_POST data
    public static function process( $contact_form, &$abort, $submission ) {
        // Verify our own nonce. wp_create_nonce() was called server-side when the
		// form was rendered, so this is safe even for logged-out users.
		$nonce = isset( $_POST['cf7w_submission_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['cf7w_submission_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'cf7w_submission' ) ) {
			return; // Nonce missing or invalid — do not process
		}

		$post_id  = $contact_form->id();
		$settings = get_post_meta( $post_id, '_cf7w_settings', true );
		if ( empty( $settings ) ) return;
        // Continue if there are mappings OR visual placements — either requires processing
        $has_mappings = ! empty( $settings['mappings'] );
        $has_vp       = ! empty( $settings['visual_placements'] ) && ! empty( $settings['visual_placement_enabled'] );
        if ( ! $has_mappings && ! $has_vp ) return;

        $mappings = $settings['mappings'] ?? array();

        // ── Collect submitted values keyed by CF7 field name ───────────────────
        $posted = array();  // cf7_field => value  (for form_data / email log)
        $pdf_data = array(); // pdf_field => value  (for PDF filler)
        $signature_data  = '';
        $submitter_email = '';
        $form_data       = array();

        foreach ( $mappings as $m ) {
            $cf7_field_raw = $m['cf7_field'] ?? '';          // original name as stored in mappings + VP placements
            $cf7_field     = sanitize_key( $cf7_field_raw ); // CF7 normalises field names the same way
            $pdf_field     = $m['pdf_field'] ?? '';
            $label         = $m['label']     ?? $pdf_field;
            if ( ! $cf7_field || ! $pdf_field ) continue;

            // Try get_posted_data() first (CF7 >= 5.4 preferred API)
            $raw = $submission->get_posted_data( $cf7_field );

            // Fallback: if get_posted_data returned nothing, read directly from $_POST.
            // This handles edge cases where CF7 hasn't yet populated its internal store
            // when our hook fires (e.g. certain CF7 version combinations).
            if ( ( $raw === null || $raw === '' || $raw === array() )
                 && isset( $_POST[ $cf7_field ] ) ) {
                $raw = wp_unslash( $_POST[ $cf7_field ] );
            }
            // Also try original (un-sanitized) name in $_POST
            if ( ( $raw === null || $raw === '' || $raw === array() )
                 && $cf7_field_raw !== $cf7_field
                 && isset( $_POST[ $cf7_field_raw ] ) ) {
                $raw = wp_unslash( $_POST[ $cf7_field_raw ] );
            }

            if ( is_array( $raw ) ) {
                $value = implode( ', ', array_map( 'sanitize_text_field', $raw ) );
            } else {
                $value = sanitize_text_field( (string) ( $raw ?? '' ) );
            }

            // Store under both sanitized and original names so VP lookup always matches
            $posted[ $cf7_field ]    = $value;
            $posted[ $cf7_field_raw ] = $value;
            $pdf_data[ $pdf_field ]   = $value;
            $form_data[ $label ]      = $value;

            if ( ! $submitter_email && filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
                $submitter_email = $value;
            }
        }

        // Signature comes from a hidden input named after the cf7w_signature tag
        foreach ( $contact_form->scan_form_tags() as $tag ) {
            if ( $tag->basetype === 'cf7w_signature' && ! empty( $tag->name ) ) {
                $sig = $submission->get_posted_data( $tag->name );
                if ( $sig ) { $signature_data = (string) $sig; break; }
            }
        }
        // Also check raw POST as fallback
        if ( ! $signature_data ) {
            foreach ( wp_unslash( $_POST ) as $key => $val ) {
                $key = sanitize_key( $key );
                if ( false !== strpos( $key, 'signature' ) && is_string( $val ) && 0 === strpos( $val, 'data:image' ) ) {
                    $signature_data = sanitize_text_field( $val );
                    break;
                }
            }
        }

        // ── Save signature PNG ─────────────────────────────────────────────────
        $sig_path = self::save_signature( $signature_data, $post_id );

        // Auto-detect which PDF field the signature maps to by matching the
        // cf7w_signature tag name against the cf7_field column in mappings.
        $sig_pdf_field = $settings['sig_pdf_field'] ?? ''; // explicit override
        if ( ! $sig_pdf_field ) {
            foreach ( $contact_form->scan_form_tags() as $tag ) {
                if ( $tag->basetype === 'cf7w_signature' && ! empty( $tag->name ) ) {
                    foreach ( $mappings as $m ) {
                        if ( ( $m['cf7_field'] ?? '' ) === $tag->name ) {
                            $sig_pdf_field = $m['pdf_field'] ?? '';
                            break 2;
                        }
                    }
                }
            }
        }
        // Inject into settings so fill() can read it
        $settings['sig_pdf_field'] = $sig_pdf_field;

        // ── Collect signature field names and VP-based placement if set ──────────
        $sig_field_names    = array();
        $sig_vp_placement   = null; // VP placement coords for signature, if positioned via VP
        foreach ( $contact_form->scan_form_tags() as $tag ) {
            if ( $tag->basetype === 'cf7w_signature' && ! empty( $tag->name ) ) {
                $sig_field_names[] = $tag->name;
                $sig_field_names[] = sanitize_key( $tag->name );
            }
        }
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W sig detection: sig_field_names=[' . implode(',', $sig_field_names) . ']' );

        // Match VP placements to signature fields.
        // VP placements store the field name as entered in the admin (may include ID suffix
        // like "cf7w_signature-976"). scan_form_tags() returns the canonical name.
        // We match on: exact name, sanitized name, and prefix up to the first hyphen-digit.
        $vp_sig_candidates = array();
        foreach ( $sig_field_names as $sfn ) {
            $vp_sig_candidates[] = $sfn;
            // Also add base name without trailing "-NNN" (e.g. "cf7w_signature" from "cf7w_signature-976")
            $base = preg_replace( '/-\d+$/', '', $sfn );
            if ( $base !== $sfn ) $vp_sig_candidates[] = $base;
        }
        // Also collect ALL tag names so we can match "cf7w_signature-976" style names
        foreach ( $contact_form->scan_form_tags() as $tag ) {
            if ( $tag->basetype === 'cf7w_signature' ) {
                // The full name with ID suffix as it appears in the form shortcode
                $full_name = ( ! empty( $tag->raw_name ) ) ? $tag->raw_name : $tag->name;
                $vp_sig_candidates[] = $full_name;
                $vp_sig_candidates[] = sanitize_key( $full_name );
            }
        }
        $vp_sig_candidates = array_unique( $vp_sig_candidates );
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W sig detection: vp_sig_candidates=[' . implode(',', $vp_sig_candidates) . ']' );

        foreach ( ( $settings['visual_placements'] ?? array() ) as $vp_pl ) {
            $vp_cf7 = $vp_pl['cf7_field'] ?? '';
            if ( in_array( $vp_cf7, $vp_sig_candidates, true )
              || in_array( sanitize_key( $vp_cf7 ), $vp_sig_candidates, true ) ) {
                $sig_vp_placement = $vp_pl;
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W sig detection: matched VP placement cf7_field=' . $vp_cf7 );
                break;
            }
        }
        if ( ! $sig_vp_placement ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W sig detection: no VP placement matched. VP fields=[' . implode(',', array_column($settings['visual_placements'] ?? array(), 'cf7_field')) . ']' );
        }
        if ( $sig_vp_placement ) {
            $settings['sig_vp_placement'] = $sig_vp_placement;
        }
        // Store sig field names so stamp_visual_placements can identify them
        $settings['sig_field_names'] = $sig_field_names;

        // ── Build VP field values independently of mappings ───────────────────
        // Walk every VP placement and collect its CF7 field value directly.
        $vp_placements_list = $settings['visual_placements'] ?? array();
        $cf7_field_values   = $posted; // seed with values already collected via mappings

        foreach ( $vp_placements_list as $vp_pl ) {
            $vp_field_name = $vp_pl['cf7_field'] ?? '';
            if ( $vp_field_name === '' ) continue;

            // Signature fields are stamped as images by stamp_signature — skip here
            if ( in_array( $vp_field_name, $sig_field_names, true ) ||
                 in_array( sanitize_key( $vp_field_name ), $sig_field_names, true ) ) {
                continue;
            }

            $vp_sanitized = sanitize_key( $vp_field_name );

            // Skip if we already have a non-empty value for this field
            if ( ( isset( $cf7_field_values[ $vp_field_name ] ) && $cf7_field_values[ $vp_field_name ] !== '' ) ||
                 ( isset( $cf7_field_values[ $vp_sanitized ]   ) && $cf7_field_values[ $vp_sanitized ]   !== '' ) ) {
                // Still add to form_data if missing (field was in mappings already, label may differ)
                if ( ! isset( $form_data[ $vp_field_name ] ) ) {
                    $existing = $cf7_field_values[ $vp_field_name ] ?? $cf7_field_values[ $vp_sanitized ] ?? '';
                    $form_data[ $vp_field_name ] = $existing;
                }
                continue;
            }

            // Read value — try every source in priority order.
            // $_POST is checked first because get_posted_data() only works for fields
            // that have AcroForm mappings registered; pure-VP fields may be absent from
            // CF7's internal posted-data store even though the value is in $_POST.
            $vp_raw = null;
            foreach ( array( $vp_field_name, $vp_sanitized ) as $_k ) {
                if ( isset( $_POST[ $_k ] ) && $_POST[ $_k ] !== '' ) {
                    $vp_raw = wp_unslash( $_POST[ $_k ] ); break;
                }
            }
            if ( $vp_raw === null || $vp_raw === '' || $vp_raw === array() ) {
                $vp_raw = $submission->get_posted_data( $vp_field_name );
            }
            if ( $vp_raw === null || $vp_raw === '' || $vp_raw === array() ) {
                $vp_raw = $submission->get_posted_data( $vp_sanitized );
            }

            $vp_value = is_array( $vp_raw )
                ? implode( ', ', array_map( 'sanitize_text_field', $vp_raw ) )
                : sanitize_text_field( (string) ( $vp_raw ?? '' ) );

            // Store under both raw and sanitized keys
            $cf7_field_values[ $vp_field_name ] = $vp_value;
            $cf7_field_values[ $vp_sanitized ]  = $vp_value;

            // Also add to form_data so submissions tab shows these fields
            if ( ! isset( $form_data[ $vp_field_name ] ) ) {
                $form_data[ $vp_field_name ] = $vp_value;
            }
        }

        // Inject into settings so fill() can use them
        $settings['cf7_field_values'] = $cf7_field_values;

        // ── Diagnostic logging ────────────────────────────────────────────────
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W submission: mappings=' . count($mappings)
            . ' cf7_field_values=' . count($cf7_field_values)
            . ' keys=[' . implode(',', array_keys($cf7_field_values)) . ']'
            . ' values=[' . implode(',', array_map(function($v){return substr((string)$v,0,20);}, $cf7_field_values)) . ']' );
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W submission: sig_field_names=[' . implode(',', $sig_field_names) . ']'
            . ' sig_vp_placement=' . ($sig_vp_placement ? json_encode(array_diff_key($sig_vp_placement,array('canvas_w'=>1,'canvas_h'=>1))) : 'null') );
        foreach ( ($settings['visual_placements'] ?? array()) as $i => $pl ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W vp_pl[' . $i . ']: cf7_field=' . ($pl['cf7_field']??'') . ' value=' . substr((string)($cf7_field_values[$pl['cf7_field']??''] ?? $cf7_field_values[sanitize_key($pl['cf7_field']??'')] ?? 'MISSING'),0,30) );
        }
        $filled_pdf = CF7W_PDF_Filler::fill( $post_id, $settings, $mappings, $pdf_data, $sig_path );

        // ── Hash the filled PDF before doing anything else with it ─────────────
        // The hash is taken here — after fill() has produced the final PDF and
        // before anything modifies or emails it. This is the canonical hash that
        // goes into the DB and into the verification email.
        // Stored separately from the PDF so it arrives via a different channel
        // (email) which makes it stronger evidence of the document state at signing.
        $doc_hash = '';
        if ( $filled_pdf && file_exists( $filled_pdf ) ) {
            $doc_hash = hash( 'sha256', file_get_contents( $filled_pdf ) );
        }

        // ── Log to DB ──────────────────────────────────────────────────────────
        global $wpdb;
        // Flush submissions list cache so the admin page reflects the new entry.
        wp_cache_flush_group( 'cf7w_submissions' );

        // Store the signature file path in the DB (not the raw base64 blob —
        // it's already embedded in the filled PDF and is large/redundant in the DB).
        $wpdb->insert( cf7w_db_table(), array(
            'form_id'    => $post_id,
            'entry_date' => current_time( 'mysql' ),
            'ip_address' => self::get_ip(),
            'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
            'form_data'  => wp_json_encode( $form_data ),
            'signature'  => $signature_data,
            'filled_pdf' => $filled_pdf ?: '',
            'doc_hash'   => $doc_hash,
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        $log_id = $wpdb->insert_id;
		
        // Defaults to true (keep) for backwards-compatibility.
        $save_pdf       = isset( $settings['save_pdf'] )       ? (bool) $settings['save_pdf']       : true;
        $save_signature = isset( $settings['save_signature'] ) ? (bool) $settings['save_signature'] : true;

        // ── [PREMIUM] Attach PDF + append audit trail to CF7 email body ────────
        if ( cf7w_fs()->can_use_premium_code__premium_only() ) {
			
			// Build the certificate of completion PDF
            $cert_path = '';
            if ( $filled_pdf && file_exists( $filled_pdf ) ) {
                $cert_path = self::generate_certificate( array(
                    'log_id'          => $log_id,
                    'post_id'         => $post_id,
                    'filled_pdf'      => $filled_pdf,
                    'doc_hash'        => $doc_hash,
                    'sig_path'        => $sig_path,
                    'form_data'       => $form_data,
                    'submitter_email' => $submitter_email,
                    'ip_address'      => self::get_ip(),
                    'settings'        => $settings,
                ) );
            }
			
			// Attach filled PDF + certificate to CF7 emails
            if ( ! empty( $settings['attach_pdf'] ) ) {
				if (! empty( $settings['add_audit'] )) {
					$attachments = array_filter( array( $filled_pdf, $cert_path ) );
			    } else {
					$attachments = array_filter( array( $filled_pdf ) );
				}
                foreach ( $attachments as $attach ) {
                    if ( ! file_exists( $attach ) ) continue;
                    if ( method_exists( $submission, 'add_extra_attachments' ) ) {
                        $submission->add_extra_attachments( $attach, 'mail' );
                        $submission->add_extra_attachments( $attach, 'mail_2' );
                    } else {
                        $GLOBALS['cf7w_attachments'][] = $attach;
                    }
                }
                // Fallback for older CF7
                if ( ! method_exists( $submission, 'add_extra_attachments' ) ) {
                    add_filter( 'wpcf7_mail_components', function( $components ) {
                        foreach ( $GLOBALS['cf7w_attachments'] ?? array() as $path ) {
                            $components['attachments'][] = $path;
                        }
                        return $components;
                    } );
                }
            }
			
			// Store certificate path in DB
            if ( $cert_path && file_exists( $cert_path ) ) {
                $wpdb->update(
                    cf7w_db_table(),
                    array( 'cert_pdf' => $cert_path ),
                    array( 'id'       => $log_id ),
                    array( '%s' ),
                    array( '%d' )
                );
            }

            // [PREMIUM] External storage
            CF7W_Premium::handle_submission( array(
                'log_id'          => $log_id,
                'post_id'         => $post_id,
                'filled_pdf'      => $filled_pdf,
                'form_data'       => $form_data,
                'submitter_email' => $submitter_email,
                'settings'        => $settings,
                'submission'      => $submission,
                'doc_hash'        => $doc_hash,
            ) );
			
	    if ( ! $save_signature && $sig_path && file_exists( $sig_path ) ) {
            cf7w_delete_file( $sig_path );
        }

        if ( ! $save_pdf && $filled_pdf && file_exists( $filled_pdf ) ) {
            // Delete after shutdown so CF7's mailer has already read the file.
            $path_to_delete = $filled_pdf;
            add_action( 'shutdown', function() use ( $path_to_delete ) {
                if ( file_exists( $path_to_delete ) ) cf7w_delete_file( $path_to_delete );
            } );
            // Clear the stored path so the submissions table shows no broken link.
            $wpdb->update(
                cf7w_db_table(),
                array( 'filled_pdf' => '' ),
                array( 'id' => $wpdb->insert_id ),
                array( '%s' ),
                array( '%d' )
            );
        }

        } // @endif can_use_premium_code__premium_only
    }

    // ── Save base64 PNG signature to disk ──────────────────────────────────────
    private static function save_signature( string $data_uri, int $form_id ): string {
		if ( ! $data_uri || 0 !== strpos( $data_uri, 'data:image/png;base64,' ) ) return '';

		$dir = CF7W_SECURE_DIR . 'signatures/';
		if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );

		$path = $dir . 'sig_' . $form_id . '_' . time() . '.png';
		$data = base64_decode( str_replace( 'data:image/png;base64,', '', $data_uri ) );

		if ( $data ) {
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$wp_filesystem->put_contents( $path, $data, FS_CHMOD_FILE );
		}

		return file_exists( $path ) ? $path : '';
    }

    private static function get_ip(): string {
        foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $k ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- empty() checks presence only; value is sanitized and unslashed on the next line
            if ( ! empty( $_SERVER[ $k ] ) ) {
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '0.0.0.0';
    }
	
    // ── [PREMIUM] Generate Certificate of Completion PDF ──────────────────────
    // @ifdef can_use_premium_code__premium_only
    private static function generate_certificate( array $data ): string {
        if ( ! cf7w_fs()->can_use_premium_code__premium_only() ) return '';

        $log_id     = (int)    ( $data['log_id']          ?? 0 );
        $post_id    = (int)    ( $data['post_id']          ?? 0 );
        $filled_pdf = (string) ( $data['filled_pdf']       ?? '' );
        $doc_hash   = (string) ( $data['doc_hash']         ?? '' );
        $sig_path   = (string) ( $data['sig_path']         ?? '' );
        $form_data  = (array)  ( $data['form_data']        ?? array() );
        $ip_address = (string) ( $data['ip_address']       ?? '' );
        $settings   = (array)  ( $data['settings']         ?? array() );

        $form_name   = get_the_title( $post_id ) ?: 'Form #' . $post_id;
        $site_name   = get_bloginfo( 'name' );
        $site_url    = get_bloginfo( 'url' );
        $timestamp   = current_time( 'j F Y \a\t g:i:s A T' );
        $doc_name    = $filled_pdf ? basename( $filled_pdf ) : 'Unknown';

        // Count pages in the signed PDF
        $page_count = 0;
        if ( $filled_pdf && file_exists( $filled_pdf ) ) {
            $pdf_bytes  = file_get_contents( $filled_pdf );
            $page_index = CF7W_PDF_Filler::debug_build_index( $pdf_bytes );
            foreach ( $page_index as $n => $info ) {
                $body = CF7W_PDF_Filler::debug_get_body( $pdf_bytes, $info );
                if ( $body && preg_match( '/\/Type\s*\/Page\b/', $body )
                  && ! preg_match( '/\/Type\s*\/Pages\b/', $body ) ) {
                    $page_count++;
                }
            }
        }

        // Find verification page URL
        global $wpdb;
        $verify_page_id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_type = 'page'
             AND post_content LIKE '%cf7w_verify%'
             LIMIT 1"
        );
        $verify_url = $verify_page_id
            ? add_query_arg( 'log_id', $log_id, get_permalink( $verify_page_id ) )
            : '';

        // Output directory
        $cert_dir = CF7W_SECURE_DIR . 'certificates/';
        wp_mkdir_p( $cert_dir );
        $cert_path  = $cert_dir . 'certificate_' . $log_id . '_' . time() . '.pdf';

        // Build the certificate using CF7W_PDF_Filler::build_certificate_pdf()
        $cert_bytes = CF7W_PDF_Filler::build_certificate_pdf( array(
            'log_id'       => $log_id,
            'form_name'    => $form_name,
            'site_name'    => $site_name,
            'site_url'     => $site_url,
            'doc_name'     => $doc_name,
            'doc_hash'     => $doc_hash,
            'page_count'   => $page_count,
            'timestamp'    => $timestamp,
            'ip_address'   => $ip_address,
            'form_data'    => $form_data,
            'sig_path'     => $sig_path,
            'verify_url'   => $verify_url,
            'status'       => 'Signed',
        ) );

        global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( $cert_bytes && $wp_filesystem->put_contents( $cert_path, $cert_bytes, FS_CHMOD_FILE ) ) {
			error_log( 'CF7W certificate: generated ' . $cert_path );
			return $cert_path;
		}

        error_log( 'CF7W certificate: failed to write ' . $cert_path );
        return '';
    }
    // @endif can_use_premium_code__premium_only
}
