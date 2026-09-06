<?php
/* =========================================================
   Create View (schedules_w)
   Version: 1.0.0
   Date: 2026-09-05
   Based on: Code Manager snippet "Create View" (CM254)

   PURPOSE:
   (Re)builds a MySQL VIEW joining a schedule table against
   Times/Groups/Courts into the friendly per-player row shape
   (name, phone, per-game scores, computed Score, court/time/group
   labels) used throughout the player- and admin-facing schedule
   pages. Defaults to `schedules_w` over `Schedules`; callers can
   point it at a different source table under a different view
   name -- CM215 "Black for scores - colour" does this, targeting
   `schedules_w_score_review` over either `Schedules` (current
   live event) or `Schedules_Scores_{$Event}` (a past event),
   depending which the admin has selected.

   CALLED FROM (as of this migration):
     - Directly: gl-schedule-production.php, spp-schedule-production.php
       (updated to call spp_create_view() directly as part of this
       migration)
     - Via [cmruncode name='Create View'] (CM254, now a transition
       shim around this function): CM194, CM215, CM262, CM263,
       CM265, CM280, CM282, and the pages "Edit Schedule", "This
       week's Schedule", "Create View". None of these have been
       touched by this migration -- they keep working unchanged via
       the shim and get updated individually as their own turn in
       the migration order comes up.

   Changes from CM254:
   - Wrapped in a real function, spp_create_view( string $table =
     'Schedules', string $view_name = 'schedules_w' ), instead of a
     bare top-level script reading $table/$view_name out of the
     Code Manager-specific $wpda_shortcode_args global. Directly
     callable from tracked PHP with ordinary parameters.
   - ADDED a guard closing the DROP+CREATE race window this view
     has always had on every unconditional call (there is a real,
     if brief, window between DROP VIEW and CREATE VIEW where the
     view does not exist -- gl-player-schedule-view.php's own
     changelog already documents this exact class of risk and
     removed its dependency on this view for that reason). "Edit
     Schedule" and "This week's Schedule" are both live,
     menu-reachable pages that still call this unconditionally on
     every render, so the window is still real today.

     The guard is NOT a naive "skip if a view of this name already
     exists" check -- that would be wrong in exactly the way the
     schedules_w collision incident was wrong. Traced every current
     caller (theme files + CM194/CM215/CM262/CM263/CM265/CM280/CM282
     + the 3 pages that call this by name) fresh before writing
     this: every caller except CM215 always pairs a fixed
     view_name with a fixed table. CM215 reuses the SAME view_name
     (schedules_w_score_review) across calls where $table genuinely
     differs (Schedules vs. Schedules_Scores_{$Event}, depending on
     which event's scores are being viewed) -- a plain existence
     check would silently keep serving a stale table's data after
     switching events, the same failure mode as the original
     incident.

     So the guard checks BOTH that the view exists AND that its
     CURRENT underlying source table already matches the requested
     $table (parsed out of information_schema.VIEWS.VIEW_DEFINITION
     -- see spp_create_view_current_table() below), skipping the
     rebuild only when both hold. Verified against this view's real,
     live VIEW_DEFINITION plus two adversarial cases (the table name
     re-appearing as a column name elsewhere in the definition; an
     unrelated table whose name contains "schedules" as a substring
     appearing later in a join) before writing this.
   - No other behavior change: identical SELECT/JOIN/Score logic,
     identical default table/view names, identical unescaped
     interpolation of $table/$view_name into the DDL (both are
     always code-controlled -- a hardcoded default, or a value
     computed by other tracked/migrated code -- never raw end-user
     input; not touched by this migration).
   - Dropped the dead "if (!session_status() == PHP_SESSION_ACTIVE)
     session_start()" guard -- always a no-op due to operator
     precedence (the same pattern already removed from every other
     snippet migrated so far), and $_SESSION was never read anywhere
     in this snippet regardless.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * Parse the current source table out of a view's definition.
 *
 * The source table is aliased "schedules" by spp_create_view()'s own
 * CREATE VIEW SQL below (`$table` `schedules` join ...), and that
 * alias appears everywhere else in the definition as
 * `schedules`.`column` -- dot-joined, no space. The only place a
 * backtick-quoted identifier is followed by whitespace, then the
 * literal `schedules`, then "join", is the true FROM-clause source
 * table, whether or not it's schema-qualified (SHOW CREATE VIEW
 * omits the schema; information_schema.VIEWS.VIEW_DEFINITION
 * includes it). Returns null if the pattern isn't found (e.g. the
 * view exists but wasn't built by this function).
 */
function spp_create_view_current_table( $view_definition ) {
    if ( preg_match( '/(?:`[^`]+`\.)?`([^`]+)`\s+`schedules`\s+join/i', $view_definition, $m ) ) {
        return $m[1];
    }
    return null;
}

function spp_create_view( string $table = 'Schedules', string $view_name = 'schedules_w' ) {
    global $wpdb;

    // Guard: skip the DROP+CREATE only if the view already exists AND
    // already points at the requested table. See header for why a plain
    // existence check is unsafe (CM215 reuses one view_name across
    // different tables).
    $current_definition = $wpdb->get_var( $wpdb->prepare(
        "SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
        $view_name
    ) );

    if ( $current_definition !== null && spp_create_view_current_table( $current_definition ) === $table ) {
        return;
    }

    $wpdb->query( "DROP VIEW IF EXISTS `$view_name`" );
    $wpdb->query( "
        CREATE ALGORITHM=UNDEFINED  /* SQL SECURITY DEFINER */ VIEW `$view_name`  AS
        SELECT `schedules`.`Sequence` AS `Sequence`, cast(`schedules`.`Rank` as unsigned) AS `Rank`, `schedules`.`user_id` AS `user_id`, `schedules`.`first_name` AS `first_name`, `schedules`.`last_name` AS `last_name`, CONCAT(`schedules`.`first_name`, ' ', `schedules`.`last_name`) AS `full_name`, `schedules`.`user_phone` AS `user_phone`, `schedules`.`user_email` AS `user_email`,
        NULLIF(`schedules`.`Game1`,0) AS `Game1`, NULLIF(`schedules`.`Game2`,0) AS `Game2`, NULLIF(`schedules`.`Game3`,0) AS `Game3`, NULLIF(`schedules`.`Game4`,0) AS `Game4`, NULLIF(`schedules`.`Game5`,0) AS `Game5`,
        `schedules`.`travel` AS `Travel`, `Times`.`T_desc` AS `T_desc`, `Times`.`T_ID` AS `t_ID`, `Groups`.`GP_name` AS `GP_name`, `Courts`.`Crt_name` AS `Crt_name`,
        CASE WHEN `schedules`.`Game1` IS NULL AND `schedules`.`Game2` IS NULL AND `schedules`.`Game3` IS NULL AND `schedules`.`Game4` IS NULL AND `schedules`.`Game5` IS NULL
             THEN NULL
             ELSE COALESCE(`schedules`.`Game1`,0) + COALESCE(`schedules`.`Game2`,0) + COALESCE(`schedules`.`Game3`,0) + COALESCE(`schedules`.`Game4`,0) + COALESCE(`schedules`.`Game5`,0)
        END AS `Score`
        FROM (((`$table` `schedules` join `Times` on(`schedules`.`time_id` = `Times`.`T_ID`)) join `Groups` on(`schedules`.`group_id` = `Groups`.`GP_ID`)) join `Courts` on(`schedules`.`Crt_ID` = `Courts`.`Crt_ID`)) ORDER BY `schedules`.`Sequence` ASC
    " );
}
