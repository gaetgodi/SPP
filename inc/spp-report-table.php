<?php
/* =========================================================
   Shared Report Table Renderer
   Version: 1.0.2
   Date: 2026-09-07

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
 *                        'sortable'=>bool, 'default_visible'=>bool].
 *                        'default_visible' defaults to true if omitted.
 * @param array $rows    Plain array of associative arrays (row data),
 *                        already fetched by the caller.
 * @param array $args    Optional: default_sort, default_dir (asc/desc),
 *                        per_page_options (array, e.g. [25,50,100,'All']),
 *                        default_per_page, id (string, param prefix,
 *                        default 'rpt').
 */
function spp_render_report_table( array $columns, array $rows, array $args = array() ) {

    $id = isset( $args['id'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $args['id'] ) ) : 'rpt';
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
    $base_url = remove_query_arg( array( $p_sort, $p_dir, $p_per_page, $p_paged ) );

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
        .spp-report-table {
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
            padding: 4px 6px;
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

    <div class="spp-report-table">
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
                        <?php foreach ( $page_rows as $row ) : ?>
                            <tr>
                                <?php foreach ( $columns as $col ) :
                                    if ( isset( $col['default_visible'] ) && ! $col['default_visible'] ) continue;
                                    $key = $col['key'];
                                    ?>
                                    <td><?php echo esc_html( $row[ $key ] ?? '' ); ?></td>
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
    <?php
}
