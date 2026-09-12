# Stouffville Pickleball Players — Site Feature Overview

*pickleballstouffville.ca — a member-run WordPress site serving roughly 150 players. Live since 2016.*

This document describes what the site can **do** today, organized by feature area. It's a capability overview, not a technical spec.

---

## 1. The Ladder System

The core of the site: a recurring weekly event with skill-based group assignment and a persistent player ranking.

- **Registration** — players sign up for the week's session through the site's events plugin.
- **Automatic schedule building** — an algorithm reads that week's registrants and builds balanced groups across courts and time slots, taking into account each player's skill rank, carpool pairings, and travel-time preferences, then runs several balancing passes to keep groups fair.
- **Publishing** — once a schedule is finalized, it's published and personalized group/court/time emails go out to every player, with a trial-send option so an admin can preview the emails before the real send.
- **Player-facing schedule view** — players see their own group, court, and time for the week.
- **Mid-event adjustments** — convenors can handle dropouts, last-minute walk-ins, and player swaps *during* an event, with a propose → confirm → notify flow so a change can be safely undone if something goes wrong before it's finalized.
- **Schedule validation** — an on-demand diagnostic check flags carpool conflicts, travel-time conflicts, and other scheduling issues, either right after a schedule is built or any time after a manual edit.
- **Score entry & correction** (see Section 5) feed directly into ranking.
- **Results & the ranking ladder** — after each event, results are compiled and every player's ladder rank is updated. A "shadow" calculation lets the club test changes to the ranking formula against real historical data before it ever touches live rankings.
- **Club Rating** — a continuous 2.0–5.0 rating powered by a Glicko rating system, the same family of rating math used in chess and other competitive games. It weighs real game outcomes together with a built-in "how confident are we in this number yet" measure that narrows as more results come in, then rescales the result to the club's own 2.0–5.0 display number — a friendlier, more comparable figure than raw ladder rank alone.
- **Rank history** — every player can see a narrative history of their own rank changes over their last several events; ladder admins can look up anyone's history.
- **Membership & tag management** — an admin-facing table of all members with editable ratings (DUPR rating validated against its real 2.0–8.0 scale, kept separate from Club Rating's own scale) and custom tags.
- **Player-management tools** — admin tools to change a new player's starting rank, copy computed ranks onto player profiles, remove inactive players from the ladder (with a review step before anything is removed), and swap two players between groups mid-schedule with automatic notification.
- **Score Scanner** — an AI-assisted tool that reads photographed/scanned paper score sheets (used as the physical backup for in-person score recording) and automatically extracts and validates the scores, cross-checking for tied/misread values before they're saved.
- **Printable score sheets** — colour-coded, per-group blank score sheets and a colour-coded results/review grid, generated straight from the current or a past event's schedule.

## 2. Photo Gallery

A player photo-sharing system tied to the club's events.

- **Submit a photo** — any logged-in member can submit a photo from their phone: pick the event it's from (recurring series, a specific one-off event, or general "Photo Book" for casual play), confirm the date (auto-suggested from the photo itself when available), and crop/rotate/adjust brightness right in the browser before submitting.
- **Moderation queue** — designated photo moderators approve or reject submissions before they go public; moderators are emailed automatically when a new photo comes in.
- **Public gallery** — approved photos display in a masonry-style gallery, organized by event, with a full-screen lightbox viewer.
- **Browse by event or by year** — a dedicated browse page lets any member pick an event from a dropdown, or view "All Photos" pooled across an entire season, showing only events/years that actually have approved photos.
- **Separately, an admin-curated gallery system** — the site also has a general-purpose album gallery (grid, carousel, or masonry layouts, shuffled/autoplay options) used for curated event albums and bulk photo imports, with an admin tool to bulk-edit titles/captions and copy photos between albums.

## 3. Reporting System

A flexible, reusable reporting engine used throughout the ladder and membership tools.

- **Built-in reports** — ladder ratings, full membership list, master player list, and a results/rankings report with an inline-editable ranking override column (restricted to admins/editors).
- **Saved report variants** — any report's exact configuration (which columns show, sort behavior, rows-per-page) can be saved as a named, reusable "variant" and reloaded later — the same report can be presented multiple different ways for different audiences without rebuilding it each time.
- **Live visual style customization** — a Report Generator admin screen lets an admin visually restyle any report's table (colors, borders, corner radius, margins, font size, cell padding, background) with a live preview, save the resulting custom CSS as part of a variant, and reset any property back to its default individually.
- **Inline cell editing** — specific report columns (like the ranking override) can be edited directly in the table by authorized users, with changes saved immediately and securely.
- **Access-controlled by design** — every report requires login, and reports carrying sensitive internal data enforce an admin/editor-only view regardless of how the report is embedded.

## 4. Blog

A member blogging system with moderation built in.

- **Submit a post** — any active member can write and submit a blog post, with a full rich-text editor (formatting, colors, etc.) right on the front end of the site.
- **Categorize & set expiry** — posts can be assigned to multiple categories and given an expiry date.
- **Moderation queue** — designated moderators review, edit, publish, or reject pending posts before they go live; moderators are notified automatically of new submissions.
- **Edit after publishing** — moderators/admins can edit any post, including a shortcut straight into the full editor from the review queue.
- **New-post reminder** — logged-in members see a friendly reminder banner on the homepage when there's a new post they haven't seen yet, once per day or whenever fresh content is published.
- **Automatic author permissions** — every currently active member automatically gets blogging permission for as long as their membership is active; a separate, manually-assigned moderator role handles review duties.

## 5. Score Entry

The system players use to record their own game results.

- **Mobile-friendly self-service entry** — any player in a group can enter that group's scores from their phone: tap the losing team, enter their score, and the system fills in everyone else's result automatically (fixed winning score by group size).
- **Live only while the schedule is published** — entry is only available once that week's schedule has gone live, and only for the group a player is actually scheduled into (admins/editors can enter on behalf of any group).
- **Most-recent-entry-wins** — if there's a discrepancy, the latest submitted entry is treated as authoritative going into scoring.
- **Paper backup + AI scanning** — printed score sheets remain the verification layer, and the Score Scanner (Section 1) can read them back in automatically.
- **Score correction** — an admin-only tool can correct scores after results have already been published, automatically recalculating group rankings and ladder ranks to match the correction.

## 6. Ace/Queen of the Courts — Live Event Runner

A purpose-built live, in-person tournament runner for the club's "Ace of the Courts" and "Queen of the Courts" event formats — a self-contained round-by-round court ladder that plays out over a single event.

- **Court draw** — at the start of the event, players tap to claim their own starting court/team slot on their phone, first-come-first-served, with real-time updates as slots fill.
- **Round-by-round movement** — after each round, winning pairs move up a court and losing pairs move down (top and bottom courts have nowhere further to go), following the "Ace/Queen of the Courts" format exactly.
- **Smart partner rotation** — when four players land on the same court together, the system prefers whichever pairing of partners hasn't played together yet that day, avoiding repeat partnerships unless unavoidable.
- **Live score entry per court** — during play, each court's players enter their own score on their phone; a live status line shows the facilitator how many courts have reported in, refreshing automatically without needing to reload.
- **Facilitator controls** — any logged-in member can help run the event (start the draw, start play, end or cancel the event) — no special role or registration is required, matching the informal, anyone-can-help nature of these events.
- **Full event lifecycle** — from "not started" through the draw, live play, round-by-round advancement, and a final "Complete" screen naming the event's ultimate Aces-court winner — plus a graceful cancellation path mid-event that records which courts' results were kept versus discarded.
- **Not yet connected to Club Rating** — every game's score is fully recorded, but nothing currently feeds those results into the club's Glicko-based Club Rating system (Section 1) — that integration hasn't been built yet, so Ace/Queen results don't affect a player's Club Rating today.

## 7. Other Member-Facing Features

- **Passkey login** — members can log in and manage their account using device-based passkeys (Face ID, fingerprint, security key) instead of a password, with a self-service "My Passkeys" management panel.
- **Custom avatars** — members can take/upload a profile photo directly from the browser, including camera capture and background replacement, without leaving the site.
- **Hints & strategies** — a categorized library of pickleball tips and strategy articles.
- **Downloadable club documents** — the club constitution, instructor certification subsidy form, and the Pickleball Canada skills self-assessment are all viewable directly on the site.
- **Personalized navigation** — menus and dashboard adapt to who's logged in and what role they hold, showing scheduling/scoring tools only once relevant (e.g., score entry only appears once a group's game time has arrived).
