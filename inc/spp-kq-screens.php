<?php
/* =========================================================
   Ace/Queen of the Courts — Screens
   Version: 1.27.0
   Date: 2026-09-19

   Changes from 1.26.0 -- two changes from real live-event feedback:

   ITEM 1 -- "View Full Scoreboard" is now unconditionally present on
   every KQ live screen, regardless of whether any scores exist yet.
   spp_kq_render_scoreboard_link() no longer returns '' when spp_kq_has_
   any_recorded_score() is false -- that gate was the actual reason it
   read as "only on some screens (in-play, Overview), missing from the
   rest-countdown screen between rounds" from the outside: every phase
   already reaches the one shared call site in spp_kq_live_shortcode()'s
   dispatcher (unchanged -- still the single place this is echoed,
   already covering not_started/organizing/in_play alike, plus complete/
   cancelled's own separate calls), it just rendered nothing until the
   first score came in. spp_kq_render_full_scoreboard_screen()'s empty-
   state message (shared by both the standalone ?kq_view=scoreboard page
   and the folded-in AJAX fragment, spp_kq_render_scoreboard_markup()'s
   own existing "always show structure, never just blank" empty-state
   handling) is now "No scores entered yet." (was "No completed rounds
   yet.") -- this state is commonly reachable now instead of a rare edge
   case, so it's worded for that. "Back to game"/the 1.26.0 false-
   reannouncement fix are both untouched and need no changes to keep
   working from every one of the persistent app's own screens -- neither
   ever depended on which screen state #kq-live-app currently holds.

   ITEM 2 -- "Skip Wait — Go to Courts" button, round 2+'s automatic
   rest-countdown screen (spp_kq_render_overview_screen()'s own else
   branch): lets a facilitator end the SPP_KQ_COURTS_REST_SECONDS wait
   early once players are ready. New 'skip_rest_countdown' POST action
   (spp_kq_handle_post_actions() below) calls spp_kq_transition_skip_
   rest_countdown() (inc/spp-kq-live.php, own docblock has the full CAS-
   guard writeup) -- moves courts_announced_at EARLIER via LEAST(), never
   later, which is what makes a double-press or a race against the
   countdown's own natural completion both safe no-ops rather than
   needing a separate guard for each. Reuses the EXACT SAME client-side
   announcement path a real elapsed countdown already takes (tick()'s own
   courts_announced_at-vs-Date.now() comparison, unchanged) -- moving the
   one timestamp is the entire fix, no separate "Go to your courts."
   trigger to keep in sync. Nested inside #kq-rest-timer-wrap
   deliberately, so it disappears via that element's own existing hide-
   on-fire logic, real or skipped, with no separate show/hide code.

   Changes from 1.25.0 -- three fixes from real live-event testing of
   yesterday's 1.25.0 scoreboard-fold-in/native-Back work:

   BUG 1 -- two "Back" controls appeared at once on the folded-in Full
   Scoreboard fragment: the top-level "View Full Scoreboard" toggle link
   (spp_kq_render_scoreboard_link(), outside #kq-live-app, relabeling
   itself to "« Back" since 1.20.0) alongside spp_kq_render_scoreboard_
   fragment()'s OWN dedicated "« Back to game" link (added inside the
   fragment itself, yesterday's spec item 2) -- the two were never
   reconciled when the second one was added. Fix: the top-level link is
   now HIDDEN (its parentNode, so no leftover empty <p class="kq-hint">
   gap) for the entire time the scoreboard is showing, in both
   showScoreboard() and the click handler it drives -- "Back to game" is
   now the one and only way back. Restored to visible by swapFragment()
   the moment #kq-live-app holds real content again. The native-Back/
   popstate mechanism from yesterday is completely unaffected -- it
   never depended on which control was visible, only on the pushed
   history entry.

   BUG 2 -- pressing "Back to game" spoke a false announcement ("Start
   play now, 1 minute remaining" together, live-tested), even though the
   round was already in progress and neither was a real threshold being
   crossed. ROOT CAUSE CONFIRMED (same general class as 1.15.0's
   duplicate-announcement fix, not guessed): "Back to game"/native Back
   both resolve through swapFragment(), which re-fetches and re-executes
   whichever fragment matches CURRENT server state via executeScripts()
   -- every caller before this always did so for a GENUINE transition
   (poll() detecting a real signature change, or submitAction() after an
   action that really changed server state), so a fresh fired={}/
   fired=false inside that re-executed <script> was always correct:
   retroactively announcing whatever threshold a device "just" crossed
   on a genuine first render of new state. "Back to game" broke that
   assumption -- the round hadn't changed at all, it's a PASSIVE
   redisplay of already-current state this device already heard
   announcements for, but the re-executed script has no way to tell the
   difference on its own. Fix: swapFragment(isTransition) -- default
   true, preserving poll()/submitAction()'s existing behavior completely
   unchanged -- sets window.__kqIsTransition immediately before
   executeScripts() runs. spp_kq_render_in_play_screen()'s tick() and
   spp_kq_render_overview_screen()'s rest-countdown tick() (the exact
   same class of bug, found by inspection, fixed the same way, even
   though only the round timer was reported live) both read it: on a
   passive redisplay, the round timer silences speak() for JUST its
   first synchronous tick() (fired[] still marks exactly as it
   otherwise would, so the visible timer/labels are correct immediately;
   speak is restored before the interval starts, so anything crossed
   from the very next tick onward -- including "Start play now." itself,
   if returned mid-"Starting in" countdown -- still announces completely
   normally); the rest-countdown, being a single one-shot event rather
   than a threshold series, just skips its one speak() call outright on
   a passive redisplay, keeping the visual reveal (hide countdown, show
   Start Play) unchanged either way, since that's genuinely correct
   current state, not something being re-announced. SppKqLiveApp.
   refreshNow is now a wrapper (isTransition=false unconditionally, its
   one caller is always a passive redisplay) rather than a direct
   swapFragment alias; the popstate listener passes false for the same
   reason.

   ITEM 3 -- spp_kq_render_draw_screen()'s card-claim success handler
   now scrolls #kq-not-drawn-list back into view (smooth, centered) after
   a successful draw, mirroring yesterday's scroll-to-cards fix in the
   other direction: real feedback that with enough cards already drawn
   on screen, a successful claim can leave the player-names list scrolled
   out of view, with no easy way back to it for the next player. Skipped
   when the draw just completed (the existing reload makes it moot) or
   the list is now empty.

   Changes from 1.24.0: (1) spp_kq_render_draw_screen()'s selectPlayer()
   now auto-scrolls #kq-card-grid into view (smooth, centered) every time
   a name is tapped -- real-incident fix: on iPhone-width viewports the
   "Not yet drawn" name list sits above the card grid/revealed-courts
   sections, which wrap below it, so tapping a name gave no visible sign
   anything happened unless the visitor already knew to scroll down
   manually ("I clicked my name and nothing happened" during live play).
   (2) removed the "Cancel Event" button/form from the
   in-play screen's action row (spp_kq_render_in_play_screen()) --
   real-incident fix: a facilitator accidentally pressed it partway
   through round 1, losing that round's in-progress scores with no way
   to resume, and had to be recovered by hand. Other, deliberate ways
   to cancel an event still exist outside this button (see 'cancel_
   event' in spp_kq_handle_post_actions() below and spp_kq_transition_
   cancel_event(), inc/spp-kq-live.php -- both left fully intact, since
   tests/spp-kq-end-cancel-test.php calls spp_kq_transition_cancel_
   event() directly and nothing else in the UI reaches it now). Once any
   court has reported a score this round, the in-play action row now
   renders nothing at all (previously: Cancel Event only) -- the round
   simply runs to completion; there is no longer an in-UI way to abort
   a round that's already partly scored, which is the point. Does not
   touch the cancelled-with-rounds-played history/recap/photo-prompt
   logic (spp-kq-history.php) -- that remains correct read-only for any
   past occurrence that was already cancelled before this change.
   (3) spp_kq_render_live_app()'s scoreboard toggle (showScoreboard()/
   swapFragment()) now pushes/resolves a same-document history entry
   instead of never touching history at all -- real-incident fix,
   mechanism CONFIRMED LIVE on occurrence 118 before changing anything
   (injected a window marker + a pageshow/persisted listener, drove the
   scoreboard toggle for real, then called history.back() and diffed):
   the marker did NOT survive and performance.getEntriesByType(
   'navigation')[0].type read "back_forward" -- a genuine full document
   reload, not a bfcache/persisted restore (ruling out the 'pageshow'-
   based fix the investigation also considered). Because entering the
   scoreboard never pushed a history entry (the link's own click handler
   always called e.preventDefault()), the phone's native Back button
   skipped over this page's one real entry entirely and landed on
   whatever earlier real page load came before it -- which, freshly
   reloaded, re-renders THIS occurrence's CURRENT phase (spp_kq_live_
   shortcode() always reads live state, never what that old entry
   originally showed) straight back into spp_kq_render_live_app(),
   recreating SppKqAnnouncer with audioUnlocked reset to false (the "Tap
   to enable sound" banner reappears) -- a facilitator who instinctively
   re-taps it (nothing on screen shows the page silently reloaded) then
   gets a real, audible re-run of whichever fragment's own <script>
   happens to be current, whose "Go to your courts."/"Start play now."
   timing is deliberately computed fresh from embedded server timestamps
   vs. Date.now() every execution (1.13.0's own design) -- sounding like
   the event is starting over. Fix: showScoreboard() success now calls
   history.pushState({kqScoreboard:true}, '', scoreboardViewUrl); the
   scoreboard link's "Back" branch and a new popstate listener both
   resolve through the same swapFragment() call (one path, can't drift);
   swapFragment() itself replaceState()s the stale entry away if a REAL
   transition (poll()/submitAction()) arrives while the scoreboard is
   still showing, matching the already-live-tested 1.20.0 auto-swap-away
   behavior. All same-document navigation -- no reload, no lost
   SppKqAnnouncer/timer state, either direction.
   (4) spp_kq_render_in_play_screen()'s tick() reverts 1.13.0's own
   "Start play now." timing choice: it now fires when now >= localStartMs
   (the same tick that falls through from "Starting in mm:ss" into real
   round-timer rendering) instead of unconditionally on the screen's very
   first tick -- real feedback: hearing it right as Start Play is pressed,
   a full 10 silent seconds before the round's real timer/round_started_at
   instant actually arrives, read as premature during live play. Only the
   announcement's timing moved; round_started_at (still stamped
   SPP_KQ_ROUND_START_DELAY_SECONDS = 10s after the press) and the
   visual-only "Starting in mm:ss" countdown are both unchanged.

   Changes from 1.23.0: spp_kq_handle_post_actions()'s 'end_event'/
   'cancel_event' cases now call spp_kq_finalize_event_history_and_
   recap() FIRST, before spp_kq_maybe_publish_to_club_ratings()/spp_kq_
   update_format_rankings() -- previously last. Required by inc/spp-kq-
   format-ranking.php's own 1.2.0 rework: it now reads a player's final
   court from spp_kq_history (needed uniformly, since its new tie-break
   levels look up OTHER players' past events from that same table too),
   not the live spp_kq_scores/spp_kq_assignments tables, so spp_kq_
   history must already hold THIS occurrence's rows by the time it
   runs. Club Rating is unaffected by the reorder either way (its own
   spp_kq_build_club_rating_games() has always read the live tables,
   never spp_kq_history). Notice-text concatenation order in the
   returned string is unchanged (rating, then format, then history) --
   only the EXECUTION order changed, not what the facilitator reads.

   Changes from 1.22.0: spp_kq_handle_post_actions()'s 'end_event'/
   'cancel_event' cases now also call spp_kq_update_format_rankings()
   (inc/spp-kq-format-ranking.php, new this same day) right alongside
   the existing Club Rating publish call -- same trigger point, same
   pre-launch-date guard, a completely separate/independent concern of
   its own (see that file's own header for the full design: two
   decay-weighted per-format averages, Ace and Queen, based on the
   court a player ends an event on). The membership-table-rebuild
   condition now also fires on $format_result['updated'], not just
   Club Rating's own 'published' flag, so a KQ event that updates format
   rankings but (hypothetically) not Club Ratings still gets its new
   ranks propagated into membership/Master. Notice text concatenation
   extended to include the format-ranking notice alongside the existing
   rating/history ones.

   Changes from 1.21.0: spp_kq_handle_post_actions()'s 'end_event'/
   'cancel_event' cases now call spp_create_membership_table() (inc/
   spp-create-membership-table.php) right after spp_kq_maybe_publish_
   to_club_ratings() (inc/spp-kq-club-rating.php 1.3.0), but ONLY when
   its new 'published' flag is true -- a KQ-driven rating change used to
   sit correctly in usermeta but never reach membership.ClubRating (and
   Master/Masterlist{year}/Membershiplist{year}) until some unrelated
   ladder action next happened to trigger that rebuild; see spp-kq-club-
   rating.php's own 1.3.0 changelog for the full root-cause writeup.
   Same call site the archival/recap sequence already uses; a pre-
   launch-date/no-games/aborted/not-enough-established run (nothing
   written to usermeta) never triggers this rebuild.

   Changes from 1.20.0 -- two fixes to the live event flow, reviewed and
   approved together:

   PART A (fold View Full Scoreboard into the persistent AJAX app): the
   persistent-page/fragment-swap architecture (organizing/in_play only --
   see spp_kq_render_live_app()'s own docblock) covered announce-courts,
   rest-countdown, Start Play, and in-play, but View Full Scoreboard was
   still a separate real page navigation (?kq_view=scoreboard) -- Back or
   a mid-game refresh reset the browser's audio-unlock state, exactly
   the problem the whole AJAX rebuild existed to eliminate. New
   spp_kq_render_scoreboard_fragment() (same live data as spp_kq_render_
   full_scoreboard_screen(), plus an in-fragment "Back to game" link)
   and its own wp_ajax_spp_kq_render_scoreboard_fragment endpoint, both
   wired into spp_kq_render_live_app()'s bootstrap script: the persistent
   "View Full Scoreboard"/"Back" link (spp_kq_render_scoreboard_link(),
   now id="kq-scoreboard-link") is intercepted there and swaps the
   scoreboard into #kq-live-app instead of navigating; "Back to game"
   (both the top link and the in-fragment one) just calls the existing
   SppKqLiveApp.refreshNow() (swapFragment()) -- which now also resets
   the link's own label/href, the one place #kq-live-app's content ever
   becomes "the real live screen" again, whoever triggered it. No
   suppression added for poll()'s own unconditional swap-on-signature-
   mismatch -- if the real phase/round changes while the scoreboard is
   showing, the outer poll still swaps it away to the new real screen,
   same as it would for any other fragment; confirmed live (see below).
   The standalone ?kq_view=scoreboard page/route and [spp_kq_event_
   detail] (a completely separate function/data source, inc/spp-kq-
   history.php) are both untouched.

   PART B (in-play screen didn't show partially-reported scores): a
   device that loaded the in-play screen before a given court reported
   never saw that court's real score appear, even after it was reported
   by another device -- only the aggregate "N of M reported" line ever
   updated live (window.SppKqLiveApp.onProgress). Root cause confirmed
   by reading wp_ajax_spp_kq_poll_status's own response shape: it never
   carried per-court values at all, only the count. Fixed: that endpoint
   now also returns 'scores' (every court with both red_score/black_
   score non-NULL this round, spp_kq_get_round_scores(), inc/spp-kq-
   live.php) alongside the existing reported/total; poll() passes it
   through to onProgress(reported, total, scores); spp_kq_render_in_
   play_screen()'s own onProgress handler now also updates each
   reported court's own input values + "Saved" tag directly (skipping
   an input the visitor is actively focused on, so a live correction in
   progress is never clobbered) -- a lightweight DOM update, no full
   fragment swap, so the running timer/audio are never disturbed for a
   same-phase/same-round score arriving from elsewhere.

   LIVE-TESTED (not just read/reasoned about), occurrence 118 (pre-
   launch sandbox -- today's real live event, 265, never touched):
   drove round 1 to in_play with 2 courts, submitted one court's score
   from a SEPARATE server-side call (simulating another device) while a
   real logged-in browser tab sat on the in-play screen the whole time --
   confirmed that court's real score and the "Saved" tag appeared via
   the next poll tick alone, no reload (a `window.__marker` set before
   the submission was still present after). Confirmed the scoreboard
   link/fragment round-trip (enter -> Back to game) preserves a second
   marker, the timer's own countdown (continuous across the swap, not
   reset), and the audio-unlock banner staying dismissed (no re-prompt).
   Then, while still showing the scoreboard fragment, completed the
   round's second court score from the server -- confirmed the outer
   poll detected the real phase/round change and auto-swapped #kq-live-
   app to the correct new "Round 2 -- Ready to play" screen on its own,
   with the toggle link correctly reset to "View Full Scoreboard" --
   also exercising Part B's "all-reported -> transition" path in the
   same pass. Zero console errors throughout. [spp_kq_event_detail]
   (a real, separate page) loaded and rendered normally, confirming no
   shared-code interference. Occurrence 118 restored to its original
   not_started resting state afterward.

   Changes from 1.19.1 (restrict Full Reset to pre-launch-date test/
   sandbox occurrences only -- urgent same-day fix, a real live event
   was in progress with this button visible/functional on a real
   occurrence): spp_kq_render_full_reset() now also checks $event_date
   >= SPP_KQ_CLUB_RATING_LAUNCH_DATE (inc/spp-kq-club-rating.php, the
   same permanent literal cutoff already used by Club Ratings/history
   archival/the photo prompt -- reused, not a new "test event" concept)
   and prints nothing at all for a real event, even to an administrator.
   The REAL enforcement is the matching check added to spp_kq_handle_
   post_actions()'s 'full_reset' case -- a crafted/direct POST against a
   real occurrence is now rejected server-side ("Full Reset is only
   available for test events.") regardless of what the button's own
   visibility would have shown. Scoped to Full Reset alone -- Cancel
   Event, End Event, and every other action are unchanged.

   Changes from 1.19.0 (BUG FIX, see inline comments at spp_kq_get_round_
   court_view()/spp_kq_get_final_winner_names()/spp_kq_get_not_yet_
   drawn() -- this changelog entry was missed at the time and is added
   retroactively here for the record): a KQ guest showed as "Member #0"
   on the Overview/in-play/Complete screens (spp_kq_get_round_court_
   view()/spp_kq_get_final_winner_names() hardcoded user_id=0 into
   spp_kq_player_name() instead of the real value -- never selected
   a.user_id at all) and as a bare "Member #{id}" on the pre-draw
   registrant list (spp_kq_get_not_yet_drawn()'s membership-only lookup
   never gave spp_kq_player_name()'s guest fallback a chance to run).
   All three now use/derive the real user_id. Not a data-loss bug --
   spp_kq_assignments held the guest's correct user_id throughout.

   Changes from 1.18.0 (Guest registrant -- see inc/spp-kq-roster.php's
   own changelog for the full feature): spp_kq_player_name() now falls
   back to the WP display name (set at creation, spp_kq_roster_add_
   guest()) for any user_id flagged spp_kq_guest=1 in usermeta, instead
   of the generic "Member #id" placeholder -- guests have no membership-
   table row by design, since every other name lookup in this feature
   (live screens, roster, scoreboard, permanent history) already funnels
   through this one function. The placeholder remains the fallback for
   everyone else (a real signal something's wrong, per this codebase's
   "Data integrity -- be suspicious" convention). New 'roster_add_guest'
   case in spp_kq_handle_post_actions() below, same access/phase gate as
   the existing 'roster_add'/'roster_remove' cases.

   Changes from 1.17.0 (fix the embedded Submit Photo form's assets
   never loading, and its init logic never running when injected via
   fragment-swap -- see the conversation this was built from for the
   full investigation/root-cause writeup): spp_kq_render_photo_prompt()
   now calls the photo-gallery plugin's new public spp_photo_gallery_
   enqueue_submit_assets() and embeds its returned <script>/<link> tags
   directly in this function's own output, right after the embedded
   form -- see that plugin function's own docblock (wp-content/plugins/
   spp-photo-gallery, 1.6.0) for why printing the tags immediately (not
   a plain wp_enqueue_script() left for wp_footer() to flush) is what
   makes this work both for a direct page render of the Complete/
   Cancelled screen AND the persistent app's own executeScripts()
   fragment-swap injecting this same html much later. Paired with that
   plugin's own photo-submit.js fix (document.readyState check instead
   of an unconditional one-time DOMContentLoaded listener), the
   embedded form's submit button now correctly enables once a photo is
   selected, in both scenarios.

   Changes from 1.16.0 (decouple the Submit Photo prompt from the
   Club-Rating launch-date gate): confirmed via a REAL occurrence (118,
   2026-09-10, 3 rounds genuinely played, properly ended via End Event),
   not a synthetic case -- the prompt showed nothing purely because
   spp_kq_history_exists_for_occurrence() also required spp_kq_archive_
   event_history() to have actually written rows, and that function
   refuses to for ANY occurrence dated before SPP_KQ_CLUB_RATING_
   LAUNCH_DATE, regardless of how real/complete the event was. Fixed:
   spp_kq_render_photo_prompt() now gates on spp_kq_has_any_recorded_
   score() directly (inc/spp-kq-live.php, already the exact "was
   anything really played" check End Event's own precondition and
   spp_kq_archive_event_history()'s "nothing-reported" branch both
   independently re-derive) -- no launch-date check attached at all.
   See that function's own updated docblock for the full reasoning,
   including why it correctly still requires "played" for the
   cancelled-screen caller while being unconditionally true for the
   complete-screen caller (End Event itself won't fire before a score
   exists). spp_kq_history_exists_for_occurrence() is gone (inc/spp-kq-
   history.php 1.7.0) -- confirmed fully orphaned before removing it,
   not assumed.

   Changes from 1.15.0 (two changes -- see the conversation this was
   built from for the full spec):

   PART A -- Submit Photo embedded on Complete/Cancelled instead of
   redirected. Context that made this the right call: the ONLY way to
   ever reach complete/cancelled is End Event/Cancel Event, both direct
   page actions (not something a device is ever polled INTO reaching);
   any other device still watching now gets the identical screen via
   the persistent app's own fragment-swap (see below), so a separate
   redirect mechanism was solving a problem the architecture no longer
   has. Removed entirely: spp_kq_maybe_photo_redirect_url(), the
   window.location.href script in the dispatcher's complete/cancelled
   case, and redirect_url from wp_ajax_spp_kq_poll_status's response.
   KEPT unchanged: spp_kq_history_exists_for_occurrence() -- still the
   one ground-truth gating condition (completed normally, or cancelled
   with >=1 round played; nothing played never shows this).
   INVESTIGATED (required before building): [spp_photo_submit]'s
   shortcode callback (wp-content/plugins/spp-photo-gallery/includes/
   photo-submission.php) took NO $atts at all -- confirmed by reading
   it, not assumed -- so query-string preselection (this morning's
   redirect) had nothing to fall back on for an inline do_shortcode()
   call with no URL involved. Fixed with a small, reasonable plugin
   change (flagged as such, not silently worked around): the callback
   now accepts event_ref/date via shortcode_atts(), validated through
   the EXISTING spp_photo_gallery_resolve_event_ref() (same check the
   AJAX submit handler already runs) and the same real-date/not-future
   rule confirmed_date already gets -- see that plugin's own 1.5.0
   changelog. spp_kq_resolve_photo_submit_url() (URL-building) is gone;
   replaced by spp_kq_resolve_photo_event_ref() (just the "series:<id>"
   ref, no page/URL lookup needed any more) and new spp_kq_render_
   photo_prompt(), called directly from spp_kq_render_complete_screen()/
   spp_kq_render_cancelled_screen() -- so BOTH the dispatcher's direct
   render (whoever pressed End Event/Cancel Event) and spp_kq_render_
   live_fragment()'s own 'complete'/'cancelled' cases (every other
   device, via a normal fragment swap) get the identical screen from
   one function, nothing duplicated. Order per spec: "Event complete."
   statement -> note -> embedded form -> "View Full Scoreboard" link --
   the scoreboard link is now rendered BY these two screen functions
   themselves (spp_kq_live_shortcode()'s usual top-of-page render is
   skipped specifically for these two phases) to land in that position.
   spp_kq_render_live_app()'s own poll() script: complete/cancelled are
   now normal swappable structural-signature states (same mechanism
   organizing/in_play already used), not reload/redirect triggers --
   only not_started (a Full Reset from another tab) still forces a
   reload, since that's the one phase this app was never built with a
   fragment for. Polling stops once a terminal fragment is showing --
   nothing can change server-side past that point.

   PART B -- "Complete Allocation Randomly" at the round-1 draw. New
   button on the draw screen (spp_kq_render_draw_screen()), plain POST
   ('complete_draw_randomly' case, spp_kq_handle_post_actions()) --
   same access level as every other draw-screen action, not admin-only.
   The actual mechanics (spp_kq_complete_draw_randomly()) live in
   inc/spp-kq-live.php -- see that file's own 1.6.0 changelog.

   Changes from 1.14.0 (two real bugs found during live device testing
   of the AJAX rebuild -- see the conversation this was built from for
   the full investigation):

   BUG 1 -- most audio announcements fired twice, including the
   countdown. CONFIRMED root cause (grepped every reference to
   lastSignature before concluding, not guessed): submitAction()
   (Announce Courts/Start Play) called swapFragment() directly for
   immediate feedback, but never touched lastSignature -- poll()'s own
   setInterval kept running regardless and still held the PRE-
   transition signature, so its very next tick (<=4s later) ALWAYS saw
   a stale mismatch and called swapFragment() a SECOND time for the
   exact same transition, re-executing that fragment's inline script
   again and arming a second, completely independent copy of whatever
   timer it sets up -- the first copy was never cleared, so both kept
   running, both speaking through the same persistent SppKqAnnouncer,
   for the rest of that fragment's life. Fix: spp_kq_render_live_
   fragment() now returns {html, state} instead of a bare string (both
   read from the SAME spp_kq_get_event_state() call, so they can never
   disagree), wp_ajax_spp_kq_render_fragment returns the structural
   fields alongside the html, and swapFragment() now sets lastSignature
   itself from whatever it just fetched -- correct immediately after
   EITHER caller (poll() or submitAction()) triggers a swap, so a
   stale mismatch can no longer occur. Defense in depth added for the
   separate, rarer case this doesn't directly address (two overlapping
   fetch() calls under a slow connection): a `swapping` guard makes a
   second concurrent swapFragment() call a no-op, and a new
   SppKqLiveApp.cleanup hook (same reset-before-swap convention
   onProgress already used) lets whichever fragment is currently active
   stop its own interval before being replaced -- the round timer
   reuses markRoundComplete() directly (its "stop counting, stop
   speaking" is exactly what cleanup needs too); the rest-countdown
   registers an equivalent small function.

   BUG 2 -- at event completion, the screen just showed "Event
   complete." with no redirect to Submit Photo. INVESTIGATED AND RULED
   OUT before concluding: wp_ajax_spp_kq_poll_status still computes and
   returns redirect_url correctly (unchanged), and spp_kq_render_live_
   app()'s own poll() DOES check `d.redirect_url` and navigate --
   confirmed directly by reading the code, not assumed; nothing was
   "dropped" during the AJAX consolidation. CONFIRMED actual root
   cause: End Event/Cancel Event are DELIBERATELY plain POST forms
   (1.14.0 -- a reload there is correct, it doesn't interrupt an
   in-progress announcement), which means the device that PRESSES one
   of them gets a full browser navigation straight to spp_kq_live_
   shortcode()'s direct, un-wrapped render of spp_kq_render_complete_
   screen()/spp_kq_render_cancelled_screen() -- a bare static fragment
   with no script, no poll, and therefore no way to ever have acted on
   redirect_url. This gap predates the AJAX rebuild entirely: End Event
   has always been a plain POST, in every version of this feature back
   to when the redirect was first built, so the presser's own device
   never had a path to it -- only OTHER devices still polling from the
   in-play/persistent-app screen ever got redirected. Fix: new
   spp_kq_maybe_photo_redirect_url() factors the exact condition
   wp_ajax_spp_kq_poll_status already used (now calls the factored
   version, no behavior change there) out to a shared helper;
   spp_kq_live_shortcode()'s complete/cancelled cases now call it
   directly at render time and, when it applies, emit a tiny inline
   `<script>window.location.href = ...;</script>` instead of the
   static screen -- the SAME client-side-navigation approach this
   entire feature's redirect has always used, just triggered from the
   one render path that had never had any mechanism to check it before.

   Changes from 1.13.0 ("full monty" -- convert the announcement-
   carrying part of the KQ live flow from full-page-reload navigation
   to a single persistent AJAX-polling page, so SppKqAnnouncer's
   audioUnlocked/keep-alive state -- and any in-flight speechSynthesis
   utterance -- survives across announce-courts, rest countdown, Start
   Play, and in-play scoring for an entire event. See the conversation
   this was built from for the full spec. SAFETY: git tag
   pre-ajax-kq-live created at the prior commit (da5c271) before any of
   this started, as a clean revert target.

   ARCHITECTURE CHOSEN: fetch server-rendered HTML FRAGMENTS via AJAX
   and swap them into a container, NOT a client-side
   render-everything-and-toggle-visibility approach. Reasoning: every
   screen in this feature (spp_kq_render_overview_screen(),
   spp_kq_render_in_play_screen(), etc.) was ALREADY a self-contained
   HTML+inline-script string producer (ob_start()/ob_get_clean()) --
   fragment-shaped from the start. Reusing those functions as-is behind
   one new AJAX endpoint means the exact same PHP stays the single
   source of truth for markup (court rosters, score values, timer
   state) that a parallel client-side template would have had to
   duplicate and keep in sync by hand -- a much larger, much riskier
   surface for a rebuild whose actual goal is audio continuity, not a
   new rendering system.

   SCOPE (confirmed by re-reading spp_kq_live_shortcode()'s dispatcher
   before changing it): only organizing (once past the round-1 draw)
   and in_play join the persistent app -- check-in (not_started),
   roster-adjust, and the round-1 draw/court-claim screen carry no
   audio and stay ordinary page loads, exactly as the spec expected.
   complete/cancelled ALSO stay ordinary page loads/navigations when
   reached directly -- the persistent app's own poll always redirects
   (Submit Photo) or reloads the instant phase leaves {organizing,
   in_play}, per item 6's own reasoning: there is no more announcement
   to protect at that point, so a real navigation there is correct, not
   a gap.

   SppKqAnnouncer CONTINUITY (the actual point of this rebuild, verified
   explicitly, not assumed to fall out of removing reloads): spp_kq_
   render_speech_announcer() is now called EXACTLY ONCE, by the new
   spp_kq_render_live_app() shell, OUTSIDE the swappable #kq-live-app
   container -- previously spp_kq_render_overview_screen() and spp_kq_
   render_in_play_screen() each called it themselves, which would have
   RECREATED SppKqAnnouncer (and reset audioUnlocked to false) on every
   single fragment swap, defeating the entire rebuild. Both screens now
   just reference the already-existing global.

   HOW TRANSITIONS WORK WITHOUT A RELOAD: the existing wp_ajax_spp_kq_
   poll_status (server-side UNCHANGED -- still just reads state, same
   redirect_url/courts_announced_at fields as before) is now polled by
   ONE outer loop (spp_kq_render_live_app()'s own script) instead of
   being duplicated across screens. On each tick it computes a
   structural signature (phase|round|courts_announced_at --
   round_started_at/round_duration_seconds deliberately excluded: they
   are set once, atomically, at the exact instant phase flips to
   in_play, so phase+round alone already captures that transition, and
   they never change again while still in_play for that round) and,
   only when it differs from last time, fetches the new fragment's HTML
   (new wp_ajax_spp_kq_render_fragment, which just calls the new
   spp_kq_render_live_fragment() -- the SAME phase-switch spp_kq_live_
   shortcode() always had, factored out so the initial paint and every
   later swap can never render differently for the same state) and
   swaps it into #kq-live-app. A fragment's own <script> tags are inert
   when set via .innerHTML (standard DOM behavior); executeScripts()
   clones each into a fresh <script> element (which DOES execute)
   immediately after every swap -- every fragment's script was ALREADY
   a self-contained IIFE computing everything from embedded server
   timestamps + Date.now(), so re-running it fresh on a swap is
   indistinguishable, from that script's own point of view, from a
   fresh page load -- this is why the "Go to your courts."/"Start play
   now." timing logic built earlier today needed ZERO changes to work
   correctly here.

   Announce Courts and Start Play -- the two actions that happen WITHIN
   the persistent app's own lifetime -- now submit via a new AJAX action
   (wp_ajax_spp_kq_live_action) instead of a plain POST: their <form>
   markup is COMPLETELY UNCHANGED (same wp_nonce_field()/hidden inputs),
   only the submit is intercepted (SppKqLiveApp.wireAjaxForm(), the
   shell's own script) and sent via FormData(form) + fetch() instead of
   a browser navigation, then the result triggers an immediate
   swapFragment() rather than a reload. The AJAX handler itself is
   nothing more than a thin wrapper calling spp_kq_handle_post_actions()
   -- completely unchanged, the exact same function every plain-POST
   action already goes through. End Event/Cancel Event/Full Reset are
   DELIBERATELY left as plain POST forms (a real reload there is
   correct, not a gap -- each one legitimately ends or interrupts the
   sequence, matching item 6's own reasoning for the final redirect).

   CAS/RACE-GUARD CONFIRMATION: spp_kq_transition_announce_courts(),
   spp_kq_transition_start_play(), spp_kq_transition_advance_round(),
   and spp_kq_submit_court_score()'s submission-order/round-still-
   current guard (inc/spp-kq-live.php) are completely untouched by this
   change -- confirmed by inspection, not re-derived: every one of
   today's new AJAX handlers is a thin wrapper around functions that
   already existed and already enforced these guards server-side; this
   rebuild only changed how the RESULT reaches the client (JSON + a
   fragment swap instead of a fresh page render), never how or whether
   a transition is allowed to happen.

   Changes from 1.12.0 (pre-round announcement flow for both Round 1
   and Round 2+, plus a revision of this SAME DAY's earlier delayed-
   start timing -- see the conversation this was built from for the
   full spec; the transitions/constants live in inc/spp-kq-live.php
   1.5.0 and the schema in inc/spp-kq-schema.php 1.7.0, see those
   files' own changelogs):

   INVESTIGATION (required before inserting the new steps -- confirmed
   by re-reading spp_kq_live_shortcode()'s phase-driven switch before
   changing anything, not assumed): BOTH Round 1 (draw complete,
   unclaimed = 0) AND every Round 2+ (spp_kq_transition_advance_round(),
   always landing on phase='organizing') fell through to the exact SAME
   spp_kq_render_overview_screen() with no intermediate step at all --
   confirmed by reading the dispatcher's 'organizing' case, which only
   ever distinguishes "round 1 with cards still unclaimed" (draw
   screen) from literally everything else (overview screen, no further
   distinction). This is exactly the single insertion point both new
   flows need, and why one screen function can serve both --
   spp_kq_render_overview_screen() itself is now that shared screen,
   branching purely on whether courts_announced_at is null (round 1,
   not yet pressed) or set (round 1 just pressed, or round 2+
   automatically) rather than on the round number at all.

   TIMING REVISION (Start Play): SPP_KQ_ROUND_START_DELAY_SECONDS is
   now 10, not 30 (inc/spp-kq-live.php 1.5.0), and "Start play now."
   now fires on the FIRST tick the in-play screen's script ever runs,
   unconditionally (once, via fired.start) -- not gated behind reaching
   round_started_at the way this morning's version was. This matches
   the exact same "fire once on first tick if not yet fired, even if
   that tick happens a little late" precedent announceIfDue() already
   established for every other trigger in this feature (a device that
   loads mid-round already retroactively announces whatever threshold
   it just missed crossing) -- not a new special case, see tick()'s own
   comment. The "Starting in mm:ss" display now counts the 10 seconds
   AFTER that announcement, toward round_started_at, instead of before
   it.

   THE BUILD:
   - New spp_kq_render_speech_announcer(): the "tap to enable sound"
     banner + speak()/keep-alive machinery, factored OUT of
     spp_kq_render_in_play_screen() (which used to be its only caller)
     now that spp_kq_render_overview_screen() needs the identical thing
     for "Go to your courts" -- see that function's own docblock. Both
     screens echo it once and call into the `SppKqAnnouncer` global it
     defines; no logic duplicated between the two screens.
   - spp_kq_render_overview_screen() gains a $courts_announced_at
     param and three states in its action-row (understaffed warning is
     unchanged and still checked first):
       1. courts_announced_at === null (round 1 only): a "Ready --
          Announce Courts" button (spp_kq_transition_announce_courts(),
          inc/spp-kq-live.php) + a poll loop (reusing wp_ajax_spp_kq_
          poll_status, now also returning courts_announced_at) so any
          OTHER device sitting on this screen detects a different
          device's press, speaks "Go to your courts.", and reloads. The
          presser's own device just finds out via its own ordinary
          form-POST reload, same asymmetry Start Play's own press
          already has with the match timer's announcements.
       2. courts_announced_at set (round 1 just pressed, or round 2+
          automatically via spp_kq_transition_advance_round()): a live
          countdown to that instant (same absolute-anchor/skew-
          corrected math as the match timer), speaks "Go to your
          courts." once it's reached (immediately, on the very first
          tick, for round 1's own already-elapsed reload -- no separate
          branch needed), then reveals the SAME Start Play form
          (#kq-start-play-wrap, initially display:none) both flows
          already shared before this feature existed -- satisfies
          "reuse one implementation for both flows" directly, since
          it's the literal same markup/script either way.
     End Event's own form is unchanged and un-nested from all of the
     above -- still gated only by $scores_exist, exactly as before.
   - spp_kq_handle_post_actions() gains 'announce_courts' (round 1
     only -- round 2+ never POSTs it, its own rest period is fully
     automatic).
   - Dispatcher: the one call site passes $state['courts_announced_at']
     through -- no other change to phase-selection logic. Round 2+'s
     routing to the new rest-countdown state needed NO change at all
     to the EXISTING poll-detects-round-advance-then-reload code in
     spp_kq_render_in_play_screen() (confirmed, not re-derived): that
     reload already lands back on the dispatcher, which now shows
     different content purely because spp_kq_transition_advance_round()
     stamps courts_announced_at -- satisfying "round-advance must route
     to the new screen" with zero changes to the round-advance
     detection/reload mechanism itself, only to what the destination
     renders.

   Changes from 1.11.0 (30-second delayed start + a real live-tested
   audio bug fix -- see the conversation this was built from for the
   full spec; the round_started_at plumbing lives in
   inc/spp-kq-live.php 1.4.0, see that file's own changelog):

   PART A -- delayed start: spp_kq_render_in_play_screen()'s timer JS
   gained a second absolute anchor, localStartMs (same skew-corrected
   design as the existing localEndMs), computed from round_started_at
   alone -- no new PHP param, no new DB column. tick() now checks
   `now < localStartMs` first: while true, it renders a "Starting in
   mm:ss" countdown (reusing the same #kq-timer/#kq-timer-label
   elements, just a different label) instead of the round timer, and
   is a complete no-op for the round-timer classes/announceIfDue().
   The instant that countdown reaches zero, it speaks "Start play
   now." exactly once (fired.start, same one-shot-per-round bookkeeping
   as every other trigger) and falls through into the SAME tick() call's
   normal round-timer rendering -- no extra frame stuck at "Starting in
   0:00" before the real countdown appears. Every device anchors to the
   same round_started_at regardless of when it individually loads the
   screen, exactly like the round timer itself already did.

   PART B -- investigated and fixed a live-tested bug: the 2-minute
   warning produced no audio (1-minute and the 10->0 countdown worked
   fine), plus the spoken countdown seemed to start a bit late relative
   to the displayed digits. Investigated and RULED OUT before landing
   on a fix (see speak()'s own inline comment for the full writeup):
     - NOT the trigger-threshold logic -- already <= thresholds
       (never exact-equality), re-checked in full every 250ms tick
       regardless of any single tick's lateness, so a late/skipped tick
       can never cause a threshold to be silently skipped.
     - NOT two independently-drifting clocks -- the visual digit
       (timerEl.textContent) and the announcement check
       (announceIfDue(remainingSeconds)) are computed from the exact
       same remainingSeconds value, once per tick(), not two separate
       reads.
     - NOT setInterval drift accumulating -- tick() recomputes
       remainingSeconds fresh from the absolute anchor every call, never
       increments a counter, so a late/irregular tick can never make the
       DISPLAYED value wrong, only (at most) render it a little late.
   CONFIRMED root cause: Chromium's own long-documented speechSynthesis
   idle bug -- the engine's internal speech queue can silently pause/
   stall after roughly 15 seconds without actively speaking, dropping
   the next speak() call with no error and no visible symptom. This
   feature's announcements are naturally sparse (the "Sound enabled."
   confirmation, then dead air until the 2-minute mark -- up to ~11
   minutes on the 13-minute default round), which is exactly the idle
   window that bug needs; a later speak() call working again after the
   engine already went idle-and-stuck also plausibly explains the
   "starts a bit late" symptom (the first utterance out of a stalled
   engine can carry its own startup lag that a never-idle engine
   doesn't have). Fix: resume() immediately before every speak() call
   (unsticks an already-stalled queue for that one utterance), plus a
   10-second pause()+resume() keep-alive interval running for as long
   as audio stays unlocked (well inside the ~15s idle window, so the
   engine never gets the chance to stall at all) -- both standard,
   narrowly-targeted mitigations for this specific documented engine
   bug, not a rewrite of the announcement scheduling itself (which was
   already correct). Also added: onstart/onerror handlers per utterance
   plus a console.debug at the moment a trigger fires (separate from
   whether speech actually played) -- previously a dropped utterance
   was completely silent and unfalsifiable; a future recurrence is now
   diagnosable from the browser console instead of another guessing
   exercise.

   Changes from 1.10.0 (post-completion Submit Photo redirect -- see the
   conversation this was built from for the full spec; the ground-truth
   "was this archived" check lives in inc/spp-kq-history.php 1.6.0, see
   that file's own changelog):

   INVESTIGATION (required before wiring the redirect target -- confirm
   the real Submit Photo form structure, not assumed): [spp_photo_submit]
   lives in a SEPARATE plugin, wp-content/plugins/spp-photo-gallery/
   includes/photo-submission.php (its own git repo, spp-photo-gallery.git
   -- not this theme), on the page at /submit-photo/ (post ID 20010543).
   Its Event <select name="event_ref"> renders one <option> per ACTIVE
   gl_event_series row as value="series:<id>" (spp_photo_gallery_
   event_options(), same file) -- confirmed directly against the live
   DB, not guessed: all 4 real KQ recurring time slots are active
   gl_event_series rows with EXACTLY the titles named in the spec, and
   every real KQ occurrence's gl_events_v.series_id already points at
   one of them (0 NULL series_id rows among all category 2/3
   occurrences) --
     id=2 "Ace of the Courts - 9:00 am"   -> series:2
     id=3 "Ace of the Courts 10:30am"     -> series:3
     id=4 "Queen of the Courts - 9:00 am" -> series:4
     id=5 "Queen of the Courts 10:30am"   -> series:5
   The date field (<input type="date" name="confirmed_date">) already
   server-side defaults its value to current_time('Y-m-d') on every
   fresh load -- redundant with "today" in the redirect's own case, but
   the redirect still passes date=Y-m-d explicitly per spec rather than
   silently relying on that default. Neither field had any existing
   query-string preselection mechanism -- added to assets/js/
   photo-submit.js (that plugin's own 1.4.0, see its changelog) as a
   small, isolated read of event_ref/date from location.search, applied
   only if event_ref matches a REAL <option> already in the dropdown
   (never trusts an arbitrary value blindly) and otherwise a complete
   no-op, so a normal (non-redirected) visit to the page is unaffected.

   THE BUILD:
   - spp_kq_resolve_photo_submit_url() (new): occurrence -> its
     series_id -> the [spp_photo_submit] page's URL with event_ref/date
     query args. Fails closed (returns null, no redirect) if the series
     or the page itself can't be resolved -- see its own docblock.
   - wp_ajax_spp_kq_poll_status now also returns redirect_url: null
     unless phase has become complete, or cancelled with
     spp_kq_history_exists_for_occurrence() true (inc/spp-kq-history.php
     1.6.0 -- the exact real outcome of the SAME archive/recap gating
     spp_kq_finalize_event_history_and_recap() already applied,
     synchronously, in the request that made the transition -- not a
     second implementation of that gating). This is the ONLY new signal
     added to that endpoint; reported/total/phase/current_round are
     unchanged.
   - spp_kq_render_in_play_screen()'s poll() checks redirect_url FIRST,
     before its existing phase/round-advance reload check (complete/
     cancelled would also trip that check, but a redirect is a
     different action from a reload) -- window.location.href instead of
     window.location.reload(). Nothing else about poll()'s cadence,
     payload for reported/total, or the round-advance reload path for a
     still-in-play round changed.
   - SCOPE, confirmed deliberately NOT expanded: the Full Scoreboard
     sub-view (?kq_view=scoreboard, spp_kq_render_full_scoreboard_
     screen()) has no poll() of its own today -- it's a static render,
     entirely separate from spp_kq_render_in_play_screen() in the
     dispatcher's own kq_view branch (spp_kq_live_shortcode()). A device
     sitting on that view (or check-in/roster/anywhere else) is
     unaffected by this change, same as spec item 4 already expects for
     "a different KQ screen" -- this task extends the EXISTING in-play
     poll only, per its own framing ("extends that same detection"), it
     does not add a new poll loop to a screen that never had one.

   Changes from 1.9.0 (match timer with voice announcements -- see the
   conversation this was built from for the full spec; the schema/CAS
   plumbing lives in inc/spp-kq-schema.php 1.6.0 and
   inc/spp-kq-live.php 1.3.0, see those files' own changelogs):

   INVESTIGATION (required before building the audio-unlock UX -- see
   the conversation for the full ask): does the in-play screen persist
   as one page across a whole round (AJAX-only updates), or does it
   reload at the start of every round? Confirmed by reading this file's
   own existing code, not assumed: it reloads every round, for TWO
   independent reasons, so the "tap to enable sound" prompt cannot be
   made to persist across rounds no matter how it's built --
     1. spp_kq_render_overview_screen()'s "Start Play" button (below)
        is a plain <form method="post"> with no JS/fetch intercepting
        it -- pressing it is an ordinary full-page browser navigation,
        every single round, not an AJAX call. This alone means every
        round's in-play screen is a genuinely fresh page load, on
        whichever device presses it.
     2. The in-play screen's own poll() (below) detects a round advance
        via `d.current_round !== renderedRound` and calls
        window.location.reload() -- also a full navigation, for every
        OTHER device already sitting on the in-play screen when a round
        ends.
   Net: there is no "one page load spans a whole round" case here to
   exploit -- every round boundary is a real page load for every
   participant, one way or the other. So the audio-unlock prompt is
   built to reappear once per round (see spp_kq_render_in_play_screen()
   below) -- this is a real UX trade-off (a facilitator/player must tap
   "Enable sound" again each round), not a bug, and not something
   fixable client-side; it's a direct consequence of how this screen
   already navigates, unrelated to this feature.

   THE BUILD:
   - spp_kq_render_overview_screen(): new "Round length (minutes)"
     number input on the Start Play form (name spp_kq_round_minutes,
     default 13, min 1, max 60) -- the ONLY place duration is set;
     once Start Play is pressed the value is read server-side, clamped,
     and locked into spp_kq_events for the round (spp_kq_transition_
     start_play(), inc/spp-kq-live.php) -- no mid-round edit path
     exists anywhere.
   - spp_kq_handle_post_actions()'s 'start_play' case: reads/clamps
     spp_kq_round_minutes (default 13 on missing/invalid input, same
     "never trust the client, but the UI already enforces min/max"
     discipline as every other action here), converts to seconds,
     passes to spp_kq_transition_start_play().
   - spp_kq_render_in_play_screen(): new $round_started_at/
     $round_duration_seconds params (sourced from spp_kq_get_event_
     state() by the dispatcher, below) drive a per-client countdown.
     EVERY client anchors to the same ABSOLUTE end instant
     (round_started_at + round_duration_seconds, a true epoch) rather
     than "seconds since my own page loaded" -- satisfies "same start
     instant for every court simultaneously" even though different
     phones actually load the in-play screen at slightly different
     real moments (the facilitator's own device sees it immediately via
     the Start Play form's own re-render; everyone else gets there by
     navigating in once told play has started -- pre-existing behavior,
     not something this feature changes). Client-clock skew is
     corrected once, at render, by also embedding the server's own
     current epoch and computing a LOCAL target epoch from the two
     deltas -- not by trusting Date.now() against the raw server value
     directly.
   - Announcements (2min/1min verbal warnings, 10..0 spoken countdown,
     final "finish rally and stop play") fire via window.speechSynthesis
     (Web Speech API), entirely client-side, independently on every
     phone -- no server push, no single "master" device. A small
     "Tap to enable sound" banner satisfies the mobile-browser
     autoplay-needs-a-gesture requirement (this fires once per PAGE
     LOAD, i.e. once per round per the investigation above); the
     visual countdown itself never waits on it -- declining or ignoring
     the prompt only silences the spoken announcements, the digits
     keep ticking regardless. Missing SpeechSynthesis support (rare)
     degrades the same way: visual-only, no error, no prompt shown.
   - The timer is intentionally NOT wired to individual court score
     submissions -- it has no idea a court just reported and keeps
     running (per spec: "does not stop or pause when one court submits
     early"). It only reacts to the EXISTING reported/total figures
     this screen already tracks (both the save-score AJAX response and
     the 4-second poll already carried these, unchanged) -- once
     reported === total, the timer display is frozen and greyed
     (.kq-timer-done) rather than hidden outright, so a court that
     finished early can still see what the clock read at that moment;
     it stops actually counting and stops scheduling any further
     announcements at that point, since the round is effectively over
     by score progress regardless of what the clock says. The round
     eventually really ends via the existing, completely unrelated
     poll-detects-round-advance -> reload path (unchanged).

   Changes from 1.8.0:
   - Start-Round-1's own timing gate ($can_start_now in
     spp_kq_render_start_screen(), formerly $can_start_today; re-checked
     server-side in spp_kq_handle_post_actions()'s 'start_round1' case)
     replaced from an exact current_time('Y-m-d') === $event_date match
     to the SAME threshold the 30-minutes-before-start screens-access
     gate already uses -- reuses spp_kq_get_occurrence_start_timestamp()
     directly, no second implementation of the math. Real incident: the
     exact-date rule wrongly blocked starting a real PAST occurrence
     (a sandbox event, or any event whose day had already passed) even
     though it's obviously long past its start time and should be
     freely startable -- a facilitator checked in a full sandbox
     roster and was blocked here. "now >= start - 30 minutes" already
     produces the right answer for all three real cases (within 30
     minutes of a future start, later today past that threshold, or
     any time after a past event's start) with no "is this a sandbox
     event" special-casing needed. spp_is_admin() exemption unchanged.
     Both functions now take an added $event_time parameter (source:
     eff_event_time, same caller-passes-it-down convention $event_date
     already used, so the two checks can never diverge) -- this is
     still a SEPARATE check from the 30-minutes-before-start screens-
     access gate (spp_kq_event_screens_open(), added in 1.8.0), not
     merged into it; that gate is completely unaffected by this change.
     Block message updated to match that gate's own wording/tone
     ("This event doesn't open until [time] on [date].") instead of
     the old "This event can only be started on its actual event
     date." -- one consistent message style across both gates.

   Changes from 1.7.0:
   - New 30-minutes-before-start access gate on [spp_kq_live] itself
     (spp_kq_event_screens_open()/spp_kq_render_too_early_notice(),
     wired into spp_kq_live_shortcode() before
     spp_kq_handle_post_actions() runs) -- previously the shortcode
     gated only on spp_kq_can_facilitate() (is_user_logged_in()), so a
     facilitator could open check-in/roster-adjust/anything for an
     event days or weeks out (the event picker itself lists the next 8
     upcoming occurrences regardless of date). This is a NEW, separate,
     earlier/broader rule -- the existing Start-Round-1 date-only gate
     ($can_start_today in spp_kq_render_start_screen(), re-checked in
     this file's own 'start_round1' POST case) is completely unchanged
     and still applies on top of this one. Administrator-exempt,
     matching that existing gate's own spp_is_admin() exemption
     exactly. See this file's own "30-minutes-before-start access gate"
     section header for the full writeup, including the timezone
     reasoning (confirmed against this site's real WP timezone_string,
     not assumed).

   Changes from 1.6.0 (Check-in + mid-event roster swap + court
   cancellation -- see the conversation this was built from for the
   full spec; most of the actual mechanics live in
   inc/spp-kq-live.php/inc/spp-kq-checkin.php/inc/spp-kq-roster.php, see
   each of those files' own changelogs):
   - spp_kq_render_start_screen() is now a check-in screen: lists every
     confirmed registrant with a toggle check-in button
     (spp_kq_get_confirmed_registrants_named()/
     spp_kq_get_checked_in_user_ids(), inc/spp-kq-checkin.php), and its
     "Cannot start" validation now runs against the CHECKED-IN count,
     not the raw confirmed count.
   - spp_kq_handle_post_actions(): 'start_round1' now reconciles
     check-in reality (withdraws anyone confirmed-but-not-checked-in)
     BEFORE calling spp_kq_transition_start_round1(), which is
     otherwise unchanged. New 'checkin_mark'/'checkin_unmark' cases.
     'start_play' now surfaces spp_kq_transition_start_play()'s own
     error (new in that function -- the understaffed-court guard) to
     the facilitator instead of discarding it. New 'roster_swap'/
     'roster_fill_slot'/'cancel_court' cases, thin wrappers over
     spp_kq_swap_player()/spp_kq_fill_open_slot()/spp_kq_cancel_court()
     (inc/spp-kq-live.php).
   - spp_kq_render_overview_screen()/spp_kq_render_in_play_screen(): a
     cancelled court now shows a badge instead of team lists/score
     inputs (the in-play screen's own JS wiring loop guards against a
     card with no score inputs to wire up); an understaffed court (a
     just-cancelled court's replacement slots re-created empty, see
     inc/spp-kq-live.php's own changelog) shows a "needs players"
     notice on the Overview screen and blocks the Start Play button
     entirely, both pointing at Roster Adjust.
   - spp_kq_styles(): new .kq-btn-small/.kq-roster-list/
     .kq-checkin-list/.kq-swap-row/.kq-swap-form/.kq-swap-select rules
     -- kq-btn-small and kq-roster-list existed in markup before this
     pass with no rule of their own (harmless fallback to default
     sizing/a bare list); given real definitions now that more screens
     use them.

   Changes from 1.5.0:
   - CSS FIX (spp_kq_styles()): scoreboard cards (.kq-court-grid/
     .kq-court-card, shared by the live Full Scoreboard screen and
     [spp_kq_event_detail]) stayed narrow at desktop widths, wrapping a
     "Red: <names> — <score>" line onto two lines. Root cause: .kq-wrap's
     max-width was a flat 560px with no responsive variance at all -- the
     only thing bounding the grid's available width on any viewport,
     phone through desktop alike, so a wide screen never gave the grid
     any more room than a narrow one did. Added two min-width-only media
     queries (600px, 900px) that widen .kq-wrap and raise
     .kq-court-grid's own minmax() floor in step, so cards get wider (not
     more numerous) as real screen width increases -- 2 columns per round
     still fits at every tier, only each column's own width grows
     (~274px base to ~444px at 900px+). min-width-only by construction:
     nothing here can affect a viewport narrower than 600px, so the
     already-correct phone rendering is untouched. CSS-only -- no
     PHP/data logic changed.

   Changes from 1.4.0:
   - spp_kq_render_full_scoreboard_screen()'s round/court markup moved
     into a new shared function, spp_kq_render_scoreboard_markup()
     (inc/spp-kq-history.php) -- this function is now just fetch (via
     spp_kq_get_full_scoreboard()) and hand off to that renderer.
     Confirmed byte-identical output for the live screen before/after.
     Done to let the new historical Event Detail view
     ([spp_kq_event_detail], same file) reuse the exact same layout
     against archived data (spp_kq_get_history_scoreboard()) instead of
     duplicating this markup.

   Changes from 1.3.0:
   - UX FIX (Full Reset investigated, confirmed working correctly at the
     DB level via marker-value testing -- see that investigation): a
     successful Full Reset rendered byte-identical to a silent failure,
     since this dispatcher's own convention was '' on success, plain
     text only on error, and Full Reset itself has nothing to visibly
     clear when the occurrence never progressed past 'not_started'.
     Added SPP_KQ_NOTICE_OK_PREFIX, a minimal opt-in marker a case can
     prefix its return with to have spp_kq_render_occurrence_header()
     style it kq-notice-ok (this file's own existing success-color CSS,
     already used by the in-play score-submission JS) instead of the
     default kq-notice-err -- 'full_reset' is the only case using it so
     far. No other case's returned text changes, so no other action's
     rendering changes at all.

   Changes from 1.2.0:
   - Replaced the Start screen's "fix your roster" link -- previously
     gl-registration-admin/?gl_reg_occ_id=X, the generic GL Events
     plugin tool gated to administrator/editor/convenor
     (GL_Roles::can_view_registrants()) -- with a link to a new
     ?kq_view=roster screen (spp_kq_render_roster_screen(),
     inc/spp-kq-roster.php), gated only by this shortcode's own
     spp_kq_can_facilitate() (any logged-in member), matching KQ's own
     access model instead of borrowing a stricter one. Two new
     spp_kq_handle_post_actions() cases, 'roster_add'/'roster_remove',
     same plain nonce-POST-and-rerender convention as every other
     action here. gl-registration-admin.php and every other gl-events
     plugin file are untouched -- this is a standalone replacement for
     KQ's use case only, not a fix to the shared tool.

   Changes from 1.1.0:
   - BUG FIX: 'cancel_event' in spp_kq_handle_post_actions() below now
     also calls spp_kq_finalize_event_history_and_recap()
     (inc/spp-kq-history.php), same call pattern as 'end_event' --
     immediately after spp_kq_maybe_publish_to_club_ratings(), only once
     $r['won'] confirms the transition actually happened. 1.1.0
     deliberately left this out of 'cancel_event', on the assumption a
     cancelled event had nothing worth archiving -- wrong: any round
     that had already reported before the cancellation is real data
     (spp_kq_transition_cancel_event() only ever discards the CURRENT
     round's unreported courts; earlier rounds are untouched), and
     Club Rating publish already treated it that way. Both transitions
     now trigger archiving/recap identically -- see
     inc/spp-kq-history.php's own header, which no longer describes this
     as an intentional asymmetry.

   Changes from 1.0.0:
   - Added the Full Scoreboard screen (spp_kq_render_full_scoreboard_screen(),
     spp_kq_render_scoreboard_link()): a ?kq_view=scoreboard flag layered
     on top of the phase switch below, not a new phase of its own --
     purely additive/read-only, no schema change, no interaction with
     spp_kq_events.phase/current_round or the movement algorithm at all.
     Shows every completed round/court/player/score for the occurrence
     so far, via spp_kq_get_full_scoreboard() (inc/spp-kq-history.php).
   - 'end_event' in spp_kq_handle_post_actions() below now also calls
     spp_kq_finalize_event_history_and_recap() (inc/spp-kq-history.php)
     immediately after spp_kq_maybe_publish_to_club_ratings(), same
     trigger point, only once $r['won'] confirms the transition actually
     happened -- archives spp_kq_history and sends each participant a
     recap email.

   PURPOSE:
   The [spp_kq_live] shortcode and its seven screens, plus the one
   AJAX action (the card draw). Everything here is UI/dispatch on top
   of inc/spp-kq-live.php's gate + transition mechanics and
   inc/spp-kq-movement.php's pure algorithm -- no game logic lives in
   this file.

   Stage 2 (final part) of a staged build (see conversation). Screen
   selection is driven entirely by spp_kq_events.phase/current_round
   plus, for 'organizing' specifically, whether round 1's draw is
   still incomplete:

     not_started                              -> Start screen
     organizing, round 1, unclaimed slots > 0  -> Draw screen
     organizing, otherwise                     -> Overview screen
     in_play                                   -> In-Play placeholder
     complete                                  -> Complete screen
     cancelled                                 -> Cancelled screen

   ACCESS: spp_kq_can_facilitate() (inc/spp-kq-live.php) is the only
   gate, checked once at the top of the shortcode and again in the
   AJAX handler -- nowhere else in this file re-derives or narrows it.

   ACTIONS: Start Round 1 Draw / Start Play / End Event are plain
   nonce-protected POST forms with no redirect afterward (same
   convention as spp-schedule-adjust.php, registration-admin.php,
   spp-remove-inactive-ladder-users.php elsewhere in this codebase) --
   the page just re-renders whatever screen the new state calls for.
   The card draw is the one AJAX action, matching spp-score-entry.php's
   convention for many-small-taps-with-live-feedback interactions.
   Cancel Event (same POST-form mechanics, and its dispatcher case/
   transition function are both still intact) no longer has a UI
   trigger anywhere in this file as of 1.25.0 -- see that changelog
   entry above.
   ========================================================= */

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in marker prefix a spp_kq_handle_post_actions() case can put on its
 * returned string to have spp_kq_render_occurrence_header() style it as a
 * success notice (kq-notice-ok) instead of the dispatcher's longstanding
 * default (kq-notice-err, historically used for every notice since this
 * dispatcher's only convention was "'' on success, plain text on
 * failure"). See that function's own comment for the full reasoning.
 */
const SPP_KQ_NOTICE_OK_PREFIX = "\u{2713} "; // "✓ "

/**
 * Match-timer defaults/bounds (1.10.0) -- the ONE place these three
 * numbers are defined; the Start Play duration field's default/min/max
 * attributes and spp_kq_sanitize_round_minutes()'s server-side clamp
 * both read from these constants so the two can never drift apart.
 */
const SPP_KQ_DEFAULT_ROUND_MINUTES = 13;
const SPP_KQ_MIN_ROUND_MINUTES = 1;
const SPP_KQ_MAX_ROUND_MINUTES = 60;

/**
 * Clamp a facilitator-submitted round length to a sane range, same
 * "never trust the client even though the UI already enforces min/max"
 * discipline as every other POST handler in this file -- missing or
 * non-numeric input falls back to the same default the UI itself
 * offers, rather than erroring the whole Start Play action out.
 */
function spp_kq_sanitize_round_minutes( $raw ) : int {
    $minutes = is_numeric( $raw ) ? (int) $raw : SPP_KQ_DEFAULT_ROUND_MINUTES;
    return max( SPP_KQ_MIN_ROUND_MINUTES, min( SPP_KQ_MAX_ROUND_MINUTES, $minutes ) );
}

// =============================================================
// Small read helpers specific to rendering (mechanics live in
// spp-kq-live.php; these just shape data for display).
// =============================================================

function spp_kq_get_occurrence_summary( int $occurrence_id ) : ?array {
    global $wpdb;
    $view = $wpdb->prefix . 'gl_events_v';
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT occurrence_id, eff_title, event_date, eff_event_time, category_name
         FROM {$view} WHERE occurrence_id = %d",
        $occurrence_id
    ), ARRAY_A );
    return $row ?: null;
}

/**
 * A KQ occurrence's recurring-event ref, in the exact "series:<id>"
 * shape [spp_photo_submit]'s own dropdown already uses as an <option
 * value> (spp_photo_gallery_event_options(), wp-content/plugins/
 * spp-photo-gallery/includes/photo-submission.php) -- every real KQ
 * occurrence belongs to one of the 4 fixed Ace/Queen 9:00am/10:30am
 * series (spp_kq_get_occurrence_summary()'s own view), never a
 * detached one-off. FAILS CLOSED: null if the series can't be
 * resolved (shouldn't happen for a real KQ occurrence, but defensive,
 * same discipline as this file's other "can't determine X" paths).
 *
 * 1.16.0: this used to also resolve the Submit Photo PAGE and build a
 * redirect URL (spp_kq_resolve_photo_submit_url(), same file) -- gone
 * now that the form is embedded inline (spp_kq_render_photo_prompt()
 * below) instead of linked to; no URL is needed any more, just this
 * ref string, passed straight to do_shortcode() as an attribute.
 */
function spp_kq_resolve_photo_event_ref( int $occurrence_id ) : ?string {
    global $wpdb;
    $view = $wpdb->prefix . 'gl_events_v';
    $series_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT series_id FROM {$view} WHERE occurrence_id = %d",
        $occurrence_id
    ) );
    return $series_id ? ( 'series:' . (int) $series_id ) : null;
}

/**
 * The Submit Photo prompt embedded directly on the Complete/Cancelled
 * screen (1.16.0 -- replaces the earlier same-day redirect entirely,
 * see this file's own 1.16.0 changelog for why: End Event/Cancel Event
 * are the only ways to ever reach these phases, and both are direct
 * page actions, not something a redirect needed to catch a device
 * mid-poll for -- every device lands here one way or another, either
 * via this same direct render or via the persistent app's own
 * fragment-swap, so embedding beats linking).
 *
 * Gating condition (1.17.0 -- DECOUPLED from spp_kq_history_exists_
 * for_occurrence(), which this used to reuse): now just spp_kq_has_
 * any_recorded_score() directly -- "was at least one round of THIS
 * occurrence actually played," full stop, no other condition riding
 * along with it. Confirmed via a real event, not a synthetic case: a
 * genuine occurrence, fully played through 3 rounds and properly ended
 * via End Event, showed no prompt purely because spp_kq_history_
 * exists_for_occurrence() ALSO requires spp_kq_archive_event_history()
 * to have actually written rows -- which that function refuses to do
 * for ANY occurrence dated before SPP_KQ_CLUB_RATING_LAUNCH_DATE,
 * regardless of how real or complete the event was (see that
 * function's own pre-launch guard, inc/spp-kq-history.php -- checked
 * FIRST, before it even looks at whether anything was reported). The
 * photo prompt has nothing to do with permanent history archival or
 * Club Rating publishing -- both of those KEEP their own launch-date
 * gate completely unchanged (spp_kq_archive_event_history(),
 * spp_kq_maybe_publish_to_club_ratings()) -- this was never a
 * reasonable case for the two to share one gate. Called only from
 * spp_kq_render_complete_screen()/spp_kq_render_cancelled_screen(), so
 * "is this occurrence complete, or cancelled" is already satisfied by
 * construction (this function is never reached from anywhere else);
 * spp_kq_has_any_recorded_score() is the one remaining real question,
 * and for the complete-screen caller specifically it's always true
 * anyway -- End Event itself refuses to fire at all until at least one
 * score already exists (spp_kq_handle_post_actions()'s own 'end_event'
 * precondition), so this only ever actually DISCRIMINATES for the
 * cancelled-screen caller, exactly as the spec's own rule describes.
 * Returns '' (no note, no form) when that condition doesn't hold --
 * callers can unconditionally echo this, same convention as spp_kq_
 * render_scoreboard_link()'s own "return '' when there's nothing to
 * show" shape.
 */
function spp_kq_render_photo_prompt( int $occurrence_id ) : string {
    if ( ! spp_kq_has_any_recorded_score( $occurrence_id ) ) {
        return '';
    }
    $event_ref = spp_kq_resolve_photo_event_ref( $occurrence_id );
    if ( ! $event_ref ) {
        return '';
    }

    ob_start();
    ?>
    <p class="kq-hint">Please take a photo and submit it!</p>
    <?php echo do_shortcode( sprintf(
        '[spp_photo_submit event_ref="%s" date="%s"]',
        $event_ref,
        current_time( 'Y-m-d' )
    ) ); ?>
    <?php
    // 1.18.0: force-enqueue [spp_photo_submit]'s own assets (Cropper.js,
    // exif-js, photo-submit.js) and embed the resulting <script>/<link>
    // tags DIRECTLY in this same output -- confirmed the plugin's own
    // conditional wp_enqueue_scripts callback can never detect this
    // shortcode being rendered here (has_shortcode() checks the KQ
    // page's OWN stored content, which is just [spp_kq_live] -- this
    // shortcode only ever appears dynamically, deep inside this
    // function, never as literal text on that page), so without this
    // the embedded form's submit button stays permanently disabled --
    // nothing ever wires it up. Embedding the actual tags (rather than
    // a plain wp_enqueue_script() call relying on wp_footer() to flush
    // them) is what makes this work for BOTH a direct page render of
    // this screen AND the persistent app's own fragment-swap
    // (executeScripts(), same file) injecting this same html much
    // later -- there is no wp_footer() call at all during the AJAX
    // request that path uses, so the tags have to travel WITH this
    // string itself. function_exists() guard: the photo-gallery plugin
    // is a separate plugin this theme depends on, not the other way
    // around -- degrade to just the (script-less, submit-button-
    // disabled) form rather than a fatal error if it's ever inactive.
    if ( function_exists( 'spp_photo_gallery_enqueue_submit_assets' ) ) {
        echo spp_photo_gallery_enqueue_submit_assets();
    }
    return ob_get_clean();
}

// =============================================================
// 30-minutes-before-start access gate (1.2.0). Separate, earlier/
// broader rule from the Start-Round-1 gate ($can_start_now in
// spp_kq_render_start_screen(), re-checked server-side in
// spp_kq_handle_post_actions()'s 'start_round1' case) -- that one is
// still its own separate check, applying on top of this one once a
// facilitator is past this gate; this one blocks EVERYTHING reachable
// for an occurrence (check-in, roster-adjust, live play, POST actions
// included -- checked in spp_kq_live_shortcode() before
// spp_kq_handle_post_actions() runs at all, not just before the
// phase-screen switch), not just Start Round 1 itself. As of 1.3.0
// the Start-Round-1 gate reuses the exact same threshold function
// (spp_kq_get_occurrence_start_timestamp(), below) this gate does --
// see that version's own changelog for why (it used to be a
// current_time('Y-m-d') === $event_date exact match, which wrongly
// blocked starting a real PAST occurrence).
//
// TIMEZONE: this site's WP timezone is confirmed correctly set to a
// real IANA zone (timezone_string = 'America/Toronto', not a static
// gmt_offset), so current_time() is DST-aware. strtotime() on a plain
// 'event_date event_time' string is interpreted under PHP's own
// default timezone, which WordPress sets to UTC -- current_time()'s
// values live in that exact same "local wall-clock time, readable as
// if it were UTC" domain (a long-documented WP quirk), so the two are
// directly comparable with no further conversion. Confirmed, not
// assumed: this is the identical convention spp_kq_render_picker_row()/
// spp_kq_render_occurrence_header() already use to display
// eff_event_time correctly (date_i18n(strtotime($eff_event_time))).
// =============================================================

/**
 * Real scheduled start datetime for an occurrence, as a timestamp in
 * the current_time()-compatible domain described above. Returns null
 * if $event_time is missing -- gl-events' own schema allows
 * eff_event_time to be NULL for a truly standalone occurrence with no
 * series and no time of its own (event_occurrences.event_time is
 * nullable; only event_series.event_time is NOT NULL) -- confirmed
 * via direct query that zero real KQ (category 2/3) occurrences hit
 * this today, but callers must not treat null as "open" regardless --
 * see spp_kq_event_screens_open()'s own fail-closed default.
 */
function spp_kq_get_occurrence_start_timestamp( string $event_date, ?string $event_time ) : ?int {
    if ( ! $event_date || ! $event_time ) {
        return null;
    }
    $ts = strtotime( $event_date . ' ' . $event_time );
    return ( $ts !== false ) ? $ts : null;
}

/**
 * Whether [spp_kq_live]'s screens are open for this occurrence right
 * now -- actual scheduled start time minus a 30-minute early-access
 * window, current server clock. Administrator exemption is checked by
 * the caller (spp_kq_live_shortcode()), not here -- same "gate belongs
 * to the caller" pattern the existing Start-Round-1 checks already
 * use, so spp_is_admin() is asserted in exactly one place per gate.
 *
 * FAILS CLOSED: an occurrence whose start time can't be determined at
 * all (see spp_kq_get_occurrence_start_timestamp()) is NOT open --
 * there is nothing real to gate against otherwise, and silently
 * allowing access on missing data would defeat the point of this gate.
 */
function spp_kq_event_screens_open( string $event_date, ?string $event_time ) : bool {
    $start_ts = spp_kq_get_occurrence_start_timestamp( $event_date, $event_time );
    if ( $start_ts === null ) {
        return false;
    }
    return current_time( 'timestamp' ) >= ( $start_ts - 30 * MINUTE_IN_SECONDS );
}

/**
 * "Not open yet" notice for a too-early access attempt -- states the
 * actual computed opening time (start minus 30 minutes) so a
 * facilitator knows exactly when to come back, rather than a generic
 * "try again later." Own small wrapper (not the full kq-wrap styles)
 * since nothing else on this response needs them.
 */
function spp_kq_render_too_early_notice( string $event_date, ?string $event_time ) : string {
    $start_ts = spp_kq_get_occurrence_start_timestamp( $event_date, $event_time );
    if ( $start_ts === null ) {
        return '<div class="kq-wrap"><p class="kq-hint">This event\'s scheduled time isn\'t set yet -- please check back closer to the event, or contact an administrator.</p></div>';
    }
    $opens_at  = $start_ts - 30 * MINUTE_IN_SECONDS;
    $when      = date_i18n( 'g:ia', $opens_at ) . ' on ' . date_i18n( 'l, F j', $opens_at );
    return '<div class="kq-wrap"><p class="kq-hint">This event doesn\'t open until ' . esc_html( $when ) . '.</p></div>';
}

function spp_kq_count_unclaimed( int $occurrence_id, int $round_number ) : int {
    global $wpdb;
    $table = spp_kq_assignments_table();
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE occurrence_id = %d AND round_number = %d AND user_id IS NULL",
        $occurrence_id, $round_number
    ) );
}

function spp_kq_player_name( ?string $first, ?string $last, int $user_id ) : string {
    $name = trim( ( $first ?? '' ) . ' ' . ( $last ?? '' ) );
    if ( $name !== '' ) {
        return $name;
    }
    // Guests (spp_kq_roster_add_guest(), inc/spp-kq-roster.php) are real
    // WP users with NO membership-table row by design -- show the name
    // typed at creation instead of the generic placeholder below, which
    // stays reserved for a genuine data problem (a real, non-guest user
    // somehow missing from membership).
    if ( get_user_meta( $user_id, 'spp_kq_guest', true ) ) {
        $user = get_userdata( $user_id );
        if ( $user && $user->display_name !== '' ) {
            return $user->display_name;
        }
    }
    return "Member #{$user_id}";
}

/**
 * Confirmed registrants for this occurrence not yet drawn in round 1,
 * named via membership (this codebase's established convention --
 * see gl-registrant-list.php / registration-admin.php -- not WP
 * display_name).
 */
function spp_kq_get_not_yet_drawn( int $occurrence_id ) : array {
    global $wpdb;

    $confirmed_ids = spp_kq_confirmed_user_ids( $occurrence_id );
    if ( empty( $confirmed_ids ) ) {
        return array();
    }

    $table = spp_kq_assignments_table();
    $drawn_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id FROM {$table} WHERE occurrence_id = %d AND round_number = 1 AND user_id IS NOT NULL",
        $occurrence_id
    ) ) );

    $remaining_ids = array_values( array_diff( $confirmed_ids, $drawn_ids ) );
    if ( empty( $remaining_ids ) ) {
        return array();
    }

    $placeholders = implode( ',', array_fill( 0, count( $remaining_ids ), '%d' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, first_name, last_name FROM membership WHERE user_id IN ({$placeholders})",
        $remaining_ids
    ), ARRAY_A );

    $by_id = array();
    foreach ( $rows as $r ) {
        $by_id[ (int) $r['user_id'] ] = spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] );
    }

    $out = array();
    foreach ( $remaining_ids as $uid ) {
        // 1.19.1 BUG FIX: the membership-table query above naturally never
        // returns a row for a guest (no membership row by design), so
        // $by_id[$uid] was always missing for one and this fell straight to
        // the bare "Member #{id}" placeholder -- never gave spp_kq_player_
        // name() a chance to apply its guest fallback. Routing the miss
        // through that function instead preserves the exact same behavior
        // for a genuine missing-membership-row bug (still "Member #{id}"),
        // while a guest now resolves to their typed name.
        $out[] = array( 'user_id' => $uid, 'name' => $by_id[ $uid ] ?? spp_kq_player_name( null, null, $uid ) );
    }
    return $out;
}

/**
 * Round 1's reveal state for the draw screen: every (court, color)
 * slot, always exactly 2 entries per color, each either a player name
 * (already drawn) or null (still face-down). Used both for the
 * initial server render and to let a mid-draw page reload show
 * exactly where things stand.
 */
function spp_kq_get_draw_reveal_state( int $occurrence_id, array $courts_order ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.court_name, a.team_color, a.user_id, m.first_name, m.last_name
         FROM {$table} a
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE a.occurrence_id = %d AND a.round_number = 1
         ORDER BY a.court_name, a.team_color, a.id",
        $occurrence_id
    ), ARRAY_A );

    $out = array();
    foreach ( $courts_order as $court ) {
        $out[ $court ] = array( 'red' => array( null, null ), 'black' => array( null, null ) );
    }
    $cursor = array_fill_keys( $courts_order, array( 'red' => 0, 'black' => 0 ) );

    foreach ( $rows as $r ) {
        $court = $r['court_name']; $color = $r['team_color'];
        if ( ! isset( $cursor[ $court ][ $color ] ) || $cursor[ $court ][ $color ] > 1 ) {
            continue; // defensive -- should never happen with exactly 2 slots/color
        }
        $idx = $cursor[ $court ][ $color ]++;
        $name = $r['user_id'] !== null ? spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] ) : null;
        $out[ $court ][ $color ][ $idx ] = $name;
    }
    return $out;
}

/**
 * One round's full court/team roster (names only, all slots assumed
 * filled -- used by the Overview screen, which only ever shows once
 * round 1's draw is complete, or a round > 1 whose assignments were
 * all written atomically by the movement algorithm).
 */
function spp_kq_get_round_court_view( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $table = spp_kq_assignments_table();

    $courts_order = spp_kq_determine_courts_order( $occurrence_id );
    $out = array();
    foreach ( $courts_order as $court ) {
        $out[ $court ] = array( 'red' => array(), 'black' => array() );
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.court_name, a.team_color, a.user_id, m.first_name, m.last_name
         FROM {$table} a
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE a.occurrence_id = %d AND a.round_number = %d AND a.user_id IS NOT NULL
         ORDER BY a.court_name, a.team_color",
        $occurrence_id, $round_number
    ), ARRAY_A );

    foreach ( $rows as $r ) {
        if ( ! isset( $out[ $r['court_name'] ] ) ) continue;
        // 1.19.1 BUG FIX: this used to hardcode 0 for spp_kq_player_name()'s
        // user_id param (never SELECTed a.user_id at all) -- harmless for a
        // real member (name always resolved from first_name/last_name), but
        // a guest has neither, so spp_kq_player_name() fell through to its
        // usermeta-driven guest-fallback check, which looked up user_id=0
        // instead of the real player and failed, printing "Member #0".
        $out[ $r['court_name'] ][ $r['team_color'] ][] = spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] );
    }
    return $out;
}

/**
 * Final-round Aces winner, for the Complete screen. Returns null if
 * Aces never reported a score this round (End Event can fire from
 * 'organizing' before any play happens at all -- see this feature's
 * own transition docs) rather than guessing.
 */
function spp_kq_get_final_winner_names( int $occurrence_id, int $round_number ) : ?string {
    global $wpdb;
    $scores_table = spp_kq_scores_table();
    $assignments_table = spp_kq_assignments_table();

    $score = $wpdb->get_row( $wpdb->prepare(
        "SELECT red_score, black_score FROM {$scores_table}
         WHERE occurrence_id = %d AND round_number = %d AND court_name = 'Aces'",
        $occurrence_id, $round_number
    ), ARRAY_A );

    if ( ! $score || $score['red_score'] === null || $score['black_score'] === null ) {
        return null;
    }

    $winning_color = ( (int) $score['red_score'] > (int) $score['black_score'] ) ? 'red' : 'black';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.user_id, m.first_name, m.last_name FROM {$assignments_table} a
         LEFT JOIN membership m ON m.user_id = a.user_id
         WHERE a.occurrence_id = %d AND a.round_number = %d AND a.court_name = 'Aces' AND a.team_color = %s",
        $occurrence_id, $round_number, $winning_color
    ), ARRAY_A );

    if ( empty( $rows ) ) return null;

    // 1.19.1 BUG FIX: same hardcoded-0 bug as spp_kq_get_round_court_view()
    // just above -- see that function's own inline comment.
    $names = array_map( fn( $r ) => spp_kq_player_name( $r['first_name'], $r['last_name'], (int) $r['user_id'] ), $rows );
    return implode( ' & ', $names );
}

/**
 * Cancelled-event summary for the Cancelled screen: which courts kept
 * a real result (with the actual score, so a facilitator can confirm
 * "yes, that's the score that counted") vs. which were discarded.
 * Reconstructed purely from what's left after cancellation -- courts
 * still present in spp_kq_scores for that round were kept (a row only
 * survives Cancel Event if it was already fully reported); the rest
 * of the event's fixed court set (spp_kq_determine_courts_order(),
 * which still resolves correctly even when round 1 itself was wiped
 * -- see that function's own fallback) were discarded.
 */
function spp_kq_get_cancellation_summary( int $occurrence_id, int $round_number ) : array {
    global $wpdb;
    $scores_table = spp_kq_scores_table();

    $courts_order = spp_kq_determine_courts_order( $occurrence_id );

    $kept_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT court_name, red_score, black_score FROM {$scores_table}
         WHERE occurrence_id = %d AND round_number = %d",
        $occurrence_id, $round_number
    ), ARRAY_A );

    $kept = array();
    foreach ( $kept_rows as $r ) {
        $kept[ $r['court_name'] ] = array( 'red' => (int) $r['red_score'], 'black' => (int) $r['black_score'] );
    }

    $discarded = array_values( array_diff( $courts_order, array_keys( $kept ) ) );

    return array( 'kept' => $kept, 'discarded' => $discarded );
}

// =============================================================
// Shared CSS (embedded once per render -- same convention as
// spp-score-entry.php)
// =============================================================

function spp_kq_styles() : string {
    return '<style>
        .kq-wrap { max-width:560px; margin:10px auto; font-family:Arial,sans-serif; font-size:15px; line-height:1.4; color:#222; }
        .kq-back a { color:#3766AB; text-decoration:none; font-size:14px; }
        .kq-heading { margin:6px 0 2px; font-size:20px; }
        .kq-subheading { margin:0 0 14px; color:#666; font-size:14px; }
        .kq-hint { margin:0 0 14px; color:#666; font-size:14px; font-style:italic; }
        .kq-caveat { margin:0 0 14px; padding:8px 12px; background:#f7f7f7; border-left:3px solid #999; color:#555; font-size:13px; }
        .kq-meta { color:#555; margin-bottom:14px; }
        .kq-round-label { font-weight:bold; font-size:16px; margin:0 0 12px; color:#2c3e50; }
        .kq-round-label-tight { font-weight:bold; font-size:16px; margin:0 0 2px; color:#2c3e50; }
        .kq-notice { padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:14px; }
        .kq-notice-err { background:#f8d7da; border:1px solid #dc3545; color:#721c24; }
        .kq-notice-ok { background:#d4edda; border:1px solid #28a745; color:#155724; }
        .kq-status { background:#f0f7ff; border:1px solid #3766AB; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:14px; color:#2c3e50; }
        .kq-score-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:14px; }
        .kq-score-row label { font-size:13px; color:#555; }
        .kq-score-input { display:block; width:70px; padding:8px; font-size:18px; text-align:center; border:1px solid #bbb; border-radius:6px; margin-top:4px; }
        .kq-saved { font-size:13px; color:#27ae60; font-weight:bold; }
        .kq-warn { background:#fff8e1; border:1px solid #e67e22; border-radius:6px; padding:12px 14px; color:#7a4a00; }
        .kq-warn a { color:#3766AB; }
        .kq-btn { padding:10px 20px; border:none; border-radius:6px; font-size:15px; cursor:pointer; }
        .kq-btn-primary { background:#3766AB; color:#fff; }
        .kq-btn-secondary { background:#888; color:#fff; }
        .kq-btn-danger { background:#c0392b; color:#fff; }
        .kq-action-row { display:flex; gap:10px; margin-top:16px; }
        .kq-action-row-right { justify-content:flex-end; }
        .kq-inline-form { display:inline-block; }
        .kq-picker-list { display:flex; flex-direction:column; gap:8px; }
        .kq-picker-row { display:flex; align-items:center; gap:4px; padding:10px 10px; border:1px solid #ddd; border-radius:8px; text-decoration:none; color:#222; flex-wrap:wrap; }
        .kq-picker-row:hover { background:#f5f8fc; }
        /* flex-shrink:0 (via 1 0 auto) matters here: title is the only
           item whose text can wrap across multiple words, so a plain
           flex-shrink:1 dumped nearly the entire deficit onto it alone --
           date/regcount/status are white-space:nowrap, so their own
           automatic minimum is already their full natural width, never
           really shrinking. Letting the whole ROW wrap onto a second
           line under real pressure (flex-wrap:wrap on the row, still
           active) degrades far more gracefully than wrapping mid-title.
           Every other measurement on this row (padding, gap, badge/status
           padding) is trimmed as tight as still reads comfortably, purely
           to buy title the room it needs within the fixed 560px .kq-wrap
           max-width -- the longest real titles ("Queen of the Courts...")
           were still a couple dozen px over budget even with 1 0 auto alone. */
        .kq-picker-title { font-weight:bold; flex:1 0 auto; }
        .kq-picker-date { color:#666; font-size:13px; white-space:nowrap; }
        .kq-picker-regcount { color:#666; font-size:13px; white-space:nowrap; }
        .kq-picker-status { font-size:12px; font-weight:bold; padding:2px 6px; border-radius:12px; white-space:nowrap; }
        .kq-picker-row--test { background:#faf7ff; border-color:#d8cdf0; }
        .kq-picker-row--test:hover { background:#f3edfc; }
        .kq-picker-badge { font-size:10px; font-weight:bold; padding:1px 5px; border-radius:9px; white-space:nowrap; letter-spacing:.02em; }
        .kq-picker-badge--test { background:#e8def8; color:#6b3fa0; }
        .kq-picker-section-heading { font-size:15px; margin:22px 0 4px; color:#555; }
        .kq-status-not-started { background:#eee; color:#666; }
        .kq-status-organizing { background:#fff3cd; color:#8a6100; }
        .kq-status-in_play { background:#d4edda; color:#155724; }
        .kq-status-complete { background:#dde7f3; color:#2c3e50; }
        .kq-status-cancelled { background:#f8d7da; color:#721c24; }
        .kq-court-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin-bottom:10px; }
        .kq-court-card { border:1px solid #ddd; border-radius:8px; padding:12px 14px; background:#fff; }
        .kq-court-name { font-weight:bold; font-size:16px; margin-bottom:6px; color:#2c3e50; }
        .kq-team { font-size:14px; margin-bottom:2px; }
        .kq-team-red { color:#c0392b; }
        .kq-team-black { color:#222; }
        .kq-draw-progress { color:#555; font-size:14px; margin:0 0 16px; }
        .kq-draw-columns { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
        .kq-draw-col { flex:1 1 220px; }
        .kq-draw-col h3 { font-size:14px; margin:0 0 8px; color:#555 !important; text-transform:uppercase; letter-spacing:.5px; }
        #kq-not-drawn-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; }
        .kq-player-btn { width:100%; padding:10px; font-size:15px; border:2px solid #3766AB; background:#fff; color:#3766AB; border-radius:6px; cursor:pointer; text-align:left; }
        .kq-player-btn.kq-selected { background:#3766AB; color:#fff; }
        #kq-card-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(56px,1fr)); gap:8px; }
        .kq-card { aspect-ratio:2/3; background:#2c3e50; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:24px; color:#fff; cursor:pointer; user-select:none; }
        .kq-card:hover { background:#3f5a80; }
        .kq-draw-revealed h3 { font-size:14px; margin:0 0 8px; color:#555 !important; text-transform:uppercase; letter-spacing:.5px; }
        .kq-slot { color:#999; }
        .kq-slot.kq-slot-filled { color:inherit; font-weight:bold; }
        .kq-cancel-summary { display:flex; gap:20px; flex-wrap:wrap; }
        /* !important: the theme own content-area h3 color rule outranks any
           class-based selector here (confirmed by testing -- even a doubled
           .kq-wrap prefix did not win), and the whole point of these two is the
           green/red semantic distinction, so it cannot just inherit the theme
           default the way every other heading in this feature harmlessly does. */
        .kq-kept h3 { color:#155724 !important; font-size:14px; margin:0 0 6px; }
        .kq-discarded h3 { color:#721c24 !important; font-size:14px; margin:0 0 6px; }
        .kq-cancel-summary ul { margin:0; padding-left:18px; font-size:14px; }
        .kq-full-reset-row { margin-top:28px; padding-top:14px; border-top:1px dashed #ccc; text-align:right; }
        .kq-full-reset-row .kq-btn { font-size:13px; padding:6px 14px; opacity:.85; }
        /* 1.1.0: check-in list, roster list, and the live swap screen
           (inc/spp-kq-checkin.php / inc/spp-kq-roster.php) all share
           this small-button/list vocabulary -- kq-btn-small and
           kq-roster-list existed in markup before this pass but had no
           rule of their own (harmless -- they just fell back to a
           normal-size button / a bare list); added here rather than
           left undefined now that more screens rely on them. */
        .kq-btn-small { padding:6px 12px; font-size:13px; }
        .kq-roster-list, .kq-checkin-list { list-style:none; margin:0 0 16px; padding:0; display:flex; flex-direction:column; gap:6px; }
        .kq-roster-list li, .kq-checkin-list li { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 10px; border:1px solid #eee; border-radius:6px; }
        .kq-checkin-list .kq-btn { width:100%; text-align:left; }
        .kq-swap-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:6px 0; border-bottom:1px solid #f0f0f0; }
        .kq-swap-row:last-of-type { border-bottom:none; }
        .kq-swap-form { display:flex; align-items:center; gap:6px; margin-top:4px; flex-basis:100%; }
        .kq-swap-select { padding:5px 8px; border:1px solid #ddd; border-radius:4px; max-width:220px; }
        /* Responsive scoreboard card width -- min-width media queries only
           (never max-width), so nothing here can ever affect a viewport
           narrower than 600px: the existing, already-correct phone
           rendering (a bare .kq-wrap max-width:560px above, unconditional
           until now) is untouched below that point.
           ROOT CAUSE this fixes: the .kq-wrap max-width was a flat 560px
           with no responsive variance at all -- the ONLY thing bounding
           the kq-court-grid available width, on every viewport, phone
           through desktop alike. A wide desktop screen never gave the
           grid any more room than a phone did, so auto-fit column math
           (and therefore each card actual rendered width) was capped
           just as low on a 1920px screen as on a 400px one -- narrow
           enough that a line like "Red: (names) dash (score)" wrapped
           onto a second line. Cards were never actually a single column
           at desktop width in the auto-fit sense; the whole .kq-wrap
           widget itself just never grew past 560px, so however many
           columns the grid picked, each one stayed just as cramped as
           on a phone.
           FIX: let .kq-wrap grow at wider viewports, and raise the
           kq-court-grid own minmax() floor to match, so each column
           gets meaningfully wider -- not more numerous -- as more real
           screen width becomes available. 2 courts per round (the common
           case) still fits exactly 2 columns at every tier below
           (2 times new-minimum plus gap stays under the new .kq-wrap
           max-width at each step), so the existing 2-column-per-round
           grouping is unchanged; only the width available to each
           column grows. */
        @media (min-width:600px) {
            .kq-wrap { max-width:760px; }
            .kq-court-grid { grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); }
        }
        @media (min-width:900px) {
            .kq-wrap { max-width:900px; }
            .kq-court-grid { grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); }
        }
        /* 1.10.0: match timer + voice announcements -- the Start Play
           duration field, the audio-unlock banner, and the timer itself. */
        .kq-start-play-form { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; }
        .kq-duration-label { font-size:13px; color:#555; }
        .kq-duration-input { display:block; width:70px; padding:8px; font-size:16px; text-align:center; border:1px solid #bbb; border-radius:6px; margin-top:4px; }
        .kq-audio-unlock { margin-bottom:14px; }
        .kq-audio-unlock .kq-btn { width:100%; }
        .kq-timer-wrap { text-align:center; background:#f0f7ff; border:1px solid #3766AB; border-radius:8px; padding:14px; margin-bottom:16px; }
        .kq-timer { font-size:42px; font-weight:bold; color:#2c3e50; line-height:1.1; font-variant-numeric:tabular-nums; }
        .kq-timer-label { font-size:13px; color:#666; margin-top:2px; }
        .kq-timer-wrap.kq-timer-warn { background:#fff8e1; border-color:#e67e22; }
        .kq-timer-wrap.kq-timer-warn .kq-timer { color:#e67e22; }
        .kq-timer-wrap.kq-timer-critical { background:#f8d7da; border-color:#c0392b; }
        .kq-timer-wrap.kq-timer-critical .kq-timer { color:#c0392b; }
        .kq-timer-wrap.kq-timer-done { background:#eee; border-color:#ccc; opacity:.7; }
        .kq-timer-wrap.kq-timer-done .kq-timer { color:#888; }
    </style>';
}

function spp_kq_status_label( ?string $phase, int $round ) : array {
    if ( ! $phase || $phase === 'not_started' ) {
        return array( 'label' => 'Not started', 'class' => 'not-started' );
    }
    $map = array(
        'organizing' => "Round {$round} \u{00b7} Organizing",
        'in_play'    => "Round {$round} \u{00b7} In Play",
        'complete'   => 'Complete',
        'cancelled'  => 'Cancelled',
    );
    return array( 'label' => $map[ $phase ] ?? $phase, 'class' => $phase );
}

// =============================================================
// Screen 1: Event picker
// =============================================================

/**
 * One picker row -- shared by both the upcoming list and the practice
 * sandbox list below, so the two can never drift apart in what they
 * show. $is_test adds the PRACTICE badge and a distinct row class;
 * everything else (title, date, registrant count, phase status) is
 * identical either way.
 */
function spp_kq_render_picker_row( array $r, bool $is_test = false ) : string {
    $status = spp_kq_status_label( $r['phase'] ?? null, (int) ( $r['current_round'] ?? 0 ) );
    $date_str = date_i18n( 'M j', strtotime( $r['event_date'] ) );
    $time_str = $r['eff_event_time'] ? date_i18n( 'g:ia', strtotime( $r['eff_event_time'] ) ) : '';
    // Same GL_Registration-backed count the Start screen shows
    // ("N confirmed registrants") -- spp_kq_confirmed_count()
    // (inc/spp-kq-live.php), not a new query.
    $reg_count = spp_kq_confirmed_count( (int) $r['occurrence_id'] );

    ob_start();
    ?>
    <a class="kq-picker-row<?php echo $is_test ? ' kq-picker-row--test' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'occ', $r['occurrence_id'] ) ); ?>">
        <?php if ( $is_test ) : ?>
            <span class="kq-picker-badge kq-picker-badge--test">PRACTICE</span>
        <?php endif; ?>
        <span class="kq-picker-title"><?php echo esc_html( $r['eff_title'] ); ?></span>
        <span class="kq-picker-date"><?php echo esc_html( trim( $date_str . ' ' . $time_str ) ); ?></span>
        <span class="kq-picker-regcount"><?php echo esc_html( $reg_count ); ?> confirmed</span>
        <span class="kq-picker-status kq-status-<?php echo esc_attr( $status['class'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
    </a>
    <?php
    return ob_get_clean();
}

function spp_kq_render_event_picker() : string {
    global $wpdb;
    $view = $wpdb->prefix . 'gl_events_v';
    $events_table = spp_kq_events_table();

    // Next 8 upcoming occurrences only, combined across Ace and Queen
    // (eff_category_id IN (2,3) already pools both) -- same chronological
    // order as before, just truncated so this list doesn't grow unbounded
    // as far-future occurrences get scheduled.
    $rows = $wpdb->get_results(
        "SELECT v.occurrence_id, v.eff_title, v.event_date, v.eff_event_time,
                e.current_round, e.phase
         FROM {$view} v
         LEFT JOIN {$events_table} e ON e.occurrence_id = v.occurrence_id
         WHERE v.eff_category_id IN (2,3) AND v.cancelled = 0 AND v.event_date >= CURDATE()
         ORDER BY v.event_date ASC, v.eff_event_time ASC
         LIMIT 8",
        ARRAY_A
    );

    // Practice / Test Sandbox: the 4 most recent occurrences dated before
    // SPP_KQ_CLUB_RATING_LAUNCH_DATE (inc/spp-kq-club-rating.php), same
    // category pooling and cancelled=0 filter as the upcoming list above.
    // A rolling "event_date < CURDATE()" boundary was used here originally,
    // but that incorrectly started including real, live events once their
    // date passed (first hit: occurrence 265, 2026-09-17). Reusing the
    // launch-date constant instead matches every other place in this
    // codebase that already draws the real-vs-test-event line (Club
    // Rating, history archival, recap emails, the photo prompt, Full
    // Reset), so there's one consistent definition sitewide.
    $test_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT v.occurrence_id, v.eff_title, v.event_date, v.eff_event_time,
                    e.current_round, e.phase
             FROM {$view} v
             LEFT JOIN {$events_table} e ON e.occurrence_id = v.occurrence_id
             WHERE v.eff_category_id IN (2,3) AND v.cancelled = 0 AND v.event_date < %s
             ORDER BY v.event_date DESC, v.eff_event_time DESC
             LIMIT 4",
            SPP_KQ_CLUB_RATING_LAUNCH_DATE
        ),
        ARRAY_A
    );

    ob_start();
    echo spp_kq_styles();
    ?>
    <div class="kq-wrap">
        <h2 class="kq-heading">Ace / Queen of the Courts &mdash; Live</h2>
        <p class="kq-caveat">Actions here affect real, live event data &mdash; please be careful.</p>
        <?php if ( empty( $rows ) ) : ?>
            <p>No upcoming Ace or Queen occurrences found.</p>
        <?php else : ?>
            <div class="kq-picker-list">
                <?php foreach ( $rows as $r ) : ?>
                    <?php echo spp_kq_render_picker_row( $r ); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $test_rows ) ) : ?>
            <h3 class="kq-picker-section-heading">Practice / Test Sandbox</h3>
            <p class="kq-hint">These are past, real events &mdash; safe to experiment on freely, since this tool didn't exist yet when they happened. Any logged-in member can use them. If a real score gets entered while testing, an administrator will need to clean it up (Reset only works before any score exists) before the next person tries.</p>
            <div class="kq-picker-list">
                <?php foreach ( $test_rows as $r ) : ?>
                    <?php echo spp_kq_render_picker_row( $r, true ); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// =============================================================
// Screens 2-7: occurrence-specific, share one header
// =============================================================

function spp_kq_render_occurrence_header( array $occurrence, string $notice = '' ) : string {
    $date_str = date_i18n( 'l, F j', strtotime( $occurrence['event_date'] ) );
    $time_str = $occurrence['eff_event_time'] ? date_i18n( 'g:ia', strtotime( $occurrence['eff_event_time'] ) ) : '';

    // Every other notice returned by spp_kq_handle_post_actions() today is
    // an error string (this dispatcher's longstanding convention: '' on
    // success, plain text on failure) -- rendered kq-notice-err
    // unconditionally, unchanged. SPP_KQ_NOTICE_OK_PREFIX is the one
    // opt-in exception: a case that wants to confirm a real success (so
    // far, only 'full_reset' -- see that case's own comment) prefixes its
    // returned string with this exact marker; stripped here before
    // display, rendered kq-notice-ok instead (already-defined CSS, same
    // class this file's own AJAX score-submission JS already uses for
    // success feedback -- reused for consistency, not invented fresh).
    // No other existing notice text starts with this marker, so every
    // other action's rendering is unchanged, byte for byte.
    $is_success = str_starts_with( $notice, SPP_KQ_NOTICE_OK_PREFIX );
    $display_notice = $is_success ? substr( $notice, strlen( SPP_KQ_NOTICE_OK_PREFIX ) ) : $notice;

    ob_start();
    ?>
    <p class="kq-back"><a href="<?php echo esc_url( remove_query_arg( 'occ' ) ); ?>">&larr; All events</a></p>
    <h2 class="kq-heading"><?php echo esc_html( $occurrence['eff_title'] ); ?></h2>
    <p class="kq-subheading"><?php echo esc_html( $date_str ) . ( $time_str ? ' &middot; ' . esc_html( $time_str ) : '' ); ?></p>
    <?php if ( $notice ) : ?>
        <div class="kq-notice <?php echo $is_success ? 'kq-notice-ok' : 'kq-notice-err'; ?>"><?php echo esc_html( $display_notice ); ?></div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/**
 * Screen 2: Start / Check-in. Every confirmed registrant (gl_registrations),
 * each toggleable checked-in/not -- see inc/spp-kq-checkin.php's own
 * header for the full flow. The "Cannot start" validation below is
 * evaluated against the CHECKED-IN count, not the raw confirmed count
 * (1.1.0) -- at the moment "Start Round 1 Draw" is pressed,
 * spp_kq_handle_post_actions()'s 'start_round1' case withdraws anyone
 * still unchecked (spp_kq_roster_remove(), same call Roster Adjust's own
 * Remove button uses) BEFORE calling spp_kq_transition_start_round1(),
 * so that function's own confirmed-count check (unchanged) already
 * evaluates the post-reconciliation reality.
 *
 * @param string      $event_date The occurrence's real event date
 *   ('Y-m-d'), from spp_kq_get_occurrence_summary() -- passed in by the
 *   caller rather than re-queried here.
 * @param string|null $event_time The occurrence's real eff_event_time
 *   ('H:i:s' or null), same source/caller convention as $event_date.
 */
function spp_kq_render_start_screen( int $occurrence_id, string $event_date, ?string $event_time ) : string {
    $confirmed        = spp_kq_get_confirmed_registrants_named( $occurrence_id );
    $checked_in_ids    = spp_kq_get_checked_in_user_ids( $occurrence_id );
    $confirmed_count   = count( $confirmed );
    $checked_in_count  = count( $checked_in_ids );
    $valid             = ( $checked_in_count >= 4 && $checked_in_count <= 16 && $checked_in_count % 4 === 0 );

    // Start-Round-1 timing gate (1.3.0): same threshold as the
    // screens-access gate (spp_kq_event_screens_open(), checked earlier
    // in spp_kq_live_shortcode() before this screen is even reached) --
    // reuses spp_kq_get_occurrence_start_timestamp() directly rather
    // than a second implementation of the same math. Previously this
    // was an exact current_time('Y-m-d') === $event_date match, which
    // wrongly blocked starting a real PAST occurrence (a sandbox event,
    // or any event whose day has already passed) even though it's
    // obviously long past its start time and should be freely
    // startable -- confirmed as a real incident, not a hypothetical:
    // a facilitator checked in a full sandbox roster and was blocked
    // here by the stale exact-date rule. "now >= start - 30 minutes"
    // already produces the right answer for all three real cases
    // (within 30 minutes of a future start, later today past that
    // threshold, or any time after a past event's start) with no
    // separate "is this a sandbox event" special-casing needed --
    // current_time() is always past a past event's own threshold.
    // Administrators exempt, unchanged (spp_is_admin()).
    $start_ts        = spp_kq_get_occurrence_start_timestamp( $event_date, $event_time );
    $can_start_now   = spp_is_admin() || ( $start_ts !== null && current_time( 'timestamp' ) >= ( $start_ts - 30 * MINUTE_IN_SECONDS ) );

    ob_start();
    ?>
    <p class="kq-meta">
        <?php echo esc_html( $checked_in_count ); ?> of <?php echo esc_html( $confirmed_count ); ?> confirmed registrant<?php echo $confirmed_count === 1 ? '' : 's'; ?> checked in
    </p>
    <p class="kq-hint">Mark each player checked in as they arrive. Anyone still not checked in when you start Round 1 is removed from the registrant list.</p>
    <p class="kq-hint"><a href="<?php echo esc_url( add_query_arg( 'kq_view', 'roster' ) ); ?>">Roster Adjust</a> &mdash; add a late arrival or remove someone, any time before starting.</p>

    <?php if ( empty( $confirmed ) ) : ?>
        <p class="kq-hint">No confirmed registrants yet.</p>
    <?php else : ?>
        <ul class="kq-checkin-list">
            <?php foreach ( $confirmed as $p ) :
                $is_in = in_array( $p['user_id'], $checked_in_ids, true );
            ?>
                <li>
                    <form method="post" class="kq-inline-form" style="width:100%;">
                        <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                        <input type="hidden" name="spp_kq_action" value="<?php echo $is_in ? 'checkin_unmark' : 'checkin_mark'; ?>">
                        <input type="hidden" name="spp_kq_checkin_user_id" value="<?php echo esc_attr( $p['user_id'] ); ?>">
                        <button type="submit" class="kq-btn <?php echo $is_in ? 'kq-btn-primary' : 'kq-btn-secondary'; ?>">
                            <?php echo esc_html( $p['name'] ); ?><?php echo $is_in ? ' -- Checked in ✓' : ' -- Check in'; ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ( ! $valid ) : ?>
        <p class="kq-warn">
            Cannot start: <?php echo esc_html( $checked_in_count ); ?> checked in &mdash; need a multiple of 4,
            between 4 and 16. Adjust the roster via
            <a href="<?php echo esc_url( add_query_arg( 'kq_view', 'roster' ) ); ?>">Roster Adjust</a> first.
        </p>
    <?php elseif ( ! $can_start_now ) : ?>
        <?php
        // Same wording/tone as spp_kq_render_too_early_notice() (the
        // screens-access gate) -- one consistent message style across
        // both gates rather than two different ones. $start_ts is never
        // null here: a null start_ts means spp_is_admin() is the only
        // way $can_start_now could be true, and an admin never reaches
        // this branch in the first place.
        $opens_at = $start_ts - 30 * MINUTE_IN_SECONDS;
        $when     = date_i18n( 'g:ia', $opens_at ) . ' on ' . date_i18n( 'l, F j', $opens_at );
        ?>
        <p class="kq-warn">
            This event doesn't open until <?php echo esc_html( $when ); ?>.
        </p>
    <?php else : ?>
        <form method="post">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="start_round1">
            <button type="submit" class="kq-btn kq-btn-primary">Start Round 1 Draw</button>
        </form>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/** Screen 3: Draw screen (organizing, round 1, unclaimed slots remain) */
function spp_kq_render_draw_screen( int $occurrence_id ) : string {
    $courts_order = spp_kq_determine_courts_order( $occurrence_id );
    $not_drawn    = spp_kq_get_not_yet_drawn( $occurrence_id );
    $reveal       = spp_kq_get_draw_reveal_state( $occurrence_id, $courts_order );
    $unclaimed    = spp_kq_count_unclaimed( $occurrence_id, 1 );
    $total_slots  = count( $courts_order ) * 4;
    $drawn_so_far = $total_slots - $unclaimed;

    ob_start();
    ?>
    <p class="kq-round-label-tight">Round 1 &mdash; Draw in progress</p>
    <p class="kq-hint">Tap a player's name, then tap any face-down card to reveal their court.</p>
    <p class="kq-draw-progress" id="kq-draw-progress">
        <span id="kq-draw-count"><?php echo esc_html( "{$drawn_so_far} of {$total_slots} drawn" ); ?></span>
    </p>
    <div class="kq-notice kq-notice-err" id="kq-draw-error" style="display:none;"></div>

    <div class="kq-draw-columns">
        <div class="kq-draw-col">
            <h3>Not yet drawn</h3>
            <ul id="kq-not-drawn-list">
                <?php foreach ( $not_drawn as $p ) : ?>
                    <li data-uid="<?php echo esc_attr( $p['user_id'] ); ?>">
                        <button type="button" class="kq-player-btn" data-uid="<?php echo esc_attr( $p['user_id'] ); ?>"><?php echo esc_html( $p['name'] ); ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="kq-draw-col">
            <h3>Tap a card</h3>
            <div id="kq-card-grid">
                <?php for ( $i = 0; $i < $unclaimed; $i++ ) : ?>
                    <div class="kq-card">&#127165;</div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <div class="kq-draw-revealed">
        <h3>Drawn so far</h3>
        <div class="kq-court-grid" id="kq-revealed-courts">
            <?php foreach ( $courts_order as $court ) : ?>
                <div class="kq-court-card">
                    <div class="kq-court-name"><?php echo esc_html( $court ); ?></div>
                    <div class="kq-team kq-team-red">Red:
                        <?php foreach ( $reveal[ $court ]['red'] as $i => $name ) : ?>
                            <span class="kq-slot<?php echo $name ? ' kq-slot-filled' : ''; ?>" data-court="<?php echo esc_attr( $court ); ?>" data-color="red"><?php echo $name ? esc_html( $name ) : '&mdash;'; ?></span><?php echo $i === 0 ? ',' : ''; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="kq-team kq-team-black">Black:
                        <?php foreach ( $reveal[ $court ]['black'] as $i => $name ) : ?>
                            <span class="kq-slot<?php echo $name ? ' kq-slot-filled' : ''; ?>" data-court="<?php echo esc_attr( $court ); ?>" data-color="black"><?php echo $name ? esc_html( $name ) : '&mdash;'; ?></span><?php echo $i === 0 ? ',' : ''; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kq-action-row kq-action-row-right">
        <?php if ( $unclaimed > 0 ) : ?>
        <form method="post" class="kq-inline-form" onsubmit="return confirm('Randomly assign the remaining <?php echo (int) $unclaimed; ?> open slot(s) to whoever hasn\'t drawn yet? Already-drawn cards are untouched.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="complete_draw_randomly">
            <input type="hidden" name="spp_kq_round" value="1">
            <button type="submit" class="kq-btn kq-btn-secondary">Complete Allocation Randomly</button>
        </form>
        <?php endif; ?>
        <form method="post" class="kq-inline-form" onsubmit="return confirm('Clear this draw and start over? Any cards already drawn will be discarded -- players will need to draw again from scratch.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="reset_event">
            <input type="hidden" name="spp_kq_round" value="1">
            <button type="submit" class="kq-btn kq-btn-secondary">Reset</button>
        </form>
    </div>

    <script>
    (function() {
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'spp_kq_live_action' ) ); ?>;
        var occ     = <?php echo (int) $occurrence_id; ?>;
        var selectedUid = null;
        var errorBox = document.getElementById( 'kq-draw-error' );

        function showError( msg ) {
            errorBox.textContent = msg;
            errorBox.style.display = 'block';
            setTimeout( function() { errorBox.style.display = 'none'; }, 4000 );
        }

        function selectPlayer( btn ) {
            document.querySelectorAll( '.kq-player-btn' ).forEach( function( b ) { b.classList.remove( 'kq-selected' ); } );
            btn.classList.add( 'kq-selected' );
            selectedUid = parseInt( btn.dataset.uid, 10 );
            // On narrow (iPhone-width) viewports the name list wraps above
            // the card grid/revealed-courts sections, so tapping a name up
            // top gives no visible sign anything happened unless the
            // visitor already knows to scroll down manually -- surface the
            // next step (the card grid, plus whatever's already revealed)
            // automatically instead of requiring that.
            var cardGrid = document.getElementById( 'kq-card-grid' );
            if ( cardGrid ) cardGrid.scrollIntoView( { behavior: 'smooth', block: 'center' } );
        }

        document.getElementById( 'kq-not-drawn-list' ).addEventListener( 'click', function( e ) {
            var btn = e.target.closest( '.kq-player-btn' );
            if ( btn ) selectPlayer( btn );
        } );

        document.getElementById( 'kq-card-grid' ).addEventListener( 'click', function( e ) {
            var card = e.target.closest( '.kq-card' );
            if ( ! card ) return;
            if ( ! selectedUid ) {
                showError( 'Select a player first, then tap a card.' );
                return;
            }

            var data = new FormData();
            data.append( 'action', 'spp_kq_draw_card' );
            data.append( 'nonce', nonce );
            data.append( 'occ', occ );
            data.append( 'user_id', selectedUid );

            fetch( ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
                .then( function( r ) { return r.json(); } )
                .then( function( res ) {
                    if ( ! res.success ) {
                        showError( res.data || 'Draw failed.' );
                        return;
                    }
                    var d = res.data;

                    // Remove the drawn player from the list.
                    var li = document.querySelector( '#kq-not-drawn-list li[data-uid="' + selectedUid + '"]' );
                    var playerName = li ? li.querySelector( '.kq-player-btn' ).textContent : '';
                    if ( li ) li.remove();
                    selectedUid = null;

                    // Remove one card from the grid.
                    card.remove();

                    // Fill the first still-empty slot for this court/color.
                    var slots = document.querySelectorAll( '.kq-slot[data-court="' + d.court_name + '"][data-color="' + d.team_color + '"]' );
                    for ( var i = 0; i < slots.length; i++ ) {
                        if ( ! slots[ i ].classList.contains( 'kq-slot-filled' ) ) {
                            slots[ i ].textContent = playerName;
                            slots[ i ].classList.add( 'kq-slot-filled' );
                            break;
                        }
                    }

                    // Update the progress counter.
                    var remaining = document.querySelectorAll( '#kq-card-grid .kq-card' ).length;
                    var total = remaining + document.querySelectorAll( '.kq-slot-filled' ).length;
                    document.getElementById( 'kq-draw-count' ).textContent = ( total - remaining ) + ' of ' + total + ' drawn';

                    if ( d.draw_complete ) {
                        window.location.reload();
                    } else {
                        // Real feedback, mirrors selectPlayer()'s own
                        // scroll-to-cards fix from yesterday but in the
                        // OTHER direction: with enough cards on screen, a
                        // successful claim can leave the "Not yet drawn"
                        // list scrolled out of view above the fold, so
                        // the next player has no easy way to find their
                        // own name to tap next. Skipped entirely when the
                        // draw just completed (reload above makes it
                        // moot) or the list is now empty (nothing left to
                        // scroll to).
                        var notDrawnList = document.getElementById( 'kq-not-drawn-list' );
                        if ( notDrawnList && notDrawnList.children.length > 0 ) {
                            notDrawnList.scrollIntoView( { behavior: 'smooth', block: 'center' } );
                        }
                    }
                } )
                .catch( function() { showError( 'Network error -- try again.' ); } );
        } );
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Shared "tap to enable sound" banner + speechSynthesis wrapper
 * (1.13.0), used by every screen that needs independent per-device
 * voice announcements -- currently spp_kq_render_overview_screen()'s
 * pre-Start-Play announce/rest state and spp_kq_render_in_play_screen()'s
 * match timer. Previously this whole apparatus (unlock button wiring,
 * the resume()-before-speak()/keep-alive fix for Chromium's
 * speechSynthesis idle bug, onstart/onerror diagnostic logging) lived
 * inline inside spp_kq_render_in_play_screen() only; factored out here
 * once a SECOND screen needed the identical thing rather than copy-
 * pasting it -- see this file's own 1.13.0 changelog. Exposes a global
 * `SppKqAnnouncer` object with `.speak(text)` and `.stopKeepAlive()`;
 * the caller's own script (loaded after this one on the same page,
 * same non-deferred inline-script convention every screen here already
 * uses) calls into it directly.
 *
 * Emits its own <div>/<button> banner markup AND the <script> that
 * wires it, together -- callers just echo this once, anywhere before
 * their own script needs SppKqAnnouncer, and don't reimplement any of
 * it themselves. Every screen that calls this owns exactly one
 * #kq-audio-unlock on the page at a time (screens are mutually
 * exclusive per page load), so the fixed element ids are never at risk
 * of colliding.
 */
function spp_kq_render_speech_announcer() : string {
    ob_start();
    ?>
    <div class="kq-audio-unlock" id="kq-audio-unlock">
        <button type="button" class="kq-btn kq-btn-secondary" id="kq-audio-unlock-btn">&#128266; Tap to enable sound announcements</button>
    </div>
    <script>
    var SppKqAnnouncer = (function() {
        var unlockWrap = document.getElementById('kq-audio-unlock');
        var unlockBtn  = document.getElementById('kq-audio-unlock-btn');

        var audioUnlocked = false;
        var keepAliveInterval = null;

        // Fixes a live-tested bug where a sparsely-spaced announcement
        // (e.g. the match timer's 2-minute warning) produced no audio
        // even though earlier/later announcements worked fine: this is
        // Chromium's own long-documented speechSynthesis idle bug --
        // the engine's internal queue can silently pause/stall after
        // roughly 15 seconds of not actively speaking, dropping the
        // next speak() call with no error and no visible symptom.
        // resume() immediately before every speak() unsticks an
        // already-stalled queue for that one utterance; the periodic
        // keep-alive (started once audio is unlocked) nudges the
        // engine well inside that 15-second window so it never gets
        // the chance to stall at all. onstart/onerror plus a
        // console.debug at the trigger-fired moment are diagnostic
        // logging -- previously a dropped utterance was completely
        // silent; a recurrence is now visible in the browser console.
        function speak(text) {
            console.debug('[KQ announce] trigger fired:', text, '(audioUnlocked=' + audioUnlocked + ')');
            if (!audioUnlocked || !('speechSynthesis' in window)) return;
            try {
                window.speechSynthesis.resume();
                var utter = new SpeechSynthesisUtterance(text);
                utter.onstart = function() { console.debug('[KQ announce] speech started:', text); };
                utter.onerror = function(e) { console.warn('[KQ announce] speech FAILED:', text, e && e.error); };
                window.speechSynthesis.speak(utter);
            } catch (e) {
                console.warn('[KQ announce] speak() threw:', e);
            }
        }

        function startKeepAlive() {
            if (!('speechSynthesis' in window) || keepAliveInterval) return;
            keepAliveInterval = setInterval(function() {
                try { window.speechSynthesis.pause(); window.speechSynthesis.resume(); } catch (e) {}
            }, 10000);
        }
        function stopKeepAlive() {
            if (keepAliveInterval) { clearInterval(keepAliveInterval); keepAliveInterval = null; }
        }

        if ('speechSynthesis' in window && unlockWrap && unlockBtn) {
            unlockBtn.addEventListener('click', function() {
                audioUnlocked = true;
                unlockWrap.style.display = 'none';
                startKeepAlive();
                try {
                    window.speechSynthesis.resume();
                    window.speechSynthesis.speak(new SpeechSynthesisUtterance('Sound enabled.'));
                } catch (e) {}
            });
        } else if (unlockWrap) {
            // No SpeechSynthesis in this browser -- nothing to unlock,
            // so don't show a prompt with no effect. Every caller's own
            // visual UI is completely unaffected either way.
            unlockWrap.style.display = 'none';
        }

        return { speak: speak, stopKeepAlive: stopKeepAlive };
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Screen 4: Overview screen (organizing, draw complete or round > 1)
 *
 * End Event is only offered once at least one real score exists
 * somewhere for this occurrence -- "declare a winner" makes no sense
 * before a single game has been played (round 1's overview, right
 * after the draw). When zero scores exist yet, this screen offers only
 * Start Play -- deliberately NOT a Reset button here either: a
 * completed draw with Start Play not yet tapped has nothing to undo
 * (Reset Event only ever appears on the Draw screen, for a genuinely
 * incomplete draw, or the In-Play screen, to undo Start Play itself --
 * see spp_kq_transition_reset_event()'s own docblock).
 */
function spp_kq_render_overview_screen( int $occurrence_id, int $round, ?int $courts_announced_at = null ) : string {
    $courts_data   = spp_kq_get_round_court_view( $occurrence_id, $round );
    $scores_exist  = spp_kq_has_any_recorded_score( $occurrence_id );
    // 1.1.0: cancelled courts get a badge instead of team lists;
    // understaffed courts (a court re-created empty after a
    // cancellation, not yet re-staffed -- see
    // spp_kq_transition_advance_round()'s own docblock,
    // inc/spp-kq-live.php) block Start Play until fixed via Roster
    // Adjust's live swap screen.
    $cancelled     = spp_kq_get_cancelled_courts( $occurrence_id, $round );
    $understaffed  = spp_kq_get_understaffed_courts( $occurrence_id, $round );

    ob_start();
    ?>
    <p class="kq-round-label">Round <?php echo esc_html( $round ); ?> &mdash; Ready to play</p>
    <div class="kq-court-grid">
        <?php foreach ( $courts_data as $court => $teams ) : ?>
            <div class="kq-court-card">
                <div class="kq-court-name"><?php echo esc_html( $court ); ?></div>
                <?php if ( in_array( $court, $cancelled, true ) ) : ?>
                    <p class="kq-hint">Cancelled for this round.</p>
                <?php elseif ( in_array( $court, $understaffed, true ) ) : ?>
                    <p class="kq-hint">Needs players &mdash; <a href="<?php echo esc_url( add_query_arg( 'kq_view', 'roster' ) ); ?>">Roster Adjust</a>.</p>
                <?php else : ?>
                    <div class="kq-team kq-team-red">Red: <?php echo esc_html( implode( ', ', $teams['red'] ) ); ?></div>
                    <div class="kq-team kq-team-black">Black: <?php echo esc_html( implode( ', ', $teams['black'] ) ); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="kq-hint">Swap a player, or cancel a court's game for this round, via <a href="<?php echo esc_url( add_query_arg( 'kq_view', 'roster' ) ); ?>">Roster Adjust</a>.</p>

    <?php if ( ! empty( $understaffed ) ) : ?>

        <p class="kq-warn">Cannot start play: <?php echo esc_html( implode( ', ', $understaffed ) ); ?> still need players.</p>

    <?php elseif ( $courts_announced_at === null ) : ?>

        <?php
        // 1.13.0: Round 1 only reaches here -- round 2+'s courts_announced_at
        // is never null (spp_kq_transition_advance_round() always stamps
        // it, inc/spp-kq-live.php). Manual: whenever the facilitator
        // judges the room ready, not a fixed duration -- see this file's
        // own 1.13.0 changelog for the full flow.
        ?>
        <p class="kq-hint">Once everyone's checked their court, announce play &mdash; this speaks "Go to your courts" on every phone following along, then opens Start Play.</p>
        <div class="kq-notice kq-notice-err" id="kq-announce-msg" style="display:none;"></div>
        <div class="kq-action-row">
            <form method="post" class="kq-inline-form kq-announce-courts-form">
                <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                <input type="hidden" name="spp_kq_action" value="announce_courts">
                <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
                <button type="submit" class="kq-btn kq-btn-primary">Ready &mdash; Announce Courts</button>
            </form>
        </div>

        <script>
        // 1.14.0: this form now submits via the persistent app's own
        // AJAX action instead of a plain POST -- see
        // spp_kq_render_live_app()'s own script for wireAjaxForm()/
        // submitAction() and this file's 1.14.0 changelog for why
        // (reloading here would wipe SppKqAnnouncer's audioUnlocked/
        // keep-alive state right before the very announcement this
        // button exists to trigger). Detection for OTHER devices
        // sitting on this same screen is now the persistent app's own
        // outer poll (courts_announced_at is part of its structural
        // signature) -- no separate poll loop needed here any more.
        SppKqLiveApp.wireAjaxForm('.kq-announce-courts-form', 'kq-announce-msg');
        </script>

    <?php else : ?>

        <?php
        // 1.13.0: courts_announced_at is set -- either round 1 just
        // after the manual press above, or round 2+'s automatic
        // SPP_KQ_COURTS_REST_SECONDS rest period (inc/spp-kq-live.php).
        // Both converge on the exact same markup/JS from here on: a
        // live countdown to that instant (already in the past for
        // round 1's own reload -- the countdown/reveal below resolves
        // that on its very first tick, no separate branch needed), then
        // "Go to your courts" once, then reveal the SAME Start Play
        // form either flow already shared before this feature existed.
        ?>
        <div class="kq-timer-wrap" id="kq-rest-timer-wrap">
            <div class="kq-timer" id="kq-rest-timer">--:--</div>
            <div class="kq-timer-label" id="kq-rest-timer-label">Next round starts in</div>
            <?php
            // 1.27.0: lets a facilitator end this rest period early when
            // players are ready before the timer naturally elapses --
            // nested INSIDE #kq-rest-timer-wrap deliberately, so tick()'s
            // existing restWrapEl.style.display='none' (fired below)
            // hides this along with the countdown itself the instant
            // "Go to your courts." fires, real or skipped -- no separate
            // show/hide logic needed. Harmless no-op on round 1's own
            // reload (courts_announced_at is already effectively "now"
            // there -- see spp_kq_transition_skip_rest_countdown()'s own
            // docblock, inc/spp-kq-live.php) since the countdown resolves
            // on its own before a human could realistically click this.
            ?>
            <p class="kq-hint">Everyone ready early? Skip the rest of the wait.</p>
            <div class="kq-notice kq-notice-err" id="kq-skip-rest-msg" style="display:none;"></div>
            <form method="post" class="kq-inline-form kq-skip-rest-form">
                <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                <input type="hidden" name="spp_kq_action" value="skip_rest_countdown">
                <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
                <button type="submit" class="kq-btn kq-btn-secondary">Skip Wait &mdash; Go to Courts</button>
            </form>
        </div>

        <div id="kq-start-play-wrap" style="display:none;">
            <?php if ( $scores_exist ) : ?>
                <p class="kq-hint">Start Play opens score entry for every court; End Event closes the day for good &mdash; no more rounds.</p>
            <?php else : ?>
                <p class="kq-hint">Start Play opens score entry for every court.</p>
            <?php endif; ?>
            <div class="kq-notice kq-notice-err" id="kq-start-play-msg" style="display:none;"></div>
            <div class="kq-action-row">
                <form method="post" class="kq-inline-form kq-start-play-form">
                    <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
                    <input type="hidden" name="spp_kq_action" value="start_play">
                    <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
                    <label class="kq-duration-label">Round length (minutes)<br>
                        <input type="number" name="spp_kq_round_minutes" class="kq-duration-input"
                               value="<?php echo esc_attr( SPP_KQ_DEFAULT_ROUND_MINUTES ); ?>"
                               min="<?php echo esc_attr( SPP_KQ_MIN_ROUND_MINUTES ); ?>"
                               max="<?php echo esc_attr( SPP_KQ_MAX_ROUND_MINUTES ); ?>" step="1" inputmode="numeric">
                    </label>
                    <button type="submit" class="kq-btn kq-btn-primary">Start Play</button>
                </form>
            </div>
        </div>

        <script>
        // 1.14.0: same AJAX-submit treatment as the Announce Courts form
        // above -- see that script's own comment. Wired unconditionally
        // here even though the form starts hidden (#kq-start-play-wrap):
        // harmless to wire an unsubmittable hidden form, and it's
        // already visible+submittable by the time a human could
        // possibly reach it (the countdown below reveals it well before
        // any real click could land).
        SppKqLiveApp.wireAjaxForm('.kq-start-play-form', 'kq-start-play-msg');
        // 1.27.0: same AJAX-submit treatment -- see spp_kq_transition_
        // skip_rest_countdown()'s own docblock (inc/spp-kq-live.php) for
        // why a plain POST/error response is never actually shown here:
        // a double-press or a race against the countdown's own natural
        // completion both resolve to a silent no-op, not an error.
        SppKqLiveApp.wireAjaxForm('.kq-skip-rest-form', 'kq-skip-rest-msg');

        (function() {
            var courtsAnnouncedAt = <?php echo (int) $courts_announced_at; ?>;
            var serverNowMs       = <?php echo (int) round( microtime( true ) * 1000 ); ?>;

            var restWrapEl  = document.getElementById('kq-rest-timer-wrap');
            var restTimerEl = document.getElementById('kq-rest-timer');
            var restLabelEl = document.getElementById('kq-rest-timer-label');
            var startPlayWrap = document.getElementById('kq-start-play-wrap');
            if (!restTimerEl) return;

            // Same absolute-anchor/skew-corrected pattern as the match
            // timer itself (inc/spp-kq-screens.php's spp_kq_render_
            // in_play_screen()) -- every device converges on the same
            // real instant regardless of when it loaded this screen.
            // Already-past on load (round 1's own post-press reload) is
            // NOT a special case: the first tick below just computes a
            // remaining of 0 and resolves immediately.
            var localAnnounceMs = courtsAnnouncedAt * 1000 + (Date.now() - serverNowMs);
            var fired = false;
            var interval = null;
            // 1.26.0 BUG 2 fix -- same class of bug, same fix, as spp_kq_
            // render_in_play_screen()'s own 1.26.0 note (see that
            // function's comment for the full root-cause writeup): this
            // script also re-executes from scratch on a passive "Back to
            // game"/native-Back redisplay, with fired starting false
            // either way -- a device returning after the real rest
            // countdown already elapsed would otherwise re-speak "Go to
            // your courts." for an announcement it already heard. Unlike
            // the round timer, this is a single one-shot event (not a
            // series of thresholds), so the fix is simpler: just skip the
            // speak() call itself on a passive redisplay -- the visual
            // reveal (hiding the countdown, showing Start Play) still
            // happens exactly as it should either way, since that's
            // genuinely correct current state, not something being
            // re-announced.
            var isPassiveRedisplay = window.__kqIsTransition === false;

            function formatTime(totalSeconds) {
                var m = Math.floor(totalSeconds / 60);
                var s = totalSeconds % 60;
                return m + ':' + (s < 10 ? '0' : '') + s;
            }

            function tick() {
                var remaining = Math.max(0, Math.round((localAnnounceMs - Date.now()) / 1000));

                if (remaining > 0) {
                    restTimerEl.textContent = formatTime(remaining);
                    return;
                }

                if (!fired) {
                    fired = true;
                    if (interval) clearInterval(interval);
                    if (!isPassiveRedisplay) SppKqAnnouncer.speak('Go to your courts.');
                    if (restWrapEl) restWrapEl.style.display = 'none';
                    if (startPlayWrap) startPlayWrap.style.display = '';
                }
            }

            tick();
            interval = setInterval(tick, 250);

            // 1.15.0: same cleanup-hook convention as the round timer
            // (spp_kq_render_in_play_screen()) -- lets swapFragment()
            // stop THIS interval before replacing this fragment. Marks
            // fired=true too, so a tick() call already mid-flight when
            // cleanup runs can't still fire the announcement/reveal.
            window.SppKqLiveApp.cleanup = function() {
                fired = true;
                if (interval) clearInterval(interval);
            };
        })();
        </script>

    <?php endif; ?>

    <?php if ( $scores_exist ) : ?>
    <div class="kq-action-row kq-action-row-right">
        <form method="post" class="kq-inline-form" onsubmit="return confirm('End the event now? This closes the day — no more rounds.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="end_event">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-secondary">End Event</button>
        </form>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/**
 * Screen 5: In-Play -- every court's score-entry view (Stage 3;
 * broadened 2026-09 -- see spp_kq_submit_court_score()'s own section
 * header in inc/spp-kq-live.php for the access-model change this is
 * part of). No per-user assignment check here anymore: every court in
 * the round is shown, to every logged-in facilitator, each with its
 * own score inputs. The AJAX submit handler below independently
 * validates the same way (real court for this occurrence/round) --
 * the two must never diverge, same discipline as everywhere else in
 * this codebase.
 */
function spp_kq_render_in_play_screen( int $occurrence_id, int $round, ?int $round_started_at = null, ?int $round_duration_seconds = null ) : string {
    $progress     = spp_kq_get_round_progress( $occurrence_id, $round );
    // Reset is only offered once, before anything real has happened this
    // round (by the state machine, only possible in round 1) -- once any
    // court anywhere has reported, the action row renders nothing (1.25.0:
    // Cancel Event removed from the UI) and the round simply runs to
    // completion.
    $scores_exist = spp_kq_has_any_recorded_score( $occurrence_id );

    $court_view = spp_kq_get_round_court_view( $occurrence_id, $round );
    // 1.1.0: a cancelled court gets a badge and no score inputs at all --
    // see spp_kq_get_round_progress()'s own "not counted anywhere" note.
    $cancelled  = spp_kq_get_cancelled_courts( $occurrence_id, $round );

    $scores_by_court = array();
    foreach ( spp_kq_get_round_scores( $occurrence_id, $round ) as $s ) {
        $scores_by_court[ $s['court_name'] ] = $s;
    }

    ob_start();
    ?>
    <p class="kq-round-label">Round <?php echo esc_html( $round ); ?> &mdash; In Play</p>

    <?php if ( $round_started_at && $round_duration_seconds ) : ?>
    <div class="kq-timer-wrap" id="kq-timer-wrap">
        <div class="kq-timer" id="kq-timer">--:--</div>
        <div class="kq-timer-label" id="kq-timer-label">Time remaining</div>
    </div>
    <?php endif; ?>

    <div class="kq-status" id="kq-status">
        <span id="kq-status-progress"><?php echo esc_html( "{$progress['reported']} of {$progress['total']} courts reported" ); ?></span>
    </div>

    <p class="kq-hint">Enter both teams' real scores for any court &mdash; play continues until someone wins by a point, so an equal score is treated as a mistake to fix.</p>
    <p class="kq-hint">A player leaves mid-round or a court can't continue? <a href="<?php echo esc_url( add_query_arg( 'kq_view', 'roster' ) ); ?>">Roster Adjust</a> -- swap in a replacement or cancel that court's game.</p>

    <div class="kq-msg kq-notice" id="kq-score-msg" style="display:none;"></div>

    <?php foreach ( $court_view as $court => $teams ) :
        $current = $scores_by_court[ $court ] ?? array( 'red_score' => null, 'black_score' => null );
        $is_cancelled = in_array( $court, $cancelled, true );
    ?>
        <div class="kq-court-card" data-court="<?php echo esc_attr( $court ); ?>">
            <div class="kq-court-name"><?php echo esc_html( $court ); ?></div>
            <?php if ( $is_cancelled ) : ?>
                <p class="kq-hint">Cancelled for this round &mdash; no score to enter.</p>
            <?php else : ?>
                <div class="kq-team kq-team-red">Red: <?php echo esc_html( implode( ', ', $teams['red'] ) ); ?></div>
                <div class="kq-team kq-team-black">Black: <?php echo esc_html( implode( ', ', $teams['black'] ) ); ?></div>

                <div class="kq-score-row">
                    <label>Red score<br>
                        <input type="number" class="kq-score-input kq-court-red-input" min="0" max="11" inputmode="numeric" pattern="[0-9]*"
                               value="<?php echo esc_attr( $current['red_score'] ?? '' ); ?>">
                    </label>
                    <label>Black score<br>
                        <input type="number" class="kq-score-input kq-court-black-input" min="0" max="11" inputmode="numeric" pattern="[0-9]*"
                               value="<?php echo esc_attr( $current['black_score'] ?? '' ); ?>">
                    </label>
                    <button type="button" class="kq-btn kq-btn-primary kq-save-score-btn">Save Score</button>
                    <span class="kq-saved" style="display:none;">Saved &#10003;</span>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <script>
    (function() {
        var ajaxUrl      = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce        = <?php echo wp_json_encode( wp_create_nonce( 'spp_kq_live_action' ) ); ?>;
        var occ          = <?php echo (int) $occurrence_id; ?>;
        var renderedRound = <?php echo (int) $round; ?>;

        var progressEl = document.getElementById('kq-status-progress');
        var msgEl      = document.getElementById('kq-score-msg');

        function showMsg(text, ok) {
            if (!msgEl) return;
            msgEl.textContent = text;
            msgEl.className = 'kq-msg kq-notice ' + (ok ? 'kq-notice-ok' : 'kq-notice-err');
            msgEl.style.display = 'block';
        }

        // ---------------------------------------------------------------
        // Match timer + voice announcements (1.10.0). See this file's own
        // 1.10.0 changelog for the full investigation/design writeup.
        // roundStartedAt/roundDurationSeconds are null when this round
        // was started before this feature existed (or any other legacy
        // row missing them) -- markRoundComplete() below still gets
        // defined either way (harmless no-op) so the progress-update call
        // sites further down never need to branch on this.
        // ---------------------------------------------------------------
        var roundStartedAt       = <?php echo $round_started_at ? (int) $round_started_at : 'null'; ?>;
        var roundDurationSeconds = <?php echo $round_duration_seconds ? (int) $round_duration_seconds : 'null'; ?>;
        var serverNowMs          = <?php echo (int) round( microtime( true ) * 1000 ); ?>;

        var markRoundComplete = function() {}; // overwritten below if a timer is actually running

        if ( roundStartedAt !== null && roundDurationSeconds !== null ) {
            (function() {
                var timerEl     = document.getElementById('kq-timer');
                var timerWrapEl = document.getElementById('kq-timer-wrap');
                var timerLabelEl = document.getElementById('kq-timer-label');
                if (!timerEl) return;

                // 1.13.0: unlock banner + speak()/keep-alive fix for
                // Chromium's speechSynthesis idle bug all now live in
                // the shared spp_kq_render_speech_announcer() helper --
                // see that function's own docblock for the full writeup
                // this used to carry inline here. 1.14.0: rendered ONCE
                // by the persistent app shell (spp_kq_render_live_app())
                // rather than by this screen itself, so SppKqAnnouncer
                // (and its audioUnlocked/keep-alive state) survives every
                // fragment swap for the rest of the event -- this screen
                // just references the already-existing global.
                var speak = SppKqAnnouncer.speak;

                // 1.26.0 BUG 2 fix (see swapFragment()'s own comment for
                // the full root-cause writeup, confirmed live): this
                // script re-executes from scratch on EVERY swap into this
                // screen, including a passive "Back to game"/native-Back
                // redisplay of an ALREADY-in-progress round -- fired
                // below starts empty either way, so without this guard,
                // tick()'s very first synchronous call would retroactively
                // re-speak every threshold already crossed (live-tested:
                // "Start play now, 1 minute remaining" fired together on
                // a return that had nothing to do with a real round
                // start). isPassiveRedisplay silences JUST that first
                // tick's speak() calls -- fired[] still gets marked
                // exactly as it would otherwise (so the visible timer/
                // labels are 100% correct immediately), speak is restored
                // to the real function before the interval starts, so any
                // threshold genuinely crossed from this point forward
                // still announces completely normally.
                var isPassiveRedisplay = window.__kqIsTransition === false;
                if (isPassiveRedisplay) speak = function() {};

                // Anchor every client to the SAME absolute end instant
                // (a true epoch), corrected once for THIS client's own
                // clock skew via the server-now/client-now delta at
                // render time -- not trusted against Date.now() raw.
                // This is what makes "same start instant for every
                // court" hold even though phones actually load this
                // screen at slightly different real moments.
                //
                // localStartMs is the SAME kind of absolute anchor, just
                // for round_started_at itself rather than its +duration
                // end -- round_started_at is stamped SPP_KQ_ROUND_START_
                // DELAY_SECONDS after the Start Play press (10 seconds,
                // inc/spp-kq-live.php 1.5.0), so a client can find itself
                // rendering BEFORE this instant (that short delay
                // window) as well as after it.
                var endEpochMs   = (roundStartedAt + roundDurationSeconds) * 1000;
                var startEpochMs = roundStartedAt * 1000;
                var skewMs       = Date.now() - serverNowMs;
                var localEndMs   = endEpochMs + skewMs;
                var localStartMs = startEpochMs + skewMs;

                var roundDone      = false;
                var fired          = {};
                var timerInterval  = null;

                // Trigger seconds-remaining thresholds, in the order the
                // spec calls for. 120/60 are each skipped entirely when
                // the round's own total duration never actually reached
                // that mark (e.g. a 1-minute round never had "2 minutes
                // remaining" to announce) -- everything from 10 down to 0
                // always applies since every round is at least 60 seconds
                // (SPP_KQ_MIN_ROUND_MINUTES).
                var triggers = [120, 60, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 0].filter(function(t) {
                    return roundDurationSeconds > t;
                });

                function announceIfDue(remainingSeconds) {
                    triggers.forEach(function(t) {
                        if (fired[t]) return;
                        if (remainingSeconds <= t) {
                            fired[t] = true;
                            if (t === 120) speak('Two minutes remaining.');
                            else if (t === 60) speak('One minute remaining.');
                            else speak(String(t));
                        }
                    });
                    if (remainingSeconds <= 0 && !fired.final) {
                        fired.final = true;
                        speak('Finish rally and stop play.');
                    }
                }

                function formatTime(totalSeconds) {
                    var m = Math.floor(totalSeconds / 60);
                    var s = totalSeconds % 60;
                    return m + ':' + (s < 10 ? '0' : '') + s;
                }

                // 1.25.0 TIMING REVISION (reverts 1.13.0's own "fires
                // immediately" change, real-incident feedback -- hearing
                // "Start play now." right as the button is pressed, 10
                // full silent seconds before the round's real timer
                // actually starts, read as premature/wrong live): "Start
                // play now." now fires the instant now >= localStartMs --
                // the SAME tick that falls through from the "Starting in
                // mm:ss" countdown into real round-timer rendering, i.e.
                // exactly when round_started_at is reached and the match
                // timer takes over, not 10 seconds before it. Only the
                // announcement's timing moved; round_started_at itself
                // (stamped SPP_KQ_ROUND_START_DELAY_SECONDS = 10 seconds
                // after the Start Play press, inc/spp-kq-live.php) and
                // the "Starting in mm:ss" VISUAL countdown during that
                // window are both unchanged -- still no spoken countdown
                // in between, visual only, exactly as already built. A
                // device that loads this screen after localStartMs has
                // already passed (mid-round join/refresh) still finds
                // fired.start false on its own first tick and announces
                // right away -- same "fire once on first tick if not yet
                // fired, even if that tick happens to be a little late"
                // precedent every other trigger in this feature already
                // follows (see announceIfDue() above), not a new special
                // case, just moved to fire on the OTHER side of the
                // now < localStartMs branch below.
                function tick() {
                    if (roundDone) return;
                    var now = Date.now();

                    if (now < localStartMs) {
                        var untilStart = Math.max(0, Math.round((localStartMs - now) / 1000));
                        timerEl.textContent = formatTime(untilStart);
                        if (timerLabelEl) timerLabelEl.textContent = 'Starting in';
                        return;
                    }

                    if (!fired.start) {
                        fired.start = true;
                        speak('Start play now.');
                    }
                    if (timerLabelEl) timerLabelEl.textContent = 'Time remaining';

                    var remainingSeconds = Math.max(0, Math.round((localEndMs - now) / 1000));

                    timerEl.textContent = formatTime(remainingSeconds);
                    if (timerWrapEl) {
                        timerWrapEl.classList.toggle('kq-timer-warn', remainingSeconds <= 60 && remainingSeconds > 10);
                        timerWrapEl.classList.toggle('kq-timer-critical', remainingSeconds <= 10);
                    }

                    announceIfDue(remainingSeconds);
                }

                tick();
                // 1.26.0 BUG 2 fix: restore the real speak() now -- only
                // that one just-completed synchronous tick() call was
                // ever meant to be silenced (see isPassiveRedisplay's own
                // comment above). Anything crossed from the very next
                // tick onward, 250ms later, announces completely
                // normally either way -- a still-mid-countdown return
                // (now < localStartMs on this first tick) hasn't marked
                // fired.start at all yet, so "Start play now." still
                // fires for real, right on time, when that countdown
                // itself naturally reaches zero.
                if (isPassiveRedisplay) speak = SppKqAnnouncer.speak;
                timerInterval = setInterval(tick, 250);

                // Exposed to the existing progress-update code (score
                // save + poll, below) so the timer can freeze/grey once
                // every court has reported -- the timer itself never
                // reacts to an individual court submission on its own
                // (per spec: keeps running through early submissions).
                markRoundComplete = function() {
                    if (roundDone) return;
                    roundDone = true;
                    if (timerInterval) clearInterval(timerInterval);
                    SppKqAnnouncer.stopKeepAlive();
                    if (timerWrapEl) {
                        timerWrapEl.classList.add('kq-timer-done');
                        timerWrapEl.classList.remove('kq-timer-warn', 'kq-timer-critical');
                    }
                    if (timerLabelEl) timerLabelEl.textContent = 'All courts reported';
                    var unlockWrap = document.getElementById('kq-audio-unlock');
                    if (unlockWrap) unlockWrap.style.display = 'none';
                };

                // 1.15.0: lets the persistent app's own swapFragment()
                // (inc/spp-kq-screens.php's spp_kq_render_live_app())
                // stop THIS timer before replacing this fragment with a
                // new one -- reuses markRoundComplete() directly since
                // "stop counting, stop speaking" is exactly what both
                // situations need; the cosmetic DOM/class updates it
                // also does are harmless on a fragment about to be
                // discarded anyway.
                window.SppKqLiveApp.cleanup = markRoundComplete;
            })();
        }

        // One wiring pass per court card -- each card owns its own pair
        // of inputs, save button, and saved-tag, so submitting one
        // court's score never touches another's inputs on the page.
        document.querySelectorAll('.kq-court-card').forEach(function(card) {
            var court      = card.dataset.court;
            var redInput   = card.querySelector('.kq-court-red-input');
            var blackInput = card.querySelector('.kq-court-black-input');
            var saveBtn    = card.querySelector('.kq-save-score-btn');
            var savedTag   = card.querySelector('.kq-saved');

            // 1.1.0: a cancelled court's card has no score inputs at all
            // (see spp_kq_render_in_play_screen()'s own PHP above) --
            // nothing to wire up for it.
            if (!redInput || !blackInput || !saveBtn) return;

            function updateSaveState() {
                if (!saveBtn) return;
                var r = redInput.value, b = blackInput.value;
                if (r === '' || b === '') { saveBtn.disabled = true; return; }
                var rn = parseInt(r, 10), bn = parseInt(b, 10);
                // Same rules, same order, as spp_kq_submit_court_score()'s
                // server-side checks -- this is immediate feedback only,
                // the server re-validates independently regardless.
                if (rn === 11 && bn === 11) {
                    saveBtn.disabled = true;
                    showMsg("11-11 isn't possible -- the game ends the instant either team reaches 11. Please double-check.", false);
                } else if (rn === bn) {
                    saveBtn.disabled = true;
                    showMsg("Scores can't be tied -- games are extended by a point specifically to avoid this.", false);
                } else {
                    saveBtn.disabled = false;
                    if (msgEl) msgEl.style.display = 'none';
                }
            }

            redInput.addEventListener('input', updateSaveState);
            blackInput.addEventListener('input', updateSaveState);
            updateSaveState();

            saveBtn.addEventListener('click', function() {
                saveBtn.disabled = true;
                savedTag.style.display = 'none';

                var data = new FormData();
                data.append('action', 'spp_kq_submit_score');
                data.append('nonce', nonce);
                data.append('occ', occ);
                data.append('round', renderedRound);
                data.append('court_name', court);
                data.append('red_score', redInput.value);
                data.append('black_score', blackInput.value);
                // Submission-order marker (1.2.0) -- see
                // spp_kq_submit_court_score()'s own docblock
                // (inc/spp-kq-live.php) for why this closes the race a
                // slow correction vs. a fast round-advance could hit.
                data.append('client_ts', Date.now());

                fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        updateSaveState();
                        if (!res.success) {
                            showMsg(res.data || 'Save failed.', false);
                            return;
                        }
                        if (res.data.applied === false) {
                            // A newer submission for this court already
                            // landed while this one was in flight -- don't
                            // let this response paint over it. Reconcile
                            // the inputs to the actual current values instead.
                            if (res.data.red_score !== null) redInput.value = res.data.red_score;
                            if (res.data.black_score !== null) blackInput.value = res.data.black_score;
                            showMsg('A newer entry for this court was already saved -- showing the current value.', true);
                        } else {
                            savedTag.style.display = 'inline';
                        }
                        if (progressEl) progressEl.textContent = res.data.reported + ' of ' + res.data.total + ' courts reported';
                        if (res.data.total > 0 && res.data.reported === res.data.total) markRoundComplete();
                    })
                    .catch(function() {
                        saveBtn.disabled = false;
                        showMsg('Network error -- try again.', false);
                    });
            });
        });

        // 1.14.0: progress updates from OTHER devices' submissions (and
        // the redirect_url/phase-round-change detection that used to
        // live in this screen's own poll()) are now entirely the
        // persistent app shell's job (spp_kq_render_live_app()) -- its
        // outer poll is the ONLY poll loop running once this feature's
        // screens joined the persistent page, and it calls this exact
        // callback on every tick where the structural state (phase/
        // round/courts_announced_at/round_started_at/duration) hasn't
        // changed enough to warrant a full fragment swap. A structural
        // change (round actually advancing) swaps this whole fragment
        // out instead, which is what used to be this poll's own reload.
        // Part B (1.20.0): 'scores' -- every court that HAS fully
        // reported this round, from wp_ajax_spp_kq_poll_status's own
        // extended payload (inc/spp-kq-screens.php) -- paints each
        // reported court's REAL values on THIS device, even though this
        // device never submitted them (the earlier version only ever
        // updated the aggregate "N of M reported" line here; a court's
        // own score cells never appeared/refreshed until this device's
        // OWN save-button click resolved, or a full fragment swap
        // happened to occur for an unrelated reason -- root cause: the
        // poll payload simply never carried per-court values at all).
        window.SppKqLiveApp.onProgress = function(reported, total, scores) {
            if (progressEl) progressEl.textContent = reported + ' of ' + total + ' courts reported';
            if (scores) {
                scores.forEach(function(s) {
                    document.querySelectorAll('.kq-court-card').forEach(function(card) {
                        if (card.dataset.court !== s.court_name) return;
                        var redInput   = card.querySelector('.kq-court-red-input');
                        var blackInput = card.querySelector('.kq-court-black-input');
                        var savedTag   = card.querySelector('.kq-saved');
                        // Don't clobber an input the visitor is actively
                        // typing into right now (e.g. correcting an
                        // already-reported score) -- server authority
                        // still wins the moment they save, same as the
                        // save handler's own "applied === false"
                        // reconciliation just above.
                        if (redInput && document.activeElement !== redInput) redInput.value = s.red_score;
                        if (blackInput && document.activeElement !== blackInput) blackInput.value = s.black_score;
                        if (savedTag) savedTag.style.display = 'inline';
                    });
                });
            }
            if (total > 0 && reported === total) markRoundComplete();
        };
    })();
    </script>

    <?php if ( ! $scores_exist ) : ?>
    <div class="kq-action-row kq-action-row-right">
        <form method="post" class="kq-inline-form" onsubmit="return confirm('Undo Start Play? This keeps the same drawn courts and returns everyone to the Ready to play screen -- nothing is lost, since no scores have been entered yet.');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="reset_event">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-secondary">Reset</button>
        </form>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/**
 * Screen 6: Complete screen. 1.16.0: embeds the Submit Photo prompt
 * (spp_kq_render_photo_prompt()) directly, replacing the earlier
 * same-day redirect -- see that function's own docblock. Reached both
 * directly (whichever device pressed End Event) and via the
 * persistent app's own fragment swap (spp_kq_render_live_fragment())
 * for every other device still watching -- both callers get the
 * identical screen from this one function, nothing duplicated between
 * them. "View Full Scoreboard" is rendered HERE, after the prompt, per
 * spec's own requested order -- NOT by spp_kq_live_shortcode()'s usual
 * top-of-page render, which is deliberately skipped for phase
 * complete/cancelled specifically (see that function's own comment).
 */
function spp_kq_render_complete_screen( int $occurrence_id, int $round ) : string {
    $winner = spp_kq_get_final_winner_names( $occurrence_id, $round );
    ob_start();
    ?>
    <p class="kq-round-label">Event complete.</p>
    <?php if ( $winner ) : ?>
        <p class="kq-meta">Final round winners (Aces): <strong><?php echo esc_html( $winner ); ?></strong></p>
    <?php endif; ?>
    <?php echo spp_kq_render_photo_prompt( $occurrence_id ); ?>
    <?php echo spp_kq_render_scoreboard_link( $occurrence_id, false ); ?>
    <?php
    return ob_get_clean();
}

/** Screen 7: Cancelled screen -- kept vs. discarded courts */
function spp_kq_render_cancelled_screen( int $occurrence_id, int $round ) : string {
    $summary = spp_kq_get_cancellation_summary( $occurrence_id, $round );
    ob_start();
    ?>
    <p class="kq-round-label">Event cancelled &mdash; Round <?php echo esc_html( $round ); ?></p>
    <div class="kq-cancel-summary">
        <?php if ( ! empty( $summary['kept'] ) ) : ?>
            <div class="kq-kept">
                <h3>Kept &mdash; already reported, counts toward Club Ratings</h3>
                <ul>
                    <?php foreach ( $summary['kept'] as $court => $sc ) : ?>
                        <li><?php echo esc_html( $court ); ?>: Red <?php echo esc_html( $sc['red'] ); ?> &mdash; Black <?php echo esc_html( $sc['black'] ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ( ! empty( $summary['discarded'] ) ) : ?>
            <div class="kq-discarded">
                <h3>Discarded &mdash; hadn't reported, not recorded</h3>
                <ul>
                    <?php foreach ( $summary['discarded'] as $court ) : ?>
                        <li><?php echo esc_html( $court ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ( empty( $summary['kept'] ) && empty( $summary['discarded'] ) ) : ?>
            <p>No round data to report.</p>
        <?php endif; ?>
    </div>
    <?php echo spp_kq_render_photo_prompt( $occurrence_id ); ?>
    <?php echo spp_kq_render_scoreboard_link( $occurrence_id, false ); ?>
    <?php
    return ob_get_clean();
}

// =============================================================
// POST action dispatch (Start Round 1 / Start Play / End Event /
// Cancel Event) -- no redirect, just returns a notice (or '') and lets
// the main shortcode re-render whatever screen the new state calls for.
// =============================================================

/**
 * @param string      $event_date The occurrence's real event date
 *   ('Y-m-d'), passed by the caller -- same value
 *   spp_kq_render_start_screen() uses, so the button's own timing gate
 *   and this server-side check can never diverge.
 * @param string|null $event_time The occurrence's real eff_event_time
 *   ('H:i:s' or null), same source/caller convention as $event_date --
 *   used by the 'start_round1' case's own timing re-check.
 */
function spp_kq_handle_post_actions( int $occurrence_id, string $event_date, ?string $event_time = null ) : string {
    if ( ! isset( $_POST['spp_kq_action'], $_POST['spp_kq_nonce'] ) ) {
        return '';
    }
    if ( ! wp_verify_nonce( $_POST['spp_kq_nonce'], 'spp_kq_live_action' ) ) {
        return 'Security check failed -- please try again.';
    }

    $action = sanitize_text_field( wp_unslash( $_POST['spp_kq_action'] ) );
    $round  = isset( $_POST['spp_kq_round'] ) ? intval( $_POST['spp_kq_round'] ) : 0;

    switch ( $action ) {
        case 'start_round1':
            // Same timing gate the Start screen's button enforces (see
            // that function's docblock -- 1.3.0, reuses
            // spp_kq_get_occurrence_start_timestamp() directly, no
            // second implementation of the same math) -- re-checked
            // here so a crafted or stale POST can't bypass it. Must
            // match what the UI offers, same discipline as every other
            // access check in this codebase.
            $start_ts = spp_kq_get_occurrence_start_timestamp( $event_date, $event_time );
            $can_start_now = spp_is_admin() || ( $start_ts !== null && current_time( 'timestamp' ) >= ( $start_ts - 30 * MINUTE_IN_SECONDS ) );
            if ( ! $can_start_now ) {
                if ( $start_ts === null ) {
                    return "This event's scheduled time isn't set yet -- please check back closer to the event, or contact an administrator.";
                }
                $opens_at = $start_ts - 30 * MINUTE_IN_SECONDS;
                $when     = date_i18n( 'g:ia', $opens_at ) . ' on ' . date_i18n( 'l, F j', $opens_at );
                return "This event doesn't open until {$when}.";
            }
            // 1.1.0: reconcile check-in reality BEFORE the transition's own
            // count check runs -- anyone confirmed but not checked in is
            // withdrawn now (spp_kq_roster_remove(), same call Roster
            // Adjust's own Remove button uses), so
            // spp_kq_transition_start_round1()'s unchanged
            // spp_kq_confirmed_count() check already evaluates the
            // post-reconciliation count. See inc/spp-kq-checkin.php's own
            // header for the full flow, including why this makes check-in
            // effectively mandatory.
            $checked_in_ids = spp_kq_get_checked_in_user_ids( $occurrence_id );
            foreach ( spp_kq_confirmed_user_ids( $occurrence_id ) as $uid ) {
                if ( ! in_array( $uid, $checked_in_ids, true ) ) {
                    spp_kq_roster_remove( $occurrence_id, $uid );
                }
            }
            $r = spp_kq_transition_start_round1( $occurrence_id );
            return ( ! $r['won'] && $r['error'] ) ? $r['error'] : '';

        case 'announce_courts':
            // 1.13.0: round 1 only -- round 2+ never POSTs this action
            // (its own courts_announced_at is stamped automatically by
            // spp_kq_transition_advance_round(), inc/spp-kq-live.php).
            $r = spp_kq_transition_announce_courts( $occurrence_id, $round );
            return ( ! $r['won'] && $r['error'] ) ? $r['error'] : '';

        case 'skip_rest_countdown':
            // 1.27.0: round 2+'s rest-countdown "Skip Wait" button -- see
            // spp_kq_transition_skip_rest_countdown()'s own docblock
            // (inc/spp-kq-live.php) for the full CAS-guard writeup.
            // 'error' is always null here by design: a double-press or a
            // race against the countdown's own natural completion both
            // resolve to a silent no-op, not something worth surfacing.
            $r = spp_kq_transition_skip_rest_countdown( $occurrence_id, $round );
            return ( ! $r['won'] && $r['error'] ) ? $r['error'] : '';

        case 'start_play':
            // 1.10.0: facilitator-set match-timer length, locked in for
            // the round the instant this transition wins -- see
            // spp_kq_transition_start_play()'s own docblock
            // (inc/spp-kq-live.php).
            $round_minutes = spp_kq_sanitize_round_minutes( $_POST['spp_kq_round_minutes'] ?? null );
            $r = spp_kq_transition_start_play( $occurrence_id, $round, $round_minutes * MINUTE_IN_SECONDS );
            return ( ! $r['won'] && $r['error'] ) ? $r['error'] : '';

        case 'end_event':
            // Same gate the Overview screen's own button visibility
            // enforces (see spp_kq_render_overview_screen()) -- must
            // match what the UI offers, re-checked here so a crafted or
            // stale POST can't declare a winner before anything's been
            // played.
            if ( ! spp_kq_has_any_recorded_score( $occurrence_id ) ) {
                return 'End Event isn\'t available yet -- no scores have been recorded for this event.';
            }
            $r = spp_kq_transition_end_event( $occurrence_id, $round );
            if ( ! $r['won'] ) {
                return '';
            }
            // 1.24.0: permanent history archive (spp_kq_history) now runs
            // FIRST, before Club Rating/format rankings -- spp_kq_get_
            // final_courts() (inc/spp-kq-format-ranking.php) reads a
            // player's final court from spp_kq_history, not the live
            // spp_kq_scores/spp_kq_assignments tables, so that table must
            // already hold THIS occurrence's rows by the time format
            // rankings run. Club Rating is unaffected by this reordering
            // either way (spp_kq_build_club_rating_games() has always
            // read the live tables, never spp_kq_history). Same trigger
            // point/pre-launch guard for all three either way; only the
            // ORDER changed, not what runs. 'cancel_event' below calls
            // all three this exact same way -- any round that had
            // already reported before a cancellation is still real data,
            // worth archiving/rating/ranking exactly as if the event had
            // ended normally.
            $history_notice = spp_kq_finalize_event_history_and_recap( $occurrence_id, $event_date );
            // Stage 4: automatic Club Rating publish on a successful
            // transition only -- spp_kq_maybe_publish_to_club_ratings()
            // (inc/spp-kq-club-rating.php) owns the pre-launch date guard
            // and the source ('ace'/'queen') resolution; this dispatcher
            // has no rating-engine knowledge of its own.
            $rating_result = spp_kq_maybe_publish_to_club_ratings( $occurrence_id, $event_date );
            // Ace/Queen of the Courts format rankings (inc/spp-kq-format-
            // ranking.php) -- same trigger point and pre-launch guard as
            // the Club Rating publish just above, but a completely
            // separate, independent-decay-average concern of its own;
            // see that file's own header for the full design.
            $format_result = spp_kq_update_format_rankings( $occurrence_id, $event_date );
            // 1.19.0: membership/Master/Masterlist{year}/Membershiplist{year}
            // are wholesale-rebuilt snapshot tables (spp_create_membership_
            // table(), inc/spp-create-membership-table.php) that pivot
            // usermeta (spp_glicko_rating et al) into the tables reports
            // actually read from -- every existing caller of that rebuild
            // is ladder-pipeline-specific, so a KQ-driven rating change
            // never used to propagate there until some UNRELATED ladder
            // action next happened to trigger a rebuild. Only called when
            // 'published' is true -- usermeta genuinely changed (not the
            // pre-launch-date/no-games/aborted/not-enough-established
            // paths, none of which touch usermeta) -- so a sandbox/test
            // event that correctly skipped publishing never triggers a
            // rebuild for nothing. Safe to call synchronously here: no
            // ladder-pipeline-specific assumption in its own implementation
            // (pure usermeta+users pivot, occurrence-agnostic), same
            // full-table-rebuild cost the ladder's own pipeline already
            // accepts synchronously today (spp-apply-override-to-results-
            // table.php).
            if ( $rating_result['published'] || $format_result['updated'] ) {
                spp_create_membership_table();
            }
            return trim( $rating_result['notice'] . ( $format_result['notice'] !== '' ? ' ' . $format_result['notice'] : '' ) . ( $history_notice !== '' ? ' ' . $history_notice : '' ) );

        case 'cancel_event':
            $r = spp_kq_transition_cancel_event( $occurrence_id, $round );
            if ( ! $r['won'] ) {
                return '';
            }
            // Same trigger point, same call pattern, same ORDER as
            // 'end_event' above (see that case's own 1.24.0 comment for
            // why history archival now runs first) -- any round that had
            // already reported before a cancellation is still real data,
            // worth archiving/rating/ranking exactly as if the event had
            // ended normally. See inc/spp-kq-history.php's own header:
            // cancel_event and end_event both trigger this, differing
            // only in which CAS transition got them here.
            $history_notice = spp_kq_finalize_event_history_and_recap( $occurrence_id, $event_date );
            $rating_result = spp_kq_maybe_publish_to_club_ratings( $occurrence_id, $event_date );
            $format_result = spp_kq_update_format_rankings( $occurrence_id, $event_date );
            if ( $rating_result['published'] || $format_result['updated'] ) {
                spp_create_membership_table();
            }
            return trim( $rating_result['notice'] . ( $format_result['notice'] !== '' ? ' ' . $format_result['notice'] : '' ) . ( $history_notice !== '' ? ' ' . $history_notice : '' ) );

        case 'complete_draw_randomly':
            // 1.17.0 -- see spp_kq_complete_draw_randomly()'s own
            // docblock (inc/spp-kq-live.php). Same access level as
            // every other draw-screen action (spp_kq_can_facilitate(),
            // already checked at the dispatcher) -- not admin-only,
            // this doesn't discard anything, it only fills what's
            // already open.
            spp_kq_complete_draw_randomly( $occurrence_id );
            return '';

        case 'reset_event':
            // Available to any facilitator (spp_kq_can_facilitate(), the
            // feature-wide gate already checked at the shortcode
            // dispatcher) -- deliberately NOT admin-restricted, unlike
            // Full Reset below. Re-derives the real current phase itself
            // rather than trusting anything from the client; the actual
            // safety guarantee is the atomic CAS inside
            // spp_kq_transition_reset_event() itself, not this read.
            $state = spp_kq_get_event_state( $occurrence_id );
            if ( $state ) {
                spp_kq_transition_reset_event( $occurrence_id, $round, $state['phase'] );
            }
            return '';

        case 'full_reset':
            // Administrator-only -- checked here, server-side, as the
            // real enforcement; the button itself is also never printed
            // into the page for a non-admin (see spp_kq_live_shortcode()),
            // but that's belt-and-suspenders, not the actual gate. A
            // crafted POST from a non-admin session must fail here
            // regardless of what the UI would have shown them.
            if ( ! spp_is_admin() ) {
                return 'You do not have permission to do that.';
            }
            // 1.19.0: test/sandbox occurrences ONLY -- same permanent,
            // literal SPP_KQ_CLUB_RATING_LAUNCH_DATE cutoff already used
            // by Club Ratings/history archival/the photo prompt (inc/
            // spp-kq-club-rating.php), reused rather than a new "is this
            // a test event" concept. Checked here, server-side, as the
            // REAL enforcement -- spp_kq_render_full_reset() not
            // printing the button for a real event is belt-and-
            // suspenders, not the gate; even an administrator (who
            // passed the check just above) cannot Full Reset a real,
            // live event via a crafted/direct POST.
            if ( $event_date >= SPP_KQ_CLUB_RATING_LAUNCH_DATE ) {
                return 'Full Reset is only available for test events.';
            }
            spp_kq_full_reset( $occurrence_id );
            // UX fix: a fully successful Full Reset on an occurrence with
            // nothing visible in assignments/scores to clear (e.g. still
            // 'not_started') previously rendered byte-identical to a
            // silent failure -- this dispatcher's own convention is '' on
            // success, plain text only on error, so nothing ever told the
            // facilitator it actually worked. SPP_KQ_NOTICE_OK_PREFIX
            // (see this file's own definition, and
            // spp_kq_render_occurrence_header()) opts this one message
            // into the success (kq-notice-ok) style instead of the
            // default error one -- confirmed via marker-value testing
            // that the deletes themselves were never the problem.
            return SPP_KQ_NOTICE_OK_PREFIX . 'Event fully reset.';

        case 'roster_add':
        case 'roster_remove':
            // KQ-specific replacement for gl-registration-admin (see
            // inc/spp-kq-roster.php's own header) -- gated only by this
            // shortcode's existing spp_kq_can_facilitate() check at the
            // top of spp_kq_live_shortcode(), same as every other action
            // here; no admin/editor/convenor role check. Re-verifies
            // phase itself (never trusts the client) -- the roster is
            // only ever adjustable before Round 1's draw locks it in.
            $roster_state = spp_kq_get_event_state( $occurrence_id );
            if ( ! $roster_state || $roster_state['phase'] !== 'not_started' ) {
                return 'The roster can only be adjusted before Round 1 starts.';
            }
            $roster_user_id = isset( $_POST['spp_kq_roster_user_id'] ) ? absint( $_POST['spp_kq_roster_user_id'] ) : 0;
            $roster_result  = ( $action === 'roster_add' )
                ? spp_kq_roster_add( $occurrence_id, $roster_user_id )
                : spp_kq_roster_remove( $occurrence_id, $roster_user_id );
            return $roster_result['success'] ? '' : ( $roster_result['error'] ?? '' );

        case 'roster_add_guest':
            // Same gate/phase re-check as 'roster_add' just above -- see
            // spp_kq_roster_add_guest()'s own docblock (inc/spp-kq-
            // roster.php) for what this actually does (creates a real,
            // no-login WP user, then adds it via spp_kq_roster_add()
            // itself, no parallel registration logic).
            $roster_state2 = spp_kq_get_event_state( $occurrence_id );
            if ( ! $roster_state2 || $roster_state2['phase'] !== 'not_started' ) {
                return 'The roster can only be adjusted before Round 1 starts.';
            }
            $guest_name   = isset( $_POST['spp_kq_guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['spp_kq_guest_name'] ) ) : '';
            $guest_result = spp_kq_roster_add_guest( $occurrence_id, $guest_name );
            return $guest_result['success'] ? '' : ( $guest_result['error'] ?? '' );

        case 'checkin_mark':
        case 'checkin_unmark':
            // 1.1.0 -- see inc/spp-kq-checkin.php's own header. Pre-Round-1
            // only, same boundary roster_add/roster_remove already enforce
            // just above.
            $checkin_state = spp_kq_get_event_state( $occurrence_id );
            if ( ! $checkin_state || $checkin_state['phase'] !== 'not_started' ) {
                return 'Check-in is only available before Round 1 starts.';
            }
            $checkin_user_id = isset( $_POST['spp_kq_checkin_user_id'] ) ? absint( $_POST['spp_kq_checkin_user_id'] ) : 0;
            if ( ! $checkin_user_id || ! in_array( $checkin_user_id, spp_kq_confirmed_user_ids( $occurrence_id ), true ) ) {
                return 'Not a confirmed registrant for this event.';
            }
            spp_kq_set_checked_in( $occurrence_id, $checkin_user_id, $action === 'checkin_mark' );
            return '';

        case 'roster_swap':
            // 1.1.0 mid-event 1-for-1 substitute -- see
            // spp_kq_swap_player()'s own docblock (inc/spp-kq-live.php) for
            // the full validation it performs; this dispatcher just reads
            // the two user_ids off the POST and hands them over.
            $swap_old = isset( $_POST['spp_kq_swap_old_user_id'] ) ? absint( $_POST['spp_kq_swap_old_user_id'] ) : 0;
            $swap_new = isset( $_POST['spp_kq_swap_new_user_id'] ) ? absint( $_POST['spp_kq_swap_new_user_id'] ) : 0;
            $swap_result = spp_kq_swap_player( $occurrence_id, $swap_old, $swap_new );
            return $swap_result['success'] ? '' : ( $swap_result['error'] ?? '' );

        case 'roster_fill_slot':
            // 1.1.0 -- re-staffs one EMPTY slot on a court
            // spp_kq_transition_advance_round() re-created after a
            // cancellation. See spp_kq_fill_open_slot()'s own docblock.
            $fill_court = isset( $_POST['spp_kq_fill_court_name'] ) ? sanitize_text_field( wp_unslash( $_POST['spp_kq_fill_court_name'] ) ) : '';
            $fill_color = isset( $_POST['spp_kq_fill_team_color'] ) ? sanitize_text_field( wp_unslash( $_POST['spp_kq_fill_team_color'] ) ) : '';
            $fill_user  = isset( $_POST['spp_kq_fill_new_user_id'] ) ? absint( $_POST['spp_kq_fill_new_user_id'] ) : 0;
            $fill_result = spp_kq_fill_open_slot( $occurrence_id, $fill_court, $fill_color, $fill_user );
            return $fill_result['success'] ? '' : ( $fill_result['error'] ?? '' );

        case 'cancel_court':
            // 1.1.0 -- see spp_kq_cancel_court()'s own docblock
            // (inc/spp-kq-live.php).
            $cancel_court_name = isset( $_POST['spp_kq_cancel_court_name'] ) ? sanitize_text_field( wp_unslash( $_POST['spp_kq_cancel_court_name'] ) ) : '';
            $cancel_result = spp_kq_cancel_court( $occurrence_id, $cancel_court_name );
            return $cancel_result['success'] ? '' : ( $cancel_result['error'] ?? '' );
    }

    return '';
}

// =============================================================
// Persistent AJAX app (1.14.0) -- organizing (announce/rest/Start Play)
// and in_play only. See this file's own 1.14.0 changelog for the full
// architecture writeup. Check-in, roster-adjust, and the draw screen
// are deliberately NOT part of this -- none of them carry audio, so a
// plain page load/reload for those is unchanged and correct.
// =============================================================

/**
 * The swappable content for whichever of organizing/in_play/complete/
 * cancelled currently applies -- the SAME phase-dispatch logic
 * spp_kq_live_shortcode() always used, factored out so BOTH the
 * persistent app's initial paint (spp_kq_render_live_app() below) and
 * every later AJAX swap (wp_ajax_spp_kq_render_fragment) call this ONE
 * function rather than two copies that could drift apart.
 *
 * complete/cancelled ARE genuinely reachable here as of 1.16.0 -- the
 * outer poll (spp_kq_render_live_app()'s own script) now treats them
 * as normal swappable states rather than redirecting/reloading away,
 * since the post-completion Submit Photo prompt is embedded directly
 * on those screens now (spp_kq_render_photo_prompt()) and is a normal
 * in-app destination, not a reason to leave. Only the not_started
 * default below stays a true "should never actually happen" case (only
 * reachable via a Full Reset from another tab, which the outer poll
 * still reloads away from, before ever requesting a fragment).
 *
 * Returns array{html: string, state: ?array} -- $state (spp_kq_get_
 * event_state()'s own row) is returned ALONGSIDE the markup, from the
 * exact same read this function already made to decide what to render,
 * rather than making the AJAX endpoint re-query it separately (1.15.0
 * -- see this file's own 1.15.0 changelog: a caller needs BOTH the
 * html AND an up-to-date structural signature from the SAME instant,
 * and returning them together is what makes swapFragment() able to
 * keep lastSignature correctly in sync no matter who triggered the
 * swap, closing the double-announcement bug that fix addresses).
 */
function spp_kq_render_live_fragment( int $occurrence_id ) : array {
    spp_kq_ensure_event_row( $occurrence_id );
    $state = spp_kq_get_event_state( $occurrence_id );
    if ( ! $state ) {
        return array( 'html' => '<p class="kq-warn">This occurrence could not be found.</p>', 'state' => null );
    }
    $phase = $state['phase'];
    $round = (int) $state['current_round'];

    // spp_kq_render_scoreboard_link()/spp_kq_render_full_reset() are
    // deliberately NOT re-rendered here -- spp_kq_live_shortcode()
    // already renders both once, statically, OUTSIDE #kq-live-app
    // (same as it always did), so including them here too would
    // double-render them for organizing/in_play specifically. Full
    // Reset's $round is a hidden field spp_kq_full_reset() itself
    // never even reads (full reset wipes everything regardless), so a
    // static/stale round number there is harmless. The scoreboard link
    // going stale for the length of one persistent session (it only
    // ever needs to APPEAR, the moment the event's first score is
    // saved) is a minor, pre-existing-shaped gap, not a regression --
    // a manual refresh already always fixed it.
    ob_start();

    switch ( $phase ) {
        case 'organizing':
            $unclaimed = spp_kq_count_unclaimed( $occurrence_id, $round );
            if ( $round === 1 && $unclaimed > 0 ) {
                echo spp_kq_render_draw_screen( $occurrence_id );
            } else {
                echo spp_kq_render_overview_screen( $occurrence_id, $round, $state['courts_announced_at'] );
            }
            break;

        case 'in_play':
            echo spp_kq_render_in_play_screen( $occurrence_id, $round, $state['round_started_at'], $state['round_duration_seconds'] );
            break;

        case 'complete':
            echo spp_kq_render_complete_screen( $occurrence_id, $round );
            break;

        case 'cancelled':
            echo spp_kq_render_cancelled_screen( $occurrence_id, $round );
            break;

        default:
            echo '<p class="kq-hint">This event just changed status &mdash; refreshing&hellip;</p><script>window.location.reload();</script>';
    }

    return array( 'html' => ob_get_clean(), 'state' => $state );
}

/**
 * The persistent app shell: renders the shared speech-announcer banner
 * ONCE (spp_kq_render_speech_announcer() -- NOT called by the
 * individual screens any more, precisely so SppKqAnnouncer's
 * audioUnlocked/keep-alive state survives every later swap instead of
 * being recreated from scratch each time), then a swappable #kq-live-
 * app container holding the current fragment, then the orchestration
 * script: polls (reusing wp_ajax_spp_kq_poll_status, unchanged
 * server-side) for a structural-state signature (phase/round/
 * courts_announced_at -- round_started_at/round_duration_seconds are
 * deliberately NOT part of it: they're set once, atomically, at the
 * SAME instant phase flips to in_play, so phase+round alone already
 * captures that transition; they never change again while still
 * in_play for that round), and on a change fetches the new fragment's
 * HTML (wp_ajax_spp_kq_render_fragment) and swaps it in.
 *
 * SCRIPT RE-EXECUTION: a fragment's own <script> tags are inert when
 * set via .innerHTML (standard DOM behavior) -- executeScripts() below
 * clones each into a fresh <script> element (which DOES execute) right
 * after every swap. Every fragment's own script is already a self-
 * contained IIFE that queries the DOM it just landed next to and
 * computes everything from embedded server timestamps + Date.now(), so
 * re-running it fresh on each swap is exactly correct -- from that
 * script's own point of view a swap is indistinguishable from a fresh
 * page load, which is why the "Go to your courts."/"Start play now."
 * timing logic built earlier today needed NO changes at all to work
 * correctly under this architecture.
 *
 * SCRIPT ORDERING matters here: this shell's own <script> (which
 * defines window.SppKqLiveApp) is emitted BEFORE the #kq-live-app div
 * that holds the first fragment, specifically so that fragment's own
 * inline script -- which calls SppKqLiveApp.wireAjaxForm(...) -- finds
 * it already defined. That's safe because this script never touches
 * #kq-live-app synchronously at parse time, only later, inside
 * functions invoked by setInterval/fetch callbacks -- by which point
 * the rest of the page (including the div, further down in source
 * order) has already been parsed into the DOM.
 */
function spp_kq_render_live_app( int $occurrence_id, array $state ) : string {
    // Matches signatureOf()'s own JS Array.prototype.join() behavior
    // exactly: a null element joins as an empty string, not the
    // literal text "null" -- getting this wrong would make the
    // PHP-computed initial signature permanently disagree with every
    // later JS-computed one for any occurrence where one of these is
    // ever null, causing a spurious fragment swap on the very first poll.
    $initial_signature = implode( '|', array(
        $state['phase'],
        (int) $state['current_round'],
        $state['courts_announced_at'] !== null ? (string) (int) $state['courts_announced_at'] : '',
    ) );

    ob_start();
    ?>
    <?php echo spp_kq_render_speech_announcer(); ?>
    <script>
    (function() {
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'spp_kq_live_action' ) ); ?>;
        var occ     = <?php echo (int) $occurrence_id; ?>;
        // Part A (1.20.0): the persistent "View Full Scoreboard" / "Back"
        // toggle link (spp_kq_render_scoreboard_link(), rendered once,
        // statically, OUTSIDE #kq-live-app -- same placement as this
        // shell itself) -- href values for both its states, precomputed
        // server-side exactly like that function computes them, so JS
        // never has to reconstruct query-string logic of its own.
        var scoreboardLink    = document.getElementById('kq-scoreboard-link');
        var scoreboardViewUrl = <?php echo wp_json_encode( esc_url( add_query_arg( 'kq_view', 'scoreboard' ) ) ); ?>;
        var scoreboardBackUrl = <?php echo wp_json_encode( esc_url( remove_query_arg( 'kq_view' ) ) ); ?>;

        function executeScripts(container) {
            var scripts = container.querySelectorAll('script');
            scripts.forEach(function(oldScript) {
                var newScript = document.createElement('script');
                for (var i = 0; i < oldScript.attributes.length; i++) {
                    newScript.setAttribute(oldScript.attributes[i].name, oldScript.attributes[i].value);
                }
                newScript.textContent = oldScript.textContent;
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });
        }

        // 1.15.0: fixes a live-tested bug where most announcements
        // fired twice (including the countdown). CONFIRMED root cause,
        // not guessed: submitAction() (Announce Courts/Start Play)
        // calls swapFragment() directly for immediate feedback, but
        // never touched lastSignature -- poll()'s own setInterval kept
        // running regardless and still held the PRE-transition
        // signature, so its very next tick (<=4s later) ALWAYS saw a
        // stale mismatch and called swapFragment() a SECOND time for
        // the exact same transition. That second swap re-executed the
        // fragment's inline script again, arming a second, completely
        // independent copy of whatever timer it sets up (the round
        // timer's tick()/setInterval, or the rest-countdown's own) --
        // the first copy was never cleared, so both kept running,
        // both speaking through the same persistent SppKqAnnouncer,
        // for the rest of that fragment's life. Confirmed by grepping
        // every reference to lastSignature before this fix: only
        // poll() ever read or wrote it.
        //
        // FIX, two parts:
        //   1. swapFragment() now updates lastSignature itself, from
        //      the SAME response it just used for the html -- so
        //      whichever caller triggers a swap (poll() or
        //      submitAction()), lastSignature is correct immediately
        //      afterward, and poll()'s own next tick can never see a
        //      stale mismatch for a transition that already happened.
        //   2. A `swapping` guard plus a SppKqLiveApp.cleanup() hook
        //      (mirroring the existing onProgress convention) as
        //      defense in depth against the SEPARATE, rarer case this
        //      fix doesn't directly address -- two overlapping fetch()
        //      calls (e.g. a slow connection) both starting before
        //      either resolves. cleanup() lets whichever fragment is
        //      CURRENTLY active (in-play's round timer, or the rest-
        //      countdown) stop its own interval before being replaced,
        //      exactly like onProgress is already reset before every
        //      swap.
        var swapping = false;
        // 1.25.0: true exactly while the scoreboard fragment is the
        // visible content AND a corresponding history entry is pushed
        // (see showScoreboard()/the popstate listener below) -- the one
        // piece of state that lets swapFragment() and popstate agree on
        // whether there's a pushed entry still needing to be resolved.
        var scoreboardHistoryPushed = false;
        // 1.26.0: BUG 2 fix -- see this file's own 1.26.0 changelog for
        // the full root-cause writeup (confirmed live, not guessed).
        // swapFragment()'s whole job is re-fetching+re-executing whatever
        // fragment matches CURRENT server state, and every caller before
        // yesterday's "Back to game" link only ever called it for a
        // GENUINE transition (poll() detecting a real signature change,
        // or submitAction() after an action that really changed server
        // state) -- so a fresh fired={}/fired=false inside the fragment's
        // own re-executed <script> was always correct: retroactively
        // announcing whatever threshold a device "just" crossed on a
        // genuine first render of that new state. "Back to game" (and
        // native Back via popstate below) broke that assumption: the
        // round hasn't changed at all, it's a PASSIVE redisplay of
        // already-current state this device already heard announcements
        // for -- but the swapped-in fragment's script has no way to tell
        // the difference on its own, so it retroactively (and audibly)
        // re-announces everything already due ("Start play now, 1 minute
        // remaining" at once, live-tested). isTransition (default true,
        // preserving every existing caller's behavior unchanged) lets
        // THIS call site say which one it is; the flag is read by the
        // in-play/overview fragments' own scripts (spp_kq_render_in_
        // play_screen()/spp_kq_render_overview_screen(), each with their
        // own 1.26.0 note) to silence just their OWN first tick's
        // speak() calls on a passive redisplay, then behave completely
        // normally (real announcements for anything crossed from then
        // on) for the rest of that fragment's life.
        function swapFragment(isTransition) {
            if (isTransition === undefined) isTransition = true;
            if (swapping) return;
            swapping = true;
            var data = new FormData();
            data.append('action', 'spp_kq_render_fragment');
            data.append('nonce', nonce);
            data.append('occ', occ);
            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    swapping = false;
                    if (!res.success) return;
                    var appEl = document.getElementById('kq-live-app');
                    if (!appEl) return;
                    // Stop whatever the OUTGOING fragment armed, then
                    // reset both hooks -- if the new fragment doesn't
                    // register its own (e.g. it's not the in-play
                    // screen), a stale one from whatever WAS active
                    // must not keep firing.
                    if (SppKqLiveApp.cleanup) SppKqLiveApp.cleanup();
                    SppKqLiveApp.onProgress = null;
                    SppKqLiveApp.cleanup = null;
                    lastSignature = signatureOf(res.data);
                    // 1.26.0: consumed once, immediately, by whichever
                    // fragment script executeScripts() is about to run --
                    // see this function's own top-of-function comment.
                    window.__kqIsTransition = isTransition;
                    appEl.innerHTML = res.data.html;
                    executeScripts(appEl);
                    // Part A (1.20.0): a swapFragment() call always lands
                    // on the actual live-phase content, never the
                    // scoreboard -- reset the toggle link's label/href
                    // unconditionally here, the one place #kq-live-app's
                    // content ever becomes "the real live screen" again,
                    // whether poll(), submitAction(), or the scoreboard
                    // fragment's own "Back to game" link (which just
                    // calls this same function) triggered it. A no-op
                    // when the link isn't even on the page (not_started/
                    // complete/cancelled -- see spp_kq_render_scoreboard_
                    // link()'s own docblock) or was already showing
                    // "View Full Scoreboard".
                    // 1.26.0: BUG 1 fix -- see this file's own 1.26.0
                    // changelog. swapFragment() always lands on the real
                    // live screen, which already has its OWN "Back to
                    // game" link (spp_kq_render_scoreboard_fragment() is
                    // never what's showing once this runs) -- so restore
                    // BOTH the label/href AND visibility here (parentNode,
                    // not the <a> itself, so no empty <p class="kq-hint">
                    // gap is left behind while showScoreboard() below has
                    // it hidden).
                    if (scoreboardLink) {
                        scoreboardLink.textContent = 'View Full Scoreboard';
                        scoreboardLink.setAttribute('href', scoreboardViewUrl);
                        scoreboardLink.parentNode.style.display = '';
                    }
                    // 1.25.0: a REAL transition (poll()/submitAction())
                    // can land here while the scoreboard's own history
                    // entry is still on the stack (live-tested 1.20.0
                    // scenario: poll() auto-swaps away from the
                    // scoreboard on its own). We're not navigating here,
                    // just replacing content, so replaceState -- not
                    // popstate's swapFragment() call below -- is what
                    // reconciles the URL bar back to the base URL without
                    // it, so a LATER native Back press lands on whatever
                    // was truly before the scoreboard was ever opened,
                    // not a now-stale, silently-orphaned entry that would
                    // otherwise refire this same swap for no reason.
                    if (scoreboardHistoryPushed) {
                        scoreboardHistoryPushed = false;
                        history.replaceState(null, '', scoreboardBackUrl);
                    }
                })
                .catch(function() { swapping = false; });
        }

        // Part A (1.20.0): fetches the scoreboard AS A FRAGMENT (see
        // spp_kq_render_scoreboard_fragment(), inc/spp-kq-screens.php)
        // and swaps it into #kq-live-app -- same cleanup-before-swap
        // discipline as swapFragment() just above (a stale onProgress/
        // cleanup hook from whatever WAS showing must not keep firing
        // against DOM this swap just removed), but deliberately does
        // NOT touch lastSignature: the scoreboard is a client-side
        // overlay on top of whatever the real structural state is, not
        // itself a structural state poll() should ever compare against.
        function showScoreboard() {
            if (swapping) return;
            swapping = true;
            var data = new FormData();
            data.append('action', 'spp_kq_render_scoreboard_fragment');
            data.append('nonce', nonce);
            data.append('occ', occ);
            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    swapping = false;
                    if (!res.success) return;
                    var appEl = document.getElementById('kq-live-app');
                    if (!appEl) return;
                    if (SppKqLiveApp.cleanup) SppKqLiveApp.cleanup();
                    SppKqLiveApp.onProgress = null;
                    SppKqLiveApp.cleanup = null;
                    appEl.innerHTML = res.data.html;
                    executeScripts(appEl);
                    // 1.26.0: BUG 1 fix -- real-incident feedback: two
                    // "Back" controls appeared at once on the scoreboard
                    // -- this top-level toggle link relabeling itself to
                    // "« Back" (pre-1.26.0), AND spp_kq_render_scoreboard_
                    // fragment()'s OWN "« Back to game" link, rendered
                    // INSIDE the fragment this same swap just injected via
                    // appEl.innerHTML above -- both doing the identical
                    // job. Redundant, not a bug in either link on its
                    // own: this toggle link predates yesterday's fold-in
                    // work (1.20.0) and was never updated when the
                    // fragment grew its own dedicated link (yesterday's
                    // spec item 2). Fix: HIDE this one entirely (its
                    // parentNode, so no leftover empty <p class="kq-hint">
                    // gap) instead of relabeling it -- the in-fragment
                    // "Back to game" link is now the ONLY way back, and
                    // this link's only remaining job is being the ENTRY
                    // point ("View Full Scoreboard") the rest of the
                    // time. Restored to visible by swapFragment() above
                    // the moment #kq-live-app holds real content again.
                    if (scoreboardLink) {
                        scoreboardLink.parentNode.style.display = 'none';
                    }
                    // 1.25.0: push a same-document history entry so the
                    // PHONE'S OWN native Back button has something correct
                    // to land on. Real-incident root cause (confirmed by
                    // injecting a marker into window and diffing it across
                    // a live history.back() on occurrence 118, not
                    // guessed): before this, entering the scoreboard never
                    // touched history at all (the link's own click handler
                    // always called e.preventDefault()), so native Back
                    // skipped over this page's single history entry
                    // entirely and landed on whatever real page came
                    // before it -- confirmed to be a genuine full document
                    // reload (window.__testMarker did NOT survive; Chrome
                    // reported navigation type "back_forward", not a
                    // bfcache restore), NOT a frozen/persisted DOM. That
                    // fresh load re-renders this SAME occurrence's CURRENT
                    // phase (spp_kq_live_shortcode() always reads live
                    // state, never what that old entry originally showed)
                    // straight back into spp_kq_render_live_app() --
                    // recreating SppKqAnnouncer with audioUnlocked reset
                    // to false (the "Tap to enable sound" banner
                    // reappears) and re-running the in-play/overview
                    // fragment's own <script> from scratch, which computes
                    // its "Go to your courts."/"Start play now." timing
                    // purely from embedded server timestamps vs.
                    // Date.now() (by design, see this function's 1.13.0
                    // changelog) -- so a facilitator who instinctively
                    // re-taps that reappeared banner (nothing on screen
                    // tells them the page silently reloaded underneath
                    // them) re-triggers a real speak() call for an
                    // announcement that, from the actual round's point of
                    // view, already correctly happened once. Pushing this
                    // entry (and the popstate listener/click-handler
                    // change below) makes native Back behave exactly like
                    // the in-app "« Back" link always has: a same-document
                    // popstate, no reload, no lost SppKqAnnouncer state.
                    if (!scoreboardHistoryPushed) {
                        history.pushState({ kqScoreboard: true }, '', scoreboardViewUrl);
                        scoreboardHistoryPushed = true;
                    }
                })
                .catch(function() { swapping = false; });
        }

        // 1.26.0: this link is now ENTRY-ONLY ("View Full Scoreboard") --
        // it's hidden (see showScoreboard()/swapFragment() above) for the
        // entire time the scoreboard is showing, since the fragment's own
        // "Back to game" link is the one and only way back now (BUG 1
        // fix). Real navigation still prevented in favor of the in-app
        // swap. The phone's native Back button is handled entirely by the
        // popstate listener below, independent of this click handler.
        if (scoreboardLink) {
            scoreboardLink.addEventListener('click', function(e) {
                e.preventDefault();
                showScoreboard();
            });
        }

        // 1.25.0: the ONLY place that resolves the scoreboard's pushed
        // history entry -- fires for the phone's own native Back button
        // (same-document popstate, no reload). 1.26.0: this is now ALSO
        // the only remaining caller reached via real back-navigation at
        // all, since the in-app "Back to game" link (spp_kq_render_
        // scoreboard_fragment()) calls SppKqLiveApp.refreshNow() directly
        // rather than history.back() -- doesn't change what this listener
        // does, only that it's exclusively a native-Back handler now.
        // Resetting the flag BEFORE calling swapFragment() means
        // swapFragment()'s own scoreboardHistoryPushed check (above) is
        // correctly a no-op here -- we already arrived via a real back-
        // navigation, so there's nothing left to replaceState away.
        // isTransition=false (BUG 2 fix, see swapFragment()'s own
        // comment): the round itself never changed, this is a passive
        // redisplay of already-current state, not a real transition.
        window.addEventListener('popstate', function() {
            if (scoreboardHistoryPushed) {
                scoreboardHistoryPushed = false;
                swapFragment(false);
            }
        });

        // Submits a REAL <form> (same hidden nonce/action/round inputs
        // every plain-POST action in this feature already renders) via
        // fetch instead of letting the browser navigate -- reloading
        // here would wipe SppKqAnnouncer's audioUnlocked/keep-alive
        // state right before the very announcement the action exists
        // to trigger. Reuses spp_kq_handle_post_actions() server-side,
        // completely unchanged -- see wp_ajax_spp_kq_live_action below.
        function submitAction(form, onSuccess, onError) {
            var data = new FormData(form);
            data.append('action', 'spp_kq_live_action');
            data.append('occ', occ);
            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) {
                        if (onError) onError(res.data || 'Action failed.');
                        return;
                    }
                    if (onSuccess) onSuccess();
                    swapFragment();
                })
                .catch(function() {
                    if (onError) onError('Network error -- try again.');
                });
        }

        function wireAjaxForm(formSelector, msgElId) {
            var form = document.querySelector(formSelector);
            if (!form) return;
            var msgEl = msgElId ? document.getElementById(msgElId) : null;
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = form.querySelector('button[type="submit"]');
                if (btn) btn.disabled = true;
                if (msgEl) msgEl.style.display = 'none';
                submitAction(form, null, function(errMsg) {
                    if (btn) btn.disabled = false;
                    if (msgEl) { msgEl.textContent = errMsg; msgEl.style.display = 'block'; }
                });
            });
        }

        // 1.26.0: refreshNow() is a wrapper, not a direct alias, now --
        // its one caller (spp_kq_render_scoreboard_fragment()'s "Back to
        // game" link) is a passive redisplay of already-current state,
        // never a real transition (BUG 2 fix, see swapFragment()'s own
        // comment) -- isTransition=false here, unconditionally, is what
        // that link means every time it's pressed.
        window.SppKqLiveApp = {
            refreshNow: function() { swapFragment(false); },
            wireAjaxForm: wireAjaxForm,
            showScoreboard: showScoreboard,
            onProgress: null,
            cleanup: null
        };

        var lastSignature = <?php echo wp_json_encode( $initial_signature ); ?>;

        function signatureOf(d) {
            return [d.phase, d.current_round, d.courts_announced_at].join('|');
        }

        // 1.16.0: complete/cancelled are now normal swappable fragment
        // states, same as organizing/in_play -- the post-completion
        // Submit Photo prompt is embedded directly on those screens
        // now (spp_kq_render_photo_prompt(), spp_kq_render_complete_
        // screen()/spp_kq_render_cancelled_screen()), so reaching them
        // is a normal in-app destination, not a reason to redirect or
        // reload away. not_started is the one true exception left --
        // only ever reached via a Full Reset from another tab, and
        // this app was never built with a check-in/draw fragment to
        // show in place, so that one case still reloads.
        var pollInterval = null;

        function poll() {
            var data = new FormData();
            data.append('action', 'spp_kq_poll_status');
            data.append('nonce', nonce);
            data.append('occ', occ);

            fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) return;
                    var d = res.data;

                    if (d.phase === 'not_started') {
                        window.location.reload();
                        return;
                    }

                    var sig = signatureOf(d);
                    if (sig !== lastSignature) {
                        lastSignature = sig;
                        swapFragment();
                    } else if (SppKqLiveApp.onProgress) {
                        // Part B (1.20.0): d.scores -- each court that HAS
                        // reported this round, with its actual red/black
                        // score (see wp_ajax_spp_kq_poll_status's own
                        // docblock) -- lets the in-play screen's onProgress
                        // handler paint a partially-reported round's real
                        // values on a device that didn't submit them,
                        // not just the aggregate count. Not present on
                        // every phase's own onProgress signature (only the
                        // in-play screen registers one that uses it) --
                        // harmless to always pass.
                        SppKqLiveApp.onProgress(d.reported, d.total, d.scores);
                    }

                    // Terminal -- nothing can change server-side past
                    // this point, so stop polling once the matching
                    // fragment is showing (or about to be).
                    if ((d.phase === 'complete' || d.phase === 'cancelled') && pollInterval) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                    }
                })
                .catch(function() {});
        }

        pollInterval = setInterval(poll, 4000);
    })();
    </script>
    <div id="kq-live-app">
        <?php echo spp_kq_render_live_fragment( $occurrence_id )['html']; ?>
    </div>
    <?php
    return ob_get_clean();
}

// =============================================================
// Main shortcode
// =============================================================

add_shortcode( 'spp_kq_live', 'spp_kq_live_shortcode' );

function spp_kq_live_shortcode() : string {
    if ( ! spp_kq_can_facilitate() ) {
        return '<p>Please log in to use this tool.</p>';
    }

    $occurrence_id = isset( $_GET['occ'] ) ? absint( $_GET['occ'] ) : 0;

    if ( ! $occurrence_id ) {
        return spp_kq_render_event_picker();
    }

    $occurrence = spp_kq_get_occurrence_summary( $occurrence_id );
    if ( ! $occurrence ) {
        return '<p>Occurrence not found.</p>';
    }

    // 30-minutes-before-start gate (1.2.0) -- see this file's own
    // "30-minutes-before-start access gate" section header, above
    // spp_kq_get_occurrence_summary(), for the full writeup. Checked
    // BEFORE spp_kq_handle_post_actions() so a crafted early POST
    // (check-in, roster-adjust, anything) can't bypass it either --
    // this blocks EVERYTHING for this occurrence, not just the phase
    // screens below. Administrators exempt, matching the existing
    // Start-Round-1 admin exemption (spp_is_admin()) exactly. Separate
    // from, and layered in front of, the Start-Round-1 gate
    // ($can_start_now in spp_kq_render_start_screen()) -- still its own
    // separate check, applying on its own once a facilitator is past
    // this one (as of 1.3.0 it reuses this same threshold function,
    // not merged into this gate -- see that version's changelog).
    if ( ! spp_is_admin() && ! spp_kq_event_screens_open( $occurrence['event_date'], $occurrence['eff_event_time'] ) ) {
        return spp_kq_render_too_early_notice( $occurrence['event_date'], $occurrence['eff_event_time'] );
    }

    $notice = spp_kq_handle_post_actions( $occurrence_id, $occurrence['event_date'], $occurrence['eff_event_time'] );

    spp_kq_ensure_event_row( $occurrence_id );
    $state = spp_kq_get_event_state( $occurrence_id );
    $phase = $state['phase'];
    $round = (int) $state['current_round'];

    // Full Scoreboard (?kq_view=scoreboard) and Roster Adjust
    // (?kq_view=roster) are both flags layered on top of the phase-
    // driven switch below rather than phases of their own -- purely
    // additive, no interaction with spp_kq_events.phase/current_round
    // at all (Roster Adjust re-verifies phase==='not_started' itself,
    // inside spp_kq_render_roster_screen() -- see inc/spp-kq-roster.php).
    $kq_view = isset( $_GET['kq_view'] ) ? sanitize_key( wp_unslash( $_GET['kq_view'] ) ) : '';
    $viewing_scoreboard = ( $kq_view === 'scoreboard' );
    $viewing_roster      = ( $kq_view === 'roster' );

    ob_start();
    echo spp_kq_styles();
    echo '<div class="kq-wrap">';
    echo spp_kq_render_occurrence_header( $occurrence, $notice );
    // 1.16.0: skipped here for phase complete/cancelled specifically --
    // spp_kq_render_complete_screen()/spp_kq_render_cancelled_screen()
    // now render their OWN "View Full Scoreboard" link, positioned
    // after the embedded Submit Photo prompt per spec's requested
    // order, rather than unconditionally at the very top of the page
    // the way every other phase still gets it. The $viewing_scoreboard/
    // $viewing_roster toggle (including the "&laquo; Back" link) is
    // completely unaffected -- still rendered here, unconditionally,
    // regardless of phase, exactly as before.
    if ( $viewing_scoreboard || $viewing_roster || ! in_array( $phase, array( 'complete', 'cancelled' ), true ) ) {
        echo spp_kq_render_scoreboard_link( $occurrence_id, $viewing_scoreboard );
    }

    if ( $viewing_scoreboard ) {
        echo spp_kq_render_full_scoreboard_screen( $occurrence_id );
    } elseif ( $viewing_roster ) {
        echo spp_kq_render_roster_screen( $occurrence_id );
    } else {
        switch ( $phase ) {
            case 'not_started':
                echo spp_kq_render_start_screen( $occurrence_id, $occurrence['event_date'], $occurrence['eff_event_time'] );
                break;

            case 'organizing':
                $unclaimed = spp_kq_count_unclaimed( $occurrence_id, $round );
                if ( $round === 1 && $unclaimed > 0 ) {
                    // Draw/court-claim carries no audio -- deliberately
                    // NOT part of the persistent app (1.14.0).
                    echo spp_kq_render_draw_screen( $occurrence_id );
                } else {
                    echo spp_kq_render_live_app( $occurrence_id, $state );
                }
                break;

            case 'in_play':
                echo spp_kq_render_live_app( $occurrence_id, $state );
                break;

            case 'complete':
                // 1.16.0: this is the direct/fresh render for whichever
                // device's OWN plain-POST action (End Event) just
                // caused this phase; any other device still on the
                // persistent app gets the identical screen via
                // spp_kq_render_live_fragment()'s own 'complete' case
                // below instead -- both call this SAME function, so
                // the two can never render differently. The Submit
                // Photo prompt is now embedded BY that function itself
                // (spp_kq_render_photo_prompt()) -- no redirect, no
                // special-casing needed here any more.
                echo spp_kq_render_complete_screen( $occurrence_id, $round );
                break;

            case 'cancelled':
                echo spp_kq_render_cancelled_screen( $occurrence_id, $round );
                break;
        }
    }

    echo spp_kq_render_full_reset( $occurrence_id, $round, $occurrence['event_date'] );

    echo '</div>';
    return ob_get_clean();
}

/**
 * Full Scoreboard screen: every completed round, every court, every
 * player, that round's score -- not just the current round. Reachable
 * via ?kq_view=scoreboard from any phase (see the dispatcher above) --
 * pure read, changes nothing, no interaction with spp_kq_events.phase/
 * current_round. Uses spp_kq_get_full_scoreboard() (inc/spp-kq-
 * history.php), the exact same read this event's own eventual archival
 * write (spp_kq_archive_event_history(), same file) will use once it
 * ends -- so what a player sees live mid-event and what later gets
 * archived/emailed are guaranteed to agree.
 *
 * The actual round/court markup is spp_kq_render_scoreboard_markup()
 * (inc/spp-kq-history.php) -- factored out so the historical Event
 * Detail view ([spp_kq_event_detail], same file) can reuse the identical
 * layout against spp_kq_get_history_scoreboard() (the archive) instead
 * of duplicating this markup for a second data source. This function is
 * now just: fetch the live scoreboard, hand it to that shared renderer.
 */
function spp_kq_render_full_scoreboard_screen( int $occurrence_id ) : string {
    $scoreboard = spp_kq_get_full_scoreboard( $occurrence_id );
    ob_start();
    ?>
    <p class="kq-round-label">Full Scoreboard</p>
    <?php
    // 1.27.0: "No scores entered yet." (was "No completed rounds yet.")
    // -- this message is now reachable from the very first moment a live
    // occurrence exists (the toggle link above is unconditional, see
    // spp_kq_render_scoreboard_link()'s own 1.27.0 note), not just a
    // rare edge case, so it's worded for that: the plain, expected state
    // of a not-yet-played event, not a "something's missing" read.
    ?>
    <?php echo spp_kq_render_scoreboard_markup( $scoreboard, 'No scores entered yet.' ); ?>
    <?php
    return ob_get_clean();
}

/**
 * Scoreboard AS A FRAGMENT for the persistent app (Part A, 1.20.0) --
 * same live data/markup as spp_kq_render_full_scoreboard_screen() just
 * above (the standalone ?kq_view=scoreboard page this supplements for
 * AJAX-swap purposes; that page/route is completely unchanged and is
 * still what a non-JS visitor's <a href> points at, and still what
 * loads on a genuine fresh page request), plus a "Back to game" link
 * that calls SppKqLiveApp.refreshNow() -- the exact same function
 * poll()/submitAction() already use to swap in the real current live
 * fragment -- rather than a browser Back button or a real navigation,
 * so audio/timer/polling state survives exactly like every other
 * fragment transition already does. Fetched by wp_ajax_spp_kq_render_
 * scoreboard_fragment below; wired up from spp_kq_render_live_app()'s
 * own bootstrap script, which is the only place window.SppKqLiveApp
 * exists (organizing/in_play only) -- see that function's own docblock.
 *
 * 1.26.0: THE ONLY "back" control while this fragment is showing --
 * spp_kq_render_scoreboard_link()'s own top-level toggle link (rendered
 * once, statically, outside #kq-live-app) is now hidden by JS for the
 * entire time this fragment is up, real-incident fix for two redundant
 * "Back"/"Back to game" controls appearing at once (that link predates
 * this fragment's own "Back to game" link by a day and was never
 * reconciled with it -- see that function's own 1.26.0 docblock note).
 */
function spp_kq_render_scoreboard_fragment( int $occurrence_id ) : string {
    ob_start();
    ?>
    <p class="kq-hint"><a href="#" id="kq-back-to-game-link">&laquo; Back to game</a></p>
    <?php echo spp_kq_render_full_scoreboard_screen( $occurrence_id ); ?>
    <script>
    (function() {
        var backLink = document.getElementById('kq-back-to-game-link');
        if (!backLink) return;
        backLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (window.SppKqLiveApp && SppKqLiveApp.refreshNow) SppKqLiveApp.refreshNow();
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * "View Full Scoreboard" / "&laquo; Back" toggle link -- rendered once
 * in the main dispatcher, same placement pattern as
 * spp_kq_render_full_reset() (present regardless of which phase-driven
 * screen is showing).
 *
 * 1.27.0: unconditional now -- previously returned '' whenever no round
 * had a recorded score yet (spp_kq_has_any_recorded_score()), which is
 * why it read as "only on some screens (in-play, Overview)" from the
 * outside: every phase already reached this call site (see the
 * dispatcher's own condition just above where this is echoed), it just
 * rendered nothing until the first score came in. $occurrence_id is now
 * unused in this function's own body (kept in the signature -- every
 * call site already passes it, and a per-occurrence scoreboard link is
 * still the right shape for this function even though it no longer
 * branches on anything occurrence-specific). spp_kq_render_full_
 * scoreboard_screen()'s own empty-state message ("No scores entered
 * yet.") is what a visitor actually sees now on a genuinely score-free
 * occurrence, rather than the link simply not existing.
 *
 * 1.20.0: id="kq-scoreboard-link" added so spp_kq_render_live_app()'s
 * own bootstrap script (organizing/in_play only -- the only phases that
 * actually run the persistent AJAX app) can find and intercept this
 * link's click to swap the scoreboard in as another fragment instead of
 * navigating -- see that function's own docblock. This function itself
 * is otherwise UNCHANGED: $viewing_scoreboard is still real server-side
 * state (?kq_view=scoreboard), so the href/label rendered here remain
 * the correct plain-navigation fallback for a non-JS visitor, and the
 * initial page-load state JS then takes over from.
 *
 * 1.26.0: on the persistent app, JS now HIDES this link's whole element
 * the entire time the scoreboard fragment is showing, rather than
 * relabeling it to "« Back" as before -- spp_kq_render_scoreboard_
 * fragment() grew its own dedicated "Back to game" link (yesterday's
 * spec item 2) and the two were never reconciled, so both appeared at
 * once (real-incident fix). This function's PHP output is unaffected --
 * $viewing_scoreboard is never true in the persistent-app render path in
 * the first place (spp_kq_live_shortcode()'s dispatcher renders EITHER
 * spp_kq_render_full_scoreboard_screen() OR spp_kq_render_live_app(),
 * never both), so the "« Back" label this function can still produce is
 * exclusively the non-JS plain-navigation fallback's own correct state,
 * never something the persistent app's JS has to un-render.
 */
function spp_kq_render_scoreboard_link( int $occurrence_id, bool $viewing_scoreboard ) : string {
    $url = $viewing_scoreboard
        ? remove_query_arg( 'kq_view' )
        : add_query_arg( 'kq_view', 'scoreboard' );
    $label = $viewing_scoreboard ? '&laquo; Back' : 'View Full Scoreboard';
    return '<p class="kq-hint"><a id="kq-scoreboard-link" href="' . esc_url( $url ) . '">' . $label . '</a></p>';
}

/**
 * Full Reset button -- administrator-only, deliberately available from
 * ANY phase (rendered once here, in the main dispatcher, not inside any
 * one screen's own render function, so it's present regardless of which
 * screen the switch above chose). Not printed into the page at all for
 * a non-administrator -- spp_is_admin() gates whether this function
 * emits anything, and the real enforcement is the matching check in
 * spp_kq_handle_post_actions()'s 'full_reset' case, not this visibility
 * check alone.
 *
 * 1.19.0: also not printed for a REAL event -- $event_date >=
 * SPP_KQ_CLUB_RATING_LAUNCH_DATE (inc/spp-kq-club-rating.php, the same
 * permanent literal cutoff Club Ratings/history archival/the photo
 * prompt already use). Full Reset permanently discards all recorded
 * data; restricting it to pre-launch test/sandbox occurrences removes
 * the risk of an administrator accidentally wiping a real, in-progress
 * event. Same belt-and-suspenders relationship to the POST handler's
 * own check as the admin gate above -- this is visibility only.
 */
function spp_kq_render_full_reset( int $occurrence_id, int $round, string $event_date ) : string {
    if ( ! spp_is_admin() ) {
        return '';
    }
    if ( $event_date >= SPP_KQ_CLUB_RATING_LAUNCH_DATE ) {
        return '';
    }
    ob_start();
    ?>
    <div class="kq-full-reset-row">
        <form method="post" class="kq-inline-form" onsubmit="return confirm('FULL RESET -- this permanently discards ALL recorded data for this event, including any real, already-saved scores. This cannot be undone. Only continue if you are certain. Proceed?');">
            <?php wp_nonce_field( 'spp_kq_live_action', 'spp_kq_nonce' ); ?>
            <input type="hidden" name="spp_kq_action" value="full_reset">
            <input type="hidden" name="spp_kq_round" value="<?php echo esc_attr( $round ); ?>">
            <button type="submit" class="kq-btn kq-btn-danger">Full Reset (Admin Only)</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

// =============================================================
// AJAX: card draw
// =============================================================

add_action( 'wp_ajax_spp_kq_draw_card', function() {
    if ( ! spp_kq_can_facilitate() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    $user_id       = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

    if ( ! $occurrence_id || ! $user_id ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    $result = spp_kq_draw_card( $occurrence_id, $user_id );
    if ( ! $result['success'] ) {
        wp_send_json_error( $result['error'] );
    }

    wp_send_json_success( $result );
} );

// =============================================================
// AJAX: score submit (Stage 3)
//
// The gate here is is_user_logged_in() -- the SAME feature-wide gate
// everything else in this feature uses, full stop. No second, narrower
// check: any logged-in user may submit any real court's score (2026-09
// access-model change, see spp_kq_submit_court_score()'s own section
// header in inc/spp-kq-live.php for why). court_name now comes from
// $_POST -- unavoidable now that it is no longer derived from the
// caller's own assignment -- but it is never trusted at face value:
// spp_kq_submit_court_score() validates it against a real
// spp_kq_scores row for this occurrence/round before writing anything.
// =============================================================

add_action( 'wp_ajax_spp_kq_submit_score', function() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    $round         = isset( $_POST['round'] ) ? absint( $_POST['round'] ) : 0;
    $court_name    = isset( $_POST['court_name'] ) ? sanitize_text_field( wp_unslash( $_POST['court_name'] ) ) : '';
    $red_score     = isset( $_POST['red_score'] ) ? intval( $_POST['red_score'] ) : -1;
    $black_score   = isset( $_POST['black_score'] ) ? intval( $_POST['black_score'] ) : -1;
    // Submission-order marker (1.2.0) -- see spp_kq_submit_court_score()'s
    // own docblock (inc/spp-kq-live.php). 0/absent just means an old
    // cached client; the function degrades gracefully for that case.
    $client_ts     = isset( $_POST['client_ts'] ) ? absint( $_POST['client_ts'] ) : 0;

    if ( ! $occurrence_id || ! $round || $court_name === '' ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    $result = spp_kq_submit_court_score( $occurrence_id, $round, $court_name, $red_score, $black_score, get_current_user_id(), $client_ts );
    if ( ! $result['success'] ) {
        wp_send_json_error( $result['error'] );
    }

    wp_send_json_success( $result );
} );

// =============================================================
// AJAX: lightweight status poll (Stage 3) -- lets the in-play screen
// update its "N of M reported" line live and detect a round advance
// without a full reload, while staying well short of real-time push.
// Read-only, no access restriction beyond being logged in.
//
// 1.16.0: no longer carries redirect_url -- the post-completion Submit
// Photo prompt is embedded directly on the Complete/Cancelled screen
// now (spp_kq_render_photo_prompt()) rather than redirected to, so
// this endpoint doesn't need to compute or expose that at all; a
// polling device picks up phase becoming complete/cancelled the same
// way it picks up any other structural change -- a normal fragment
// swap (see spp_kq_render_live_app()'s own script) -- since that
// screen IS the correct destination now, not a link off of it.
//
// 1.20.0 (Part B): now also carries 'scores' -- every court that HAS
// fully reported this round (both red_score/black_score non-NULL), so
// a device watching the in-play screen that did NOT submit a given
// court's score still sees its real value without a reload, not just
// the aggregate "N of M reported" count -- see spp_kq_render_in_play_
// screen()'s own onProgress handler for what does with it. This is the
// exact same data already rendered directly into that screen's own
// initial HTML (spp_kq_get_round_scores(), inc/spp-kq-live.php) for
// anyone with access to it -- not a new exposure, just now also kept
// live without a full fragment swap.
// =============================================================

add_action( 'wp_ajax_spp_kq_poll_status', function() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    if ( ! $occurrence_id ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    $state = spp_kq_get_event_state( $occurrence_id );
    if ( ! $state ) {
        wp_send_json_error( 'Occurrence not found.' );
    }

    $round    = (int) $state['current_round'];
    $progress = spp_kq_get_round_progress( $occurrence_id, $round );

    // Only fully-reported courts (both scores non-NULL) -- matches
    // $progress['reported']'s own definition exactly (spp_kq_get_round_
    // progress(), inc/spp-kq-live.php), so the two can never disagree
    // about which courts count as "reported".
    $scores = array_values( array_filter(
        spp_kq_get_round_scores( $occurrence_id, $round ),
        fn( $s ) => $s['red_score'] !== null && $s['black_score'] !== null
    ) );

    wp_send_json_success( array(
        'phase'               => $state['phase'],
        'current_round'       => $round,
        'reported'            => $progress['reported'],
        'total'               => $progress['total'],
        'scores'              => $scores,
        // 1.13.0: lets a device detect a DIFFERENT device pressing
        // "Ready -- Announce Courts" -- null until that happens, same
        // nullable-epoch shape as round_started_at. 1.14.0: also part
        // of the persistent app's own structural-state signature
        // (spp_kq_render_live_app()) that decides when to swap in a
        // new fragment.
        'courts_announced_at' => $state['courts_announced_at'] !== null ? (int) $state['courts_announced_at'] : null,
    ) );
} );

// =============================================================
// AJAX: persistent app fragment fetch + form-action submit (1.14.0)
// =============================================================

/**
 * Returns the current fragment's HTML for the persistent app to swap
 * into #kq-live-app -- literally spp_kq_render_live_fragment(), the
 * exact same function the app's own initial page paint already calls,
 * so the two can never render differently for the same underlying
 * state. Read-only from this endpoint's own point of view (it only
 * ever reflects whatever state already exists); state changes
 * themselves only ever happen via wp_ajax_spp_kq_live_action below or
 * the existing wp_ajax_spp_kq_submit_score/wp_ajax_spp_kq_draw_card
 * handlers, unchanged.
 *
 * 1.15.0: also returns phase/current_round/courts_announced_at --
 * the SAME structural-signature fields wp_ajax_spp_kq_poll_status
 * returns, read from the exact same spp_kq_get_event_state() call that
 * decided what html to render (spp_kq_render_live_fragment() now
 * returns both together for exactly this reason). This lets
 * swapFragment() update its own lastSignature from whatever it just
 * fetched, regardless of whether poll() or submitAction() triggered
 * it -- see spp_kq_render_live_app()'s own script for the double-
 * announcement bug this closes.
 */
add_action( 'wp_ajax_spp_kq_render_fragment', function() {
    if ( ! spp_kq_can_facilitate() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    if ( ! $occurrence_id ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    $fragment = spp_kq_render_live_fragment( $occurrence_id );
    $state    = $fragment['state'];

    wp_send_json_success( array(
        'html'                => $fragment['html'],
        'phase'               => $state ? $state['phase'] : null,
        'current_round'       => $state ? (int) $state['current_round'] : null,
        'courts_announced_at' => ( $state && $state['courts_announced_at'] !== null ) ? (int) $state['courts_announced_at'] : null,
    ) );
} );

/**
 * Returns the scoreboard fragment (Part A, 1.20.0) -- same access/nonce
 * discipline as wp_ajax_spp_kq_render_fragment just above, and used the
 * same way (fetched by spp_kq_render_live_app()'s own bootstrap script,
 * swapped into #kq-live-app in place of the phase-driven fragment). No
 * phase/current_round/courts_announced_at in the response -- unlike
 * that endpoint, this one is never used to update lastSignature; the
 * scoreboard is a client-side overlay on top of whatever the real
 * structural state is, not itself a structural state. If that real
 * state changes while the scoreboard is showing (a round actually
 * advancing), the outer poll's own unconditional swapFragment() call on
 * a signature mismatch still fires exactly as it would for any other
 * fragment and replaces the scoreboard with the new real screen -- see
 * spp_kq_render_live_app()'s own docblock; deliberately no special-
 * casing to suppress that while viewing the scoreboard.
 */
add_action( 'wp_ajax_spp_kq_render_scoreboard_fragment', function() {
    if ( ! spp_kq_can_facilitate() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    if ( ! $occurrence_id ) {
        wp_send_json_error( 'Missing parameters.' );
    }

    wp_send_json_success( array( 'html' => spp_kq_render_scoreboard_fragment( $occurrence_id ) ) );
} );

/**
 * AJAX front door for the two actions that occur WITHIN the persistent
 * app's own lifetime and must not cause a page reload (Announce
 * Courts, Start Play) -- see spp_kq_render_live_app()'s own docblock
 * for why a reload here would defeat the entire point of this feature
 * (it would wipe SppKqAnnouncer's audioUnlocked/keep-alive state right
 * before the very announcement each of these two actions exists to
 * trigger). Every OTHER action available from within the persistent
 * app's own fragments (End Event, Cancel Event, Full Reset) is
 * deliberately left as a plain POST form causing a real reload/
 * navigation -- each of those legitimately ends or interrupts the
 * announcement sequence anyway, so a reload at that exact point is
 * correct, not a gap (see this file's own 1.14.0 changelog).
 *
 * Reuses spp_kq_handle_post_actions() completely unchanged -- the
 * SAME function every plain-POST action in this feature already goes
 * through, reading spp_kq_action/spp_kq_round straight off $_POST
 * exactly as it always has (the client submits the real, unmodified
 * <form>'s own fields via FormData(form), just routed through fetch()
 * instead of a browser navigation) -- so the CAS-guarded transition
 * functions and their server-side validation are completely
 * untouched; only how the RESULT reaches the client differs (JSON
 * instead of a fresh page render).
 */
add_action( 'wp_ajax_spp_kq_live_action', function() {
    if ( ! spp_kq_can_facilitate() ) {
        wp_send_json_error( 'Not authorized' );
    }
    check_ajax_referer( 'spp_kq_live_action', 'spp_kq_nonce' );

    $occurrence_id = isset( $_POST['occ'] ) ? absint( $_POST['occ'] ) : 0;
    if ( ! $occurrence_id ) {
        wp_send_json_error( 'Missing parameters.' );
    }
    $occurrence = spp_kq_get_occurrence_summary( $occurrence_id );
    if ( ! $occurrence ) {
        wp_send_json_error( 'Occurrence not found.' );
    }

    $notice = spp_kq_handle_post_actions( $occurrence_id, $occurrence['event_date'], $occurrence['eff_event_time'] );

    // Same '' on success / plain text on error convention every plain-
    // POST action already returns -- SPP_KQ_NOTICE_OK_PREFIX is the one
    // opt-in success-text exception (see spp_kq_render_occurrence_
    // header()'s own comment), never applicable to announce_courts/
    // start_play specifically, but checked here for correctness anyway
    // rather than assuming.
    if ( $notice !== '' && ! str_starts_with( $notice, SPP_KQ_NOTICE_OK_PREFIX ) ) {
        wp_send_json_error( $notice );
    }
    wp_send_json_success();
} );
