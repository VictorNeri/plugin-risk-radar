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

        PRR_Scanner::run_scan();

        wp_safe_redirect( admin_url( 'admin.php?page=plugin-risk-radar&scanned=1' ) );
        exit;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $data    = PRR_Scanner::get_results();
        $plugins = $data['plugins'];
        $scanned_at = $data['scanned_at'] ? date_i18n( 'F j, Y g:ia', $data['scanned_at'] ) : 'Never';

        // Sort: red first, then yellow, then green
        $order = array( 'red' => 0, 'yellow' => 1, 'unknown' => 2, 'green' => 3 );
        uasort( $plugins, function( $a, $b ) use ( $order ) {
            return $order[ $a['risk_level'] ] <=> $order[ $b['risk_level'] ];
        } );

        ?>
        <div class="wrap prr-wrap">
            <h1>Plugin Risk Radar</h1>
            <p>Last scanned: <strong><?php echo esc_html( $scanned_at ); ?></strong></p>

            <?php if ( isset( $_GET['scanned'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Scan complete.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="prr_manual_scan">
                <?php wp_nonce_field( 'prr_manual_scan_action' ); ?>
                <button type="submit" class="button button-primary">Scan Now</button>
            </form>

            <table class="widefat striped prr-table" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th style="width: 90px;">Status</th>
                        <th>Plugin</th>
                        <th>Version</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $plugins ) ) : ?>
                    <tr><td colspan="4">No scan data yet. Click "Scan Now" above.</td></tr>
                <?php else : ?>
                    <?php foreach ( $plugins as $plugin ) : ?>
                        <tr>
                            <td><span class="prr-badge prr-badge-<?php echo esc_attr( $plugin['risk_level'] ); ?>"><?php echo esc_html( strtoupper( $plugin['risk_level'] ) ); ?></span></td>
                            <td><strong><?php echo esc_html( $plugin['name'] ); ?></strong></td>
                            <td><?php echo esc_html( $plugin['version'] ); ?></td>
                            <td><?php echo esc_html( $plugin['reason'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <div class="prr-upsell" style="margin-top: 30px; padding: 16px; background: #fff; border-left: 4px solid #2271b1;">
                <h3>Want more?</h3>
                <p>Plugin Risk Radar Pro adds vulnerability database cross-referencing, plugin ownership-change alerts, Slack notifications, and PDF audit reports for client sites.</p>
                <a href="https://example.com/plugin-risk-radar-pro" class="button">Learn about Pro</a>
            </div>
        </div>
        <?php
    }
}
