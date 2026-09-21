<?php
/* =========================================================
   Report Registry
   Version: 1.14.0
   Date: 2026-09-21

   Changes from 1.13.0:
   - Added event_status: reproduces the live GL Event Status page's
     ([gl_event_registrations], plugin file wp-content/plugins/gl-events/
     public/gl-event-registrations.php, page 20010189 "gl-event-status-2",
     linked in both Main nav ("Status of Events") and GL admin nav) own
     aggregate query -- GROUP BY occurrence, COUNT/CASE-based confirmed/
     waiting/withdrawn tallies against gl_event_occurrences/
     gl_registrations/gl_event_categories -- as a real [spp_report]
     registry entry with actual column sorting/pagination, not a second
     copy of that page. NOT a WPDA-app replacement -- gl_event_registrations
     is a GL Events plugin feature, unrelated to the WPDA-deactivation
     migration this week's other report additions were part of.
     Date scope deliberately widened from the plugin page's rolling
     5-week window to unbounded (event_date >= CURDATE(), no upper
     cutoff) per this task's explicit instruction -- this report is
     meant to show every current/future event, not just the near term.
     No category filter dropdown (also per instruction) -- sorting by
     the Category column, same as any other column, replaces it; a
     saved report-variant scoped to one category is a future decision
     if wanted, not built here.
     Date column is stored as `event_date` + zero-padded 24h `event_time`
     concatenated ("2026-09-21 18:00"), NOT the plugin page's human
     display format ("Sep 21, 2026 6:00 pm") -- necessary because
     spp_render_report_table()'s sort is a plain is_numeric-or-
     strcasecmp comparison on the raw column value (spp-report-table.php,
     ~line 517), and month-abbreviation date strings don't sort
     chronologically under strcasecmp (e.g. "Aug" > "Apr" alphabetically
     works, but "Dec" < "Feb" doesn't -- not a safe general format).
     ISO-ish zero-padded date+time sorts correctly as a plain string and
     stays fully human-readable. This is the one place this report's
     column values deliberately differ from the source page's display
     formatting -- everything else (which counts get tallied, which
     rows get included, the "n/a" capacity placeholder for capacity<=0)
     is reproduced exactly, including the source query's own existing
     quirk of silently excluding 'late_withdrawn' registrations from
     every tally (matches gl-event-registrations.php's CASE branches
     exactly, which only match 'confirmed'/'waiting'/'withdrawn').
     ACCESS: no report-specific gate, matching the source page's own
     real access level -- [gl_event_registrations] itself has no
     capability/login check of its own. The [spp_report] shortcode's
     blanket is_user_logged_in() floor still applies (site-wide policy,
     no per-report opt-out, see 1.4.0 above) -- an ungated GL Events
     page and this report differ only in that one respect, which is a
     property of the shared shortcode handler, not a per-report choice
     made here.
     Not wired into any page by this change -- registry entry only, per
     Gaetan's review-before-embedding instruction; the existing GL Event
     Status page/shortcode is untouched.

   Changes from 1.12.0:
   - Added recent_results: replaces the WPDA "LadderRanking" app
     (app_id=9, table Results -- yes, the same table spp_report_results()
     already queries; app 9 is a second, differently-scoped app over the
     same table, not a typo). Live today as [wpda_app app_id="9"] on
     20000903 ("Rankings from most recent results", linked in Main nav,
     real member traffic) -- 20005826 ("Results of Monday's ladder
     tournament", same app_id) is now trashed, so 20000903 is the only
     remaining live embed.
     Pulled app 9's real cnt_table config fresh rather than trusting the
     earlier Phase 1 inventory summary's characterization of it as a
     "limited" member-safe view: IMPORTANT DISCREPANCY FOUND -- app 9's
     actual column set includes RankOverride (orderable, no less),
     alongside Rank/display_name/RankPrev/RankCalc/Score/event_id/
     user_id/group_id. It does NOT include RankCalc_Shadow. This is the
     exact same class of exposure spp_report_results() was security-fixed
     for at 1.4.0 above ("any logged-in member could view per-player
     RankOverride... internal ranking mechanics") -- except app 9 has
     been doing exactly that, live, on a Main-nav page, this whole time;
     nothing in this migration created that exposure, it already exists
     in production today via the WPDA app itself.
     Built to match app 9's real config exactly, RankOverride included,
     per this task's explicit instruction to reproduce app 9's actual
     column set rather than assume/impose the 'results' report's
     narrower admin-safe set -- but this is a real judgment call, not a
     mechanical one: flagged clearly for Gaetan rather than silently
     either (a) reproducing a possible PII/internal-mechanics leak, or
     (b) unilaterally dropping a column the live app currently shows.
     Not gated beyond the shortcode's blanket is_user_logged_in() floor
     -- matches app 9's own real current exposure level (no theme-level
     page gate applies to 20000903 either, same as app 9's WPDA config
     has no access restriction of its own) -- but Gaetan should decide
     whether RankOverride visibility is actually intended before this
     gets wired into any page; trivial to add spp_is_admin_or_editor()
     gating on this one column (or the whole report) at that point.
     No WHERE filter, matching app 9's own empty defaultWhere -- verified
     this is safe/correct, not an oversight: the Results table itself
     only ever holds the single most recent event's rows (144 rows, all
     one event_id, confirmed fresh against live data), rebuilt by
     spp-create-results.php each event, so there's no cross-event data
     to accidentally leak by omitting a recency filter.
     Same orderable flags as app 9's own config: only Rank and
     RankOverride are sortable, matching its real orderable set exactly
     (display_name/RankPrev/RankCalc/event_id/user_id/group_id are not),
     same precedent as spp_report_courts()/spp_report_schedule_current()
     for reproducing a WPDA app's real orderable set rather than
     upgrading it silently.
     Not wired into 20000903 by this change -- registry entry only, per
     Gaetan's review-before-embedding instruction.

   Changes from 1.11.0:
   - Added schedule_current: replaces the WPDA "ShowSchedules" app
     (app_id=4, table schedules_w). Investigated before building --
     app 4 is NOT dead/superseded config: it's live, embedded today as
     [wpda_app app_id="4"] on page 20005754 ("This week's Schedule"),
     paired with [spp_create_view] (a separate, non-report shortcode --
     see inc/spp-create-view.php -- that just (re)points the schedules_w
     VIEW at the live Schedules table before app 4 renders it; it is
     not itself a report and renders no output of its own).
     "This week's Schedule" is menu-linked from Main ("View Schedule")
     but is, in practice, editor/admin-only today: [spp_create_view] is
     in functions.php's migrated_admin_tool_shortcodes gate list, and
     20005754 is not in $member_pages, so a regular member clicking
     "View Schedule" is silently redirected home. Flagged for Gaetan
     separately -- likely an unintended side effect of the 2026-09-06
     cmruncode migration gate (see that file's own changelog) landing
     on a page that reads as member-facing by name/title, not a
     deliberate access decision -- not fixed here since it's outside
     this task's scope.
     Compared app 4's actual scope against both existing schedule
     shortcodes before building: [spp_player_schedule_view] (the real
     member-facing replacement, published/gated behind
     get_option('spp_schedule_published'), card-per-court-per-time-slot
     layout) is NOT the same tool -- app 4 is a flat, fully sortable
     single table across every player/court/time in one view, useful
     for a convenor eyeballing the whole current (possibly
     not-yet-published) schedule at once, which the card layout doesn't
     offer. Not a duplicate; built.
     Sources Schedules/Times/Groups/Courts directly via the same JOIN
     schedules_w's CREATE VIEW itself uses, rather than querying
     schedules_w -- deliberately not reproducing the schedules_w
     dependency gl-player-schedule-view.php's own 1.6.5 changelog
     already documented and removed for this exact page's use case
     (schedules_w is a repointable VIEW with a real DROP+CREATE race
     window and a history of being left pointed at the wrong table by
     an unrelated feature; reading the underlying tables directly
     removes that risk structurally, same fix, same reasoning).
     Same 9 columns/labels/orderable flags app 4's own WPDA config
     renders today (Rank, First Name, Last Name, User Phone, T Desc,
     Travel, Crt Name, GP Name, User) -- first_name/user_phone kept
     non-sortable, matching app 4's own orderable flags exactly, same
     as spp_report_courts()'s precedent for reproducing a WPDA app's
     real orderable set rather than upgrading it silently. No WHERE
     filter, matching app 4's own empty defaultWhere (Group 99
     dropout/unscheduled sentinel rows included, unlike
     spp_player_schedule_view which excludes them -- intentional parity
     with what app 4 itself shows today, not an oversight).
     ACCESS: gated to spp_is_admin_or_editor(), same pattern/precedent
     as spp_report_results() -- this preserves app 4's real current
     exposure level (editor/admin-only, per the page-access gate above)
     rather than the shortcode's normal blanket is_user_logged_in()
     floor, since this data (incl. every player's phone number) is only
     public today once spp_player_schedule_view's own publish flag is
     set -- an ungated version of this report would leak a
     not-yet-published week's schedule + phone numbers to any logged-in
     member. Not wired into any page by this change -- registry entry
     only, per Gaetan's review-before-embedding instruction; loosen this
     gate only if/when he decides where this gets embedded and confirms
     that's the intended exposure.

   Changes from 1.10.0:
   - Added 3 new registry entries -- ladder_ratings_only, queen_ratings,
     ace_ratings -- each a filtered/sorted subset of
     spp_report_ladder_ratings()'s own data (membership table, same 9
     columns: Rank, ClubRating, RatingGames, DUPR, KQAceRank, KQQueenRank,
     Ladder, first_name, last_name). NOT related to the differently-named
     'results' registry entry/spp_report_results() -- that function
     queries the Results table (RankOverride/RankCalc_Shadow internal
     ranking mechanics) and is gated to spp_is_admin_or_editor(); these 3
     reuse ladder_ratings' query/columns/gating instead (confirmed with
     Gaetan before implementing, since the two are easy to conflate by
     name -- "Ranks & Ratings" is ladder_ratings' page title, not
     'results'). Same access level as ladder_ratings: no per-report
     capability check, just the shortcode's blanket is_user_logged_in()
     floor -- these are member-facing rank views, not the admin-only
     internal-mechanics class of report.
     - ladder_ratings_only: WHERE Ladder = 'Yes' (same flag master_list
       already filters on), default_sort Rank ASC.
     - queen_ratings: WHERE KQQueenRank IS NOT NULL, default_sort
       KQQueenRank ASC. KQQueenRank is pivoted from its own usermeta
       (spp_kq_queen_rank, inc/spp-create-membership-table.php) independent
       of ClubRating, so this deliberately does NOT also require
       ClubRating > 0 the way ladder_ratings' own query does -- a
       Queen-only player with no ladder ClubRating still belongs here.
     - ace_ratings: same reasoning, WHERE KQAceRank IS NOT NULL,
       default_sort KQAceRank ASC. Expected to render zero rows until an
       Ace-format KQ event has actually been processed into KQAceRank --
       spp_render_report_table() already renders headers-only (not a bare
       "No results." message) for an empty report, so that's a real empty
       state, not a broken one.

   Changes from 1.9.0:
   - spp_report_ladder_ratings() ("Ranks & Ratings") gains two new
     sortable columns, Ace Rank / Queen Rank, sourced from membership.
     KQAceRank/KQQueenRank (inc/spp-create-membership-table.php 1.5.0,
     itself pivoted from the new spp_kq_{ace,queen}_rank usermeta --
     see inc/spp-kq-format-ranking.php for the full ranking system).
     Existing columns/WHERE ClubRating > 0 filter unchanged.

   Changes from 1.8.0:
   - Added kq_history: reads the new spp_kq_history table (inc/spp-kq-
     schema.php 1.3.0, inc/spp-kq-history.php owns the write path).
     Deliberately open to all logged-in members via just this
     shortcode's own blanket floor -- no per-report gate added -- since
     KQ has no ranking and this is a plain post-event score log, nothing
     in the same sensitivity class as results/membership_tags. The
     function itself lives in inc/spp-kq-history.php, not this file
     (co-located with the rest of that feature), same as every other
     report's definition function still lives wherever makes sense for
     it -- only the registry array entry lives here. See that function's
     own docblock for how "organized by event, then by round" is
     reproduced within spp_render_report_table()'s flat-table-only
     rendering model (same compound-sort trick membership/master_list
     already use).

   Changes from 1.7.0:
   - SECURITY FIX -- PII exposure: preferred_new and preferred_permanent
     now require spp_is_admin_or_editor(), same gating pattern as
     spp_report_results()/spp_report_membership_tags(), instead of relying
     on the shortcode's blanket is_user_logged_in() floor. Per Gaetan's
     review of the access-gating audit (read-only pass, no code changed
     at the time): both reports' user_phone column is real member PII,
     and both live pages were reachable by any logged-in member -- the
     Editor-dropdown nav link hiding them from the menu was never real
     access control, a pattern this codebase had already caught once
     before (the MembershipTags refresh wrapper's own history). No other
     report changed: membership and master_list were confirmed in that
     same audit as intentional logged-in-only member directories and are
     left exactly as they were; courts/times/ladder_ratings/results/
     membership_tags were all confirmed already correct.

   Changes from 1.6.0 (Phase 2 of the WPDA-to-spp-reports migration --
   see the Phase 1 inventory/cross-reference that preceded this):
   - Added 5 new registry entries, each replacing a still-live WPDA
     "App Builder" app: courts (app_id=12, table Courts),
     times (app_id=30, table Times), preferred_new (app_id=29, table
     preferred_new), preferred_permanent (app_id=31, table
     preferred_permanent), membership_tags (app_id=3, table
     MembershipTags). See each function's own docblock for exactly what
     was/wasn't reproduced from its WPDA config -- in short: Courts and
     MembershipTags keep the same inline-editable columns WPDA actually
     exposed (Crt_name/active, and Balls/Tag respectively); Times/
     preferred_new/preferred_permanent are read-only reports, which is
     an exact behavioral match for those three (none of them had any
     inlineEditing:true column in WPDA despite table-level insert/
     update/delete transaction flags). None of the 5 reproduce WPDA's
     row-level add/delete -- this shared registry
     (spp_render_report_table() + spp-report-edit.php) only ever UPDATEs
     an existing row's editable columns; there is no insert/delete
     mechanism here at all, for any report. Courts and Preferred
     Permanent are the two WPDA apps that actually had insert/delete
     enabled, so those two are where this gap has real (if likely
     infrequent) practical impact -- flagged per-function above.
   - membership_tags is the one report of the 5 with a stricter-than-
     logged-in view gate (spp_is_admin_or_editor(), same pattern as
     spp_report_results()) -- WPDA's own app_settings for app_id=3
     restrict its REST API to administrator/editor, a protection level
     this shortcode's blanket is_user_logged_in() floor alone wouldn't
     preserve. See that function's own docblock for the full reasoning,
     including why inc/spp-membership-tags-refresh-ui.php's separate
     refresh-then-display wrapper was deliberately left pointed at the
     old WPDA app rather than repointed at this new report.
   - Live pages repointed from [wpda_app app_id="X"] to
     [spp_report table="..."] for all 5: 20006436 (Courts),
     20007885 (Times), 20007853 (Preferred New), 20007891 (Preferred
     Permanent), 20005729 (MembershipTags). The old WPDA apps themselves
     were left active/unmodified per this migration's own instructions
     -- Gaetan retires them and deactivates WPDA once all 5 are
     verified.
   - Corrected master_list's docblock (below): it previously named
     app_id=18 as the WPDA app it replaces; that app_id is a dead,
     unembedded app that happens to share the same table/near-identical
     name as app_id=24, the app actually still live (page 1517) in
     parallel with master_list's own page. See that function's updated
     docblock for the full correction.

   Changes from 1.5.0:
   - BUG FIX: ladder_ratings' recently-added 'default_sort' => 'ClubRating'
     was in fact being read and applied on every load -- the shortcode
     handler's default_sort resolution (below) correctly picked
     'ClubRating' out of the definition and passed it through to
     spp_render_report_table(). The actual defect was direction, not
     column: the handler (and the Report Generator admin preview's
     identical block, inc/spp-report-generator-admin.php) both hardcoded
     'default_dir' => 'asc' unconditionally -- a report definition had no
     way to ask for a descending default, so ClubRating always rendered
     lowest-first on load, the opposite of a ratings leaderboard's
     expected highest-first order (which read, in practice, as "the sort
     isn't applying"). No hardcoded ORDER BY or report-variant setting
     was involved -- spp_report_ladder_ratings()'s own query has no
     ORDER BY, and spp_report_variants (inc/spp-report-variants.php)
     stores only columns/no_sort/per_page/css, nothing sort-related, so
     it couldn't have been overriding this even when a variant is in
     play. Column casing matched too (ClubRating column key vs. the
     query's own ClubRating alias) -- no aliasing bug there either.
     FIXED: 'default_sort' can now optionally be
     ['column' => ..., 'direction' => 'asc'|'desc'] instead of a bare
     column-name string -- see the new spp_report_resolve_default_sort()
     helper below, now the single place both the shortcode handler and
     the admin preview resolve a definition's default_sort against the
     effective column list. A bare string (every other report definition
     today: 'Rank', 'last_name') keeps resolving to 'asc', unchanged --
     fully backward compatible, verified against master_list/membership/
     results, none of which specify a direction. ladder_ratings is the
     first (and so far only) report to use the array form, set to
     ['column' => 'ClubRating', 'direction' => 'DESC'].

   Changes from 1.4.0:
   - SECURITY FIX (found via full audit of the editable-column
     functionality): spp_report_results() had no capability check of
     its own, relying only on the shortcode's blanket is_user_logged_in()
     floor -- any logged-in member could view per-player RankOverride/
     RankCalc_Shadow/internal ranking mechanics via a saved variant
     (results-variant-2, page 20010698), even though only the editable
     RankOverride cell itself was gated to spp_is_admin_or_editor().
     FIXED: spp_report_results() now gates its entire output on
     spp_is_admin_or_editor() (same check as its own edit path,
     spp-report-edit.php), returning a plain access-denied notice row
     instead of real data for anyone else. No other report in the
     registry carries this class of internal data, so none of the
     others needed the same treatment.

   Changes from 1.3.0:
   - [spp_report] now requires a logged-in visitor, full stop -- one
     is_user_logged_in() check at the very top of the shortcode handler,
     before shortcode_atts() or any report definition runs. This is a
     site-wide policy gate, not a per-report judgment call: it covers
     every report in the registry and every saved variant, present and
     future, with no per-report opt-out and no exceptions (including
     ladder_ratings, previously reasoned to be fine ungated -- see the
     now-corrected ACCESS note below). Logged-out visitors get a plain
     message, same pattern/tone as spp_score_entry_shortcode()'s own
     login gate (inc/spp-score-entry.php): '<p>Please log in to view
     this report.</p>'.
     Prompted by spp_report_membership() (1.1.0, above): unlike
     ladder_ratings, it returns all 452 members unfiltered, including
     phone/email -- real PII that was reachable by anyone with the URL,
     logged in or not, since this shortcode had no gate of its own.
     The Report Generator admin screen's live preview is unaffected --
     it calls spp_render_report_table() directly (inc/spp-report-
     generator-admin.php), never through do_shortcode() or this
     closure, so it was never subject to this gate (or its absence) in
     the first place; it has its own, separate wp-admin-level gate
     (admin screen's own administrator-only check).

   Changes from 1.2.0:
   - Rows-per-page is now a variant-configurable setting, same
     treatment as columns=/no_sort=: added spp_report_per_page_choices()
     (the canonical enum: 10/25/50/100/'All' -- used by the Report
     Generator admin form's <select>, this shortcode's own
     per_page_options, and to validate a saved variant's stored value)
     and spp_report_sanitize_per_page() (validates/normalizes any
     candidate value against that enum, falling back to 'All'). The
     [spp_report] shortcode gets a new per_page= attribute, resolved
     with the same explicit-attribute-wins-over-saved-variant
     precedence as columns=/no_sort=. The system-wide fallback (no
     variant, no attribute) is now 'All' (no pagination), replacing the
     previous hardcoded 50 -- a deliberate behavior change, not just an
     additive one: an existing [spp_report table="ladder_ratings"] with
     no per_page= now renders unpaginated where it previously defaulted
     to 50/page. spp_get_report_variant()/spp_save_report_variant()
     (inc/spp-report-variants.php 1.1.0+) both grew a per_page
     parameter/return key to carry this through.

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

   ACCESS (superseded by 1.4.0, kept for history -- see that entry
   above): this used to read "no capability check here deliberately...
   the source query is filtered to Ladder='Yes' members only, not
   anything sensitive beyond what's already shown on the public-facing
   Ladder - Master List / Club Membership list pages." That reasoning
   covered spp_report_ladder_ratings() but was never revisited when
   spp_report_membership() (1.1.0) added an unfiltered, all-columns,
   all-452-members report (phone/email included) to the exact same
   ungated shortcode -- real PII, reachable by anyone with the URL.
   1.4.0 replaces this per-report judgment call with a single, blanket
   is_user_logged_in() gate in the shortcode handler itself: every
   report and every variant now requires a logged-in visitor, no
   exceptions, no per-report opt-out. A future report that needs
   stricter-than-logged-in gating (e.g. admin-only) still adds that
   inside its own definition function, same as before -- this file's
   gate is a floor, not a ceiling.

   UPDATE (2026-09-06) -- variant support, plus columns=/no_sort=/
   per_page= shortcode attributes, added for the new Report Generator
   admin screen (inc/spp-report-generator-admin.php). All are additive
   to the shortcode handler below; the registry and every existing
   definition function (spp_report_ladder_ratings included) are
   unchanged. Resolution order, per-request:
     1. Is `table` a saved variant name (spp_get_report_variant(),
        inc/spp-report-variants.php)? If so, its base_table/columns/
        no_sort/per_page become the starting point.
     2. Otherwise, `table` must be a registry name directly -- exactly
        today's behavior, unchanged.
     3. Explicit columns=/no_sort=/per_page= attributes on the
        shortcode tag itself, if present, override whatever step 1 or 2
        produced -- an explicit attribute always wins over a saved
        variant's stored defaults.
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
    'ladder_ratings'       => 'spp_report_ladder_ratings',
    'ladder_ratings_only'  => 'spp_report_ladder_ratings_only',
    'queen_ratings'        => 'spp_report_queen_ratings',
    'ace_ratings'          => 'spp_report_ace_ratings',
    'membership'           => 'spp_report_membership',
    'master_list'          => 'spp_report_master',
    'results'              => 'spp_report_results',
    'courts'               => 'spp_report_courts',
    'times'                => 'spp_report_times',
    'preferred_new'        => 'spp_report_preferred_new',
    'preferred_permanent'  => 'spp_report_preferred_permanent',
    'membership_tags'      => 'spp_report_membership_tags',
    'kq_history'           => 'spp_report_kq_history',
    'schedule_current'     => 'spp_report_schedule_current',
    'recent_results'       => 'spp_report_recent_results',
    'event_status'         => 'spp_report_event_status',
);

/**
 * Results override edit report:
 * source the results and membership table.
 *
 * ACCESS CONTROL: gated to spp_is_admin_or_editor() -- same check as
 * this report's own editable RankOverride cell (spp-report-edit.php's
 * spp_ajax_save_report_cell()), so view and edit permission stay
 * symmetric. This is NOT covered by the [spp_report] shortcode's
 * blanket is_user_logged_in() floor (spp-reports.php 1.4.0) -- that
 * floor was written for member-facing reports (Ladder Ratings,
 * Membership); this report exposes per-player RankOverride/
 * RankCalc_Shadow internal ranking mechanics, admin/editor territory,
 * confirmed live-reachable by any logged-in member via
 * results-variant-2 (page 20010698) before this check existed. Per
 * this file's own documented convention, a report needing
 * stricter-than-logged-in gating adds it inside its own definition
 * function -- this is that.
 */
function spp_report_results() {
    global $wpdb;

    if ( ! function_exists( 'spp_is_admin_or_editor' ) || ! spp_is_admin_or_editor() ) {
        return array(
            'columns' => array(
                array( 'key' => 'notice', 'label' => 'Notice', 'sortable' => false ),
            ),
            'rows' => array(
                array( 'notice' => 'You do not have permission to view this report.' ),
            ),
        );
    }

    $rows = $wpdb->get_results(
        "SELECT user_id, Rank, RankPrev, RankCalc, RankOverride, RankCalc_Shadow, group_id, Score, event_id, display_name
         FROM Results",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'user_id',  'label' => 'User',        'sortable' => true ),
        array( 'key' => 'Rank',       'label' => 'Rank',        'sortable' => true ),
        array( 'key' => 'RankPrev', 'label' => 'RankPrev', 'sortable' => true ),
        array( 'key' => 'RankCalc', 'label' => 'RankCalc', 'sortable' => true ),
        array(
            'key'           => 'RankOverride',
            'label'         => 'Override',
            'sortable'      => true,
            'editable'      => true,
            'edit_type'     => 'decimal',
            'edit_nullable' => true,
            'edit_decimals' => 2,
        ),
        array( 'key' => 'RankCalc_Shadow', 'label' => 'RankCalc_Shadow',  'sortable' => true ),
        array( 'key' => 'group_id',  'label' => 'Group',   'sortable' => true ),
        array( 'key' => 'Score',  'label' => 'Score',   'sortable' => true ),
        array( 'key' => 'event_id',  'label' => 'Event',   'sortable' => true ),
        array( 'key' => 'display_name',  'label' => 'Name',   'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => 'Rank',
        'edit'         => array(
            'table'      => 'Results',
            'key_column' => 'user_id',
        ),
    );
}


/**
 * Recent results report: replaces the WPDA "LadderRanking" app (app_id=9,
 * table Results, page 20000903 "Rankings from most recent results").
 * See this file's 1.13.0 changelog entry for the full investigation,
 * including the RankOverride exposure discrepancy found there -- app 9's
 * real config includes RankOverride, reproduced here deliberately, not
 * an oversight. Distinct from spp_report_results()/'results': that
 * report is the admin/editor-only override-editing tool (adds
 * RankCalc_Shadow, an editable RankOverride cell, edit wiring to the
 * Results table); this one is app 9's real, narrower, member-facing
 * read-only column set.
 *
 * ACCESS: no report-specific gate -- just the shortcode's blanket
 * is_user_logged_in() floor, matching app 9's own real current exposure
 * (its only live embedding page, 20000903, has no admin/editor page gate
 * either). Revisit if Gaetan decides RankOverride shouldn't be
 * member-visible once this is actually wired into a page.
 */
function spp_report_recent_results() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT Rank, display_name, RankPrev, RankCalc, RankOverride, Score, event_id, user_id, group_id
         FROM Results",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Rank',         'label' => 'Rank',         'sortable' => true ),
        array( 'key' => 'display_name', 'label' => 'Display Name', 'sortable' => false ),
        array( 'key' => 'RankPrev',     'label' => 'RankPrev',     'sortable' => false ),
        array( 'key' => 'RankCalc',     'label' => 'RankCalc',     'sortable' => false ),
        array( 'key' => 'RankOverride', 'label' => 'RankOverride', 'sortable' => true ),
        array( 'key' => 'Score',        'label' => 'Score',        'sortable' => true ),
        array( 'key' => 'event_id',     'label' => 'Event Id',     'sortable' => false ),
        array( 'key' => 'user_id',      'label' => 'User Id',      'sortable' => false ),
        array( 'key' => 'group_id',     'label' => 'Group Id',     'sortable' => false ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => 'Rank',
    );
}

/**
 * Event status report: reproduces the live GL Event Status page's own
 * aggregate query ([gl_event_registrations], wp-content/plugins/gl-events/
 * public/gl-event-registrations.php) as a real, sortable [spp_report]
 * entry. See this file's 1.14.0 changelog entry for the full reasoning,
 * including why the Date column's value is stored zero-padded/ISO-ish
 * rather than in the source page's human display format (sortability
 * under spp_render_report_table()'s plain string/numeric compare).
 *
 * Date scope: event_date >= CURDATE(), no upper cutoff -- deliberately
 * unbounded, unlike the source page's rolling 5-week window.
 *
 * ACCESS: no report-specific gate -- matches [gl_event_registrations]'s
 * own real access level (no capability/login check of its own); only
 * the [spp_report] shortcode's blanket is_user_logged_in() floor applies.
 */
function spp_report_event_status() {
    global $wpdb;
    $p = $wpdb->prefix;

    $raw_rows = $wpdb->get_results(
        "SELECT
            o.id                AS occurrence_id,
            o.event_date        AS event_date,
            o.event_time        AS event_time,
            o.title             AS Event,
            COALESCE(c.name, '') AS Category,
            o.capacity          AS capacity_raw,
            COUNT( DISTINCT CASE WHEN r.status = 'confirmed' THEN r.user_id END ) AS Confirmed,
            COUNT( DISTINCT CASE WHEN r.status = 'waiting'   THEN r.user_id END ) AS Waiting,
            COUNT( DISTINCT CASE WHEN r.status = 'withdrawn' THEN r.user_id END ) AS Withdrawn
         FROM {$p}gl_event_occurrences o
         LEFT JOIN {$p}gl_event_categories c ON o.category_id = c.id
         LEFT JOIN {$p}gl_registrations r    ON r.occurrence_id = o.id
         WHERE o.cancelled = 0
         AND o.event_date >= CURDATE()
         GROUP BY o.id, o.event_date, o.event_time, o.title, o.capacity, c.name",
        ARRAY_A
    );

    $rows = array();
    foreach ( $raw_rows as $r ) {
        $time_part = $r['event_time'] ? substr( $r['event_time'], 0, 5 ) : '00:00';
        $rows[] = array(
            'Date'      => $r['event_date'] . ' ' . $time_part,
            'Event'     => $r['Event'],
            'Category'  => $r['Category'],
            'Confirmed' => (int) $r['Confirmed'],
            'Capacity'  => ( (int) $r['capacity_raw'] > 0 ) ? (int) $r['capacity_raw'] : 'n/a',
            'Waiting'   => (int) $r['Waiting'],
            'Withdrawn' => (int) $r['Withdrawn'],
        );
    }

    $columns = array(
        array( 'key' => 'Date',      'label' => 'Date',      'sortable' => true ),
        array( 'key' => 'Event',     'label' => 'Event',     'sortable' => true ),
        array( 'key' => 'Category',  'label' => 'Category',  'sortable' => true ),
        array( 'key' => 'Confirmed', 'label' => 'Confirmed', 'sortable' => true ),
        array( 'key' => 'Capacity',  'label' => 'Capacity',  'sortable' => true ),
        array( 'key' => 'Waiting',   'label' => 'Waiting',   'sortable' => true ),
        array( 'key' => 'Withdrawn', 'label' => 'Withdrawn', 'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => 'Date',
    );
}

/**
 * Current schedule report: replaces the WPDA "ShowSchedules" app
 * (app_id=4, table schedules_w, page 20005754 "This week's Schedule").
 * See this file's 1.12.0 changelog entry for the full investigation --
 * app 4 is live and distinct in purpose from [spp_player_schedule_view],
 * not a duplicate.
 *
 * Sources Schedules/Times/Groups/Courts directly (the same JOIN
 * schedules_w's own CREATE VIEW uses) rather than querying schedules_w
 * itself, to avoid that view's repointable-VIEW race/staleness risk --
 * same fix gl-player-schedule-view.php's 1.6.5 changelog already
 * applied to this exact data for this exact reason.
 *
 * ACCESS CONTROL: gated to spp_is_admin_or_editor(), same pattern as
 * spp_report_results() -- preserves app 4's real current exposure
 * level (see 1.12.0 changelog) rather than the shortcode's normal
 * blanket is_user_logged_in() floor. This report includes every
 * scheduled player's phone number, which today is only public once
 * spp_player_schedule_view's own publish flag is set.
 */
function spp_report_schedule_current() {
    global $wpdb;

    if ( ! function_exists( 'spp_is_admin_or_editor' ) || ! spp_is_admin_or_editor() ) {
        return array(
            'columns' => array(
                array( 'key' => 'notice', 'label' => 'Notice', 'sortable' => false ),
            ),
            'rows' => array(
                array( 'notice' => 'You do not have permission to view this report.' ),
            ),
        );
    }

    $rows = $wpdb->get_results(
        "SELECT Schedules.Rank, Schedules.first_name, Schedules.last_name,
                Schedules.user_phone, Times.T_desc, Schedules.travel AS Travel,
                Courts.Crt_name, Groups.GP_name, Schedules.user_id
         FROM Schedules
         JOIN Times   ON Schedules.time_id  = Times.T_ID
         JOIN Groups  ON Schedules.group_id = Groups.GP_ID
         JOIN Courts  ON Schedules.Crt_ID   = Courts.Crt_ID
         ORDER BY Times.T_desc ASC, Courts.Crt_name ASC, Schedules.Rank ASC",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Rank',       'label' => 'Rank',       'sortable' => true ),
        array( 'key' => 'first_name', 'label' => 'First Name', 'sortable' => false ),
        array( 'key' => 'last_name',  'label' => 'Last Name',  'sortable' => true ),
        array( 'key' => 'user_phone', 'label' => 'User Phone', 'sortable' => false ),
        array( 'key' => 'T_desc',     'label' => 'Times',     'sortable' => true ),
        array( 'key' => 'Travel',     'label' => 'Travel',     'sortable' => true ),
        array( 'key' => 'Crt_name',   'label' => 'Courts',   'sortable' => true ),
        array( 'key' => 'GP_name',    'label' => 'Groups',    'sortable' => true ),
        array( 'key' => 'user_id',    'label' => 'User code',       'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => 'T_desc',
    );
}

/**
 * Ladder Ratings report: Rank, Club Rating, DUPR, First Name, Last Name
 * for every currently active-ladder (Ladder='Yes') member, sourced from
 * the membership table (already enriched with ClubRating/DUPR).
 */
function spp_report_ladder_ratings() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT Rank, ClubRating, RatingGames, DUPR, KQAceRank, KQQueenRank, Ladder, first_name, last_name
         FROM membership
         WHERE ClubRating > 0",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Rank',       'label' => 'Rank',        'sortable' => true ),
        array( 'key' => 'ClubRating', 'label' => 'SPP Rating', 'sortable' => true ),
        array( 'key' => 'RatingGames', 'label' => 'Games', 'sortable' => true ),
        array( 'key' => 'DUPR',       'label' => 'DUPR',        'sortable' => true ),
        // Ace/Queen of the Courts format rankings (inc/spp-kq-format-
        // ranking.php) -- NULL for anyone who's never had a KQ event in
        // that format count toward it, rendered blank same as any other
        // NULL column here.
        array( 'key' => 'KQAceRank',   'label' => 'Ace Rank',   'sortable' => true ),
        array( 'key' => 'KQQueenRank', 'label' => 'Queen Rank', 'sortable' => true ),
        array( 'key' => 'Ladder',       'label' => 'Ladder',        'sortable' => true ),
        array( 'key' => 'first_name', 'label' => 'First Name',  'sortable' => true ),
        array( 'key' => 'last_name',  'label' => 'Last Name',   'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => array( 'column' => 'ClubRating', 'direction' => 'DESC' ),
    );
}

/**
 * Ladder Ratings, filtered to active-ladder members only: same columns/
 * source as spp_report_ladder_ratings(), WHERE Ladder = 'Yes' (same flag
 * spp_report_master() already filters on), sorted ascending by Rank.
 * Same access level as spp_report_ladder_ratings() -- no per-report
 * capability check beyond the shortcode's blanket is_user_logged_in()
 * floor.
 */
function spp_report_ladder_ratings_only() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT Rank, ClubRating, RatingGames, DUPR, KQAceRank, KQQueenRank, Ladder, first_name, last_name
         FROM membership
         WHERE Ladder = 'Yes'",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Rank',       'label' => 'Rank',        'sortable' => true ),
        array( 'key' => 'ClubRating', 'label' => 'SPP Rating', 'sortable' => true ),
        array( 'key' => 'RatingGames', 'label' => 'Games', 'sortable' => true ),
        array( 'key' => 'DUPR',       'label' => 'DUPR',        'sortable' => true ),
        array( 'key' => 'KQAceRank',   'label' => 'Ace Rank',   'sortable' => true ),
        array( 'key' => 'KQQueenRank', 'label' => 'Queen Rank', 'sortable' => true ),
        array( 'key' => 'Ladder',       'label' => 'Ladder',        'sortable' => true ),
        array( 'key' => 'first_name', 'label' => 'First Name',  'sortable' => true ),
        array( 'key' => 'last_name',  'label' => 'Last Name',   'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => array( 'column' => 'Rank', 'direction' => 'ASC' ),
    );
}

/**
 * Queen of the Courts format ranking: same columns/source as
 * spp_report_ladder_ratings(), filtered to members with a real
 * (non-NULL) KQQueenRank, sorted ascending by that rank. Deliberately
 * does NOT also require ClubRating > 0 -- KQQueenRank is pivoted from
 * its own usermeta (spp_kq_queen_rank, inc/spp-create-membership-
 * table.php) independent of ladder ClubRating, so a Queen-only player
 * with no ladder rating still belongs here. Same access level as
 * spp_report_ladder_ratings() -- no per-report capability check beyond
 * the shortcode's blanket is_user_logged_in() floor.
 */
function spp_report_queen_ratings() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT Rank, ClubRating, RatingGames, DUPR, KQAceRank, KQQueenRank, Ladder, first_name, last_name
         FROM membership
         WHERE KQQueenRank IS NOT NULL",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Rank',       'label' => 'Rank',        'sortable' => true ),
        array( 'key' => 'ClubRating', 'label' => 'SPP Rating', 'sortable' => true ),
        array( 'key' => 'RatingGames', 'label' => 'Games', 'sortable' => true ),
        array( 'key' => 'DUPR',       'label' => 'DUPR',        'sortable' => true ),
        array( 'key' => 'KQAceRank',   'label' => 'Ace Rank',   'sortable' => true ),
        array( 'key' => 'KQQueenRank', 'label' => 'Queen Rank', 'sortable' => true ),
        array( 'key' => 'Ladder',       'label' => 'Ladder',        'sortable' => true ),
        array( 'key' => 'first_name', 'label' => 'First Name',  'sortable' => true ),
        array( 'key' => 'last_name',  'label' => 'Last Name',   'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => array( 'column' => 'KQQueenRank', 'direction' => 'ASC' ),
    );
}

/**
 * Ace of the Courts format ranking: same columns/source as
 * spp_report_ladder_ratings(), filtered to members with a real
 * (non-NULL) KQAceRank, sorted ascending by that rank. Same
 * ClubRating-independence reasoning as spp_report_queen_ratings() above.
 * Same access level as spp_report_ladder_ratings() -- no per-report
 * capability check beyond the shortcode's blanket is_user_logged_in()
 * floor. Expected to render zero rows (headers still shown, per
 * spp_render_report_table()'s empty-report handling) until an
 * Ace-format KQ event has been processed into KQAceRank.
 */
function spp_report_ace_ratings() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT Rank, ClubRating, RatingGames, DUPR, KQAceRank, KQQueenRank, Ladder, first_name, last_name
         FROM membership
         WHERE KQAceRank IS NOT NULL",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Rank',       'label' => 'Rank',        'sortable' => true ),
        array( 'key' => 'ClubRating', 'label' => 'SPP Rating', 'sortable' => true ),
        array( 'key' => 'RatingGames', 'label' => 'Games', 'sortable' => true ),
        array( 'key' => 'DUPR',       'label' => 'DUPR',        'sortable' => true ),
        array( 'key' => 'KQAceRank',   'label' => 'Ace Rank',   'sortable' => true ),
        array( 'key' => 'KQQueenRank', 'label' => 'Queen Rank', 'sortable' => true ),
        array( 'key' => 'Ladder',       'label' => 'Ladder',        'sortable' => true ),
        array( 'key' => 'first_name', 'label' => 'First Name',  'sortable' => true ),
        array( 'key' => 'last_name',  'label' => 'Last Name',   'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => array( 'column' => 'KQAceRank', 'direction' => 'ASC' ),
    );
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
        "SELECT Rank, Rating, ClubRating, RatingGames, DUPR, Ladder, Tag,
                first_name, last_name, travel, user_phone, user_email,
                PCO, user_id
         FROM membership
         ORDER BY last_name ASC, first_name ASC",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Rank',       'label' => 'Rank',       'sortable' => true ),
        array( 'key' => 'Rating',     'label' => 'Rating',     'sortable' => true ),
        array( 'key' => 'ClubRating', 'label' => 'SPPRating', 'sortable' => true ),
        array( 'key' => 'RatingGames', 'label' => 'Games', 'sortable' => true ),
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
 * Masterlist report: all tracked columns for every member of the ladder, unfiltered
 * -- replaces the WPDA "Ladder - Master List" app (app_id=24, title
 * "masterList", table Master, page 1517 -- the app actually still live
 * and embedded there today via [wpda_app app_id="24"], run in parallel
 * with this report's own page). CORRECTED (Phase 2 audit, 2026-09-13):
 * this docblock previously named app_id=18 as the app being replaced --
 * that app_id is in fact a dead, unembedded app that happens to share
 * the same "Master" app_name/table as app 24 (title "List of Ladder
 * Players" vs. 24's "Ladder - Master List"), which is what caused the
 * mix-up. app_id=18 is harmless context (the original/retired app_id
 * for this table) but was never the one actually serving traffic here.
 * Same 11 columns/order/labels that app renders today,
 * plus ClubRating and DUPR (selected in that app's own column picker
 * but never actually wired into its rendered column list). Every
 * column here is sortable, unlike the WPDA app (last_name only) --
 * intentional improvement, not an oversight.
 *
 * ORDER BY last_name, first_name here
 */
function spp_report_master() {
    global $wpdb;
 
    $rows = $wpdb->get_results(
        "SELECT Rank, Rating, ClubRating, RatingGames, DUPR, Ladder, Tag,
                first_name, last_name, travel, user_phone, user_email,
                PCO, user_id
         FROM membership
         where Ladder  = 'Yes'
         ORDER BY last_name ASC, first_name ASC",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Rank',       'label' => 'Rank',       'sortable' => true ),
        array( 'key' => 'Rating',     'label' => 'Rating',     'sortable' => true ),
        array( 'key' => 'ClubRating', 'label' => 'SPPRating', 'sortable' => true ),
        array( 'key' => 'RatingGames', 'label' => 'Games', 'sortable' => true ),
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
        'default_sort' => 'Rank',
    );
}

/**
 * Courts report: replaces the WPDA "Courts" app (app_id=12, table
 * Courts, page 20006436 "Edit Courts" -- linked in the Editor > Schedules
 * nav). 8 rows.
 *
 * PARITY NOTE: WPDA's own config for this app has
 * transactions.insert/delete = true (an admin could add/remove a court
 * row directly in that UI), in addition to update. This shared registry
 * (spp_render_report_table() + spp-report-edit.php's
 * spp_ajax_save_report_cell()) only ever UPDATEs an existing row's
 * editable columns -- there is no add-row/delete-row mechanism here at
 * all. So this report reproduces Courts' two inline-editable columns
 * (Crt_name, active) exactly, but does NOT reproduce add/remove-a-court
 * -- that still requires the old WPDA page (left live, per this
 * migration's own instructions) or a direct DB change until row-level
 * insert/delete is added to the shared renderer.
 *
 * Sortability mirrors WPDA's own orderable flags exactly: only `active`
 * was orderable there (Crt_ID/Crt_name were not) -- not an oversight,
 * matching what today's app actually allows.
 */
function spp_report_courts() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT Crt_ID, Crt_name, active FROM Courts ORDER BY Crt_ID ASC",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'Crt_ID',   'label' => 'Crt ID',  'sortable' => false ),
        array(
            'key'           => 'Crt_name',
            'label'         => 'Crt Name',
            'sortable'      => false,
            'editable'      => true,
            'edit_type'     => 'text',
            'edit_nullable' => true,
        ),
        array(
            'key'           => 'active',
            'label'         => 'Active',
            'sortable'      => true,
            'editable'      => true,
            'edit_type'     => 'integer',
            'edit_nullable' => false,
        ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => 'Crt_ID',
        'edit'         => array(
            'table'      => 'Courts',
            'key_column' => 'Crt_ID',
        ),
    );
}

/**
 * Times report: replaces the WPDA "Times" app (app_id=30, table Times,
 * page 20007885 "Edit Times" -- linked in the Editor > Schedules nav).
 * 3 rows.
 *
 * PARITY NOTE: WPDA's transactions.insert/update/delete are all true
 * for this app, but every one of its columns has inlineEditing:false --
 * meaning no per-cell inline edit was actually exposed to users despite
 * the table-level flags. This report is therefore genuinely read-only
 * (no 'edit' key), an exact behavioral match, not a reduction -- only
 * add/remove-a-time-slot (a row-level, not cell-level, WPDA capability)
 * is not reproduced, same shared-renderer limitation as Courts above.
 */
function spp_report_times() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT T_ID, T_desc, Active FROM Times ORDER BY T_ID ASC",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'T_ID',   'label' => 'T ID',   'sortable' => true ),
        array(
            'key'           => 'T_desc',
            'label'         => 'T Desc',
            'sortable'      => false,
            'editable'      => true,
            'edit_type'     => 'text',
            'edit_nullable' => true,
        ),
        array(
            'key'           => 'Active',
            'label'         => 'Active',
            'sortable'      => true,
            'editable'      => true,
            'edit_type'     => 'integer',
            'edit_nullable' => false,
        ),
        
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => 'T_ID',
        'edit'         => array(
            'table'      => 'Times',
            'key_column' => 'T_ID',
    )
    );
}

/**
 * Preferred (travel-time) registrations report: replaces the WPDA
 * "Preferred New" app (app_id=29, table preferred_new, page 20007853
 * "Preferred" -- linked in the Editor > Schedules nav). 7 rows.
 *
 * PARITY NOTE: WPDA's transactions are all false for this app (pure
 * read-only view there too) and every column has inlineEditing:false --
 * this report is read-only (no 'edit' key), an exact match.
 * defaultWhere excludes a fixed list of user_ids (site accounts /
 * one-off exclusions, not ladder members) -- reproduced verbatim below
 * rather than reinterpreted, since the original intent behind each
 * excluded id isn't recorded anywhere else. WPDA itself had no default
 * sort column (empty defaultOrderBy); 'Rank' is used here as the
 * default, the same fallback this registry already applies everywhere
 * else a report doesn't have an obvious sort of its own (see
 * spp_report_resolve_default_sort()).
 *
 * ACCESS CONTROL (added per the access-gating audit, see this file's
 * version history): gated to spp_is_admin_or_editor(), same pattern as
 * spp_report_results()/spp_report_membership_tags() -- this report's
 * user_phone/travel columns are real member PII, and its live page was
 * reachable by any logged-in member with only a hidden Editor-nav-dropdown
 * link standing in the way, a protection this codebase has already
 * documented elsewhere (the MembershipTags refresh wrapper) as not
 * actually restricting access.
 */
function spp_report_preferred_new() {
    global $wpdb;

    if ( ! function_exists( 'spp_is_admin_or_editor' ) || ! spp_is_admin_or_editor() ) {
        return array(
            'columns' => array(
                array( 'key' => 'notice', 'label' => 'Notice', 'sortable' => false ),
            ),
            'rows' => array(
                array( 'notice' => 'You do not have permission to view this report.' ),
            ),
        );
    }

    $rows = $wpdb->get_results(
        "SELECT user_id, Rank, first_name, last_name, user_phone, travel
         FROM preferred_new
         WHERE user_id NOT IN (1,2193,2101,2144,2106,2289,2880)",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'user_id',    'label' => 'User Id',    'sortable' => true ),
        array( 'key' => 'Rank',       'label' => 'Rank',       'sortable' => true ),
        array( 'key' => 'first_name', 'label' => 'First Name', 'sortable' => true ),
        array( 'key' => 'last_name',  'label' => 'Last Name',  'sortable' => true ),
        array( 'key' => 'user_phone', 'label' => 'User Phone', 'sortable' => true ),
        array( 'key' => 'travel',     'label' => 'Travel',     'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => 'Rank',
    );
}

/**
 * Preferred Permanent (standing travel-time preference) registrations
 * report: replaces the WPDA "Preferred Permanent" app (app_id=31, table
 * preferred_permanent, page 20007891 -- linked in the Editor >
 * Schedules nav). 7 rows.
 *
 * PARITY NOTE: unlike preferred_new, WPDA's transactions.insert/update/
 * delete are all true here -- but, same as Times above, every column
 * still has inlineEditing:false, so no per-cell inline edit was actually
 * exposed. This report is read-only (no 'edit' key), a faithful match
 * of what was editable in place (nothing); only add/remove-a-standing-
 * preference (a row-level WPDA capability) is not reproduced, same
 * shared-renderer limitation noted on Courts/Times above. No defaultWhere
 * filter existed for this app (unlike preferred_new); none applied here
 * either. WPDA had no default sort column here either -- 'Rank' used as
 * the same fallback default as preferred_new.
 *
 * ACCESS CONTROL (added per the access-gating audit, see this file's
 * version history): gated to spp_is_admin_or_editor(), same pattern as
 * spp_report_results()/spp_report_membership_tags() -- same reasoning as
 * preferred_new above, and the higher-urgency of the two: this table
 * has 7 live rows with real phone numbers today, not an empty result.
 */
function spp_report_preferred_permanent() {
    global $wpdb;

    if ( ! function_exists( 'spp_is_admin_or_editor' ) || ! spp_is_admin_or_editor() ) {
        return array(
            'columns' => array(
                array( 'key' => 'notice', 'label' => 'Notice', 'sortable' => false ),
            ),
            'rows' => array(
                array( 'notice' => 'You do not have permission to view this report.' ),
            ),
        );
    }

    $rows = $wpdb->get_results(
        "SELECT user_id, Rank, first_name, last_name, user_phone, travel
         FROM preferred_permanent",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'user_id',    'label' => 'User Id',    'sortable' => true ),
        array( 'key' => 'Rank',       'label' => 'Rank',       'sortable' => true ),
        array( 'key' => 'first_name', 'label' => 'First Name', 'sortable' => true ),
        array( 'key' => 'last_name',  'label' => 'Last Name',  'sortable' => true ),
        array( 'key' => 'user_phone', 'label' => 'User Phone', 'sortable' => true ),
        array( 'key' => 'travel',     'label' => 'Travel',     'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => 'Rank',
    );
}

/**
 * Membership Tags report: replaces the WPDA "MembershipTags" app
 * (app_id=3, table MembershipTags, page 20005729 "Record membership tag
 * numbers"). 452 rows. Renders the same 5-column subset that app's own
 * config selects (user_id, Balls, Tag, first_name, last_name) out of
 * MembershipTags' much wider schema -- not the full table, matching
 * what was actually curated/visible there.
 *
 * ACCESS CONTROL -- deliberately NOT covered by just the [spp_report]
 * shortcode's blanket is_user_logged_in() floor: WPDA's own app_settings
 * for app_id=3 restricts its REST API to
 * authorized_roles: [administrator, editor] only -- i.e. today, any
 * member who loaded page 20005729 would see the app's empty shell but
 * could not actually pull row data through it without that role. This
 * report's own theme-level template_redirect gate
 * ($migrated_admin_tool_shortcodes in functions.php) does NOT cover
 * [spp_report] either (that list gates specific one-off legacy shortcode
 * tags, and spp_report is one shared tag serving many reports of very
 * different sensitivity -- adding it there would wrongly gate
 * ladder_ratings/membership too). So, same as spp_report_results()
 * before it, this report gates its own output on spp_is_admin_or_editor()
 * to preserve the exact protection level WPDA's REST API already
 * enforced, rather than silently loosening view access to every logged-
 * in member the moment this page's shortcode is swapped.
 * Editing (Balls/Tag) was already separately gated to
 * spp_is_admin_or_editor() regardless, by spp_render_report_table()'s
 * own $can_edit check -- this only changes VIEW access, to match.
 *
 * NOT touched by this change: inc/spp-membership-tags-refresh-ui.php's
 * own spp_membership_tags_refresh_ui() shortcode (used via
 * [cmruncode name='Membership tags table refresh']) still rebuilds
 * MembershipTags from usermeta and then displays the result via
 * do_shortcode('[wpda_app app_id="3"]') -- a distinct
 * refresh-then-immediately-show workflow, not just a display. Left
 * pointed at the old WPDA app deliberately: repointing it at this new
 * report is a separate decision outside this migration's five targets,
 * and this migration is explicitly leaving the old app itself live and
 * usable in the meantime.
 */
function spp_report_membership_tags() {
    global $wpdb;

    if ( ! function_exists( 'spp_is_admin_or_editor' ) || ! spp_is_admin_or_editor() ) {
        return array(
            'columns' => array(
                array( 'key' => 'notice', 'label' => 'Notice', 'sortable' => false ),
            ),
            'rows' => array(
                array( 'notice' => 'You do not have permission to view this report.' ),
            ),
        );
    }

    $rows = $wpdb->get_results(
        "SELECT user_id, Balls, Tag, first_name, last_name
         FROM MembershipTags
         ORDER BY last_name ASC, first_name ASC",
        ARRAY_A
    );

    $columns = array(
        array( 'key' => 'user_id',    'label' => 'User Id',    'sortable' => false ),
        array(
            'key'           => 'Balls',
            'label'         => 'Balls',
            'sortable'      => true,
            'editable'      => true,
            'edit_type'     => 'integer',
            'edit_nullable' => true,
        ),
        array(
            'key'           => 'Tag',
            'label'         => 'Tag',
            'sortable'      => true,
            'editable'      => true,
            'edit_type'     => 'integer',
            'edit_nullable' => true,
        ),
        array( 'key' => 'first_name', 'label' => 'First Name', 'sortable' => false ),
        array( 'key' => 'last_name',  'label' => 'Last Name',  'sortable' => true ),
    );

    return array(
        'columns'      => $columns,
        'rows'         => $rows,
        'default_sort' => array( 'column' => 'last_name', 'direction' => 'ASC' ),
        'edit'         => array(
            'table'      => 'MembershipTags',
            'key_column' => 'user_id',
        ),
    );
}

/**
 * Canonical enum of rows-per-page choices, used everywhere per_page is
 * surfaced: the Report Generator admin form's <select>, the actual
 * [spp_report] shortcode's per_page_options (the dropdown a visitor
 * sees), and validation of both a saved variant's per_page column and
 * an explicit per_page= shortcode attribute. 'All' means no pagination
 * -- the exact string spp_render_report_table() already tests for
 * ($per_page_raw === 'All'), not 'all' or any other casing/spelling.
 */
function spp_report_per_page_choices() {
    return array( 10, 25, 50, 100, 'All' );
}

/**
 * Validate/normalize a per_page value (from a saved variant or an
 * explicit shortcode attribute) against spp_report_per_page_choices().
 * Anything not an exact string match falls back to 'All' -- the
 * system-wide default -- rather than being fatal or silently ignored.
 */
function spp_report_sanitize_per_page( $value ) {
    $choices = array_map( 'strval', spp_report_per_page_choices() );
    $value   = (string) $value;
    return in_array( $value, $choices, true ) ? $value : 'All';
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

/**
 * Resolve a report definition's 'default_sort' value against the
 * effective column list actually being rendered (post columns=
 * filtering), and return the column key + direction spp_render_report_table()
 * should be told to use.
 *
 * A definition's 'default_sort' may be either:
 *   - a bare column-name string (e.g. 'Rank') -- backward compatible with
 *     every report definition that predates direction support; always
 *     resolves to 'asc', exactly as before this function existed.
 *   - ['column' => 'ClubRating', 'direction' => 'DESC'] -- direction is
 *     case-insensitive and defaults to 'asc' if omitted from the array.
 *
 * Falls back to the original Rank-if-present-else-first-column
 * heuristic (direction 'asc') when the definition doesn't set
 * 'default_sort' at all, or its column didn't survive a columns=
 * filter -- unchanged from the behavior before direction support existed.
 *
 * @param mixed $definition_default_sort The definition's 'default_sort' value (string, array, or absent/null).
 * @param array $effective_keys          Column keys actually being rendered, in order.
 * @return array ['column' => string, 'direction' => 'asc'|'desc']
 */
function spp_report_resolve_default_sort( $definition_default_sort, array $effective_keys ) {
    $column    = null;
    $direction = 'asc';

    if ( is_array( $definition_default_sort ) ) {
        $column    = $definition_default_sort['column'] ?? null;
        $direction = ( strtolower( (string) ( $definition_default_sort['direction'] ?? 'asc' ) ) === 'desc' ) ? 'desc' : 'asc';
    } elseif ( is_string( $definition_default_sort ) && $definition_default_sort !== '' ) {
        $column = $definition_default_sort;
    }

    if ( $column === null || ! in_array( $column, $effective_keys, true ) ) {
        // Fall back to the pre-existing heuristic, direction always 'asc'.
        $column    = in_array( 'Rank', $effective_keys, true ) ? 'Rank' : ( $effective_keys[0] ?? '' );
        $direction = 'asc';
    }

    return array( 'column' => $column, 'direction' => $direction );
}

add_shortcode( 'spp_report', function( $atts ) {
    // Site-wide policy, not a per-report judgment call: every report
    // and every variant requires a logged-in visitor, no exceptions,
    // no per-report opt-out. First thing in the handler, before
    // shortcode_atts() or any report definition runs -- see this
    // file's 1.4.0 changelog entry and the (superseded) ACCESS note
    // above for why. Same message pattern/tone as
    // spp_score_entry_shortcode()'s own login gate (inc/spp-score-entry.php).
    if ( ! is_user_logged_in() ) {
        return '<p>Please log in to view this report.</p>';
    }

    $atts = shortcode_atts( array(
        'table'    => '',
        'columns'  => '',
        'no_sort'  => '',
        'per_page' => '',
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
    $per_page     = $variant ? $variant['per_page'] : 'All';

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
    if ( $atts['per_page'] !== '' ) {
        $per_page = $atts['per_page'];
    }
    $per_page = spp_report_sanitize_per_page( $per_page ); // validates variant-sourced and attribute-sourced values alike

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
    // heuristic, unchanged from before this key existed. Direction comes
    // along with it -- see spp_report_resolve_default_sort().
    $effective_keys = array_column( $effective_columns, 'key' );
    $sort_resolved  = spp_report_resolve_default_sort( $definition['default_sort'] ?? null, $effective_keys );

    ob_start();
    spp_render_report_table( $effective_columns, $definition['rows'], array(
        'id'               => $name,
        'edit_report'      => $name,
        'default_sort'     => $sort_resolved['column'],
        'default_dir'      => $sort_resolved['direction'],
        'per_page_options' => spp_report_per_page_choices(),
        'default_per_page' => $per_page,
        'edit'             => $definition['edit'] ?? array(),
    ) );
    return ob_get_clean();
} );
