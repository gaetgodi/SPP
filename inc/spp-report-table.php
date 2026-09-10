<?php
/* =========================================================
   Shared Report Table Renderer
   Version: 1.5.0
   Date: 2026-09-10

   Changes from 1.4.0:
   - Folded the inline-editing CSS (editable/discrepancy cell
     backgrounds, the editable input's hover/focus state, its saving
     opacity, and its success/error flash colors) into the
     --spp-report-* customization system, same as everything else this
     table renders -- it was added hardcoded alongside the edit
     feature and never wired in. Eight new custom properties, added to
     the same :where(.spp-report-table) default block as the existing
     13, each one's default exactly reproducing today's hardcoded
     value so this is a styling no-op until someone actually overrides
     one: --spp-report-editable-bg (#fff8cc), --spp-report-discrepancy-bg
     (#ffe8c2), --spp-report-discrepancy-editable-bg (#ffe0a8),
     --spp-report-edit-focus-border (#bbb), --spp-report-edit-focus-bg
     (#fff) -- covers both :hover and :focus, which already shared one
     rule -- --spp-report-edit-saving-opacity (0.6),
     --spp-report-edit-success-bg (#c6efce), --spp-report-edit-error-bg
     (#ffc7ce). Documented in the Report Generator's CSS Customization
     Reference (spp-report-generator-admin.php) alongside the other 13,
     with matching style-editor controls. Deliberately NOT exposed:
     the editable input's own box sizing (min-width/max-width/margin/
     padding/border-radius) -- implementation plumbing to sit flush in
     the cell, not a look choice, same reasoning this file's 1.1.0 CSS
     Customization Reference note already applies to other internal
     details -- and the 1.2s flash duration, since this system
     customizes appearance, not timing.

   Changes from 1.1.0:
   - BUG FIX (found via today's read-only audit): $base_url only ever
     stripped THIS render's own 4 id-prefixed params
     (remove_query_arg() on $p_sort/$p_dir/$p_per_page/$p_paged) -- any
     OTHER report/variant's leftover params from a time this page's
     embedded [spp_report table=""] pointed at something else just rode
     along in $base_url untouched, and every sort/pagination link this
     render then generated carried them forward forever. FIXED: also
     strip any $_GET key suffixed _sort/_dir/_per_page/_paged that
     isn't this render's own -- confirmed both live [spp_report]
     callers (Ranks & Ratings, Membership list) embed at most one
     report each, so "every other suffixed param on this URL" and
     "every other report/variant's own leftover params" are the same
     set; confirmed no other file in this theme uses a $_GET key with
     any of these four suffixes, so this can't clobber unrelated
     query-string state.
   - Found a SECOND leak of the same bug while testing the fix above:
     the "Rows per page" <form>'s own hidden-field loop re-echoes every
     current $_GET key except its own per_page/paged as a hidden field
     (to preserve sort/etc. across a per-page change) -- an old
     report/variant's leftover params kept propagating right back in
     through there even with $base_url fixed, since that loop builds
     its own field list independently. Fixed with the same
     $stale_params exclusion.

   Changes from 1.0.3:
   - Fixed --spp-report-* custom properties (header-bg, radius, etc.)
     silently not applying when set via Divi's Custom CSS field on a
     real front-end page, despite working in the Report Generator admin
     preview. Root cause: this file's own default declaration,
     `.spp-report-table { --spp-report-header-bg: #2c3e50; ... }`, and a
     page-level Divi override share the exact same selector/specificity
     (0,1,0) -- a tie the cascade then breaks by source order. Divi's
     module/row/page Custom CSS compiles into a stylesheet that loads in
     <head>; this file's own <style> block is echoed inline every time
     spp_render_report_table() runs, inside the page body -- always
     later in the document -- so the default always won, unconditionally,
     regardless of what was pasted into Divi. (The admin preview never
     hit this: spp_render_report_style_editor_script() deliberately
     places its live-preview <style> tag AFTER this file's base <style>
     specifically to win this same tie via order -- see that function's
     own docblock. That's a preview-only mechanism; nothing analogous
     existed for the real shortcode render path.)
     Fixed by wrapping ONLY the --spp-report-* default assignments in
     `:where(.spp-report-table) { ... }` -- :where() zeroes the
     specificity of whatever it wraps, so this default block now loses
     any specificity tie unconditionally, regardless of source order, to
     literally any other rule that also targets `.spp-report-table`
     (Divi Custom CSS included) and sets the same property. Deliberately
     NOT applied to:
       - The rest of this same rule (font-family, font-size, max-width)
         -- those aren't part of the bug being fixed, and lowering their
         specificity too could change how they cascade against unrelated
         page-level rules that happen to also target .spp-report-table
         and currently lose to it; left at normal specificity, unchanged.
       - The @media (max-width: 600px) block's own --spp-report-font-size
         / --spp-report-cell-padding re-declarations -- that block's
         whole job (see the CSS Customization Reference's "Mobile
         breakpoint" note) is to WIN over a desktop-scoped override at
         narrow viewports unless the override itself also targets that
         breakpoint; :where()-ing it would invert that, letting a
         desktop-only override always beat the mobile default instead.
         Left at normal specificity, exactly as documented/intended.
     spp_render_report_style_editor_script()'s existing order-based
     mechanism (placing its live-preview <style> after this file's base
     style) is unaffected and still needed: it wins ties against the
     *non-custom-property* declarations above (font-family/font-size/
     max-width, still normal specificity) for arbitrary hand-typed CSS
     in that panel that might target those, and is now simply redundant
     -- not conflicting -- for the --spp-report-* properties themselves,
     which win via specificity regardless of order after this fix. Left
     in place rather than simplified away: removing it would only ever
     matter for the properties it's now redundant for, and keeping it
     costs nothing.

   Changes from 1.0.2:
   - Fixed the "Rows per page" <select>'s native dropdown arrow
     rendering on top of the selected number (most visible on short
     values like "10"/"25"): equal 4px/6px left-right padding left no
     room for the browser's own arrow icon inside the select's
     shrink-to-fit width. Widened the right padding to 22px (arrow
     clearance) and kept 8px on the left -- styling only, no behavior
     change. Reported against the Report Generator admin preview but
     this control is shared with the live [spp_report] shortcode too,
     so both are fixed by the one change.

   Changes from 1.0.1:
   - Added three more customization points, same pattern as
     --spp-report-max-width (variable default matches the prior
     hardcoded/inherited behavior exactly, so this is styling-only):
       --spp-report-radius (default: 0) -- on .spp-report-table-scroll,
         not the <table> itself, so overflow-x:auto's clipping box
         actually rounds the visible corners (border-radius on a
         border-collapse table doesn't reliably clip per-browser).
       --spp-report-margin (default: 0) -- on table.spp-report-table-grid
         itself (not the outer .spp-report-table wrapper, which is a
         full-width block and wouldn't visibly center from margin:auto).
         Documented in the admin screen as a fallback for centering when
         Divi's own row/column alignment isn't available (e.g. a raw
         code/text module); Divi's native alignment is the preferred way.
       --spp-report-header-weight (default: bold) / --spp-report-header-transform
         (default: none) -- both formalize the header <th>'s existing
         rendering (bold is the browser UA-stylesheet default for th,
         not previously an explicit rule here) into explicit,
         overridable declarations.
   - Added a permanent "CSS Customization Reference" section to the
     Report Generator admin screen (spp-report-generator-admin.php)
     documenting all of the above.

   Changes from 1.0.0:
   - Added --spp-report-max-width custom property (default: none) and
     wired it into table.spp-report-table-grid's max-width, so a Divi
     module's per-module Custom CSS can cap the table's width by
     setting the variable on .spp-report-table, without needing
     !important to fight the existing width:auto !important /
     .entry-content table:not(.variations) specificity war. Default of
     none preserves the pre-existing content-sized rendering exactly.

   PURPOSE:
   Generic, data-agnostic table renderer for member-facing reports:
   sortable column headers, per-page selection, and pagination, all
   driven by URL query parameters read via $_GET (?sort=&dir=&per_page=
   &paged=) rather than JavaScript -- matching this codebase's existing
   pattern for the group-selector dropdowns and similar controls (plain
   GET forms / links, no AJAX). Knows nothing about where its rows come
   from: the caller fetches and passes in $rows as a plain array of
   associative arrays, and this file does no querying of its own.

   USAGE:
     spp_render_report_table(
         $columns = [
             ['key' => 'Rank',       'label' => 'Rank',       'sortable' => true, 'default_visible' => true],
             ['key' => 'first_name', 'label' => 'First Name', 'sortable' => true, 'default_visible' => true],
             ...
         ],
         $rows = [ ['Rank' => 1, 'first_name' => 'Jane', ...], ... ],
         $args = [
             'default_sort'      => 'Rank',
             'default_dir'       => 'asc',
             'per_page_options'  => [25, 50, 100, 'All'],
             'default_per_page'  => 50,
         ]
     );

   Sorting/pagination state lives entirely in the URL, so headers are
   plain links and the per-page control is a plain <select> inside a
   GET <form> with no onchange JS -- reload-based, same discipline as
   the rest of this theme. A unique query-param prefix (derived from a
   'id' arg, default 'rpt') keeps multiple report tables on the same
   page from colliding with each other's sort/page state.

   NOT this file's job: fetching data, filtering by any business rule,
   access control. Callers (e.g. spp-reports.php) own all of that.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * Render a generic sortable, paginated report table.
 *
 * @param array $columns Each entry: ['key'=>string, 'label'=>string,
 *                        'sortable'=>bool, 'default_visible'=>bool,
 *                        'editable'=>bool]. 'editable' defaults to false.
 * @param array $rows    Plain array of associative arrays (row data),
 *                        already fetched by the caller.
 * @param array $args    Optional: default_sort, default_dir (asc/desc),
 *                        per_page_options (array, e.g. [25,50,100,'All']),
 *                        default_per_page, id (string, param prefix,
 *                        default 'rpt'), edit (array with 'table' and
 *                        'key_column' for persistence).
 */
function spp_render_report_table( array $columns, array $rows, array $args = array() ) {

    $id = isset( $args['id'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $args['id'] ) ) : 'rpt';
    $edit_report = isset( $args['edit_report'] )
    ? sanitize_text_field( (string) $args['edit_report'] )
    : $id;
    if ( $id === '' ) $id = 'rpt';

    $p_sort     = "{$id}_sort";
    $p_dir      = "{$id}_dir";
    $p_per_page = "{$id}_per_page";
    $p_paged    = "{$id}_paged";

    $column_keys = array_column( $columns, 'key' );

    $default_sort     = $args['default_sort']     ?? ( $column_keys[0] ?? '' );
    $default_dir      = ( ( $args['default_dir'] ?? 'asc' ) === 'desc' ) ? 'desc' : 'asc';
    $per_page_options = $args['per_page_options']  ?? array( 25, 50, 100, 'All' );
    $default_per_page = $args['default_per_page']  ?? ( $per_page_options[0] ?? 25 );

    // -- Optional inline editing --------------------------------------------
    $edit_config = ( isset( $args['edit'] ) && is_array( $args['edit'] ) )
        ? $args['edit']
        : array();

    $edit_table = isset( $edit_config['table'] ) ? (string) $edit_config['table'] : '';
    $edit_key_column = isset( $edit_config['key_column'] ) ? (string) $edit_config['key_column'] : '';

    $can_edit = (
        $edit_table !== ''
        && $edit_key_column !== ''
        && function_exists( 'spp_is_admin_or_editor' )
        && spp_is_admin_or_editor()
    );

    $editable_columns = array();
    if ( $can_edit ) {
        foreach ( $columns as $col ) {
            if ( ! empty( $col['editable'] ) && isset( $col['key'] ) ) {
                $editable_columns[ $col['key'] ] = array(
                    'type'     => isset( $col['edit_type'] ) ? sanitize_key( $col['edit_type'] ) : 'text',
                    'nullable' => ! empty( $col['edit_nullable'] ),
                    'decimals' => isset( $col['edit_decimals'] ) ? max( 0, min( 6, (int) $col['edit_decimals'] ) ) : 2,
                );
            }
        }
        if ( empty( $editable_columns ) ) {
            $can_edit = false;
        }
    }

    $edit_nonce = $can_edit ? wp_create_nonce( 'spp_report_edit' ) : '';

    // -- Read state from the URL --------------------------------------------
    $sort = isset( $_GET[ $p_sort ] ) ? sanitize_text_field( wp_unslash( $_GET[ $p_sort ] ) ) : $default_sort;
    if ( ! in_array( $sort, $column_keys, true ) ) $sort = $default_sort;

    $dir = isset( $_GET[ $p_dir ] ) && $_GET[ $p_dir ] === 'desc' ? 'desc' : $default_dir;

    $per_page_raw = isset( $_GET[ $p_per_page ] ) ? sanitize_text_field( wp_unslash( $_GET[ $p_per_page ] ) ) : (string) $default_per_page;
    $valid_options = array_map( 'strval', $per_page_options );
    if ( ! in_array( $per_page_raw, $valid_options, true ) ) $per_page_raw = (string) $default_per_page;
    $per_page = ( $per_page_raw === 'All' ) ? null : max( 1, (int) $per_page_raw );

    $paged = isset( $_GET[ $p_paged ] ) ? max( 1, (int) $_GET[ $p_paged ] ) : 1;

    // -- Sort -----------------------------------------------------------------
    if ( $sort !== '' ) {
        usort( $rows, function( $a, $b ) use ( $sort, $dir ) {
            $av = $a[ $sort ] ?? '';
            $bv = $b[ $sort ] ?? '';
            if ( is_numeric( $av ) && is_numeric( $bv ) ) {
                $cmp = ( (float) $av ) <=> ( (float) $bv );
            } else {
                $cmp = strcasecmp( (string) $av, (string) $bv );
            }
            return $dir === 'desc' ? -$cmp : $cmp;
        } );
    }

    // -- Paginate ---------------------------------------------------------------
    $total_rows = count( $rows );
    if ( $per_page === null ) {
        $page_rows  = $rows;
        $total_pages = 1;
        $paged = 1;
    } else {
        $total_pages = max( 1, (int) ceil( $total_rows / $per_page ) );
        $paged       = min( $paged, $total_pages );
        $page_rows   = array_slice( $rows, ( $paged - 1 ) * $per_page, $per_page );
    }

    // -- URL helpers --------------------------------------------------------
    // Strip this render's own 4 id-prefixed params, AND any OTHER
    // report/variant's leftover _sort/_dir/_per_page/_paged param still
    // sitting on the URL -- a Divi page's embedded [spp_report table=""]
    // can change to a different report or variant over time, and
    // without this, an old id's params (e.g. membershipvariant1_per_page
    // after the page switches to table="membership-variant-2") just
    // ride along in every link this render generates, forever -- see
    // this file's version-history block for the full story. In practice
    // a page embeds at most one [spp_report] (confirmed against both
    // live callers today), so "every param on this URL suffixed
    // _sort/_dir/_per_page/_paged that isn't this render's own" and
    // "every OTHER report/variant's own leftover params" are the same
    // set -- stripped by suffix rather than needing to track what the
    // old id even was. Confirmed no other file in this theme uses a
    // $_GET key with any of these four suffixes, so this can't clobber
    // unrelated query-string state.
    $own_params   = array( $p_sort, $p_dir, $p_per_page, $p_paged );
    $stale_params = array();
    foreach ( array_keys( $_GET ) as $key ) {
        if ( in_array( $key, $own_params, true ) ) continue;
        if ( preg_match( '/_(sort|dir|per_page|paged)$/', $key ) ) {
            $stale_params[] = $key;
        }
    }
    $base_url = remove_query_arg( array_merge( $own_params, $stale_params ) );

    $sort_link = function( $key ) use ( $base_url, $p_sort, $p_dir, $sort, $dir ) {
        $new_dir = ( $sort === $key && $dir === 'asc' ) ? 'desc' : 'asc';
        return esc_url( add_query_arg( array( $p_sort => $key, $p_dir => $new_dir ), $base_url ) );
    };

    $page_link = function( $target_page ) use ( $base_url, $p_sort, $p_dir, $p_per_page, $p_paged, $sort, $dir, $per_page_raw ) {
        return esc_url( add_query_arg( array(
            $p_sort     => $sort,
            $p_dir      => $dir,
            $p_per_page => $per_page_raw,
            $p_paged    => $target_page,
        ), $base_url ) );
    };

    // -- Render ---------------------------------------------------------------
    ?>
    <style>
        /* :where() zeroes specificity on this block ONLY -- these are
           just fallback defaults for a page-level override (e.g. Divi
           Custom CSS) to beat unconditionally, regardless of which of
           the two loads/prints first in the document. See this file's
           1.1.0 changelog entry for the full cascade-tie story this
           fixes. Real properties (font-family/font-size/max-width)
           stay on the normal-specificity selector below, unchanged. */
        :where(.spp-report-table) {
            --spp-report-header-bg: #2c3e50;
            --spp-report-header-text: #ffffff;
            --spp-report-border-color: #ddd;
            --spp-report-row-alt-bg: #f5f5f5;
            --spp-report-row-hover-bg: #eef7f6;
            --spp-report-font-size: 13px;
            --spp-report-cell-padding: 5px 10px;
            --spp-report-link-color: #3766AB;
            --spp-report-max-width: none; /* default preserves today's content-sized
                                              behavior below -- see table.spp-report-table-grid */
            --spp-report-radius: 0;
            --spp-report-margin: 0;
            --spp-report-header-weight: bold;
            --spp-report-header-transform: none;
            --spp-report-editable-bg: #fff8cc;
            --spp-report-discrepancy-bg: #ffe8c2;
            --spp-report-discrepancy-editable-bg: #ffe0a8;
            --spp-report-edit-focus-border: #bbb;
            --spp-report-edit-focus-bg: #fff;
            --spp-report-edit-saving-opacity: 0.6;
            --spp-report-edit-success-bg: #c6efce;
            --spp-report-edit-error-bg: #ffc7ce;
        }
        .spp-report-table {
            font-family: Arial, sans-serif;
            font-size: var(--spp-report-font-size);
            max-width: 100%;
        }
        .spp-report-table .spp-report-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
        }
        .spp-report-table .spp-report-per-page label {
            margin-right: 6px;
        }
        .spp-report-table .spp-report-per-page select {
            font-size: var(--spp-report-font-size);
            /* Right padding wider than left -- clears the browser's own
               dropdown-arrow icon, which otherwise overlaps a short
               value like "10" when the select's shrink-to-fit width is
               only as wide as its padding + content. */
            padding: 4px 22px 4px 8px;
        }
        .spp-report-table .spp-report-summary {
            color: #666;
        }
        .spp-report-table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            max-width: 100%;
            /* border-radius lives here (not on table.spp-report-table-grid)
               so this box's own overflow clipping is what actually rounds
               the visible corners -- border-radius on a border-collapse
               table doesn't reliably clip its own cell borders/backgrounds
               to rounded corners across browsers. */
            border-radius: var(--spp-report-radius);
        }
        table.spp-report-table-grid {
            border-collapse: collapse;
            table-layout: auto;
            /* width:auto (not 100%) -- let the table size to its own
               content instead of always stretching to fill whatever
               container it's given. The container varies a lot page to
               page (a normal ~1024-1280px boxed Divi row on most tool
               pages, vs. a full-width, edge-to-edge row on some report
               pages) and forcing 100% on a table with only a handful of
               short columns produced wide, sparse cells rather than
               compact ones sized to their actual content.
               !important is required here: the theme's own global
               stylesheet has a higher-specificity rule,
               ".entry-content table:not(.variations) { width:100%; }",
               that otherwise wins the cascade and silently overrides
               table-layout:auto's effect -- same fight this codebase
               already has elsewhere against theme table defaults (see
               spp-rank-history.php's .rh-table th color override).
               .spp-report-table-scroll above still guarantees no column
               is ever clipped if natural content width exceeds a narrow
               container (see @media block below).

               max-width is driven by --spp-report-max-width (default
               none, i.e. no cap -- identical to today's rendering) so a
               Divi module's per-module Custom CSS can constrain it by
               setting the variable on .spp-report-table, e.g.:
                 .spp-report-table { --spp-report-max-width: 700px; }
               with no !important of its own needed: !important below
               only has to win the cascade fight against the theme's
               table default for the *max-width property itself*, not
               against the variable assignment, since a plain (non-
               !important) declaration of a custom property is a
               different property entirely and always wins for whatever
               reads it via var(). */
            width: auto !important;
            max-width: var(--spp-report-max-width) !important;
            /* margin lives on the table element itself, not the outer
               .spp-report-table div -- that div is a full-width block,
               so margin:auto on it wouldn't visibly center anything.
               The table's own used width comes from the automatic
               table-layout algorithm (shrink-to-fit, per width:auto
               above), which is exactly the case CSS auto-margins center
               within the containing block -- see the note in the admin
               screen's reference: Divi's own row/column centering is
               the preferred way to center this table; this variable is
               documented there as the fallback for contexts without
               that control (e.g. a raw code/text module). */
            margin: var(--spp-report-margin);
        }
        table.spp-report-table-grid th,
        table.spp-report-table-grid td {
            padding: var(--spp-report-cell-padding);
            border-bottom: 1px solid var(--spp-report-border-color);
            text-align: left;
            white-space: nowrap;
        }
        table.spp-report-table-grid thead th {
            background: var(--spp-report-header-bg);
            color: var(--spp-report-header-text);
            font-weight: var(--spp-report-header-weight);
            text-transform: var(--spp-report-header-transform);
        }
        table.spp-report-table-grid thead th a {
            color: var(--spp-report-header-text);
            text-decoration: none;
        }
        table.spp-report-table-grid thead th a:hover {
            text-decoration: underline;
        }
        table.spp-report-table-grid tbody tr:nth-child(even) {
            background: var(--spp-report-row-alt-bg);
        }
        table.spp-report-table-grid tbody tr:hover {
            background: var(--spp-report-row-hover-bg);
        }
        .spp-report-table .spp-report-pagination {
            display: flex;
            gap: 6px;
            align-items: center;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .spp-report-table .spp-report-pagination a,
        .spp-report-table .spp-report-pagination span {
            padding: 4px 9px;
            border: 1px solid var(--spp-report-border-color);
            border-radius: 4px;
            text-decoration: none;
            color: var(--spp-report-link-color);
        }
        .spp-report-table .spp-report-pagination span.current {
            background: var(--spp-report-header-bg);
            color: var(--spp-report-header-text);
            border-color: var(--spp-report-header-bg);
        }
        .spp-report-table .spp-report-empty {
            padding: 16px;
            color: #666;
            font-style: italic;
        }

        /* Inline report editing */
        .spp-report-table td.spp-report-editable-cell {
            background-color: var(--spp-report-editable-bg);
        }
        .spp-report-table td.spp-report-discrepancy {
            background-color: var(--spp-report-discrepancy-bg);
        }
        .spp-report-table td.spp-report-discrepancy.spp-report-editable-cell {
            background-color: var(--spp-report-discrepancy-editable-bg);
        }
        .spp-report-table .spp-report-editable {
            box-sizing: border-box;
            min-width: 4.5em;
            width: 100%;
            max-width: 12em;
            margin: -2px 0;
            padding: 3px 6px;
            border: 1px solid transparent;
            border-radius: 3px;
            background: transparent;
            font: inherit;
            color: inherit;
        }
        .spp-report-table .spp-report-editable:hover,
        .spp-report-table .spp-report-editable:focus {
            border-color: var(--spp-report-edit-focus-border);
            background: var(--spp-report-edit-focus-bg);
            outline: none;
        }
        .spp-report-table .spp-report-editable.spp-edit-saving {
            opacity: var(--spp-report-edit-saving-opacity);
        }
        .spp-report-table .spp-edit-success {
            animation: spp-report-edit-success 1.2s ease-out;
        }
        .spp-report-table .spp-edit-error {
            animation: spp-report-edit-error 1.2s ease-out;
        }
        @keyframes spp-report-edit-success {
            0% { background-color: var(--spp-report-edit-success-bg); }
            100% { background-color: transparent; }
        }
        @keyframes spp-report-edit-error {
            0% { background-color: var(--spp-report-edit-error-bg); }
            100% { background-color: transparent; }
        }

        @media (max-width: 600px) {
            .spp-report-table {
                --spp-report-font-size: 12px;
                --spp-report-cell-padding: 4px 7px;
            }
            .spp-report-table .spp-report-controls {
                flex-direction: column;
                align-items: flex-start;
            }
            .spp-report-table .spp-report-pagination a,
            .spp-report-table .spp-report-pagination span {
                padding: 6px 10px; /* kept larger than the cell padding above -- these are tap targets, not data cells */
            }
        }
    </style>

    <div class="spp-report-table"<?php if ( $can_edit ) : ?>
        data-spp-edit-report="<?php echo esc_attr( $edit_report ); ?>"
        data-spp-edit-nonce="<?php echo esc_attr( $edit_nonce ); ?>"
        data-spp-edit-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
    <?php endif; ?>>
        <div class="spp-report-controls">
            <div class="spp-report-summary">
                <?php
                if ( $total_rows === 0 ) {
                    echo '0 results';
                } elseif ( $per_page === null ) {
                    echo esc_html( "Showing all {$total_rows} result" . ( $total_rows === 1 ? '' : 's' ) );
                } else {
                    $start = ( $paged - 1 ) * $per_page + 1;
                    $end   = min( $paged * $per_page, $total_rows );
                    // HTML entity, not a \x escape -- same reasoning as the sort
                    // arrows below: consistent, not dependent on the source
                    // file's own byte encoding surviving future edits intact.
                    echo esc_html( "Showing {$start}\xE2\x80\x93{$end} of {$total_rows}" );
                    // Note: esc_html() would strip a literal '&ndash;' entity here,
                    // so the UTF-8 byte sequence (valid inside this double-quoted
                    // string) is kept as-is -- unlike the arrows above, this one
                    // was already correct; not changed further.
                }
                ?>
            </div>
            <form method="get" class="spp-report-per-page">
                <?php foreach ( $_GET as $gk => $gv ) :
                    if ( in_array( $gk, array( $p_per_page, $p_paged ), true ) ) continue;
                    // Same stale-param stripping as $base_url above -- this
                    // form re-submits every other current $_GET key as a
                    // hidden field (to preserve sort/etc. across a per-page
                    // change), which was the second place an old report/
                    // variant's leftover params kept propagating even after
                    // the $base_url fix.
                    if ( in_array( $gk, $stale_params, true ) ) continue;
                    if ( is_array( $gv ) ) continue;
                    ?>
                    <input type="hidden" name="<?php echo esc_attr( $gk ); ?>" value="<?php echo esc_attr( $gv ); ?>">
                <?php endforeach; ?>
                <label for="<?php echo esc_attr( $p_per_page ); ?>">Rows per page</label>
                <select name="<?php echo esc_attr( $p_per_page ); ?>" id="<?php echo esc_attr( $p_per_page ); ?>" onchange="this.form.submit()">
                    <?php foreach ( $per_page_options as $opt ) :
                        $opt_str = (string) $opt;
                        ?>
                        <option value="<?php echo esc_attr( $opt_str ); ?>" <?php selected( $per_page_raw, $opt_str ); ?>>
                            <?php echo esc_html( $opt_str ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit">Go</button></noscript>
            </form>
        </div>

        <?php if ( $total_rows === 0 ) : ?>
            <div class="spp-report-empty">No results.</div>
        <?php else : ?>
            <div class="spp-report-table-scroll">
                <table class="spp-report-table-grid">
                    <thead>
                        <tr>
                            <?php foreach ( $columns as $col ) :
                                if ( isset( $col['default_visible'] ) && ! $col['default_visible'] ) continue;
                                $key      = $col['key'];
                                $label    = $col['label'] ?? $key;
                                $sortable = $col['sortable'] ?? false;
                                ?>
                                <th>
                                    <?php if ( $sortable ) : ?>
                                        <a href="<?php echo $sort_link( $key ); ?>">
                                            <?php echo esc_html( $label ); ?><?php
                                            if ( $sort === $key ) {
                                                // HTML entities, not \x escapes -- \x only expands inside
                                                // double-quoted PHP strings, and a literal-backslash bug
                                                // here would be easy to miss visually in a code review.
                                                echo $dir === 'asc' ? ' &#9650;' : ' &#9660;';
                                            }
                                            ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html( $label ); ?>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $page_rows as $row ) :
                            $row_edit_key = $can_edit ? (string) ( $row[ $edit_key_column ] ?? '' ) : '';
                            ?>
                            <tr<?php if ( $can_edit ) : ?> data-spp-edit-key="<?php echo esc_attr( $row_edit_key ); ?>"<?php endif; ?>>
                                <?php foreach ( $columns as $col ) :
                                    if ( isset( $col['default_visible'] ) && ! $col['default_visible'] ) continue;
                                    $key = $col['key'];
                                    $value = $row[ $key ] ?? '';
                                    ?>
                                    <?php
                                    // Highlight both calculation fields when their numeric
                                    // difference exceeds 1.99. Blank/NULL values are ignored.
                                    $is_discrepancy = false;
                                    if ( in_array( $key, array( 'RankCalc', 'RankCalc_Shadow' ), true ) ) {
                                        $calc       = $row['RankCalc'] ?? null;
                                        $calc_shadow = $row['RankCalc_Shadow'] ?? null;
                                        if ( $calc !== null && $calc !== '' && $calc_shadow !== null && $calc_shadow !== ''
                                            && is_numeric( $calc ) && is_numeric( $calc_shadow ) ) {
                                            $is_discrepancy = ( abs( (float) $calc - (float) $calc_shadow ) > 1.99 );
                                        }
                                    }
                                    $is_editable_cell = $can_edit && isset( $editable_columns[ $key ] );
                                    $cell_classes = array();
                                    if ( $is_editable_cell ) $cell_classes[] = 'spp-report-editable-cell';
                                    if ( $is_discrepancy ) $cell_classes[] = 'spp-report-discrepancy';
                                    ?>
                                    <td<?php if ( ! empty( $cell_classes ) ) : ?> class="<?php echo esc_attr( implode( ' ', $cell_classes ) ); ?>"<?php endif; ?>>
                                        <?php if ( $is_editable_cell ) : ?>
                                            <?php
                                            $edit_meta = $editable_columns[ $key ];
                                            $input_type = ( $edit_meta['type'] === 'integer' || $edit_meta['type'] === 'decimal' ) ? 'number' : 'text';
                                            $step = '';
                                            if ( $edit_meta['type'] === 'integer' ) {
                                                $step = '1';
                                            } elseif ( $edit_meta['type'] === 'decimal' ) {
                                                $step = '0.' . str_repeat( '0', max( 0, $edit_meta['decimals'] - 1 ) ) . '1';
                                            }
                                            ?>
                                            <input
                                                type="<?php echo esc_attr( $input_type ); ?>"
                                                class="spp-report-editable"
                                                value="<?php echo esc_attr( $value ); ?>"
                                                data-spp-edit-column="<?php echo esc_attr( $key ); ?>"
                                                data-spp-edit-original="<?php echo esc_attr( $value ); ?>"
                                                data-spp-edit-type="<?php echo esc_attr( $edit_meta['type'] ); ?>"
                                                data-spp-edit-nullable="<?php echo $edit_meta['nullable'] ? '1' : '0'; ?>"
                                                <?php if ( $step !== '' ) : ?>step="<?php echo esc_attr( $step ); ?>"<?php endif; ?>
                                                <?php if ( $edit_meta['type'] === 'decimal' || $edit_meta['type'] === 'integer' ) : ?>inputmode="decimal"<?php endif; ?>
                                                aria-label="<?php echo esc_attr( $col['label'] ?? $key ); ?>"
                                            >
                                        <?php else : ?>
                                            <?php echo esc_html( $value ); ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ( $per_page !== null && $total_pages > 1 ) : ?>
                <div class="spp-report-pagination">
                    <?php if ( $paged > 1 ) : ?>
                        <a href="<?php echo $page_link( $paged - 1 ); ?>">&laquo; Prev</a>
                    <?php endif; ?>
                    <?php
                    $window = 2;
                    for ( $i = 1; $i <= $total_pages; $i++ ) :
                        if ( $i !== 1 && $i !== $total_pages && abs( $i - $paged ) > $window ) {
                            if ( abs( $i - $paged ) === $window + 1 ) echo '<span>&hellip;</span>';
                            continue;
                        }
                        if ( $i === $paged ) :
                            ?>
                            <span class="current"><?php echo (int) $i; ?></span>
                        <?php else : ?>
                            <a href="<?php echo $page_link( $i ); ?>"><?php echo (int) $i; ?></a>
                        <?php endif;
                    endfor;
                    ?>
                    <?php if ( $paged < $total_pages ) : ?>
                        <a href="<?php echo $page_link( $paged + 1 ); ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ( $can_edit ) : ?>
    <script>
    (function () {
        'use strict';

        function sppInitReportEditing(root) {
            if (!root || root.dataset.sppEditBound === '1') {
                return;
            }
            root.dataset.sppEditBound = '1';

            root.querySelectorAll('.spp-report-editable').forEach(function (input) {
                input.addEventListener('change', function () {
                    sppSaveReportCell(root, input);
                });

                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        input.blur();
                    } else if (event.key === 'Escape') {
                        input.value = input.dataset.sppEditOriginal || '';
                        input.blur();
                    }
                });
            });
        }

        function sppSaveReportCell(root, input) {
            if (input.disabled) {
                return;
            }

            var row = input.closest('tr[data-spp-edit-key]');
            if (!row) {
                return;
            }

            var original = input.dataset.sppEditOriginal || '';
            var value = input.value;
            var column = input.dataset.sppEditColumn || '';
            var report = root.dataset.sppEditReport || '';
            var nonce = root.dataset.sppEditNonce || '';
            var ajaxUrl = root.dataset.sppEditAjax || '';

            if (!report || !nonce || !ajaxUrl || !column) {
                input.value = original;
                sppFlashEdit(input, false);
                return;
            }

            if (value === original) {
                return;
            }

            var formData = new FormData();
            formData.append('action', 'spp_save_report_cell');
            formData.append('nonce', nonce);
            formData.append('report', report);
            formData.append('key', row.dataset.sppEditKey || '');
            formData.append('column', column);
            formData.append('value', value);

            input.disabled = true;
            input.classList.add('spp-edit-saving');

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error(
                        data && data.data && data.data.message
                            ? data.data.message
                            : 'Save failed.'
                    );
                }

                var savedValue = (
                    data.data &&
                    Object.prototype.hasOwnProperty.call(data.data, 'value')
                ) ? String(data.data.value) : value;

                input.value = savedValue;
                input.dataset.sppEditOriginal = savedValue;
                sppFlashEdit(input, true);
            })
            .catch(function () {
                input.value = original;
                sppFlashEdit(input, false);
            })
            .finally(function () {
                input.disabled = false;
                input.classList.remove('spp-edit-saving');
            });
        }

        function sppFlashEdit(input, success) {
            input.classList.remove('spp-edit-success', 'spp-edit-error');
            void input.offsetWidth;
            input.classList.add(success ? 'spp-edit-success' : 'spp-edit-error');

            window.setTimeout(function () {
                input.classList.remove('spp-edit-success', 'spp-edit-error');
            }, 1300);
        }

        function sppInitAllReportEditing() {
            document.querySelectorAll('.spp-report-table[data-spp-edit-report]').forEach(
                sppInitReportEditing
            );
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', sppInitAllReportEditing);
        } else {
            sppInitAllReportEditing();
        }
    }());
    </script>
    <?php endif; ?>

    <?php
}
