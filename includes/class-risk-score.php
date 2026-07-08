<?php
/**
 * PRR_Risk_Score
 *
 * Calculates a 0–100 risk score from WP.org API data. Higher = riskier.
 *
 * Components:
 *   Staleness          0–35 pts  months since last update
 *   WP compat lag      0–20 pts  minor versions behind "tested up to"
 *   Support health     0–15 pts  unresolved thread ratio (requires >=5 threads)
 *   Install base       0–10 pts  very low installs suggest abandoned/unwatched
 *   Ownership change  +30 pts   bonus on top, result capped at 100
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PRR_Risk_Score {

    /**
     * @param  array $api_data          Data array from PRR_Scanner::fetch_wporg_data().
     * @param  bool  $ownership_changed Whether PRR_Ownership_Tracker flagged a change.
     * @return array { score: int 0-100, factors: string[] }
     */
    public static function calculate( array $api_data, $ownership_changed = false ) {
        $score   = 0;
        $factors = array();

        // 1. Staleness
        if ( ! empty( $api_data['last_updated'] ) ) {
            $months = ( time() - strtotime( $api_data['last_updated'] ) ) / ( 30 * DAY_IN_SECONDS );
            $pts    = self::stale_points( $months );
            if ( $pts > 0 ) {
                $score    += $pts;
                $factors[] = sprintf( 'Staleness +%d (%d months since last update)', $pts, (int) $months );
            }
        }

        // 2. "Tested up to" WP version lag
        if ( ! empty( $api_data['tested'] ) ) {
            $pts = self::compat_points( $api_data['tested'] );
            if ( $pts > 0 ) {
                $score    += $pts;
                $factors[] = sprintf( 'WP compat +%d (tested up to %s)', $pts, $api_data['tested'] );
            }
        }

        // 3. Support thread resolution rate
        if ( isset( $api_data['support_threads'] ) && $api_data['support_threads'] >= 5 ) {
            $total    = (int) $api_data['support_threads'];
            $resolved = (int) $api_data['support_threads_resolved'];
            $rate     = $total > 0 ? $resolved / $total : 0;
            $pts      = self::support_points( $rate );
            if ( $pts > 0 ) {
                $score    += $pts;
                $factors[] = sprintf( 'Support health +%d (%d%% of threads resolved)', $pts, (int) ( $rate * 100 ) );
            }
        }

        // 4. Active installs — very low count suggests abandoned or unwatched
        if ( isset( $api_data['active_installs'] ) ) {
            $pts = self::install_points( (int) $api_data['active_installs'] );
            if ( $pts > 0 ) {
                $score    += $pts;
                $factors[] = sprintf(
                    'Low install base +%d (%s active installs)',
                    $pts,
                    number_format( (int) $api_data['active_installs'] )
                );
            }
        }

        // 5. Ownership change — significant supply-chain signal
        if ( $ownership_changed ) {
            $score    += 30;
            $factors[] = 'Ownership change +30';
        }

        $score = min( 100, $score );

        if ( empty( $factors ) ) {
            $factors[] = 'No risk factors detected.';
        }

        return array(
            'score'   => $score,
            'factors' => $factors,
        );
    }

    // 0–35 pts based on months since last update
    private static function stale_points( $months ) {
        if ( $months >= 24 ) return 35;
        if ( $months >= 18 ) return 28;
        if ( $months >= 12 ) return 20;
        if ( $months >= 6  ) return 10;
        return 0;
    }

    // 0–20 pts based on how many WP minor versions behind "tested up to" is
    private static function compat_points( $tested ) {
        global $wp_version;
        $c = explode( '.', (string) $wp_version );
        $t = explode( '.', (string) $tested );
        $current = (int) ( $c[0] ?? 0 ) * 100 + (int) ( $c[1] ?? 0 );
        $plugin  = (int) ( $t[0] ?? 0 ) * 100 + (int) ( $t[1] ?? 0 );
        $lag = $current - $plugin;
        if ( $lag >= 3 ) return 20;
        if ( $lag >= 2 ) return 14;
        if ( $lag >= 1 ) return 8;
        return 0;
    }

    // 0–15 pts based on support thread resolution rate
    private static function support_points( $rate ) {
        if ( $rate < 0.40 ) return 15;
        if ( $rate < 0.60 ) return 10;
        if ( $rate < 0.80 ) return 5;
        return 0;
    }

    // 0–10 pts — very low installs suggest plugin is abandoned/unwatched
    private static function install_points( $installs ) {
        if ( $installs < 10  ) return 10;
        if ( $installs < 100 ) return 5;
        return 0;
    }
}
