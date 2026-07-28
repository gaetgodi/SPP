# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Divi child theme (`divi-spp-child`) for a WordPress site run by Stouffville Pickleball Players
(pickleballstouffville.ca). There is no build step, package manager, or test suite — this is plain
PHP/CSS/JS loaded directly by WordPress. Files are edited and deployed straight to the WP theme
directory; "running" the code means loading it in the live/staging WP site. Live since 2016, ~150
players. This is production. Members use it weekly.

There is a `staging.pickleballstouffville.ca.code-workspace` file — this project is normally worked
on against a staging site, not a local dev server.

## Naming — read this first

Every function in this theme should be prefixed `spp_`. A collision with an unprefixed `gl_`
function caused a real production bug (Player Schedule View). Never introduce an unprefixed or
`gl_`-prefixed function name in this theme, with the deliberate exception of the GL Events plugin
integration itself (`gl-schedule-production.php`, `gl-publish-schedule.php`,
`gl-player-schedule-view.php`), which is a separate plugin surface by design.

## Structure

- `functions.php` — theme bootstrap: enqueues styles/scripts, `require_once`s everything in `inc/`,
  and defines a few cross-cutting hooks (role helpers, login/membership gating, page-access restriction).
- `inc/` — the actual application code. Each file is a self-contained feature, almost always exposing
  itself as a `[shortcode]` embedded in a WP page, plus optional `wp_ajax_*` handlers for its own AJAX
  calls. There is no shared router/framework beyond WordPress hooks.
- `mu-plugins/` — must-use plugins (always active, independent of the active theme). Similar
  shortcode/AJAX pattern to `inc/`, used for things that should survive a theme change (contact
  system, FAQ, testimonials, rich editor, avatar booth, WebAuthn/passkey authentication).
- `css/`, `js/` — theme assets, mostly one file per feature, enqueued conditionally (e.g. FAQ CSS/JS
  only loads on `is_page('faq')`). CSS token architecture: `spp-tokens.css`, `spp-drawers.css`,
  `spp-dashboard.css` — add tokens rather than hard-coding values.
- `tribe/events/` — template overrides for The Events Calendar (TEC) plugin.

## Core domain: the scheduling/ranking system

This is the heart of the codebase (most of the line count in `inc/`). It runs a recurring event
(weekly pickleball sessions) with skill-based group assignment and a ranking ladder. Key pieces,
roughly in pipeline order:

1. **Registration** — players register for an event via GL Events plugin
   (`{$wpdb->prefix}gl_registrations`, `gl_event_occurrences`). A legacy path via The Events Calendar
   Pro (`{$wpdb->prefix}rtec_entries`) still exists in `spp-schedule-production.php` for older/parallel
   flows — don't assume one table is the only source of truth; check which file you're in.
2. **Schedule production** (`gl-schedule-production.php`, shortcode `[spp_create_schedule]`) — the
   main algorithm. Reads registrants, builds `Master`/`Groups`/`Courts`/`Times`/`Schedules` tables,
   then runs multiple ranked "phases" (distribution, carpool pairing, travel-time preference swaps,
   rank-tolerance-bounded swaps) to balance groups by skill (`Rank`) while respecting travel-time
   preferences and carpool pairings. `spp-schedule-production.php` is the older/parallel version of
   this same pipeline (rtec_entries-based) — the version-history comment block at the top of
   `gl-schedule-production.php` is the best source of truth for current algorithm behavior; read it
   before changing scheduling logic.
3. **Publishing** (`gl-publish-schedule.php`, `[gl_publish_schedule]`) and **player-facing view**
   (`gl-player-schedule-view.php`, `[spp_player_schedule_view]`).
4. **Mid-event adjustments** (`spp-schedule-adjust.php`, `[spp_schedule_adjust]`) — dropout,
   last-minute add, group swap, rank override, player swap. Every mutating action follows a strict
   3-stage flow: **Propose** (no writes) → **Apply + Check** (writes, backs up `Schedules` to
   `Schedules_backup_<action>_<timestamp>`, runs `spp_run_schedule_check()`, no notifications yet) →
   convenor confirms **send notifications** or **cancel** (surgically reverts only the rows that
   action touched). Backup tables are intentionally not auto-dropped; they get cleaned up at the
   start of the next schedule production run. Preserve this flow when touching this file — it exists
   specifically so a bad change can be undone without clobbering concurrent/already-finalized changes.
5. **Schedule check** (`spp-schedule-check.php`, `[spp_schedule_check]`) — validation/diagnostic pass
   over a produced schedule, also invoked internally by the adjust tool after every mutation.
6. **Score entry & correction** (`spp-score-entry.php`, `spp-score-correction.php`) —
   players/convenors enter scores for their group; correction tool recalculates group rankings,
   `RankCalc`, and ordinal ranks after the fact.
7. **Results / ranking** (`spp-create-results.php`, `[spp_create_results]`) — end-of-event pass that
   builds `Results_{event}` tables and updates the ladder (`RankPrime`, `RankCalc`, `RankOverride`,
   `Rank`). Includes a parallel "shadow" ranking calculation (writes to `Results_shadow_{event}`,
   reports a before/after comparison) used to test-drive ranking algorithm changes against real data
   without touching the live `Results` table, `RankOverride`, or anything downstream — use this
   pattern (a shadow table + comparison report) when experimenting with ranking math rather than
   mutating the live tables directly.
8. **Club rating** (`spp-club-rating.php`) — computes a club-relative `ClubRating` (2.000–5.000) from
   rank standing, used elsewhere (e.g. shadow dampening in `spp-create-results.php`) in preference to
   noisier self-reported `Master.Rating`.

Ranking/override data is also surfaced through WPDA (WP Data Access) admin table-editor pages (e.g.
"Modify Overrides", app_id 7 / project 30) — some columns exist specifically so they show up
correctly in that UI, not just in code.

## Roles & access control

- `spp_is_admin_or_editor()` (functions.php) checks **actual WP roles** (`administrator`, `editor`),
  not capabilities — use this instead of `current_user_can('editor')`, which also returns true for
  subscribers who've had `edit_posts` granted by `spp_sync_blog_author_caps()` (blog-roles.php). This
  distinction has caused real permission bugs; always prefer the role-helper functions for gating
  admin-only tools.
- `spp_is_ladder_admin()` extends the above with `ladder-cop` for rank-history access.
- `blog_author` (auto-granted to active members, `YrEndDt` = current year) and `blog_moderator`
  (manually assigned, registered as a real WP role so Ultimate Member grants backend access) are
  defined in `blog-roles.php`.
- Page access to `[cmruncode]`-shortcode pages is restricted to editors/admins by page ID allowlist
  in `functions.php` (`template_redirect` hook) — member-facing tool pages must be added to the
  `$member_pages` array there.
- Login is blocked for members whose `YrEndDt` usermeta isn't the current year (`authenticate` filter
  in `functions.php`), except for exempt roles and `OkToLogin = Yes`.

## Data integrity — be suspicious

- `Results_all` carried a systematic one-week `event_id` shift, caused by a pre-v2.1 Apply Override
  reading `spp_current_event` too early. Six weeks of `Schedules_Scores_*` tables were rebuilt
  forensically. If event_id alignment looks off, that's the shape of the bug — check it before
  assuming data is right.
- `Schedules_Scores_153`/`154` were mislabeled by an off-by-one bug.
- `page_sequence` must be `INT(10)` before it can hold 30000000+ event IDs.

## Things that live outside the repo

- Code Manager snippets are rows in a DB table, not files here. Always ask me for a fresh copy of a
  snippet before modifying it — never work from an old version. CM 278 is disabled and kept as a
  fallback.
- The "Event Status" page runs the `[gl_event_registrations]` shortcode, which lives in the GL
  Events plugin itself, not this theme repo — so `git log`/`grep` over this repo will never find it.
- After a first `git pull` on the server, the mu-plugin symlink must be recreated.

## Deployment rules

- Never run destructive SQL. Propose it; I run it.
- Inline styles to beat Divi resets.
- Tables: `max-width:100%; overflow-x:auto`. Never fixed widths.
- The `spp` alias points at the theme directory, not the WP root. Use `spproot` for `wp db query`.

## Conventions

- Every feature file in `inc/`/`mu-plugins/` starts with a version-history comment block describing
  changes release over release — read it before modifying the file, and add an entry when you change
  behavior (this is the changelog; there is no separate CHANGELOG file or git-tag convention being
  used for this).
- Files guard against direct access with `defined( 'ABSPATH' ) || exit;`.
- AJAX handlers are plain `add_action('wp_ajax_...')` / `wp_ajax_nopriv_...` closures beside the
  shortcode they support, not a separate controller layer.
- No automated tests exist. Verifying a change means exercising the shortcode/page on staging.