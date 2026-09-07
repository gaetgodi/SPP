<?php
/* =========================================================
   Report Registry
   Version: 1.2.0
   Date: 2026-09-07

   Changes from 1.1.0:
   - Added spp_report_membership(): the full, unfiltered membership
     table (all 452 rows, no WHERE) -- replaces the WPDA "Club
     Membership list" app (app_id=25). Same 11 columns/labels/order
     that app currently renders, plus ClubRating and DUPR (which that
     app's own column-selection step had marked selected but never
     actually rendered -- see that investigation). Every column
     sortable=true, a deliberate improvement over the WPDA app (which
     only allowed sorting by last_name).
   - A report definition may now optionally return a 'default_sort' key
     -- both the [spp_report] shortcode handler below and the Report
     Generator admin screen's preview (spp-report-generator-admin.php)
     now prefer it over the old Rank-if-present-else-first-column
     heuristic, falling back to that heuristic when a definition
     doesn't set one. Needed because spp_report_membership() has to
     default-sort by last_name despite also having a Rank column (which
     the old heuristic would have picked unconditionally) --
     ladder_ratings is unaffected, it still gets 'Rank' via the same
     fallback path as before, unchanged. spp_render_report_table()
     itself only sorts by one key at a time; membership's query is
     written with ORDER BY last_name, first_name so that PHP 8's stable
     usort(), sorting by last_name alone via default_sort, preserves
     that first_name tie-break for members sharing a last name --
     reproducing the WPDA app's two-key default sort without teaching
     the shared renderer a new sorting concept.

   PURPOSE:
   Small registry mapping a report name (used as the [spp_report
   table="..."] shortcode attribute) to a definition function. Each
   definition function owns its own data source entirely -- this file
   and spp-report-table.php know nothing about where any report's rows
   come from; they only know how to look up a name and hand the result
   to the shared renderer.

   Adding a new report: write a spp_report_<name>() function that
   returns ['columns' => [...], 'rows' => [...]] (same shapes
   spp_render_report_table() expects), then add one line to
   $spp_report_registry below. No other file needs to change.

   CALLED FROM:
     [spp_report table="ladder_ratings"] embedded on a page. Read-only
     -- this shortcode and every definition function below only SELECT;
     none of them write to the database.

   ACCESS: no capability check here deliberately -- matches the
   existing pattern for read-only, non-admin-tool reports elsewhere in
   this theme (e.g. spp_rank_history, spp_scores_events_dropdown), and
   the source query is filtered to Ladder='Yes' members only, not
   anything sensitive beyond what's already shown on the public-facing
   Ladder - Master List / Club Membership list pages. If a future
   report needs restricting, gate it inside that report's own
   definition function (e.g. spp_is_admin_or_editor() check), not here
   -- keeps that decision visible next to the data it protects.

   UPDATE (2026-09-06) -- variant support, plus columns=/no_sort=
   shortcode attributes, added for the new Report Generator admin
   screen (inc/spp-report-generator-admin.php). Both are additive to
   the shortcode handler below; the registry and every existing
   definition function (spp_report_ladder_ratings included) are
   unchanged. Resolution order, per-request:
     1. Is `table` a saved variant name (spp_get_report_variant(),
        inc/spp-report-variants.php)? If so, its base_table/columns/
        no_sort become the starting point.
     2. Otherwise, `table` must be a registry name directly -- exactly
        today's behavior, unchanged.
     3. Explicit columns=/no_sort= attributes on the shortcode tag
        itself, if present, override whatever step 1 or 2 produced --
        an explicit attribute always wins over a saved variant's
        stored defaults.
   The effective column list is built by filtering/reordering the full
   report definition's own columns array to the requested key list
   (unknown keys ignored, not fatal); no_sort forces sortable=false on
   every effective column rather than teaching the renderer a new
   concept it doesn't need. default_sort is 'Rank' if that key survives
   into the effective list, else the first effective column -- fixes a
   latent bug (default_sort was unconditionally 'Rank' before, which
   would have silently no-op'd the initial sort for any future columns=
   selection that excludes Rank).
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * Report name => definition function name.
 * Each definition function takes no arguments and returns:
 *   ['columns' => <see spp_render_report_table()>, 'rows' => <see spp_render_report_table()>]
 */
$GLOBALS['spp_report_registry'] = array(
    'ladder_ratings' => 'spp_report_ladder_ratings',
    'membership'     => 'spp_report_membership',
);

/**
 * Ladder Ratings report: Rank, Club Rating, DUPR, First Name, Last Name
 * for every currently active-ladder (Ladder='Yes') member, sourced from
 * the membership table (already enriched with ClubRating/DUPR).
 */
function spp_report_ladder_ratings() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT Rank, ClubRating, DUPR, first_name, last_name
         FROM membership
         WHERE Ladder = 'Yes'",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Rank',       'label' => 'Rank',        'sortable' => true ),
        array( 'key' => 'ClubRating', 'label' => 'Club Rating', 'sortable' => true ),
        array( 'key' => 'DUPR',       'label' => 'DUPR',        'sortable' => true ),
        array( 'key' => 'first_name', 'label' => 'First Name',  'sortable' => true ),
        array( 'key' => 'last_name',  'label' => 'Last Name',   'sortable' => true ),
    );

    return array( 'columns' => $columns, 'rows' => $rows );
}

/**
 * Membership report: all tracked columns for every member, unfiltered
 * -- replaces the WPDA "Club Membership list" app (app_id=25, see that
 * investigation). Same 11 columns/order/labels that app renders today,
 * plus ClubRating and DUPR (selected in that app's own column picker
 * but never actually wired into its rendered column list). Every
 * column here is sortable, unlike the WPDA app (last_name only) --
 * intentional improvement, not an oversight.
 *
 * ORDER BY last_name, first_name here (not just 'default_sort' below)
 * matches the WPDA app's compound default sort -- see this file's
 * version-history block for why a single default_sort key plus this
 * query order reproduces that two-key sort exactly.
 */
function spp_report_membership() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT Rank, Rating, ClubRating, DUPR, Ladder, Tag,
                first_name, last_name, travel, user_phone, user_email,
                PCO, user_id
         FROM membership
         ORDER BY last_name ASC, first_name ASC",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Rank',       'label' => 'Rank',       'sortable' => true ),
        array( 'key' => 'Rating',     'label' => 'Rating',     'sortable' => true ),
        array( 'key' => 'ClubRating', 'label' => 'ClubRating', 'sortable' => true ),
        array( 'key' => 'DUPR',       'label' => 'DUPR',       'sortable' => true ),
        array( 'key' => 'Ladder',     'label' => 'Ldr',        'sortable' => true ),
        array( 'key' => 'Tag',        'label' => 'Tag',        'sortable' => true ),
        array( 'key' => 'first_name', 'label' => 'First Name', 'sortable' => true ),
        array( 'key' => 'last_name',  'label' => 'Last Name',  'sortable' => true ),
        array( 'key' => 'travel',     'label' => 'Travel',     'sortable' => true ),
        array( 'key' => 'user_phone', 'label' => 'Phone',      'sortable' => true ),
        array( 'key' => 'user_email', 'label' => 'Email',      'sortable' => true ),
        array( 'key' => 'PCO',        'label' => 'PCO',        'sortable' => true ),
        array( 'key' => 'user_id',    'label' => 'User',       'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => 'last_name',
    );
}

/**
 * Given a full report definition's columns array and a requested list
 * of keys (subset + order), build the effective columns array: each
 * requested key that actually exists in the definition, in the
 * requested order, with its original label/sortable metadata intact.
 * Unknown keys are silently dropped rather than fatal -- a stale
 * variant referencing a column a report definition later removed
 * should degrade, not break the page.
 */
function spp_report_filter_columns( array $full_columns, array $requested_keys ) {
    $by_key = array();
    foreach ( $full_columns as $col ) {
        $by_key[ $col['key'] ] = $col;
    }

    $effective = array();
    foreach ( $requested_keys as $key ) {
        if ( isset( $by_key[ $key ] ) ) {
            $effective[] = $by_key[ $key ];
        }
    }

    return $effective;
}

add_shortcode( 'spp_report', function( $atts ) {
    $atts = shortcode_atts( array(
        'table'   => '',
        'columns' => '',
        'no_sort' => '',
    ), $atts, 'spp_report' );

    $name     = sanitize_key( $atts['table'] );
    $registry = $GLOBALS['spp_report_registry'] ?? array();

    if ( $name === '' ) {
        return '<p>Unknown report.</p>';
    }

    // -- Step 1/2: resolve to a base report, via a saved variant or the registry directly --
    $variant      = spp_get_report_variant( $name );
    $base_table   = $variant ? $variant['base_table'] : $name;
    $columns_keys = $variant ? $variant['columns'] : null; // null = "use full definition's columns"
    $no_sort      = $variant ? $variant['no_sort'] : false;

    if ( ! isset( $registry[ $base_table ] ) || ! function_exists( $registry[ $base_table ] ) ) {
        return '<p>Unknown report.</p>';
    }

    $definition = call_user_func( $registry[ $base_table ] );
    if ( ! is_array( $definition ) || ! isset( $definition['columns'], $definition['rows'] ) ) {
        return '<p>Report misconfigured.</p>';
    }

    // -- Step 3: explicit shortcode attributes override the variant (or the lack of one) --
    if ( $atts['columns'] !== '' ) {
        $columns_keys = array_filter( array_map( 'trim', explode( ',', $atts['columns'] ) ), fn( $k ) => $k !== '' );
    }
    if ( $atts['no_sort'] !== '' ) {
        $no_sort = ( $atts['no_sort'] === '1' );
    }

    // -- Build the effective column list --
    $effective_columns = ( $columns_keys === null )
        ? $definition['columns']
        : spp_report_filter_columns( $definition['columns'], $columns_keys );

    if ( empty( $effective_columns ) ) {
        // A columns= list that matched nothing (all-unknown keys, or empty
        // after filtering) -- fall back to the full definition rather than
        // rendering a table with no columns at all.
        $effective_columns = $definition['columns'];
    }

    if ( $no_sort ) {
        $effective_columns = array_map( function( $col ) {
            $col['sortable'] = false;
            return $col;
        }, $effective_columns );
    }

    // A definition's own 'default_sort' wins if it survived into the
    // effective column list (it always will unless a columns= filter
    // dropped it); otherwise fall back to the original Rank-if-present
    // heuristic, unchanged from before this key existed.
    $effective_keys       = array_column( $effective_columns, 'key' );
    $definition_default   = $definition['default_sort'] ?? null;
    $default_sort         = ( $definition_default !== null && in_array( $definition_default, $effective_keys, true ) )
        ? $definition_default
        : ( in_array( 'Rank', $effective_keys, true ) ? 'Rank' : ( $effective_keys[0] ?? '' ) );

    ob_start();
    spp_render_report_table( $effective_columns, $definition['rows'], array(
        'id'               => $name,
        'default_sort'     => $default_sort,
        'default_dir'      => 'asc',
        'per_page_options' => array( 25, 50, 100, 'All' ),
        'default_per_page' => 50,
    ) );
    return ob_get_clean();
} );
