<?php
/* =========================================================
   Ace/Queen of the Courts — Screens
   Version: 1.0.0
   Date: 2026-09-11

   PURPOSE:
   The [spp_kq_live] shortcode and its seven screens, plus the one
   AJAX action (the card draw). Everything here is UI/dispatch on top
   of inc/spp-kq-live.php's gate + transition mechanics and
   inc/spp-kq-movement.php's pure algorithm -- no game logic lives in
   this file.

   Stage 2 (final part) of a staged build (see conversation). Screen
   selection is driven entirely by spp_kq_events.phase/current_round
   plus, for 'organizing' specifically, whether round 1's draw is
   still incomplete:

     not_started                              -> Start screen
     organizing, round 1, unclaimed slots > 0  -> Draw screen
     organizing, otherwise                     -> Overview screen
     in_play                                   -> In-Play placeholder
     complete                                  -> Complete screen
     cancelled                                 -> Cancelled screen

   ACCESS: spp_kq_can_facilitate() (inc/spp-kq-live.php) is the only
   gate, checked once at the top of the shortcode and again in the
   AJAX handler -- nowhere else in this file re-derives or narrows it.

   ACTIONS: Start Round 1 Draw / Start Play / End Event / Cancel Event
   are plain nonce-protected POST forms with no redirect afterward
   (same convention as spp-schedule-adjust.php, registration-admin.php,
   spp-remove-inactive-ladder-users.php elsewhere in this codebase) --
   the page just re-renders whatever screen the new state calls for.
   The card draw is the one AJAX action, matching spp-score-entry.php's
   convention for many-small-taps-with-live-feedback interactions.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

// =============================================================
// Small read helpers specific to rendering (mechanics live in
// spp-kq-live.php; these just shape data for display).
// =============================================================

function spp_kq_get_occurrence_summary( int $occurrence_id ) : ?array {
    global $wpdb;
    $view = $wpdb->prefix . 'gl_events_v';
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT occurrence_id, eff_title, event_date, eff_event_time, category_name
         FROM {$view} WHERE occurrence_id = %d",
        $occurrence_id
    ), ARRAY_A );
    return $row ?: null;
}

function spp_kq_count_unclaimed( int $occurrence_id, int $round_number ) : int {
    global $wpdb;
    $table = spp_kq_assignments_table();
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE occurrence_id = %d AND round_number = %d AND user_id IS NULL",
        $occurrence_id, $round_number
    ) );
}

function spp_kq_player_name( ?string $first, ?string $last, int $user_id ) : string {
    $name = trim( ( $first ?? '' ) . ' ' . ( $last ?? '' ) );
    return $name !== '' ? $name : "Member #{$user_id}";
}

/**
 * Confirmed registrants for this occurrence not yet drawn in round 1,
 * named via membership (this codebase's established convention --
 * see gl-registrant-list.php / registration-admin.php -- not WP
 * display_name).
 */
function spp_kq_get_not_yet_drawn( int $occurrence_id ) : array {
    global $wpdb;

    $confirmed_ids = spp_kq_confirmed_user_ids( $occurrence_id );
    if ( empty( $confirmed_ids ) ) {
        return array();
    }

    $table = spp_kq_assignments_table();
    $drawn_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id FROM {$table} WHERE occurrence_id = %d AND round_number = 1 AND user_id IS NOT NULL",
        $occurrence_id
    ) ) );

    $remaining_ids = array_values( array_diff( $confirmed_ids, $drawn_ids ) );
    if ( empty( $remaining_ids ) ) {
        return array();
    }

    $placeholders = implode( ',', array_fill( 0, count( $remaining_ids ), '%d' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, first_name, last_name FROM membership WHERE user_id IN ({$placeholders})",
        $remaining_ids
    ), ARRAY_A );

    $by_id = array();
    foreach ( $rows as $r ) {
        $by_id[ (int) $r['user_id'] ] = spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] );
    }

    $out = array();
    foreach ( $remaining_ids as $uid ) {
        $out[] = array( 'user_id' => $uid, 'name' => $by_id[ $uid ] ?? "Member #{$uid}" );
    }
    return $out;
}

/**
 * Round 1's reveal state for the draw screen: every (court, color)
 * slot, always exactly 2 entries per color, each either a player name
 * (already drawn) or null (still face-down). Used both for the
 * initial server render and to let a mid-draw page reload show
 * exactly where things stand.
 */
function spp_kq_get_draw_reveal_state( int $occurrence_id, array $courts_order ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.court_name, a.team_color, a.user_id, m.first_name, m.last_name
         FROM {$table} a
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE a.occurrence_id = %d AND a.round_number = 1
         ORDER BY a.court_name, a.team_color, a.id",
        $occurrence_id
    ), ARRAY_A );

    $out = array();
    foreach ( $courts_order as $court ) {
        $out[ $court ] = array( 'red' => array( null, null ), 'black' => array( null, null ) );
    }
    $cursor = array_fill_keys( $courts_order, array( 'red' => 0, 'black' => 0 ) );

    foreach ( $rows as $r ) {
        $court = $r['court_name']; $color = $r['team_color'];
        if ( ! isset( $cursor[ $court ][ $color ] ) || $cursor[ $court ][ $color ] > 1 ) {
            continue; // defensive -- should never happen with exactly 2 slots/color
        }
        $idx = $cursor[ $court ][ $color ]++;
        $name = $r['user_id'] !== null ? spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] ) : null;
        $out[ $court ][ $color ][ $idx ] = $name;
    }
    return $out;
}

/**
 * One round's full court/team roster (names only, all slots assumed
 * filled -- used by the Overview screen, which only ever shows once
 * round 1's draw is complete, or a round > 1 whose assignments were
 * all written atomically by the movement algorithm).
 */
function spp_kq_get_round_court_view( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();

    $courts_order = spp_kq_determine_courts_order( $occurrence_id );
    $out = array();
    foreach ( $courts_order as $court ) {
        $out[ $court ] = array( 'red' => array(), 'black' => array() );
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.court_name, a.team_color, m.first_name, m.last_name
         FROM {$table} a
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE a.occurrence_id = %d AND a.round_number = %d AND a.user_id IS NOT NULL
         ORDER BY a.court_name, a.team_color",
        $occurrence_id, $round_number
    ), ARRAY_A );

    foreach ( $rows as $r ) {
        if ( ! isset( $out[ $r['court_name'] ] ) ) continue;
        $out[ $r['court_name'] ][ $r['team_color'] ][] = spp_kq_player_name( $r['first_name'], $r['last_name'], 0 );
    }
    return $out;
}

/**
 * Final-round Aces winner, for the Complete screen. Returns null if
 * Aces never reported a score this round (End Event can fire from
 * 'organizing' before any play happens at all -- see this feature's
 * own transition docs) rather than guessing.
 */
function spp_kq_get_final_winner_names( int $occurrence_id, int $round_number ) : ?string {
    global $wpdb;
    $scores_table = spp_kq_scores_table();
    $assignments_table = spp_kq_assignments_table();

    $score = $wpdb->get_row( $wpdb->prepare(
        "SELECT red_score, black_score FROM {$scores_table}
         WHERE occurrence_id = %d AND round_number = %d AND court_name = 'Aces'",
        $occurrence_id, $round_number
    ), ARRAY_A );

    if ( ! $score || $score['red_score'] === null || $score['black_score'] === null ) {
        return null;
    }

    $winning_color = ( (int) $score['red_score'] > (int) $score['black_score'] ) ? 'red' : 'black';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT m.first_name, m.last_name FROM {$assignments_table} a
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE a.occurrence_id = %d AND a.round_number = %d AND a.court_name = 'Aces' AND a.team_color = %s",
        $occurrence_id, $round_number, $winning_color
    ), ARRAY_A );

    if ( empty( $rows ) ) return null;

    $names = array_map( fn( $r ) => spp_kq_player_name( $r['first_name'], $r['last_name'], 0 ), $rows );
    return implode( ' & ', $names );
}

/**
 * Cancelled-event summary for the Cancelled screen: which courts kept
 * a real result (with the actual score, so a facilitator can confirm
 * "yes, that's the score that counted") vs. which were discarded.
 * Reconstructed purely from what's left after cancellation -- courts
 * still present in spp_kq_scores for that round were kept (a row only
 * survives Cancel Event if it was already fully reported); the rest
 * of the event's fixed court set (spp_kq_determine_courts_order(),
 * which still resolves correctly even when round 1 itself was wiped
 * -- see that function's own fallback) were discarded.
 */
function spp_kq_get_cancellation_summary( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $scores_table = spp_kq_scores_table();

    $courts_order = spp_kq_determine_courts_order( $occurrence_id );

    $kept_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT court_name, red_score, black_score FROM {$scores_table}
         WHERE occurrence_id = %d AND round_number = %d",
        $occurrence_id, $round_number
    ), ARRAY_A );

    $kept = array();
    foreach ( $kept_rows as $r ) {
        $kept[ $r['court_name'] ] = array( 'red' => (int) $r['red_score'], 'black' => (int) $r['black_score'] );
    }

    $discarded = array_values( array_diff( $courts_order, array_keys( $kept ) ) );

    return array( 'kept' => $kept, 'discarded' => $discarded );
}

// =============================================================
// Shared CSS (embedded once per render -- same convention as
// spp-score-entry.php)
// =============================================================

function spp_kq_styles() : string {
    return '<style>
        .kq-wrap { max-width:560px; margin:10px auto; font-family:Arial,sans-serif; font-size:15px; line-height:1.4; color:#222; }
        .kq-back a { color:#3766AB; text-decoration:none; font-size:14px; }
        .kq-heading { margin:6px 0 2px; font-size:20px; }
        .kq-subheading { margin:0 0 14px; color:#666; font-size:14px; }
        .kq-hint { margin:0 0 14px; color:#666; font-size:14px; font-style:italic; }
        .kq-caveat { margin:0 0 14px; padding:8px 12px; background:#f7f7f7; border-left:3px solid #999; color:#555; font-size:13px; }
        .kq-meta { color:#555; margin-bottom:14px; }
        .kq-round-label { font-weight:bold; font-size:16px; margin:0 0 12px; color:#2c3e50; }
        .kq-round-label-tight { font-weight:bold; font-size:16px; margin:0 0 2px; color:#2c3e50; }
        .kq-notice { padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:14px; }
        .kq-notice-err { background:#f8d7da; border:1px solid #dc3545; color:#721c24; }
        .kq-notice-ok { background:#d4edda; border:1px solid #28a745; color:#155724; }
        .kq-status { background:#f0f7ff; border:1px solid #3766AB; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:14px; color:#2c3e50; }
        .kq-score-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:14px; }
        .kq-score-row label { font-size:13px; color:#555; }
        .kq-score-input { display:block; width:70px; padding:8px; font-size:18px; text-align:center; border:1px solid #bbb; border-radius:6px; margin-top:4px; }
        .kq-saved { font-size:13px; color:#27ae60; font-weight:bold; }
        .kq-warn { background:#fff8e1; border:1px solid #e67e22; border-radius:6px; padding:12px 14px; color:#7a4a00; }
        .kq-warn a { color:#3766AB; }
        .kq-btn { padding:10px 20px; border:none; border-radius:6px; font-size:15px; cursor:pointer; }
        .kq-btn-primary { background:#3766AB; color:#fff; }
        .kq-btn-secondary { background:#888; color:#fff; }
        .kq-btn-danger { background:#c0392b; color:#fff; }
        .kq-action-row { display:flex; gap:10px; margin-top:16px; }
        .kq-action-row-right { justify-content:flex-end; }
        .kq-inline-form { display:inline-block; }
        .kq-picker-list { display:flex; flex-direction:column; gap:8px; }
        .kq-picker-row { display:flex; align-items:center; gap:4px; padding:10px 10px; border:1px solid #ddd; border-radius:8px; text-decoration:none; color:#222; flex-wrap:wrap; }
        .kq-picker-row:hover { background:#f5f8fc; }
        /* flex-shrink:0 (via 1 0 auto) matters here: title is the only
           item whose text can wrap across multiple words, so a plain
           flex-shrink:1 dumped nearly the entire deficit onto it alone --
           date/regcount/status are white-space:nowrap, so their own
           automatic minimum is already their full natural width, never
           really shrinking. Letting the whole ROW wrap onto a second
           line under real pressure (flex-wrap:wrap on the row, still
           active) degrades far more gracefully than wrapping mid-title.
           Every other measurement on this row (padding, gap, badge/status
           padding) is trimmed as tight as still reads comfortably, purely
           to buy title the room it needs within the fixed 560px .kq-wrap
           max-width -- the longest real titles ("Queen of the Courts...")
           were still a couple dozen px over budget even with 1 0 auto alone. */
        .kq-picker-title { font-weight:bold; flex:1 0 auto; }
        .kq-picker-date { color:#666; font-size:13px; white-space:nowrap; }
        .kq-picker-regcount { color:#666; font-size:13px; white-space:nowrap; }
        .kq-picker-status { font-size:12px; font-weight:bold; padding:2px 6px; border-radius:12px; white-space:nowrap; }
        .kq-picker-row--test { background:#faf7ff; border-color:#d8cdf0; }
        .kq-picker-row--test:hover { background:#f3edfc; }
        .kq-picker-badge { font-size:10px; font-weight:bold; padding:1px 5px; border-radius:9px; white-space:nowrap; letter-spacing:.02em; }
        .kq-picker-badge--test { background:#e8def8; color:#6b3fa0; }
        .kq-picker-section-heading { font-size:15px; margin:22px 0 4px; color:#555; }
        .kq-status-not-started { background:#eee; color:#666; }
        .kq-status-organizing { background:#fff3cd; color:#8a6100; }
        .kq-status-in_play { background:#d4edda; color:#155724; }
        .kq-status-complete { background:#dde7f3; color:#2c3e50; }
        .kq-status-cancelled { background:#f8d7da; color:#721c24; }
        .kq-court-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin-bottom:10px; }
        .kq-court-card { border:1px solid #ddd; border-radius:8px; padding:12px 14px; background:#fff; }
        .kq-court-name { font-weight:bold; font-size:16px; margin-bottom:6px; color:#2c3e50; }
        .kq-team { font-size:14px; margin-bottom:2px; }
        .kq-team-red { color:#c0392b; }
        .kq-team-black { color:#222; }
        .kq-draw-progress { color:#555; font-size:14px; margin:0 0 16px; }
        .kq-draw-columns { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
        .kq-draw-col { flex:1 1 220px; }
        .kq-draw-col h3 { font-size:14px; margin:0 0 8px; color:#555 !important; text-transform:uppercase; letter-spacing:.5px; }
        #kq-not-drawn-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; }
        .kq-player-btn { width:100%; padding:10px; font-size:15px; border:2px solid #3766AB; background:#fff; color:#3766AB; border-radius:6px; cursor:pointer; text-align:left; }
        .kq-player-btn.kq-selected { background:#3766AB; color:#fff; }
        #kq-card-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(56px,1fr)); gap:8px; }
        .kq-card { aspect-ratio:2/3; background:#2c3e50; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:24px; color:#fff; cursor:pointer; user-select:none; }
        .kq-card:hover { background:#3f5a80; }
        .kq-draw-revealed h3 { font-size:14px; margin:0 0 8px; color:#555 !important; text-transform:uppercase; letter-spacing:.5px; }
        .kq-slot { color:#999; }
        .kq-slot.kq-slot-filled { color:inherit; font-weight:bold; }
        .kq-cancel-summary { display:flex; gap:20px; flex-wrap:wrap; }
        /* !important: the theme own content-area h3 color rule outranks any
           class-based selector here (confirmed by testing -- even a doubled
           .kq-wrap prefix did not win), and the whole point of these two is the
           green/red semantic distinction, so it cannot just inherit the theme
           default the way every other heading in this feature harmlessly does. */
        .kq-kept h3 { color:#155724 !important; font-size:14px; margin:0 0 6px; }
        .kq-discarded h3 { color:#721c24 !important; font-size:14px; margin:0 0 6px; }
        .kq-cancel-summary ul { margin:0; padding-left:18px; font-size:14px; }
        .kq-full-reset-row { margin-top:28px; padding-top:14px; border-top:1px dashed #ccc; text-align:right; }
        .kq-full-reset-row .kq-btn { font-size:13px; padding:6px 14px; opacity:.85; }
    </style>';
}

function spp_kq_status_label( ?string $phase, int $round ) : array {
    if ( ! $phase || $phase === 'not_started' ) {
        return array( 'label' => 'Not started', 'class' => 'not-started' );
    }
    $map = array(
        'organizing' => "Round {$round} \u{00b7} Organizing",
        'in_play'    => "Round {$round} \u{00b7} In Play",
        'complete'   => 'Complete',
        'cancelled'  => 'Cancelled',
    );
    return array( 'label' => $map[ $phase ] ?? $phase, 'class' => $phase );
}

// =============================================================
// Screen 1: Event picker
// =============================================================

/**
 * One picker row -- shared by both the upcoming list and the practice
 * sandbox list below, so the two can never drift apart in what they
 * show. $is_test adds the PRACTICE badge and a distinct row class;
 * everything else (title, date, registrant count, phase status) is
 * identical either way.
 */
function spp_kq_render_picker_row( array $r, bool $is_test = false ) : string {
    $status = spp_kq_status_label( $r['phase'] ?? null, (int) ( $r['current_round'] ?? 0 ) );
    $date_str = date_i18n( 'M j', strtotime( $r['event_date'] ) );
    $time_str = $r['eff_event_time'] ? date_i18n( 'g:ia', strtotime( $r['eff_event_time'] ) ) : '';
    // Same GL_Registration-backed count the Start screen shows
    // ("N confirmed registrants") -- spp_kq_confirmed_count()
    // (inc/spp-kq-live.php), not a new query.
    $reg_count = spp_kq_confirmed_count( (int) $r['occurrence_id'] );

    ob_start();
    ?>
    <a class="kq-picker-row<?php echo $is_test ? ' kq-picker-row--test' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'occ', $r['occurrence_id'] ) ); ?>">
        <?php if ( $is_test ) : ?>
            <span class="kq-picker-badge kq-picker-badge--test">PRACTICE</span>
        <?php endif; ?>
        <span class="kq-picker-title"><?php echo esc_html( $r['eff_title'] ); ?></span>
        <span class="kq-picker-date"><?php echo esc_html( trim( $date_str . ' ' . $time_str ) ); ?></span>
        <span class="kq-picker-regcount"><?php echo esc_html( $reg_count ); ?> confirmed</span>
        <span class="kq-picker-status kq-status-<?php echo esc_attr( $status['class'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
    </a>
    <?php
    return ob_get_clean();
}

function spp_kq_render_event_picker() : string {
    global $wpdb;
    $view = $wpdb->prefix . 'gl_events_v';
    $events_table = spp_kq_events_table();

    // Next 8 upcoming occurrences only, combined across Ace and Queen
    // (eff_category_id IN (2,3) already pools both) -- same chronological
    // order as before, just truncated so this list doesn't grow unbounded
    // as far-future occurrences get scheduled.
    $rows = $wpdb->get_results(
        "SELECT v.occurrence_id, v.eff_title, v.event_date, v.eff_event_time,
                e.current_round, e.phase
         FROM {$view} v
         LEFT JOIN {$events_table} e ON e.occurrence_id = v.occurrence_id
         WHERE v.eff_category_id IN (2,3) AND v.cancelled = 0 AND v.event_date >= CURDATE()
         ORDER BY v.event_date ASC, v.eff_event_time ASC
         LIMIT 8",
        ARRAY_A
    );

    // Practice / Test Sandbox: the 4 most recent PAST occurrences,
    // auto-selected by date (never hand-picked), same category pooling
    // and cancelled=0 filter as the upcoming list above. event_date <
    // CURDATE() is a live, self-maintaining boundary -- not a frozen
    // literal date -- so this set naturally rolls forward on its own as
    // today's date advances, the same way the upcoming list already
    // does via event_date >= CURDATE(). Safe to offer to any logged-in
    // member with zero risk regardless of what happens to it: this
    // feature didn't exist before this week, so no past occurrence has
    // ever had any spp_kq_* rows of its own to begin with.
    $test_rows = $wpdb->get_results(
        "SELECT v.occurrence_id, v.eff_title, v.event_date, v.eff_event_time,
                e.current_round, e.phase
         FROM {$view} v
         LEFT JOIN {$events_table} e ON e.occurrence_id = v.occurrence_id
         WHERE v.eff_category_id IN (2,3) AND v.cancelled = 0 AND v.event_date < CURDATE()
         ORDER BY v.event_date DESC, v.eff_event_time DESC
         LIMIT 4",
        ARRAY_A
    );

    ob_start();
    echo spp_kq_styles();
    ?>
    <div class="kq-wrap">
        <h2 class="kq-heading">Ace / Queen of the Courts &mdash; Live</h2>
        <p class="kq-caveat">Actions here affect real, live event data &mdash; please be careful.</p>
        <?php if ( empty( $rows ) ) : ?>
            <p>No upcoming Ace or Queen occurrences found.</p>
        <?php else : ?>
            <div class="kq-picker-list">
                <?php foreach ( $rows as $r ) : ?>
                    <?php echo spp_kq_render_picker_row( $r ); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $test_rows ) ) : ?>
            <h3 class="kq-picker-section-heading">Practice / Test Sandbox</h3>
            <p class="kq-hint">These are past, real events &mdash; safe to experiment on freely, since this tool didn't exist yet when they happened. Any logged-in member can use them. If a real score gets entered while testing, an administrator will need to clean it up (Reset only works before any score exists) before the next person tries.</p>
            <div class="kq-picker-list">
                <?php foreach ( $test_rows as $r ) : ?>
                    <?php echo spp_kq_render_picker_row( $r, true ); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// =============================================================
// Screens 2-7: occurrence-specific, share one header
// =============================================================

function spp_kq_render_occurrence_header( array $occurrence, string $notice = '' ) : string {
    $date_str = date_i18n( 'l, F j', strtotime( $occurrence['event_date'] ) );
    $time_str = $occurrence['eff_event_time'] ? date_i18n( 'g:ia', strtotime( $occurrence['eff_event_time'] ) ) : '';
    ob_start();
    ?>
    <p class="kq-back"><a href="<?php echo esc_url( remove_query_arg( 'occ' ) ); ?>">&larr; All events</a></p>
    <h2 class="kq-heading"><?php echo esc_html( $occurrence['eff_title'] ); ?></h2>
    <p class="kq-subheading"><?php echo esc_html( $date_str ) . ( $time_str ? ' &middot; ' . esc_html( $time_str ) : '' ); ?></p>
    <?php if ( $notice ) : ?>
        <div class="kq-notice kq-notice-err"><?php echo esc_html( $notice ); ?></div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/**
 * Screen 2: Start screen (not_started)
 *
 * @param string $event_date The occurrence's real event date ('Y-m-d'),
 *   from spp_kq_get_occurrence_summary() -- passed in by the caller rather
 *   than re-queried here.
 */
function spp_kq_render_start_screen( int $occurrence_id, string $event_date ) : string {
    $count = spp_kq_confirmed_count( $occurrence_id );
    $valid = ( $count >= 4 && $count <= 16 && $count % 4 === 0 );

    // Day-of restriction: this feature has no access gate beyond
    // is_user_logged_in() (see spp_kq_can_facilitate()), so this is the one
    // guard against an event being started on the wrong day by mistake --
    // block rather than invent a workaround, same convention as the
    // non-multiple-of-4 headcount case above. Administrators are exempt,
    // for testing (spp_is_admin() -- same helper this codebase already uses
    // for administrator-only exceptions elsewhere). Ordinary members,
    // editors included, stay restricted to the actual event day.
    $is_event_day    = ( current_time( 'Y-m-d' ) === $event_date );
    $can_start_today = $is_event_day || spp_is_admin();

    ob_start();
    ?>
    <p class="kq-meta"><?php echo esc_html( $count ); ?> confirmed registrant<?php echo $count === 1 ? '' : 's'; ?></p>
    <p class="kq-hint">Starts a random card draw that assigns everyone's starting court for Round 1.</p>
    <?php if ( ! $valid ) : ?>
        <p class="kq-warn">
            Cannot start: <?php echo esc_html( $count ); ?> confirmed registrant(s) &mdash; need a multiple of 4,
            between 4 and 16. Adjust the roster via
            <a href="<?php echo esc_url( add_query_arg( 'gl_reg_occ_id', $occurrence_id, home_url( '/gl-registration-admin/' ) ) ); ?>">Registration Admin</a> first.
        </p>
    <?php elseif ( ! $can_start_today ) : ?>
        <p class="kq-warn">
            This event is scheduled for <?php echo esc_html( date_i18n( 'l, F j', strtotime( $event_date ) ) ); ?> &mdash; come back on the day to start it.
        </p>
    <?php else : ?>
        <form method="post">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="start_round1">
            <button type="submit" class="kq-btn kq-btn-primary">Start Round 1 Draw</button>
        </form>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/** Screen 3: Draw screen (organizing, round 1, unclaimed slots remain) */
function spp_kq_render_draw_screen( int $occurrence_id ) : string {
    $courts_order = spp_kq_determine_courts_order( $occurrence_id );
    $not_drawn    = spp_kq_get_not_yet_drawn( $occurrence_id );
    $reveal       = spp_kq_get_draw_reveal_state( $occurrence_id, $courts_order );
    $unclaimed    = spp_kq_count_unclaimed( $occurrence_id, 1 );
    $total_slots  = count( $courts_order ) * 4;
    $drawn_so_far = $total_slots - $unclaimed;

    ob_start();
    ?>
    <p class="kq-round-label-tight">Round 1 &mdash; Draw in progress</p>
    <p class="kq-hint">Tap a player's name, then tap any face-down card to reveal their court.</p>
    <p class="kq-draw-progress" id="kq-draw-progress">
        <span id="kq-draw-count"><?php echo esc_html( "{$drawn_so_far} of {$total_slots} drawn" ); ?></span>
    </p>
    <div class="kq-notice kq-notice-err" id="kq-draw-error" style="display:none;"></div>

    <div class="kq-draw-columns">
        <div class="kq-draw-col">
            <h3>Not yet drawn</h3>
            <ul id="kq-not-drawn-list">
                <?php foreach ( $not_drawn as $p ) : ?>
                    <li data-uid="<?php echo esc_attr( $p['user_id'] ); ?>">
                        <button type="button" class="kq-player-btn" data-uid="<?php echo esc_attr( $p['user_id'] ); ?>"><?php echo esc_html( $p['name'] ); ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="kq-draw-col">
            <h3>Tap a card</h3>
            <div id="kq-card-grid">
                <?php for ( $i = 0; $i < $unclaimed; $i++ ) : ?>
                    <div class="kq-card">&#127165;</div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <div class="kq-draw-revealed">
        <h3>Drawn so far</h3>
        <div class="kq-court-grid" id="kq-revealed-courts">
            <?php foreach ( $courts_order as $court ) : ?>
                <div class="kq-court-card">
                    <div class="kq-court-name"><?php echo esc_html( $court ); ?></div>
                    <div class="kq-team kq-team-red">Red:
                        <?php foreach ( $reveal[ $court ]['red'] as $i => $name ) : ?>
                            <span class="kq-slot<?php echo $name ? ' kq-slot-filled' : ''; ?>" data-court="<?php echo esc_attr( $court ); ?>" data-color="red"><?php echo $name ? esc_html( $name ) : '&mdash;'; ?></span><?php echo $i === 0 ? ',' : ''; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="kq-team kq-team-black">Black:
                        <?php foreach ( $reveal[ $court ]['black'] as $i => $name ) : ?>
                            <span class="kq-slot<?php echo $name ? ' kq-slot-filled' : ''; ?>" data-court="<?php echo esc_attr( $court ); ?>" data-color="black"><?php echo $name ? esc_html( $name ) : '&mdash;'; ?></span><?php echo $i === 0 ? ',' : ''; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kq-action-row kq-action-row-right">
        <form method="post" class="kq-inline-form" onsubmit="return confirm('Clear this draw and start over? Any cards already drawn will be discarded -- players will need to draw again from scratch.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="reset_event">
            <input type="hidden" name="spp_kq_round" value="1">
            <button type="submit" class="kq-btn kq-btn-secondary">Reset</button>
        </form>
    </div>

    <script>
    (function() {
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'spp_kq_live_action' ) ); ?>;
        var occ     = <?php echo (int) $occurrence_id; ?>;
        var selectedUid = null;
        var errorBox = document.getElementById( 'kq-draw-error' );

        function showError( msg ) {
            errorBox.textContent = msg;
            errorBox.style.display = 'block';
            setTimeout( function() { errorBox.style.display = 'none'; }, 4000 );
        }

        function selectPlayer( btn ) {
            document.querySelectorAll( '.kq-player-btn' ).forEach( function( b ) { b.classList.remove( 'kq-selected' ); } );
            btn.classList.add( 'kq-selected' );
            selectedUid = parseInt( btn.dataset.uid, 10 );
        }

        document.getElementById( 'kq-not-drawn-list' ).addEventListener( 'click', function( e ) {
            var btn = e.target.closest( '.kq-player-btn' );
            if ( btn ) selectPlayer( btn );
        } );

        document.getElementById( 'kq-card-grid' ).addEventListener( 'click', function( e ) {
            var card = e.target.closest( '.kq-card' );
            if ( ! card ) return;
            if ( ! selectedUid ) {
                showError( 'Select a player first, then tap a card.' );
                return;
            }

            var data = new FormData();
            data.append( 'action', 'spp_kq_draw_card' );
            data.append( 'nonce', nonce );
            data.append( 'occ', occ );
            data.append( 'user_id', selectedUid );

            fetch( ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
                .then( function( r ) { return r.json(); } )
                .then( function( res ) {
                    if ( ! res.success ) {
                        showError( res.data || 'Draw failed.' );
                        return;
                    }
                    var d = res.data;

                    // Remove the drawn player from the list.
                    var li = document.querySelector( '#kq-not-drawn-list li[data-uid="' + selectedUid + '"]' );
                    var playerName = li ? li.querySelector( '.kq-player-btn' ).textContent : '';
                    if ( li ) li.remove();
                    selectedUid = null;

                    // Remove one card from the grid.
                    card.remove();

                    // Fill the first still-empty slot for this court/color.
                    var slots = document.querySelectorAll( '.kq-slot[data-court="' + d.court_name + '"][data-color="' + d.team_color + '"]' );
                    for ( var i = 0; i < slots.length; i++ ) {
                        if ( ! slots[ i ].classList.contains( 'kq-slot-filled' ) ) {
                            slots[ i ].textContent = playerName;
                            slots[ i ].classList.add( 'kq-slot-filled' );
                            break;
                        }
                    }

                    // Update the progress counter.
                    var remaining = document.querySelectorAll( '#kq-card-grid .kq-card' ).length;
                    var total = remaining + document.querySelectorAll( '.kq-slot-filled' ).length;
                    document.getElementById( 'kq-draw-count' ).textContent = ( total - remaining ) + ' of ' + total + ' drawn';

                    if ( d.draw_complete ) {
                        window.location.reload();
                    }
                } )
                .catch( function() { showError( 'Network error -- try again.' ); } );
        } );
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Screen 4: Overview screen (organizing, draw complete or round > 1)
 *
 * End Event is only offered once at least one real score exists
 * somewhere for this occurrence -- "declare a winner" makes no sense
 * before a single game has been played (round 1's overview, right
 * after the draw). When zero scores exist yet, this screen offers only
 * Start Play -- deliberately NOT a Reset button here either: a
 * completed draw with Start Play not yet tapped has nothing to undo
 * (Reset Event only ever appears on the Draw screen, for a genuinely
 * incomplete draw, or the In-Play screen, to undo Start Play itself --
 * see spp_kq_transition_reset_event()'s own docblock).
 */
function spp_kq_render_overview_screen( int $occurrence_id, int $round ) : string {
    $courts_data   = spp_kq_get_round_court_view( $occurrence_id, $round );
    $scores_exist  = spp_kq_has_any_recorded_score( $occurrence_id );

    ob_start();
    ?>
    <p class="kq-round-label">Round <?php echo esc_html( $round ); ?> &mdash; Ready to play</p>
    <div class="kq-court-grid">
        <?php foreach ( $courts_data as $court => $teams ) : ?>
            <div class="kq-court-card">
                <div class="kq-court-name"><?php echo esc_html( $court ); ?></div>
                <div class="kq-team kq-team-red">Red: <?php echo esc_html( implode( ', ', $teams['red'] ) ); ?></div>
                <div class="kq-team kq-team-black">Black: <?php echo esc_html( implode( ', ', $teams['black'] ) ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ( $scores_exist ) : ?>
        <p class="kq-hint">Start Play shows each player only their own court; End Event closes the day for good &mdash; no more rounds.</p>
    <?php else : ?>
        <p class="kq-hint">Start Play shows each player only their own court.</p>
    <?php endif; ?>
    <div class="kq-action-row">
        <form method="post" class="kq-inline-form">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="start_play">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-primary">Start Play</button>
        </form>
        <?php if ( $scores_exist ) : ?>
        <form method="post" class="kq-inline-form" onsubmit="return confirm('End the event now? This closes the day — no more rounds.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="end_event">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-secondary">End Event</button>
        </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Screen 5: In-Play -- the narrowed per-court score-entry view (Stage
 * 3). Access to the score itself is gated a SECOND time here, on top
 * of the feature-wide is_user_logged_in() check the shortcode
 * dispatcher already applied: spp_kq_get_my_court_assignment() is the
 * single source of truth, called identically here and in the AJAX
 * submit handler below, so the two can never diverge. A logged-in
 * user with no assignment this round sees only the live status line
 * -- never another court's names or score.
 */
function spp_kq_render_in_play_screen( int $occurrence_id, int $round ) : string {
    $user_id      = get_current_user_id();
    $assignment   = spp_kq_get_my_court_assignment( $occurrence_id, $round, $user_id );
    $progress     = spp_kq_get_round_progress( $occurrence_id, $round );
    // Reset is only offered once, before anything real has happened this
    // round (by the state machine, only possible in round 1) -- once any
    // court anywhere has reported, only Cancel Event remains available.
    $scores_exist = spp_kq_has_any_recorded_score( $occurrence_id );

    $court_view  = array();
    $current     = array( 'red_score' => null, 'black_score' => null );
    if ( $assignment ) {
        $all_courts = spp_kq_get_round_court_view( $occurrence_id, $round );
        $court_view = $all_courts[ $assignment['court_name'] ] ?? array( 'red' => array(), 'black' => array() );

        global $wpdb;
        $current = $wpdb->get_row( $wpdb->prepare(
            "SELECT red_score, black_score FROM " . spp_kq_scores_table() . "
             WHERE occurrence_id = %d AND round_number = %d AND court_name = %s",
            $occurrence_id, $round, $assignment['court_name']
        ), ARRAY_A ) ?: $current;
    }

    ob_start();
    ?>
    <p class="kq-round-label">Round <?php echo esc_html( $round ); ?> &mdash; In Play</p>

    <div class="kq-status" id="kq-status">
        <span id="kq-status-progress"><?php echo esc_html( "{$progress['reported']} of {$progress['total']} courts reported" ); ?></span>
    </div>

    <?php if ( ! $assignment ) : ?>
        <p class="kq-meta">You're not assigned to a court this round.</p>
    <?php else : ?>
        <div class="kq-msg kq-notice" id="kq-score-msg" style="display:none;"></div>

        <div class="kq-court-card">
            <div class="kq-court-name">Your court: <?php echo esc_html( $assignment['court_name'] ); ?></div>
            <div class="kq-team kq-team-red">Red: <?php echo esc_html( implode( ', ', $court_view['red'] ) ); ?></div>
            <div class="kq-team kq-team-black">Black: <?php echo esc_html( implode( ', ', $court_view['black'] ) ); ?></div>
        </div>

        <p class="kq-hint">Enter both teams' real scores &mdash; play continues until someone wins by a point, so an equal score is treated as a mistake to fix.</p>

        <div class="kq-score-row">
            <label>Red score<br>
                <input type="number" id="kq-red-score" class="kq-score-input" min="0" max="11" inputmode="numeric" pattern="[0-9]*"
                       value="<?php echo esc_attr( $current['red_score'] ?? '' ); ?>">
            </label>
            <label>Black score<br>
                <input type="number" id="kq-black-score" class="kq-score-input" min="0" max="11" inputmode="numeric" pattern="[0-9]*"
                       value="<?php echo esc_attr( $current['black_score'] ?? '' ); ?>">
            </label>
            <button type="button" class="kq-btn kq-btn-primary" id="kq-save-score-btn">Save Score</button>
            <span class="kq-saved" id="kq-saved-tag" style="display:none;">Saved &#10003;</span>
        </div>
    <?php endif; ?>

    <script>
    (function() {
        var ajaxUrl      = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce        = <?php echo wp_json_encode( wp_create_nonce( 'spp_kq_live_action' ) ); ?>;
        var occ          = <?php echo (int) $occurrence_id; ?>;
        var renderedRound = <?php echo (int) $round; ?>;

        var progressEl = document.getElementById('kq-status-progress');
        var msgEl      = document.getElementById('kq-score-msg');
        var redInput   = document.getElementById('kq-red-score');
        var blackInput = document.getElementById('kq-black-score');
        var saveBtn    = document.getElementById('kq-save-score-btn');
        var savedTag   = document.getElementById('kq-saved-tag');

        function showMsg(text, ok) {
            if (!msgEl) return;
            msgEl.textContent = text;
            msgEl.className = 'kq-msg kq-notice ' + (ok ? 'kq-notice-ok' : 'kq-notice-err');
            msgEl.style.display = 'block';
        }

        function updateSaveState() {
            if (!saveBtn) return;
            var r = redInput.value, b = blackInput.value;
            if (r === '' || b === '') { saveBtn.disabled = true; return; }
            var rn = parseInt(r, 10), bn = parseInt(b, 10);
            // Same rules, same order, as spp_kq_submit_court_score()'s
            // server-side checks -- this is immediate feedback only, the
            // server re-validates independently regardless.
            if (rn === 11 && bn === 11) {
                saveBtn.disabled = true;
                showMsg("11-11 isn't possible -- the game ends the instant either team reaches 11. Please double-check.", false);
            } else if (rn === bn) {
                saveBtn.disabled = true;
                showMsg("Scores can't be tied -- games are extended by a point specifically to avoid this.", false);
            } else {
                saveBtn.disabled = false;
                if (msgEl) msgEl.style.display = 'none';
            }
        }

        if (redInput && blackInput) {
            redInput.addEventListener('input', updateSaveState);
            blackInput.addEventListener('input', updateSaveState);
            updateSaveState();
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                saveBtn.disabled = true;
                savedTag.style.display = 'none';

                var data = new FormData();
                data.append('action', 'spp_kq_submit_score');
                data.append('nonce', nonce);
                data.append('occ', occ);
                data.append('round', renderedRound);
                data.append('red_score', redInput.value);
                data.append('black_score', blackInput.value);

                fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        updateSaveState();
                        if (!res.success) {
                            showMsg(res.data || 'Save failed.', false);
                            return;
                        }
                        savedTag.style.display = 'inline';
                        progressEl.textContent = res.data.reported + ' of ' + res.data.total + ' courts reported';
                    })
                    .catch(function() {
                        saveBtn.disabled = false;
                        showMsg('Network error -- try again.', false);
                    });
            });
        }

        // Lightweight poll: update the live count, and reload only once
        // this round has actually moved on (no real-time push needed --
        // "people are standing together anyway").
        function poll() {
            var data = new FormData();
            data.append('action', 'spp_kq_poll_status');
            data.append('nonce', nonce);
            data.append('occ', occ);

            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) return;
                    var d = res.data;
                    if (d.phase !== 'in_play' || d.current_round !== renderedRound) {
                        window.location.reload();
                        return;
                    }
                    if (progressEl) progressEl.textContent = d.reported + ' of ' + d.total + ' courts reported';
                })
                .catch(function() {});
        }
        setInterval(poll, 4000);
    })();
    </script>

    <div class="kq-action-row kq-action-row-right">
        <?php if ( ! $scores_exist ) : ?>
        <form method="post" class="kq-inline-form" onsubmit="return confirm('Undo Start Play? This keeps the same drawn courts and returns everyone to the Ready to play screen -- nothing is lost, since no scores have been entered yet.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="reset_event">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-secondary">Reset</button>
        </form>
        <?php endif; ?>
        <form method="post" class="kq-inline-form" onsubmit="return confirm('Cancel today\'s event? Any court that hasn\'t reported its score yet will lose this round\'s data entirely. Courts that already reported keep their result.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="cancel_event">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-danger">Cancel Event</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/** Screen 6: Complete screen */
function spp_kq_render_complete_screen( int $occurrence_id, int $round ) : string {
    $winner = spp_kq_get_final_winner_names( $occurrence_id, $round );
    ob_start();
    ?>
    <p class="kq-round-label">Event complete.</p>
    <?php if ( $winner ) : ?>
        <p class="kq-meta">Final round winners (Aces): <strong><?php echo esc_html( $winner ); ?></strong></p>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/** Screen 7: Cancelled screen -- kept vs. discarded courts */
function spp_kq_render_cancelled_screen( int $occurrence_id, int $round ) : string {
    $summary = spp_kq_get_cancellation_summary( $occurrence_id, $round );
    ob_start();
    ?>
    <p class="kq-round-label">Event cancelled &mdash; Round <?php echo esc_html( $round ); ?></p>
    <div class="kq-cancel-summary">
        <?php if ( ! empty( $summary['kept'] ) ) : ?>
            <div class="kq-kept">
                <h3>Kept &mdash; already reported, counts toward Club Ratings</h3>
                <ul>
                    <?php foreach ( $summary['kept'] as $court => $sc ) : ?>
                        <li><?php echo esc_html( $court ); ?>: Red <?php echo esc_html( $sc['red'] ); ?> &mdash; Black <?php echo esc_html( $sc['black'] ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ( ! empty( $summary['discarded'] ) ) : ?>
            <div class="kq-discarded">
                <h3>Discarded &mdash; hadn't reported, not recorded</h3>
                <ul>
                    <?php foreach ( $summary['discarded'] as $court ) : ?>
                        <li><?php echo esc_html( $court ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ( empty( $summary['kept'] ) && empty( $summary['discarded'] ) ) : ?>
            <p>No round data to report.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// =============================================================
// POST action dispatch (Start Round 1 / Start Play / End Event /
// Cancel Event) -- no redirect, just returns a notice (or '') and lets
// the main shortcode re-render whatever screen the new state calls for.
// =============================================================

/**
 * @param string $event_date The occurrence's real event date ('Y-m-d'),
 *   passed by the caller -- same value spp_kq_render_start_screen() uses,
 *   so the button's own day-of gate and this server-side check can never
 *   diverge.
 */
function spp_kq_handle_post_actions( int $occurrence_id, string $event_date ) : string {
    if ( ! isset( $_POST['spp_kq_action'], $_POST['spp_kq_nonce'] ) ) {
        return '';
    }
    if ( ! wp_verify_nonce( $_POST['spp_kq_nonce'], 'spp_kq_live_action' ) ) {
        return 'Security check failed -- please try again.';
    }

    $action = sanitize_text_field( wp_unslash( $_POST['spp_kq_action'] ) );
    $round  = isset( $_POST['spp_kq_round'] ) ? intval( $_POST['spp_kq_round'] ) : 0;

    switch ( $action ) {
        case 'start_round1':
            // Same day-of gate the Start screen's button enforces (see that
            // function's docblock) -- re-checked here so a crafted or stale
            // POST can't bypass it. Must match what the UI offers, same
            // discipline as every other access check in this codebase.
            if ( current_time( 'Y-m-d' ) !== $event_date && ! spp_is_admin() ) {
                return 'This event can only be started on its actual event date.';
            }
            $r = spp_kq_transition_start_round1( $occurrence_id );
            return ( ! $r['won'] && $r['error'] ) ? $r['error'] : '';

        case 'start_play':
            spp_kq_transition_start_play( $occurrence_id, $round );
            return '';

        case 'end_event':
            // Same gate the Overview screen's own button visibility
            // enforces (see spp_kq_render_overview_screen()) -- must
            // match what the UI offers, re-checked here so a crafted or
            // stale POST can't declare a winner before anything's been
            // played.
            if ( ! spp_kq_has_any_recorded_score( $occurrence_id ) ) {
                return 'End Event isn\'t available yet -- no scores have been recorded for this event.';
            }
            spp_kq_transition_end_event( $occurrence_id, $round );
            return '';

        case 'cancel_event':
            spp_kq_transition_cancel_event( $occurrence_id, $round );
            return '';

        case 'reset_event':
            // Available to any facilitator (spp_kq_can_facilitate(), the
            // feature-wide gate already checked at the shortcode
            // dispatcher) -- deliberately NOT admin-restricted, unlike
            // Full Reset below. Re-derives the real current phase itself
            // rather than trusting anything from the client; the actual
            // safety guarantee is the atomic CAS inside
            // spp_kq_transition_reset_event() itself, not this read.
            $state = spp_kq_get_event_state( $occurrence_id );
            if ( $state ) {
                spp_kq_transition_reset_event( $occurrence_id, $round, $state['phase'] );
            }
            return '';

        case 'full_reset':
            // Administrator-only -- checked here, server-side, as the
            // real enforcement; the button itself is also never printed
            // into the page for a non-admin (see spp_kq_live_shortcode()),
            // but that's belt-and-suspenders, not the actual gate. A
            // crafted POST from a non-admin session must fail here
            // regardless of what the UI would have shown them.
            if ( ! spp_is_admin() ) {
                return 'You do not have permission to do that.';
            }
            spp_kq_full_reset( $occurrence_id );
            return '';
    }

    return '';
}

// =============================================================
// Main shortcode
// =============================================================

add_shortcode( 'spp_kq_live', 'spp_kq_live_shortcode' );

function spp_kq_live_shortcode() : string {
    if ( ! spp_kq_can_facilitate() ) {
        return '<p>Please log in to use this tool.</p>';
    }

    $occurrence_id = isset( $_GET['occ'] ) ? absint( $_GET['occ'] ) : 0;

    if ( ! $occurrence_id ) {
        return spp_kq_render_event_picker();
    }

    $occurrence = spp_kq_get_occurrence_summary( $occurrence_id );
    if ( ! $occurrence ) {
        return '<p>Occurrence not found.</p>';
    }

    $notice = spp_kq_handle_post_actions( $occurrence_id, $occurrence['event_date'] );

    spp_kq_ensure_event_row( $occurrence_id );
    $state = spp_kq_get_event_state( $occurrence_id );
    $phase = $state['phase'];
    $round = (int) $state['current_round'];

    ob_start();
    echo spp_kq_styles();
    echo '<div class="kq-wrap">';
    echo spp_kq_render_occurrence_header( $occurrence, $notice );

    switch ( $phase ) {
        case 'not_started':
            echo spp_kq_render_start_screen( $occurrence_id, $occurrence['event_date'] );
            break;

        case 'organizing':
            $unclaimed = spp_kq_count_unclaimed( $occurrence_id, $round );
            if ( $round === 1 && $unclaimed > 0 ) {
                echo spp_kq_render_draw_screen( $occurrence_id );
            } else {
                echo spp_kq_render_overview_screen( $occurrence_id, $round );
            }
            break;

        case 'in_play':
            echo spp_kq_render_in_play_screen( $occurrence_id, $round );
            break;

        case 'complete':
            echo spp_kq_render_complete_screen( $occurrence_id, $round );
            break;

        case 'cancelled':
            echo spp_kq_render_cancelled_screen( $occurrence_id, $round );
            break;
    }

    echo spp_kq_render_full_reset( $occurrence_id, $round );

    echo '</div>';
    return ob_get_clean();
}

/**
 * Full Reset button -- administrator-only, deliberately available from
 * ANY phase (rendered once here, in the main dispatcher, not inside any
 * one screen's own render function, so it's present regardless of which
 * screen the switch above chose). Not printed into the page at all for
 * a non-administrator -- spp_is_admin() gates whether this function
 * emits anything, and the real enforcement is the matching check in
 * spp_kq_handle_post_actions()'s 'full_reset' case, not this visibility
 * check alone.
 */
function spp_kq_render_full_reset( int $occurrence_id, int $round ) : string {
    if ( ! spp_is_admin() ) {
        return '';
    }
    ob_start();
    ?>
    <div class="kq-full-reset-row">
        <form method="post" class="kq-inline-form" onsubmit="return confirm('FULL RESET -- this permanently discards ALL recorded data for this event, including any real, already-saved scores. This cannot be undone. Only continue if you are certain. Proceed?');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="full_reset">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-danger">Full Reset (Admin Only)</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

// =============================================================
// AJAX: card draw
// =============================================================

add_action( 'wp_ajax_spp_kq_draw_card', function() {
    if ( ! spp_kq_can_facilitate() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    $user_id       = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

    if ( ! $occurrence_id || ! $user_id ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    $result = spp_kq_draw_card( $occurrence_id, $user_id );
    if ( ! $result['success'] ) {
        wp_send_json_error( $result['error'] );
    }

    wp_send_json_success( $result );
} );

// =============================================================
// AJAX: score submit (Stage 3)
//
// The base gate here is still just is_user_logged_in() -- the SAME
// feature-wide gate everything else uses -- but that alone is NOT
// sufficient for a score: spp_kq_submit_court_score() (inc/spp-kq-
// live.php) independently re-derives the caller's own court via
// spp_kq_get_my_court_assignment() and refuses anyone without a real
// assignment row for occ+round+user_id. This handler never reads a
// court_name from $_POST at all -- there is nothing here for a client
// to spoof.
// =============================================================

add_action( 'wp_ajax_spp_kq_submit_score', function() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    $round         = isset( $_POST['round'] ) ? absint( $_POST['round'] ) : 0;
    $red_score     = isset( $_POST['red_score'] ) ? intval( $_POST['red_score'] ) : -1;
    $black_score   = isset( $_POST['black_score'] ) ? intval( $_POST['black_score'] ) : -1;

    if ( ! $occurrence_id || ! $round ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    $result = spp_kq_submit_court_score( $occurrence_id, $round, $red_score, $black_score, get_current_user_id() );
    if ( ! $result['success'] ) {
        wp_send_json_error( $result['error'] );
    }

    wp_send_json_success( $result );
} );

// =============================================================
// AJAX: lightweight status poll (Stage 3) -- lets the in-play screen
// update its "N of M reported" line live and detect a round advance
// without a full reload, while staying well short of real-time push.
// Read-only, no access restriction beyond being logged in: the count
// alone identifies no one's score.
// =============================================================

add_action( 'wp_ajax_spp_kq_poll_status', function() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    if ( ! $occurrence_id ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    $state = spp_kq_get_event_state( $occurrence_id );
    if ( ! $state ) {
        wp_send_json_error( 'Occurrence not found.' );
    }

    $progress = spp_kq_get_round_progress( $occurrence_id, (int) $state['current_round'] );

    wp_send_json_success( array(
        'phase'         => $state['phase'],
        'current_round' => (int) $state['current_round'],
        'reported'      => $progress['reported'],
        'total'         => $progress['total'],
    ) );
} );
