<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CF7W_PDF_Filler -- Pure-PHP AcroForm PDF filler.
 *
 * Supports both classic xref-table PDFs and compressed cross-reference stream
 * PDFs (PDF 1.5+, /ObjStm). Uses pdftk+FDF when available; pure-PHP otherwise.
 */
class CF7W_PDF_Filler {

    // --
    // PUBLIC ENTRY POINT
    // --

    public static function fill(
        int    $form_id,
        array  $settings,
        array  $mappings,
        array  $pdf_data,
        string $sig_path,
        string $filename_scheme = ''
    ): string {
        $attach_id = absint( $settings['pdf_attach_id'] ?? 0 );
        $src_path  = $attach_id
            ? get_attached_file( $attach_id )
            : ( isset( $settings['pdf_url'] ) ? self::url_to_path( $settings['pdf_url'] ) : '' );

        if ( ! $src_path || ! file_exists( $src_path ) ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W: PDF source not found: ' . $src_path );
            return '';
        }

        $out_dir = CF7W_SECURE_DIR . 'filled/';
        if ( ! is_dir( $out_dir ) ) wp_mkdir_p( $out_dir );

        $scheme   = $settings['pdf_filename_scheme'] ?? '';
        $out_name = self::build_filename( $scheme, $pdf_data, $form_id );
        $out_path = $out_dir . $out_name;

        $sig = array(
            'path'      => $sig_path,
            'pdf_field' => $settings['sig_pdf_field'] ?? '',
            'page'      => max( 1, absint( $settings['sig_page'] ?? 1 ) ),
            'x'         => floatval( $settings['sig_x'] ?? 50 ),
            'y'         => floatval( $settings['sig_y'] ?? 80 ),
            'w'         => floatval( $settings['sig_w'] ?? 200 ),
            'h'         => floatval( $settings['sig_h'] ?? 60 ),
            'vp'        => $settings['sig_vp_placement'] ?? null,
        );

        // Visual placements (Step 3)
        $vp_enabled    = ! empty( $settings['visual_placement_enabled'] );
        $vp_placements = $settings['visual_placements'] ?? array();
        // Build cf7_field => value map for VP stamping
        $vp_field_values = array();
        if ( $vp_enabled && ! empty( $vp_placements ) ) {
            // Method 1: Use cf7_field_values if explicitly passed (PREFERRED).
            // This is keyed by the original CF7 field name (matching what VP placements store).
            if ( ! empty( $settings['cf7_field_values'] ) ) {
                $vp_field_values = $settings['cf7_field_values'];
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log('CF7W: Using explicit cf7_field_values: ' . count($vp_field_values) . ' values');
            } else {
                // Method 2: Build from mappings -- map cf7_field => value
                // using pdf_data (which is keyed by pdf_field) as the value source.
                // NOTE: do NOT merge pdf_data keys directly; they are PDF field names,
                // not CF7 field names, and would corrupt the VP lookup.
                foreach ( $mappings as $m ) {
                    $cf7f = $m['cf7_field'] ?? '';
                    $pdff = $m['pdf_field'] ?? '';
                    if ( $cf7f && isset( $pdf_data[ $pdff ] ) ) {
                        $vp_field_values[ $cf7f ] = $pdf_data[ $pdff ];
                        // Also index by sanitized key so lookup succeeds either way
                        $sanitized = sanitize_key( $cf7f );
                        if ( $sanitized !== $cf7f ) {
                            $vp_field_values[ $sanitized ] = $pdf_data[ $pdff ];
                        }
                    }
                }
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log('CF7W: Built vp_field_values from mappings: ' . count($vp_field_values) . ' values');
            }
        }

        try {
            $pdf = file_get_contents( $src_path );
            if ( $pdf === false ) throw new Exception( 'Cannot read PDF source' );

            // Strategy 1: pdftk + FDF (most reliable, handles all PDF types)
            $filled = false;
            if ( ! empty( $pdf_data ) && self::pdftk_available() ) {
                $filled = self::fill_with_pdftk( $src_path, $out_path, $pdf_data );
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W fill: strategy=pdftk filled=' . ($filled?'yes':'no') );
                if ( $filled ) {
                    $pdf2 = file_get_contents( $out_path );
                    if ( $pdf2 ) {
                        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W fill: pdf2 size=' . strlen($pdf2) . ' vp_enabled=' . ($vp_enabled?'yes':'no') . ' placements=' . count($vp_placements) . ' field_values=' . count($vp_field_values) );
                        // Stamp signature using original for /Rect lookup
                        if ( $sig['path'] && file_exists( $sig['path'] ) ) {
                            $pdf2 = self::stamp_signature( $pdf2, $sig, $pdf );
                        }
                        // Stamp visual placements -- pass original $pdf as ref for page geometry
                        if ( $vp_enabled && ! empty( $vp_placements ) ) {
                            $pdf2 = self::stamp_visual_placements( $pdf2, $vp_placements, $vp_field_values, $settings, $pdf );
                        }
                        // Always flatten (make fields read-only)
                        $pdf2 = self::flatten_pdf( $pdf2 );
                        file_put_contents( $out_path, $pdf2 );
                    }
                }
            }

            // Strategy 2: pure-PHP
            if ( ! $filled ) {
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W fill: strategy=pure-php vp_enabled=' . ($vp_enabled?'yes':'no') . ' placements=' . count($vp_placements) . ' field_values=' . count($vp_field_values) );
                $original_pdf = $pdf;
                if ( $vp_enabled && ! empty( $vp_placements ) ) {
                    // VP mode: stamp fields visually -- skip AcroForm fill entirely
                    $settings['_sig_path']        = $sig['path'] ?? '';
                    $settings['_sig_field_names'] = $settings['sig_field_names'] ?? array();
                    $pdf = self::stamp_visual_placements( $pdf, $vp_placements, $vp_field_values, $settings );
                } else {
                    // AcroForm mode: fill PDF form fields directly
                    if ( ! empty( $pdf_data ) ) $pdf = self::fill_fields( $pdf, $pdf_data );
                }
                // Signature: stamp_signature only if VP mode didn't already handle it inline.
                // VP mode stamps the sig as an Image XObject when the sig field has a VP placement.
                // If sig has no VP placement, stamp_signature handles it (AcroForm /Rect or fallback).
                $vp_handled_sig = false;
                if ( $vp_enabled && ! empty( $vp_placements ) ) {
                    $sig_field_names_check = $settings['sig_field_names'] ?? array();
                    foreach ( $vp_placements as $_pl ) {
                        $fn = $_pl['cf7_field'] ?? '';
                        if ( in_array( $fn, $sig_field_names_check, true )
                          || in_array( sanitize_key( $fn ), $sig_field_names_check, true )
                          || strpos( $fn, 'cf7w_signature' ) === 0
                          || strpos( sanitize_key( $fn ), 'cf7w_signature' ) === 0 ) {
                            $vp_handled_sig = true; break;
                        }
                    }
                }
                if ( ! $vp_handled_sig && $sig['path'] && file_exists( $sig['path'] ) ) {
                    $pdf = self::stamp_signature( $pdf, $sig, $original_pdf );
                }
                // Always flatten (make fields read-only) -- no longer a user option
                $pdf = self::flatten_pdf( $pdf );
                if ( file_put_contents( $out_path, $pdf ) === false ) throw new Exception( 'Cannot write output' );
            }
        } catch ( Exception $e ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W PDF Filler: ' . $e->getMessage() );
            copy( $src_path, $out_path );
        }

		// ── Watermark unlicensed PDFs ──────────────────────────────────────────
        // Runs AFTER fill so the stamp overlays filled content.
        // process() always calls fill() — the watermark is the only paywall.
        if ( file_exists( $out_path ) ) {
            $is_licensed = class_exists( 'CF7W_License' ) && CF7W_License::is_active();
            if ( ! $is_licensed ) {
                $wm_pdf = self::stamp_watermark( file_get_contents( $out_path ) );
                if ( $wm_pdf ) {
                    file_put_contents( $out_path, $wm_pdf );
                    error_log( 'CF7W: Watermark applied — no active license for form ' . $form_id );
                }
            }
        }

        return file_exists( $out_path ) ? $out_path : '';
    }

    // Build output filename from a scheme string with {token} placeholders
    public static function build_filename( string $scheme, array $pdf_data, int $form_id ): string {
        if ( ! $scheme ) return 'waiver_' . $form_id . '_' . time() . '.pdf';
        $name = $scheme;
        foreach ( $pdf_data as $field => $val ) {
            $name = str_ireplace( '{' . $field . '}', $val, $name );
        }
        $name = str_replace( array( '{form_id}', '{date}', '{time}', '{timestamp}' ),
                             array( $form_id, gmdate( 'Y-m-d' ), gmdate( 'His' ), time() ), $name );
        $name = preg_replace( '/\{[^}]+\}/', '', $name );
        $name = preg_replace( '/[^a-zA-Z0-9._\-]/', '_', $name );
        $name = trim( $name, '_' );
        if ( ! $name ) $name = 'waiver_' . $form_id . '_' . time();
        if ( strtolower( substr( $name, -4 ) ) !== '.pdf' ) $name .= '.pdf';
        $out_dir = CF7W_SECURE_DIR . 'filled/';
        if ( file_exists( $out_dir . $name ) ) $name = substr( $name, 0, -4 ) . '_' . time() . '.pdf';
        return $name;
    }

    // --
    // FIELD FILLING -- orchestrator
    // --

    private static function fill_fields( string $pdf, array $data ): string {
        $obj_index    = self::build_obj_index( $pdf );
        $updates      = array();
        $acroform_obj = null;

        foreach ( $obj_index as $n => $info ) {
            $body = self::get_obj_body( $pdf, $info );
            if ( $body === null ) continue;

            if ( $acroform_obj === null && preg_match( '/\/Fields\s*\[/', $body ) ) {
                $acroform_obj = $n;
            }

            $field_name = self::extract_T( $body );
            if ( $field_name === null ) continue;

            $value = self::match_data( $field_name, $data );
            if ( $value === null ) continue;

            $new_body      = self::set_v( $body, $value );
            $updates[ $n ] = $n . " 0 obj\n" . $new_body . "\nendobj\n";

            // -- Radio button: also update /AS on each child widget --
            // The parent field holds /V (group selection) and /FT /Btn + bit-15 /Ff.
            // Each kid widget holds /AS (its own appearance state) -- we must set
            // /AS to /Off on every kid except the one whose export value matches.
            $ff = 0;
            if ( preg_match( '/\/Ff\s+(\d+)/', $body, $fm ) ) $ff = (int) $fm[1];
            if ( preg_match( '/\/FT\s*\/Btn\b/', $body ) && ( $ff & ( 1 << 15 ) ) ) {
                // This is a radio group parent.  Walk its /Kids.
                if ( preg_match( '/\/Kids\s*\[([^\]]+)\]/', $body, $km ) ) {
                    preg_match_all( '/(\d+)\s+0\s+R/', $km[1], $kid_refs );

                    foreach ( $kid_refs[1] as $kid_num_str ) {
                        $kid_n    = (int) $kid_num_str;
                        $kid_info = $obj_index[ $kid_n ] ?? null;
                        if ( ! $kid_info ) continue;
                        $kid_body = self::get_obj_body( $pdf, $kid_info );
                        if ( ! $kid_body ) continue;

                        $export_val = self::radio_export_value( $kid_body, $pdf, $obj_index );

                        // Determine what /AS this kid should have
                        $submitted = preg_replace( '/[^A-Za-z0-9_.:\-]/', '_', $value );
                        $chosen    = ( $export_val !== null && strcasecmp( $export_val, $submitted ) === 0 )
                                   ? $export_val : 'Off';


                        $new_kid = preg_replace( '/\/AS\s*\/\w+/', '/AS /' . $chosen, $kid_body );
                        if ( $new_kid === $kid_body ) {
                            $new_kid = self::inject_before_end( $kid_body, '/AS /' . $chosen );
                        }
                        $updates[ $kid_n ] = $kid_n . " 0 obj\n" . $new_kid . "\nendobj\n";
                    }
                }
            }
        }

        if ( $acroform_obj === null && preg_match( '/\/AcroForm\s+(\d+)\s+0\s+R/', $pdf, $am ) ) {
            $acroform_obj = (int) $am[1];
        }

        if ( $acroform_obj !== null ) {
            if ( isset( $updates[ $acroform_obj ] ) ) {
                $updates[ $acroform_obj ] = self::inject_need_appearances_into_obj_str( $updates[ $acroform_obj ] );
            } elseif ( isset( $obj_index[ $acroform_obj ] ) ) {
                $body = self::get_obj_body( $pdf, $obj_index[ $acroform_obj ] );
                if ( $body !== null ) {
                    $updates[ $acroform_obj ] = $acroform_obj . " 0 obj\n" . self::ensure_need_appearances( $body ) . "\nendobj\n";
                }
            }
        }

        if ( empty( $updates ) ) return $pdf;
        return self::apply_incremental( $pdf, $updates );
    }

    // --
    // OBJECT INDEX -- handles both classic xref tables and xref streams (/ObjStm)
    // --

    /**
     * Returns array( obj_num => array( 'offset' => int, 'body' => string|null ) )
     *
     * For normal objects: offset is the byte position in $pdf, body is null (read on demand).
     * For compressed objects in ObjStm: offset is -1, body is the pre-extracted dict string.
     */
    private static function build_obj_index( string $pdf ): array {
        $index = array();

        // -- Phase 1: parse the xref structure --
        // Find the last valid startxref value and walk the chain (following /Prev).
        // Linearized PDFs have "startxref 0" near the top -- skip zeros and out-of-range values.
        if ( ! preg_match_all( '/startxref\s+(\d+)/s', $pdf, $sx ) ) return $index;
        $pdf_len      = strlen( $pdf );
        $valid_sx     = array_filter( $sx[1], fn($v) => (int)$v > 0 && (int)$v < $pdf_len );
        if ( empty( $valid_sx ) ) return $index;
        $xref_offset  = (int) end( $valid_sx );
        $visited     = array();

        while ( $xref_offset > 0 && ! isset( $visited[ $xref_offset ] ) ) {
            $visited[ $xref_offset ] = true;
            // Read to end of file -- xref tables can be arbitrarily large
            $window = substr( $pdf, $xref_offset );
            if ( $window === '' ) break;

            if ( substr( ltrim( $window ), 0, 4 ) === 'xref' ) {
                // -- Classic xref table --
                $chunk = ltrim( $window );
                $pos   = 4; // skip 'xref'
                while ( preg_match( '/\G\s*(\d+)\s+(\d+)\s*[\r\n]+/A', $chunk, $m, 0, $pos ) ) {
                    $first = (int) $m[1]; $count = (int) $m[2];
                    $pos  += strlen( $m[0] );
                    for ( $i = 0; $i < $count; $i++ ) {
                        // Each xref entry is exactly 20 bytes per PDF spec.
                        // Format: 10-digit-offset SP 5-digit-gen SP n/f EOL
                        // EOL is either SP+LF (20 bytes) or CR+LF (20 bytes).
                        // Read 20, then peek at byte 20 -- if it is also a line-ending byte,
                        // consume it too (handles rare 21-byte non-standard generators).
                        $entry = substr( $chunk, $pos, 20 );
                        if ( strlen( $entry ) < 18 ) break;
                        $byte_offset = (int) substr( $entry, 0, 10 );
                        // char 17 is always the n/f flag in a spec-conforming entry
                        $in_use = $entry[17];
                        // Advance past the 20-byte entry, plus one extra byte if a stray \r or \n follows
                        $advance = 20;
                        $peek = substr( $chunk, $pos + 20, 1 );
                        if ( $peek === "\r" || $peek === "\n" ) $advance = 21;
                        $pos += $advance;
                        $n = $first + $i;
                        if ( ! isset( $index[ $n ] ) ) {
                            $index[ $n ] = array( 'offset' => ( $in_use === 'n' ) ? $byte_offset : -1, 'body' => null );
                        }
                    }
                }
                // Follow /Prev
                $prev = 0;
                if ( preg_match( '/\/Prev\s+(\d+)/', $chunk, $pm ) ) $prev = (int) $pm[1];
                $xref_offset = $prev;

            } else {
                // -- Xref stream (PDF 1.5+ compressed cross-reference) --
                // The xref stream is itself an object: "N 0 obj\n<< /Type /XRef ... >>\nstream\n..."
                $stream_body = self::read_obj_body_raw( $pdf, $xref_offset );
                if ( $stream_body === null ) break;

                list( $dict_str, $stream_data ) = $stream_body;

                // Parse /W field widths
                if ( ! preg_match( '/\/W\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s*\]/', $dict_str, $wm ) ) break;
                $w = array( (int) $wm[1], (int) $wm[2], (int) $wm[3] );

                // Parse /Index (subsection starts/counts), default [0 /Size]
                $size = 0;
                if ( preg_match( '/\/Size\s+(\d+)/', $dict_str, $szm ) ) $size = (int) $szm[1];
                $subsections = array( array( 0, $size ) );
                if ( preg_match( '/\/Index\s*\[([^\]]+)\]/', $dict_str, $im ) ) {
                    $nums = preg_split( '/\s+/', trim( $im[1] ) );
                    $subsections = array();
                    for ( $i = 0; $i + 1 < count( $nums ); $i += 2 ) {
                        $subsections[] = array( (int) $nums[ $i ], (int) $nums[ $i + 1 ] );
                    }
                }

                // Decompress the stream
                $decoded = self::decode_stream( $stream_data, $dict_str );
                if ( $decoded === null ) break;

                // Walk the binary xref stream entries
                $entry_size = $w[0] + $w[1] + $w[2];
                $pos        = 0;
                foreach ( $subsections as list( $first, $count ) ) {
                    for ( $i = 0; $i < $count; $i++ ) {
                        if ( $pos + $entry_size > strlen( $decoded ) ) break;
                        $entry = substr( $decoded, $pos, $entry_size );
                        $pos  += $entry_size;

                        $type    = $w[0] > 0 ? self::be_int( substr( $entry, 0,        $w[0] ) ) : 1;
                        $field2  =              self::be_int( substr( $entry, $w[0],     $w[1] ) );
                        $field3  = $w[2] > 0 ? self::be_int( substr( $entry, $w[0]+$w[1], $w[2] ) ) : 0;
                        $n       = $first + $i;

                        if ( isset( $index[ $n ] ) ) continue; // newer entry wins

                        if ( $type === 1 ) {
                            // Type 1: uncompressed object at byte offset $field2
                            $index[ $n ] = array( 'offset' => $field2, 'body' => null );
                        } elseif ( $type === 2 ) {
                            // Type 2: object $n is at index $field3 inside ObjStm stream object $field2
                            $index[ $n ] = array( 'offset' => -1, 'body' => null, 'stm' => $field2, 'stm_idx' => $field3 );
                        } else {
                            // Type 0: free
                            $index[ $n ] = array( 'offset' => -1, 'body' => null );
                        }
                    }
                }

                // Follow /Prev
                $prev = 0;
                if ( preg_match( '/\/Prev\s+(\d+)/', $dict_str, $pm ) ) $prev = (int) $pm[1];
                $xref_offset = $prev;
            }
        }

        // -- Phase 2: regex scan fallback if index is still empty --
        if ( empty( $index ) ) {
            preg_match_all( '/(?:^|\n)(\d+)\s+0\s+obj\b/m', $pdf, $fm, PREG_OFFSET_CAPTURE );
            foreach ( $fm[1] as $match ) {
                $index[ (int) $match[0] ] = array( 'offset' => (int) $match[1], 'body' => null );
            }
        }

        // -- Phase 3: extract bodies from ObjStm streams --
        // Collect all ObjStm stream object numbers needed
        $stm_needed = array(); // stm_obj_num => [ obj_num, ... ]
        foreach ( $index as $n => $info ) {
            if ( isset( $info['stm'] ) ) {
                $stm_needed[ $info['stm'] ][] = $n;
            }
        }

        foreach ( $stm_needed as $stm_num => $obj_nums ) {
            // The ObjStm itself must be a normal (type 1) object
            if ( ! isset( $index[ $stm_num ] ) || $index[ $stm_num ]['offset'] < 0 ) continue;
            $stm_info = self::read_obj_body_raw( $pdf, $index[ $stm_num ]['offset'] );
            if ( $stm_info === null ) continue;
            list( $stm_dict, $stm_data ) = $stm_info;

            if ( ! preg_match( '/\/Type\s*\/ObjStm\b/', $stm_dict ) ) continue;

            // /N = number of objects, /First = byte offset of first object in decompressed stream
            $n_objs = 0; $first_offset = 0;
            if ( preg_match( '/\/N\s+(\d+)/', $stm_dict, $nm ) ) $n_objs = (int) $nm[1];
            if ( preg_match( '/\/First\s+(\d+)/', $stm_dict, $fm2 ) ) $first_offset = (int) $fm2[1];

            $decoded = self::decode_stream( $stm_data, $stm_dict );
            if ( $decoded === null || $decoded === '' ) continue;

            // The header section lists: "objnum offset objnum offset ..."
            $header = substr( $decoded, 0, $first_offset );
            $body_section = substr( $decoded, $first_offset );

            // Parse the header: pairs of (obj_num, byte_offset_within_body_section)
            $entries = array();
            if ( preg_match_all( '/(\d+)\s+(\d+)/', $header, $hm ) ) {
                for ( $i = 0; $i < count( $hm[1] ); $i++ ) {
                    $entries[] = array( (int) $hm[1][ $i ], (int) $hm[2][ $i ] );
                }
            }

            // Extract each object's body from the body section
            for ( $ei = 0; $ei < count( $entries ); $ei++ ) {
                list( $obj_num, $obj_off ) = $entries[ $ei ];
                $next_off = isset( $entries[ $ei + 1 ] ) ? $entries[ $ei + 1 ][1] : strlen( $body_section );
                $obj_content = trim( substr( $body_section, $obj_off, $next_off - $obj_off ) );

                // Extract the dict body if it starts with <<
                if ( substr( $obj_content, 0, 2 ) === '<<' ) {
                    $dict_body = self::extract_dict( $obj_content, 0 );
                } else {
                    $dict_body = $obj_content; // might be a number, array, etc.
                }

                if ( isset( $index[ $obj_num ] ) ) {
                    // Only set body from ObjStm if this entry IS a type-2 (ObjStm) entry.
                    // Type-1 (direct offset) entries from a newer incremental layer must
                    // not be overwritten -- they supersede the ObjStm version.
                    if ( isset( $index[ $obj_num ]['stm'] ) ) {
                        $index[ $obj_num ]['body'] = $dict_body;
                    }
                }
            }
        }

        return $index;
    }

    // --
    // OBJECT READING HELPERS
    // --

    // Get the dict body for an object, either from pre-extracted body or by reading from $pdf
    private static function get_obj_body( string $pdf, array $info ): ?string {
        if ( $info['body'] !== null ) return $info['body'];
        if ( $info['offset'] < 0 )   return null;
        return self::read_obj_dict( $pdf, $info['offset'] );
    }

    // Read an object at $offset and return [ dict_string, stream_bytes ] or null
    // Used for xref stream objects and ObjStm objects
    // Read a stream object at $offset and return [ dict_string, stream_bytes ] or null.
    // Works on objects of any size. Handles all PDF line-ending styles and indirect /Length.
    private static function read_obj_body_raw( string $pdf, int $offset ): ?array {
        $pdf_len = strlen( $pdf );
        if ( $offset < 0 || $offset >= $pdf_len ) return null;

        // Read enough for object header + dict (65KB covers all realistic dicts)
        $header_win = substr( $pdf, $offset, min( 65536, $pdf_len - $offset ) );
        if ( ! preg_match( '/^\d+\s+\d+\s+obj\s*/s', $header_win, $hm ) ) return null;

        $body_rel = ltrim( substr( $header_win, strlen( $hm[0] ) ) );
        if ( substr( $body_rel, 0, 2 ) !== '<<' ) return null;
        $dict_str = self::extract_dict( $body_rel, 0 );
        if ( $dict_str === null ) return null;

        // Locate stream keyword using absolute offsets into $pdf
        $hm_len         = strlen( $hm[0] );
        $leading_spaces = strlen( substr( $header_win, $hm_len ) ) - strlen( $body_rel );
        $dict_abs_end   = $offset + $hm_len + $leading_spaces + strlen( $dict_str );

        $kw_search = substr( $pdf, $dict_abs_end, 64 );
        $kw_pos    = strpos( $kw_search, 'stream' );
        if ( $kw_pos === false ) return array( $dict_str, '' ); // no stream

        $stream_abs = $dict_abs_end + $kw_pos + 6; // past 'stream'

        // Skip mandatory line ending after 'stream' keyword (PDF spec: \r\n, \n, or bare \r)
        if ( $stream_abs + 1 < $pdf_len && substr( $pdf, $stream_abs, 2 ) === "\r\n" ) {
            $stream_abs += 2;
        } elseif ( $stream_abs < $pdf_len && ( $pdf[$stream_abs] === "\n" || $pdf[$stream_abs] === "\r" ) ) {
            $stream_abs += 1;
        }

        // Use /Length if direct integer; otherwise search for endstream
        $direct_len = -1;
        if ( preg_match( '/\/Length\s+(\d+)\s*(?:\/|>>)/', $dict_str, $lm ) ) {
            $direct_len = (int) $lm[1];
        }

        if ( $direct_len >= 0 ) {
            $stream_data = substr( $pdf, $stream_abs, $direct_len );
        } else {
            $end_abs     = strpos( $pdf, 'endstream', $stream_abs );
            $stream_data = $end_abs !== false ? substr( $pdf, $stream_abs, $end_abs - $stream_abs ) : '';
        }

        return array( $dict_str, $stream_data );
    }
    // Read just the dict body (<<...>>) of a normal object at a byte offset
    private static function read_obj_dict( string $pdf, int $offset ): ?string {
        if ( $offset < 0 ) return null;
        // Read enough for any reasonable object header + dict.
        // Most objects are under 64KB; for very large ones we extend as needed.
        $chunk = substr( $pdf, $offset, min( 131072, strlen($pdf) - $offset ) );
        if ( ! preg_match( '/^\d+\s+\d+\s+obj\s*/s', $chunk, $hm ) ) return null;
        $s = ltrim( substr( $chunk, strlen( $hm[0] ) ) );
        if ( substr( $s, 0, 2 ) !== '<<' ) return null;
        return self::extract_dict( $s, 0 );
    }

    // Extract a balanced <<...>> dict starting at $pos in $s.
    // Correctly handles: nested dicts <<...<<...>>...>>, literal strings (...),
    // and hex strings <...> which must NOT be counted as dict delimiters.
    private static function extract_dict( string $s, int $pos ): ?string {
        $len = strlen( $s );
        if ( $pos >= $len || substr( $s, $pos, 2 ) !== '<<' ) return null;
        $depth = 0; $in_str = false; $esc = false; $in_hex = false;
        for ( $i = $pos; $i < $len - 1; $i++ ) {
            $c = $s[ $i ];
            // Inside a literal string ( ... )
            if ( $esc )    { $esc = false; continue; }
            if ( $in_str ) {
                if ( $c === '\\' ) { $esc = true; continue; }
                if ( $c === ')' )   { $in_str = false; }
                continue;
            }
            // Inside a hex string < ... >  (single angle brackets, not <<)
            if ( $in_hex ) {
                if ( $c === '>' ) { $in_hex = false; }
                continue;
            }
            if ( $c === '(' ) { $in_str = true; continue; }
            // Double angle: dict delimiter
            if ( $c === '<' && isset( $s[ $i + 1 ] ) && $s[ $i + 1 ] === '<' ) {
                $depth++; $i++; continue;
            }
            if ( $c === '>' && isset( $s[ $i + 1 ] ) && $s[ $i + 1 ] === '>' ) {
                $depth--; $i++;
                if ( $depth === 0 ) return substr( $s, $pos, $i + 1 - $pos );
                continue;
            }
            // Single < that is NOT followed by < -- hex string
            if ( $c === '<' ) { $in_hex = true; continue; }
            // Single > outside hex (malformed but be resilient)
        }
        return null;
    }

    // Decode a PDF stream: handles /FlateDecode (with optional PNG predictor) and no filter
    private static function decode_stream( string $data, string $dict ): ?string {
        // Normalise filter: /Filter /Name or /Filter [/Name] -- check for FlateDecode
        $has_flat = (bool) preg_match( '#/Filter\s*/FlateDecode\b#', $dict );
        if ( ! $has_flat && preg_match( '#/Filter\s*\[([^\]]*)\]#', $dict, $fm ) ) {
            $has_flat = strpos( $fm[1], 'FlateDecode' ) !== false;
        }

        if ( $has_flat ) {
            if ( ! function_exists( 'zlib_decode' ) ) return null;
            // Try zlib (RFC 1950) first, then raw deflate fallback
            $clean   = rtrim( $data, "\x00" );
            $decoded = @zlib_decode( $clean );
            if ( $decoded === false && function_exists( 'gzinflate' ) ) {
                $decoded = @gzinflate( $clean );
            }
            if ( $decoded === false || $decoded === '' ) return null;

            // -- Apply PNG predictor if /DecodeParms specifies Predictor >= 10 --
            // PDF xref streams commonly use /DecodeParms<</Predictor 12 /Columns N>>
            // Predictor 10 = None, 11 = Sub, 12 = Up, 13 = Average, 14/15 = Paeth
            // After zlib decode, rows are: [1-byte filter tag][Columns data bytes]
            $predictor = 1; $columns = 1;
            if ( preg_match( '#/DecodeParms\s*<<([^>]*)>>#s', $dict, $dp ) ||
                 preg_match( '#/DecodeParms\s*\[\s*<<([^>]*)>>\s*\]#s', $dict, $dp ) ) {
                if ( preg_match( '#/Predictor\s+(\d+)#', $dp[1], $pm ) ) $predictor = (int) $pm[1];
                if ( preg_match( '#/Columns\s+(\d+)#',   $dp[1], $cm ) ) $columns   = (int) $cm[1];
            }

            if ( $predictor >= 10 ) {
                $row_len  = $columns + 1; // 1 filter byte + Columns data bytes
                $n_rows   = (int) floor( strlen( $decoded ) / $row_len );
                $out      = '';
                $prev_row = str_repeat( "\x00", $columns );
                for ( $r = 0; $r < $n_rows; $r++ ) {
                    $row      = substr( $decoded, $r * $row_len, $row_len );
                    $filter   = ord( $row[0] );
                    $raw      = substr( $row, 1 );
                    $result   = '';
                    for ( $i = 0; $i < $columns; $i++ ) {
                        $x    = ord( $raw[ $i ] );
                        $a    = $i > 0           ? ord( $result[ $i - 1 ] )   : 0; // left
                        $b    = ord( $prev_row[ $i ] );                              // above
                        $c    = $i > 0           ? ord( $prev_row[ $i - 1 ] ) : 0; // above-left
                        switch ( $filter ) {
                            case 0:  $val = $x; break;                              // None
                            case 1:  $val = ( $x + $a ) & 0xFF; break;             // Sub
                            case 2:  $val = ( $x + $b ) & 0xFF; break;             // Up
                            case 3:  $val = ( $x + (int) floor( ( $a + $b ) / 2 ) ) & 0xFF; break; // Average
                            case 4:                                                  // Paeth
                                $p  = $a + $b - $c;
                                $pa = abs( $p - $a ); $pb = abs( $p - $b ); $pc = abs( $p - $c );
                                $val = ( $x + ( $pa <= $pb && $pa <= $pc ? $a : ( $pb <= $pc ? $b : $c ) ) ) & 0xFF;
                                break;
                            default: $val = $x;
                        }
                        $result .= chr( $val );
                    }
                    $out     .= $result;
                    $prev_row = $result;
                }
                return $out;
            }

            return $decoded;
        }
        // No filter -- return raw bytes
        if ( ! preg_match( '#/Filter\b#', $dict ) ) return $data;
        return null; // other filters (LZW, ASCII85, etc.) not supported
    }

    // Convert a big-endian binary string to integer
    private static function be_int( string $bytes ): int {
        $result = 0;
        for ( $i = 0; $i < strlen( $bytes ); $i++ ) {
            $result = ( $result << 8 ) | ord( $bytes[ $i ] );
        }
        return $result;
    }

    // --
    // FIELD VALUE MANIPULATION
    // --

    private static function extract_T( string $body ): ?string {
        if ( preg_match( '/\/T\s*\(/', $body, $m, PREG_OFFSET_CAPTURE ) ) {
            $start = $m[0][1] + strlen( $m[0][0] );
            $name  = self::read_literal_string( $body, $start );
            if ( $name !== null ) return $name;
        }
        if ( preg_match( '/\/T\s*<([0-9A-Fa-f\s]*)>/', $body, $m ) ) {
            return pack( 'H*', preg_replace( '/\s+/', '', $m[1] ) );
        }
        return null;
    }

    private static function read_literal_string( string $s, int $pos ): ?string {
        $result = ''; $depth = 1; $esc = false; $len = strlen( $s );
        for ( $i = $pos; $i < $len; $i++ ) {
            $c = $s[ $i ];
            if ( $esc ) {
                if ( $c >= '0' && $c <= '7' ) {
                    $oct = $c;
                    for ( $j = 1; $j < 3 && isset( $s[ $i+1 ] ) && $s[$i+1] >= '0' && $s[$i+1] <= '7'; $j++ ) $oct .= $s[++$i];
                    $result .= chr( octdec( $oct ) );
                } else {
                    $map = array( 'n' => "\n", 'r' => "\r", 't' => "\t", '(' => '(', ')' => ')', '\\' => '\\' );
                    $result .= $map[ $c ] ?? $c;
                }
                $esc = false; continue;
            }
            if ( $c === '\\' ) { $esc = true; continue; }
            if ( $c === '(' )  { $depth++; $result .= $c; continue; }
            if ( $c === ')' )  { $depth--; if ( $depth === 0 ) return $result; $result .= $c; continue; }
            $result .= $c;
        }
        return null;
    }

    private static function match_data( string $field_name, array $data ): ?string {
        $leaf = basename( str_replace( '.', '/', $field_name ) );
        foreach ( $data as $key => $val ) {
            if ( strcasecmp( $field_name, $key ) === 0 ) return (string) $val;
            if ( strcasecmp( $leaf,       $key ) === 0 ) return (string) $val;
        }
        return null;
    }

    // Extract the "on" export value name for a radio button widget.
    // Handles all known AP structures:
    //   /AP << /N << /Yes stream /Off stream >>  >>   (inline AP, inline N)
    //   /AP << /N 97 0 R >>                           (inline AP, indirect N -- uncommon)
    //   /AP 97 0 R                                    (indirect AP object -- Sejda/Adobe common)
    //     where obj 97 = << /N << /Yes stream /Off stream >> >>
    public static function radio_export_value( string $kid_body, string $pdf, array $obj_index ): ?string {
        // Get the AP dict body -- either inline or by following the indirect ref
        $ap_body = null;
        if ( preg_match( '/\/AP\s*<</', $kid_body, $m, PREG_OFFSET_CAPTURE ) ) {
            // Inline AP dict
            $ap_body = self::extract_dict( $kid_body, $m[0][1] + strlen( $m[0][0] ) - 2 );
        } elseif ( preg_match( '/\/AP\s+(\d+)\s+0\s+R/', $kid_body, $m ) ) {
            // Indirect AP object -- read it from the PDF
            $ap_obj = (int) $m[1];
            if ( isset( $obj_index[ $ap_obj ] ) ) {
                $ap_body = self::get_obj_body( $pdf, $obj_index[ $ap_obj ] );
            }
        }
        if ( $ap_body === null ) return null;

        // Within the AP dict, find /N which may be inline << >> or indirect ref
        $n_body = null;
        if ( preg_match( '/\/N\s*<</', $ap_body, $m, PREG_OFFSET_CAPTURE ) ) {
            $n_body = self::extract_dict( $ap_body, $m[0][1] + strlen( $m[0][0] ) - 2 );
        } elseif ( preg_match( '/\/N\s+(\d+)\s+0\s+R/', $ap_body, $m ) ) {
            $n_obj = (int) $m[1];
            if ( isset( $obj_index[ $n_obj ] ) ) {
                $n_body = self::get_obj_body( $pdf, $obj_index[ $n_obj ] );
            }
        }
        if ( $n_body === null ) return null;

        // The non-Off keys in /N are the export (on) state names
        // Keys may be followed by either a stream ref (N 0 R) or inline stream dict
        preg_match_all( '/\/([A-Za-z0-9_.:\-]+)/', $n_body, $keys );
        foreach ( $keys[1] as $k ) {
            if ( strcasecmp( $k, 'Off' ) !== 0 ) return $k;
        }
        return null;
    }

    private static function set_v( string $body, string $value ): string {
        $is_btn = (bool) preg_match( '/\/FT\s*\/Btn\b/', $body );
        if ( $is_btn ) {
            // Bit 15 (0-indexed) of /Ff = Radio button; absent or 0 = checkbox.
            $ff        = 0;
            if ( preg_match( '/\/Ff\s+(\d+)/', $body, $fm ) ) $ff = (int) $fm[1];
            $is_radio  = (bool) ( $ff & ( 1 << 15 ) );

            if ( $is_radio ) {
                // Radio: /V must be the exact export-value name of the chosen option.
                // The export value is the appearance name in /AP /N (e.g. /Male, /Yes, /Option1).
                // We receive it as a plain string from the form; convert to a PDF name.
                // If value is empty/off, set /V /Off.
                $truthy = $value !== '' && $value !== '0'
                    && strtolower( $value ) !== 'off'
                    && strtolower( $value ) !== 'no'
                    && strtolower( $value ) !== 'false';
                if ( $truthy ) {
                    // Sanitise to a valid PDF name (no whitespace or special chars)
                    $name_val = '/' . preg_replace( '/[^A-Za-z0-9_.:\-]/', '_', $value );
                } else {
                    $name_val = '/Off';
                }
                $body = preg_replace( '/\/V\s*\/\w+/',  '/V '  . $name_val, $body );
                $body = preg_replace( '/\/AS\s*\/\w+/', '/AS ' . $name_val, $body );
                if ( ! preg_match( '/\/V\s*\//', $body ) ) {
                    $body = self::inject_before_end( $body, '/V ' . $name_val );
                }
            } else {
                // Checkbox: standard /Yes or /Off
                $truthy = $value !== '' && $value !== '0'
                    && strtolower( $value ) !== 'off'
                    && strtolower( $value ) !== 'no'
                    && strtolower( $value ) !== 'false';
                $pv   = $truthy ? '/Yes' : '/Off';
                $body = preg_replace( '/\/V\s*\/\w+/',  '/V '  . $pv, $body );
                $body = preg_replace( '/\/AS\s*\/\w+/', '/AS ' . $pv, $body );
                if ( ! preg_match( '/\/V\s*\//', $body ) ) {
                    $body = self::inject_before_end( $body, '/V ' . $pv );
                }
            }
        } else {
            $enc  = self::encode_literal( $value );
            $body = self::replace_v_string( $body, $enc );
        }
        $body = preg_replace( '/\/AP\s*<<(?:[^<>]|<(?!<)|(?<!>)>)*>>/s', '', $body );
        $body = preg_replace( '/\/AP\s+\d+\s+\d+\s+R\b/', '', $body );
        return $body;
    }

    private static function replace_v_string( string $body, string $enc ): string {
        if ( preg_match( '/\/V\s*\(/', $body, $m, PREG_OFFSET_CAPTURE ) ) {
            $v_start = $m[0][1]; $i = $m[0][1] + strlen( $m[0][0] );
            $depth = 1; $esc = false; $len = strlen( $body );
            while ( $i < $len && $depth > 0 ) {
                $c = $body[ $i ];
                if ( $esc ) { $esc = false; } elseif ( $c === '\\' ) { $esc = true; }
                elseif ( $c === '(' ) { $depth++; } elseif ( $c === ')' ) { $depth--; }
                $i++;
            }
            return substr( $body, 0, $v_start ) . '/V (' . $enc . ')' . substr( $body, $i );
        }
        return self::inject_before_end( $body, '/V (' . $enc . ')' );
    }

    private static function ensure_need_appearances( string $body ): string {
        if ( preg_match( '/\/NeedAppearances\s+true\b/', $body ) ) return $body;
        $body = preg_replace( '/\/NeedAppearances\s+false\b/', '', $body );
        return self::inject_before_end( $body, '/NeedAppearances true' );
    }

    private static function inject_need_appearances_into_obj_str( string $obj_str ): string {
        if ( preg_match( '/^(\d+ 0 obj\n)(.*?)(\nendobj\n)$/s', $obj_str, $m ) ) {
            return $m[1] . self::ensure_need_appearances( $m[2] ) . $m[3];
        }
        return $obj_str;
    }

    private static function inject_before_end( string $body, string $token ): string {
        $pos = strrpos( $body, '>>' );
        if ( $pos === false ) return $body . "\n" . $token;
        return substr( $body, 0, $pos ) . "\n" . $token . "\n" . substr( $body, $pos );
    }

    private static function encode_literal( string $s ): string {
        return str_replace(
            array( '\\',   '(',    ')',    "\r",   "\n"   ),
            array( '\\\\', '\\(',  '\\)',  '\\r',  '\\n'  ),
            $s
        );
    }

    // --
    // INCREMENTAL UPDATE -- appends changes without rewriting the whole file
    // --

    private static function apply_incremental( string $pdf, array $updates ): string {
        // Strip trailing %%EOF (may appear multiple times in incremental PDFs -- strip only last)
        $base = rtrim( $pdf );
        $eof_pos = strrpos( $base, '%%EOF' );
        if ( $eof_pos !== false ) $base = rtrim( substr( $base, 0, $eof_pos ) );
        $base .= "\n";

        // Append all new/updated objects, recording their byte offsets
        $new_xref = array(); $append = '';
        foreach ( $updates as $obj_num => $obj_str ) {
            $new_xref[ (int) $obj_num ] = strlen( $base ) + strlen( $append );
            $append .= $obj_str . "\n";
        }

        $out      = $base . $append;
        $xref_pos = strlen( $out );

        $nums = array_keys( $new_xref );
        sort( $nums, SORT_NUMERIC );

        // Gather metadata from the original PDF
        preg_match_all( '/\\bSize\\s+(\\d+)/', $pdf, $szm );
        $true_size = $szm[1] ? max( array_map( 'intval', $szm[1] ) ) : 0;
        $new_size  = max( $true_size, max( $nums ) + 1 );

        $root = '';
        if ( preg_match( '/\\bRoot\\s+(\\d+\\s+\\d+\\s+R)/', $pdf, $rm ) ) $root = $rm[1];
        $info = '';
        if ( preg_match( '/\\bInfo\\s+(\\d+\\s+\\d+\\s+R)/', $pdf, $im ) ) $info = $im[1];
        $id = '';
        if ( preg_match( '/\\bID\\s*(\\[.*?\\])/s', $pdf, $idm ) ) $id = $idm[1];
        // $prev_sx must point to the LAST startxref in the INPUT pdf (before our append).
        // Using end() on the full pdf would pick our own xref if called on an already-updated pdf.
        // Instead, find startxref values only in the base content (before our new objects start).
        preg_match_all( '/startxref\s+(\d+)/s', $base, $sxm );
        $prev_sx = $sxm[1] ? (int) end( $sxm[1] ) : 0;

        // -- Decide: xref stream or classic table --
        // If the original PDF uses xref streams (PDF 1.5+), we must also write an
        // xref stream -- a classic xref table in the incremental update will be
        // ignored by strict readers for objects that were originally in ObjStms.
        $uses_xref_stream = ( strpos( $pdf, '/Type /XRef' ) !== false ||
                              strpos( $pdf, '/Type/XRef' )  !== false );

        if ( $uses_xref_stream && function_exists( 'gzcompress' ) ) {
            // -- Write incremental xref stream --
            // For PDFs with multiple pre-existing xref levels (e.g. linearized + incremental),
            // merge ALL existing xref entries into our final xref. This makes our xref
            // fully self-contained and eliminates multi-level /Prev chain dependency,
            // which can cause some PDF readers to lose objects (e.g. images) when the
            // chain has 3+ levels. Our updated objects override the merged entries.
            $merged_xref = array(); // obj_num => [type, f1, f2]
            $has_multi_prev = ( substr_count( $base, 'startxref' ) >= 3 );
            
            // BUGFIX: Always merge existing xref when using xref streams, not just for 3+ levels.
            // Linearized PDFs have 2 startxref entries (linearized hint + main xref), and
            // delinearize_pdf() only blanks the /Linearized flag without removing the hint xref.
            // If we don't merge for 2-level PDFs, $merged_xref stays empty and only new objects
            // are written to the xref, causing existing objects (images, fonts) to disappear.
            $merged_xref = self::build_raw_xref_index( $pdf );
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W apply_incremental: merging ' . count($merged_xref) . ' existing xref entries into self-contained xref' );

            // Our new/updated objects override any existing entries
            foreach ( $new_xref as $n => $off ) {
                $merged_xref[ $n ] = array( 1, $off, 0 ); // type=1 (direct), offset, gen=0
            }

            // Build the full xref: merged existing + our updates
            // W field: [type_bytes, offset_bytes, gen_bytes]
            $max_off   = max( array_values( $new_xref ) ) + strlen( $append ) + 8192;
            $off_bytes = $max_off > 0xFFFFFF ? 4 : 3;
            $w_field   = array( 1, $off_bytes, 1 );

            // Sort all object numbers and build contiguous subsections
            $all_nums = array_keys( $merged_xref );
            sort( $all_nums, SORT_NUMERIC );
            $new_size = max( $new_size, max( $all_nums ) + 1 );

            $sections = array(); $sec = array( $all_nums[0] );
            for ( $i = 1; $i < count( $all_nums ); $i++ ) {
                if ( $all_nums[$i] === $all_nums[$i-1] + 1 ) { $sec[] = $all_nums[$i]; }
                else { $sections[] = $sec; $sec = array( $all_nums[$i] ); }
            }
            $sections[] = $sec;

            $xref_data = '';
            $index_arr = array();
            foreach ( $sections as $sec ) {
                $index_arr[] = $sec[0];
                $index_arr[] = count( $sec );
                foreach ( $sec as $n ) {
                    $entry = $merged_xref[ $n ];
                    $type  = $entry[0];
                    $f1    = $entry[1];
                    $f2    = $entry[2];
                    $xref_data .= chr( $type );
                    for ( $b = $off_bytes - 1; $b >= 0; $b-- ) $xref_data .= chr( ($f1 >> ($b * 8)) & 0xff );
                    $xref_data .= chr( $f2 & 0xff );
                }
            }

            // Allocate a new object number for the xref stream itself
            $xref_obj_num = $new_size;
            $new_size++;

            $xref_data_z = gzcompress( $xref_data );
            $xref_len    = strlen( $xref_data_z );
            $index_str   = implode( ' ', $index_arr );
            $w_str       = implode( ' ', $w_field );

            $xref_dict  = "<</Type /XRef /Size {$new_size} /W [{$w_str}]";
            $xref_dict .= " /Index [{$index_str}]";
            $xref_dict .= " /Filter /FlateDecode /Length {$xref_len}";
            if ( $root ) $xref_dict .= " /Root {$root}";
            if ( $info ) $xref_dict .= " /Info {$info}";
            if ( $id   ) $xref_dict .= " /ID {$id}";
            // BUGFIX #3: Always include /Prev even when merging.
            // Our merged xref excludes Type 2 (ObjStm) entries to avoid broken references,
            // but those objects must still be accessible via the /Prev chain.
            // Without /Prev, Type 2 objects (images, fonts in ObjStms) become unreachable.
            if ( $prev_sx ) $xref_dict .= " /Prev {$prev_sx}";

            // Record the xref stream object's own offset BEFORE appending it
            $xref_obj_off = strlen( $out );
            $out .= "{$xref_obj_num} 0 obj\n{$xref_dict}>>\nstream\n{$xref_data_z}\nendstream\nendobj\n";
            $out .= "startxref\n{$xref_obj_off}\n%%EOF\n";

        } else {
            // -- Classic xref table (fallback for pre-1.5 PDFs or no zlib) --
            $sections = array(); $sec = array( $nums[0] );
            for ( $i = 1; $i < count( $nums ); $i++ ) {
                if ( $nums[$i] === $nums[$i-1] + 1 ) { $sec[] = $nums[$i]; }
                else { $sections[] = $sec; $sec = array( $nums[$i] ); }
            }
            $sections[] = $sec;

            $out .= "xref\n";
            foreach ( $sections as $sec ) {
                $out .= $sec[0] . ' ' . count( $sec ) . "\n";
                foreach ( $sec as $n ) $out .= sprintf( "%010d %05d n\r\n", $new_xref[$n], 0 );
            }

            $tb  = '/Size ' . $new_size;
            if ( $root ) $tb .= ' /Root ' . $root;
            if ( $info ) $tb .= ' /Info ' . $info;
            if ( $id   ) $tb .= ' /ID '   . $id;
            $tb .= ' /Prev ' . $prev_sx;
            $out .= "trailer\n<<{$tb}>>\n";
            $out .= "startxref\n{$xref_pos}\n%%EOF\n";
        }

        return $out;
    }

    /**
     * Build a raw xref index from ALL xref levels in a PDF, returning
     * obj_num => [type, field1, field2] without populating object bodies.
     * Used by apply_incremental to merge multi-level chains into one xref.
     * Newer entries (from later xref levels) override older ones.
     */
    private static function build_raw_xref_index( string $pdf ): array {
        $index   = array();
        $pdf_len = strlen( $pdf );

        if ( ! preg_match_all( '/startxref\s+(\d+)/s', $pdf, $sx ) ) return $index;
        $valid_sx   = array_filter( $sx[1], fn($v) => (int)$v > 0 && (int)$v < $pdf_len );
        if ( empty( $valid_sx ) ) return $index;
        $xref_offset = (int) end( $valid_sx );
        $visited     = array();

        while ( $xref_offset > 0 && ! isset( $visited[ $xref_offset ] ) ) {
            $visited[ $xref_offset ] = true;
            $window = substr( $pdf, $xref_offset );
            if ( $window === '' ) break;

            if ( substr( ltrim( $window ), 0, 4 ) === 'xref' ) {
                // Classic xref table
                $chunk = ltrim( $window );
                $pos   = 4;
                while ( preg_match( '/\G\s*(\d+)\s+(\d+)\s*[\r\n]+/A', $chunk, $m, 0, $pos ) ) {
                    $first = (int) $m[1]; $count = (int) $m[2];
                    $pos  += strlen( $m[0] );
                    for ( $i = 0; $i < $count; $i++ ) {
                        $entry    = substr( $chunk, $pos, 20 );
                        $advance  = 20;
                        $peek     = substr( $chunk, $pos + 20, 1 );
                        if ( $peek === "\r" || $peek === "\n" ) $advance = 21;
                        $pos     += $advance;
                        if ( strlen( $entry ) < 18 ) break;
                        $off  = (int) substr( $entry, 0, 10 );
                        $flag = $entry[17];
                        $n    = $first + $i;
                        if ( ! isset( $index[ $n ] ) ) {
                            $index[ $n ] = array( $flag === 'n' ? 1 : 0, $off, 0 );
                        }
                    }
                }
                $prev = 0;
                if ( preg_match( '/\/Prev\s+(\d+)/', $chunk, $pm ) ) $prev = (int) $pm[1];
                $xref_offset = $prev;
            } else {
                // Xref stream
                $stream_body = self::read_obj_body_raw( $pdf, $xref_offset );
                if ( $stream_body === null ) break;
                list( $dict_str, $stream_data ) = $stream_body;
                if ( ! preg_match( '/\/W\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s*\]/', $dict_str, $wm ) ) break;
                $w = array( (int) $wm[1], (int) $wm[2], (int) $wm[3] );
                $size = 0;
                if ( preg_match( '/\/Size\s+(\d+)/', $dict_str, $szm ) ) $size = (int) $szm[1];
                $subsections = array( array( 0, $size ) );
                if ( preg_match( '/\/Index\s*\[([^\]]+)\]/', $dict_str, $im ) ) {
                    $nums = preg_split( '/\s+/', trim( $im[1] ) );
                    $subsections = array();
                    for ( $i = 0; $i + 1 < count( $nums ); $i += 2 ) {
                        $subsections[] = array( (int) $nums[$i], (int) $nums[$i+1] );
                    }
                }
                $decoded = self::decode_stream( $stream_data, $dict_str );
                if ( $decoded === null ) break;
                $entry_size = $w[0] + $w[1] + $w[2];
                $pos = 0;
                foreach ( $subsections as list( $first, $count ) ) {
                    for ( $i = 0; $i < $count; $i++ ) {
                        if ( $pos + $entry_size > strlen( $decoded ) ) break;
                        $entry = substr( $decoded, $pos, $entry_size );
                        $pos  += $entry_size;
                        $type  = $w[0] > 0 ? self::be_int( substr( $entry, 0, $w[0] ) ) : 1;
                        $f1    = self::be_int( substr( $entry, $w[0], $w[1] ) );
                        $f2    = $w[2] > 0 ? self::be_int( substr( $entry, $w[0]+$w[1], $w[2] ) ) : 0;
                        $n     = $first + $i;
                        if ( ! isset( $index[ $n ] ) ) {
                            $index[ $n ] = array( $type, $f1, $f2 );
                        }
                    }
                }
                $prev = 0;
                if ( preg_match( '/\/Prev\s+(\d+)/', $dict_str, $pm ) ) $prev = (int) $pm[1];
                $xref_offset = $prev;
            }
        }

        // Remove free entries (type=0), compressed entries (type=2), and xref stream objects
        // Type 2 entries point to objects inside Object Streams (ObjStm) via:
        //   [2, objstm_num, index_within_objstm]
        // These can't be included in the merged xref because:
        //   1. The ObjStm offsets are relative to the ORIGINAL PDF
        //   2. Our incremental update doesn't copy/rewrite ObjStm objects
        //   3. Including them would create broken references in the output
        // They remain accessible via the /Prev chain to the original xref.
        // Only include Type 1 (normal uncompressed) entries in the merged xref.
        foreach ( $index as $n => $entry ) {
            if ( $entry[0] !== 1 ) unset( $index[ $n ] ); // Keep only Type 1 (uncompressed)
        }

        return $index;
    }

    // --
    // SIGNATURE STAMPING
    // --

    // $pdf       = the PDF bytes to stamp onto (may be pdftk output)
    // $sig       = placement array (path, pdf_field, page, x, y, w, h)
    // $ref_pdf   = optional original PDF bytes to look up field /Rect from;
    //              defaults to $pdf if not supplied
    // --
    // VISUAL PLACEMENT STAMPING
    // Stamps CF7 field values as text images onto the PDF at saved coordinates.
    // Works on any PDF, with or without AcroForm fields.
    // Coordinates are stored as: x/y = origin top-left (our JS model).
    // PDF coordinate system is bottom-left, so we convert: pdf_y = page_h - y - h
    // --

    /** Debug helper: stamp VP onto a PDF file with given dummy values. Returns stamped PDF string or false. */
    /** @return string|false */
    public static function test_stamp_vp( string $src_path, array $placements, array $field_values, array $settings ) {
        $pdf = @file_get_contents( $src_path );
        if ( ! $pdf ) return false;
        $result = self::stamp_visual_placements( $pdf, $placements, $field_values, $settings );
        return $result !== $pdf || ! empty( $placements ) ? $result : false;
    }

    public static function stamp_visual_placements(
        string $pdf,
        array  $placements,   // array of {cf7_field,page,x,y,w,h} -- coords in canvas pixels
        array  $field_values, // cf7_field => submitted string value
        array  $settings = array(),
        string $ref_pdf = '' // optional: original PDF for page geometry when $pdf is already modified
    ): string {
        if ( empty( $placements ) ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log('CF7W stamp_visual_placements: No placements defined');
            return $pdf;
        }

        // Delinearize the PDF before processing to prevent linearized xref chain issues
        // (e.g. images disappearing when object offsets change after incremental update).
        $pdf = self::delinearize_pdf( $pdf );
        // Note: field_values may be empty if only a signature VP is present -- don't bail here.
        $sig_path         = $settings['_sig_path']        ?? '';
        $sig_field_names  = $settings['sig_field_names']  ?? array();
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp_vp: ' . count($placements) . ' placements, ' . count($field_values) . ' field_values'
            . ' keys=[' . implode(',', array_keys($field_values)) . ']' );
        foreach ( $placements as $pl ) {
            $f = $pl['cf7_field'] ?? '?';
            $v = $field_values[$f] ?? ($field_values[sanitize_key($f)] ?? 'MISSING');
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W stamp_vp: placement cf7_field={$f} page=".($pl['page']??'?')." value=".substr((string)$v,0,40) );
        }
        $has_sig_vp = false;
        foreach ( $placements as $pl ) {
            if ( in_array( $pl['cf7_field'] ?? '', $sig_field_names, true )
              || in_array( sanitize_key( $pl['cf7_field'] ?? '' ), $sig_field_names, true ) ) {
                $has_sig_vp = true; break;
            }
        }
        if ( empty( $field_values ) && ! $has_sig_vp ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log('CF7W stamp_visual_placements: No field values and no sig VP -- nothing to stamp');
            return $pdf;
        }
        if ( ! function_exists( 'imagecreatetruecolor' ) ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp_visual_placements: GD not available' );
            return $pdf;
        }

        $global_canvas_w = max( 1.0, floatval( $settings['vp_iframe_w'] ?? 800 ) );
        $global_canvas_h = max( 1.0, floatval( $settings['vp_iframe_h'] ?? 1000 ) );
        $font_size       = max( 6, (int) ( $settings['vp_font_size'] ?? 12 ) );
        $bg_color        = $settings['vp_bg_color'] ?? 'transparent'; // transparent|white|yellow|cyan

        // Build page index from $pdf. Use $ref_pdf for MediaBox only if provided.
        $pdf_index = self::build_obj_index( $pdf );
        $ref_index = $ref_pdf !== '' ? self::build_obj_index( $ref_pdf ) : $pdf_index;


        // Build page list: page_number => [ num => obj_num, body => dict_string ]
        $page_objs  = array();
        $page_count = 0;
        foreach ( $pdf_index as $obj_n => $info ) {
            $body = self::get_obj_body( $pdf, $info );
            if ( $body === null ) continue;
            if ( ! preg_match( '/\/Type\s*\/Page\b/', $body ) ) continue;
            if (   preg_match( '/\/Type\s*\/Pages\b/', $body ) ) continue;
            $page_count++;
            $page_objs[ $page_count ] = array( 'num' => $obj_n, 'body' => $body );
        }

        // Fallback: use ref_index if pdf_index found no pages
        if ( $page_count === 0 && $ref_pdf !== '' ) {
            foreach ( $ref_index as $obj_n => $ref_info ) {
                $ref_body = self::get_obj_body( $ref_pdf, $ref_info );
                if ( $ref_body === null ) continue;
                if ( ! preg_match( '/\/Type\s*\/Page\b/', $ref_body ) ) continue;
                if (   preg_match( '/\/Type\s*\/Pages\b/', $ref_body ) ) continue;
                $page_count++;
                $pdf_body = isset( $pdf_index[ $obj_n ] ) ? self::get_obj_body( $pdf, $pdf_index[ $obj_n ] ) : null;
                $page_objs[ $page_count ] = array( 'num' => $obj_n, 'body' => $pdf_body ?? $ref_body );
            }
        }

        // Next available object number
        $max_obj = $pdf_index ? max( array_keys( $pdf_index ) ) : 1;

        $updates = array();

        // Group placements by page
        $by_page = array();
        foreach ( $placements as $pl ) {
            $pn = max( 1, (int) ( $pl['page'] ?? 1 ) );
            $by_page[ $pn ][] = $pl;
        }

        foreach ( $by_page as $page_num => $page_placements ) {
            if ( ! isset( $page_objs[ $page_num ] ) ) continue;

            $page_obj_num    = $page_objs[ $page_num ]['num'];
            $page_body       = $page_objs[ $page_num ]['body'];
            $page_needs_helv  = false;
            $page_font_name   = null; // resolved on first placement
            $page_xobj_entries = array(); // sig image XObjects to register

            // Get MediaBox from ref_pdf page if available (more reliable for geometry)
            $ref_page_body = $page_body;
            if ( $ref_pdf !== '' && isset( $ref_index[ $page_obj_num ] ) ) {
                $rb = self::get_obj_body( $ref_pdf, $ref_index[ $page_obj_num ] );
                if ( $rb ) $ref_page_body = $rb;
            }

            // Detect page dimensions from MediaBox
            // Default US Letter -- overridden immediately by /MediaBox if found
            $page_w = 612.0; $page_h = 792.0;
            if ( preg_match( '/\/MediaBox\s*\[\s*[\d.+-]+\s+[\d.+-]+\s+([\d.+-]+)\s+([\d.+-]+)\s*\]/', $ref_page_body, $mb ) ) {
                $page_w = floatval( $mb[1] );
                $page_h = floatval( $mb[2] );
            } elseif ( preg_match( '/\/MediaBox\s*\[\s*[\d.+-]+\s+[\d.+-]+\s+([\d.+-]+)\s+([\d.+-]+)\s*\]/', $page_body, $mb ) ) {
                $page_w = floatval( $mb[1] );
                $page_h = floatval( $mb[2] );
            }

            // Detect Y-axis flip: some generators (Word, LibreOffice) open with a global
            // "1 0 0 -1 0 H cm" transform that maps Y=0 to the TOP of the page.
            // In that coordinate system our canvas px->PDF conversion must NOT invert Y.
            // Y-flip detection: PDFs from Word/LibreOffice use "1 0 0 -1 0 H cm" as their
            // first content stream op. This flips the coordinate system so y=0 is at the TOP.
            // Our appended content stream ALSO runs in this flipped space because the CTM
            // set in the preceding stream carries over within the same page rendering context.
            // (PDF.js confirms this -- content drawn in "normal" coords appears at wrong position.)
            //
            // For y-flipped pages we must:
            //   1. Use pdf_y = canvas_y * sy (no y-inversion -- y already increases downward)
            //   2. Prepend "q 1 0 0 -1 0 {page_h} cm" to our ops so graphics state matches
            //   3. Use Tm d=-1 for text (to counteract the d=-1 in the CTM for glyph orientation)
            //   4. For images: flip the cm matrix y-scale too
            $page_y_flipped = false;
            {
                $cs_nums = array();
                if ( preg_match( '/\/Contents\s+(\d+)\s+0\s+R/', $page_body, $_cm ) ) {
                    $cs_nums[] = (int) $_cm[1];
                } elseif ( preg_match( '/\/Contents\s*\[([^\]]+)\]/', $page_body, $_cm ) ) {
                    preg_match_all( '/(\d+)\s+0\s+R/', $_cm[1], $_refs );
                    foreach ( $_refs[1] as $_r ) $cs_nums[] = (int) $_r;
                }
                foreach ( $cs_nums as $_csn ) {
                    if ( ! isset( $pdf_index[ $_csn ] ) ) continue;
                    $_csi = $pdf_index[ $_csn ];
                    if ( $_csi['offset'] < 0 ) continue; // ObjStm -- skip
                    $_raw = self::read_obj_body_raw( $pdf, $_csi['offset'] );
                    if ( ! $_raw ) continue;
                    $_dec = self::decode_stream( $_raw[1], $_raw[0] );
                    if ( $_dec === null ) continue;
                    $_stripped = ltrim( $_dec );
                    // Match first cm operator: a b c d e f cm
                    if ( preg_match( '/^([-\d.e]+)\s+([-\d.e]+)\s+([-\d.e]+)\s+([-\d.e]+)\s+([-\d.e]+)\s+([-\d.e]+)\s+cm\b/', $_stripped, $_cmm ) ) {
                        if ( floatval( $_cmm[4] ) < 0 ) { // d < 0 means Y-axis flipped
                            $page_y_flipped = true;
                        }
                    }
                    break; // only need first content stream
                }
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W page=' . $page_obj_num . ' y_flipped=' . ( $page_y_flipped ? 'YES' : 'NO' ) . ' cs_nums=[' . implode(',', $cs_nums) . ']' );
            }

            // Content ops for this page -- native PDF text operators
            $page_content_ops = array();

            foreach ( $page_placements as $pl ) {
                $cf7_field = $pl['cf7_field'] ?? '';
                // Per-placement canvas size (saved since last build) or global fallback
                $canvas_w = floatval( $pl['canvas_w'] ?? 0 );
                $canvas_h = floatval( $pl['canvas_h'] ?? 0 );
                if ( $canvas_w < 1 ) $canvas_w = $global_canvas_w;
                if ( $canvas_h < 1 ) $canvas_h = $global_canvas_h;

                // -- Signature field: stamp PNG image at VP coords --
                // Also match any field whose name starts with 'cf7w_signature' as a safety net
                $is_sig_field = in_array( $cf7_field, $sig_field_names, true )
                             || in_array( sanitize_key( $cf7_field ), $sig_field_names, true )
                             || ( strpos( $cf7_field, 'cf7w_signature' ) === 0 )
                             || ( strpos( sanitize_key( $cf7_field ), 'cf7w_signature' ) === 0 );
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W vp is_sig_field=' . ($is_sig_field?'YES':'NO') . ' cf7_field=' . $cf7_field . ' sig_field_names=[' . implode(',', $sig_field_names) . '] sig_path_ok=' . ($sig_path && file_exists($sig_path) ? 'YES' : 'NO') );
                if ( $is_sig_field ) {
                    if ( $sig_path && file_exists( $sig_path ) ) {
                        // Stamp inline -- converted to JPEG and written as an Image XObject
                        $im_sig = @imagecreatefrompng( $sig_path );
                        if ( $im_sig ) {
                            $sw = imagesx( $im_sig ); $sh = imagesy( $im_sig );
                            $flat = imagecreatetruecolor( $sw, $sh );
                            $white = imagecolorallocate( $flat, 255, 255, 255 );
                            imagefill( $flat, 0, 0, $white );
                            imagecopy( $flat, $im_sig, 0, 0, 0, 0, $sw, $sh );
                            imagedestroy( $im_sig );
                            ob_start(); imagejpeg( $flat, null, 92 ); $sig_jpeg = ob_get_clean(); imagedestroy( $flat );
                            if ( $sig_jpeg ) {
                                // Compute PDF coords
                                $sig_px_x   = floatval( $pl['x'] ?? 0 );
                                $sig_px_y   = floatval( $pl['y'] ?? 0 );
                                $sig_px_w   = floatval( $pl['w'] ?? 150 );
                                $sig_px_h   = floatval( $pl['h'] ?? 50 );
                                $sig_x_pts  = $sig_px_x * ( $page_w / $canvas_w );
                                $sig_w_pts  = $sig_px_w * ( $page_w / $canvas_w );
                                $sig_h_pts  = $sig_px_h * ( $page_h / $canvas_h );
                                if ( $page_y_flipped ) {
                                    // y-flipped: y=0 at top, origin is top-left of field
                                    $sig_y_pts = $sig_px_y * ( $page_h / $canvas_h );
                                    // cm matrix: scale w x -h, translate to bottom-left of image
                                    // In flipped space the "bottom" of the field is at y + h
                                    $sig_cm = "{$sig_w_pts} 0 0 -{$sig_h_pts} {$sig_x_pts} " . ($sig_y_pts + $sig_h_pts);
                                } else {
                                    // normal: y=0 at bottom, origin is bottom-left of field
                                    $sig_y_pts = $page_h - ( $sig_px_y * ( $page_h / $canvas_h ) ) - $sig_h_pts;
                                    $sig_cm    = "{$sig_w_pts} 0 0 {$sig_h_pts} {$sig_x_pts} {$sig_y_pts}";
                                }
                                $sig_jpeg = rtrim( $sig_jpeg, "
" );
                                $sig_jlen = strlen( $sig_jpeg );
                                $max_obj++;
                                $sig_img_num = $max_obj;
                                $updates[ $sig_img_num ] = "{$sig_img_num} 0 obj
"
                                    . "<</Type /XObject /Subtype /Image /Width {$sw} /Height {$sh}"
                                    . " /ColorSpace /DeviceRGB /BitsPerComponent 8"
                                    . " /Filter /DCTDecode /Length {$sig_jlen}>>
"
                                    . "stream
{$sig_jpeg}
endstream
endobj
";
                                // Add Do operator to page content
                                $sig_img_name = "CF7SigImg{$sig_img_num}";
                                $page_content_ops[] = "q {$sig_cm} cm /{$sig_img_name} Do Q";
                                $page_xobj_entries[ $sig_img_name ] = $sig_img_num;
                                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log("CF7W: stamped sig image at x={$sx} y={$sy} w={$sw_pts} h={$sh_pts}");
                            }
                        }
                    }
                    continue; // never render sig field as text
                }

                $value = $field_values[ $cf7_field ] ?? '';
                if ( $value === '' ) $value = $field_values[ sanitize_key( $cf7_field ) ] ?? '';
                if ( $value === '' ) $value = $field_values[ strtolower( $cf7_field ) ] ?? '';
                if ( $value === '' ) {
                    ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp: no value for "' . $cf7_field . '" -- keys: ' . implode( ', ', array_keys( $field_values ) ) );
                    continue;
                }

                $px_x = floatval( $pl['x'] ?? 0 );
                $px_y = floatval( $pl['y'] ?? 0 );
                $px_w = floatval( $pl['w'] ?? 180 );
                $px_h = floatval( $pl['h'] ?? 24 );

                // Scale canvas px -> PDF points
                $sx = $page_w / $canvas_w;
                $sy = $page_h / $canvas_h;

                $x = $px_x * $sx;
                $w = $px_w * $sx;
                $h = $px_h * $sy;
                // Convert canvas coords to PDF coords.
                // y-flipped pages: canvas y=0 is at the TOP, and y increases downward (same as canvas).
                // Normal pages: PDF y=0 is at BOTTOM, so we must invert.
                if ( $page_y_flipped ) {
                    $vp_y = $px_y * $sy;               // y increases downward already
                } else {
                    $vp_y = $page_h - ( $px_y * $sy ) - $h; // flip to bottom-left origin
                }

                $fs = $font_size;

                // Render text to GD image at 2-- for clarity
                // -- Render text directly as PDF operators --
                // No GD image, no white box -- native PDF text operators.
                // We look up the page's existing font dict and use whatever font
                // is already registered -- zero resource dict changes needed.
                // Scale UI font size to PDF points. 0.85 converts screen px to pt approximately.
                $pdf_fs = max( 6.0, $fs * 0.85 );

                // Always use CF7Helv (standard Helvetica) -- never reuse page fonts because
                // embedded subset fonts only contain the glyphs of the original text and will
                // silently drop any character not in the subset.
                $page_font_name  = 'CF7Helv';
                $page_needs_helv = true;

                // Helvetica average glyph width -- 0.55-- font size (industry standard metric).
                // Line height = 1.2-- font size (standard leading for body text).
                $char_w_pts = $pdf_fs * 0.55;
                $max_chars  = max( 1, (int) floor( $w / $char_w_pts ) );
                $text_lines = self::wrap_text_pdf( $value, $max_chars );
                $line_h_pts = $pdf_fs * 1.2;

                $n_lines  = count( $text_lines );
                $text_ops = array();

                if ( $page_y_flipped ) {
                    // Y-flipped page: y=0 is at TOP, y increases downward (like canvas).
                    // vp_y is the TOP edge of the field box. vp_y+h is the BOTTOM edge.
                    // Background rect: origin (x, vp_y) extends downward by h.
                    if ( $bg_color !== 'transparent' ) {
                        $rgb_map = array( 'white' => '1 1 1', 'yellow' => '1 1 0.6', 'cyan' => '0.7 0.95 1' );
                        $rgb = $rgb_map[ $bg_color ] ?? '1 1 1';
                        $text_ops[] = "q {$rgb} rg {$x} {$vp_y} {$w} {$h} re f Q";
                    }
                    // Text: use Tm with d=-1 to counteract the page CTM flip (glyphs render right-side up).
                    // With Tm d=-1, the combined CTM*Tm has d=+1 so text renders UPWARD from the Tm y position.
                    // "Upward" in device space = toward SMALLER y in flipped space = toward TOP of page.
                    // So the Tm y position is the BOTTOM edge of the text (visually).
                    // To align text at the BOTTOM of the field box (like normal PDF placement):
                    //   first line Tm y = vp_y + h - 1.5pt  (near bottom edge of box)
                    //   multi-line: each additional line is at a SMALLER y (stacking upward)
                    $baseline_y = $vp_y + $h - 1.5; // 1.5pt from bottom edge (for descenders)
                    foreach ( $text_lines as $li => $tline ) {
                        // Line 0 = first (bottom) line; higher lines go UP (decreasing y in flipped space)
                        $line_y = $baseline_y - ( $li * $line_h_pts );
                        if ( $line_y < $vp_y ) continue; // clip above box top
                        $safe = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $tline );
                        // 1 0 0 -1 x y Tm: d=-1 cancels CTM d=-1 => net d=+1 => upright glyphs
                        $text_ops[] = "BT /{$page_font_name} {$pdf_fs} Tf 1 0 0 -1 {$x} {$line_y} Tm 0 0 0 rg ({$safe}) Tj ET";
                    }
                } else {
                    // Normal page: y=0 at BOTTOM. vp_y is the BOTTOM edge of the field box.
                    if ( $bg_color !== 'transparent' ) {
                        $rgb_map = array( 'white' => '1 1 1', 'yellow' => '1 1 0.6', 'cyan' => '0.7 0.95 1' );
                        $rgb = $rgb_map[ $bg_color ] ?? '1 1 1';
                        $text_ops[] = "q {$rgb} rg {$x} {$vp_y} {$w} {$h} re f Q";
                    }
                    // Bottom-left alignment: first line baseline sits just above vp_y.
                    // Additional lines stack upward (higher y = higher on page).
                    $baseline_y = $vp_y + 1.5; // 1.5pt above bottom edge for descenders
                    foreach ( $text_lines as $li => $tline ) {
                        // Line 0 = bottom line; last line = top line
                        $line_y = $baseline_y + ( ( $n_lines - 1 - $li ) * $line_h_pts );
                        if ( $line_y + $pdf_fs > $vp_y + $h + 2 ) continue; // clip above box top
                        $safe = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $tline );
                        $text_ops[] = "BT /{$page_font_name} {$pdf_fs} Tf {$x} {$line_y} Td 0 0 0 rg ({$safe}) Tj ET";
                    }
                }

                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W stamp_vp: field='{$cf7_field}' pdf_x={$x} pdf_y={$vp_y} pdf_w={$w} pdf_h={$h} fs={$pdf_fs} page={$page_w}x{$page_h}" );
                if ( empty( $text_ops ) ) { ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log("CF7W stamp_vp: no text_ops for '{$cf7_field}' value='".substr($value,0,30)."' w={$w} h={$h} pdf_fs={$pdf_fs}"); continue; }
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W stamp_vp: generated ".count($text_ops)." text_ops for '{$cf7_field}'" );
                $page_content_ops = array_merge( $page_content_ops, $text_ops );
            }


            if ( empty( $page_content_ops ) ) continue;

            // -- Content stream for this page --
            $cs_content = implode( "\n", $page_content_ops ) . "\n";
            $max_obj++;
            $cs_num = $max_obj;
            $updates[ $cs_num ] = "{$cs_num} 0 obj\n<</Length " . strlen( $cs_content ) . ">>\nstream\n{$cs_content}\nendstream\nendobj\n";

            // -- Extend page /Contents --
            $new_page_body = $page_body;
            if ( preg_match( '/\/Contents\s*\[([^\]]*)\]/', $new_page_body, $cm ) ) {
                $new_page_body = str_replace( $cm[0], '/Contents [' . trim( $cm[1] ) . ' ' . $cs_num . ' 0 R]', $new_page_body );
            } elseif ( preg_match( '/\/Contents\s+(\d+\s+\d+\s+R)/', $new_page_body, $cm ) ) {
                $new_page_body = str_replace( $cm[0], '/Contents [' . $cm[1] . ' ' . $cs_num . ' 0 R]', $new_page_body );
            } else {
                $new_page_body = self::inject_before_end( $new_page_body, '/Contents [' . $cs_num . ' 0 R]' );
            }


            // -- Filter Widget annotations from /Annots to prevent AcroForm white boxes --
            // AcroForm Widget annotations have /AP (appearance) streams that draw white
            // boxes over form fields, which would cover our stamped text. We selectively
            // remove only Widget-subtype annotation refs, preserving non-Widget annotations
            // (links, comments, stamps, etc.). For indirect /Annots N 0 R we strip the
            // whole ref since we can't partially rewrite the array without a new object.
            $had_annots = ( strpos( $new_page_body, '/Annots' ) !== false );
            if ( $had_annots ) {
                // Case A: inline /Annots [24 0 R 25 0 R ...]
                if ( preg_match( '/\/Annots\s*\[([^\]]*)\]/', $new_page_body, $ann_m ) ) {
                    $annot_refs = preg_split( '/\s+/', trim( $ann_m[1] ) );
                    // Parse ref triplets: "N 0 R"
                    $keep_refs = array();
                    for ( $ai = 0; $ai + 2 < count( $annot_refs ); $ai += 3 ) {
                        if ( $annot_refs[ $ai + 1 ] !== '0' || $annot_refs[ $ai + 2 ] !== 'R' ) continue;
                        $ann_num = (int) $annot_refs[ $ai ];
                        // Read the annotation object to check its /Subtype
                        $is_widget = false;
                        if ( isset( $pdf_index[ $ann_num ] ) ) {
                            $ann_body = self::get_obj_body( $pdf, $pdf_index[ $ann_num ] );
                            if ( $ann_body !== null ) {
                                $is_widget = (bool) preg_match( '/\/Subtype\s*\/Widget\b/', $ann_body );
                            }
                        }
                        if ( ! $is_widget ) {
                            $keep_refs[] = $annot_refs[ $ai ] . ' 0 R';
                        }
                    }
                    if ( empty( $keep_refs ) ) {
                        $new_page_body = preg_replace( '/\/Annots\s*\[[^\]]*\]/', '', $new_page_body );
                    } else {
                        $new_page_body = preg_replace(
                            '/\/Annots\s*\[[^\]]*\]/',
                            '/Annots [' . implode( ' ', $keep_refs ) . ']',
                            $new_page_body
                        );
                    }
                } else {
                    // Case B: indirect /Annots N 0 R -- can't filter without rewriting the array obj
                    // Strip entirely to prevent Widget AP streams covering our text.
                    $new_page_body = preg_replace( '/\/Annots\s+\d+\s+0\s+R/', '', $new_page_body );
                }
            }
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W vp page=' . $page_obj_num . ' had_annots=' . ($had_annots?'YES':'NO') . ' annots_after=' . (strpos($new_page_body,'/Annots')!==false?'KEPT':'gone') );

            // -- Register /CF7Helv font in /Resources --
            // Our text ops use /CF7Helv which maps to standard Helvetica.
            // We only need to add it if the page's resource dict doesn't already have it.
            $helv_entry = '/CF7Helv<</Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding>>';
            if ( ! empty( $page_needs_helv ) ) {
                if ( preg_match( '/\/Resources\s+(\d+)\s+0\s+R/', $new_page_body, $rr ) ) {
                    $res_num  = (int) $rr[1];
                    // Try current pdf first, then ref_pdf as fallback (e.g. after pdftk restructure)
                    $res_body_from_pdf = isset( $pdf_index[ $res_num ] ) ? self::get_obj_body( $pdf, $pdf_index[ $res_num ] ) : null;
                    $res_body_from_ref = ( $res_body_from_pdf === null && $ref_pdf !== '' && isset( $ref_index[ $res_num ] ) )
                                       ? self::get_obj_body( $ref_pdf, $ref_index[ $res_num ] ) : null;
                    $res_body  = $res_body_from_pdf ?? $res_body_from_ref;
                    $res_pdf   = ( $res_body_from_pdf !== null ) ? $pdf : $ref_pdf;
                    $res_index = ( $res_body_from_pdf !== null ) ? $pdf_index : $ref_index;
                    ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( sprintf( 'CF7W font-reg: page=%d res_obj=%d in_pdf_idx=%s pdf_readable=%s ref_readable=%s',
                        $page_obj_num, $res_num,
                        isset($pdf_index[$res_num]) ? 'yes(off='.$pdf_index[$res_num]['offset'].',type='.(isset($pdf_index[$res_num]['stm'])?'ObjStm':'direct').')' : 'no',
                        $res_body_from_pdf !== null ? 'YES' : 'NO',
                        $res_body_from_ref !== null ? 'YES' : 'NO'
                    ) );
                    if ( $res_body !== null ) {
                        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W font-reg: res_body first 80=' . substr($res_body,0,80) );
                        $new_res_body = self::add_font_to_res_dict( $res_body, $helv_entry, $updates, $res_pdf, $res_index );
                        $changed = ($new_res_body !== $res_body);
                        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W font-reg: add_font_to_res_dict changed=' . ($changed?'YES':'NO') );
                        // Always write res_body to updates when read from ref_pdf -- it needs to exist in output pdf too.
                        // Also write when inline-patched (changed=YES). Skip only when res came from $pdf and unchanged.
                        if ( $changed || $res_pdf !== $pdf ) {
                            $updates[ $res_num ] = "{$res_num} 0 obj\n" . trim( $new_res_body ) . "\nendobj\n";
                            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W font-reg: wrote res obj ' . $res_num . ' to updates (changed=' . ($changed?'Y':'N') . ' from_ref=' . ($res_pdf!==$pdf?'Y':'N') . ')' );
                        }
                    } else {
                        // Cannot read resource dict at all -- write a new standalone resource obj
                        $new_res_num = ++$max_obj;
                        $updates[ $new_res_num ] = "{$new_res_num} 0 obj\n<</Font<<\n{$helv_entry}\n>>>>\nendobj\n";
                        // Point page to our new resource obj
                        $new_page_body = preg_replace( '#/Resources\s+\d+\s+0\s+R#',
                            "/Resources {$new_res_num} 0 R", $new_page_body );
                        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W font-reg: res obj {$res_num} unreadable from both pdf and ref -- created new res obj {$new_res_num}" );
                    }
                } else {
                    // Inline /Resources in page body (or no /Resources at all).
                    // Look up inherited /Resources from the /Parent /Pages node so that we
                    // carry existing fonts (e.g. F1, F2) into the page-level dict we create.
                    // Without this, pages that inherit resources from /Pages would lose their
                    // fonts when we add our own /Resources dict (CSS-like specificity: page
                    // dict wins over /Pages dict, so it must contain ALL needed entries).
                    $parent_res_body_inh = '';
                    if ( preg_match( '/\/Parent\s+(\d+)\s+0\s+R/', $new_page_body, $_pr ) ) {
                        $_par_num = (int) $_pr[1];
                        $_par_body = isset( $pdf_index[ $_par_num ] ) ? self::get_obj_body( $pdf, $pdf_index[ $_par_num ] ) : null;
                        if ( $_par_body !== null && preg_match( '/\/Resources\s+(\d+)\s+0\s+R/', $_par_body, $_prr ) ) {
                            $_par_res_num = (int) $_prr[1];
                            $parent_res_body_inh = isset( $pdf_index[ $_par_res_num ] )
                                ? ( self::get_obj_body( $pdf, $pdf_index[ $_par_res_num ] ) ?? '' ) : '';
                        } elseif ( $_par_body !== null && preg_match( '/\/Resources\s*<</', $_par_body, $_prm, PREG_OFFSET_CAPTURE ) ) {
                            $_ds = $_prm[0][1] + strlen( $_prm[0][0] ) - 2;
                            $parent_res_body_inh = self::extract_dict( $_par_body, $_ds ) ?? '';
                        }
                        if ( $parent_res_body_inh !== '' ) {
                            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W font-reg: inherited parent res from obj ' . $_par_num . ' (len=' . strlen($parent_res_body_inh) . ')' );
                        }
                    }
                    $new_page_body = self::add_font_to_page_body( $new_page_body, $helv_entry, $updates, $pdf, $pdf_index, $parent_res_body_inh );
                }
            }

            // -- Register any signature image XObjects in /Resources --
            if ( ! empty( $page_xobj_entries ) ) {
                $xobj_entries_str = '';
                foreach ( $page_xobj_entries as $fname => $fnum ) {
                    $xobj_entries_str .= "/{$fname} {$fnum} 0 R\n";
                }
                $xobj_entries_str = rtrim( $xobj_entries_str );
                if ( preg_match( '#/Resources\s+(\d+)\s+0\s+R#', $new_page_body, $rr ) ) {
                    $res_num  = (int) $rr[1];
                    $res_body = isset( $pdf_index[ $res_num ] ) ? self::get_obj_body( $pdf, $pdf_index[ $res_num ] ) : null;
                    if ( $res_body === null && $ref_pdf !== '' && isset( $ref_index[ $res_num ] ) ) {
                        $res_body = self::get_obj_body( $ref_pdf, $ref_index[ $res_num ] );
                    }
                    if ( $res_body !== null ) {
                        $res_pdf   = ( isset( $pdf_index[ $res_num ] ) && self::get_obj_body( $pdf, $pdf_index[ $res_num ] ) !== null ) ? $pdf : $ref_pdf;
                        $res_index = ( $res_pdf === $pdf ) ? $pdf_index : $ref_index;
                        $new_res_body = self::add_xobjects_to_res_dict( $res_body, $xobj_entries_str, $updates, $res_pdf, $res_index );
                        if ( $new_res_body !== $res_body ) {
                            $updates[ $res_num ] = "{$res_num} 0 obj\n" . trim( $new_res_body ) . "\nendobj\n";
                        }
                    } else {
                        $new_page_body = self::add_xobjects_to_page_body( $new_page_body, $xobj_entries_str, $updates, $pdf, $pdf_index );
                    }
                } else {
                    $new_page_body = self::add_xobjects_to_page_body( $new_page_body, $xobj_entries_str, $updates, $pdf, $pdf_index );
                }
            }

            $updates[ $page_obj_num ] = "{$page_obj_num} 0 obj\n" . trim( $new_page_body ) . "\nendobj\n";
            $page_needs_helv  = false;
            $page_xobj_entries = array(); // reset for next page
        }

        if ( empty( $updates ) ) return $pdf;

        // Log what we're about to write so we can verify font dict is included
        $upd_summary = array();
        foreach ( $updates as $n => $body ) {
            $preview = substr( str_replace( "\n", ' ', $body ), 0, 60 );
            $upd_summary[] = "obj{$n}(" . strlen($body) . "b)={$preview}";
        }
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp updates: ' . implode( ' | ', $upd_summary ) );

        $result = self::apply_incremental( $pdf, $updates );
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp apply_incremental: in=' . strlen($pdf) . ' out=' . strlen($result) . ' delta=' . (strlen($result)-strlen($pdf)) );
        return $result;
    }

    // -- Add a font entry to a resource dict body.
    // $updates passed by ref -- if /Font is indirect, we rewrite that obj directly.
    private static function add_font_to_res_dict(
        string $res_body,
        string $font_entry,
        array  &$updates,
        string $pdf,
        array  $pdf_index
    ): string {
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W add_font_to_res_dict INPUT: " . substr($res_body, 0, 300) );
        
        if ( strpos( $res_body, '/CF7Helv' ) !== false ) return $res_body; // already registered

        // Case A: /Font << ... >> inline -- add entry inside it
        if ( preg_match( '#/Font\s*<<#', $res_body, $m, PREG_OFFSET_CAPTURE ) ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: Found inline Font at position " . $m[0][1] );
            $dict_start = $m[0][1] + strlen( $m[0][0] ) - 2;
            $inner = self::extract_dict( $res_body, $dict_start );
            if ( $inner !== null ) {
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: Font dict extracted, length=" . strlen($inner) . " content=" . substr($inner, 0, 100) );
                $patched = substr( $inner, 0, -2 ) . "\n" . $font_entry . "\n>>";
                $result = substr( $res_body, 0, $dict_start ) . $patched . substr( $res_body, $dict_start + strlen( $inner ) );
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W add_font_to_res_dict OUTPUT: " . substr($result, 0, 300) );
                return $result;
            }
        }

        // Case B: /Font N 0 R (indirect) -- read that obj and add entry, OR inline-replace if unreadable
        if ( preg_match( '#/Font\s+(\d+)\s+0\s+R#', $res_body, $fr ) ) {
            $font_dict_num = (int) $fr[1];
            $font_body = isset( $pdf_index[ $font_dict_num ] )
                       ? self::get_obj_body( $pdf, $pdf_index[ $font_dict_num ] ) : null;
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W add_font_to_res_dict: Case B font_dict={$font_dict_num} readable=" . ($font_body!==null?'YES':'NO') );
            if ( $font_body !== null && strpos( $font_body, '/CF7Helv' ) === false ) {
                // Can read it -- patch the font dict object directly
                $patched_font = self::inject_before_end( $font_body, $font_entry );
                $updates[ $font_dict_num ] = "{$font_dict_num} 0 obj\n" . trim( $patched_font ) . "\nendobj\n";
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W add_font_to_res_dict: patched font dict obj {$font_dict_num} in updates" );
                return $res_body; // res_body unchanged; font obj written to $updates
            }
            // Cannot read the font dict (ObjStm, wrong pdf, etc.) OR CF7Helv already there.
            // Replace the indirect /Font ref with an inline dict that includes CF7Helv.
            // We merge in the existing font entries by reading what we can, then add ours.
            $existing_fonts = '';
            if ( $font_body !== null ) {
                // CF7Helv already present -- nothing to do
                if ( strpos( $font_body, '/CF7Helv' ) !== false ) return $res_body;
                // Extract existing font entries to preserve them inline
                $inner = self::extract_dict( $font_body, 0 );
                if ( $inner ) {
                    $existing_fonts = trim( substr( $inner, 2, -2 ) ) . "\n";
                }
            }
            $new_res = preg_replace( '#/Font\s+\d+\s+0\s+R#',
                "/Font<<\n" . $existing_fonts . $font_entry . "\n>>", $res_body );
            if ( $new_res !== null && $new_res !== $res_body ) {

                return $new_res;
            }
        }

        // Case C: no /Font at all -- add inline
        return self::inject_before_end( $res_body, "/Font<<\n" . $font_entry . "\n>>" );
    }

    // -- Add font entry to a page body with inline /Resources --
    private static function add_font_to_page_body(
        string $page_body,
        string $font_entry,
        array  &$updates,
        string $pdf,
        array  $pdf_index,
        string $parent_res_body = '' // optional: inherited /Resources dict body from /Pages node
    ): string {
        if ( preg_match( '#/Resources\s*<<#', $page_body, $m, PREG_OFFSET_CAPTURE ) ) {
            $dict_start = $m[0][1] + strlen( $m[0][0] ) - 2;
            $inner = self::extract_dict( $page_body, $dict_start );
            if ( $inner !== null ) {
                $new_inner = self::add_font_to_res_dict( $inner, $font_entry, $updates, $pdf, $pdf_index );
                if ( $new_inner === $inner ) return $page_body; // font dict rewritten via $updates
                return substr( $page_body, 0, $dict_start ) . $new_inner . substr( $page_body, $dict_start + strlen( $inner ) );
            }
        }
        // No inline /Resources on the page. If we have an inherited resources dict from the
        // parent /Pages node, use it as the base so existing fonts (F1, F2, etc.) remain accessible.
        // Without this, adding /Resources<</Font<<CF7Helv>>>> would SHADOW the parent resources
        // and all inherited fonts would disappear, causing text to render as garbage.
        if ( $parent_res_body !== '' && strpos( $parent_res_body, '<<' ) !== false ) {
            $new_res = self::add_font_to_res_dict( $parent_res_body, $font_entry, $updates, $pdf, $pdf_index );
            return self::inject_before_end( $page_body, "/Resources {$new_res}" );
        }
        return self::inject_before_end( $page_body, "/Resources<</Font<<\n" . $font_entry . "\n>>>>" );
    }

    // -- Find first usable font name already registered in page resources --
    // Returns the PDF resource name (e.g. "F1", "TT2") to use in Tf operator,
    // or null if none found (caller then registers Helvetica).
    private static function find_page_font( string $page_body, string $pdf, array $pdf_index ): ?string {
        // Get the resource dict -- may be inline or indirect
        $res_body = null;
        if ( preg_match( '#/Resources\s+(\d+)\s+0\s+R#', $page_body, $rr ) ) {
            $res_num  = (int) $rr[1];
            $res_body = isset( $pdf_index[ $res_num ] ) ? self::get_obj_body( $pdf, $pdf_index[ $res_num ] ) : null;
        } elseif ( preg_match( '#/Resources\s*<<#', $page_body, $rm, PREG_OFFSET_CAPTURE ) ) {
            $dict_start = $rm[0][1] + strlen( $rm[0][0] ) - 2;
            $res_body   = self::extract_dict( $page_body, $dict_start );
        }
        if ( $res_body === null ) return null;

        // Get the font dict -- may be inline or indirect
        $font_body = null;
        if ( preg_match( '#/Font\s+(\d+)\s+0\s+R#', $res_body, $fr ) ) {
            $font_num  = (int) $fr[1];
            $font_body = isset( $pdf_index[ $font_num ] ) ? self::get_obj_body( $pdf, $pdf_index[ $font_num ] ) : null;
        } elseif ( preg_match( '#/Font\s*<<#', $res_body, $fm, PREG_OFFSET_CAPTURE ) ) {
            $dict_start = $fm[0][1] + strlen( $fm[0][0] ) - 2;
            $font_body  = self::extract_dict( $res_body, $dict_start );
        }
        if ( $font_body === null ) return null;

        // Pick the first font name key in the font dict (e.g. /F1, /TT2, /Helvetica)
        // Font dict entries look like: /Name N 0 R  or  /Name <<...>>
        if ( preg_match( '/\/([A-Za-z][A-Za-z0-9+._-]*)\s*(?:\d+\s+0\s+R|<<)/', $font_body, $fm2 ) ) {
            return $fm2[1];
        }
        return null;
    }

    // -- Word-wrap for PDF text (character-count based) --
    private static function wrap_text_pdf( string $text, int $max_chars ): array {
        $words = explode( ' ', $text );
        $lines = array(); $cur = '';
        foreach ( $words as $word ) {
            $test = $cur === '' ? $word : $cur . ' ' . $word;
            if ( mb_strlen( $test ) <= $max_chars ) {
                $cur = $test;
            } else {
                if ( $cur !== '' ) $lines[] = $cur;
                $cur = mb_strlen( $word ) > $max_chars ? mb_substr( $word, 0, $max_chars ) : $word;
            }
        }
        if ( $cur !== '' ) $lines[] = $cur;
        return $lines ?: array( $text );
    }

    /**
     * Simple word-wrap for imagestring (which uses a bitmap font).
     * Returns array of lines.
     */
    private static function wrap_text_gd( string $text, int $max_px_w, int $font_px ): array {
        // imagestring character width -- 6px for built-in fonts at most sizes
        // 0.6-- approximates average Helvetica glyph width in pixels at given font size.
        $char_w = max( 1, (int) round( $font_px * 0.6 ) );
        $max_chars = max( 1, (int) floor( $max_px_w / $char_w ) );
        $words  = explode( ' ', $text );
        $lines  = array();
        $cur    = '';
        foreach ( $words as $word ) {
            $test = $cur === '' ? $word : $cur . ' ' . $word;
            if ( strlen( $test ) <= $max_chars ) {
                $cur = $test;
            } else {
                if ( $cur !== '' ) $lines[] = $cur;
                $cur = strlen( $word ) > $max_chars ? substr( $word, 0, $max_chars ) : $word;
            }
        }
        if ( $cur !== '' ) $lines[] = $cur;
        return $lines ?: array( '' );
    }

    /**
     * Pick the closest built-in GD font (1-5) for a given pixel size.
     */
    private static function gd_font( int $px ): int {
        if ( $px <= 8  ) return 1;
        if ( $px <= 11 ) return 2;
        if ( $px <= 14 ) return 3;
        if ( $px <= 18 ) return 4;
        return 5;
    }

    private static function stamp_signature( string $pdf, array $sig, string $ref_pdf = '' ): string {
        if ( ! function_exists( 'imagecreatefrompng' ) ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp_signature: GD not available' ); return $pdf;
        }
        $im = @imagecreatefrompng( $sig['path'] );
        if ( ! $im ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp_signature: imagecreatefrompng failed for ' . $sig['path'] ); return $pdf;
        }

        // -- Flatten transparency onto white before JPEG encoding --
        // Signature PNGs have a transparent background; JPEG has no alpha channel
        // so any un-flattened transparent pixel becomes black.
        $iw = imagesx( $im );
        $ih = imagesy( $im );
        $flat = imagecreatetruecolor( $iw, $ih );
        $white = imagecolorallocate( $flat, 255, 255, 255 );
        imagefill( $flat, 0, 0, $white );
        imagealphablending( $im, false );
        imagecopy( $flat, $im, 0, 0, 0, 0, $iw, $ih );
        imagedestroy( $im );

        ob_start(); imagejpeg( $flat, null, 92 ); $jpeg = ob_get_clean(); imagedestroy( $flat );
        if ( ! $jpeg ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp_signature: imagejpeg failed' ); return $pdf;
        }

        $img_w = $iw;
        $img_h = $ih;

        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp_signature: pdf_field=' . ($sig['pdf_field']??'(none)')
            . ' vp=' . ( !empty($sig['vp']) ? json_encode(array_intersect_key($sig['vp'],array_flip(['cf7_field','page','x','y','w','h','canvas_w','canvas_h']))) : 'null' )
            . ' explicit x=' . ($sig['x']??'?') . ' y=' . ($sig['y']??'?') . ' page=' . ($sig['page']??'?') );

        // Use $ref_pdf (original) for field /Rect lookup when available,
        // because pdftk may restructure widgets in its output.
        $lookup_pdf = $ref_pdf !== '' ? $ref_pdf : $pdf;
        $index      = self::build_obj_index( $lookup_pdf );

        // -- Find the signature field, its /Rect and its page --
        $place = null; // array( 'page_num' => int, 'x','y','w','h' => float )

        $sig_field_name = $sig['pdf_field'] ?? '';
        if ( $sig_field_name ) {
            foreach ( $index as $obj_n => $info ) {
                $body = self::get_obj_body( $lookup_pdf, $info );
                if ( $body === null ) continue;

                $t = self::extract_T( $body );
                if ( $t === null ) continue;
                $leaf = basename( str_replace( '.', '/', $t ) );
                if ( strcasecmp( $t, $sig_field_name ) !== 0
                  && strcasecmp( $leaf, $sig_field_name ) !== 0 ) continue;

                // The /Rect may be on this object directly (merged field+widget),
                // or on a child widget listed in /Kids (split field/widget pattern).
                $widget_body = null;
                if ( preg_match( '/\/Rect\s*\[/', $body ) ) {
                    // Rect is on the field object itself
                    $widget_body = $body;
                } elseif ( preg_match( '/\/Kids\s*\[([^\]]+)\]/', $body, $km ) ) {
                    // Follow each kid until we find one with /Rect
                    preg_match_all( '/(\d+)\s+0\s+R/', $km[1], $kids );
                    foreach ( $kids[1] as $kid_num ) {
                        $kid_num = (int) $kid_num;
                        if ( ! isset( $index[ $kid_num ] ) ) continue;
                        $kid_body = self::get_obj_body( $lookup_pdf, $index[ $kid_num ] );
                        if ( $kid_body && preg_match( '/\/Rect\s*\[/', $kid_body ) ) {
                            $widget_body = $kid_body;
                            break;
                        }
                    }
                }

                if ( $widget_body === null ) continue; // no Rect found anywhere

                if ( ! preg_match( '/\/Rect\s*\[\s*([\d.+-]+)\s+([\d.+-]+)\s+([\d.+-]+)\s+([\d.+-]+)\s*\]/', $widget_body, $rm ) ) continue;
                $llx = floatval( $rm[1] ); $lly = floatval( $rm[2] );
                $urx = floatval( $rm[3] ); $ury = floatval( $rm[4] );

                // Resolve page number -- check widget body first, then field body
                $page_num = null;
                $page_src = preg_match( '/\/P\s+(\d+)\s+0\s+R/', $widget_body, $pm ) ? $pm[1]
                          : ( preg_match( '/\/P\s+(\d+)\s+0\s+R/', $body, $pm ) ? $pm[1] : null );
                if ( $page_src !== null ) {
                    $page_num = self::resolve_page_number( $lookup_pdf, $index, (int) $page_src );
                }
                if ( $page_num === null ) $page_num = 1;

                $place = array(
                    'page_num' => $page_num,
                    'x' => $llx, 'y' => $lly,
                    'w' => $urx - $llx, 'h' => $ury - $lly,
                );
                break;
            }
            if ( $place === null ) {
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp_signature: field "' . $sig_field_name . '" not found, falling back to explicit coords' );
            }
        }

        // Fall back: use VP canvas placement if the signature was positioned via VP
        if ( $place === null && ! empty( $sig['vp'] ) ) {
            $vp        = $sig['vp'];
            $vp_cw     = floatval( $vp['canvas_w'] ?? 800 );
            $vp_ch     = floatval( $vp['canvas_h'] ?? 1000 );
            // We need page dimensions to convert coords -- use defaults (stamp_signature
            // will use the actual page size from the PDF later, but here we approximate).
            // Store raw VP coords and let stamp_signature convert them.
            $sig['vp_raw'] = $vp;
        }

        // Fall back to explicit placement settings
        if ( $place === null ) {
            if ( ! empty( $sig['vp_raw'] ) ) {
                // Convert VP canvas coords to PDF explicit coords using page dimensions
                // We estimate here; stamp_signature will refine once it finds the page.
                $vp    = $sig['vp_raw'];
                $vp_cw = max( 1.0, floatval( $vp['canvas_w'] ?? 800 ) );
                $vp_ch = max( 1.0, floatval( $vp['canvas_h'] ?? 1000 ) );
                // Will be refined per-page below; store as VP-mode flag
                $place = array(
                    'page_num' => max( 1, (int) ( $vp['page'] ?? 1 ) ),
                    'vp_mode'  => true,
                    'vp'       => $vp,
                    'vp_cw'    => $vp_cw,
                    'vp_ch'    => $vp_ch,
                );
            } else {
                // REMOVED: Hardcoded fallback coordinates
                // Signature will only be stamped if there's a PDF field or visual placement defined
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp_signature: No PDF field, VP placement, or explicit coords found - skipping signature' );
                return $pdf;
            }
        }

        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W stamp_signature: place=' . json_encode($place) );

        // -- Find target page object in $pdf (the PDF we're stamping onto) --
        // Build a separate index from $pdf since $index points into $lookup_pdf.
        $page_index       = ( $ref_pdf !== '' ) ? self::build_obj_index( $pdf ) : $index;
        $target_page_num  = null;
        $target_page_body = null;

        // Use tree-ordered page list (DFS via /Pages /Kids) so page numbers are correct
        // regardless of object number ordering in the index.
        $ordered_pages = array();
        self::collect_pages( $pdf, $page_index, $ordered_pages );
        $target_idx = $place['page_num'] - 1; // 0-based
        if ( isset( $ordered_pages[ $target_idx ] ) ) {
            $obj_n = $ordered_pages[ $target_idx ];
            if ( isset( $page_index[ $obj_n ] ) ) {
                $target_page_num  = $obj_n;
                $target_page_body = self::get_obj_body( $pdf, $page_index[ $obj_n ] );
            }
        }
        // Fallback: sequential scan if tree traversal fails
        if ( $target_page_num === null ) {
            $page_count = 0;
            foreach ( $page_index as $obj_n => $info ) {
                $body = self::get_obj_body( $pdf, $info );
                if ( $body === null ) continue;
                if ( ! preg_match( '/\/Type\s*\/Page\b/', $body ) ) continue;
                if (   preg_match( '/\/Type\s*\/Pages\b/', $body ) ) continue;
                $page_count++;
                if ( $page_count === $place['page_num'] ) {
                    $target_page_num  = $obj_n;
                    $target_page_body = $body;
                    break;
                }
            }
        }

        if ( $target_page_num === null ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W sig: page ' . $place['page_num'] . ' not found (' . $page_count . ' pages in pdf)' );
            return $pdf;
        }
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W sig: found page obj=' . $target_page_num . ' body_first100=' . substr(str_replace("\n"," ",$target_page_body),0,100) );

        // -- Allocate new object numbers above current index max --
        // Use the index rather than a regex over raw bytes (binary JPEG data can
        // contain byte sequences that look like "N 0 obj" and corrupt the max).
        $max_obj = $page_index ? max( array_keys( $page_index ) ) : 1;
        $img_obj = $max_obj + 1;
        $cs_obj  = $max_obj + 2;

        // If placed via VP canvas, convert canvas px to PDF points now that we have page dims
        if ( ! empty( $place['vp_mode'] ) ) {
            $vp    = $place['vp'];
            $vp_cw = $place['vp_cw'];
            $vp_ch = $place['vp_ch'];
            // Get page MediaBox from target_page_body
            // Default US Letter in points (612--792); overridden immediately by page /MediaBox
            $sig_page_w = 612.0; $sig_page_h = 792.0;
            if ( preg_match( '/\/MediaBox\s*\[\s*[\d.+-]+\s+[\d.+-]+\s+([\d.+-]+)\s+([\d.+-]+)\s*\]/', $target_page_body, $smb ) ) {
                $sig_page_w = floatval( $smb[1] ); $sig_page_h = floatval( $smb[2] );
            }
            $s_sx = $sig_page_w / $vp_cw;
            $s_sy = $sig_page_h / $vp_ch;
            $x = floatval( $vp['x'] ?? 0 ) * $s_sx;
            $w = floatval( $vp['w'] ?? 180 ) * $s_sx;
            $h = floatval( $vp['h'] ?? 60 ) * $s_sy;
            // Standard bottom-left PDF conversion (fresh CTM in our stream, per PDF spec)
            $y = $sig_page_h - ( floatval( $vp['y'] ?? 0 ) * $s_sy ) - $h;
        } else {
            $x = $place['x']; $y = $place['y']; $w = $place['w']; $h = $place['h'];
        }

        // -- Image XObject --
        $jpeg        = rtrim( $jpeg, "\r\n" );
        $jpeg_len    = strlen( $jpeg );
        $img_obj_str = "{$img_obj} 0 obj\n"
            . "<</Type /XObject /Subtype /Image"
            . " /Width {$img_w} /Height {$img_h}"
            . " /ColorSpace /DeviceRGB /BitsPerComponent 8"
            . " /Filter /DCTDecode /Length {$jpeg_len}>>\n"
            . "stream\n{$jpeg}\nendstream\nendobj\n";

        // -- Content stream: paint image into the field rect --
        $cs_content = "q\n{$w} 0 0 {$h} {$x} {$y} cm\n/CF7WSig Do\nQ\n";
        $cs_obj_str = "{$cs_obj} 0 obj\n<</Length " . strlen( $cs_content ) . ">>\nstream\n{$cs_content}\nendstream\nendobj\n";

        // -- Patch page /Contents to include our new content stream --
        // Strategy: always rewrite the page dict with /Contents as an array.
        // This is safe because we write it as a new object in the incremental update,
        // overriding the old one. PDF readers use the last definition of each object.
        $page_body = $target_page_body;
        $updates   = array(
            $img_obj => $img_obj_str,
            $cs_obj  => $cs_obj_str,
        );

        if ( preg_match( '/\/Contents\s*\[([^\]]*)\]/', $page_body, $cm ) ) {
            $page_body = str_replace( $cm[0], '/Contents [' . trim( $cm[1] ) . ' ' . $cs_obj . ' 0 R]', $page_body );
        } elseif ( preg_match( '/\/Contents\s+(\d+\s+\d+\s+R)/', $page_body, $cm ) ) {
            $page_body = str_replace( $cm[0], '/Contents [' . $cm[1] . ' ' . $cs_obj . ' 0 R]', $page_body );
        } else {
            $page_body = self::inject_before_end( $page_body, '/Contents [' . $cs_obj . ' 0 R]' );
        }

        // -- Register CF7WSig XObject -- handle all levels of indirection --
        // Walk: page /Resources (may be indirect) -- /XObject (may be indirect)
        // We patch whichever object actually holds the /XObject dict entries.
        $xobj_entry  = '/CF7WSig ' . $img_obj . ' 0 R';
        $xobj_placed = false;

        $res_body    = null;
        $res_obj_num = null;

        // Step 1: get the resource dict body
        if ( preg_match( '/\/Resources\s+(\d+)\s+0\s+R/', $page_body, $rr ) ) {
            $res_obj_num = (int) $rr[1];
            if ( isset( $page_index[ $res_obj_num ] ) ) {
                $res_body = self::get_obj_body( $pdf, $page_index[ $res_obj_num ] );
            }
        } elseif ( preg_match( '/\/Resources\s*<</', $page_body ) ) {
            // Inline -- treat page body as the resource body for XObject injection
            $res_body    = $page_body;
            $res_obj_num = null; // signals: modify page_body
        }

        if ( $res_body !== null ) {
            // Step 2: check if /XObject is inline or indirect within the resource dict
            if ( preg_match( '#/XObject\s+(\d+)\s+0\s+R#', $res_body, $xr ) ) {
                // /XObject is a separate dict object -- patch it directly
                $xobj_dict_num = (int) $xr[1];
                if ( isset( $page_index[ $xobj_dict_num ] ) ) {
                    $xobj_body = self::get_obj_body( $pdf, $page_index[ $xobj_dict_num ] );
                    if ( $xobj_body !== null ) {
                        // Add our entry before closing >>
                        $new_xobj_body = self::inject_before_end( $xobj_body, $xobj_entry );
                        $updates[ $xobj_dict_num ] = "{$xobj_dict_num} 0 obj\n" . trim( $new_xobj_body ) . "\nendobj\n";
                        $xobj_placed = true;
                    }
                }
                if ( ! $xobj_placed ) {
                    // Can't read the XObject dict -- add a new inline /XObject to the resource dict
                }
            }

            if ( ! $xobj_placed ) {
                // /XObject is inline in res_body, or we couldn't patch it indirectly
                $new_res_body = self::inject_xobject( $res_body, $xobj_entry );
                if ( $res_obj_num !== null ) {
                    $updates[ $res_obj_num ] = "{$res_obj_num} 0 obj\n" . trim( $new_res_body ) . "\nendobj\n";
                } else {
                    $page_body = $new_res_body; // was inline in page
                }
                $xobj_placed = true;
            }
        }

        if ( ! $xobj_placed ) {
            // No /Resources found at all -- inject into page body
            $page_body = self::inject_xobject( $page_body, $xobj_entry );
        }

        $updates[ $target_page_num ] = "{$target_page_num} 0 obj\n" . trim( $page_body ) . "\nendobj\n";
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W sig: final page body first100=' . substr(str_replace("\n"," ",$page_body),0,100) );
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W sig: updates objs=[' . implode(',',array_keys($updates)) . ']' );

        return self::apply_incremental( $pdf, $updates );
    }

    // -- Add XObject entries to a resource dict body (the dict itself, starts with <<).
    // $updates is passed by reference so we can rewrite indirect XObject dicts.
    // $pdf and $pdf_index are needed to read indirect objects.
    private static function add_xobjects_to_res_dict(
        string $res_body,
        string $xobj_entries,
        array  &$updates,
        string $pdf,
        array  $pdf_index
    ): string {
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W add_xobjects_to_res_dict: res_body=" . substr($res_body, 0, 200) );
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W add_xobjects_to_res_dict: xobj_entries=" . $xobj_entries );
        
        // Case A: /XObject << ... >> exists inline -- insert entries before closing >>
        if ( preg_match( '#/XObject\s*<<#s', $res_body, $m, PREG_OFFSET_CAPTURE ) ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: Found inline XObject at position " . $m[0][1] );
            $dict_start = $m[0][1] + strlen( $m[0][0] ) - 2;
            $inner = self::extract_dict( $res_body, $dict_start );
            if ( $inner !== null ) {
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: extract_dict SUCCEEDED, inner=" . substr($inner, 0, 100) );
                $patched = substr( $inner, 0, -2 ) . "\n" . $xobj_entries . "\n>>";
                $result = substr( $res_body, 0, $dict_start ) . $patched . substr( $res_body, $dict_start + strlen( $inner ) );
                ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: Patched result=" . substr($result, 0, 250) );
                return $result;
            }
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: extract_dict FAILED (returned null) - using fallback" );
            // BUGFIX: extract_dict failed, but /XObject exists inline
            // Extract existing entries manually and merge with new ones
            $existing_entries = self::extract_xobject_entries_from_inline( $res_body );
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: Extracted existing entries: " . $existing_entries );
            $merged_entries = $existing_entries . "\n" . $xobj_entries;
            // Remove old /XObject dict and add merged one
            $res_without_xobj = self::remove_xobject_dict( $res_body );
            $result = self::inject_before_end( $res_without_xobj, "/XObject<<\n" . $merged_entries . "\n>>" );
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: Merged result=" . substr($result, 0, 250) );
            return $result;
        }
        
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: NO inline XObject found - checking for indirect reference" );
        
        // Case B: /XObject N 0 R (indirect) -- read that object, add our entries, rewrite it
        if ( preg_match( '#/XObject\s+(\d+)\s+0\s+R#', $res_body, $xr ) ) {
            $xobj_dict_num = (int) $xr[1];
            if ( isset( $pdf_index[ $xobj_dict_num ] ) ) {
                $xobj_body = self::get_obj_body( $pdf, $pdf_index[ $xobj_dict_num ] );
                if ( $xobj_body !== null ) {
                    // $xobj_body is the XObject dict <<...>> -- add entries before closing >>
                    $patched_xobj = self::inject_before_end( $xobj_body, $xobj_entries );
                    $updates[ $xobj_dict_num ] = "{$xobj_dict_num} 0 obj\n" . trim( $patched_xobj ) . "\nendobj\n";
                    // res_body unchanged -- /XObject still points to same obj num,
                    // but that obj now has our entries in the incremental update
                    return $res_body;
                }
            }
            // BUGFIX: Can't read indirect XObject dict - extract entries from reference and merge
            $existing_entries = self::extract_xobject_entries_from_indirect( $res_body, $xr[1] );
            $merged_entries = $existing_entries . "\n" . $xobj_entries;
            // Remove old /XObject reference (indirect reference, not dict - this one is simple)
            $res_without_xobj = preg_replace( '/\/XObject\s+\d+\s+0\s+R/', '', $res_body );
            return self::inject_before_end( $res_without_xobj, "/XObject<<\n" . $merged_entries . "\n>>" );
        }
        // Case C: no /XObject at all -- add one inline
        return self::inject_before_end( $res_body, "/XObject<<\n" . $xobj_entries . "\n>>" );
    }

    // Extract XObject entries from inline dict when extract_dict fails
    // Extracts entries like "/Im0 97 0 R /Im1 98 0 R" from "/XObject<</Im0 97 0 R/Im1 98 0 R>>"
    // This needs to handle the compact PDF format with no spaces between entries
    private static function extract_xobject_entries_from_inline( string $res_body ): string {
        // Find /XObject<< and extract everything until we hit >>
        // We need to count angle brackets to handle nested dicts properly
        if ( ! preg_match( '#/XObject\s*<<#', $res_body, $m, PREG_OFFSET_CAPTURE ) ) {
            return ''; // No XObject dict found
        }
        
        $start_pos = $m[0][1] + strlen( $m[0][0] ); // Position after /XObject<<
        $len = strlen( $res_body );
        $depth = 1; // We're inside the first <<
        $end_pos = $start_pos;
        
        // Walk through the string counting << and >> to find where XObject dict ends
        for ( $i = $start_pos; $i < $len - 1; $i++ ) {
            if ( $res_body[$i] === '<' && $res_body[$i + 1] === '<' ) {
                $depth++;
                $i++; // Skip the second <
            } elseif ( $res_body[$i] === '>' && $res_body[$i + 1] === '>' ) {
                $depth--;
                if ( $depth === 0 ) {
                    $end_pos = $i;
                    break;
                }
                $i++; // Skip the second >
            }
        }
        
        if ( $depth !== 0 ) {
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: Failed to extract XObject entries - unbalanced brackets" );
            return '';
        }
        
        // Extract the content between /XObject<< and >>
        $content = substr( $res_body, $start_pos, $end_pos - $start_pos );
        return trim( $content );
    }

    // Remove /XObject dict from Resources body (handles nested brackets correctly)
    private static function remove_xobject_dict( string $res_body ): string {
        if ( ! preg_match( '#/XObject\s*<<#', $res_body, $m, PREG_OFFSET_CAPTURE ) ) {
            return $res_body; // No XObject dict to remove
        }
        
        $xobj_start = $m[0][1]; // Start of /XObject
        $dict_start = $xobj_start + strlen( $m[0][0] ); // Position after /XObject<<
        $len = strlen( $res_body );
        $depth = 1;
        
        // Find the closing >> for the XObject dict
        for ( $i = $dict_start; $i < $len - 1; $i++ ) {
            if ( $res_body[$i] === '<' && $res_body[$i + 1] === '<' ) {
                $depth++;
                $i++;
            } elseif ( $res_body[$i] === '>' && $res_body[$i + 1] === '>' ) {
                $depth--;
                if ( $depth === 0 ) {
                    // Found the end - remove from /XObject to >>
                    return substr( $res_body, 0, $xobj_start ) . substr( $res_body, $i + 2 );
                }
                $i++;
            }
        }
        
        // If we get here, brackets were unbalanced - return original
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: Failed to remove XObject dict - unbalanced brackets" );
        return $res_body;
    }

    // Extract XObject entries from indirect reference (fallback - returns empty if can't read)
    private static function extract_xobject_entries_from_indirect( string $res_body, string $obj_ref ): string {
        // We already tried to read this object and failed (that's why we're in fallback)
        // Return empty string - the old entries will be lost, but at least new ones will work
        ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( "CF7W: Could not read XObject object {$obj_ref} - existing XObject entries may be lost" );
        return '';
    }

    // -- Add XObject entries to a page body that has inline /Resources << ... >>
    private static function add_xobjects_to_page_body(
        string $page_body,
        string $xobj_entries,
        array  &$updates,
        string $pdf,
        array  $pdf_index
    ): string {
        if ( preg_match( '#/Resources\s*<<#', $page_body, $m, PREG_OFFSET_CAPTURE ) ) {
            $dict_start = $m[0][1] + strlen( $m[0][0] ) - 2;
            $inner = self::extract_dict( $page_body, $dict_start );
            if ( $inner !== null ) {
                $new_inner = self::add_xobjects_to_res_dict( $inner, $xobj_entries, $updates, $pdf, $pdf_index );
                return substr( $page_body, 0, $dict_start ) . $new_inner . substr( $page_body, $dict_start + strlen( $inner ) );
            }
        }
        // No /Resources at all -- append one before closing >>
        return self::inject_before_end( $page_body, "/Resources<</XObject<<\n" . $xobj_entries . "\n>>>>" );
    }

    // Inject /CF7WSig into /XObject dict inside a resource dict body (inline).
    // Handles: existing /XObject <<...>>, existing /Resources <<...>> without XObject,
    // and page body with no /Resources at all.
    private static function inject_xobject( string $body, string $xobj_entry ): string {
        // Case 1: /XObject dict already exists inline -- insert our entry before closing >>
        if ( preg_match( '#/XObject\s*<<#', $body, $m, PREG_OFFSET_CAPTURE ) ) {
            $dict_start = $m[0][1] + strlen( $m[0][0] ) - 2; // position of <<
            $inner      = self::extract_dict( $body, $dict_start );
            if ( $inner !== null ) {
                $new_inner = substr( $inner, 0, -2 ) . "\n" . $xobj_entry . "\n>>";
                return substr( $body, 0, $dict_start ) . $new_inner . substr( $body, $dict_start + strlen( $inner ) );
            }
            // BUGFIX: extract_dict failed - extract entries manually and merge
            $existing_entries = self::extract_xobject_entries_from_inline( $body );
            $merged_entries = $existing_entries . "\n" . $xobj_entry;
            // Remove old /XObject and add merged one
            $body_without_xobj = self::remove_xobject_dict( $body );
            // Find Resources dict and inject merged XObject
            if ( preg_match( '/\/Resources\s*<</', $body_without_xobj, $rm, PREG_OFFSET_CAPTURE ) ) {
                $res_start = $rm[0][1] + strlen( $rm[0][0] ) - 2;
                $res_inner = self::extract_dict( $body_without_xobj, $res_start );
                if ( $res_inner !== null ) {
                    $new_res = substr( $res_inner, 0, -2 ) . "\n/XObject<<\n" . $merged_entries . "\n>>\n>>";
                    return substr( $body_without_xobj, 0, $res_start ) . $new_res . substr( $body_without_xobj, $res_start + strlen( $res_inner ) );
                }
            }
            // Fallback: inject at page level
            return self::inject_before_end( $body_without_xobj, "/XObject<<\n" . $merged_entries . "\n>>" );
        }
        // Case 2: /XObject is an indirect reference -- we can't modify it here;
        // inject a second /XObject entry which PDF readers merge (last one wins for
        // name resolution within the same dict scope is undefined, but most readers
        // honour the later entry in an incremental update context).
        // Instead we inject into /Resources if it's inline, otherwise fall through.
        if ( preg_match( '#/XObject\s+\d+\s+0\s+R#', $body ) ) {
            // BUGFIX: Extract existing entries from indirect reference and merge
            $existing_entries = self::extract_xobject_entries_from_inline( $body );
            $merged_entries = $existing_entries . "\n" . $xobj_entry;
            
            // /XObject is indirect -- inject a supplemental inline /XObject dict
            // directly into the /Resources dict after it; PDF readers honour the
            // last definition. We append it using inject_before_end on the /Resources dict.
            if ( preg_match( '/\/Resources\s*<</', $body, $m, PREG_OFFSET_CAPTURE ) ) {
                $dict_start = $m[0][1] + strlen( $m[0][0] ) - 2;
                $inner      = self::extract_dict( $body, $dict_start );
                if ( $inner !== null ) {
                    // Insert merged /XObject inline entry
                    $new_inner = substr( $inner, 0, -2 ) . "\n/XObject<<\n" . $merged_entries . "\n>>\n>>";
                    return substr( $body, 0, $dict_start ) . $new_inner . substr( $body, $dict_start + strlen( $inner ) );
                }
            }
            // Fall through to generic inject
        }
        // Case 3: /Resources dict exists inline but no /XObject -- insert /XObject dict inside /Resources
        if ( preg_match( '/\/Resources\s*<</', $body, $m, PREG_OFFSET_CAPTURE ) ) {
            $dict_start = $m[0][1] + strlen( $m[0][0] ) - 2;
            $inner      = self::extract_dict( $body, $dict_start );
            if ( $inner !== null ) {
                $new_inner = substr( $inner, 0, -2 ) . "\n/XObject<<\n" . $xobj_entry . "\n>>\n>>";
                return substr( $body, 0, $dict_start ) . $new_inner . substr( $body, $dict_start + strlen( $inner ) );
            }
        }
        // Case 4: no /Resources at all -- inject one before the closing >>
        return self::inject_before_end( $body, "/Resources<</XObject<<\n" . $xobj_entry . "\n>>>>" );
    }

    // Walk the /Pages tree to find what page number a given page object is
    private static function resolve_page_number( string $pdf, array $index, int $page_obj_num ): ?int {
        // Build ordered list of all leaf /Page objects via DFS of /Pages tree
        $pages = array();
        self::collect_pages( $pdf, $index, $pages );
        $pos = array_search( $page_obj_num, $pages, true );
        return $pos !== false ? $pos + 1 : null;
    }

    private static function collect_pages( string $pdf, array $index, array &$pages, int $node = -1 ): void {
        if ( $node === -1 ) {
            // Find the root Pages object from the Catalog
            foreach ( $index as $n => $info ) {
                $body = self::get_obj_body( $pdf, $info );
                if ( $body && preg_match( '/\/Type\s*\/Catalog\b/', $body ) ) {
                    if ( preg_match( '/\/Pages\s+(\d+)\s+0\s+R/', $body, $m ) ) {
                        self::collect_pages( $pdf, $index, $pages, (int) $m[1] );
                    }
                    return;
                }
            }
            return;
        }
        if ( ! isset( $index[ $node ] ) ) return;
        $body = self::get_obj_body( $pdf, $index[ $node ] );
        if ( $body === null ) return;
        if ( preg_match( '/\/Type\s*\/Pages\b/', $body ) ) {
            // Intermediate node -- walk /Kids array
            if ( preg_match( '/\/Kids\s*\[([^\]]+)\]/', $body, $km ) ) {
                preg_match_all( '/(\d+)\s+0\s+R/', $km[1], $refs );
                foreach ( $refs[1] as $ref ) {
                    self::collect_pages( $pdf, $index, $pages, (int) $ref );
                }
            }
        } elseif ( preg_match( '/\/Type\s*\/Page\b/', $body ) ) {
            $pages[] = $node;
        }
    }

    // --
    // PDFTK / FDF  (Strategy 1)
    // --

    private static function pdftk_available(): bool {
        static $result = null;
        if ( $result !== null ) return $result;
        if ( ! function_exists( 'shell_exec' ) ) return ( $result = false );
        $out = @shell_exec( 'which pdftk 2>/dev/null' );
        return ( $result = ! empty( trim( (string) $out ) ) );
    }

    private static function fill_with_pdftk( string $src, string $dst, array $data ): bool {
        $fdf_path = $dst . '.fdf';
        if ( file_put_contents( $fdf_path, self::build_fdf( $data ) ) === false ) return false;
        $cmd = 'pdftk ' . escapeshellarg($src) . ' fill_form ' . escapeshellarg($fdf_path)
             . ' output ' . escapeshellarg($dst) . ' need_appearances 2>&1';
        @shell_exec( $cmd );
        cf7w_delete_file( $fdf_path );
        if ( file_exists( $dst ) && filesize( $dst ) > 0 ) return true;
        return false;
    }

    private static function build_fdf( array $data ): string {
        $fields = '';
        foreach ( $data as $name => $value ) {
            $fields .= '<< /T (' . self::fdf_escape($name) . ') /V (' . self::fdf_escape($value) . ") >>\n";
        }
        return "%FDF-1.2\n%\xe2\xe3\xcf\xd3\n1 0 obj\n<< /FDF << /Fields [\n{$fields}] >> >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private static function fdf_escape( string $s ): string {
        return str_replace( array('\\','(',')' ,"\r" ,"\n"  ),
                            array('\\\\','\\(','\\)','\\r','\\n'), $s );
    }

    // -- Public debug helpers (used by CF7W_Admin::render_debug_page) --

    // --
    // PDF DE-LINEARIZATION -- removes the /Linearized flag and normalizes the xref
    // so that incremental updates work reliably on all PDF viewers.
    // Linearized PDFs have a "hint table" front xref that some viewers obey exclusively,
    // ignoring our incremental xref. Removing the /Linearized entry forces normal parsing.
    // Removes the /Linearized flag from the header object (strips only; preserves all offsets).
    // --
    private static function delinearize_pdf( string $pdf ): string {
        // Quick check: is this a linearized PDF?
        if ( strpos( $pdf, '/Linearized' ) === false ) return $pdf;

        // Overwrite the /Linearized key+value with spaces -- preserving the exact byte length.
        //
        // CRITICAL: We must NOT remove bytes. Every byte offset in an xref table/stream
        // is absolute from the start of the file. Removing bytes before any of those
        // offsets (e.g. by stripping "/Linearized 1/L.../T.../H[...]") corrupts every
        // subsequent offset, making the entire xref unreadable.
        //
        // Overwriting with spaces is safe because:
        //   1. The /Linearized key is always inside an object dictionary (object 183 etc.)
        //      that starts very near the top of the file; spaces are legal PDF whitespace.
        //   2. All existing byte offsets remain valid.
        //   3. Without the /Linearized key, readers fall through to normal xref-chain
        //      parsing (startxref at EOF -> our new incremental xref -> /Prev chain
        //      covering the original objects), so images and existing content are found
        //      via the original xref and our stamps are found via our incremental xref.
        if ( preg_match( '#/Linearized\s+\d+[^>]*#', $pdf, $m ) ) {
            $replacement = str_repeat( ' ', strlen( $m[0] ) );
            $pdf = substr_replace( $pdf, $replacement, strpos( $pdf, $m[0] ), strlen( $m[0] ) );
            ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && error_log( 'CF7W delinearize_pdf: blanked /Linearized header (' . strlen($m[0]) . ' bytes)' );
        }
        return $pdf;
    }

    // --
    // PDF FLATTENING -- makes all form fields read-only so no further input accepted
    // Sets bit 1 (ReadOnly) on every AcroForm field's /Ff flags and removes /AA.
    // --
    private static function flatten_pdf( string $pdf ): string {
        $index   = self::build_obj_index( $pdf );
        $updates = array();

        foreach ( $index as $n => $info ) {
            $body = self::get_obj_body( $pdf, $info );
            if ( $body === null ) continue;

            // Any object with /T (field name) or /Subtype /Widget is a form field or widget
            $is_field  = preg_match( '#/T\s*[(<]#', $body );
            $is_widget = preg_match( '#/Subtype\s*/Widget\b#', $body );
            if ( ! $is_field && ! $is_widget ) continue;

            $new_body = $body;

            // Set ReadOnly bit (bit 1 = value 1) in /Ff
            if ( preg_match( '#/Ff\s+(\\d+)#', $new_body, $fm ) ) {
                $ff = (int) $fm[1] | 1; // set bit 1
                $new_body = preg_replace( '#/Ff\s+\\d+#', '/Ff ' . $ff, $new_body );
            } else {
                $new_body = self::inject_before_end( $new_body, '/Ff 1' );
            }

            // Remove /AA (additional actions -- e.g. keystroke validation)
            $new_body = preg_replace( '#/AA\s*<<[^>]*>>#s', '', $new_body );
            // Remove /AA indirect reference
            $new_body = preg_replace( '#/AA\s+\\d+\s+0\s+R#', '', $new_body );

            if ( $new_body !== $body ) {
                $updates[$n] = "{$n} 0 obj\n" . trim( $new_body ) . "\nendobj\n";
            }
        }

        // Also set /NeedAppearances false on AcroForm so appearances are used as-is
        $acro_num = null;
        if ( preg_match( '#/AcroForm\s+(\\d+)\s+0\s+R#', $pdf, $am ) ) {
            $acro_num = (int) $am[1];
        } elseif ( preg_match( '#/AcroForm\s*<<#', $pdf ) ) {
            // inline acroform -- handled below via field updates
        }
        if ( $acro_num !== null && isset( $index[$acro_num] ) ) {
            $ab = self::get_obj_body( $pdf, $index[$acro_num] );
            if ( $ab ) {
                $ab2 = preg_replace( '#/NeedAppearances\s+\\w+#', '/NeedAppearances false', $ab );
                if ( $ab2 === $ab && strpos( $ab, '/NeedAppearances' ) === false ) {
                    $ab2 = self::inject_before_end( $ab, '/NeedAppearances false' );
                }
                if ( $ab2 !== $ab ) $updates[$acro_num] = "{$acro_num} 0 obj\n" . trim($ab2) . "\nendobj\n";
            }
        }

        if ( empty( $updates ) ) return $pdf;
        return self::apply_incremental( $pdf, $updates );
    }

    public static function debug_build_index( string $pdf ): array {
        return self::build_obj_index( $pdf );
    }
    public static function debug_get_body( string $pdf, array $info ): ?string {
        return self::get_obj_body( $pdf, $info );
    }
    public static function debug_read_raw( string $pdf, int $offset ): ?array {
        return self::read_obj_body_raw( $pdf, $offset );
    }

    // --
    // HELPERS
    // --

    private static function url_to_path( string $url ): string {
        $upload = wp_upload_dir();
        if ( strpos( $url, $upload['baseurl'] ) === 0 ) {
            return $upload['basedir'] . substr( $url, strlen( $upload['baseurl'] ) );
        }
        return '';
    }
	
public static function stamp_watermark( string $pdf ): string {

    $index   = self::build_obj_index( $pdf );
    $updates = array();
    $max_obj = $index ? max( array_keys( $index ) ) : 1;

    // Collect all leaf /Page objects from the current index.
    // Because $pdf is read from the already-written output file AFTER
    // stamp_signature and stamp_visual_placements have run, the page bodies
    // here already contain all previous /Contents and /XObject additions.
    $page_objs = array();
    foreach ( $index as $n => $info ) {
        $body = self::get_obj_body( $pdf, $info );
        if ( $body === null ) continue;
        if ( ! preg_match( '/\/Type\s*\/Page\b/', $body ) ) continue;
        if (   preg_match( '/\/Type\s*\/Pages\b/', $body ) ) continue;
        $page_objs[] = array( 'num' => $n, 'body' => $body );
    }

    if ( empty( $page_objs ) ) return $pdf;

    // Single shared ExtGState for 30% opacity
    $max_obj++;
    $gs_num = $max_obj;
    $updates[ $gs_num ] = "{$gs_num} 0 obj\n"
        . "<</Type /ExtGState /ca 0.30 /CA 0.30>>\n"
        . "endobj\n";

    foreach ( $page_objs as $pg ) {
        $page_num  = $pg['num'];
        $page_body = $pg['body'];

        // Page dimensions
        $pw = 612.0; $ph = 792.0;
        if ( preg_match(
            '/\/MediaBox\s*\[\s*[\d.+-]+\s+[\d.+-]+\s+([\d.+-]+)\s+([\d.+-]+)\s*\]/',
            $page_body, $mb
        ) ) {
            $pw = floatval( $mb[1] );
            $ph = floatval( $mb[2] );
        }

        $cx = round( $pw / 2, 4 );
        $cy = round( $ph / 2, 4 );
        $cos = 0.7071; $sin = 0.7071;
        $fs = 104; $x_offset = -114;

        // Form XObject with self-contained resources
        $font_inline = '<</Type /Font /Subtype /Type1'
            . ' /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding>>';

        $xobj_ops = "/CF7WMAlpha gs\n"
            . "0.70 0.70 0.70 rg\n"
            . "BT\n"
            . "/CF7WMFont {$fs} Tf\n"
            . "{$cos} {$sin} -{$sin} {$cos} {$cx} {$cy} Tm\n"
            . "{$x_offset} 0 Td\n"
            . "(DEMO) Tj\n"
            . "ET\n";

        $xobj_dict = "<</Type /XObject /Subtype /Form"
            . " /BBox [0 0 {$pw} {$ph}]"
            . " /Matrix [1 0 0 1 0 0]"
            . " /Resources<<"
                . "/Font<</CF7WMFont {$font_inline}>>"
                . "/ExtGState<</CF7WMAlpha {$gs_num} 0 R>>"
            . ">>"
            . " /Length " . strlen( $xobj_ops )
            . ">>";

        $max_obj++;
        $xobj_num = $max_obj;
        $updates[ $xobj_num ] = "{$xobj_num} 0 obj\n"
            . "{$xobj_dict}\n"
            . "stream\n{$xobj_ops}endstream\nendobj\n";

        // Minimal content stream that invokes the Form XObject
        $cs_ops = "q\n/CF7WMStamp Do\nQ\n";
        $max_obj++;
        $cs_num = $max_obj;
        $updates[ $cs_num ] = "{$cs_num} 0 obj\n"
            . "<</Length " . strlen( $cs_ops ) . ">>\n"
            . "stream\n{$cs_ops}endstream\nendobj\n";

        // ── Extend /Contents ───────────────────────────────────────────────────
        if ( preg_match( '/\/Contents\s*\[([^\]]*)\]/', $page_body, $cm ) ) {
            $page_body = str_replace(
                $cm[0],
                '/Contents [' . trim( $cm[1] ) . ' ' . $cs_num . ' 0 R]',
                $page_body
            );
        } elseif ( preg_match( '/\/Contents\s+(\d+\s+\d+\s+R)/', $page_body, $cm ) ) {
            $page_body = str_replace(
                $cm[0],
                '/Contents [' . $cm[1] . ' ' . $cs_num . ' 0 R]',
                $page_body
            );
        } else {
            $page_body = self::inject_before_end(
                $page_body,
                '/Contents [' . $cs_num . ' 0 R]'
            );
        }

        // ── Register /CF7WMStamp XObject ───────────────────────────────────────
        //
        // This mirrors stamp_signature() exactly (lines 2066-2112):
        //
        //   1. If /Resources is an INDIRECT reference (/Resources N 0 R):
        //      - Read the resource object body from $pdf using $index
        //      - If /XObject inside it is also indirect: patch that XObject
        //        object directly and add to $updates
        //      - Otherwise: use inject_xobject() on the resource body and
        //        write the patched resource object to $updates
        //      - Page body is NOT modified for resources (ref stays intact)
        //
        //   2. If /Resources is INLINE (/Resources<<...>>):
        //      - Use inject_xobject() on page_body directly
        //      - Page body is modified in place
        //
        //   3. If no /Resources at all:
        //      - Use inject_xobject() on page_body
        //
        // inject_xobject() is only called on the RESOURCE DICT BODY or on
        // the page body when resources are inline. It is never called on a
        // page body that contains an indirect /Resources reference, because
        // inject_xobject() cannot follow indirect references.

        $xobj_entry  = '/CF7WMStamp ' . $xobj_num . ' 0 R';
        $xobj_placed = false;
        $res_body    = null;
        $res_obj_num = null;

        // Step 1: resolve /Resources
        if ( preg_match( '/\/Resources\s+(\d+)\s+0\s+R/', $page_body, $rr ) ) {
            // Indirect /Resources — read the resource object
            $res_obj_num = (int) $rr[1];
            if ( isset( $index[ $res_obj_num ] ) ) {
                $res_body = self::get_obj_body( $pdf, $index[ $res_obj_num ] );
            }
        } elseif ( preg_match( '/\/Resources\s*<</', $page_body ) ) {
            // Inline /Resources — treat page body as the resource container
            $res_body    = $page_body;
            $res_obj_num = null; // null signals: write back into page_body
        }

        if ( $res_body !== null ) {
            // Step 2: check if /XObject inside the resource dict is indirect
            if ( preg_match( '#/XObject\s+(\d+)\s+0\s+R#', $res_body, $xr ) ) {
                $xobj_dict_num = (int) $xr[1];
                if ( isset( $index[ $xobj_dict_num ] ) ) {
                    $xobj_body = self::get_obj_body( $pdf, $index[ $xobj_dict_num ] );
                    if ( $xobj_body !== null ) {
                        // Patch the XObject dict object directly
                        $new_xobj_body = self::inject_before_end( $xobj_body, $xobj_entry );
                        $updates[ $xobj_dict_num ] = "{$xobj_dict_num} 0 obj\n"
                            . trim( $new_xobj_body ) . "\nendobj\n";
                        $xobj_placed = true;
                    }
                }
            }

            if ( ! $xobj_placed ) {
                // /XObject is inline in the resource dict, or indirect but unreadable.
                // inject_xobject() operates on the resource body string — this is correct
                // because res_body IS the resource dict, not the page body.
                $new_res_body = self::inject_xobject( $res_body, $xobj_entry );
                if ( $res_obj_num !== null ) {
                    // Indirect resource — write patched resource object to updates
                    $updates[ $res_obj_num ] = "{$res_obj_num} 0 obj\n"
                        . trim( $new_res_body ) . "\nendobj\n";
                } else {
                    // Inline resource — update page_body in place
                    $page_body = $new_res_body;
                }
                $xobj_placed = true;
            }
        }

        if ( ! $xobj_placed ) {
            // No /Resources at all — inject into page body
            $page_body = self::inject_xobject( $page_body, $xobj_entry );
        }

        $updates[ $page_num ] = "{$page_num} 0 obj\n"
            . trim( $page_body ) . "\nendobj\n";
    }

    return self::apply_incremental( $pdf, $updates );
}
}
