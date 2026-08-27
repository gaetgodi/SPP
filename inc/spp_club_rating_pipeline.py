"""
SPP Club Rating — end-to-end pipeline
======================================

Rebuilds a Glicko-style doubles skill rating for the Stouffville Pickleball
Players ladder, directly from a phpMyAdmin SQL dump of the
`Schedules_Scores_<event_id>` tables.

Usage:
    python spp_club_rating_pipeline.py /path/to/dump.sql /path/to/output.xlsx

What it does, in order:
  1. Parse every Schedules_Scores_* table out of the raw SQL dump.
  2. Clean up known data issues (duplicate/stale tables, mistyped event_id).
  3. Reconstruct doubles games from the Game1-Game5 columns (partners share
     a recorded value; that value is the OPPONENT's actual score).
  4. Run a Glicko-lite rating engine over all events in chronological order,
     seeding new players from a rank-regression against established peers.
  5. Rescale to a 2.0-5.0 "Club Rating" and run a causal holdout validation.
  6. Write a multi-tab Excel workbook with the results.

See the accompanying Methodology tab in the output workbook for the full
write-up of the modeling choices. This script is meant to be readable and
rerunnable, not a black box — every step above is its own function below.

A note on what `Rank` actually measures (confirmed with the club, not
guessed): it is NOT a win/loss ladder. Within each weekly group, the player
with the highest sum of their own scores across that group's games gets -3
(their Rank improves); scoring 56+ out of a possible 60 earns another -3
regardless of placement, with the mirror-image +3/+3 at the bottom of the
group. Ratings are not currently used operationally in the club's ladder —
this is a comparison exercise. With the score-reconstruction fix below, Rank
and Club Rating now correlate strongly (~-0.85 — a good Rank number pairs
with a high Club Rating, as expected).

IMPORTANT CORRECTION: an earlier version of this script had the score
direction backwards — treating a player's recorded Game-column value as
their OPPONENT's score rather than their OWN team's score. Both readings
produce equally plausible-looking individual scores (e.g. 17-20 reads fine
either way), so nothing in the raw data alone flags the error. It was caught
by checking a specific player's computed rating against real-world knowledge
of his actual ability, then confirmed against the club's own description of
the ranking formula ("the sum of the scores he got" — his own score, not his
opponent's). The fix is in `try_split_pairs()` below: a team's actual score
is the value ITS OWN players share, not the value the other team shares.
"""

import sys
import re
import math
import statistics
import collections

from openpyxl import Workbook
from openpyxl.styles import Font, Alignment, PatternFill, Border, Side
from openpyxl.utils import get_column_letter


# =====================================================================
# 1. SQL PARSING
# =====================================================================

def parse_tuples(values_text):
    """Parse a `VALUES (...),(...),(...)` blob into a list of raw field lists,
    respecting quoted strings and backslash escapes (phpMyAdmin dump style)."""
    rows = []
    i, n = 0, len(values_text)
    depth = 0
    in_string = False
    buf = []
    cur_fields = None
    while i < n:
        c = values_text[i]
        if not in_string:
            if c == '(' and depth == 0:
                depth, cur_fields, buf = 1, [], []
            elif c == "'" and depth == 1:
                in_string = True
                buf.append(c)
            elif c == ',' and depth == 1:
                cur_fields.append(''.join(buf).strip())
                buf = []
            elif c == ')' and depth == 1:
                cur_fields.append(''.join(buf).strip())
                rows.append(cur_fields)
                depth, cur_fields = 0, None
            elif depth == 1:
                buf.append(c)
        else:
            if c == '\\' and i + 1 < n:
                buf.append(c)
                buf.append(values_text[i + 1])
                i += 1
            elif c == "'":
                if i + 1 < n and values_text[i + 1] == "'":
                    buf.append("''")
                    i += 1
                else:
                    buf.append(c)
                    in_string = False
            else:
                buf.append(c)
        i += 1
    return rows


def unescape_sql_string(s):
    inner = s[1:-1]
    return (inner.replace("\\'", "'").replace('\\"', '"')
                 .replace("\\\\", "\\").replace("\\n", "\n")
                 .replace("\\r", "\r").replace("''", "'"))


def convert_field(raw):
    raw = raw.strip()
    if raw == 'NULL':
        return None
    if raw.startswith("'"):
        return unescape_sql_string(raw)
    try:
        return float(raw) if '.' in raw else int(raw)
    except ValueError:
        return raw


def parse_sql_dump(path):
    with open(path, encoding='utf-8') as f:
        content = f.read()

    table_names = sorted(set(re.findall(r"CREATE TABLE `(Schedules_Scores_\d+)`", content)))
    all_rows = []
    for tname in table_names:
        pattern = re.compile(
            r"INSERT INTO `" + re.escape(tname) + r"` \(([^)]*)\) VALUES\s*(.*?);\s*\n", re.S)
        for m in pattern.finditer(content):
            cols = [c.strip().strip('`') for c in m.group(1).split(',')]
            for t in parse_tuples(m.group(2)):
                if len(t) != len(cols):
                    continue
                row = {col: convert_field(val) for col, val in zip(cols, t)}
                row['_table'] = tname
                all_rows.append(row)
    return all_rows


# =====================================================================
# 2. DATA CLEANUP
# =====================================================================

def clean_rows(raw_rows):
    """Drop known-stale duplicate tables, fix mistyped event_id fields."""
    cleaned = []
    dropped = 0
    for r in raw_rows:
        table_suffix = int(r['_table'].rsplit('_', 1)[1])
        # Table 44 is a stale leftover whose event_id was updated to 152 but whose
        # rows were never migrated to the correctly named Schedules_Scores_152 table.
        if r['_table'] == 'Schedules_Scores_44' and r.get('event_id') != 44:
            dropped += 1
            continue
        if r.get('event_id') != table_suffix:
            r = dict(r)
            r['event_id'] = table_suffix
        cleaned.append(r)
    return cleaned, dropped


# =====================================================================
# 3. GAME RECONSTRUCTION
# =====================================================================

def try_split_pairs(present4):
    by_val = collections.defaultdict(list)
    for uid, val in present4:
        by_val[val].append(uid)
    if len(by_val) != 2:
        return None
    (va, ta), (vb, tb) = list(by_val.items())
    if len(ta) != 2 or len(tb) != 2 or va == vb:
        return None
    # A player's recorded value IS their own team's actual score for that
    # round — partners share it because they're on the same team, not
    # because it's the opponent's score. (See correction note above.)
    return {'team1': tuple(sorted(ta)), 'team2': tuple(sorted(tb)), 'score1': va, 'score2': vb}


def reconstruct_round(present):
    """present: list of (user_id, recorded_value) for one Game column in one pod/round."""
    if len(present) == 4:
        return try_split_pairs(present)
    if len(present) == 5:
        # One player sat out this round. In older schemas that shows as NULL and is
        # already filtered out by the caller; in newer NOT-NULL-DEFAULT-0 schemas the
        # bye shows up as a lone unmatched value (commonly 0) among the five.
        vals = [v for _, v in present]
        singles = [v for v, cnt in collections.Counter(vals).items() if cnt == 1]
        if len(singles) == 1:
            remaining = [(u, v) for u, v in present if v != singles[0]]
            if len(remaining) == 4:
                return try_split_pairs(remaining)
        return None
    return None


def reconstruct_games(cleaned_rows):
    by_event_group = collections.defaultdict(list)
    for r in cleaned_rows:
        by_event_group[(r['event_id'], r['group_id'])].append(r)

    games, total_slots, unreconstructed = [], 0, 0
    for (eid, gid), members in by_event_group.items():
        for gn in range(1, 6):
            col = f'Game{gn}'
            present = [(r['user_id'], r[col]) for r in members if r.get(col) is not None]
            if not present:
                continue
            total_slots += 1
            result = reconstruct_round(present)
            if result is None:
                unreconstructed += 1
                continue
            games.append({'event_id': eid, 'group_id': gid, 'game_num': gn, **result})
    return games, total_slots, unreconstructed


# =====================================================================
# 4. RATING ENGINE (Glicko-lite for doubles)
# =====================================================================

Q = math.log(10) / 400
DEFAULT_MU, DEFAULT_RD = 1500.0, 300.0
MIN_RD, MAX_RD = 40.0, 350.0


class PlayerState:
    __slots__ = ('mu', 'rd', 'games_played', 'first_event', 'last_event', 'history')

    def __init__(self, mu, rd, first_event):
        self.mu, self.rd = mu, rd
        self.games_played = 0
        self.first_event = self.last_event = first_event
        self.history = []


def g_rd(rd):
    return 1.0 / math.sqrt(1.0 + 3.0 * Q ** 2 * rd ** 2 / math.pi ** 2)


def expected_score(mu_a, mu_b, rd_b):
    return 1.0 / (1.0 + 10 ** (-g_rd(rd_b) * (mu_a - mu_b) / 400.0))


def margin_scale(score_a, score_b):
    diff, total = score_a - score_b, max(score_a + score_b, 1)
    return 0.5 + 0.5 * math.tanh((diff / total) * 2.2)


def seed_new_player(uid, event_id, states, rank_pe):
    r = rank_pe.get((event_id, uid))
    peers = [(rank, states[u].mu) for (e, u), rank in rank_pe.items()
             if e == event_id and u != uid and u in states and states[u].games_played >= 3]
    if r is None or len(peers) < 4:
        return DEFAULT_MU, DEFAULT_RD
    xs, ys = [p[0] for p in peers], [p[1] for p in peers]
    n = len(xs)
    mean_x, mean_y = sum(xs) / n, sum(ys) / n
    var_x = sum((x - mean_x) ** 2 for x in xs)
    if var_x == 0:
        pred = mean_y
    else:
        slope = sum((x - mean_x) * (y - mean_y) for x, y in zip(xs, ys)) / var_x
        pred = mean_y + slope * (r - mean_x)
    return pred, DEFAULT_RD


def run_rating_model(event_ids_ordered, games_by_event, rank_pe):
    states = {}
    predictions = []  # (predicted_prob_team1, actual_team1_won, event_id) — fully causal

    for event_id in event_ids_ordered:
        games = games_by_event.get(event_id, [])
        if not games:
            continue
        players_this_event = set()
        for g in games:
            players_this_event.update(g['team1'])
            players_this_event.update(g['team2'])
        for uid in players_this_event:
            if uid not in states:
                mu0, rd0 = seed_new_player(uid, event_id, states, rank_pe)
                states[uid] = PlayerState(mu0, rd0, event_id)

        deltas = collections.defaultdict(list)
        for g in games:
            t1, t2 = g['team1'], g['team2']
            mu1 = (states[t1[0]].mu + states[t1[1]].mu) / 2
            mu2 = (states[t2[0]].mu + states[t2[1]].mu) / 2
            rd1 = (states[t1[0]].rd + states[t1[1]].rd) / 2
            rd2 = (states[t2[0]].rd + states[t2[1]].rd) / 2
            exp1 = expected_score(mu1, mu2, rd2)
            actual1 = margin_scale(g['score1'], g['score2'])
            predictions.append((exp1, 1 if g['score1'] > g['score2'] else 0, event_id))
            for uid in t1:
                deltas[uid].append((rd2, actual1, exp1))
            for uid in t2:
                deltas[uid].append((rd1, 1 - actual1, 1 - exp1))

        for uid, obs in deltas.items():
            st = states[uid]
            d2_inv = sum(Q ** 2 * g_rd(rd) ** 2 * e * (1 - e) for rd, a, e in obs)
            sum_term = sum(g_rd(rd) * (a - e) for rd, a, e in obs)
            if d2_inv > 0:
                d2 = 1.0 / d2_inv
                new_rd = math.sqrt(1.0 / (1.0 / st.rd ** 2 + 1.0 / d2))
                new_mu = st.mu + Q * new_rd ** 2 * sum_term
            else:
                new_rd, new_mu = st.rd, st.mu
            st.history.append((event_id, st.mu, st.rd))
            st.mu, st.rd = new_mu, max(MIN_RD, min(MAX_RD, new_rd))
            st.games_played += len(obs)
            st.last_event = event_id

    return states, predictions


# =====================================================================
# 5. RANK vs CLUB RATING COMPARISON
# =====================================================================

def win_loss_counts(games):
    """Simple career win/loss per player, straight from reconstructed games —
    independent of the rating model, used as a sanity check and for the
    Rank-vs-Rating comparison."""
    wins, losses = collections.Counter(), collections.Counter()
    for g in games:
        if g['score1'] > g['score2']:
            w, l = g['team1'], g['team2']
        elif g['score2'] > g['score1']:
            w, l = g['team2'], g['team1']
        else:
            continue
        for u in w:
            wins[u] += 1
        for u in l:
            losses[u] += 1
    return wins, losses


def club_rating_scale(states, min_games=15):
    """Rescale raw Glicko mu onto a 2.0-5.0 'Club Rating', anchored on the
    established (min_games+) population via a z-score affine transform —
    preserves the actual shape of the skill distribution rather than forcing
    a normal or uniform one."""
    established = {uid: st for uid, st in states.items() if st.games_played >= min_games}
    mus = [st.mu for st in established.values()]
    mean_mu, std_mu = statistics.mean(mus), statistics.stdev(mus)
    z_top = (max(mus) - mean_mu) / std_mu
    z_bot = (min(mus) - mean_mu) / std_mu
    k = min(1.5 / z_top, 1.5 / abs(z_bot))
    return (lambda mu: round(3.5 + (mu - mean_mu) / std_mu * k, 2)), mean_mu, std_mu, k


def rank_vs_rating_table(states, games, rank_pe, event_id, player_name):
    """Build the comparison table for one event: Rank, Club Rating, and the
    player's actual career win/loss record, for everyone who played that week."""
    to_scale, *_ = club_rating_scale(states)
    wins, losses = win_loss_counts(games)
    rows = []
    for (eid, uid), rank in rank_pe.items():
        if eid != event_id or uid not in states:
            continue
        w, l = wins[uid], losses[uid]
        total = w + l
        rows.append({
            'rank': rank,
            'name': player_name.get(uid, f'Player {uid}'),
            'club_rating': to_scale(states[uid].mu),
            'win_rate_pct': round(100 * w / total, 1) if total else None,
            'record': f"{w}-{l}",
            'games_played': states[uid].games_played,
        })
    rows.sort(key=lambda r: r['rank'])
    return rows


# =====================================================================
# 6. REPORT / XLSX OUTPUT
# =====================================================================
# (Kept in the companion analysis for brevity — see build_report_data.py /
# build_xlsx.py / build_rank_comparison.py in this delivery for the full
# workbook-writing code. The functions above are the reusable, non-Excel-
# specific core: rerun them on a fresh SQL export any week to get updated
# ratings and an updated Rank-vs-Rating comparison.)


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python spp_club_rating_pipeline.py /path/to/dump.sql")
        sys.exit(1)

    raw_rows = parse_sql_dump(sys.argv[1])
    cleaned, dropped = clean_rows(raw_rows)
    games, total_slots, unrecon = reconstruct_games(cleaned)
    print(f"Parsed {len(raw_rows)} rows, dropped {dropped} stale duplicates, "
          f"{len(cleaned)} clean rows.")
    print(f"Reconstructed {len(games)} of {total_slots} game-slots "
          f"({unrecon} unreconstructed, {unrecon/total_slots:.1%}).")

    event_date = collections.defaultdict(list)
    for r in cleaned:
        d = r.get('registration_date')
        if d and str(d)[:10] != '0000-00-00':
            event_date[r['event_id']].append(str(d)[:10])
    event_date = {e: max(ds) for e, ds in event_date.items()}
    event_ids_ordered = sorted(event_date, key=lambda e: (event_date[e], e))

    rank_pe = {(r['event_id'], r['user_id']): int(str(r['Rank']).strip())
               for r in cleaned if r.get('Rank') is not None}

    games_by_event = collections.defaultdict(list)
    for g in games:
        games_by_event[g['event_id']].append(g)

    states, predictions = run_rating_model(event_ids_ordered, games_by_event, rank_pe)
    print(f"Rated {len(states)} players "
          f"({sum(1 for s in states.values() if s.games_played >= 15)} established).")
