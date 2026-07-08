<?php
/**
 * PRR_Dashboard
 *
 * Renders the admin page: a simple table of installed plugins with a
 * green / yellow / red risk indicator, plus a "Scan Now" button.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PRR_Dashboard {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_prr_manual_scan', array( $this, 'handle_manual_scan' ) );
        add_action( 'admin_post_prr_toggle_acknowledge', array( $this, 'handle_acknowledge' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register_menu() {
        add_menu_page(
            'Plugin Risk Radar',
            'Risk Radar',
            'manage_options',
            'plugin-risk-radar',
            array( $this, 'render_page' ),
            'dashicons-shield-alt',
            80
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'plugin-risk-radar' ) === false ) {
            return;
        }
        wp_enqueue_style( 'prr-admin-css', PRR_PLUGIN_URL . 'admin/css/admin.css', array(), PRR_VERSION );
    }

    public function handle_manual_scan() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'prr_manual_scan_action' );

        if ( get_transient( 'prr_scan_lock' ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=plugin-risk-radar&scan_locked=1' ) );
            exit;
        }

        set_transient( 'prr_scan_lock', 1, 5 * MINUTE_IN_SECONDS );
        PRR_Scanner::run_scan();

        wp_safe_redirect( admin_url( 'admin.php?page=plugin-risk-radar&scanned=1' ) );
        exit;
    }

    public function handle_acknowledge() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'prr_acknowledge_action' );

        $plugin_file = isset( $_POST['plugin_file'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_file'] ) ) : '';
        $scan_data   = PRR_Scanner::get_results();

        // Validate the key is an actually-installed plugin to prevent arbitrary writes.
        if ( empty( $plugin_file ) || ! array_key_exists( $plugin_file, $scan_data['plugins'] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=plugin-risk-radar' ) );
            exit;
        }

        $acknowledged = get_option( 'prr_acknowledged_plugins', array() );

        if ( in_array( $plugin_file, $acknowledged, true ) ) {
            $acknowledged = array_values( array_diff( $acknowledged, array( $plugin_file ) ) );
        } else {
            $acknowledged[] = $plugin_file;
        }

        update_option( 'prr_acknowledged_plugins', $acknowledged );

        wp_safe_redirect( admin_url( 'admin.php?page=plugin-risk-radar' ) );
        exit;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $data       = PRR_Scanner::get_results();
        $plugins    = $data['plugins'];
        $scanned_at = $data['scanned_at'] ? date_i18n( 'F j, Y g:ia', $data['scanned_at'] ) : 'Never';
        $is_locked  = (bool) get_transient( 'prr_scan_lock' );

        // Tag each plugin with its acknowledged state so it's available during sort and render.
        $acknowledged_keys = get_option( 'prr_acknowledged_plugins', array() );
        foreach ( $plugins as $key => &$plugin ) {
            $plugin['acknowledged'] = in_array( $key, $acknowledged_keys, true );
        }
        unset( $plugin );

        // Sort: red → yellow → unknown → green; acknowledged plugins always last within each group.
        $order = array( 'red' => 0, 'yellow' => 1, 'unknown' => 2, 'green' => 3 );
        uasort( $plugins, function( $a, $b ) use ( $order ) {
            if ( $a['acknowledged'] !== $b['acknowledged'] ) {
                return $a['acknowledged'] ? 1 : -1;
            }
            return $order[ $a['risk_level'] ] <=> $order[ $b['risk_level'] ];
        } );

        ?>
        <div class="wrap prr-wrap">
            <h1>Plugin Risk Radar</h1>
            <p>Last scanned: <strong><?php echo esc_html( $scanned_at ); ?></strong></p>

            <?php if ( isset( $_GET['scanned'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Scan complete.</p></div>
            <?php endif; ?>

            <?php if ( isset( $_GET['scan_locked'] ) ) : ?>
                <div class="notice notice-warning is-dismissible"><p>A scan was run recently. Please wait a few minutes before scanning again.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="prr_manual_scan">
                <?php wp_nonce_field( 'prr_manual_scan_action' ); ?>
                <button type="submit" class="button button-primary"<?php disabled( $is_locked ); ?>>
                    <?php echo $is_locked ? 'Scan Now (cooldown active)' : 'Scan Now'; ?>
                </button>
            </form>

            <table class="widefat striped prr-table" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th style="width: 90px;">Status</th>
                        <th>Plugin</th>
                        <th>Version</th>
                        <th>Details</th>
                        <th style="width: 110px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $plugins ) ) : ?>
                    <tr><td colspan="5">No scan data yet. Click "Scan Now" above.</td></tr>
                <?php else : ?>
                    <?php foreach ( $plugins as $plugin_file => $plugin ) :
                        $is_ack = $plugin['acknowledged'];
                    ?>
                        <tr class="<?php echo $is_ack ? 'prr-row-acknowledged' : ''; ?>">
                            <td><span class="prr-badge prr-badge-<?php echo esc_attr( $plugin['risk_level'] ); ?>"><?php echo esc_html( strtoupper( $plugin['risk_level'] ) ); ?></span></td>
                            <td><strong><?php echo esc_html( $plugin['name'] ); ?></strong></td>
                            <td><?php echo esc_html( $plugin['version'] ); ?></td>
                            <td>
                                <?php echo esc_html( $plugin['reason'] ); ?>
                                <?php if ( $is_ack ) : ?><em class="prr-ack-label"> — acknowledged</em><?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="prr_toggle_acknowledge">
                                    <input type="hidden" name="plugin_file" value="<?php echo esc_attr( $plugin_file ); ?>">
                                    <?php wp_nonce_field( 'prr_acknowledge_action' ); ?>
                                    <button type="submit" class="button button-small">
                                        <?php echo $is_ack ? 'Unmark' : 'Acknowledge'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <div class="prr-upsell" style="margin-top: 30px; padding: 16px; background: #fff; border-left: 4px solid #2271b1;">
                <h3>Want more?</h3>
                <p>Plugin Risk Radar Pro adds vulnerability database cross-referencing, Slack notifications, and PDF audit reports for client sites.</p>
                <a href="https://example.com/plugin-risk-radar-pro" class="button">Learn about Pro</a>
            </div>
        </div>
        <?php
    }
}
