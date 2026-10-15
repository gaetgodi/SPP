<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Create Results forced re-run vs Master -- replay of the event 169 failure.
 *
 * What happened on 169: Create Results ran, Apply Override + Copy Ranks
 * copied its ranks into usermeta and Master, then Create Results was
 * re-run with force=1. The force path restored usermeta Rank from the
 * pre-event snapshot but not Master, and Step 10 reads Master.Rank as the
 * previous rank of every absent player -- so they got +1.6 twice.
 *
 * 3.5.1: the Apply Override step now also drops Results_<event>, as the
 * real cleanup does -- 3.5.0's version of this test kept it, which is
 * why it passed while the real re-run (no Results_169) still went down
 * the first-run path. Scenario B now also checks a non-forced re-run
 * after Apply Override is refused.
 *
 * Scenarios (all on throwaway zzfr_t_* tables, fake event 990169):
 *   A. Normal single run, Master == usermeta == pre-event ranks: runs, and
 *      every absent player gets RankPrev = pre-event rank, RankCalc = +1.6.
 *   B. Run 1 -> Apply Override archive -> Copy Ranks -> forced re-run:
 *      the re-run must give the same RankPrev / RankCalc / Rank as run 1
 *      for every player, and must not re-take the snapshot.
 *   C. Master and usermeta disagree on a normal run: Create Results stops
 *      with a plain message and writes nothing.
 * Run once against the old code (live theme before 3.5.0) and once against
 * the new: the old code fails B (doubled penalty) and C (no guard).
 *
 * Fixture: event 169's permanent archives -- Schedules_Scores_169 (the 30
 * played rows) and usermeta_rank_pre_event_169 (pre-event ranks, never
 * overwritten) -- for the 145 players in Results_all event 169. Real tables
 * are only read. ClubRating / Rating (shadow inputs) are read live, so the
 * test compares runs with each other, not with fixed shadow numbers.
 *
 * Copy Ranks is replayed with its own two statements (usermeta Rank from
 * Results.Rank, then Master.Rank = LPAD(usermeta Rank, 3, 0) as the
 * membership rebuild does): the real functions are hard-wired to the real
 * tables. The Apply Override archive is the real spp_ao_archive_results().
 *
 * Run: php tests/spp-create-results-force-rerun-test.php
 */

$GLOBALS['fr_mail'] = array();
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $GLOBALS['fr_mail'][] = array( 'to' => $to, 'subject' => $subject );
    return true;
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
if ( ( new ReflectionFunction( 'wp_mail' ) )->getFileName() !== __FILE__ ) { echo "ABORT: wp_mail is not the test stub\n"; exit( 1 ); }
foreach ( array( 'spp_create_results_run', 'spp_cr_tables', 'spp_ao_archive_results' ) as $fn ) {
    if ( ! function_exists( $fn ) ) { echo "ABORT: $fn not loaded\n"; exit( 1 ); }
}
$cr_file = ( new ReflectionFunction( 'spp_create_results_run' ) )->getFileName();
preg_match( '/Version:\s*([\d.]+)/', file_get_contents( $cr_file ), $vm );
echo "Create Results forced re-run tests -- code under test: {$cr_file} (v" . ( $vm[1] ?? '?' ) . ")\n";

global $wpdb;
$P   = 'zzfr_t_';
$EV  = 990169;
$UM  = 'zzfr_t_usermeta';
$MS  = 'zzfr_t_Master';
$SNAP = "{$P}usermeta_rank_pre_event_{$EV}";

$pass = 0; $fail = 0;
function fr_ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

// ── Write firewall: only zzfr_t_* tables may be written ────────────────────
$GLOBALS['fr_blocked'] = array();
add_filter( 'query', function ( $q ) {
    if ( ! preg_match( '/^\s*(DROP|RENAME|CREATE|INSERT|UPDATE|DELETE|ALTER|TRUNCATE|REPLACE)\b/i', $q ) ) return $q;
    $targets = array();
    if ( preg_match( '/^\s*RENAME\s+TABLE\s+`?(\w+)`?\s+TO\s+`?(\w+)`?/i', $q, $m ) ) { $targets = array( $m[1], $m[2] ); }
    elseif ( preg_match( '/^\s*(?:DROP|CREATE)\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?(\w+)`?/i', $q, $m ) ) { $targets = array( $m[1] ); }
    elseif ( preg_match( '/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $q, $m ) ) { $targets = array( $m[1] ); }
    elseif ( preg_match( '/^\s*UPDATE\s+`?(\w+)`?/i', $q, $m ) ) { $targets = array( $m[1] ); }
    elseif ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $q, $m ) ) { $targets = array( $m[1] ); }
    elseif ( preg_match( '/^\s*(?:ALTER|TRUNCATE)\s+TABLE\s+`?(\w+)`?/i', $q, $m ) ) { $targets = array( $m[1] ); }
    else { $targets = array( '?' ); }
    global $wpdb;
    foreach ( $targets as $t ) {
        if ( strpos( $t, 'zzfr_t_' ) === 0 ) continue;
        if ( $t === $wpdb->options ) continue; // WordPress' own option/transient writes
        $GLOBALS['fr_blocked'][] = substr( preg_replace( '/\s+/', ' ', $q ), 0, 160 );
        return "SELECT 'fr_test_blocked_write'";
    }
    return $q;
} );

function fr_real_fingerprint() {
    global $wpdb;
    $fp = array();
    foreach ( array( 'Schedules', 'Master', 'Results', 'Results_all', 'club_rating_state', 'club_rating_event_log', 'event_date_lookup', 'preferred', 'preferred_new', 'Schedules_Scores_169', 'usermeta_rank_pre_event_169' ) as $t ) {
        $r = $wpdb->get_row( "CHECKSUM TABLE `{$t}`", ARRAY_A );
        $fp[ $t ] = $r ? $r['Checksum'] : 'missing';
    }
    $fp['usermeta'] = $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', SUM(CRC32(CONCAT(user_id, meta_key, IFNULL(meta_value,''))))) FROM {$wpdb->usermeta} WHERE meta_key IN ('Rank','ClubRating','Rating','spp_glicko_rating','spp_glicko_rating_games')" );
    $fp['tables'] = md5( implode( ',', $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name NOT LIKE 'zzfr\\_t\\_%' ORDER BY table_name" ) ) );
    return $fp;
}

function fr_drop_all() {
    global $wpdb;
    foreach ( $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzfr\\_t\\_%'" ) as $t ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
    }
}

// Fresh state (after the base tables): schedule = event 169's played rows,
// usermeta Rank + Master Rank = pre-event ranks, no Results_<event>, no
// snapshot. fr_drop_all() sweeps every zzfr_t_ table, so the two base
// tables are rebuilt from PHP copies each time.
function fr_fresh() {
    global $wpdb, $EV, $UM, $MS, $base_sched_rows, $base_players_rows;
    fr_drop_all();
    $wpdb->query( "CREATE TABLE zzfr_t_base_sched AS SELECT * FROM Schedules_Scores_169 WHERE 1 = 0" );
    foreach ( $base_sched_rows as $r ) $wpdb->insert( 'zzfr_t_base_sched', $r );
    $wpdb->query( "CREATE TABLE zzfr_t_base_players (user_id BIGINT, base_rank INT, first_name VARCHAR(100), last_name VARCHAR(100), rating VARCHAR(20))" );
    foreach ( $base_players_rows as $r ) $wpdb->insert( 'zzfr_t_base_players', $r );

    $wpdb->query( "CREATE TABLE zzfr_t_Schedules LIKE Schedules" ); // keeps the generated Score column
    $cols = 'Sequence, registration_date, Rank, user_id, first_name, last_name, user_phone, user_email, event_id, Game1, Game2, Game3, Game4, Game5, group_id, Crt_ID, time_id, travel, RankPrime';
    $wpdb->query( "INSERT INTO zzfr_t_Schedules ({$cols}) SELECT {$cols} FROM zzfr_t_base_sched" );
    $wpdb->query( "UPDATE zzfr_t_Schedules SET event_id = {$EV}" );

    $wpdb->query( "CREATE TABLE {$UM} LIKE {$wpdb->usermeta}" );
    $wpdb->query( "INSERT INTO {$UM} (user_id, meta_key, meta_value) SELECT user_id, 'Rank', base_rank FROM zzfr_t_base_players" );
    $wpdb->query( "INSERT INTO {$UM} (user_id, meta_key, meta_value) SELECT u.user_id, u.meta_key, u.meta_value FROM {$wpdb->usermeta} u JOIN zzfr_t_base_players b ON b.user_id = u.user_id WHERE u.meta_key = 'ClubRating'" );

    $wpdb->query( "CREATE TABLE {$MS} AS SELECT * FROM Master WHERE 1 = 0" );
    $wpdb->query( "INSERT INTO {$MS} (Rank, user_id, first_name, last_name, user_email, Ladder, Rating)
                   SELECT LPAD(b.base_rank, 3, '0'), b.user_id, b.first_name, b.last_name, '', 'Yes', b.rating FROM zzfr_t_base_players b" );

    $wpdb->query( "CREATE TABLE zzfr_t_event_date_lookup LIKE event_date_lookup" );
    $wpdb->query( "CREATE TABLE zzfr_t_Results_all LIKE Results_all" );
}

function fr_run( $force ) {
    global $P, $UM, $MS;
    $T = array(
        'schedules' => 'zzfr_t_Schedules', 'master' => $MS, 'usermeta' => $UM, 'results' => 'zzfr_t_Results', 'results_all' => 'zzfr_t_Results_all',
        'event_date_lookup' => 'zzfr_t_event_date_lookup', 'prefix' => $P, 'nc_groups' => array( 1 ),
    );
    ob_start();
    spp_create_results_run( $force, $T );
    $html = ob_get_clean();
    return array( $html, strpos( $html, 'Results calculated successfully' ) !== false );
}

function fr_results_by_uid( $table ) {
    global $wpdb;
    $by = array();
    foreach ( $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ) as $r ) $by[ (int) $r['user_id'] ] = $r;
    return $by;
}

// Apply Override Stage 1's archive + Results rebuild + the part of its
// cleanup that matters here (it drops Results_<event>, keeping only the
// padded Results_00000<event> backup), then Copy Ranks.
function fr_apply_override_and_copy_ranks() {
    global $wpdb, $EV, $UM, $MS, $P;
    ob_start();
    spp_ao_archive_results( $EV, array( 'results' => 'zzfr_t_Results', 'results_all' => 'zzfr_t_Results_all', 'tmp' => 'zzfr_t_tmp_results' ) );
    ob_end_clean();
    $wpdb->query( "DROP TABLE IF EXISTS zzfr_t_Results" );
    $wpdb->query( "CREATE TABLE zzfr_t_Results AS (SELECT * FROM zzfr_t_tmp_results WHERE user_id IN (SELECT user_id FROM {$MS}))" );
    $pad = str_pad( $EV, 10, '0', STR_PAD_LEFT );
    $wpdb->query( "DROP TABLE IF EXISTS {$P}Results_{$pad}" );
    $wpdb->query( "CREATE TABLE {$P}Results_{$pad} AS SELECT * FROM zzfr_t_Results" );
    $wpdb->query( "DROP TABLE IF EXISTS {$P}Results_{$EV}" );
    // Copy Ranks: usermeta Rank <- Results.Rank, then the Master rebuild.
    foreach ( $wpdb->get_results( "SELECT user_id, Rank FROM zzfr_t_Results", ARRAY_A ) as $r ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$UM} WHERE user_id = %d AND meta_key = 'Rank'", $r['user_id'] ) );
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$UM} (user_id, meta_key, meta_value) VALUES (%d, 'Rank', %s)", $r['user_id'], $r['Rank'] ) );
    }
    $wpdb->query( "UPDATE {$MS} m JOIN {$UM} u ON u.user_id = m.user_id AND u.meta_key = 'Rank' SET m.Rank = LPAD(u.meta_value, 3, '0')" );
}

$before = fr_real_fingerprint();

// ── Base copies (real tables read once) ────────────────────────────────────
fr_drop_all();
$wpdb->query( "CREATE TABLE zzfr_t_base_sched AS SELECT * FROM Schedules_Scores_169" );
$wpdb->query( "CREATE TABLE zzfr_t_base_players AS
    SELECT r.user_id, CAST(s.meta_value AS UNSIGNED) AS base_rank,
           SUBSTRING_INDEX(r.display_name, ' ', 1) AS first_name,
           SUBSTRING(r.display_name, LENGTH(SUBSTRING_INDEX(r.display_name, ' ', 1)) + 2) AS last_name,
           (SELECT meta_value FROM {$wpdb->usermeta} um WHERE um.user_id = r.user_id AND um.meta_key = 'Rating' LIMIT 1) AS rating
    FROM Results_all r JOIN usermeta_rank_pre_event_169 s ON s.user_id = r.user_id AND s.meta_key = 'Rank'
    WHERE r.event_id = 169" );
$n_players = (int) $wpdb->get_var( "SELECT COUNT(*) FROM zzfr_t_base_players" );
$n_played  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM zzfr_t_base_sched" );
fr_ok( $n_players === 145 && $n_played === 30, "fixture: 145 players with a pre-event rank, 30 played (got {$n_players}/{$n_played})" );
$ERNST = 3181; $ASIM = 2513;
$base_rank = array_map( 'intval', array_column( $wpdb->get_results( "SELECT user_id, base_rank FROM zzfr_t_base_players", ARRAY_A ), 'base_rank', 'user_id' ) );
$played = array_flip( array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM zzfr_t_base_sched" ) ) );

$base_sched_rows   = $wpdb->get_results( "SELECT * FROM zzfr_t_base_sched", ARRAY_A );
$base_players_rows = $wpdb->get_results( "SELECT * FROM zzfr_t_base_players", ARRAY_A );

// ── A. Normal single run ───────────────────────────────────────────────────
echo "A. Normal single run (Master == usermeta == pre-event ranks)\n";
fr_fresh();
fr_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$MS}" ) === 145 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$UM} WHERE meta_key = 'Rank'" ) === 145, 'throwaway Master and usermeta hold 145 ranks each' );
list( $h, $ok ) = fr_run( false );
fr_ok( $ok, 'normal run completes (guard does not block a synced Master)' );
fr_ok( strpos( $h, 'Stopped:' ) === false, 'no guard message on a normal run' );
fr_ok( spp_cr_snapshot_is_valid_baseline( $SNAP ), 'run 1 took the pre-event snapshot (clean_baseline)' );
$R1 = fr_results_by_uid( "{$P}Results_{$EV}" );
$bad_prev = 0; $bad_calc = 0; $absent = 0;
foreach ( $R1 as $uid => $r ) {
    if ( isset( $played[ $uid ] ) ) continue;
    $absent++;
    if ( (int) $r['RankPrev'] !== $base_rank[ $uid ] ) $bad_prev++;
    if ( abs( (float) $r['RankCalc'] - ( $base_rank[ $uid ] + 1.6 ) ) > 0.001 ) $bad_calc++;
}
fr_ok( $absent === 115 && $bad_prev === 0, "all {$absent} absent players: RankPrev = pre-event rank ({$bad_prev} wrong)" );
fr_ok( $bad_calc === 0, "all absent players: RankCalc = pre-event rank + 1.6 ({$bad_calc} wrong)" );
fr_ok( (int) $R1[ $ERNST ]['RankPrev'] === 1 && (float) $R1[ $ERNST ]['RankCalc'] === 2.6 && (int) $R1[ $ERNST ]['Rank'] === 2, "Ernst Boxler: prev 1, calc 2.60, rank 2 (got {$R1[$ERNST]['RankPrev']}, {$R1[$ERNST]['RankCalc']}, {$R1[$ERNST]['Rank']})" );
fr_ok( (int) $R1[ $ASIM ]['Rank'] === 1 && $R1[ $ASIM ]['GroupStatus'] === 'NC' && (float) $R1[ $ASIM ]['RankCalc'] === 2.0, 'Asim Sayed (NC): calc 2.00 = prev, rank 1' );
$snap_ck = $wpdb->get_row( "CHECKSUM TABLE {$SNAP}", ARRAY_A )['Checksum'];
$snap_comment = $wpdb->get_row( "SHOW TABLE STATUS LIKE '{$SNAP}'", ARRAY_A )['Comment'];

// ── B. Run 1 -> Apply Override -> Copy Ranks -> forced re-run ──────────────
echo "B. Apply Override + Copy Ranks after run 1, then force=1 re-run\n";
fr_apply_override_and_copy_ranks();
$ernst_after_copy = $wpdb->get_var( "SELECT Rank FROM {$MS} WHERE user_id = {$ERNST}" );
fr_ok( $ernst_after_copy === '002', "after Copy Ranks Master holds run 1's ranks (Ernst {$ernst_after_copy})" );
$AO1 = fr_results_by_uid( 'zzfr_t_Results_all' );
fr_ok( ! $wpdb->get_var( "SHOW TABLES LIKE '{$P}Results\\_{$EV}'" ), 'after Apply Override, Results_<event> is gone (as on production)' );
list( $h, $ok ) = fr_run( false );
fr_ok( ! $ok && strpos( $h, 'has already been processed' ) !== false, 'NON-forced re-run after Apply Override is refused' );
fr_ok( (int) $wpdb->get_var( "SELECT Rank FROM {$MS} WHERE user_id = {$ERNST}" ) === 2, 'refused run changed nothing (Master still holds run 1 ranks)' );
list( $h, $ok ) = fr_run( true );
fr_ok( strpos( $h, 'force=1: restored' ) !== false, 'forced re-run took the restore path' );
fr_ok( $ok, 'forced re-run completes' );
fr_ok( strpos( $h, 'Pre-run snapshot already exists' ) !== false, 'forced re-run says the snapshot already exists (not re-taken)' );
fr_ok( $wpdb->get_row( "CHECKSUM TABLE {$SNAP}", ARRAY_A )['Checksum'] === $snap_ck
    && $wpdb->get_row( "SHOW TABLE STATUS LIKE '{$SNAP}'", ARRAY_A )['Comment'] === $snap_comment, 'snapshot table unchanged (same checksum and comment)' );
$R2 = fr_results_by_uid( "{$P}Results_{$EV}" );
$diff_prev = 0; $diff_calc = 0; $diff_shadow = 0; $diff_rank = 0; $diff_names = array();
foreach ( $R1 as $uid => $r ) {
    $s = $R2[ $uid ] ?? null;
    if ( ! $s ) { $diff_rank++; continue; }
    if ( (int) $s['RankPrev'] !== (int) $r['RankPrev'] ) $diff_prev++;
    if ( (string) $s['RankCalc'] !== (string) $r['RankCalc'] ) $diff_calc++;
    if ( (string) $s['RankCalc_Shadow'] !== (string) $r['RankCalc_Shadow'] ) $diff_shadow++;
    if ( (int) $s['Rank'] !== (int) $r['Rank'] ) { $diff_rank++; if ( count( $diff_names ) < 3 ) $diff_names[] = $r['display_name']; }
}
echo "     (info) Ernst after the forced re-run: prev {$R2[$ERNST]['RankPrev']}, calc {$R2[$ERNST]['RankCalc']}, rank {$R2[$ERNST]['Rank']}\n";
fr_ok( $diff_prev === 0, "re-run RankPrev identical to run 1 for all players ({$diff_prev} differ)" );
fr_ok( $diff_calc === 0, "re-run RankCalc identical to run 1 ({$diff_calc} differ)" );
fr_ok( $diff_shadow === 0, "re-run RankCalc_Shadow identical to run 1 ({$diff_shadow} differ)" );
fr_ok( $diff_rank === 0, "re-run final Rank identical to run 1 ({$diff_rank} differ" . ( $diff_names ? ': ' . implode( ', ', $diff_names ) : '' ) . ')' );
fr_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$UM} u JOIN {$SNAP} s ON s.user_id = u.user_id AND s.meta_key = 'Rank' WHERE u.meta_key = 'Rank' AND u.meta_value <> s.meta_value" ) === 0, 'usermeta Rank restored to the snapshot' );
$drift = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$MS} m JOIN {$UM} u ON u.user_id = m.user_id AND u.meta_key = 'Rank' WHERE CAST(m.Rank AS SIGNED) <> CAST(u.meta_value AS SIGNED)" );
fr_ok( $drift === 0, "Master matches usermeta after the forced re-run ({$drift} differ)" );
// And the archive the convenor would publish from it matches run 1's.
fr_apply_override_and_copy_ranks();
$AO2 = fr_results_by_uid( 'zzfr_t_Results_all' );
$ao_diff = 0;
foreach ( $AO1 as $uid => $r ) if ( ! isset( $AO2[ $uid ] ) || (int) $AO2[ $uid ]['Rank'] !== (int) $r['Rank'] || (string) $AO2[ $uid ]['RankPrev'] !== (string) $r['RankPrev'] ) $ao_diff++;
fr_ok( $ao_diff === 0 && count( $AO2 ) === 145, "Apply Override archive after the re-run equals run 1's archive ({$ao_diff} differ)" );

// ── C. Guard: Master and usermeta disagree on a normal run ─────────────────
echo "C. Guard when Master and usermeta disagree\n";
fr_fresh();
$wpdb->query( "UPDATE {$MS} SET Rank = '002' WHERE user_id = {$ERNST}" );
$wpdb->query( "UPDATE {$MS} SET Rank = '007' WHERE user_id = 3178" );
$wpdb->query( "DELETE FROM {$UM} WHERE meta_key = 'Rank' AND user_id = 2101" );
$sched_ck = $wpdb->get_row( "CHECKSUM TABLE zzfr_t_Schedules", ARRAY_A )['Checksum'];
list( $h, $ok ) = fr_run( false );
fr_ok( ! $ok, 'run with Master != usermeta does not calculate' );
fr_ok( strpos( $h, 'Stopped: 3 player(s)' ) !== false, 'message gives the count (3: two different ranks, one with no profile rank)' );
fr_ok( strpos( $h, 'Ernst Boxler (Master 2, profile 1)' ) !== false && strpos( $h, 'profile none' ) !== false, 'message names players with both ranks' );
fr_ok( ! $wpdb->get_var( "SHOW TABLES LIKE '{$P}Results\\_{$EV}'" ) && ! $wpdb->get_var( "SHOW TABLES LIKE '{$SNAP}'" ), 'no Results_<event> and no snapshot created' );
fr_ok( $wpdb->get_row( "CHECKSUM TABLE zzfr_t_Schedules", ARRAY_A )['Checksum'] === $sched_ck, 'schedule untouched (stopped before Step 2)' );
if ( function_exists( 'spp_cr_master_rank_drift' ) ) {
    $wpdb->query( "UPDATE {$MS} SET Rank = '001' WHERE user_id = {$ERNST}" );
    $wpdb->query( "UPDATE {$MS} SET Rank = '005' WHERE user_id = 3178" );
    $wpdb->query( "INSERT INTO {$UM} (user_id, meta_key, meta_value) VALUES (2101, 'Rank', '14')" );
    fr_ok( spp_cr_master_rank_drift( $MS, $UM ) === array(), 'once fixed, the drift check is empty' );
    list( $h, $ok ) = fr_run( false );
    fr_ok( $ok, 'and the run then completes' );
}

// ── Cleanup + proof nothing real changed ───────────────────────────────────
fr_drop_all();
$after = fr_real_fingerprint();
fr_ok( $before === $after, 'real tables, usermeta ranks/ratings and the table list unchanged' );
fr_ok( empty( $GLOBALS['fr_blocked'] ), 'no write to a non-test table was attempted' . ( $GLOBALS['fr_blocked'] ? ': ' . $GLOBALS['fr_blocked'][0] : '' ) );
fr_ok( empty( $GLOBALS['fr_mail'] ), 'no email sent' );
fr_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zzfr\\_t\\_%'" ) === 0, 'all zzfr_t_ tables dropped' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
