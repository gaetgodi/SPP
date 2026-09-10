<?php
/* =========================================================
   Report Generator — Admin Screen
   Version: 2.5.0
   Date: 2026-09-10

   Changes from 2.4.0:
   - BUG FIX (found via a read-only investigation into whether the
     preview and the saved DB state can ever diverge): a failed
     update-in-place (2.4.0's "Update Preview & Save" doubling) left
     $selected_keys/$no_sort/$per_page/$css at whatever was just
     attempted-and-rejected, and both the preview and the column-
     selection form itself rendered from those stale values -- looking
     exactly like a normal, successful state, with only the error
     banner above the form saying otherwise. Confirmed live: deleting a
     variant out from under a stale form submission (a real race --
     e.g. another admin's concurrent delete) produced a fully-rendered
     preview of the attempted new columns/settings for a variant that
     no longer existed at all, while the Configuration dropdown fell
     back to showing "Default" selected (since the gone variant isn't
     in $existing_variants any more) even as the button label still
     read 'Update Preview & Save to "<the deleted name>"' -- two parts
     of the same page disagreeing with each other.
     FIXED: on any spp_update_report_variant() failure,
     $existing_variants is refreshed and checked for whether
     $loaded_variant still exists at all; if not, $loaded_variant
     resets to '' (Default), exactly like the explicit delete_variant
     action already does. Either way, $include/$order/$no_sort/
     $per_page/$css are then re-seeded via spp_report_generator_seed_
     state() from that same fresh $existing_variants list -- the
     variant's actual current row if it's still there, or the report's
     bare defaults if it's gone -- and $selected_keys is rebuilt from
     the re-seeded $include/$order (new spp_report_generator_build_
     selected_keys(), split out of this file's own inline logic so it
     can be called a second time here). The column-selection form's own
     checkboxes/order inputs re-render from the same re-seeded
     $include/$order too, not just the preview panel -- the whole page
     now reflects one consistent, actually-true state after a failed
     update, never the rejected attempt.

   Changes from 2.3.0:
   - "Update Preview" now doubles as update-in-place when a variant is
     currently loaded (tracked via the existing $loaded_variant hidden
     field -- no new state needed): clicking it both refreshes the
     preview (unchanged) AND overwrites that variant's stored row via
     the new spp_update_report_variant() (inc/spp-report-variants.php
     1.3.0) -- true update, no new name, no new row. When nothing is
     loaded (Default, or a fresh/never-saved custom config), the
     button behaves exactly as before -- preview only, no write --
     since there's nothing existing to update in that case.
   - Button label goes dynamic to make this unambiguous: plain
     "Update Preview" when nothing is loaded; "Update Preview & Save
     to "<variant-name>"" plus a one-line note underneath when a
     variant is loaded. "Save as New Variant" is untouched and
     unaffected -- still always creates a new auto-named row
     regardless of what's loaded, giving the same "Save" vs. "Save
     As" pattern once a variant is in context.

   Changes from 2.2.0:
   - BUG FIX (found via today's read-only audit, confirmed live against
     real data before fixing): spp_report_generator_match_variant()'s
     plain first-match linear scan meant that when two saved variants
     for the same report share identical columns/no_sort/per_page (a
     real live case: membership-variant-1 and membership-variant-2),
     the live shortcode could only ever show the alphabetically-first
     one's name, regardless of which was actually loaded or just
     saved. Separately, spp_report_generator_live_shortcode()'s
     bare-default carve-out unconditionally won over ANY variant match
     whenever the current config equaled the report's own bare
     defaults -- so a variant saved with that exact config (also a
     real live case: membership-variant-3) could never show its own
     shortcode at all, always falling through to the bare
     [spp_report table="<report>"] instead.
   - FIXED: both functions now take an optional $preferred_name --
     the variant actually in context (just saved, or loaded via the
     combined selector), passed as the already-existing $loaded_variant
     at the one call site. match_variant() checks it first (only
     counts if its own saved config still matches the current form
     state exactly) before falling back to the unchanged generic scan.
     live_shortcode()'s bare-default carve-out is now skipped
     specifically when the match IS the preferred variant, so a
     loaded/just-saved variant always shows its own name even if its
     config happens to equal bare defaults -- a merely coincidental
     match with no variant in context still defers to the bare
     shortcode exactly as before. Re-verified against live production
     data after the fix: all 4 existing membership variants (including
     the two that previously never showed their own name) now
     correctly resolve to their own shortcode.

   Changes from 2.1.1:
   - REVERSED the 2.1.0 diff-only decision for the style editor's CSS
     textarea, by explicit request: it now always shows all 13
     --spp-report-* properties with their current value (default or
     customized), a complete self-contained reference, rather than
     only the properties that differ. Control edits now always
     update their own line in place (updateVarInText()) instead of
     removing it when dialed back to default -- the diff-only removal
     path (removeVarFromText()/isControlDefault()) is gone.
   - Added a new ensureFullDump() step, run once on load: expands
     whatever starting text the server handed over (Default's empty
     rule, a loaded variant's saved snippet, or a preserved postback)
     into the complete 13-property dump before display, filling in
     only the properties the source text didn't already set. This is
     what keeps a variant saved diff-only under 2.1.0 fully compatible
     with the new always-full display: its saved (possibly partial)
     values are merged onto the documented defaults, producing the
     exact same effective style, just spelled out in full rather than
     partially. Saving a variant from now on stores the full dump the
     textarea already shows, going forward.
   - Added a "Reset to Defaults" button next to the textarea label:
     restores exactly the 13 known properties to the values in the
     CSS Customization Reference table below (resetAllToDefaults(),
     built the same way as ensureFullDump() -- both are just
     updateVarInText() run 13 times), leaving any other hand-typed
     content in the textarea (another selector, a comment) untouched.
   - None of this touches the live shortcode's own columns=/no_sort=/
     per_page= diff-only attribute logic above (a separate mechanism,
     unaffected) or the CSS Customization Reference's example
     snippets (correctly minimal/diff-style for pasting into Divi,
     which is a different context from this editor's own textarea).

   Changes from 2.1.0:
   - Added a placeholder + title (hover tooltip) to each of the style
     editor's five length-valued text inputs (max-width, radius, margin,
     font-size, cell-padding), e.g. "e.g. 8px" -- prompted by a real
     live bug: a Divi Custom CSS paste with --spp-report-radius:10 (no
     unit) is invalid CSS and silently no-ops. Both attributes carry the
     same hint text; title is what a user actually sees day-to-day since
     every one of these fields always has a value (its property's own
     default), so placeholder alone would never display under normal
     use -- kept anyway for the rare case of a field cleared outright.
     Hint text only, no new validation logic, no behavior change.

   Changes from 2.0.0:
   - CSS snippet is now diff-only, same principle as the live shortcode
     (spp_report_generator_live_shortcode()): the style editor's textarea
     only ever contains a --spp-report-* declaration for a property
     whose control value differs from that property's own default.
     Moving a control back to its default value removes the line
     entirely rather than writing the default back out explicitly.
     Starting state (Default configuration, nothing customized) is now
     an empty `.spp-report-table {\n}` rule instead of a full 13-property
     dump. Hand-typed textarea edits are unaffected -- this only changes
     what a *control* edit writes; the textarea itself still accepts
     (and round-trips) genuinely arbitrary CSS exactly as before.
   - CSS is now persisted (reversing this file's own 2.0.0 "purely
     client-side and ephemeral" decision, now that saved variants have
     somewhere to put it -- see inc/spp-report-variants.php 1.2.0's new
     css column). Saving a variant stores the textarea's current
     diff-only snippet alongside its columns/no_sort/per_page. The
     column-selection <form> doesn't itself contain the CSS textarea
     (it lives below that form, next to the preview it targets), so the
     textarea carries `name="css_snapshot" form="spp_rg_column_form"` --
     native HTML, associates it with that form for submission without
     JS or a mirrored hidden field. This also means an "Update Preview"
     click (not just Save) now round-trips the in-progress CSS text
     across the page reload, which it silently dropped before (back to
     the hardcoded default dump) -- a side effect of the same wiring,
     not a separately-requested fix, but a strict improvement.
   - Loading a variant (the "Configuration" selector) now repopulates
     the style editor's textarea from that variant's saved css (or the
     empty diff-only default, for "Default" / a variant saved before
     this feature existed). spp_render_report_style_editor() takes a
     new $initial_css param for this. Controls are resynced from
     whatever text ends up in the textarea -- the resync loop formerly
     inline inside the textarea's own 'input' handler is now a named
     function (spp_render_report_style_editor_script()'s
     resyncControlsFromText()) called both there and once on script
     init, so a server-rendered starting snippet (loaded variant or
     preserved postback) updates the controls immediately without
     waiting for a hand-typed edit -- same skip-if-not-representable
     behavior as before (color needs strict 6-digit hex, select needs
     an exact option match, everything else updates normally).
   - spp_report_generator_seed_state() returns a 5th element, $css, from
     the seeded variant (or '' for Default) -- same shape change
     pattern as the per_page addition in 2.0.0.

   Changes from 1.0.3:
   - CSS Customization Reference no longer prints unconditionally at
     the top. It now prints below the report-picker dropdown when no
     report is loaded yet, and below the rendered preview once one is.
   - Rows-per-page is now a variant-configurable setting (per_page,
     default 'All' -- see inc/spp-reports.php 1.3.0 /
     spp_report_per_page_choices()), selected via a <select> alongside
     no_sort, saved into spp_report_variants, and usable as a per_page=
     shortcode attribute.
   - The old "pick a report" GET form's job of also loading a variant
     is now a second, combined selector: "Default" (the report's own
     bare configuration) plus every existing variant for that base
     table (spp_get_report_variants_for_base()). Selecting one and
     clicking Load seeds the column/order/no_sort/per_page form state
     from it, for review/editing -- not read-only. A "Delete Selected"
     button sits next to it (same <select>, via a formmethod="post"
     override on an otherwise method="get" form -- see the render
     function for why that's safe) with a native confirm() before
     deleting; deleting the currently-loaded variant resets the form to
     Default.
   - Saving a variant no longer takes a manual name -- it's always a
     new variant, auto-named "{report}-variant-{N}" via
     spp_next_report_variant_name() (MAX-based, so a deleted variant's
     number is never reused). There is no update-in-place; "editing" a
     loaded variant and saving again just creates another new one.
   - The "Generate Shortcode" button and its always-behind-an-action
     output are gone. The current shortcode is now always visible,
     computed fresh on every render from the form's current state, in
     one of three forms (see spp_report_generator_live_shortcode()):
     (1) if current settings exactly match an existing variant, its
     simple [spp_report table="variant-name"]; (2) else if current
     settings exactly match the report's own bare defaults (full
     column set/order, sortable, per_page='All'), the bare
     [spp_report table="report-name"]; (3) else the explicit form,
     including only the columns=/no_sort=/per_page= attributes that
     actually differ from the bare defaults.
   - Added a live CSS style-editor + live preview panel (see
     spp_render_report_style_editor()): one control per --spp-report-*
     custom property (color pickers for the six colors, text inputs for
     max-width/radius/margin/font-size/cell-padding, selects for
     header-transform/header-weight), two-way synced with an editable
     CSS textarea, plus a lightweight brace/quote balance indicator.
     The live preview is scoped via `@scope (#spp-rg-live-preview)`
     wrapping whatever the textarea currently contains -- this is
     genuinely arbitrary CSS, not limited to the properties the
     controls know about, same as pasting into Divi's real Custom CSS
     field would be, but confined to the preview's own container
     regardless of what selectors appear in it. The empty
     <style id="spp-rg-live-style"> tag this gets written into is
     deliberately placed AFTER spp_render_report_table()'s own inline
     <style> in the DOM: @scope doesn't add the scope root to inner
     selectors' specificity, so `@scope (#spp-rg-live-preview) {
     .spp-report-table {...} }` ties in specificity with that base
     rule, and source order breaks the tie in our favor only if we come
     later. Two-way sync structured to avoid a feedback loop: a control
     edit surgically replaces/inserts just that one --spp-report-x
     line in the textarea's current text (never destroying unrelated
     hand-typed CSS) via regex, then re-applies the live CSS; a textarea
     edit re-applies the live CSS unconditionally (so arbitrary typed
     CSS always takes effect) and separately resyncs each control by
     plain `.value =` assignment (not dispatchEvent, which is what
     keeps this from looping back into the control-edit handler) --
     skipping a color control silently if the extracted value isn't
     strict 6-digit hex, and a select silently if it isn't an exact
     option match, per this feature's spec: don't error, don't revert,
     leave that one control showing whatever it last had. Purely
     client-side and ephemeral -- not persisted to spp_report_variants
     or anywhere else; it exists to help craft a snippet to paste into
     Divi's Custom CSS field, not to be a second, competing home for
     style state. Vanilla JS, scoped to just this panel (id/class
     prefix spp-rg-/spp_rg_), per this feature's own explicit exception
     to the rest of this screen's no-JS philosophy.

   Changes from 1.0.2:
   - Preview's default-sort now prefers the report definition's own
     'default_sort' key (spp-reports.php 1.2.0+), same preference order
     as the [spp_report] shortcode handler, falling back to the
     original Rank-if-present heuristic when a definition doesn't set
     one -- kept in sync so the preview shown here matches what the
     live shortcode actually renders. No changes to this file otherwise;
     new reports (e.g. membership) require no changes here at all, the
     dropdown reads $GLOBALS['spp_report_registry'] directly.

   Changes from 1.0.1:
   - Added a permanent "CSS Customization Reference" section
     (spp_render_report_css_reference()) documenting the wrapper class
     and every CSS custom property inc/spp-report-table.php exposes,
     for quick reference when setting up a page in Divi. Kept in sync
     with that file's own property list by hand -- see its changelog
     for the three properties (--spp-report-radius, --spp-report-margin,
     --spp-report-header-weight/--spp-report-header-transform) added
     alongside this.

   Changes from 1.0.0:
   - Both forms on this screen submit without an explicit action, so a
     GET submission (the "select a report" form) rebuilds the query
     string from its own fields only and drops post_type=page, which
     this screen's add_submenu_page() parent ('edit.php?post_type=page')
     needs present to resolve get_admin_page_parent() correctly --
     without it, WP core's user_can_access_admin_page() resolves the
     wrong parent ('edit.php', i.e. Posts) and wp_die()s with "Sorry,
     you are not allowed to access this page." before this file's own
     render callback (and its role check) ever runs. Fixed by adding a
     hidden post_type=page field to the GET form. Added the same
     hidden field to the POST form too for consistency/defense-in-depth,
     even though POST submissions without an action attribute preserve
     the current URL's query string as-is (so the POST form wasn't
     actually broken).

   PURPOSE:
   wp-admin screen (under Pages) for building a [spp_report] variant
   interactively: pick a registered report, choose which columns to
   show and in what order, optionally disable sorting, choose a
   per-page setting, preview the real rendered table right there (with
   a live CSS style editor alongside it), then either copy the
   always-visible shortcode (no DB write) or save the current
   configuration as a new, auto-named variant (writes to
   spp_report_variants via spp_save_report_variant(),
   inc/spp-report-variants.php) so it can be embedded later with just
   [spp_report table="<variant_name>"].

   No JS/AJAX for the column/order/no-sort/per-page/variant-selection
   form -- plain GET to pick a report or load a variant, plain
   nonce-protected POST for the column form and the delete action,
   matching this project's established philosophy (same reasoning
   already documented in inc/spp-report-table.php). Column reordering
   is numbered order inputs, not drag-and-drop -- simplest robust
   option, zero new JS dependency. The one deliberate exception is the
   style-editor panel (see above), which needs genuine live
   interactivity and says so explicitly in its own section.

   ACCESS CONTROL -- administrator-only, deliberately NOT
   spp_is_admin_or_editor() (that helper is admin-OR-editor, the wrong
   gate for a screen that can create new public-facing report
   configurations). Two layers, per this project's own established
   "never trust the capability parameter alone" convention:
     1. add_submenu_page()'s own capability: 'manage_options' -- hides
        the menu item from non-administrators, including editors.
     2. The real, load-bearing check at the top of the render
        callback: an explicit role check (in_array('administrator', ...)),
        not current_user_can('manage_options') -- same technique
        functions.php's spp_is_admin_or_editor()/spp_is_ladder_admin()
        already use, just narrowed to administrator only, so this
        screen doesn't depend on 'manage_options' being reachable only
        via the admin role (confirmed true today by inspecting
        blog-roles.php's spp_sync_blog_author_caps(), which never
        grants manage_options to any custom role -- but the internal
        check doesn't need to depend on that staying true).
   Both layers agree today: manage_options is held only by
   administrators on this site, so there's no path where a user sees
   the menu item but fails the internal check, or vice versa.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=page',
        'Report Generator',
        'Report Generator',
        'manage_options', // layer 1: hides the menu item from non-admins
        'spp-report-generator',
        'spp_render_report_generator_page'
    );
} );

/**
 * Permanent reference for the CSS custom properties
 * inc/spp-report-table.php exposes on .spp-report-table -- not tied to
 * any selected report. Manually kept in sync with that file's own
 * property list (see its version-history block for the source of
 * truth on defaults/behavior); this is documentation only, it doesn't
 * read the properties from anywhere. Printed below the report picker
 * when no report is loaded, and below the preview once one is (see
 * spp_render_report_generator_page()) -- no longer shown unconditionally.
 */
function spp_render_report_css_reference() {
    $rows = array(
        array( '--spp-report-header-bg', '#2c3e50', 'Header row background; also the background/border of the current-page number in pagination.' ),
        array( '--spp-report-header-text', '#ffffff', 'Header text and header sort-link color; also the text color of the current-page number in pagination.' ),
        array( '--spp-report-border-color', '#ddd', 'Bottom border on every cell; border on pagination links/page numbers.' ),
        array( '--spp-report-row-alt-bg', '#f5f5f5', 'Alternating (even) row background.' ),
        array( '--spp-report-row-hover-bg', '#eef7f6', 'Row background on hover.' ),
        array( '--spp-report-font-size', '13px', 'Base font size for the table and its per-page selector. Separate, smaller value under the 600px mobile breakpoint -- see note below.' ),
        array( '--spp-report-cell-padding', '5px 10px', 'Padding on every cell. Separate, smaller value under the 600px mobile breakpoint -- see note below.' ),
        array( '--spp-report-link-color', '#3766AB', 'Text color of non-current pagination links (Prev/Next/page numbers). Does not affect header sort links -- those use --spp-report-header-text.' ),
        array( '--spp-report-max-width', 'none', "Max-width cap on the table itself, before the surrounding container's horizontal scrollbar kicks in. Default of none means no cap -- the table is sized to its own content." ),
        array( '--spp-report-radius', '0', "Corner rounding of the table's scrolling container." ),
        array( '--spp-report-margin', '0', 'Margin on the table element -- see the centering note below.' ),
        array( '--spp-report-header-weight', 'bold', 'Font weight of header cell text.' ),
        array( '--spp-report-header-transform', 'none', 'Text transform of header cell text, e.g. uppercase.' ),
    );
    ?>
    <h2>CSS Customization Reference</h2>
    <p>
        Every report table rendered by <code>[spp_report]</code> (including the preview above) is
        wrapped in <code>.spp-report-table</code> -- target that class from a Divi module's
        <strong>Custom CSS</strong> field (Advanced tab &rarr; Custom CSS &rarr; Main Element) to
        override any of the properties below.
    </p>
    <table class="widefat striped" style="max-width:900px;margin-bottom:16px;">
        <thead>
            <tr>
                <th style="width:230px;">Property</th>
                <th style="width:110px;">Default</th>
                <th>Controls</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $rows as $r ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $r[0] ); ?></code></td>
                    <td><code><?php echo esc_html( $r[1] ); ?></code></td>
                    <td><?php echo esc_html( $r[2] ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p><strong>Example -- Divi module Custom CSS:</strong></p>
    <pre style="background:#f6f7f7;border:1px solid #ccd0d4;padding:10px 14px;max-width:500px;overflow-x:auto;">.spp-report-table {
  --spp-report-max-width: 700px;
  --spp-report-header-bg: #1a5276;
  --spp-report-radius: 8px;
}</pre>

    <p><strong>Example -- plainer header, no color changes:</strong></p>
    <pre style="background:#f6f7f7;border:1px solid #ccd0d4;padding:10px 14px;max-width:500px;overflow-x:auto;">.spp-report-table {
  --spp-report-header-weight: normal;
  --spp-report-header-transform: uppercase;
}</pre>

    <p>
        <strong>Centering:</strong> prefer Divi's own row/column alignment settings to center this
        table -- that's the normal way to do it and it just works. <code>--spp-report-margin</code>
        (e.g. <code>--spp-report-margin: 0 auto;</code>) is a fallback only for contexts where Divi's
        native alignment doesn't apply, such as a raw code/text module with no column-alignment
        control of its own.
    </p>
    <p>
        <strong>Mobile breakpoint:</strong> <code>--spp-report-font-size</code> and
        <code>--spp-report-cell-padding</code> both switch to smaller values under a 600px viewport
        (baked into <code>inc/spp-report-table.php</code>'s own <code>@media (max-width: 600px)</code>
        rule). Setting either one from Divi at the default/desktop scope will still be overridden below
        600px unless you also target that breakpoint, e.g.:
    </p>
    <pre style="background:#f6f7f7;border:1px solid #ccd0d4;padding:10px 14px;max-width:500px;overflow-x:auto;">@media (max-width: 600px) {
  .spp-report-table {
    --spp-report-font-size: 14px;
    --spp-report-cell-padding: 6px 10px;
  }
}</pre>
    <hr style="margin:20px 0;">
    <?php
}

/**
 * Seed include/order/no_sort/per_page form state from either a named
 * variant (if $variant_name matches one in $existing_variants) or the
 * report's bare defaults (all columns, original order, sortable,
 * per_page='All') otherwise. Shared by: a fresh GET load (?variant=),
 * and re-seeding after a delete_variant POST (which carries no
 * column-form fields of its own -- see the delete handling in
 * spp_render_report_generator_page()).
 *
 * @return array [ $include, $order, $no_sort, $per_page, $css ]
 */
function spp_report_generator_seed_state( $variant_name, array $existing_variants, array $full_columns ) {
    $seed = null;
    if ( $variant_name !== '' ) {
        foreach ( $existing_variants as $v ) {
            if ( $v['variant_name'] === $variant_name ) {
                $seed = $v;
                break;
            }
        }
    }

    $include = array();
    $order   = array();

    if ( $seed !== null ) {
        $i = 0;
        foreach ( $seed['columns'] as $key ) {
            $include[ $key ] = true;
            $order[ $key ]   = ++$i;
        }
        // Columns the report has today but the saved variant doesn't
        // (e.g. added to the report definition after the variant was
        // saved) -- included unchecked, ordered after the variant's own,
        // so nothing from the current definition silently disappears
        // from the editable list.
        foreach ( $full_columns as $col ) {
            $key = $col['key'];
            if ( ! isset( $include[ $key ] ) ) {
                $include[ $key ] = false;
                $order[ $key ]   = ++$i;
            }
        }
        return array( $include, $order, $seed['no_sort'], spp_report_sanitize_per_page( $seed['per_page'] ), $seed['css'] ?? '' );
    }

    foreach ( $full_columns as $i => $col ) {
        $include[ $col['key'] ] = true;
        $order[ $col['key'] ]   = $i + 1;
    }
    return array( $include, $order, false, 'All', '' );
}

/**
 * Build the effective, ordered list of selected column keys from the
 * form's $include/$order state. Split out from spp_render_report_
 * generator_page() so it can be called a second time after a failed
 * update-in-place re-seeds $include/$order from the database instead
 * of the failed attempt's posted values (see that function's own
 * comments on the preview-doubles-as-update block) -- without this,
 * the re-seed would fix $include/$order but $selected_keys itself
 * would still reflect the stale, already-computed attempt.
 *
 * @return array Ordered list of column keys.
 */
function spp_report_generator_build_selected_keys( array $full_columns, array $include, array $order ) {
    $selected_keys = array();
    foreach ( $full_columns as $col ) {
        if ( ! empty( $include[ $col['key'] ] ) ) {
            $selected_keys[] = $col['key'];
        }
    }
    usort( $selected_keys, function( $a, $b ) use ( $order ) {
        return ( $order[ $a ] ?? 0 ) <=> ( $order[ $b ] ?? 0 );
    } );
    return $selected_keys;
}

/**
 * Does $selected_keys (in order) + $no_sort + $per_page exactly match
 * an existing variant for this report? Returns that variant's name, or
 * null. Used by the always-visible shortcode's three-state logic
 * (state 1: show the variant's own simple shortcode).
 *
 * @param string $preferred_name The variant actually in context --
 *               just saved, or currently loaded via the combined
 *               selector (callers pass $loaded_variant). Checked
 *               FIRST, but only counts as a match if its own saved
 *               config still equals the current form state exactly;
 *               otherwise falls through to the generic scan below,
 *               same as when no preferred name is given at all. This
 *               is what lets two variants with identical config (or a
 *               variant whose config equals the report's own bare
 *               defaults) each still show their OWN name while
 *               they're the one loaded/just-saved, instead of a plain
 *               first-match scan always picking the same one
 *               regardless of which is actually in context.
 */
function spp_report_generator_match_variant( array $selected_keys, $no_sort, $per_page, array $existing_variants, $preferred_name = '' ) {
    $selected_keys = array_values( $selected_keys );

    $is_match = function( $v ) use ( $selected_keys, $no_sort, $per_page ) {
        return $v['columns'] === $selected_keys
            && (bool) $v['no_sort'] === (bool) $no_sort
            && spp_report_sanitize_per_page( $v['per_page'] ) === $per_page;
    };

    if ( $preferred_name !== '' ) {
        foreach ( $existing_variants as $v ) {
            if ( $v['variant_name'] === $preferred_name && $is_match( $v ) ) {
                return $v['variant_name'];
            }
        }
    }

    // Generic scan -- unchanged from before this fix, and the only path
    // taken when nothing is specifically in context (a fresh, never-
    // saved configuration that happens to coincidentally match an
    // existing variant).
    foreach ( $existing_variants as $v ) {
        if ( $is_match( $v ) ) {
            return $v['variant_name'];
        }
    }
    return null;
}

/**
 * The always-visible shortcode for the form's current state --
 * three-state logic:
 *   1. Current settings exactly match an existing variant for this
 *      report -> that variant's own simple shortcode. If more than
 *      one variant shares that exact config, $preferred_name (the
 *      variant actually in context -- just saved, or loaded via the
 *      combined selector) wins over a plain first-match scan; see
 *      spp_report_generator_match_variant().
 *   2. Else current settings exactly match the report's bare defaults
 *      (full column set in original order, sortable, per_page='All')
 *      -> the bare shortcode, no attributes. This carve-out is
 *      skipped when state 1 matched the PREFERRED variant specifically
 *      -- a variant whose saved config happens to equal the report's
 *      own defaults must still show its own name while it's the one
 *      loaded/just-saved, not silently fall through to the bare
 *      report name. A merely coincidental match (no variant actually
 *      in context) still defers to the bare shortcode here, unchanged.
 *   3. Else -> the explicit form, but only the columns=/no_sort=/
 *      per_page= attributes that actually differ from the bare
 *      defaults (never an attribute that matches the default).
 * Returns '' if $selected_keys is empty -- caller shows a prompt
 * instead of a bogus columns="" shortcode.
 *
 * @param string $preferred_name See spp_report_generator_match_variant().
 *               Callers pass $loaded_variant.
 */
function spp_report_generator_live_shortcode( $selected_report, array $selected_keys, $no_sort, $per_page, array $full_columns, array $existing_variants, $preferred_name = '' ) {
    if ( empty( $selected_keys ) ) {
        return '';
    }

    $default_keys    = array_column( $full_columns, 'key' );
    $is_bare_default = ( array_values( $selected_keys ) === $default_keys && $no_sort === false && $per_page === 'All' );

    $matched             = spp_report_generator_match_variant( $selected_keys, $no_sort, $per_page, $existing_variants, $preferred_name );
    $is_preferred_match  = ( $preferred_name !== '' && $matched === $preferred_name );

    if ( $is_bare_default && ! $is_preferred_match ) {
        return '[spp_report table="' . $selected_report . '"]';
    }

    if ( $matched !== null ) {
        return '[spp_report table="' . $matched . '"]';
    }

    $attrs = '';
    if ( array_values( $selected_keys ) !== $default_keys ) {
        $attrs .= ' columns="' . implode( ',', $selected_keys ) . '"';
    }
    if ( $no_sort !== false ) {
        $attrs .= ' no_sort="1"';
    }
    if ( $per_page !== 'All' ) {
        $attrs .= ' per_page="' . $per_page . '"';
    }
    return '[spp_report table="' . $selected_report . '"' . $attrs . ']';
}

/**
 * The live CSS style-editor + live preview panel. $preview_wrapper_id
 * is the id of the element wrapping the actual spp_render_report_table()
 * output -- the @scope root everything here confines itself to. See
 * this file's version-history block (2.0.0) for the full design
 * rationale (scoping, two-way sync, why the empty <style> tag's DOM
 * position matters). Caller is responsible for placing that empty
 * <style id="spp-rg-live-style"> tag AFTER the preview markup; this
 * function only emits the controls/textarea/script, not that tag.
 *
 * @param string $initial_css Starting textarea content -- a loaded
 *               variant's saved snippet (diff-only, from before 2.2.0,
 *               or a full dump, from 2.2.0 on -- both work identically,
 *               see below), a postback's preserved css_snapshot, or ''
 *               (Default / nothing saved yet). Whatever comes in here,
 *               the script below always expands it into a complete
 *               13-property dump before display -- see
 *               spp_render_report_style_editor_script()'s
 *               ensureFullDump().
 */
function spp_render_report_style_editor( $initial_css = '' ) {
    // Six colors need strict 6-digit hex for <input type="color">
    // (browsers reject 3-digit/shorthand) -- normalized here even
    // though the CSS reference above documents #ddd/#3766AB as the
    // "real" defaults; both are valid CSS, this is just what the
    // native color-picker widget requires. Also each control's
    // data-default -- both what a control reverts to on "Reset to
    // Defaults" and what ensureFullDump() below fills in for a
    // property the starting snippet didn't set.
    $defaults = array(
        '--spp-report-header-bg'        => '#2c3e50',
        '--spp-report-header-text'      => '#ffffff',
        '--spp-report-border-color'     => '#dddddd',
        '--spp-report-row-alt-bg'       => '#f5f5f5',
        '--spp-report-row-hover-bg'     => '#eef7f6',
        '--spp-report-link-color'       => '#3766ab',
        '--spp-report-max-width'        => 'none',
        '--spp-report-radius'           => '0',
        '--spp-report-margin'           => '0',
        '--spp-report-font-size'        => '13px',
        '--spp-report-cell-padding'     => '5px 10px',
        '--spp-report-header-transform' => 'none',
        '--spp-report-header-weight'    => 'bold',
    );

    // Whatever text comes in (a saved snippet, diff-only or full; a
    // preserved postback; or nothing yet) is just the seed -- the
    // script's ensureFullDump() expands it into a complete 13-property
    // dump on load, so an empty rule here is a sufficient starting
    // point for Default too.
    $starting_css = ( $initial_css !== '' ) ? $initial_css : ".spp-report-table {\n}\n";
    ?>
    <h2>Style Editor</h2>
    <p style="color:#666;max-width:700px;">
        Adjust a control or hand-edit the CSS below to see the preview update immediately; copy the
        result into a Divi module's Custom CSS field (see the reference at the bottom of this page)
        when you're happy with it, or click "Save as New Variant" below to keep it with that variant.
    </p>
    <div id="spp-rg-style-editor" style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:20px;max-width:1100px;">
        <div style="min-width:260px;">
            <p><label>Header background<br><input type="color" data-var="--spp-report-header-bg" data-default="<?php echo esc_attr( $defaults['--spp-report-header-bg'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-header-bg'] ); ?>"></label></p>
            <p><label>Header text<br><input type="color" data-var="--spp-report-header-text" data-default="<?php echo esc_attr( $defaults['--spp-report-header-text'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-header-text'] ); ?>"></label></p>
            <p><label>Border color<br><input type="color" data-var="--spp-report-border-color" data-default="<?php echo esc_attr( $defaults['--spp-report-border-color'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-border-color'] ); ?>"></label></p>
            <p><label>Row alt background<br><input type="color" data-var="--spp-report-row-alt-bg" data-default="<?php echo esc_attr( $defaults['--spp-report-row-alt-bg'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-row-alt-bg'] ); ?>"></label></p>
            <p><label>Row hover background<br><input type="color" data-var="--spp-report-row-hover-bg" data-default="<?php echo esc_attr( $defaults['--spp-report-row-hover-bg'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-row-hover-bg'] ); ?>"></label></p>
            <p><label>Link color<br><input type="color" data-var="--spp-report-link-color" data-default="<?php echo esc_attr( $defaults['--spp-report-link-color'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-link-color'] ); ?>"></label></p>
            <?php
            // placeholder alone would never actually be seen here -- every
            // one of these fields always carries a value (the property's
            // default), and a placeholder only shows on an empty field.
            // title= adds a hover tooltip with the same text so the hint is
            // actually visible day-to-day; placeholder is kept too, for the
            // rare case someone clears the field outright before retyping.
            ?>
            <p><label>Max width<br><input type="text" data-var="--spp-report-max-width" data-default="<?php echo esc_attr( $defaults['--spp-report-max-width'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-max-width'] ); ?>" placeholder="e.g. 700px" title="e.g. 700px, or none" style="width:140px;"></label></p>
            <p><label>Radius<br><input type="text" data-var="--spp-report-radius" data-default="<?php echo esc_attr( $defaults['--spp-report-radius'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-radius'] ); ?>" placeholder="e.g. 8px" title="e.g. 8px -- a bare number like &quot;8&quot; is not valid CSS" style="width:140px;"></label></p>
            <p><label>Margin<br><input type="text" data-var="--spp-report-margin" data-default="<?php echo esc_attr( $defaults['--spp-report-margin'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-margin'] ); ?>" placeholder="e.g. 0 auto" title="e.g. 0 auto, or 10px 0" style="width:140px;"></label></p>
            <p><label>Font size<br><input type="text" data-var="--spp-report-font-size" data-default="<?php echo esc_attr( $defaults['--spp-report-font-size'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-font-size'] ); ?>" placeholder="e.g. 13px" title="e.g. 13px -- a bare number like &quot;13&quot; is not valid CSS" style="width:140px;"></label></p>
            <p><label>Cell padding<br><input type="text" data-var="--spp-report-cell-padding" data-default="<?php echo esc_attr( $defaults['--spp-report-cell-padding'] ); ?>" value="<?php echo esc_attr( $defaults['--spp-report-cell-padding'] ); ?>" placeholder="e.g. 5px 10px" title="e.g. 5px 10px -- bare numbers are not valid CSS" style="width:140px;"></label></p>
            <p><label>Header transform<br>
                <select data-var="--spp-report-header-transform" data-default="<?php echo esc_attr( $defaults['--spp-report-header-transform'] ); ?>">
                    <option value="none">none</option>
                    <option value="uppercase">uppercase</option>
                    <option value="lowercase">lowercase</option>
                    <option value="capitalize">capitalize</option>
                </select>
            </label></p>
            <p><label>Header weight<br>
                <select data-var="--spp-report-header-weight" data-default="<?php echo esc_attr( $defaults['--spp-report-header-weight'] ); ?>">
                    <option value="normal">normal</option>
                    <option value="bold" selected>bold</option>
                </select>
            </label></p>
        </div>
        <div style="flex:1;min-width:320px;">
            <label for="spp_rg_css_editor"><strong>CSS (live, editable)</strong></label>
            <button type="button" id="spp_rg_css_reset_btn" class="button" style="margin-left:10px;">Reset to Defaults</button>
            <br>
            <textarea id="spp_rg_css_editor" name="css_snapshot" form="spp_rg_column_form" rows="17" spellcheck="false"
                      style="width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea( $starting_css ); ?></textarea>
            <p id="spp_rg_css_balance" style="margin:4px 0;font-size:12px;color:#666;">Looks balanced.</p>
            <p style="color:#666;font-size:12px;">
                All 13 properties are always shown here with their current value, whether that's the
                default or something you've customized -- a complete, self-contained reference you can
                copy from directly. Saved with the variant when you click "Save as New Variant" below.
                "Reset to Defaults" restores just these 13 properties to the values in the reference
                table below; anything else you've hand-typed here (another selector, a comment) is left
                alone.
            </p>
        </div>
    </div>
    <?php
}

/**
 * The <script> driving spp_render_report_style_editor(), plus the
 * empty live-CSS <style> tag it targets. Split out from that function
 * so the caller can place THIS after the preview markup (DOM order
 * matters for the cascade -- see this file's 2.0.0 changelog entry)
 * while the controls/textarea above can stay visually above it.
 *
 * @param string $preview_wrapper_id Id of the element wrapping the
 *               preview's spp_render_report_table() output -- the
 *               @scope root.
 */
function spp_render_report_style_editor_script( $preview_wrapper_id ) {
    ?>
    <style id="spp-rg-live-style"></style>
    <script>
    (function() {
        'use strict';
        var scopeId  = <?php echo wp_json_encode( $preview_wrapper_id ); ?>;
        var textarea = document.getElementById( 'spp_rg_css_editor' );
        var styleTag = document.getElementById( 'spp-rg-live-style' );
        var balanceEl = document.getElementById( 'spp_rg_css_balance' );
        var controls = document.querySelectorAll( '#spp-rg-style-editor [data-var]' );
        if ( ! textarea || ! styleTag ) return;

        function escapeRegExp( s ) {
            return s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
        }

        // Scoped via @scope so genuinely arbitrary CSS the user types
        // (any selector, not just the --spp-report-* properties the
        // controls know about) takes effect only inside the preview's
        // own container -- never site-wide, never any other
        // .spp-report-table that might exist elsewhere on this page.
        function applyLiveCss( text ) {
            styleTag.textContent = '@scope (#' + scopeId + ') {\n' + text + '\n}';
        }

        // Not a real CSS parser -- just a brace/quote tally, enough to
        // flag "you're missing a closing brace" while typing without
        // pretending to validate the CSS itself.
        function checkBalance( text ) {
            var depth = 0, quote = null, ok = true;
            for ( var i = 0; i < text.length; i++ ) {
                var ch = text.charAt( i );
                if ( quote ) {
                    if ( ch === quote && text.charAt( i - 1 ) !== '\\' ) quote = null;
                    continue;
                }
                if ( ch === '"' || ch === "'" ) { quote = ch; continue; }
                if ( ch === '{' ) depth++;
                else if ( ch === '}' ) { depth--; if ( depth < 0 ) ok = false; }
            }
            if ( depth !== 0 || quote !== null ) ok = false;
            if ( balanceEl ) {
                balanceEl.textContent = ok ? 'Looks balanced.' : 'Unbalanced braces or quotes.';
                balanceEl.style.color = ok ? '#1e8449' : '#c0392b';
            }
        }

        // Surgically replace (or insert, if absent) just one
        // `--spp-report-x: value;` line -- never destroys unrelated
        // hand-typed CSS elsewhere in the textarea.
        function updateVarInText( text, varName, value ) {
            var re = new RegExp( '(' + escapeRegExp( varName ) + '\\s*:\\s*)([^;]+)(;)', 'i' );
            if ( re.test( text ) ) {
                return text.replace( re, function( m, p1, p2, p3 ) { return p1 + value + p3; } );
            }
            var braceIdx = text.indexOf( '{' );
            if ( braceIdx === -1 ) {
                return '.spp-report-table {\n  ' + varName + ': ' + value + ';\n}\n' + text;
            }
            return text.slice( 0, braceIdx + 1 ) + '\n  ' + varName + ': ' + value + ';' + text.slice( braceIdx + 1 );
        }

        function extractVar( text, varName ) {
            var re = new RegExp( escapeRegExp( varName ) + '\\s*:\\s*([^;]+);', 'i' );
            var m  = re.exec( text );
            return m ? m[1].trim() : null;
        }

        // Expands `text` into a complete 13-property dump: for each
        // known --spp-report-x, keep its current value if the text
        // already sets one, else fill in that control's own default.
        // Built entirely out of updateVarInText(), so -- same as any
        // single-property edit -- anything else already in the text
        // (another selector, a hand-typed comment) is left untouched.
        // Used on load so every starting state (Default, a loaded
        // variant saved diff-only before 2.2.0 or as a full dump from
        // 2.2.0 on, or a preserved postback) always displays as a
        // complete reference, not a partial one.
        function ensureFullDump( text ) {
            controls.forEach( function( control ) {
                var current = extractVar( text, control.dataset.var );
                text = updateVarInText( text, control.dataset.var, current !== null ? current : control.dataset.default );
            } );
            return text;
        }

        // "Reset to Defaults": same shape as ensureFullDump(), but
        // unconditionally overwrites all 13 -- not just the missing
        // ones -- with their own default, regardless of their current
        // value. Only ever touches these 13 lines; anything else
        // hand-typed in the textarea is left exactly as-is.
        function resetAllToDefaults( text ) {
            controls.forEach( function( control ) {
                text = updateVarInText( text, control.dataset.var, control.dataset.default );
            } );
            return text;
        }

        // Sync every control's displayed value FROM the given CSS text --
        // shared by the textarea's own 'input' handler (hand-typed edits)
        // and by the initial load below (a loaded variant's saved
        // snippet, or a postback's preserved css_snapshot, may set only
        // some of the 13 properties -- the rest must stay at their own
        // default, which is exactly what "leave control as-is" already
        // gives us, since every control's markup default IS its data-default).
        function resyncControlsFromText( text ) {
            controls.forEach( function( control ) {
                var val = extractVar( text, control.dataset.var );
                if ( val === null ) return; // property not present in the text -- leave control as-is

                if ( control.type === 'color' ) {
                    // <input type="color"> only accepts strict #rrggbb --
                    // a named color / rgb() / hsl() / 3-digit hex is left
                    // alone rather than errored or reverted.
                    if ( /^#[0-9a-f]{6}$/i.test( val ) ) {
                        control.value = val.toLowerCase(); // plain assignment -- does not fire 'input', so this can't loop back
                    }
                } else if ( control.tagName === 'SELECT' ) {
                    var matchedValue = null;
                    for ( var i = 0; i < control.options.length; i++ ) {
                        if ( control.options[ i ].value.toLowerCase() === val.toLowerCase() ) {
                            matchedValue = control.options[ i ].value;
                            break;
                        }
                    }
                    if ( matchedValue !== null ) control.value = matchedValue;
                } else {
                    control.value = val;
                }
            } );
        }

        controls.forEach( function( control ) {
            control.addEventListener( 'input', function() {
                var newText = updateVarInText( textarea.value, control.dataset.var, control.value );
                textarea.value = newText;
                applyLiveCss( newText );
                checkBalance( newText );
            } );
        } );

        textarea.addEventListener( 'input', function() {
            var text = textarea.value;
            applyLiveCss( text );
            checkBalance( text );
            resyncControlsFromText( text );
        } );

        var resetBtn = document.getElementById( 'spp_rg_css_reset_btn' );
        if ( resetBtn ) {
            resetBtn.addEventListener( 'click', function() {
                var newText = resetAllToDefaults( textarea.value );
                textarea.value = newText;
                applyLiveCss( newText );
                checkBalance( newText );
                resyncControlsFromText( newText );
            } );
        }

        // Initial load: expand whatever the server handed us (Default's
        // empty rule, a loaded variant's saved snippet -- diff-only or
        // full, a preserved postback) into a complete 13-property dump,
        // then sync the preview/balance/controls to match -- no
        // interaction needed to see the full reference immediately.
        textarea.value = ensureFullDump( textarea.value );
        applyLiveCss( textarea.value );
        checkBalance( textarea.value );
        resyncControlsFromText( textarea.value );
    })();
    </script>
    <?php
}

function spp_render_report_generator_page() {
    // Layer 2: the real gate. Deliberately not spp_is_admin_or_editor()
    // (admin-OR-editor is the wrong scope here) and deliberately an
    // explicit role check rather than current_user_can('manage_options')
    // again, so this doesn't just re-check the same capability the menu
    // registration already gated on.
    $roles = (array) wp_get_current_user()->roles;
    if ( ! in_array( 'administrator', $roles, true ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    $registry = $GLOBALS['spp_report_registry'] ?? array();

    echo '<div class="wrap"><h1>Report Generator</h1>';

    // -- Step 1: choose a report (plain GET) ---------------------------------
    $selected_report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : '';
    if ( isset( $_POST['report'] ) ) {
        // A POST submission (preview/save/delete) carries the report
        // selection forward too, takes priority over the GET one.
        $selected_report = sanitize_key( wp_unslash( $_POST['report'] ) );
    }

    ?>
    <form method="get" style="margin-bottom:20px;">
        <input type="hidden" name="post_type" value="page">
        <input type="hidden" name="page" value="spp-report-generator">
        <label for="spp_rg_report"><strong>Report:</strong></label>
        <select name="report" id="spp_rg_report">
            <option value="">-- select a report --</option>
            <?php foreach ( $registry as $key => $fn ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_report, $key ); ?>>
                    <?php echo esc_html( $key ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button">Load</button>
    </form>
    <?php

    if ( $selected_report === '' || ! isset( $registry[ $selected_report ] ) || ! function_exists( $registry[ $selected_report ] ) ) {
        if ( $selected_report !== '' ) {
            echo '<p style="color:#c0392b;">Unknown report.</p>';
        }
        // Item 1: below the dropdown when no report is loaded yet.
        spp_render_report_css_reference();
        echo '</div>';
        return;
    }

    $definition = call_user_func( $registry[ $selected_report ] );
    if ( ! is_array( $definition ) || ! isset( $definition['columns'], $definition['rows'] ) ) {
        echo '<p style="color:#c0392b;">Report misconfigured.</p></div>';
        return;
    }
    $full_columns = $definition['columns'];
    $rows         = $definition['rows'];

    $existing_variants = spp_get_report_variants_for_base( $selected_report );

    // -- Sticky form state ---------------------------------------------------
    $action   = '';
    $messages = array(); // ['type'=>'error'|'success', 'text'=>string]

    $is_post_for_this_report = ( $_SERVER['REQUEST_METHOD'] === 'POST' && $selected_report !== '' );

    if ( $is_post_for_this_report ) {
        check_admin_referer( 'spp_report_generator', 'spp_report_generator_nonce' );
        $action = isset( $_POST['spp_action'] ) ? sanitize_key( wp_unslash( $_POST['spp_action'] ) ) : '';
    }

    if ( $is_post_for_this_report && $action === 'delete_variant' ) {
        // The combined selector's own form posts here (via a
        // formmethod="post" override on its Delete button) -- it only
        // ever carries report/variant/spp_action/nonce, never the
        // column-form fields, so this branch re-seeds state from
        // scratch afterward rather than reading col_include/col_order.
        $target = isset( $_POST['variant'] ) ? sanitize_key( wp_unslash( $_POST['variant'] ) ) : '';
        if ( $target === '' ) {
            $messages[]     = array( 'type' => 'error', 'text' => 'Select a variant to delete.' );
            $loaded_variant = '';
        } else {
            $result = spp_delete_report_variant( $target );
            if ( is_wp_error( $result ) ) {
                $messages[]     = array( 'type' => 'error', 'text' => $result->get_error_message() );
                $loaded_variant = $target;
            } else {
                $messages[]     = array( 'type' => 'success', 'text' => 'Variant "' . esc_html( $target ) . '" deleted.' );
                $loaded_variant = ''; // reset to Default, per spec
            }
            $existing_variants = spp_get_report_variants_for_base( $selected_report ); // refresh -- it just changed
        }
        list( $include, $order, $no_sort, $per_page, $css ) = spp_report_generator_seed_state( $loaded_variant, $existing_variants, $full_columns );

    } elseif ( $is_post_for_this_report ) {
        // Normal column-form POST (Update Preview / Save as New Variant).
        $no_sort        = isset( $_POST['no_sort'] ) && $_POST['no_sort'] === '1';
        $per_page       = isset( $_POST['per_page'] ) ? spp_report_sanitize_per_page( wp_unslash( $_POST['per_page'] ) ) : 'All';
        $loaded_variant = isset( $_POST['loaded_variant'] ) ? sanitize_key( wp_unslash( $_POST['loaded_variant'] ) ) : '';
        // The style editor's textarea (name="css_snapshot") is outside
        // this <form> in the DOM but associated with it via its own
        // form="spp_rg_column_form" attribute, so it rides along on
        // every submit of this form -- both Update Preview and Save as
        // New Variant. Not sanitized beyond wp_unslash(): this screen is
        // administrator-only (see this file's ACCESS CONTROL note), the
        // only place it's ever redisplayed is back into this same
        // <textarea> via esc_textarea(), and stripping tags here would
        // mangle otherwise-valid CSS (e.g. content: "<";).
        $css            = isset( $_POST['css_snapshot'] ) ? wp_unslash( $_POST['css_snapshot'] ) : '';

        $posted_include = isset( $_POST['col_include'] ) && is_array( $_POST['col_include'] ) ? wp_unslash( $_POST['col_include'] ) : array();
        $posted_order   = isset( $_POST['col_order'] ) && is_array( $_POST['col_order'] ) ? wp_unslash( $_POST['col_order'] ) : array();

        $include = array();
        $order   = array();
        foreach ( $full_columns as $i => $col ) {
            $key = $col['key'];
            $include[ $key ] = isset( $posted_include[ $key ] );
            $order[ $key ]   = isset( $posted_order[ $key ] ) ? (int) $posted_order[ $key ] : ( $i + 1 );
        }
    } else {
        // Fresh GET load -- seed from ?variant= (the combined selector's
        // Load button) or Default.
        $loaded_variant = isset( $_GET['variant'] ) ? sanitize_key( wp_unslash( $_GET['variant'] ) ) : '';
        list( $include, $order, $no_sort, $per_page, $css ) = spp_report_generator_seed_state( $loaded_variant, $existing_variants, $full_columns );
    }

    // -- Build the effective, ordered key list from the current form state --
    $selected_keys = spp_report_generator_build_selected_keys( $full_columns, $include, $order );

    // -- Update Preview doubling as update-in-place, when a variant is loaded --
    // $loaded_variant is the hidden field's value -- "whichever variant was
    // loaded when this form was rendered" -- so this only fires for a real
    // loaded variant, never for Default or a fresh/never-saved custom
    // config (nothing exists yet to update in either of those cases).
    // Distinct from the save block below: this overwrites that SAME row
    // (spp_update_report_variant()), never creates a new one -- "Save as
    // New Variant" still always branches off a new auto-named copy,
    // completely unaffected by this block's existence.
    if ( $is_post_for_this_report && $action === 'preview' && $loaded_variant !== '' ) {
        if ( empty( $selected_keys ) ) {
            $messages[] = array( 'type' => 'error', 'text' => 'Select at least one column -- "' . esc_html( $loaded_variant ) . '" was not updated.' );
        } else {
            $result = spp_update_report_variant( $loaded_variant, $selected_keys, $no_sort, $per_page, $css );
            if ( is_wp_error( $result ) ) {
                $messages[] = array( 'type' => 'error', 'text' => $result->get_error_message() );

                // Never leave the attempted-but-failed values on screen --
                // confirmed via live testing that a variant deleted out
                // from under a stale form submission (a real race: another
                // admin's concurrent delete, or a stale hidden field) still
                // rendered the attempted new columns/no_sort/per_page/css as
                // a normal, working-looking preview, with only this error
                // banner distinguishing it from an actual save. Re-seed
                // everything from the database's actual current state
                // instead. $existing_variants is refreshed first (not
                // spp_get_report_variant() alone) so the "still exists?"
                // check below and spp_report_generator_seed_state()'s own
                // lookup share one fresh query and can't disagree with each
                // other. If the variant is gone entirely (this failure's own
                // "No such variant" case, or deleted by someone else in the
                // meantime), $loaded_variant resets to '' -- same fallback
                // the explicit delete_variant action already uses -- so the
                // Configuration dropdown, the button label, and the preview
                // all agree with each other and with the database, instead
                // of the dropdown falling back to "Default" while the button
                // still names a variant that no longer exists.
                $existing_variants = spp_get_report_variants_for_base( $selected_report );
                $still_exists = false;
                foreach ( $existing_variants as $v ) {
                    if ( $v['variant_name'] === $loaded_variant ) {
                        $still_exists = true;
                        break;
                    }
                }
                if ( ! $still_exists ) {
                    $loaded_variant = '';
                }
                list( $include, $order, $no_sort, $per_page, $css ) = spp_report_generator_seed_state( $loaded_variant, $existing_variants, $full_columns );
                $selected_keys = spp_report_generator_build_selected_keys( $full_columns, $include, $order );
            } else {
                $messages[]        = array(
                    'type' => 'success',
                    'text' => 'Variant "' . esc_html( $loaded_variant ) . '" updated.',
                );
                $existing_variants = spp_get_report_variants_for_base( $selected_report ); // refresh -- its config just changed
            }
        }
    }

    // -- Save action: always a new, auto-named variant -----------------------
    if ( $is_post_for_this_report && $action === 'save' ) {
        if ( empty( $selected_keys ) ) {
            $messages[] = array( 'type' => 'error', 'text' => 'Select at least one column before saving.' );
        } else {
            $variant_key = spp_next_report_variant_name( $selected_report );
            $result      = spp_save_report_variant( $variant_key, $selected_report, $selected_keys, $no_sort, $per_page, $css );
            if ( is_wp_error( $result ) ) {
                $messages[] = array( 'type' => 'error', 'text' => $result->get_error_message() );
            } else {
                $messages[] = array(
                    'type' => 'success',
                    'text' => 'Variant saved as "' . esc_html( $variant_key ) . '". Embed it with: <code>[spp_report table="' . esc_html( $variant_key ) . '"]</code>',
                );
                $loaded_variant     = $variant_key;
                $existing_variants  = spp_get_report_variants_for_base( $selected_report ); // refresh so the combined selector includes it
            }
        }
    }

    // -- Messages -------------------------------------------------------------
    foreach ( $messages as $m ) {
        $color = $m['type'] === 'error' ? '#c0392b' : '#1e8449';
        $bg    = $m['type'] === 'error' ? '#fdf3f2' : '#eafaf1';
        echo '<div style="background:' . $bg . ';border:1px solid ' . $color . ';color:' . $color . ';padding:10px 14px;border-radius:6px;margin-bottom:14px;">' . $m['text'] . '</div>';
    }

    // -- Item 3: combined "Default + existing variants" selector, with delete --
    ?>
    <form method="get" style="margin-bottom:16px;">
        <?php wp_nonce_field( 'spp_report_generator', 'spp_report_generator_nonce' ); ?>
        <input type="hidden" name="post_type" value="page">
        <input type="hidden" name="page" value="spp-report-generator">
        <input type="hidden" name="report" value="<?php echo esc_attr( $selected_report ); ?>">
        <label for="spp_rg_variant"><strong>Configuration:</strong></label>
        <select name="variant" id="spp_rg_variant">
            <option value="">Default</option>
            <?php foreach ( $existing_variants as $v ) : ?>
                <option value="<?php echo esc_attr( $v['variant_name'] ); ?>" <?php selected( $loaded_variant, $v['variant_name'] ); ?>>
                    <?php echo esc_html( $v['variant_name'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button">Load</button>
        <?php
        // formmethod="post" overrides just this button's submission to
        // POST (with the same fields, including the select's current
        // value as $_POST['variant']) -- lets Delete act on whatever's
        // selected without needing Load clicked first, and without a
        // second <form> duplicating the <select>.
        ?>
        <button type="submit" name="spp_action" value="delete_variant" class="button" style="color:#c0392b;"
                formmethod="post" <?php disabled( empty( $existing_variants ) ); ?>
                onclick="return confirm('Delete the selected variant? This cannot be undone.');">
            Delete Selected
        </button>
    </form>

    <?php
    // -- Item 4: always-visible shortcode, computed fresh every render --------
    // $loaded_variant is already tracked through every code path above
    // (delete-reseed, normal POST via its hidden field, fresh GET load,
    // and overwritten to the new variant's name right after a
    // successful save) -- passed as the preferred-variant hint so the
    // shortcode shown here always favors the variant actually just
    // saved or explicitly loaded, not just "first alphabetical match
    // with identical config."
    $live_shortcode = spp_report_generator_live_shortcode( $selected_report, $selected_keys, $no_sort, $per_page, $full_columns, $existing_variants, $loaded_variant );
    echo '<div style="margin-bottom:14px;">';
    echo '<label for="spp_rg_shortcode_out"><strong>Shortcode:</strong></label><br>';
    if ( $live_shortcode === '' ) {
        echo '<p style="color:#c0392b;margin:4px 0;">Select at least one column to see its shortcode.</p>';
    } else {
        echo '<textarea id="spp_rg_shortcode_out" readonly rows="2" style="width:100%;max-width:700px;font-family:monospace;" onclick="this.select()">' . esc_textarea( $live_shortcode ) . '</textarea>';
    }
    echo '</div>';

    // -- Column selection form --------------------------------------------
    ?>
    <form method="post" id="spp_rg_column_form" style="max-width:700px;">
        <?php wp_nonce_field( 'spp_report_generator', 'spp_report_generator_nonce' ); ?>
        <input type="hidden" name="post_type" value="page">
        <input type="hidden" name="report" value="<?php echo esc_attr( $selected_report ); ?>">
        <input type="hidden" name="loaded_variant" value="<?php echo esc_attr( $loaded_variant ); ?>">

        <table class="widefat" style="margin-bottom:14px;">
            <thead>
                <tr>
                    <th style="width:60px;">Show</th>
                    <th>Column</th>
                    <th style="width:100px;">Order</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $full_columns as $col ) :
                    $key = $col['key'];
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="col_include[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $include[ $key ] ) ); ?>>
                        </td>
                        <td><?php echo esc_html( $col['label'] ?? $key ); ?></td>
                        <td>
                            <input type="number" name="col_order[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $order[ $key ] ?? 1 ); ?>" style="width:70px;">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <label>
                <input type="checkbox" name="no_sort" value="1" <?php checked( $no_sort ); ?>>
                Disable column-header sorting for this report
            </label>
        </p>

        <p>
            <label for="spp_rg_per_page"><strong>Rows per page</strong></label><br>
            <select name="per_page" id="spp_rg_per_page">
                <?php foreach ( spp_report_per_page_choices() as $opt ) :
                    $opt_str = (string) $opt;
                    ?>
                    <option value="<?php echo esc_attr( $opt_str ); ?>" <?php selected( $per_page, $opt_str ); ?>>
                        <?php echo esc_html( $opt_str ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <button type="submit" name="spp_action" value="preview" class="button">
                <?php echo $loaded_variant !== ''
                    ? 'Update Preview &amp; Save to &#8220;' . esc_html( $loaded_variant ) . '&#8221;'
                    : 'Update Preview'; ?>
            </button>
            <?php if ( $loaded_variant !== '' ) : ?>
                <br><span style="color:#666;font-size:12px;">Also overwrites the saved &#8220;<?php echo esc_html( $loaded_variant ); ?>&#8221; variant with these settings -- no new variant is created.</span>
            <?php endif; ?>
        </p>

        <hr>

        <p>
            <button type="submit" name="spp_action" value="save" class="button button-primary">Save as New Variant</button>
        </p>
    </form>

    <?php
    // -- Item 5: style editor (controls above the preview it targets) --------
    spp_render_report_style_editor( $css );

    echo '<h2>Preview</h2>';
    $preview_columns = spp_report_filter_columns( $full_columns, $selected_keys );
    if ( empty( $preview_columns ) ) {
        $preview_columns = $full_columns; // nothing selected yet -- show everything rather than an empty table
    }
    if ( $no_sort ) {
        $preview_columns = array_map( function( $col ) {
            $col['sortable'] = false;
            return $col;
        }, $preview_columns );
    }
    $preview_keys           = array_column( $preview_columns, 'key' );
    $definition_default_sort = $definition['default_sort'] ?? null;
    $preview_default_sort  = ( $definition_default_sort !== null && in_array( $definition_default_sort, $preview_keys, true ) )
        ? $definition_default_sort
        : ( in_array( 'Rank', $preview_keys, true ) ? 'Rank' : ( $preview_keys[0] ?? '' ) );

    echo '<div id="spp-rg-live-preview">';
    spp_render_report_table( $preview_columns, $rows, array(
        'id'               => 'preview',
        'default_sort'     => $preview_default_sort,
        'default_dir'      => 'asc',
        'per_page_options' => spp_report_per_page_choices(),
        'default_per_page' => $per_page,
    ) );
    echo '</div>';

    // Empty live-CSS <style> + its <script>, placed AFTER the preview's own
    // inline <style> above -- see spp_render_report_style_editor_script()'s
    // docblock for why the DOM order here is load-bearing, not cosmetic.
    spp_render_report_style_editor_script( 'spp-rg-live-preview' );

    // Item 1: below the rendered preview once a report is loaded.
    spp_render_report_css_reference();

    echo '</div>';
}
