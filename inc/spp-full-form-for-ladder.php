<?php
/* =========================================================
   Full Form For Ladder
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Full form for ladder" (CM270)

   PURPOSE:
   Renders the ladder-event dropdown form with the carpool rank
   tolerance input field. Used where the caller needs that extra
   field (schedule production); see spp_short_form_for_ladder() for
   the version without it.

   EXPLICIT PARAMETER, per instruction: CM270 originally read $all
   via `global $all;`, implicitly relying on a parent snippet
   (CM275/CM54) having just populated it in the same request. Now
   that spp_gl_ladder_events_dropdown() (the migrated CM275) calls
   this function directly, $all is passed explicitly instead --
   same discipline as CM279's migration. The dropdown's own value
   contract (EventNumber/EventName/EventDate/Registrations per row)
   is unchanged.

   CALLED FROM (as of this migration):
     Directly, with $all passed explicitly, from
     spp_gl_ladder_events_dropdown() (the migrated CM275).
     Via [cmruncode name='Full form for ladder'] (CM270, now a
     transition shim around this function, falling back to reading
     the global $all if called that way): CM54 "Ladder Events drop
     down" -- unmigrated, flagged redundant/deprioritized earlier
     tonight, its own menu path already removed from Main. Not
     touched by this migration -- keeps working via the shim if
     ever invoked directly by URL.

   Changes from CM270: wrapped in a real function,
   spp_full_form_for_ladder( array $all ), instead of a bare
   top-level script. No mutation anywhere in this file, so no
   wildcard/meta_key audit applies. No other behavior change --
   identical markup, identical default tolerance value (15).
   ========================================================= */

defined( 'ABSPATH' ) || exit;

function spp_full_form_for_ladder( array $all ) {
    ?>
    <form id="dropform" name="PBEvent" method="post">
        <label for="PBEvent">Ladder List:</label>
        <select id="PBEvent" name="PBEvent">
            <option selected hidden value="">--- No Ladder selected ---</option>
            <?php foreach ( $all as $row ) : ?>
                <option value="<?php echo $row['EventNumber']; ?>">
                    <?php echo $row['EventName'] . ' ' . $row['EventDate'] . ' (' . $row['Registrations'] . ' registered)'; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <br><br>
        <label for="carpool_rank_tolerance"><strong>Carpool rank tolerance:</strong></label>
        <input type="number" id="carpool_rank_tolerance" name="carpool_rank_tolerance" value="15" min="1" max="50" style="width:60px;margin:0 8px;">
        <small style="color:#666;">Max rank difference when swapping players to help with travel/carpool (default 15)</small>
        <br><br>
        <input type="submit" value="Submit">
    </form>
    <?php
}
