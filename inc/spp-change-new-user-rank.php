<?php
/* =========================================================
   Change New User Rank
   Version: 1.0.0
   Date: 2026-09-06
   Based on: Code Manager snippet "Change New User Rank" (CM219),
   version 2.0

   PURPOSE:
   Manual admin form: pick a member from a searchable dropdown, set
   their Rank, and flip Ladder='Yes' -- the tool used to bring a
   brand-new registrant onto the ladder with an initial rank (or to
   manually correct an existing member's rank + ladder-active flag).
   Writes usermeta.Rank + usermeta.Ladder (delete-then-insert, exact
   meta_key match), then rebuilds Master/membership from that
   updated usermeta so the change is immediately reflected.

   CALLED FROM (as of this migration):
     Via [cmruncode name='Change New User Rank'] (CM219, now a
     transition shim around this function): the page
     "Change new user rank" (ID 20006919) -- the only caller,
     confirmed fresh against production tonight. Genuinely
     menu-reachable: Main menu side-nav -> Editor -> Schedules ->
     "Change user rank - usually just new users".

   REDUNDANCY CHECK (against spp_sa2_rank_flow() in
   spp-schedule-adjust.php, at the user's request, before migrating):
   NOT redundant -- confirmed a real capability/scope difference, not
   just a style difference:
     - spp_sa2_rank_flow()'s member picker reads from Master; this
       tool reads from `membership`. A brand-new registrant has
       neither a Master row nor Ladder='Yes' yet, so they can't even
       be selected in spp_sa2's dropdown -- it has no path to onboard
       a new user, only to nudge an existing ladder member's rank.
     - spp_sa2_rank_flow() writes Master.Rank directly and never
       touches Ladder anywhere. Per spp-create-membership-table.php's
       own header, Master is a DERIVED table, rebuilt from usermeta
       (WHERE Rank <> 0 AND Ladder = 'Yes'). A direct Master.Rank
       write with no matching usermeta.Ladder='Yes' would be silently
       reverted the next time spp_create_membership_table() runs
       (e.g. the next Schedule Production) -- this tool's usermeta
       + rebuild sequence doesn't have that failure mode.
   Proceeding with the migration as planned.

   Changes from CM219 v2.0:
   - Wrapped in a real function, spp_change_new_user_rank(), instead
     of a bare top-level script.
   - Calls spp_create_membership_table() directly instead of
     echo do_shortcode("[cmruncode name='Create membership table']")
     -- CM102 has already been migrated. Called bare, same as the
     original (no arguments either way); spp_create_membership_table()
     only ever echoes on its own internal error, so calling it
     in-place here reproduces the exact same "any error text appears
     at this point in the page" behavior the original do_shortcode()
     call had.
   - Dropped the dead "if (!session_status() == PHP_SESSION_ACTIVE)
     session_start()" guard -- always a no-op due to operator
     precedence (the same pattern already removed from every other
     snippet migrated so far), and $_SESSION was never read anywhere
     in this snippet regardless.
   - No other behavior change. Not fixed, noted only (same "faithful
     translation" reasoning as CM82/CM176): this form has no nonce
     and no capability check of its own -- same as every other
     CM-migrated admin form, its security boundary is the page-level
     editor/admin-only restriction (functions.php's cmruncode gate on
     page 20006919), not a per-form nonce. Not introduced here as
     that would be a behavior change outside this migration's scope.
   - Already correct in CM219 v2.0, preserved as-is: exact meta_key
     match on 'Rank'/'Ladder' (no wildcard over-delete), old_Rank
     left untouched, delete-before-insert on both keys.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_change_new_user_rank() {
    global $wpdb;
    $prefix     = $wpdb->prefix;
    $umetatable = $prefix . 'usermeta';
    $membership = 'membership';

    // ── Handle form submission ───────────────────────────────────────────────
    $success_msg = '';
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['User'], $_POST['rank'] ) ) {
        $user = intval( $_POST['User'] );
        $rank = intval( $_POST['rank'] );

        if ( $user > 0 && $rank > 0 ) {
            // Look up the user's name for the success message
            $person = $wpdb->get_row( $wpdb->prepare(
                "SELECT first_name, last_name FROM {$membership} WHERE user_id = %d",
                $user
            ), ARRAY_A );
            $person_name = $person ? trim( $person['first_name'] . ' ' . $person['last_name'] ) : "User {$user}";

            // Exact-match deletes — no wildcard over-deletion
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$umetatable} WHERE user_id = %d AND meta_key = 'Rank'",
                $user
            ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$umetatable} WHERE user_id = %d AND meta_key = 'Ladder'",
                $user
            ) );

            // Insert new Rank + Ladder. old_Rank is left untouched.
            $wpdb->insert( $umetatable, array(
                'user_id'    => $user,
                'meta_key'   => 'Rank',
                'meta_value' => $rank,
            ) );
            $wpdb->insert( $umetatable, array(
                'user_id'    => $user,
                'meta_key'   => 'Ladder',
                'meta_value' => 'Yes',
            ) );

            spp_create_membership_table();

            $success_msg = "Rank {$rank} set for {$person_name} (user ID {$user}). "
                        . 'Run Create Schedule when ready to reflect this change.';
        }
    }

    // ── Load members for the dropdown ────────────────────────────────────────
    $all = $wpdb->get_results(
        "SELECT user_id, first_name, last_name, user_email, Rank, Ladder
         FROM {$membership}
         ORDER BY first_name ASC, last_name ASC",
        ARRAY_A
    );

    ?>

    <style>
        .sur-wrap { max-width:560px; margin:20px auto; font-family:Arial,sans-serif; font-size:15px; }
        .sur-title { color:#2c3e50; font-size:20px; font-weight:bold; margin-bottom:14px; border-bottom:2px solid #3766AB; padding-bottom:6px; }
        .sur-success { background:#d4edda; border:1px solid #28a745; color:#155724; padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:14px; }
        .sur-field { margin-bottom:16px; }
        .sur-field label { display:block; font-weight:bold; margin-bottom:6px; color:#333; }
        .sur-field input[type=text], .sur-field input[type=number] {
            padding:8px 12px; font-size:15px; border:1px solid #bbb; border-radius:6px; width:100%; max-width:340px; box-sizing:border-box;
        }
        .sur-field select {
            padding:8px 12px; font-size:15px; border:1px solid #bbb; border-radius:6px;
            width:100%; max-width:340px; box-sizing:border-box;
        }
        .sur-search { margin-bottom:6px; }
        .sur-current { background:#f0f7ff; border:1px solid #3766AB; border-radius:6px; padding:10px 14px; margin:6px 0 16px; font-size:14px; color:#3766AB; display:none; }
        .sur-current.show { display:block; }
        .sur-current strong { color:#2c3e50; }
        .sur-submit {
            padding:10px 22px; background:#3766AB; color:#fff; border:none; border-radius:6px;
            font-size:15px; cursor:pointer;
        }
        .sur-submit:hover { background:#2d5491; }
        .sur-submit:disabled { background:#aaa; cursor:not-allowed; }
        .sur-hint { font-size:13px; color:#666; margin-top:4px; }
    </style>

    <div class="sur-wrap">
        <div class="sur-title">Set User Rank</div>

        <?php if ( $success_msg ) : ?>
            <div class="sur-success">✓ <?php echo esc_html( $success_msg ); ?></div>
        <?php endif; ?>

        <form method="post" action="" id="sur-form">

            <div class="sur-field">
                <label for="sur_search">Search Member</label>
                <input type="text" id="sur_search" class="sur-search" placeholder="Type name or email to filter..." autocomplete="off">
                <select name="User" id="sur_select" required size="8" style="margin-top:4px;">
                    <option value="">— Select a member —</option>
                    <?php foreach ( $all as $row ) :
                        $full  = trim( $row['first_name'] . ' ' . $row['last_name'] );
                        $rank  = $row['Rank'] ?? '—';
                        $ladd  = $row['Ladder'] ?? '—';
                        $label = "{$full} — Rank {$rank} — Ladder {$ladd}";
                    ?>
                        <option value="<?php echo esc_attr( $row['user_id'] ); ?>"
                                data-search="<?php echo esc_attr( strtolower( $full . ' ' . ( $row['user_email'] ?? '' ) ) ); ?>"
                                data-rank="<?php echo esc_attr( $rank ); ?>"
                                data-ladder="<?php echo esc_attr( $ladd ); ?>"
                                data-name="<?php echo esc_attr( $full ); ?>">
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sur-current" id="sur_current">
                Current: <strong id="sur_cur_name"></strong> &mdash;
                Rank <strong id="sur_cur_rank"></strong> &mdash;
                Ladder <strong id="sur_cur_ladder"></strong>
            </div>

            <div class="sur-field">
                <label for="sur_rank">New Rank</label>
                <input type="number" id="sur_rank" name="rank" min="1" required>
                <div class="sur-hint">Enter a positive integer. Ladder will be set to <strong>Yes</strong>.</div>
            </div>

            <input type="submit" class="sur-submit" id="sur_submit" value="Set Rank" disabled>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var search   = document.getElementById('sur_search');
        var select   = document.getElementById('sur_select');
        var current  = document.getElementById('sur_current');
        var curName  = document.getElementById('sur_cur_name');
        var curRank  = document.getElementById('sur_cur_rank');
        var curLad   = document.getElementById('sur_cur_ladder');
        var rankIn   = document.getElementById('sur_rank');
        var submit   = document.getElementById('sur_submit');
        var allOpts  = Array.from(select.options);

        function updateSubmitState() {
            submit.disabled = !( select.value && rankIn.value && parseInt(rankIn.value) > 0 );
        }

        // Filter dropdown by search input
        search.addEventListener('input', function() {
            var term = this.value.toLowerCase().trim();
            while (select.options.length > 1) select.remove(1);
            allOpts.forEach(function(opt) {
                if (opt.value === '') return;
                if (!term || (opt.dataset.search && opt.dataset.search.indexOf(term) !== -1)) {
                    select.appendChild(opt.cloneNode(true));
                }
            });
            if (select.options.length === 2) select.selectedIndex = 1;
            showCurrent();
            updateSubmitState();
        });

        // Show the selected member's current rank
        function showCurrent() {
            var opt = select.options[select.selectedIndex];
            if (opt && opt.value) {
                curName.textContent = opt.dataset.name;
                curRank.textContent = opt.dataset.rank;
                curLad.textContent  = opt.dataset.ladder;
                current.classList.add('show');
            } else {
                current.classList.remove('show');
            }
        }

        select.addEventListener('change', function() {
            showCurrent();
            updateSubmitState();
        });
        rankIn.addEventListener('input', updateSubmitState);

        // Confirmation before submit
        document.getElementById('sur-form').addEventListener('submit', function(e) {
            var opt = select.options[select.selectedIndex];
            if (!opt || !opt.value) return;
            var name    = opt.dataset.name;
            var oldRank = opt.dataset.rank;
            var newRank = rankIn.value;
            if (!confirm('Set rank for ' + name + ' from ' + oldRank + ' to ' + newRank + '?')) {
                e.preventDefault();
            }
        });
    });
    </script>
    <?php
}

add_shortcode( 'spp_change_new_user_rank', function( $atts ) {
    ob_start();
    spp_change_new_user_rank();
    return ob_get_clean();
} );
