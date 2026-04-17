<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CF7W_PDF_Parser — pure-PHP AcroForm field extractor.
 *
 * Handles both traditional uncompressed objects and ObjStm (object streams),
 * which is the format used by Acrobat, Sejda, and most modern PDF tools.
 *
 * Returns rich field objects:
 *   { name, label, pdf_type, cf7_type, is_radio, is_sig }
 */
class CF7W_PDF_Parser {

    public static function extract_fields( string $file_path ): array {
        if ( ! file_exists( $file_path ) ) return [];
        $raw = file_get_contents( $file_path );

        // Collect all object bodies: both uncompressed and from ObjStm
        $bodies = array_merge(
            self::collect_raw_objects( $raw ),
            self::collect_objstm_objects( $raw )
        );

        $fields = self::extract_fields_from_bodies( $bodies );

        // Pass 3: visible text labels if nothing found
        if ( empty( $fields ) ) {
            $fields = self::scan_text_labels( $raw );
        }

        // Deduplicate by lowercase name, skip pure-button fields (submit/reset/nav)
        $seen = []; $out = [];
        foreach ( $fields as $f ) {
            $key = strtolower( $f['name'] );
            if ( isset( $seen[$key] ) ) continue;
            if ( self::is_ui_button( $f ) ) continue;
            $seen[$key] = true;
            $out[] = $f;
        }
        return $out;
    }

    /** BC shim */
    public static function extract_field_names( string $file_path ): array {
        return array_column( self::extract_fields( $file_path ), 'name' );
    }

    // ── Collect bodies from uncompressed objects ───────────────────────────────
    // Scans for "N 0 obj" markers and grabs up to 2KB per object (safe: stream
    // data comes after "stream\n" which we don't need).
    private static function collect_raw_objects( string $raw ): array {
        $bodies = [];
        $offset = 0;
        $len    = strlen( $raw );
        while ( $offset < $len ) {
            if ( ! preg_match( '/\d+\s+\d+\s+obj\b/', $raw, $m, PREG_OFFSET_CAPTURE, $offset ) ) break;
            $start  = $m[0][1];
            $offset = $start + strlen( $m[0][0] ) + 1;
            // Grab up to 2KB — enough for any field dict header
            $window = substr( $raw, $start, 2048 );
            // Stop at stream keyword to avoid binary data
            $stream_pos = strpos( $window, 'stream' );
            if ( $stream_pos !== false ) $window = substr( $window, 0, $stream_pos );
            $bodies[] = $window;
        }
        return $bodies;
    }

    // ── Collect bodies from ObjStm (compressed object streams) ───────────────
    // ObjStm packs multiple objects into one FlateDecode stream.
    // Format: "N First" index at the start, then N object bodies concatenated.
    private static function collect_objstm_objects( string $raw ): array {
        $bodies = [];
        if ( ! function_exists( 'gzuncompress' ) ) return $bodies;

        // Find all objects that declare /Type /ObjStm
        $offset = 0;
        while ( preg_match( '/\/Type\s*\/ObjStm/', $raw, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
            $type_pos = $m[0][1];
            $offset   = $type_pos + 1;

            // Find the stream data for this object — scan forward for "stream\n"
            $stream_marker = strpos( $raw, "stream\n", $type_pos );
            if ( $stream_marker === false ) {
                $stream_marker = strpos( $raw, "stream\r\n", $type_pos );
                if ( $stream_marker === false ) continue;
                $stream_start = $stream_marker + 8;
            } else {
                $stream_start = $stream_marker + 7;
            }

            // Sanity check: stream must be within ~5KB of the /Type /ObjStm
            if ( $stream_start - $type_pos > 5000 ) continue;

            $stream_end = strpos( $raw, 'endstream', $stream_start );
            if ( $stream_end === false ) continue;

            $compressed = substr( $raw, $stream_start, $stream_end - $stream_start );

            // Decompress
            $d = @gzuncompress( $compressed );
            if ( $d === false ) $d = @gzinflate( $compressed );
            if ( ! $d || strlen( $d ) < 4 ) continue;

            // Read N and First from the object header (between /Type /ObjStm and stream)
            $header_chunk = substr( $raw, max(0, $type_pos - 200), 200 + ($stream_start - $type_pos) );
            $n     = 0; $first = 0;
            if ( preg_match( '/\/N\s+(\d+)/',     $header_chunk, $nm ) ) $n     = (int) $nm[1];
            if ( preg_match( '/\/First\s+(\d+)/', $header_chunk, $fm ) ) $first = (int) $fm[1];
            if ( $n === 0 || $first === 0 || $first >= strlen( $d ) ) continue;

            // Parse the index: N pairs of (objnum, byte-offset-from-First)
            $index_section = substr( $d, 0, $first );
            preg_match_all( '/(\d+)\s+(\d+)/', $index_section, $pairs, PREG_SET_ORDER );

            // Extract each object body using the index offsets
            for ( $i = 0; $i < count( $pairs ); $i++ ) {
                $body_start = $first + (int) $pairs[$i][2];
                $body_end   = isset( $pairs[$i+1] ) ? $first + (int) $pairs[$i+1][2] : strlen( $d );
                $body       = substr( $d, $body_start, $body_end - $body_start );
                $bodies[]   = $body;
            }
        }
        return $bodies;
    }

    // ── Extract field descriptors from a list of object body strings ───────────
    private static function extract_fields_from_bodies( array $bodies ): array {
        $fields = [];
        foreach ( $bodies as $body ) {
            if ( strpos( $body, '/T' ) === false ) continue;

            $name = self::extract_T( $body );
            if ( $name === '' ) continue;

            $pdf_type = 'Tx';
            if ( preg_match( '/\/FT\s*\/(\w+)/', $body, $m ) ) $pdf_type = $m[1];

            $ff = 0;
            if ( preg_match( '/\/Ff\s+(\d+)/', $body, $m ) ) $ff = (int) $m[1];

            $fields[] = self::make_field( $name, $pdf_type, $ff );
        }
        return $fields;
    }

    // ── Extract /T value from an object body string ───────────────────────────
    private static function extract_T( string $body ): string {
        if ( preg_match( '/\/T\s*\(/', $body, $m, PREG_OFFSET_CAPTURE ) ) {
            $start = $m[0][1] + strlen( $m[0][0] );
            $s = ''; $depth = 1;
            for ( $i = $start; $i < strlen( $body ) && $depth > 0; $i++ ) {
                $c = $body[$i];
                if ( $c === '\\' ) { $i++; $s .= isset($body[$i]) ? $body[$i] : ''; continue; }
                if ( $c === '(' ) $depth++;
                elseif ( $c === ')' ) { $depth--; if ( $depth === 0 ) break; }
                if ( $depth > 0 ) $s .= $c;
            }
            return self::clean( $s );
        }
        if ( preg_match( '/\/T\s*<([0-9A-Fa-f\s]+)>/', $body, $m ) ) {
            return self::clean( self::hex_decode( preg_replace('/\s/', '', $m[1]) ) );
        }
        return '';
    }

    // ── Build a field descriptor from name + PDF type info ────────────────────
    private static function make_field( string $name, string $pdf_type, int $ff ): array {
        $is_radio     = ( $pdf_type === 'Btn' && ( $ff & ( 1 << 15 ) ) );
        $is_push      = ( $pdf_type === 'Btn' && ( $ff & ( 1 << 16 ) ) ); // push button (submit/reset)
        $is_multiline = ( $pdf_type === 'Tx'  && ( $ff & ( 1 << 12 ) ) );
        $is_sig       = ( $pdf_type === 'Sig' );

        if ( $is_sig )            $cf7 = 'cf7w_signature';
        elseif ( $is_radio )      $cf7 = 'radio';
        elseif ( $is_push )       $cf7 = '_button';   // internal marker; filtered out
        elseif ( $pdf_type === 'Btn' ) $cf7 = 'checkbox';
        elseif ( $pdf_type === 'Ch' )  $cf7 = 'select';
        elseif ( $is_multiline )  $cf7 = 'textarea';
        else                      $cf7 = self::guess_type( $name );

        return [
            'name'     => $name,
            'label'    => $name,
            'pdf_type' => $pdf_type,
            'cf7_type' => $cf7,
            'is_radio' => $is_radio,
            'is_sig'   => $is_sig,
            'is_push'  => $is_push,
        ];
    }

    // ── Filter out pure UI push buttons (submit, reset, navigation) ───────────
    private static function is_ui_button( array $f ): bool {
        if ( $f['cf7_type'] !== '_button' ) return false;
        // Also filter by common button names
        $n = strtolower( $f['name'] );
        return in_array( $n, ['submit','reset','hide','show','prevpage','nextpage','prevpage2','nextpage2','google','firstpage','back'], true )
            || str_starts_with( $n, 'prev' ) || str_starts_with( $n, 'next' );
    }

    // ── Pass 3: visible label lines ending with ':' ───────────────────────────
    private static function scan_text_labels( string $raw ): array {
        $fields = []; $chunks = [];
        preg_match_all( '/BT\s*(.*?)\s*ET/s', $raw, $blocks );
        foreach ( $blocks[1] as $block ) {
            preg_match_all( '/\(([^)]{1,120})\)\s*Tj/', $block, $tj );
            foreach ( $tj[1] as $t ) $chunks[] = $t;
        }
        foreach ( $chunks as $chunk ) {
            $chunk = self::clean( $chunk );
            if ( substr( $chunk, -1 ) === ':' ) {
                $label = trim( rtrim( $chunk, ':' ) );
                if ( strlen( $label ) >= 2 )
                    $fields[] = self::make_field( ucwords( strtolower( $label ) ), 'Tx', 0 );
            }
        }
        return $fields;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private static function clean( string $s ): string {
        $s = preg_replace_callback( '/\\\\([0-7]{1,3})/', fn($m) => chr( octdec( $m[1] ) ), $s );
        $s = str_replace( ['\\n','\\r','\\t','\\\\','\\(','\\)'], [' ',' ',' ','\\','(',')'], $s );
        $s = preg_replace( '/[^\x20-\x7E]/', '', $s );
        $s = trim( $s );
        if ( strlen( $s ) < 1 || strlen( $s ) > 100 ) return '';
        $word = preg_match_all( '/[A-Za-z0-9 _\-.]/', $s );
        if ( strlen($s) > 0 && ( $word / strlen( $s ) ) < 0.5 ) return '';
        return $s;
    }

    private static function hex_decode( string $hex ): string {
        if ( strlen( $hex ) % 2 ) $hex .= '0';
        $out = '';
        for ( $i = 0; $i < strlen( $hex ); $i += 2 ) $out .= chr( hexdec( substr( $hex, $i, 2 ) ) );
        return $out;
    }

    public static function guess_type( string $label ): string {
        $l = strtolower( $label );
        if ( str_contains( $l, 'email' ) )                                                                 return 'email';
        if ( str_contains( $l, 'phone' ) || str_contains( $l, 'tel' ) )                                   return 'tel';
        if ( str_contains( $l, 'date'  ) || str_contains( $l, 'dob' ) )                                   return 'date';
        if ( str_contains( $l, 'agree' ) || str_contains( $l, 'consent' ) || str_contains( $l, 'accept' ) ) return 'checkbox';
        if ( str_contains( $l, 'address') || str_contains( $l, 'comment') || str_contains( $l, 'note')
          || str_contains( $l, 'multiline') || str_contains( $l, 'message') )                             return 'textarea';
        if ( str_contains( $l, 'signature') || str_contains( $l, 'sign') )                                return 'cf7w_signature';
        if ( str_contains( $l, 'number') || str_contains( $l, 'amount') || str_contains( $l, 'qty')
          || str_contains( $l, 'calc') )                                                                   return 'number';
        if ( str_contains( $l, 'url'   ) || str_contains( $l, 'website') )                                return 'url';
        return 'text';
    }

    public static function default_fields_names(): array {
        return [ 'Full Name', 'Email Address', 'Phone Number', 'Date of Birth', 'Emergency Contact', 'Agreement' ];
    }
}
