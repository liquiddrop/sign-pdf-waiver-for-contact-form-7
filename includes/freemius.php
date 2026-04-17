<?php
// includes/freemius.php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'cf7w_fs' ) ) {

    function cf7w_fs() {
        global $cf7w_fs;

        if ( ! isset( $cf7w_fs ) ) {
            // Include the Freemius SDK
            require_once CF7W_DIR . 'vendor/freemius/start.php';

            $cf7w_fs = fs_dynamic_init( array(
                'id'                  => '26496',
                'slug'                => 'sign-pdf-waiver-for-contact-form-7',
                'type'                => 'plugin',
                'public_key'          => 'pk_fd00c71b350069bab76277bf78e16',
                'is_premium'          => true,
                'is_premium_only'     => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                // Automatically removed in the free version. If you're not using the
                // auto-generated free version, delete this line before uploading to wp.org.
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'trial'               => array(
                    'days'               => 14,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'first-path'     => 'plugins.php',
                    'contact'        => false,
                    'support'        => false,
                ),
            ) );
        }

        return $cf7w_fs;
    }

    // Initialize Freemius immediately
    cf7w_fs();

    // Register the after-uninstall hook
    cf7w_fs()->add_action( 'after_uninstall', 'cf7w_fs_uninstall_cleanup' );
}

/**
 * Clean up plugin data on uninstall.
 * Called by Freemius after the plugin is deleted.
 */
function cf7w_fs_uninstall_cleanup() {
    global $wpdb;
    // Optionally drop the submissions table on uninstall
    // $wpdb->query( 'DROP TABLE IF EXISTS ' . cf7w_db_table() );
    delete_option( 'cf7w_settings' );
}