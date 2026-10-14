<?php
// Command line only: these scripts load WordPress and/or write test data, so
// a web request must never run them (the theme folder is web-reachable).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * NC (Not Complete) group status -- end-to-end tests.
 *
 * Every write goes to throwaway zznc_t_* tables (copies of the archived
 * Schedules_Scores_169 under a fake event id 990169, and of
 * Schedules_Scores_167 under 990167), a throwaway option (via the
 * spp_nc_groups_option filter), or session-only TEMPORARY tables. A
 * `query` filter blocks and reports any write to anything else (except
 * wp_options rows WordPress itself touches). The whole Create Results
 * pipeline runs through spp_create_results_run() with a 'zznc_t_' table
 * prefix -- that prefix also switches off its Step 13 cleanup and the
 * preferred promotion. Real Schedules/Master/Results/Results_all/rating
 * tables are checksummed before and after, the list of real tables is
 * compared, and the real spp_nc_groups option must be untouched.
 *
 * Self-contained (2026-10-14): the test no longer depends on live state.
 *   - The event-169 fixture comes from the permanent Schedules_Scores_169
 *     snapshot, not the live Schedules (which moves on every week), and
 *     group 1's games 4 and 5 are reset to NULL in the copy -- the
 *     "incomplete group" the test was written against (Create Results
 *     has since turned those NULLs into 0 in the real tables). Byes are
 *     put back to NULL too, as they were before Create Results: in a
 *     5-player group the bye is the only 0 in that game's column (a real
 *     0-point score is two teammates' 0).
 *   - The Results_all copies are built to the shape each check needs:
 *     GroupStatus is added only if missing, and the "no column" copy has
 *     it dropped if present. Section 9's pre-ALTER readers run on a
 *     session-only TEMPORARY Results_all without the column, because the
 *     real one now has it.
 *
 * Run: php tests/spp-nc-group-status-test.php
 */

$GLOBALS['nc_mail'] = array();
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $GLOBALS['nc_mail'][] = array( 'to' => $to, 'subject' => $subject );
    return true;
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    require '/var/www/vhosts/pickleballstouffville.ca/httpdocs/wp-load.php';
}
if ( ( new ReflectionFunction( 'wp_mail' ) )->getFileName() !== __FILE__ ) { echo "ABORT: wp_mail is not the test stub\n"; exit( 1 ); }
foreach ( array( 'spp_create_results_run', 'spp_cr_tables', 'spp_nc_groups_for_event', 'spp_ao_archive_results', 'spp_sc_recalculate', 'spp_ppr_build_games', 'spp_crt_process_event_ratings' ) as $fn ) {
    if ( ! function_exists( $fn ) ) { echo "ABORT: $fn not loaded\n"; exit( 1 ); }
}

global $wpdb;
$P     = 'zznc_t_';
$EV    = 990169;
$EV167 = 990167;
$OPT   = 'zznc_t_nc_groups';
add_filter( 'spp_nc_groups_option', function () use ( $OPT ) { return $OPT; } );

$pass = 0; $fail = 0;
function nc_ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

// ── Write firewall ─────────────────────────────────────────────────────────
// Allowed write targets: zznc_t_* tables, and the TEMPORARY tables this
// test creates by name (only while $GLOBALS['nc_temp_ok'] lists them).
$GLOBALS['nc_blocked'] = array();
$GLOBALS['nc_temp_ok'] = array();
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
        if ( strpos( $t, 'zznc_t_' ) === 0 ) continue;
        if ( in_array( $t, $GLOBALS['nc_temp_ok'], true ) ) continue;
        if ( $t === $wpdb->options ) continue; // WordPress' own option/transient writes
        $GLOBALS['nc_blocked'][] = substr( preg_replace( '/\s+/', ' ', $q ), 0, 160 );
        return "SELECT 'nc_test_blocked_write'";
    }
    return $q;
} );

function nc_real_fingerprint() {
    global $wpdb;
    $fp = array();
    foreach ( array( 'Schedules', 'Master', 'Results', 'Results_all', 'club_rating_state', 'club_rating_event_log', 'event_date_lookup', 'preferred', 'preferred_new', 'Schedules_Scores_167', 'Schedules_Scores_168', 'Schedules_Scores_169' ) as $t ) {
        $r = $wpdb->get_row( "CHECKSUM TABLE `{$t}`", ARRAY_A );
        $fp[ $t ] = $r ? $r['Checksum'] : 'missing';
    }
    $fp['usermeta_rank'] = $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', SUM(CRC32(CONCAT(user_id, meta_key, IFNULL(meta_value,''))))) FROM {$wpdb->usermeta} WHERE meta_key IN ('Rank','ClubRating','spp_glicko_rating','spp_glicko_rating_games')" );
    $fp['tables'] = md5( implode( ',', $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name NOT LIKE 'zznc\\_t\\_%' ORDER BY table_name" ) ) );
    $fp['real_option'] = serialize( get_option( 'spp_nc_groups', '__absent__' ) );
    return $fp;
}

// Drop every per-run table (keeps the base copies unless $all).
function nc_drop_run_tables( $all = false ) {
    global $wpdb;
    $keep = $all ? array() : array( 'zznc_t_base_169', 'zznc_t_base_167' );
    foreach ( $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zznc\\_t\\_%'" ) as $t ) {
        if ( in_array( $t, $keep, true ) ) continue;
        $wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
    }
}

// Fresh zznc_t_Schedules from a base copy, then apply $sql edits.
function nc_schedules_from( $base, array $edits = array() ) {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS zznc_t_Schedules" );
    $wpdb->query( "CREATE TABLE zznc_t_Schedules LIKE Schedules" );          // keeps the generated Score column
    $cols = 'Sequence, registration_date, Rank, user_id, first_name, last_name, user_phone, user_email, event_id, Game1, Game2, Game3, Game4, Game5, group_id, Crt_ID, time_id, travel, RankPrime';
    $wpdb->query( "INSERT INTO zznc_t_Schedules ({$cols}) SELECT {$cols} FROM {$base}" );
    foreach ( $edits as $e ) $wpdb->query( $e );
}

// Run the pipeline on the zznc tables; returns [html, ok].
function nc_run( array $nc_groups = null ) {
    global $P;
    nc_drop_run_tables_keep_schedules();
    $T = array(
        'schedules' => 'zznc_t_Schedules', 'master' => 'Master', 'results' => 'zznc_t_Results',
        'event_date_lookup' => 'zznc_t_event_date_lookup', 'prefix' => $P, 'nc_groups' => $nc_groups,
    );
    ob_start();
    spp_create_results_run( false, $T );
    $html = ob_get_clean();
    return array( $html, strpos( $html, 'Results calculated successfully' ) !== false );
}
function nc_drop_run_tables_keep_schedules() {
    global $wpdb;
    foreach ( $wpdb->get_col( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zznc\\_t\\_%'" ) as $t ) {
        if ( in_array( $t, array( 'zznc_t_base_169', 'zznc_t_base_167', 'zznc_t_Schedules', 'zznc_t_Results_all', 'zznc_t_Results_all_nocol', 'zznc_t_event_date_lookup' ), true ) ) continue;
        $wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
    }
}
function nc_results_by_uid( $table = 'zznc_t_Results' ) {
    global $wpdb;
    $rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
    $by = array();
    foreach ( $rows as $r ) $by[ (int) $r['user_id'] ] = $r;
    return $by;
}

echo "NC group status tests\n";
nc_drop_run_tables( true );
$before = nc_real_fingerprint();
if ( $before['real_option'] !== serialize( '__absent__' ) ) { echo "  note: real spp_nc_groups option exists before the test: {$before['real_option']}\n"; }

// Base copies (read the live tables once).
// Event 169 from its permanent snapshot, with group 1's games 4 and 5 put
// back to NULL (not yet entered) -- the incomplete group every NC check
// below is built around.
$wpdb->query( "CREATE TABLE zznc_t_base_169 AS SELECT * FROM Schedules_Scores_169" );
$wpdb->query( "UPDATE zznc_t_base_169 SET event_id = {$EV}" );
$wpdb->query( "UPDATE zznc_t_base_169 SET Game4 = NULL, Game5 = NULL WHERE group_id = 1" );
for ( $g = 1; $g <= 5; $g++ ) {
    $bye_groups = array_map( 'intval', $wpdb->get_col( "SELECT group_id FROM zznc_t_base_169 WHERE group_id <> 99 GROUP BY group_id HAVING COUNT(*) = 5 AND SUM(Game{$g} = 0) = 1" ) );
    if ( $bye_groups ) $wpdb->query( "UPDATE zznc_t_base_169 SET Game{$g} = NULL WHERE Game{$g} = 0 AND group_id IN (" . implode( ',', $bye_groups ) . ")" );
}
$wpdb->query( "CREATE TABLE zznc_t_base_167 AS SELECT * FROM Schedules_Scores_167" );
$wpdb->query( "UPDATE zznc_t_base_167 SET event_id = {$EV167}" );
$wpdb->query( "CREATE TABLE zznc_t_event_date_lookup LIKE event_date_lookup" );
nc_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM zznc_t_base_169" ) === 30, 'base copy of event 169 Schedules has 30 rows' );
$entered = "(Game1 IS NOT NULL) + (Game2 IS NOT NULL) + (Game3 IS NOT NULL) + (Game4 IS NOT NULL) + (Game5 IS NOT NULL)";
$short = $wpdb->get_col( "SELECT DISTINCT b.group_id FROM zznc_t_base_169 b JOIN (SELECT group_id, COUNT(*) n FROM zznc_t_base_169 WHERE group_id <> 99 GROUP BY group_id) g USING (group_id) WHERE {$entered} < g.n - 1" );
$over  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM zznc_t_base_169 b JOIN (SELECT group_id, COUNT(*) n FROM zznc_t_base_169 WHERE group_id <> 99 GROUP BY group_id) g USING (group_id) WHERE {$entered} > g.n - 1" );
nc_ok( $short === array( '1' ) && $over === 0 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM zznc_t_base_169 WHERE group_id = 1 AND Game4 IS NULL AND Game5 IS NULL" ) === 5,
    'fixture: byes are NULL, every group has exactly group_size-1 games entered except group 1 (games 4 and 5 not entered)' );

$G1 = array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM zznc_t_base_169 WHERE group_id = 1" ) );

// ── 1. Option helpers ──────────────────────────────────────────────────────
echo "1. NC list helpers\n";
delete_option( $OPT );
nc_ok( spp_nc_groups_for_event( $EV ) === array(), 'no option -> no NC groups' );
spp_nc_set_group( $EV, 3, true ); spp_nc_set_group( $EV, 1, true ); spp_nc_set_group( $EV, 1, true );
nc_ok( spp_nc_groups_for_event( $EV ) === array( 1, 3 ), 'set 3 then 1 (twice) -> [1,3], no duplicates' );
nc_ok( spp_nc_groups_for_event( 168 ) === array(), 'list stored for another event is ignored' );
spp_nc_set_group( $EV, 3, false ); spp_nc_set_group( $EV, 1, false );
nc_ok( get_option( $OPT, '__absent__' ) === '__absent__', 'clearing the last group deletes the option' );
spp_nc_set_group( 168, 2, true );
spp_nc_set_group( $EV, 4, true );
nc_ok( spp_nc_groups_for_event( $EV ) === array( 4 ), 'marking for a new event drops the stale event\'s list' );
delete_option( $OPT );

// ── 2. Step 1 / 1b ─────────────────────────────────────────────────────────
echo "2. Create Results Step 1 / 1b\n";
nc_schedules_from( 'zznc_t_base_169' );
$sched_ck = $wpdb->get_row( "CHECKSUM TABLE zznc_t_Schedules", ARRAY_A )['Checksum'];
list( $h, $ok ) = nc_run( array() );
nc_ok( ! $ok && strpos( $h, '1 group(s) have incomplete scores (Group 1)' ) !== false, 'no NC: event 169 copy aborts at Step 1b naming Group 1 only' );
nc_ok( ! $wpdb->get_var( "SHOW TABLES LIKE 'zznc\\_t\\_Results\\_{$EV}'" ), 'aborted run created no Results_<event>' );
nc_ok( $wpdb->get_row( "CHECKSUM TABLE zznc_t_Schedules", ARRAY_A )['Checksum'] === $sched_ck, 'aborted run left the schedule copy untouched' );

nc_schedules_from( 'zznc_t_base_169', array( "UPDATE zznc_t_Schedules SET Game2 = NULL WHERE group_id = 3 AND user_id = (SELECT user_id FROM zznc_t_base_169 WHERE group_id = 3 AND Game2 IS NOT NULL ORDER BY user_id LIMIT 1)" ) );
list( $h, $ok ) = nc_run( array( 1 ) );
nc_ok( ! $ok && strpos( $h, '1 group(s) have incomplete scores (Group 3)' ) !== false, 'group 1 NC + a missing game in group 3: Step 1b still aborts, naming Group 3 only' );

nc_schedules_from( 'zznc_t_base_169', array( "UPDATE zznc_t_Schedules SET Game1=NULL, Game2=NULL, Game3=NULL, Game4=NULL, Game5=NULL WHERE group_id IN (5,6)" ) );
list( $h, $ok ) = nc_run( array( 1 ) );
nc_ok( ! $ok && strpos( $h, 'results not entered -- cannot calculate new Ranks' ) !== false, 'group 1 NC + groups 5,6 blank: Step 1 (20% zero-score) still aborts' );
list( $h, $ok ) = nc_run( array( 1, 5, 6 ) );
nc_ok( $ok, 'same data with groups 1,5,6 all NC: Steps 1 and 1b pass, run completes' );

// ── 3. Main run: event 169 copy, group 1 NC ────────────────────────────────
echo "3. Event 169 copy, group 1 NC\n";
nc_schedules_from( 'zznc_t_base_169' );
$orig_games = $wpdb->get_results( "SELECT user_id, Game1, Game2, Game3, Game4, Game5, group_id, Crt_ID, time_id FROM zznc_t_Schedules ORDER BY user_id", ARRAY_A );
list( $h, $ok ) = nc_run( array( 1 ) );
nc_ok( $ok, 'run completes with group 1 NC' );
nc_ok( strpos( $h, 'Not Complete (NC): Group 1' ) !== false, 'run output names the NC group' );
$R = nc_results_by_uid();
nc_ok( spp_nc_table_has_status( 'zznc_t_Results' ) && spp_nc_table_has_status( "zznc_t_Results_{$EV}" ), 'GroupStatus column on Results and Results_<event>' );
$nc_ok_rows = 0; $nc_no_penalty = 0;
foreach ( $G1 as $u ) {
    $r = $R[ $u ];
    if ( $r['GroupStatus'] === 'NC' && (float) $r['RankCalc'] === (float) $r['RankPrev'] && (float) $r['RankCalc_Shadow'] === (float) $r['RankPrev'] && (float) $r['RankOverride'] === (float) $r['RankPrev'] ) $nc_ok_rows++;
    if ( abs( (float) $r['RankCalc'] - (float) $r['RankPrev'] ) < 0.001 ) $nc_no_penalty++;
}
nc_ok( $nc_ok_rows === 5, 'all 5 group-1 players: GroupStatus NC, RankCalc = RankCalc_Shadow = RankOverride = RankPrev' );
nc_ok( $nc_no_penalty === 5, 'no +1.6/+2.6 (or any) delta on NC players' );
$non_nc_status = (int) $wpdb->get_var( "SELECT COUNT(*) FROM zznc_t_Results WHERE GroupStatus IS NOT NULL AND group_id <> 1" );
nc_ok( $non_nc_status === 0, 'no other row carries a GroupStatus' );
$moved = (int) $wpdb->get_var( "SELECT COUNT(*) FROM zznc_t_Results WHERE group_id = 1 AND Rank <> CAST(RankPrev AS UNSIGNED)" );
echo "     (info) NC players whose final ordinal Rank differs from RankPrev: {$moved} of 5 -- the sort still places them\n";
$after_games = $wpdb->get_results( "SELECT user_id, Game1, Game2, Game3, Game4, Game5, group_id, Crt_ID, time_id FROM zznc_t_Schedules ORDER BY user_id", ARRAY_A );
$same = true;
foreach ( $orig_games as $i => $o ) {
    $a = $after_games[ $i ];
    foreach ( array( 'group_id', 'Crt_ID', 'time_id' ) as $c ) if ( $o[ $c ] !== $a[ $c ] ) $same = false;
    for ( $g = 1; $g <= 5; $g++ ) { $ov = $o["Game$g"]; $av = $a["Game$g"]; if ( $ov !== null && $ov !== $av ) $same = false; if ( $ov === null && $av !== '0' ) $same = false; }
}
nc_ok( $same, 'entered scores, group_id, Crt_ID, time_id unchanged; only NULL games became 0 (Step 2, same as every group)' );
$scores_t = "zznc_t_Schedules_Scores_{$EV}";
$g1_scores = $wpdb->get_results( "SELECT user_id, Game1, Game2, Game3 FROM {$scores_t} WHERE group_id = 1 ORDER BY user_id", ARRAY_A );
$base_g1   = $wpdb->get_results( "SELECT user_id, IFNULL(Game1,0) Game1, IFNULL(Game2,0) Game2, IFNULL(Game3,0) Game3 FROM zznc_t_base_169 WHERE group_id = 1 ORDER BY user_id", ARRAY_A );
nc_ok( $g1_scores == $base_g1, 'group 1 entered games 1-3 reach Schedules_Scores_<event> unchanged' );

// Rating dry run on that Schedules_Scores copy.
$rows = $wpdb->get_results( "SELECT user_id, group_id, Rank, Game1, Game2, Game3, Game4, Game5 FROM {$scores_t}", ARRAY_A );
$b = spp_ppr_build_games( $rows );
$g1_games = 0;
foreach ( $b['games'] as $g ) { if ( array_intersect( array_merge( $g['team1'], $g['team2'] ), $G1 ) ) $g1_games++; }
nc_ok( $g1_games === 3, "rating input: group 1 contributes exactly its 3 entered games (got {$g1_games})" );
nc_ok( $b['unreconstructed'] === 0, 'no unreconstructed slots; group 1 games 4/5 counted as not played (' . $b['not_played'] . ' slot(s))' );
$cr_before = $wpdb->get_row( "CHECKSUM TABLE club_rating_state", ARRAY_A )['Checksum'];
$dry = spp_crt_process_event_ratings( $b['games'], $b['rank_by_user'], $EV, 'ladder', true );
$dry_uids = array_map( 'intval', array_column( $dry['dry_run_rows'] ?? array(), 'user_id' ) );
nc_ok( empty( $dry['aborted'] ) && count( array_intersect( $G1, $dry_uids ) ) === 5, 'rating dry run includes all 5 NC players (' . count( $dry_uids ) . ' players rated)' );
nc_ok( $wpdb->get_row( "CHECKSUM TABLE club_rating_state", ARRAY_A )['Checksum'] === $cr_before, 'dry run wrote nothing to club_rating_state' );
$R_nc = $R;

// ── 4. NC only changes the NC group ────────────────────────────────────────
echo "4. Same week with group 1's missing games filled, no NC\n";
nc_schedules_from( 'zznc_t_base_169', array(
    // games 4 and 5 for group 1 (byes so far: G1 Sol, G2 Ray, G3 Ed; now
    // G4 Fayaz, G5 Asim) -- everyone ends with exactly 4 games
    "UPDATE zznc_t_Schedules SET Game4 = 15 WHERE user_id IN (2513, 2922)",
    "UPDATE zznc_t_Schedules SET Game4 = 9  WHERE user_id IN (2692, 2718)",
    "UPDATE zznc_t_Schedules SET Game5 = 15 WHERE user_id IN (2692, 2922)",
    "UPDATE zznc_t_Schedules SET Game5 = 11 WHERE user_id IN (2874, 2718)",
) );
list( $h, $ok ) = nc_run( array() );
nc_ok( $ok, 'complete week without NC runs' );
$R_full = nc_results_by_uid();
$diff = 0;
foreach ( $R_full as $u => $r ) {
    if ( in_array( $u, $G1, true ) ) continue;
    if ( ! isset( $R_nc[ $u ] ) ) { $diff++; continue; }
    foreach ( array( 'RankPrev', 'RankCalc', 'RankCalc_Shadow', 'RankOverride' ) as $c ) if ( (string) $r[ $c ] !== (string) $R_nc[ $u ][ $c ] ) $diff++;
}
nc_ok( $diff === 0, 'every non-NC player\'s RankPrev/RankCalc/RankCalc_Shadow/RankOverride identical with and without the NC group' );
$g1_moved = 0;
foreach ( $G1 as $u ) if ( (string) $R_full[ $u ]['RankCalc'] !== (string) $R_full[ $u ]['RankPrev'] ) $g1_moved++;
nc_ok( $g1_moved === 5 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM zznc_t_Results WHERE GroupStatus IS NOT NULL" ) === 0, 'without NC, group 1 is ranked normally (all 5 calcs move) and no GroupStatus is set' );

// ── 5. Penalty paths ───────────────────────────────────────────────────────
echo "5. Penalty paths\n";
$np_nc  = 2718; // group 1, marked NP but still sitting in the (NC) group
$np_out = (int) $wpdb->get_var( "SELECT user_id FROM zznc_t_base_169 WHERE group_id = 6 ORDER BY Rank DESC LIMIT 1" );
nc_schedules_from( 'zznc_t_base_169', array(
    "UPDATE zznc_t_Schedules SET Game1 = -1, Game2 = 0, Game3 = 0, Game4 = 0, Game5 = 0 WHERE user_id = {$np_nc}",
    "UPDATE zznc_t_Schedules SET Game1 = -1, Game2 = 0, Game3 = 0, Game4 = 0, Game5 = 0, group_id = 99, Crt_ID = 99, time_id = 99 WHERE user_id = {$np_out}",
) );
list( $h, $ok ) = nc_run( array( 1 ) );
$R5 = nc_results_by_uid();
$m_rank = (float) $wpdb->get_var( "SELECT Rank FROM Master WHERE user_id = {$np_out}" );
nc_ok( $ok && $R5[ $np_nc ]['GroupStatus'] === 'NC' && (float) $R5[ $np_nc ]['RankCalc'] === (float) $R5[ $np_nc ]['RankPrev'], 'an NP row inside an NC group gets RankPrev, not +1.6' );
nc_ok( abs( (float) $R5[ $np_out ]['RankCalc'] - ( $m_rank + 1.6 ) ) < 0.001 && $R5[ $np_out ]['GroupStatus'] === null, 'a normal NP elsewhere still gets Master.Rank + 1.6' );
$absent = (int) $wpdb->get_var( "SELECT m.user_id FROM Master m LEFT JOIN zznc_t_Schedules s ON s.user_id = m.user_id WHERE s.user_id IS NULL AND m.Rank IS NOT NULL AND m.Rank <> 0 ORDER BY m.user_id LIMIT 1" );
nc_ok( $absent && abs( (float) $R5[ $absent ]['RankCalc'] - ( (float) $wpdb->get_var( "SELECT Rank FROM Master WHERE user_id = {$absent}" ) + 1.6 ) ) < 0.001, 'a member not scheduled at all still decays by +1.6' );

// ── 6. Refactor regression: event 167 scores, no NC ────────────────────────
echo "6. Regression: event 167 scores replayed (no NC) vs archived Results_all 167\n";
nc_schedules_from( 'zznc_t_base_167' );
list( $h, $ok ) = nc_run( array() );
$cmp = $wpdb->get_results( "SELECT r.user_id, r.RankCalc new_calc, a.RankCalc old_calc FROM zznc_t_Results r JOIN Results_all a ON a.user_id = r.user_id AND a.event_id = 167 JOIN zznc_t_Schedules s ON s.user_id = r.user_id WHERE s.Game1 >= 0 AND s.group_id <> 99", ARRAY_A );
$mis = 0; foreach ( $cmp as $c ) if ( abs( (float) $c['new_calc'] - (float) $c['old_calc'] ) > 0.005 ) $mis++;
nc_ok( $ok && count( $cmp ) > 0 && $mis === 0, 'plain RankCalc for every player who played matches the archived event-167 value (' . count( $cmp ) . " players, {$mis} mismatches)" );

// ── 7. Apply Override archive into Results_all ─────────────────────────────
echo "7. Apply Override: marker into Results_all, RankOverride editable\n";
nc_schedules_from( 'zznc_t_base_169' );
list( $h, $ok ) = nc_run( array( 1 ) );
$wpdb->query( "DROP TABLE IF EXISTS zznc_t_Results_all" );
$wpdb->query( "CREATE TABLE zznc_t_Results_all LIKE Results_all" );
if ( ! spp_nc_table_has_status( 'zznc_t_Results_all' ) ) {
    $wpdb->query( "ALTER TABLE zznc_t_Results_all ADD COLUMN GroupStatus VARCHAR(4) NULL DEFAULT NULL" ); // the hand-run ALTER
}
$wpdb->query( "DROP TABLE IF EXISTS zznc_t_Results_all_nocol" );
$wpdb->query( "CREATE TABLE zznc_t_Results_all_nocol LIKE Results_all" );
if ( spp_nc_table_has_status( 'zznc_t_Results_all_nocol' ) ) {
    $wpdb->query( "ALTER TABLE zznc_t_Results_all_nocol DROP COLUMN GroupStatus" ); // pre-ALTER shape
}
nc_ok( spp_nc_table_has_status( 'zznc_t_Results_all' ) && ! spp_nc_table_has_status( 'zznc_t_Results_all_nocol' ), 'fixtures: one Results_all copy with GroupStatus, one without' );
nc_ok( spp_ao_nc_block_reason( 'zznc_t_Results', 'zznc_t_Results_all_nocol' ) !== '', 'Stage 1 refuses when Results has NC rows but Results_all has no GroupStatus column' );
nc_ok( spp_ao_nc_block_reason( 'zznc_t_Results', 'zznc_t_Results_all' ) === '', '...and allows it once the column exists' );
nc_ok( spp_ao_nc_block_reason() === '', 'real Results/Results_all as they are now: no block -- Apply Override works as before' );
$hand = $G1[0];
$wpdb->query( "UPDATE zznc_t_Results SET RankOverride = 1.50 WHERE user_id = {$hand}" ); // a hand-edited override on an NC player
ob_start(); $a = spp_ao_archive_results( $EV, array( 'results' => 'zznc_t_Results', 'results_all' => 'zznc_t_Results_all', 'tmp' => 'zznc_t_tmp_results' ) ); ob_end_clean();
nc_ok( $a['expected'] === $a['actual'] && $a['actual'] > 0 && $a['group_status'], "archive wrote {$a['actual']} rows with GroupStatus" );
$arch = nc_results_by_uid( 'zznc_t_Results_all' );
$survive = 0; foreach ( $G1 as $u ) if ( $arch[ $u ]['GroupStatus'] === 'NC' ) $survive++;
nc_ok( $survive === 5 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM zznc_t_Results_all WHERE GroupStatus = 'NC'" ) === 5, 'NC marker survives into Results_all for exactly the 5 group-1 players' );
$expect_rank = 1 + (int) $wpdb->get_var( "SELECT COUNT(*) FROM zznc_t_Results WHERE CAST(RankOverride AS DECIMAL(6,2)) < 1.50" );
nc_ok( (float) $arch[ $hand ]['RankOverride'] === 1.5 && (int) $arch[ $hand ]['Rank'] === $expect_rank, "hand-edited RankOverride on an NC player is kept and drives the sort (Rank {$expect_rank})" );
$untouched = 0; foreach ( array_slice( $G1, 1 ) as $u ) if ( (float) $arch[ $u ]['RankOverride'] === (float) $arch[ $u ]['RankPrev'] ) $untouched++;
nc_ok( $untouched === 4, 'the other NC players archive with RankOverride = RankPrev' );
ob_start(); $a2 = spp_ao_archive_results( $EV, array( 'results' => 'zznc_t_Results', 'results_all' => 'zznc_t_Results_all_nocol', 'tmp' => 'zznc_t_tmp_results' ) ); ob_end_clean();
nc_ok( $a2['expected'] === $a2['actual'] && ! $a2['group_status'], 'archive into a Results_all without the column still works (pre-ALTER shape)' );
$wpdb->query( "CREATE TABLE zznc_t_Results_rebuilt AS (SELECT * FROM zznc_t_tmp_results WHERE user_id IN (SELECT user_id FROM membership))" );
$wpdb->query( "CREATE TABLE zznc_t_Results_bak LIKE zznc_t_Results" );
$wpdb->query( "INSERT INTO zznc_t_Results_bak SELECT * FROM zznc_t_Results" );
nc_ok( spp_nc_table_has_status( 'zznc_t_Results_rebuilt' ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM zznc_t_Results_bak" ) === (int) $wpdb->get_var( "SELECT COUNT(*) FROM zznc_t_Results" ), 'Apply Override rebuild (SELECT *) and backups (LIKE + SELECT *, also gl-schedule-production\'s) carry the column' );

// ── 8. Readers, on session-only TEMPORARY shadows holding the test rows ────
echo "8. Readers with the new column (TEMPORARY shadows of Results_all / Results / event_date_lookup)\n";
$GLOBALS['nc_temp_ok'] = array( 'Results_all', 'Results', 'event_date_lookup', "Schedules_Scores_{$EV}" );
$wpdb->query( "CREATE TEMPORARY TABLE Results_all AS SELECT * FROM zznc_t_Results_all" );
$wpdb->query( "CREATE TEMPORARY TABLE Results AS SELECT * FROM zznc_t_Results" );
$wpdb->query( "CREATE TEMPORARY TABLE event_date_lookup AS SELECT * FROM event_date_lookup" );
$wpdb->query( "CREATE TEMPORARY TABLE Schedules_Scores_{$EV} AS SELECT * FROM {$scores_t}" );
$all_temp = true;
foreach ( array( 'Results_all', 'Results', 'event_date_lookup', "Schedules_Scores_{$EV}" ) as $t ) {
    $sc = $wpdb->get_row( "SHOW CREATE TABLE `{$t}`", ARRAY_N );
    if ( ! $sc || stripos( $sc[1], 'CREATE TEMPORARY TABLE' ) !== 0 ) $all_temp = false;
}
nc_ok( $all_temp, 'every shadow is a TEMPORARY table' );
if ( ! $all_temp ) { $GLOBALS['nc_temp_ok'] = array(); echo "ABORT: a shadow is not temporary\n"; nc_drop_run_tables( true ); exit( 1 ); }
$wpdb->query( "INSERT INTO event_date_lookup (event_id, event_date) VALUES ({$EV}, '2026-10-12 17:00:00')" );
$shadowed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM Results_all WHERE event_id = {$EV}" ) > 0
    && (int) $wpdb->get_var( "SELECT COUNT(*) FROM Results_all WHERE event_id = 167" ) === 0
    && (int) $wpdb->get_var( "SELECT COUNT(*) FROM Results WHERE event_id = {$EV}" ) > 0;
nc_ok( $shadowed, 'temporary shadows are live (real Results_all/Results hidden in this connection)' );
if ( $shadowed ) {
    wp_set_current_user( 1 );
    $GLOBALS['Event'] = $EV;
    ob_start(); spp_show_results(); $sr = ob_get_clean();
    nc_ok( strpos( $sr, '<th>Status</th>' ) !== false && substr_count( $sr, '<td>NC</td>' ) === 5, 'show-results: Status column, 5 NC cells' );
    $_POST['rank_history_user'] = $G1[1];
    ob_start(); spp_rank_history(); $rh = ob_get_clean();
    unset( $_POST['rank_history_user'] );
    nc_ok( strpos( $rh, 'the session was not completed (NC)' ) !== false && strpos( $rh, '<td>1 NC</td>' ) !== false, 'rank history: NC narrative and "1 NC" group cell' );
    nc_ok( strpos( $rh, 'NC (not complete)' ) !== false, 'rank history: NC row in the "how are rankings calculated" table' );
    $def = spp_report_results();
    $nc_rows = 0; foreach ( $def['rows'] as $r ) if ( ( $r['GroupStatus'] ?? null ) === 'NC' ) $nc_rows++;
    $override_col = array_values( array_filter( $def['columns'], function ( $c ) { return $c['key'] === 'RankOverride'; } ) )[0];
    nc_ok( $nc_rows === 5 && ! empty( $override_col['editable'] ), 'results report: 5 NC rows in Status, RankOverride still editable' );
    $rr = spp_report_recent_results();
    nc_ok( count( $rr['rows'] ) > 0, 'recent_results report still reads Results' );
    nc_ok( spp_nc_group_is_nc_in_results( $EV, 1 ) && ! spp_nc_group_is_nc_in_results( $EV, 2 ), 'NC lookup from Results_all: group 1 yes, group 2 no' );
    $sc_nc  = spp_sc_recalculate( $EV, $G1[0], 1, 11 );
    $sc_nc_null = count( array_filter( $sc_nc['new_calcs'], function ( $v ) { return $v !== null; } ) ) === 0;
    nc_ok( ! empty( $sc_nc['is_nc'] ) && $sc_nc_null && $sc_nc['new_scores'][ $G1[0] ] !== $sc_nc['old_scores'][ $G1[0] ], 'score correction on an NC group: flagged NC, nobody re-ranked, score total still recomputed' );
    $g2u = (int) $wpdb->get_var( "SELECT user_id FROM Schedules_Scores_{$EV} WHERE group_id = 2 LIMIT 1" );
    $sc_2 = spp_sc_recalculate( $EV, $g2u, 1, 3 );
    nc_ok( empty( $sc_2['is_nc'] ) && $sc_2['new_calcs'][ $g2u ] !== null, 'score correction on a normal group still re-ranks' );
    // Simple reads other consumers make of Results / Results_all
    nc_ok( (int) $wpdb->get_var( "SELECT event_id FROM Results LIMIT 1" ) === $EV, 'Results.event_id read (club ratings, partial publish, score correction) unaffected' );
    nc_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM Results WHERE (Rank <> 0 OR Rank IS NOT NULL)" ) > 0, 'copy-ranks count query unaffected' );
}
foreach ( array( 'Results_all', 'Results', 'event_date_lookup', "Schedules_Scores_{$EV}" ) as $t ) $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS `{$t}`" );
$GLOBALS['nc_temp_ok'] = array();
nc_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM Results_all WHERE event_id = {$EV}" ) === 0, 'temporary shadows gone; real Results_all has no test rows' );

// ── 9. Readers on a Results_all without the column (pre-ALTER shape) ───────
// The real Results_all now has GroupStatus, so the pre-ALTER shape is a
// session-only TEMPORARY shadow: every real column except GroupStatus, real
// rows (read in the same statement, before the shadow exists).
echo "9. Readers on a Results_all without the GroupStatus column (TEMPORARY shadow)\n";
$nocol_cols = implode( ', ', array_map( function ( $c ) { return "`{$c}`"; }, array_diff( $wpdb->get_col( "SHOW COLUMNS FROM Results_all" ), array( 'GroupStatus' ) ) ) );
$GLOBALS['nc_temp_ok'] = array( 'Results_all' );
$wpdb->query( "CREATE TEMPORARY TABLE Results_all AS SELECT {$nocol_cols} FROM Results_all" );
$sc = $wpdb->get_row( "SHOW CREATE TABLE `Results_all`", ARRAY_N );
$nocol_live = $sc && stripos( $sc[1], 'CREATE TEMPORARY TABLE' ) === 0 && ! spp_nc_table_has_status( 'Results_all' )
    && (int) $wpdb->get_var( "SELECT COUNT(*) FROM Results_all WHERE event_id = 167" ) > 0;
nc_ok( $nocol_live, 'temporary Results_all without GroupStatus is live, with the real event-167 rows' );
if ( $nocol_live ) {
    wp_set_current_user( 1 );
    $GLOBALS['Event'] = 167;
    ob_start(); spp_show_results(); $sr = ob_get_clean();
    nc_ok( strpos( $sr, 'Calculated' ) !== false && strpos( $sr, 'Status' ) === false, 'show-results renders event 167 without a Status column' );
    $_POST['rank_history_user'] = $G1[1];
    ob_start(); spp_rank_history(); $rh = ob_get_clean(); unset( $_POST['rank_history_user'] );
    nc_ok( strpos( $rh, 'rh-table' ) !== false && strpos( $rh, 'but the session was not completed (NC)' ) === false && $wpdb->last_error === '', 'rank history renders, no NC text, no SQL error' );
}
$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS `Results_all`" );
$GLOBALS['nc_temp_ok'] = array();
nc_ok( spp_nc_table_has_status( 'Results_all' ), 'temporary shadow gone; real Results_all (with GroupStatus) visible again' );
$def = spp_report_results();
nc_ok( count( $def['rows'] ) > 0 && array_key_exists( 'GroupStatus', $def['rows'][0] ) && $def['rows'][0]['GroupStatus'] === null, 'results report reads real Results (Status blank)' );

// ── 10. Schedule Admin: set / clear, roles ─────────────────────────────────
echo "10. Schedule Admin NC control\n";
delete_option( $OPT );
$real_ev = (int) spp_cr_current_event()['event'];
$sched_real = $wpdb->get_row( "CHECKSUM TABLE Schedules", ARRAY_A )['Checksum'];
$test_group = 3; // NOT group 2
function nc_admin_post( $user, $action, $group ) {
    wp_set_current_user( $user );
    $_POST = array( 'spp_sa_nonce' => wp_create_nonce( 'spp_schedule_admin' ), 'spp_sa_action' => $action, 'spp_group_id' => (string) $group );
    $html = do_shortcode( '[spp_schedule_admin]' );
    $_POST = array();
    return $html;
}
$h = nc_admin_post( 2484, 'nc_set', $test_group );
nc_ok( strpos( $h, 'You do not have permission' ) !== false && get_option( $OPT, '__absent__' ) === '__absent__', 'subscriber (2484' . ( user_can( 2484, 'edit_posts' ) ? ', has edit_posts' : '' ) . '): refused, nothing stored' );
$h = nc_admin_post( 2880, 'nc_set', $test_group );
nc_ok( strpos( $h, 'You do not have permission' ) !== false && get_option( $OPT, '__absent__' ) === '__absent__', 'ladder-cop (2880): refused, nothing stored' );
$h = nc_admin_post( 2193, 'nc_set', $test_group );
nc_ok( spp_nc_groups_for_event( $real_ev ) === array( $test_group ) && strpos( $h, "Group {$test_group} marked NC" ) !== false, "editor (2193): marks group {$test_group} NC for event {$real_ev}" );
nc_ok( strpos( $h, 'sa-nc-badge' ) !== false && strpos( $h, 'Clear NC' ) !== false, 'page shows the NC badge and a Clear NC button' );
nc_ok( $wpdb->get_row( "CHECKSUM TABLE Schedules", ARRAY_A )['Checksum'] === $sched_real, 'Schedules untouched by marking (scores, group_id, Crt_ID, time_id)' );
$h = nc_admin_post( 1, 'nc_clear', $test_group );
nc_ok( get_option( $OPT, '__absent__' ) === '__absent__' && strpos( $h, "NC cleared for Group {$test_group}" ) !== false, 'administrator: clears it again' );
$h = nc_admin_post( 1, 'nc_set', 99 );
nc_ok( get_option( $OPT, '__absent__' ) === '__absent__' && strpos( $h, 'Group not found' ) !== false, 'group 99 / unknown group refused' );
wp_set_current_user( 1 );
$_POST = array( 'spp_sa_nonce' => 'bad', 'spp_sa_action' => 'nc_set', 'spp_group_id' => (string) $test_group );
do_shortcode( '[spp_schedule_admin]' ); $_POST = array();
nc_ok( get_option( $OPT, '__absent__' ) === '__absent__', 'bad nonce: nothing stored' );
nc_ok( $wpdb->get_row( "CHECKSUM TABLE Schedules", ARRAY_A )['Checksum'] === $sched_real, 'Schedules still untouched after set/clear' );
nc_ok( empty( $GLOBALS['nc_mail'] ), 'no email sent by any NC action' );
foreach ( array( 1 => true, 2193 => true, 2484 => false, 2880 => false ) as $u => $want ) {
    wp_set_current_user( $u );
    nc_ok( spp_is_admin_or_editor() === $want, "Create Results / Apply Override / score correction gate for user {$u}: " . ( $want ? 'allowed' : 'refused' ) );
}

// ── Cleanup + leak check ───────────────────────────────────────────────────
echo "Cleanup\n";
delete_option( $OPT );
nc_drop_run_tables( true );
nc_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'zznc\\_t\\_%'" ) === 0, 'all zznc_t_* tables dropped' );
$after = nc_real_fingerprint();
foreach ( $before as $k => $v ) nc_ok( $after[ $k ] === $v, "real {$k} unchanged" );
nc_ok( empty( $GLOBALS['nc_blocked'] ), 'firewall blocked no writes' . ( $GLOBALS['nc_blocked'] ? ': ' . implode( ' | ', $GLOBALS['nc_blocked'] ) : '' ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
