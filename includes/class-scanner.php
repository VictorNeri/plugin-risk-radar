<?php
/**
 * PRR_Scanner
 *
 * Core scan logic. For every installed plugin, checks:
 *  - Whether it's still listed on WordPress.org (closed/pulled plugins are a red flag)
 *  - How long since its last update (>12 months = stale/abandoned)
 *
 * Results are cached in wp_options so the dashboard can render instantly
 * without re-hitting the WordPress.org API on every page load.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PRR_Scanner {

    const OPTION_KEY = 'prr_scan_results';
    const STALE_MONTHS = 12;

    /**
     * Run a full scan of all installed plugins and store results.
     * Called on activation and by the daily cron.
     */
    public static function run_scan() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed_plugins = get_plugins();
        $results = array();

        foreach ( $installed_plugins as $plugin_file => $plugin_data ) {
            $slug = self::get_slug_from_file( $plugin_file );
            $results[ $plugin_file ] = self::check_plugin( $slug, $plugin_data );
        }

        update_option( self::OPTION_KEY, array(
            'scanned_at' => time(),
            'plugins'    => $results,
        ) );

        return $results;
    }

    /**
     * Check a single plugin against the WordPress.org plugins API.
     */
    private static function check_plugin( $slug, $plugin_data ) {
        $status = array(
            'name'             => $plugin_data['Name'],
            'version'          => $plugin_data['Version'],
            'risk_level'       => 'unknown', // green | yellow | red | unknown
            'reason'           => '',
            'last_updated'     => null,
            'ownership_change' => null, // populated below if a change was detected
        );

        $api_data = self::fetch_wporg_data( $slug );

        if ( is_wp_error( $api_data ) ) {
            $status['risk_level'] = 'unknown';
            $status['reason']     = 'Could not reach the WordPress.org API. Try scanning again later.';
            return $status;
        }

        // Closed = actively removed from the repository (security issue or ToS violation). Red.
        if ( $api_data === 'closed' ) {
            $status['risk_level'] = 'red';
            $status['reason']     = 'Removed from WordPress.org. Plugins are closed for security issues or terms violations — strongly consider replacing it.';
            return $status;
        }

        // Not found = never listed, almost certainly a premium or custom plugin. Yellow.
        if ( $api_data === 'not_found' ) {
            $status['risk_level'] = 'yellow';
            $status['reason']     = 'Not found on WordPress.org (likely a premium or custom plugin).';
            return $status;
        }

        // Parse "last updated" date, e.g. "2025-11-03 4:15pm GMT"
        if ( ! empty( $api_data['last_updated'] ) ) {
            $last_updated_ts = strtotime( $api_data['last_updated'] );
            $status['last_updated'] = $last_updated_ts;

            $months_since_update = ( time() - $last_updated_ts ) / ( 30 * DAY_IN_SECONDS );

            if ( $months_since_update >= self::STALE_MONTHS ) {
                $status['risk_level'] = 'red';
                $status['reason']     = sprintf(
                    'Not updated in over %d months. Unmaintained plugins are a leading cause of WordPress compromises.',
                    self::STALE_MONTHS
                );
            } else {
                $status['risk_level'] = 'green';
                $status['reason']     = 'Actively maintained.';
            }
        }

        // Ownership-change check runs regardless of the staleness result above,
        // since a freshly-updated plugin under new ownership is arguably the
        // higher-risk case (see: the 2026 "backdoor shipped as a routine update"
        // pattern) — it always overrides to red.
        if ( ! empty( $api_data['author'] ) ) {
            $ownership_result = PRR_Ownership_Tracker::record_and_check(
                $slug,
                $api_data['author'],
                $api_data['contributors']
            );

            if ( $ownership_result['changed'] ) {
                $status['risk_level']       = 'red';
                $status['ownership_change'] = $ownership_result;
                $status['reason']           = 'OWNERSHIP CHANGE DETECTED: ' . $ownership_result['message'];
            }
        }

        return $status;
    }

    /**
     * Fetch plugin metadata from the WordPress.org Plugins API.
     *
     * Returns one of:
     *   - array        valid plugin data
     *   - 'closed'     plugin was actively removed from the repository
     *   - 'not_found'  slug was never listed (premium/custom)
     *   - WP_Error     network failure (not cached — retried on next scan)
     */
    private static function fetch_wporg_data( $slug ) {
        $transient_key = 'prr_wporg_' . md5( $slug );
        $cached = get_transient( $transient_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $url = add_query_arg( array(
            'action'  => 'plugin_information',
            'request' => rawurlencode( wp_json_encode( array(
                'slug'   => $slug,
                'fields' => array(
                    'last_updated' => true,
                    'sections'     => false,
                    'author'       => true,
                    'contributors' => true,
                ),
            ) ) ),
        ), 'https://api.wordpress.org/plugins/info/1.2/' );

        $http_response = wp_remote_get( $url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $http_response ) ) {
            return $http_response; // Network error — don't cache, retry next scan.
        }

        $json = json_decode( wp_remote_retrieve_body( $http_response ), true );

        // The WP.org API returns {"error":"closed"} for removed plugins and
        // {"error":"Plugin not found."} for slugs that were never listed.
        // These need different risk levels, so we return distinct sentinels.
        if ( isset( $json['error'] ) ) {
            $result = ( $json['error'] === 'closed' ) ? 'closed' : 'not_found';
            set_transient( $transient_key, $result, DAY_IN_SECONDS );
            return $result;
        }

        $data = array(
            'last_updated' => isset( $json['last_updated'] ) ? $json['last_updated'] : null,
            'author'       => isset( $json['author'] ) ? wp_strip_all_tags( $json['author'] ) : null,
            'contributors' => isset( $json['contributors'] ) ? array_keys( (array) $json['contributors'] ) : array(),
        );

        set_transient( $transient_key, $data, DAY_IN_SECONDS );
        return $data;
    }

    /**
     * Derive the WordPress.org slug from a plugin file path.
     * e.g. "akismet/akismet.php" -> "akismet"
     */
    private static function get_slug_from_file( $plugin_file ) {
        if ( strpos( $plugin_file, '/' ) !== false ) {
            return dirname( $plugin_file );
        }
        return str_replace( '.php', '', $plugin_file );
    }

    /**
     * Retrieve the last cached scan results.
     */
    public static function get_results() {
        return get_option( self::OPTION_KEY, array( 'scanned_at' => null, 'plugins' => array() ) );
    }
}
