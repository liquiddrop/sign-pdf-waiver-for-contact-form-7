<?php
// includes/class-license.php

if ( ! defined( 'ABSPATH' ) ) exit;

class CF7W_License {

    /**
     * Returns true only when the site has an active paid plan OR
     * is within the 14-day free trial window.
     *
     * Freemius caches the license state locally (wp_options) and refreshes
     * it in the background daily — this method does NOT make a network call.
     */
    public static function is_active(): bool {
        if ( ! function_exists( 'cf7w_fs' ) ) {
            return false; // SDK failed to load — fail closed
        }

        $fs = cf7w_fs();

        // is_paying() = has an active, non-expired paid subscription
        if ( $fs->is_paying() ) {
            return true;
        }

        // is_trial() = currently inside the trial window (not yet expired)
        // NOTE: is_trial() returns false once the trial period ends, so
        // no separate expiry check is needed here.
        if ( $fs->is_trial() ) {
            return true;
        }

        return false;
    }

    /**
     * Short status string for display in admin notices.
     */
    public static function status_label(): string {
        if ( ! function_exists( 'cf7w_fs' ) ) return 'SDK not loaded';
        $fs = cf7w_fs();

        if ( $fs->is_paying() )  return 'Active — paid license';
        if ( $fs->is_trial() )   return 'Free Trial';
        return 'No active license or trial';
    }
}