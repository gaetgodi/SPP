<?php
/* =========================================================
   Ace/Queen of the Courts — KQ-Specific Roster Adjust
   Version: 1.0.0
   Date: 2026-09-13

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
    if ( ! $is_member ) {
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

    if ( $phase !== 'not_started' ) {
        return $back_link . '<p class="kq-hint">The roster can only be adjusted before Round 1 starts -- this event has already moved on.</p>';
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
