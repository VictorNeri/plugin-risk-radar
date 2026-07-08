<?php
/**
 * PRR_Notifications
 *
 * Sends an email digest to the site admin when the scan finds
 * red-level (high risk) plugins. Uses native wp_mail() — no extra
 * infrastructure or cost required.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PRR_Notifications {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook into the same cron event as the scanner, but run after it
        // by listening at a slightly lower priority.
        add_action( 'prr_daily_scan_event', array( $this, 'maybe_send_digest' ), 20 );
    }

    public function maybe_send_digest() {
        $data    = PRR_Scanner::get_results();
        $plugins = $data['plugins'];

        $acknowledged_keys = get_option( 'prr_acknowledged_plugins', array() );

        $risky_keys = array_keys( array_filter( $plugins, function( $p ) {
            return $p['risk_level'] === 'red';
        } ) );

        // Never notify about acknowledged plugins — the admin has already reviewed them.
        $risky_keys = array_values( array_diff( $risky_keys, $acknowledged_keys ) );

        // Only alert on plugins that have newly turned red since the last notification.
        // This prevents the same email being sent every day for a persistently-red plugin.
        $notified_keys  = get_option( 'prr_notified_plugins', array() );
        $newly_red_keys = array_values( array_diff( $risky_keys, $notified_keys ) );

        // Sync the notified set: drop plugins that are no longer red, add newly-red ones.
        update_option( 'prr_notified_plugins', array_values( array_unique(
            array_merge( array_intersect( $notified_keys, $risky_keys ), $newly_red_keys )
        ) ) );

        if ( empty( $newly_red_keys ) ) {
            return;
        }

        $newly_red = array_intersect_key( $plugins, array_flip( $newly_red_keys ) );

        $ownership_alerts = array_filter( $newly_red, function( $p ) {
            return ! empty( $p['ownership_change'] );
        } );

        $to = get_option( 'admin_email' );

        $subject = ! empty( $ownership_alerts )
            ? sprintf( '[%s] URGENT: Plugin ownership change detected', get_bloginfo( 'name' ) )
            : sprintf( '[%s] Plugin Risk Radar found %d new risky plugin(s)', get_bloginfo( 'name' ), count( $newly_red ) );

        $body = '';

        if ( ! empty( $ownership_alerts ) ) {
            $body .= "OWNERSHIP CHANGES DETECTED — review these first:\n\n";
            foreach ( $ownership_alerts as $plugin ) {
                $body .= sprintf( "- %s (v%s): %s\n", $plugin['name'], $plugin['version'], $plugin['reason'] );
            }
            $body .= "\n";
        }

        $other_risky = array_filter( $newly_red, function( $p ) {
            return empty( $p['ownership_change'] );
        } );

        if ( ! empty( $other_risky ) ) {
            $body .= "Other high-risk plugins:\n\n";
            foreach ( $other_risky as $plugin ) {
                $body .= sprintf( "- %s (v%s): %s\n", $plugin['name'], $plugin['version'], $plugin['reason'] );
            }
        }

        $body .= "\nReview details in your dashboard: " . admin_url( 'admin.php?page=plugin-risk-radar' );

        wp_mail( $to, $subject, $body );
    }
}
