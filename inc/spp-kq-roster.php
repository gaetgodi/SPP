<?php
/* =========================================================
   Ace/Queen of the Courts — KQ-Specific Roster Adjust
   Version: 1.2.0
   Date: 2026-09-17

   Changes from 1.1.0 (Guest registrant, for a non-member filling in at a
   short-handed event -- reviewed/approved same day, needed for a live
   event that morning):
   - New spp_kq_roster_add_guest(): creates a real, lightweight WP user
     (no usable password, role => '' so zero capabilities, display name
     = whatever was typed), flags it spp_kq_guest=1 in usermeta, then
     hands off to spp_kq_roster_add() -- the EXACT same add-to-registrant
     path a real member's Add form already uses. No parallel/duplicate
     registration logic; court draw, movement, scoring, and permanent
     history all work completely unmodified from here since they already
     just operate on a user_id.
   - spp_kq_roster_add() itself now also accepts a spp_kq_guest=1 user_id
     (previously required a membership-table row) -- one added OR check,
     nothing else about that function changed.
   - New "Add a Guest" form on the Roster Adjust screen (?kq_view=roster,
     phase 'not_started' only, same as the existing Add/Remove forms),
     name-only -- posts 'roster_add_guest' (new case in spp_kq_handle_
     post_actions(), inc/spp-kq-screens.php), same nonce/phase-gate
     discipline as 'roster_add'/'roster_remove'.
   - Guests are deliberately NOT added to the membership table -- see
     spp_kq_player_name()'s (inc/spp-kq-screens.php, 1.19.0) new guest
     fallback for how their typed name still displays correctly
     everywhere without one. The only two places that need to know a
     player is a guest at all: Club Rating publish (spp_kq_build_club_
     rating_games(), inc/spp-kq-club-rating.php 1.2.0 -- skips the whole
     game, not just the guest's side) and the recap email (spp_kq_send_
     recap_emails(), inc/spp-kq-history.php 1.8.0 -- never sent, no real
     address exists).

   Changes from 1.0.0:
   - spp_kq_render_roster_screen() (?kq_view=roster) now branches on
     phase instead of only ever handling 'not_started': the pre-
     Round-1 add/remove UI is unchanged and still the ONLY thing shown
     while phase==='not_started', but 'organizing'/'in_play' (Round 1's
     draw complete, event underway) now render a live swap/cancel
     screen instead of the old "roster can only be adjusted before
     Round 1 starts" dead end -- see spp_kq_render_live_swap_screen()
     below. 'complete'/'cancelled' still show a plain "moved on"
     message, worded per phase.
   - New spp_kq_render_live_swap_screen(): per court in the CURRENT
     round, shows each of its 4 slots (occupied -> a "Swap out" pick-a-
     replacement form; empty, from a cancelled court re-created empty
     by spp_kq_transition_advance_round() -- "Assign" pick-a-player
     form) plus a "Cancel this court" action, unless that court has
     already reported (locked) or is already cancelled (badge only).
     Calls spp_kq_swap_player()/spp_kq_fill_open_slot()/
     spp_kq_cancel_court() (inc/spp-kq-live.php) -- this file adds no
     new mechanics of its own, only the screen and the
     spp_kq_handle_post_actions() wiring ('roster_swap'/
     'roster_fill_slot'/'cancel_court', inc/spp-kq-screens.php).

   PURPOSE:
   Replaces KQ's prior dependency on the generic GL Events
   [gl_registration_admin] shortcode (gl-events plugin,
   admin/registration-admin.php) for the one thing KQ's own
   pre-event flow ever needed from it: fixing an invalid confirmed-
   registrant headcount (spp_kq_render_start_screen()'s own
   4-16/multiple-of-4 check) before Round 1's draw. That tool is
   gated to administrator/editor/convenor
   (GL_Roles::can_view_registrants()) -- a real mismatch against KQ's
   own access model, deliberately open to any logged-in member
   (spp_kq_can_facilitate()). This file gives KQ its own, narrowly-
   scoped roster tool instead. Investigated read-only first (see the
   conversation this was built from) -- gl-registration-admin.php
   itself and every other gl-events plugin file are untouched; this is
   a standalone replacement for KQ's use case only, not a fix to the
   shared tool, which remains available as-is for its own, non-KQ uses.

   SCOPE (deliberately narrow, confirmed -- do not expand):
     - Add: any membership-table member can be added to the
       occurrence's registrant list (status='confirmed').
     - Remove: any currently-registered player can be removed
       (withdrawn).
     - Nothing else -- no waitlist-promotion logic, no contact-info
       editing, no other admin-tool functionality. A waitlisted player
       who should play is added the same way any other member is --
       spp_kq_roster_add() below doesn't care what status a row is
       already at; it always overwrites to 'confirmed', exactly like
       spp_sa2_registration_confirm()'s own behavior.

   DIRECT-WRITE PATTERN: follows inc/spp-schedule-adjust.php's own
   precedent (spp_sa2_registration_confirm()/
   spp_sa2_registration_withdraw(), that file's "REGISTRATION SYNC"
   section) exactly -- writes straight to gl_registrations, bypassing
   GL_Registration::register()/deregister() and the gl-events plugin's
   own hooks entirely, for the same reasons that file already
   documents: a same-day pre-event add/removal is almost always past
   any registration cutoff the plugin would otherwise enforce, and
   register() can silently downgrade to 'waiting' at capacity --
   neither fits a facilitator's deliberate manual override immediately
   before a card draw. No do_action() fired here, so no gl-events
   confirmation/withdrawal email goes out; the facilitator is telling
   the player directly (typically standing right there).

   PRE-ROUND-1 ONLY: both actions (and the screen below) re-verify
   server-side -- never trusting the client -- that the occurrence's
   phase is still 'not_started'. Once Round 1's draw begins, the roster
   is locked in via spp_kq_assignments placeholders; adjusting
   registrants after that point is a different feature (mid-event
   substitution) this tool deliberately does not attempt.

   ACCESS: no gate of its own here, deliberately. Every function in
   this file is only ever reached through [spp_kq_live]
   (spp_kq_live_shortcode(), inc/spp-kq-screens.php), which already
   checks spp_kq_can_facilitate() (any logged-in member) before
   rendering anything or dispatching any POST action -- same single-
   gate discipline every other KQ file already follows (see
   inc/spp-kq-live.php's own header: "spp_kq_can_facilitate() is the
   ONE gate function -- every entry point in this feature calls it and
   nothing else"). This file introduces no new shortcode, no new AJAX
   action, and no new entry point outside that existing gate.

   WIRING: two new spp_kq_handle_post_actions() cases
   ('roster_add'/'roster_remove', inc/spp-kq-screens.php), plain nonce-
   protected POST forms (same convention as Start Play/End Event/
   Cancel Event -- no redirect, the page just re-renders), a new
   ?kq_view=roster screen (same additive-flag pattern the Full
   Scoreboard screen already uses), and the Start screen's former
   gl-registration-admin link now points here instead.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * Add (or re-confirm) one member's registration for this occurrence.
 * Mirrors spp_sa2_registration_confirm() (inc/spp-schedule-adjust.php)
 * exactly: any existing row (whatever its current status) is
 * overwritten to 'confirmed'; otherwise a fresh confirmed row is
 * inserted. $user_id must be a real membership-table row -- validated
 * here rather than trusted from the client.
 *
 * @return array ['success'=>bool, 'error'=>?string]
 */
function spp_kq_roster_add( int $occurrence_id, int $user_id ) : array {
    if ( $user_id <= 0 ) {
        return array( 'success' => false, 'error' => 'Please select a member to add.' );
    }

    global $wpdb;

    $is_member = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM membership WHERE user_id = %d", $user_id
    ) );
    // Guests (spp_kq_roster_add_guest() below) are real WP users but
    // deliberately have no membership-table row -- accepted here too so
    // that function can reuse this same add path rather than duplicate
    // its INSERT/UPDATE into gl_registrations.
    $is_guest = (bool) get_user_meta( $user_id, 'spp_kq_guest', true );
    if ( ! $is_member && ! $is_guest ) {
        return array( 'success' => false, 'error' => 'Not a recognized member.' );
    }

    $table = $wpdb->prefix . 'gl_registrations';
    $now   = current_time( 'mysql' );

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE occurrence_id = %d AND user_id = %d", $occurrence_id, $user_id
    ) );

    if ( $existing ) {
        $wpdb->update( $table,
            array( 'status' => 'confirmed', 'updated_at' => $now ),
            array( 'id' => $existing->id )
        );
    } else {
        $wpdb->insert( $table, array(
            'occurrence_id' => $occurrence_id,
            'user_id'       => $user_id,
            'status'        => 'confirmed',
            'registered_at' => $now,
            'updated_at'    => $now,
            'notes'         => 'Added via KQ Roster tool',
        ) );
    }

    return array( 'success' => true, 'error' => null );
}

/**
 * Create a real, lightweight, no-login WP user for a non-member filling
 * in at a short-handed event, flag it spp_kq_guest=1 in usermeta, then
 * add it to this occurrence's registrant list via spp_kq_roster_add()
 * ABOVE -- the exact same INSERT/UPDATE path a real member's Add form
 * uses, no parallel/duplicate registration logic. Everything downstream
 * of that call (court draw, movement, scoring, permanent history)
 * already just operates on a user_id and needs no guest-awareness of
 * its own; only Club Rating (spp_kq_build_club_rating_games(), inc/
 * spp-kq-club-rating.php) and the recap email (spp_kq_send_recap_
 * emails(), inc/spp-kq-history.php) explicitly skip anyone flagged this
 * way.
 *
 * No usable password (wp_generate_password() output is hashed then
 * discarded -- nobody, including this code, ever holds it in the
 * clear), no role at all (role => '' -- zero WordPress capabilities,
 * not even 'read'), so a guest cannot log in or access anything. Not
 * added to the membership table on purpose -- see spp_kq_player_name()'s
 * (inc/spp-kq-screens.php) guest fallback for how their typed name still
 * displays everywhere without one.
 *
 * @return array ['success'=>bool, 'error'=>?string]
 */
function spp_kq_roster_add_guest( int $occurrence_id, string $name ) : array {
    $name = trim( sanitize_text_field( $name ) );
    if ( $name === '' ) {
        return array( 'success' => false, 'error' => 'Please enter the guest\'s name.' );
    }

    $token = wp_generate_password( 12, false, false );

    $user_id = wp_insert_user( array(
        'user_login'   => 'kq_guest_' . $token,
        'user_email'   => 'kq-guest-' . $token . '@pickleballstouffville.ca',
        'user_pass'    => wp_generate_password( 64, true, true ),
        'display_name' => $name,
        'nickname'     => $name,
        'first_name'   => $name,
        'role'         => '',
    ) );

    if ( is_wp_error( $user_id ) ) {
        return array( 'success' => false, 'error' => 'Could not create guest: ' . $user_id->get_error_message() );
    }

    update_user_meta( $user_id, 'spp_kq_guest', 1 );

    return spp_kq_roster_add( $occurrence_id, (int) $user_id );
}

/**
 * Withdraw one currently-registered player from this occurrence.
 * Mirrors spp_sa2_registration_withdraw() exactly: only ever UPDATEs an
 * existing confirmed row to 'withdrawn' -- there is nothing to insert
 * if the player was never registered here in the first place.
 *
 * @return array ['success'=>bool, 'error'=>?string]
 */
function spp_kq_roster_remove( int $occurrence_id, int $user_id ) : array {
    if ( $user_id <= 0 ) {
        return array( 'success' => false, 'error' => 'Please select a player to remove.' );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'gl_registrations';

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE occurrence_id = %d AND user_id = %d AND status = 'confirmed'",
        $occurrence_id, $user_id
    ) );

    if ( ! $existing ) {
        return array( 'success' => false, 'error' => 'That player is not currently registered for this occurrence.' );
    }

    $wpdb->update( $table,
        array( 'status' => 'withdrawn', 'updated_at' => current_time( 'mysql' ) ),
        array( 'id' => $existing->id )
    );

    return array( 'success' => true, 'error' => null );
}

/**
 * Roster Adjust screen: current confirmed registrants (each with a
 * Remove button) + a searchable Add form listing every membership-table
 * member not already registered. Same searchable-dropdown UX as the
 * generic gl-registration-admin tool this replaces (a plain text filter
 * over a <select>, vanilla JS, no library) -- familiar to anyone who's
 * used that tool before, reimplemented here rather than shared, since
 * this file deliberately never touches gl-events plugin code.
 *
 * Reachable via ?kq_view=roster (inc/spp-kq-screens.php's dispatcher),
 * same additive-flag pattern as the Full Scoreboard screen. Re-verifies
 * phase === 'not_started' itself rather than trusting the caller --
 * see this file's own header.
 */
function spp_kq_render_roster_screen( int $occurrence_id ) : string {
    $back_link = '<p class="kq-hint"><a href="' . esc_url( remove_query_arg( 'kq_view' ) ) . '">&laquo; Back</a></p>';

    $state = spp_kq_get_event_state( $occurrence_id );
    $phase = $state['phase'] ?? 'not_started';

    if ( in_array( $phase, array( 'organizing', 'in_play' ), true ) ) {
        return $back_link . spp_kq_render_live_swap_screen( $occurrence_id );
    }

    if ( $phase === 'complete' || $phase === 'cancelled' ) {
        $why = ( $phase === 'complete' ) ? 'this event has ended.' : 'this event was cancelled.';
        return $back_link . '<p class="kq-hint">The roster can no longer be adjusted -- ' . esc_html( $why ) . '</p>';
    }

    global $wpdb;

    $confirmed = $wpdb->get_results( $wpdb->prepare(
        "SELECT r.user_id, m.first_name, m.last_name
         FROM {$wpdb->prefix}gl_registrations r
         LEFT JOIN membership m ON m.user_id = r.user_id
         WHERE r.occurrence_id = %d AND r.status = 'confirmed'
         ORDER BY m.first_name ASC, m.last_name ASC",
        $occurrence_id
    ), ARRAY_A );

    $registered_ids = array_map( 'intval', array_column( $confirmed, 'user_id' ) );

    $all_members = $wpdb->get_results(
        "SELECT user_id, first_name, last_name FROM membership WHERE user_email != '' ORDER BY first_name ASC, last_name ASC",
        ARRAY_A
    );

    $count = count( $confirmed );
    $valid = ( $count >= 4 && $count <= 16 && $count % 4 === 0 );

    ob_start();
    echo $back_link;
    ?>
    <p class="kq-round-label">Roster Adjust</p>
    <p class="kq-meta">
        <?php echo esc_html( $count ); ?> confirmed registrant<?php echo $count === 1 ? '' : 's'; ?>
        &mdash;
        <?php if ( $valid ) : ?>
            <span style="color:#2c7a2c;">valid, ready to start</span>
        <?php else : ?>
            <span style="color:#b03a2e;">need a multiple of 4, between 4 and 16</span>
        <?php endif; ?>
    </p>

    <h3 class="kq-picker-section-heading">Currently Registered</h3>
    <?php if ( empty( $confirmed ) ) : ?>
        <p class="kq-hint">No confirmed registrants yet.</p>
    <?php else : ?>
        <ul class="kq-roster-list">
            <?php foreach ( $confirmed as $p ) :
                $name = spp_kq_player_name( $p['first_name'], $p['last_name'], (int) $p['user_id'] );
            ?>
                <li>
                    <?php echo esc_html( $name ); ?>
                    <form method="post" class="kq-inline-form" style="display:inline;">
                        <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                        <input type="hidden" name="spp_kq_action" value="roster_remove">
                        <input type="hidden" name="spp_kq_roster_user_id" value="<?php echo esc_attr( $p['user_id'] ); ?>">
                        <button type="submit" class="kq-btn kq-btn-danger kq-btn-small">Remove</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h3 class="kq-picker-section-heading">Add a Member</h3>
    <form method="post" class="kq-inline-form">
        <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
        <input type="hidden" name="spp_kq_action" value="roster_add">
        <input type="text" id="kq-roster-search" placeholder="Type name to search..." autocomplete="off"
               style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;width:280px;">
        <select id="kq-roster-add-user-id" name="spp_kq_roster_user_id" required size="6"
                style="display:block;margin-top:6px;width:340px;border:1px solid #ddd;border-radius:4px;padding:4px;">
            <option value="">&mdash; Select member &mdash;</option>
            <?php foreach ( $all_members as $m ) :
                if ( in_array( (int) $m['user_id'], $registered_ids, true ) ) continue;
                $full_name = trim( $m['first_name'] . ' ' . $m['last_name'] );
            ?>
                <option value="<?php echo esc_attr( $m['user_id'] ); ?>" data-name="<?php echo esc_attr( strtolower( $full_name ) ); ?>">
                    <?php echo esc_html( $full_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="kq-btn kq-btn-primary" style="margin-top:8px;">Add</button>
    </form>

    <h3 class="kq-picker-section-heading">Add a Guest</h3>
    <p class="kq-hint">For a non-member filling in at a short-handed event. Creates a no-login account -- games count normally, but never affect Club Ratings and never get a recap email.</p>
    <form method="post" class="kq-inline-form">
        <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
        <input type="hidden" name="spp_kq_action" value="roster_add_guest">
        <input type="text" name="spp_kq_guest_name" placeholder="Guest's name" required maxlength="60"
               style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;width:280px;">
        <button type="submit" class="kq-btn kq-btn-primary" style="margin-top:8px;">Add Guest</button>
    </form>

    <script>
    (function() {
        var searchInput = document.getElementById('kq-roster-search');
        var select = document.getElementById('kq-roster-add-user-id');
        if (!searchInput || !select) return;
        var allOptions = Array.from(select.options);
        searchInput.addEventListener('input', function() {
            var term = this.value.toLowerCase().trim();
            while (select.options.length > 1) select.remove(1);
            allOptions.forEach(function(opt) {
                if (opt.value === '') return;
                if (!term || opt.dataset.name.indexOf(term) !== -1) select.appendChild(opt.cloneNode(true));
            });
            if (select.options.length === 2) select.selectedIndex = 1;
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

// =============================================================
// Live swap / cancel screen (Round 1 draw complete, event underway --
// 'organizing' or 'in_play'). See this file's own 1.1.0 changelog.
// =============================================================

/**
 * Every court's 4 slots for the current round, in fixed hierarchy
 * order, each either occupied (user_id + name) or empty (both null --
 * only possible for a court spp_kq_transition_advance_round() just
 * re-created after a cancellation, inc/spp-kq-live.php). Distinct from
 * spp_kq_get_round_court_view() (inc/spp-kq-screens.php), which only
 * ever shows real (non-NULL) players by name -- this needs the empty
 * slots too, plus user_id, for the swap/assign forms below.
 *
 * @return array court_name => list of ['team_color'=>string,
 *   'user_id'=>?int, 'name'=>?string], 4 entries per court.
 */
function spp_kq_get_round_slots_detailed( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.court_name, a.team_color, a.user_id, m.first_name, m.last_name
         FROM {$table} a
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE a.occurrence_id = %d AND a.round_number = %d
         ORDER BY a.court_name, a.team_color, a.id",
        $occurrence_id, $round_number
    ), ARRAY_A );

    $out = array();
    foreach ( $rows as $r ) {
        $out[ $r['court_name'] ][] = array(
            'team_color' => $r['team_color'],
            'user_id'    => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'name'       => $r['user_id'] !== null ? spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] ) : null,
        );
    }
    return $out;
}

/**
 * Live swap/cancel screen: every court in the current round, each slot
 * either "Swap out" (occupied) or "Assign" (empty, a re-staffed-after-
 * cancellation court) plus a "Cancel this court" action -- unless that
 * court has already reported (locked, shown as a badge only) or is
 * already cancelled (badge only). One shared <template> of available
 * members (anyone NOT already assigned this round) is cloned into
 * whichever row's <select> the facilitator opens, via
 * spp_kq_swap_player()/spp_kq_fill_open_slot()/spp_kq_cancel_court()
 * (inc/spp-kq-live.php) doing the actual validation server-side --
 * this function only renders, never trusts its own display state.
 */
function spp_kq_render_live_swap_screen( int $occurrence_id ) : string {
    $state = spp_kq_get_event_state( $occurrence_id );
    $round = (int) ( $state['current_round'] ?? 0 );

    if ( $round === 1 && spp_kq_count_unclaimed( $occurrence_id, 1 ) > 0 ) {
        return '<p class="kq-hint">Finish Round 1\'s draw before swapping players.</p>';
    }

    $courts_order = spp_kq_determine_courts_order( $occurrence_id );
    $slots        = spp_kq_get_round_slots_detailed( $occurrence_id, $round );
    $cancelled    = spp_kq_get_cancelled_courts( $occurrence_id, $round );

    $assigned_ids = array();
    foreach ( $slots as $court_slots ) {
        foreach ( $court_slots as $s ) {
            if ( $s['user_id'] !== null ) {
                $assigned_ids[ $s['user_id'] ] = true;
            }
        }
    }

    global $wpdb;
    $exclude_sql = empty( $assigned_ids )
        ? ''
        : 'AND user_id NOT IN (' . implode( ',', array_map( 'intval', array_keys( $assigned_ids ) ) ) . ')';
    $available_members = $wpdb->get_results(
        "SELECT user_id, first_name, last_name FROM membership WHERE user_email != '' {$exclude_sql} ORDER BY first_name ASC, last_name ASC",
        ARRAY_A
    );

    ob_start();
    ?>
    <p class="kq-round-label">Round <?php echo esc_html( $round ); ?> &mdash; Swap / Cancel</p>
    <p class="kq-hint">Swap a player out for a replacement, or cancel a court's game for this round -- both only before that court reports its score.</p>

    <template id="kq-swap-options-template">
        <option value="">&mdash; Select &mdash;</option>
        <?php foreach ( $available_members as $m ) :
            $full_name = trim( $m['first_name'] . ' ' . $m['last_name'] );
        ?>
            <option value="<?php echo esc_attr( $m['user_id'] ); ?>"><?php echo esc_html( $full_name ); ?></option>
        <?php endforeach; ?>
    </template>

    <?php foreach ( $courts_order as $court ) :
        $court_slots = $slots[ $court ] ?? array();
        $is_cancelled = in_array( $court, $cancelled, true );
        $has_reported = spp_kq_court_has_reported( $occurrence_id, $round, $court );
    ?>
        <div class="kq-court-card">
            <div class="kq-court-name"><?php echo esc_html( $court ); ?></div>

            <?php if ( $is_cancelled ) : ?>
                <p class="kq-hint">Cancelled for this round.</p>
            <?php elseif ( $has_reported ) : ?>
                <p class="kq-hint">Already reported &mdash; locked.</p>
            <?php else : ?>
                <?php foreach ( $court_slots as $i => $slot ) :
                    $row_id = 'kq-swap-' . sanitize_title( $court ) . '-' . $i;
                ?>
                    <div class="kq-swap-row">
                        <span class="kq-team-<?php echo esc_attr( $slot['team_color'] ); ?>">
                            <?php echo esc_html( ucfirst( $slot['team_color'] ) ); ?>:
                            <?php echo $slot['name'] ? esc_html( $slot['name'] ) : '(empty)'; ?>
                        </span>
                        <button type="button" class="kq-btn kq-btn-secondary kq-btn-small kq-swap-toggle" data-target="<?php echo esc_attr( $row_id ); ?>">
                            <?php echo $slot['user_id'] ? 'Swap out' : 'Assign'; ?>
                        </button>
                        <form method="post" class="kq-inline-form kq-swap-form" id="<?php echo esc_attr( $row_id ); ?>" hidden>
                            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                            <?php if ( $slot['user_id'] ) : ?>
                                <input type="hidden" name="spp_kq_action" value="roster_swap">
                                <input type="hidden" name="spp_kq_swap_old_user_id" value="<?php echo esc_attr( $slot['user_id'] ); ?>">
                                <select class="kq-swap-select" name="spp_kq_swap_new_user_id" required></select>
                            <?php else : ?>
                                <input type="hidden" name="spp_kq_action" value="roster_fill_slot">
                                <input type="hidden" name="spp_kq_fill_court_name" value="<?php echo esc_attr( $court ); ?>">
                                <input type="hidden" name="spp_kq_fill_team_color" value="<?php echo esc_attr( $slot['team_color'] ); ?>">
                                <select class="kq-swap-select" name="spp_kq_fill_new_user_id" required></select>
                            <?php endif; ?>
                            <button type="submit" class="kq-btn kq-btn-primary kq-btn-small">Confirm</button>
                        </form>
                    </div>
                <?php endforeach; ?>

                <form method="post" class="kq-inline-form" onsubmit="return confirm('Cancel this court\'s game for this round? No score will be recorded for it -- other courts are unaffected.');">
                    <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                    <input type="hidden" name="spp_kq_action" value="cancel_court">
                    <input type="hidden" name="spp_kq_cancel_court_name" value="<?php echo esc_attr( $court ); ?>">
                    <button type="submit" class="kq-btn kq-btn-danger kq-btn-small">Cancel this court</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <script>
    (function() {
        document.querySelectorAll('.kq-swap-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var form = document.getElementById(this.dataset.target);
                if (!form) return;
                var select = form.querySelector('.kq-swap-select');
                if (select && !select.dataset.populated) {
                    var tpl = document.getElementById('kq-swap-options-template');
                    select.appendChild(tpl.content.cloneNode(true));
                    select.dataset.populated = '1';
                }
                form.hidden = !form.hidden;
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
