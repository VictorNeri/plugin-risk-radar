<?php
/**
 * PRR_Ownership_Tracker
 *
 * Snapshots each plugin's author + contributor list on every scan and
 * compares it to the previous snapshot. A change in ownership is one of
 * the strongest early signals of a supply-chain risk: legitimate plugins
 * are sometimes sold, and new owners occasionally ship a backdoored
 * update months later once the acquisition no longer looks suspicious.
 *
 * Everything here runs inside WordPress itself (wp_options) — no
 * external server or database required, so it costs nothing to run.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PRR_Ownership_Tracker {

    const OPTION_KEY   = 'prr_ownership_history';
    const MAX_SNAPSHOTS = 10; // per plugin, keeps the option size small

    /**
     * Record a new snapshot for a plugin and check whether ownership changed
     * compared to the most recent prior snapshot.
     *
     * @param string $slug         WordPress.org plugin slug.
     * @param string $author       Author name/string from the API.
     * @param array  $contributors Array of contributor usernames.
     *
     * @return array {
     *     @type bool   $changed        Whether ownership changed since last snapshot.
     *     @type string $previous_author
     *     @type array  $previous_contributors
     *     @type string $message        Human-readable summary if changed.
     * }
     */
    public static function record_and_check( $slug, $author, $contributors ) {
        $history = get_option( self::OPTION_KEY, array() );

        $contributors = is_array( $contributors ) ? array_values( $contributors ) : array();
        sort( $contributors ); // normalize order so re-ordered lists don't false-positive

        $result = array(
            'changed'               => false,
            'previous_author'       => null,
            'previous_contributors' => array(),
            'message'               => '',
        );

        $existing = isset( $history[ $slug ] ) ? $history[ $slug ] : array();

        if ( ! empty( $existing ) ) {
            $last_snapshot = end( $existing );

            $author_changed       = $last_snapshot['author'] !== $author;
            $contributors_changed = $last_snapshot['contributors'] !== $contributors;

            if ( $author_changed || $contributors_changed ) {
                $result['changed']               = true;
                $result['previous_author']       = $last_snapshot['author'];
                $result['previous_contributors'] = $last_snapshot['contributors'];

                if ( $author_changed ) {
                    $result['message'] = sprintf(
                        'Plugin author changed from "%s" to "%s". This can indicate a legitimate transfer, or a sold plugin — verify before trusting future updates.',
                        $last_snapshot['author'],
                        $author
                    );
                } else {
                    $removed = array_diff( $last_snapshot['contributors'], $contributors );
                    $added   = array_diff( $contributors, $last_snapshot['contributors'] );
                    $result['message'] = sprintf(
                        'Contributor list changed. Added: %s. Removed: %s.',
                        $added ? implode( ', ', $added ) : 'none',
                        $removed ? implode( ', ', $removed ) : 'none'
                    );
                }
            }
        }

        // Only store a new snapshot if something actually changed, or if this
        // is the very first snapshot. No point storing identical duplicates daily.
        if ( empty( $existing ) || $result['changed'] ) {
            $existing[] = array(
                'author'       => $author,
                'contributors' => $contributors,
                'recorded_at'  => current_time( 'timestamp' ),
            );

            // Trim to the most recent N snapshots
            if ( count( $existing ) > self::MAX_SNAPSHOTS ) {
                $existing = array_slice( $existing, -self::MAX_SNAPSHOTS );
            }

            $history[ $slug ] = $existing;
            update_option( self::OPTION_KEY, $history );
        }

        return $result;
    }

    /**
     * Get the full snapshot history for a given plugin slug (useful for
     * the Pro-tier PDF audit report / detailed view).
     */
    public static function get_history( $slug ) {
        $history = get_option( self::OPTION_KEY, array() );
        return isset( $history[ $slug ] ) ? $history[ $slug ] : array();
    }
}
