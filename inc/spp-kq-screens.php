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
        .kq-meta { color:#555; margin-bottom:14px; }
        .kq-round-label { font-weight:bold; font-size:16px; margin:0 0 12px; color:#2c3e50; }
        .kq-round-label-tight { font-weight:bold; font-size:16px; margin:0 0 2px; color:#2c3e50; }
        .kq-notice { padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:14px; }
        .kq-notice-err { background:#f8d7da; border:1px solid #dc3545; color:#721c24; }
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
        .kq-picker-row { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid #ddd; border-radius:8px; text-decoration:none; color:#222; flex-wrap:wrap; }
        .kq-picker-row:hover { background:#f5f8fc; }
        .kq-picker-title { font-weight:bold; flex:1 1 200px; }
        .kq-picker-date { color:#666; font-size:14px; }
        .kq-picker-status { font-size:12px; font-weight:bold; padding:3px 10px; border-radius:12px; white-space:nowrap; }
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

function spp_kq_render_event_picker() : string {
    global $wpdb;
    $view = $wpdb->prefix . 'gl_events_v';
    $events_table = spp_kq_events_table();

    $rows = $wpdb->get_results(
        "SELECT v.occurrence_id, v.eff_title, v.event_date, v.eff_event_time,
                e.current_round, e.phase
         FROM {$view} v
         LEFT JOIN {$events_table} e ON e.occurrence_id = v.occurrence_id
         WHERE v.eff_category_id IN (2,3) AND v.cancelled = 0 AND v.event_date >= CURDATE()
         ORDER BY v.event_date ASC, v.eff_event_time ASC",
        ARRAY_A
    );

    ob_start();
    echo spp_kq_styles();
    ?>
    <div class="kq-wrap">
        <h2 class="kq-heading">Ace / Queen of the Courts &mdash; Live</h2>
        <?php if ( empty( $rows ) ) : ?>
            <p>No upcoming Ace or Queen occurrences found.</p>
        <?php else : ?>
            <div class="kq-picker-list">
                <?php foreach ( $rows as $r ) :
                    $status = spp_kq_status_label( $r['phase'] ?? null, (int) ( $r['current_round'] ?? 0 ) );
                    $date_str = date_i18n( 'M j', strtotime( $r['event_date'] ) );
                    $time_str = $r['eff_event_time'] ? date_i18n( 'g:ia', strtotime( $r['eff_event_time'] ) ) : '';
                ?>
                    <a class="kq-picker-row" href="<?php echo esc_url( add_query_arg( 'occ', $r['occurrence_id'] ) ); ?>">
                        <span class="kq-picker-title"><?php echo esc_html( $r['eff_title'] ); ?></span>
                        <span class="kq-picker-date"><?php echo esc_html( trim( $date_str . ' ' . $time_str ) ); ?></span>
                        <span class="kq-picker-status kq-status-<?php echo esc_attr( $status['class'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
                    </a>
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

/** Screen 2: Start screen (not_started) */
function spp_kq_render_start_screen( int $occurrence_id ) : string {
    $count = spp_kq_confirmed_count( $occurrence_id );
    $valid = ( $count >= 4 && $count <= 16 && $count % 4 === 0 );

    ob_start();
    ?>
    <p class="kq-meta"><?php echo esc_html( $count ); ?> confirmed registrant<?php echo $count === 1 ? '' : 's'; ?></p>
    <?php if ( $valid ) : ?>
        <form method="post">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="start_round1">
            <button type="submit" class="kq-btn kq-btn-primary">Start Round 1 Draw</button>
        </form>
    <?php else : ?>
        <p class="kq-warn">
            Cannot start: <?php echo esc_html( $count ); ?> confirmed registrant(s) &mdash; need a multiple of 4,
            between 4 and 16. Adjust the roster via
            <a href="<?php echo esc_url( add_query_arg( 'gl_reg_occ_id', $occurrence_id, home_url( '/gl-registration-admin/' ) ) ); ?>">Registration Admin</a> first.
        </p>
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

/** Screen 4: Overview screen (organizing, draw complete or round > 1) */
function spp_kq_render_overview_screen( int $occurrence_id, int $round ) : string {
    $courts_data = spp_kq_get_round_court_view( $occurrence_id, $round );

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
    <div class="kq-action-row">
        <form method="post" class="kq-inline-form">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="start_play">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-primary">Start Play</button>
        </form>
        <form method="post" class="kq-inline-form" onsubmit="return confirm('End the event now? This closes the day — no more rounds.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="end_event">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-secondary">End Event</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/** Screen 5: In-Play placeholder (Stage 3 owns the real content) */
function spp_kq_render_in_play_screen( int $occurrence_id, int $round ) : string {
    ob_start();
    ?>
    <p class="kq-round-label">Round <?php echo esc_html( $round ); ?> &mdash; In Play</p>
    <p class="kq-meta">Score entry is coming in Stage 3.</p>
    <div class="kq-action-row kq-action-row-right">
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

function spp_kq_handle_post_actions( int $occurrence_id ) : string {
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
            $r = spp_kq_transition_start_round1( $occurrence_id );
            return ( ! $r['won'] && $r['error'] ) ? $r['error'] : '';

        case 'start_play':
            spp_kq_transition_start_play( $occurrence_id, $round );
            return '';

        case 'end_event':
            spp_kq_transition_end_event( $occurrence_id, $round );
            return '';

        case 'cancel_event':
            spp_kq_transition_cancel_event( $occurrence_id, $round );
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

    $notice = spp_kq_handle_post_actions( $occurrence_id );

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
            echo spp_kq_render_start_screen( $occurrence_id );
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

    echo '</div>';
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
