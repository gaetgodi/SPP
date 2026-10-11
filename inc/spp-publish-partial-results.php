<?php
/* =========================================================
   Publish Partial Results (no ranking change)
   Version: 1.1.0
   Date: 2026-10-11

   Changes from 1.0.0:
   - Page: "Publish Partial Results" (slug publish-partial-results),
     Published, not Private -- this site's editor role has no
     read_private_pages, so a Private page 404s for editors. Same model
     as the other tool pages: the shortcode gates itself, and anyone who
     is not administrator/editor (logged out, subscriber, any other role)
     gets only "You do not have permission to use this tool." -- no form,
     no event data. Kept out of menus; Yoast noindex (post meta, also
     drops it from the sitemap); excluded from site search below;
     /publish-partial-results/ added to WP Super Cache's rejected URIs.
   - spp_ppr_tool_page_url( $slug ) / spp_ppr_page_url( $event ): page
     links by slug, never by ID ('' if the page is missing). Used by the
     Create Schedule guard message (gl-schedule-production.php 2.2.2) to
     link here with ppr_event prefilled. The slug goes through the
     spp_ppr_page_slug filter (test hook for the missing-page case).
   - Backup table names get a _2, _3... suffix when a run in the same
     second already used the name. Before, the second run stopped at
     step 1 ("table already exists") -- safe (nothing else changed) but a
     spurious failure; found by the test suite running faster.
   - Confirm screen wording, plain language: scores are saved and count
     toward player ratings; rankings are NOT changed; no results email
     is sent; the event is closed so the next schedule can be created.

   PURPOSE:
   For a ladder event that was only partly played (rain-out etc.):
   keep the scores that were entered, feed them to the Glicko Club
   Rating, and release the Create Schedule guard -- WITHOUT changing
   ladder rankings. First used for event 168 (2026-10-05).

   It deliberately never calls Create Results, Apply Override, Copy
   Ranks to user profile, or spp_update_club_ratings():
   - Create Results / Apply Override / Copy Ranks are the ranking
     pipeline (Results, Results_all, usermeta Rank).
   - Apply Override and spp_update_club_ratings() take their event id
     from Results.event_id, which still holds the LAST fully published
     event -- running them would rewrite that event's
     Schedules_Scores_ table with this event's rows.
   The event id is always explicit (form field), never inferred.

   STEPS (in order, inside the 'spp_schedule_production' named lock
   that Create Schedule also takes, so neither can run during the
   other; a second overlapping submit is refused, touching nothing):
     1. Snapshot Schedules -> PartialPub_Schedules_{event}_{ts}.
        The name matches none of the Create Results / Apply Override
        cleanup patterns (Results_%, Schedules_%_$, Schedules_00%).
     2. Schedules_Scores_{event} = copy of Schedules (same statement
        Create Results / Apply Override use; blanks stay NULL).
        Verified row-by-row against Schedules. If the table already
        exists and matches, the step is skipped (re-submit safe); if
        it exists and differs, the run stops.
     3. Ratings: rebuild doubles games from the scores table (same
        STEP 1 logic as spp_update_club_ratings()) and call
        spp_crt_process_event_ratings( ..., $event, 'ladder', false ).
        club_rating_state, club_rating_event_log and the Glicko usermeta
        are first copied to PartialPub_* backup tables, then the update
        runs inside one transaction and is checked (ledger row per
        player, state == ledger mu_after/rd_after/games_after); any
        failure rolls the whole step back. Skipped if the ledger
        already has rows for this event (re-submit safe -- it never
        reprocesses) or if no game could be rebuilt.
     4. Flags last: spp_schedule_published = 0, spp_results_posted = 1
        (exactly what Apply Override Stage 1 sets). Only reached when
        1-3 succeeded.
     5. Log: appended to the spp_partial_publish_log option and
        error_log().

   ZERO-SCORE CASE: if no score at all was entered, there is nothing
   to keep or rate -- steps 2 and 3 are skipped (so an empty event
   doesn't show up in the scores drop-down) and only the snapshot and
   flags happen.

   REFUSES (preview shows why, run re-checks inside the lock):
   not admin/editor; missing/invalid event id; event not a ladder
   occurrence; Schedules empty or holding any other event's rows;
   Results already stamped with this event (normal Publish path).

   Shortcode: [spp_publish_partial_results]. GET ?ppr_event=N shows a
   read-only preview with a rating dry run; the run is a nonce'd POST.
   spp_ppr_run() takes a $cfg array so tests can point every table,
   option and the rating callable at throwaway names.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_publish_partial_results', 'spp_publish_partial_results_shortcode' );

/**
 * URL of a published tool page, looked up by slug. '' if missing/unpublished.
 */
function spp_ppr_tool_page_url( string $slug ) : string {
    $page = get_page_by_path( $slug, OBJECT, 'page' );
    return ( $page && $page->post_status === 'publish' ) ? (string) get_permalink( $page ) : '';
}

/**
 * This tool's page, opened straight to the preview for $event. '' if missing.
 */
function spp_ppr_page_url( int $event ) : string {
    $url = spp_ppr_tool_page_url( apply_filters( 'spp_ppr_page_slug', 'publish-partial-results' ) );
    return ( $url && $event ) ? add_query_arg( 'ppr_event', $event, $url ) : $url;
}

// Keep the tool page out of site search (noindex/sitemap is Yoast post meta).
add_action( 'pre_get_posts', function( $query ) {
    if ( ! is_admin() && $query->is_main_query() && $query->is_search() ) {
        $page = get_page_by_path( 'publish-partial-results', OBJECT, 'page' );
        if ( $page ) {
            $query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), array( $page->ID ) ) );
        }
    }
} );

/**
 * Table/option names and the rating callable. Tests override these.
 */
function spp_ppr_config( array $overrides = array() ) : array {
    global $wpdb;
    return array_merge( array(
        'schedules'       => 'Schedules',
        'scores_prefix'   => 'Schedules_Scores_',
        'backup_prefix'   => 'PartialPub_',
        'state_table'     => 'club_rating_state',
        'ledger_table'    => 'club_rating_event_log',
        'usermeta_table'  => $wpdb->prefix . 'usermeta',
        'results_table'   => 'Results',
        'flag_published'  => 'spp_schedule_published',
        'flag_posted'     => 'spp_results_posted',
        'log_option'      => 'spp_partial_publish_log',
        'lock_name'       => 'spp_schedule_production',
        'require_ladder'  => true,
        'rate'            => 'spp_crt_process_event_ratings',
    ), $overrides );
}

/**
 * STEP 1 of spp_update_club_ratings(), on rows passed in: rebuild the
 * doubles games. Same rules (NULL = didn't play, all-zero slot = not
 * played, slot that doesn't split cleanly = unreconstructed).
 */
function spp_ppr_build_games( array $rows ) : array {
    $groups = array();
    $rank_by_user = array();
    foreach ( $rows as $r ) {
        $groups[ $r['group_id'] ][] = $r;
        $rank_by_user[ (int) $r['user_id'] ] = (int) $r['Rank'];
    }
    $games = array();
    $slots = 0; $not_played = 0; $unrec = 0;
    foreach ( $groups as $members ) {
        for ( $gn = 1; $gn <= 5; $gn++ ) {
            $present = array();
            foreach ( $members as $m ) {
                if ( $m[ "Game$gn" ] !== null ) {
                    $present[] = array( 'uid' => (int) $m['user_id'], 'val' => (int) $m[ "Game$gn" ] );
                }
            }
            if ( empty( $present ) ) continue;
            $slots++;
            $all_zero = true;
            foreach ( $present as $p ) { if ( $p['val'] !== 0 ) { $all_zero = false; break; } }
            if ( $all_zero ) { $not_played++; continue; }
            $g = spp_crt_reconstruct_round( $present );
            if ( $g === null ) { $unrec++; continue; }
            $games[] = $g;
        }
    }
    $players = array();
    foreach ( $games as $g ) {
        foreach ( array_merge( $g['team1'], $g['team2'] ) as $u ) $players[ $u ] = true;
    }
    return array(
        'games' => $games, 'rank_by_user' => $rank_by_user, 'players' => array_keys( $players ),
        'slots' => $slots, 'not_played' => $not_played, 'unreconstructed' => $unrec,
    );
}

function spp_ppr_table_exists( string $t ) : bool {
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s", $t
    ) );
}

/**
 * Read-only look at the event: blocking errors, per-group counts,
 * rebuilt games, current state of each step.
 */
function spp_ppr_inspect( int $event, array $cfg ) : array {
    global $wpdb;
    $s = array( 'event' => $event, 'errors' => array(), 'occ' => null, 'groups' => array() );
    $sched = $cfg['schedules'];

    if ( $event <= 0 ) { $s['errors'][] = 'No event id given.'; return $s; }

    if ( $cfg['require_ladder'] ) {
        $s['occ'] = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, title, event_date, category_id, cancelled FROM {$wpdb->prefix}gl_event_occurrences WHERE id = %d", $event
        ), ARRAY_A );
        if ( ! $s['occ'] ) {
            $s['errors'][] = "Event $event does not exist.";
            return $s;
        }
        if ( (int) $s['occ']['category_id'] !== 1 ) {
            $s['errors'][] = "Event $event is not a ladder event.";
        }
    }

    if ( ! spp_ppr_table_exists( $sched ) ) { $s['errors'][] = "$sched table is missing."; return $s; }

    $s['rows_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$sched`" );
    $s['rows_event'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$sched` WHERE event_id = %d", $event ) );
    if ( $s['rows_event'] === 0 ) {
        $s['errors'][] = "$sched has no rows for event $event -- it holds a different event (has Create Schedule already run for the next week?).";
    } elseif ( $s['rows_event'] !== $s['rows_total'] ) {
        $s['errors'][] = "$sched mixes events ({$s['rows_event']} of {$s['rows_total']} rows are event $event).";
    }

    if ( spp_ppr_table_exists( $cfg['results_table'] ) ) {
        $res_event = (int) $wpdb->get_var( "SELECT event_id FROM `{$cfg['results_table']}` LIMIT 1" );
        $s['results_event'] = $res_event;
        if ( $res_event === $event ) {
            $s['errors'][] = "Results is already stamped with event $event -- it went through Create Results; use the normal Publish Results path.";
        }
    }
    if ( ! empty( $s['errors'] ) ) return $s;

    $rows = $wpdb->get_results( "SELECT user_id, group_id, Crt_ID, time_id, Rank, Game1, Game2, Game3, Game4, Game5 FROM `$sched`", ARRAY_A );
    $s['scored_cells'] = 0;
    foreach ( $rows as $r ) {
        $k = $r['group_id'];
        if ( ! isset( $s['groups'][ $k ] ) ) {
            $s['groups'][ $k ] = array( 'time_id' => $r['time_id'], 'court' => $r['Crt_ID'], 'players' => 0, 'played' => array() );
        }
        $s['groups'][ $k ]['players']++;
        for ( $gn = 1; $gn <= 5; $gn++ ) {
            if ( $r[ "Game$gn" ] !== null ) { $s['groups'][ $k ]['played'][ $gn ] = true; $s['scored_cells']++; }
        }
    }
    uasort( $s['groups'], fn( $a, $b ) => [ $a['time_id'], $a['court'] ] <=> [ $b['time_id'], $b['court'] ] );
    $s['zero_score'] = ( $s['scored_cells'] === 0 );
    $s['build'] = spp_ppr_build_games( $rows );

    $scores = $cfg['scores_prefix'] . $event;
    $s['scores_table'] = $scores;
    $s['scores_exists'] = spp_ppr_table_exists( $scores );
    $s['ledger_rows'] = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$cfg['ledger_table']}` WHERE event_id = %d AND source = 'ladder'", $event
    ) );
    $s['flag_published'] = (int) get_option( $cfg['flag_published'], 0 );
    $s['flag_posted']    = (int) get_option( $cfg['flag_posted'], 0 );
    return $s;
}

/**
 * Rows of $a and $b identical on every column the scores table is read for.
 */
function spp_ppr_tables_match( string $a, string $b ) : bool {
    global $wpdb;
    $na = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$a`" );
    $nb = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$b`" );
    if ( $na !== $nb || $wpdb->last_error ) return false;
    $same = (int) $wpdb->get_var( "
        SELECT COUNT(*) FROM `$a` x JOIN `$b` y ON x.user_id = y.user_id
        WHERE x.event_id <=> y.event_id AND x.group_id <=> y.group_id AND x.Rank <=> y.Rank
          AND x.Crt_ID <=> y.Crt_ID AND x.time_id <=> y.time_id
          AND x.Game1 <=> y.Game1 AND x.Game2 <=> y.Game2 AND x.Game3 <=> y.Game3
          AND x.Game4 <=> y.Game4 AND x.Game5 <=> y.Game5" );
    return $same === $na && ! $wpdb->last_error;
}

/**
 * Do it. Returns array( 'ok' => bool, 'log' => string[] , ... ).
 * Never echoes. Takes the production lock itself.
 */
function spp_ppr_run( int $event, array $cfg ) : array {
    global $wpdb;
    $out = array( 'ok' => false, 'event' => $event, 'log' => array(), 'backups' => array() );
    $say = function( $m ) use ( &$out ) { $out['log'][] = $m; };

    if ( $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 0)", $cfg['lock_name'] ) ) !== '1' ) {
        $say( 'Another schedule production or partial publish is running -- nothing was changed.' );
        spp_ppr_log( $out, $cfg );
        return $out;
    }
    try {
        $s = spp_ppr_inspect( $event, $cfg );
        if ( ! empty( $s['errors'] ) ) {
            foreach ( $s['errors'] as $e ) $say( "Refused: $e" );
            return $out;
        }
        // Backup-name suffix, unique even for two runs in the same second.
        $ts = gmdate( 'Ymd_His' );
        for ( $n = 2; spp_ppr_table_exists( "{$cfg['backup_prefix']}Schedules_{$event}_{$ts}" ); $n++ ) {
            $ts = gmdate( 'Ymd_His' ) . "_$n";
        }

        // 1. Snapshot Schedules.
        $snap = "{$cfg['backup_prefix']}Schedules_{$event}_{$ts}";
        $wpdb->query( "CREATE TABLE `$snap` AS SELECT * FROM `{$cfg['schedules']}`" );
        if ( $wpdb->last_error || ! spp_ppr_tables_match( $snap, $cfg['schedules'] ) ) {
            $say( "Step 1 failed: snapshot $snap could not be created/verified ({$wpdb->last_error}). Nothing else was changed." );
            return $out;
        }
        $out['backups'][] = $snap;
        $say( "Step 1: Schedules snapshot saved to $snap ({$s['rows_total']} rows)." );

        // 2. Schedules_Scores_{event}.
        $scores = $s['scores_table'];
        if ( $s['zero_score'] ) {
            $say( "Step 2: no scores were entered -- $scores not created." );
        } elseif ( $s['scores_exists'] ) {
            if ( ! spp_ppr_tables_match( $scores, $cfg['schedules'] ) ) {
                $say( "Step 2 stopped: $scores already exists and does NOT match Schedules. Not overwritten; ratings and flags not touched. Needs a manual look." );
                return $out;
            }
            $say( "Step 2: $scores already exists and matches Schedules -- kept." );
        } else {
            $wpdb->query( "CREATE TABLE `$scores` SELECT * FROM `{$cfg['schedules']}`" );
            if ( $wpdb->last_error || ! spp_ppr_tables_match( $scores, $cfg['schedules'] ) ) {
                $err = $wpdb->last_error;
                $wpdb->query( "DROP TABLE IF EXISTS `$scores`" );
                $say( "Step 2 failed: $scores could not be created/verified ($err) -- dropped again. Ratings and flags not touched." );
                return $out;
            }
            $say( "Step 2: $scores created ({$s['rows_total']} rows, verified against Schedules)." );
        }

        // 3. Ratings.
        $b = $s['build'];
        if ( $s['zero_score'] || empty( $b['games'] ) ) {
            $say( 'Step 3: no complete game could be rebuilt -- ratings not changed.' );
        } elseif ( $s['ledger_rows'] > 0 ) {
            $say( "Step 3: ratings for event $event were already applied ({$s['ledger_rows']} ledger rows) -- not reprocessed." );
        } else {
            $rows  = $wpdb->get_results( "SELECT user_id, group_id, Rank, Game1, Game2, Game3, Game4, Game5 FROM `$scores`", ARRAY_A );
            $b     = spp_ppr_build_games( $rows );
            $r     = spp_ppr_rate_in_transaction( $event, $b, $cfg, $ts, $out['backups'] );
            foreach ( $r['log'] as $m ) $say( $m );
            if ( ! $r['ok'] ) {
                $say( 'Flags not changed.' );
                return $out;
            }
            $out['rated_players'] = $b['players'];
        }

        // 4. Flags last.
        update_option( $cfg['flag_published'], 0 );
        update_option( $cfg['flag_posted'], 1 );
        if ( (int) get_option( $cfg['flag_published'] ) !== 0 || (int) get_option( $cfg['flag_posted'] ) !== 1 ) {
            $say( 'Step 4 failed: flags did not read back as published=0 / results_posted=1.' );
            return $out;
        }
        $say( "Step 4: {$cfg['flag_published']} = 0, {$cfg['flag_posted']} = 1 -- Create Schedule is unblocked." );
        $out['ok'] = true;
        return $out;

    } catch ( Throwable $e ) {
        $say( 'Stopped on an unexpected error: ' . $e->getMessage() );
        return $out;
    } finally {
        $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $cfg['lock_name'] ) );
        spp_ppr_log( $out, $cfg );
    }
}

/**
 * Step 3: back up rating tables, run the Glicko update in one
 * transaction, verify, commit or roll back.
 */
function spp_ppr_rate_in_transaction( int $event, array $b, array $cfg, string $ts, array &$backups ) : array {
    global $wpdb;
    $log = array();
    $state = $cfg['state_table']; $ledger = $cfg['ledger_table']; $um = $cfg['usermeta_table'];

    $bk = array(
        $state  => "{$cfg['backup_prefix']}club_rating_state_{$event}_{$ts}",
        $ledger => "{$cfg['backup_prefix']}club_rating_event_log_{$event}_{$ts}",
        $um     => "{$cfg['backup_prefix']}glicko_usermeta_{$event}_{$ts}",
    );
    foreach ( $bk as $src => $dst ) {
        $where = ( $src === $um ) ? " WHERE meta_key IN ('spp_glicko_rating','spp_glicko_rating_games')" : '';
        $wpdb->query( "CREATE TABLE `$dst` AS SELECT * FROM `$src`$where" );
        if ( $wpdb->last_error ) {
            return array( 'ok' => false, 'log' => array( "Step 3 not started: backup $dst failed ({$wpdb->last_error})." ) );
        }
        $backups[] = $dst;
    }
    $log[] = 'Step 3: rating tables backed up to ' . implode( ', ', array_values( $bk ) ) . '.';

    $wpdb->query( 'START TRANSACTION' );
    try {
        $r = call_user_func( $cfg['rate'], $b['games'], $b['rank_by_user'], $event, 'ladder', false );
        if ( ! empty( $r['aborted'] ) ) {
            throw new RuntimeException( 'rating engine aborted (players updated by a later event: ' . implode( ', ', $r['aborted_users'] ) . ')' );
        }
        // Verify: one ledger row per rated player, and state == ledger "after".
        $n_players = count( $b['players'] );
        $n_ledger  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$ledger` WHERE event_id = %d AND source = 'ladder'", $event ) );
        $n_match   = (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) FROM `$ledger` l JOIN `$state` s ON s.user_id = l.user_id
            WHERE l.event_id = %d AND l.source = 'ladder'
              AND ABS(s.mu - l.mu_after) < 0.001 AND ABS(s.rd - l.rd_after) < 0.001 AND s.games_played = l.games_after", $event ) );
        if ( $wpdb->last_error ) throw new RuntimeException( 'verification query failed: ' . $wpdb->last_error );
        if ( $n_ledger !== $n_players || $n_match !== $n_players ) {
            throw new RuntimeException( "verification failed: $n_players players, $n_ledger ledger rows, $n_match matching state rows" );
        }
        $wpdb->query( 'COMMIT' );
        if ( $wpdb->last_error ) throw new RuntimeException( 'COMMIT failed: ' . $wpdb->last_error );
    } catch ( Throwable $e ) {
        $wpdb->query( 'ROLLBACK' );
        $log[] = 'Step 3 FAILED and was rolled back: ' . $e->getMessage() . '. Backups kept: ' . implode( ', ', array_values( $bk ) ) . '.';
        return array( 'ok' => false, 'log' => $log );
    }

    // The engine writes usermeta with raw SQL -- drop cached copies.
    foreach ( $wpdb->get_col( "SELECT user_id FROM `$state`" ) as $uid ) {
        wp_cache_delete( (int) $uid, 'user_meta' );
    }
    $log[] = sprintf(
        'Step 3: Glicko ratings updated for %d player(s) from %d game(s) (%d slot(s) not played, %d unreconstructed)%s.',
        count( $b['players'] ), count( $b['games'] ), $b['not_played'], $b['unreconstructed'],
        empty( $r['usermeta_written'] ) ? ' -- usermeta NOT written (too few established players)' : ''
    );
    return array( 'ok' => true, 'log' => $log );
}

function spp_ppr_log( array $out, array $cfg ) : void {
    $entry = array(
        'time'    => current_time( 'mysql' ),
        'user'    => get_current_user_id(),
        'event'   => $out['event'],
        'ok'      => $out['ok'],
        'log'     => $out['log'],
        'backups' => $out['backups'],
    );
    $all = get_option( $cfg['log_option'], array() );
    if ( ! is_array( $all ) ) $all = array();
    $all[] = $entry;
    update_option( $cfg['log_option'], $all, false );
    error_log( '[spp_publish_partial_results] event ' . $out['event'] . ' ok=' . ( $out['ok'] ? 1 : 0 ) . ' :: ' . implode( ' | ', $out['log'] ) );
}

/* =========================================================
   SHORTCODE: event picker -> preview (dry run) -> nonce'd POST run
   ========================================================= */

function spp_publish_partial_results_shortcode() {
    // Never cache this page, not even the permission message.
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    if ( ! headers_sent() ) nocache_headers();
    if ( ! spp_is_admin_or_editor() ) {
        return '<p>You do not have permission to use this tool.</p>';
    }

    $cfg = spp_ppr_config();
    ob_start();
    echo '<div style="max-width:100%;overflow-x:auto;font-family:Arial,sans-serif;font-size:14px;">';

    // -- RUN (POST) ------------------------------------------------------------
    if ( isset( $_POST['spp_ppr_run'] ) ) {
        $event = isset( $_POST['ppr_event'] ) ? (int) $_POST['ppr_event'] : 0;
        if ( ! $event ) {
            echo '<p style="color:#c0392b;font-weight:bold;">No event id given -- nothing was changed.</p></div>';
            return ob_get_clean();
        }
        if ( ! isset( $_POST['spp_ppr_nonce'] ) || ! wp_verify_nonce( $_POST['spp_ppr_nonce'], 'spp_ppr_run_' . $event ) ) {
            echo '<p style="color:#c0392b;font-weight:bold;">This form has expired or is invalid -- nothing was changed. Reload the preview and try again.</p></div>';
            return ob_get_clean();
        }
        $res = spp_ppr_run( $event, $cfg );
        $col = $res['ok'] ? '#339966' : '#c0392b';
        echo '<h3 style="color:' . $col . ';">' . ( $res['ok'] ? 'Done' : 'Stopped' ) . ' -- event ' . (int) $event . '</h3><ul>';
        foreach ( $res['log'] as $m ) echo '<li>' . esc_html( $m ) . '</li>';
        echo '</ul></div>';
        return ob_get_clean();
    }

    // -- EVENT PICKER ----------------------------------------------------------
    $event = isset( $_GET['ppr_event'] ) ? (int) $_GET['ppr_event'] : 0;
    $suggest = (int) get_option( 'spp_current_event', 0 );
    echo '<form method="get" style="margin-bottom:16px;">';
    foreach ( $_GET as $k => $v ) {
        if ( $k !== 'ppr_event' && is_string( $v ) ) echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
    }
    echo '<label><strong>Ladder event id:</strong> <input type="number" name="ppr_event" value="' . esc_attr( $event ?: $suggest ) . '" style="width:110px;"></label> ';
    echo '<button type="submit">Preview</button></form>';
    if ( ! $event ) {
        echo '<p>Enter the event id and click Preview. Nothing is changed until you confirm on the preview.</p></div>';
        return ob_get_clean();
    }

    // -- PREVIEW (read-only) ---------------------------------------------------
    $s = spp_ppr_inspect( $event, $cfg );
    $title = $s['occ'] ? $s['occ']['title'] . ' ' . date( 'F d, Y', strtotime( $s['occ']['event_date'] ) ) : 'Event ' . $event;
    echo '<h3>Publish partial results -- ' . esc_html( $title ) . ' (event ' . (int) $event . ')</h3>';
    if ( ! empty( $s['errors'] ) ) {
        echo '<div style="background:#fdf3f2;border:2px solid #c0392b;border-radius:6px;padding:12px;"><strong>Cannot run:</strong><ul>';
        foreach ( $s['errors'] as $e ) echo '<li>' . esc_html( $e ) . '</li>';
        echo '</ul></div></div>';
        return ob_get_clean();
    }

    echo '<table border="1" cellpadding="5" style="border-collapse:collapse;max-width:100%;"><tr style="background:#3766AB;"><th style="color:#fff !important;">Time</th><th style="color:#fff !important;">Court</th><th style="color:#fff !important;">Group</th><th style="color:#fff !important;">Players</th><th style="color:#fff !important;">Games with scores</th></tr>';
    foreach ( $s['groups'] as $gid => $g ) {
        $played = array_keys( $g['played'] );
        echo '<tr><td>' . (int) $g['time_id'] . '</td><td>' . (int) $g['court'] . '</td><td>' . (int) $gid . '</td><td>' . (int) $g['players'] . '</td><td>'
           . ( $played ? 'Game ' . implode( ', ', $played ) : '<em>none</em>' ) . '</td></tr>';
    }
    echo '</table>';

    $b = $s['build'];
    echo '<p>Rebuilt <strong>' . count( $b['games'] ) . '</strong> game(s) for <strong>' . count( $b['players'] ) . '</strong> player(s)'
       . ' (' . (int) $b['unreconstructed'] . ' unreconstructed, ' . (int) $b['not_played'] . ' not played). '
       . 'Current flags: published = ' . (int) $s['flag_published'] . ', results_posted = ' . (int) $s['flag_posted'] . '.</p>';

    echo '<div style="max-width:100%;box-sizing:border-box;background:#fff8e1;border:2px solid #e67e22;border-radius:6px;padding:12px 16px;margin:12px 0;font-size:15px;">';
    echo '<p style="margin:0 0 6px;"><strong>When you confirm below:</strong></p><ul style="margin:0 0 0 20px;">';
    if ( $s['zero_score'] ) {
        echo '<li>No scores were entered for this event, so there is nothing to save and no player rating changes.</li>';
    } elseif ( $s['ledger_rows'] > 0 ) {
        echo '<li>The scores are saved. They already count toward player ratings (done earlier) -- that is not repeated.</li>';
    } else {
        echo '<li>The scores that were entered are <strong>saved</strong> and <strong>count toward player ratings</strong> (Club Rating).</li>';
    }
    echo '<li>Ladder rankings are <strong>NOT</strong> changed.</li>';
    echo '<li><strong>No</strong> results email is sent.</li>';
    echo '<li>The event is closed, so the next schedule can be created.</li></ul>';
    echo '<p style="margin:8px 0 0;font-weight:bold;">Nothing changes until you click the button at the bottom of this page.</p></div>';
    echo '<p style="font-size:12px;color:#666;">Details: Schedules is backed up to a <code>' . esc_html( $cfg['backup_prefix'] ) . 'Schedules_' . (int) $event . '_…</code> table'
       . ( $s['zero_score'] ? '' : ', scores are kept as <code>' . esc_html( $s['scores_table'] ) . '</code>' )
       . ', and spp_schedule_published / spp_results_posted are set to 0 / 1.</p>';

    if ( ! $s['zero_score'] && $s['ledger_rows'] === 0 && ! empty( $b['games'] ) ) {
        $r = call_user_func( $cfg['rate'], $b['games'], $b['rank_by_user'], $event, 'ladder', true );
        if ( ! empty( $r['aborted'] ) ) {
            echo '<p style="color:#c0392b;font-weight:bold;">Dry run: the rating engine would abort (user ids ' . esc_html( implode( ', ', $r['aborted_users'] ) ) . ') -- a run would stop at step 3.</p>';
        } else {
            echo '<h4>Rating dry run (nothing written)</h4><table border="1" cellpadding="4" style="border-collapse:collapse;font-size:13px;max-width:100%;"><tr><th>user_id</th><th>Club Rating now</th><th>after</th><th>games</th></tr>';
            foreach ( $r['dry_run_rows'] as $row ) {
                $now = get_user_meta( $row['user_id'], 'spp_glicko_rating', true );
                echo '<tr><td>' . (int) $row['user_id'] . '</td><td>' . esc_html( $now ) . '</td><td>' . esc_html( $row['rating'] ) . '</td><td>'
                   . (int) $row['games_before'] . ' → ' . (int) $row['games_after'] . '</td></tr>';
            }
            echo '</table>';
        }
    }

    echo '<form method="post" style="margin-top:16px;" onsubmit="this.querySelector(\'button\').disabled=true;">';
    wp_nonce_field( 'spp_ppr_run_' . $event, 'spp_ppr_nonce' );
    echo '<input type="hidden" name="ppr_event" value="' . (int) $event . '">';
    // Hidden field, not the button's name: a disabled submit button isn't sent.
    echo '<input type="hidden" name="spp_ppr_run" value="1">';
    echo '<button type="submit" style="padding:10px 20px;background:#c0392b;color:#fff;border:none;border-radius:4px;" onclick="return confirm(\'Publish partial results for event ' . (int) $event . '?\\n\\nScores are saved and count toward player ratings.\\nRankings are NOT changed.\\nNo results email is sent.\\nThe event is closed so the next schedule can be created.\');">Publish partial results for event ' . (int) $event . '</button>';
    echo '</form></div>';
    return ob_get_clean();
}
