<?php
/**
 * Plugin Name: Plugin Risk Radar
 * Plugin URI:  https://github.com/VictorNeri/plugin-risk-radar
 * Description: Scans your installed plugins and flags ones that are abandoned, closed from WordPress.org, or overdue for updates — so you can spot risk before it becomes a hack.
 * Version:     1.0.1
 * Author:      VictorNeri
 * Author URI:  https://github.com/VictorNeri
 * License:     GPLv2 or later
 * Text Domain: plugin-risk-radar
 */

// Block direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PRR_VERSION', '1.0.1' );
define( 'PRR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load core classes
require_once PRR_PLUGIN_DIR . 'includes/class-ownership-tracker.php';
require_once PRR_PLUGIN_DIR . 'includes/class-risk-score.php';
require_once PRR_PLUGIN_DIR . 'includes/class-scanner.php';
require_once PRR_PLUGIN_DIR . 'includes/class-dashboard.php';
require_once PRR_PLUGIN_DIR . 'includes/class-notifications.php';

/**
 * Main plugin bootstrap class.
 * Ties together the scanner, dashboard, and notifications.
 */
final class Plugin_Risk_Radar {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Activation / deactivation
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'on_deactivate' ) );

        // Boot the dashboard (admin menu + widget)
        add_action( 'plugins_loaded', array( $this, 'load_components' ) );

        // Daily cron scan
        add_action( 'prr_daily_scan_event', array( 'PRR_Scanner', 'run_scan' ) );
    }

    public function load_components() {
        PRR_Dashboard::instance();
        PRR_Notifications::instance();
    }

    public function on_activate() {
        // Run an initial scan immediately so the dashboard isn't empty
        PRR_Scanner::run_scan();

        // Schedule the daily cron if not already scheduled
        if ( ! wp_next_scheduled( 'prr_daily_scan_event' ) ) {
            wp_schedule_event( time(), 'daily', 'prr_daily_scan_event' );
        }
    }

    public function on_deactivate() {
        $timestamp = wp_next_scheduled( 'prr_daily_scan_event' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'prr_daily_scan_event' );
        }
    }
}

Plugin_Risk_Radar::instance();
