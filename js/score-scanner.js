/* Score Scanner -- SPP Ladder
   Version: 1.1
   Changes from 1.0:
   - Duplicate rank detection: rows with the same rank highlighted
     in red with a warning, convenor must delete one before saving.
*/

async function sppScanScores() {
    var fileInput = document.getElementById('spp-score-file');
    if (!fileInput.files.length) {
        sppStatus('Please select a file to upload.', 'error');
        return;
    }
    document.getElementById('spp-loading').style.display = 'block';
    document.getElementById('spp-status').innerHTML = '';
    document.getElementById('spp-review').innerHTML = '';

    var formData = new FormData();
    for (var i = 0; i < fileInput.files.length; i++) {
        formData.append('files[]', fileInput.files[i]);
    }
    formData.append('action', 'spp_scan_scores');
    formData.append('nonce', sppScanner.nonce);
    formData.append('event_id', sppScanner.event);

    try {
        var resp = await fetch(sppScanner.ajaxurl, { method: 'POST', body: formData });
        var data = await resp.json();
        document.getElementById('spp-loading').style.display = 'none';
        if (!data.success) { sppStatus('Error: ' + data.data, 'error'); return; }
        sppRenderReview(data.data);
    } catch (e) {
        document.getElementById('spp-loading').style.display = 'none';
        sppStatus('Network error: ' + e.message, 'error');
    }
}

function sppRenderReview(data) {
    var players  = data.players;
    var warnings = data.warnings || [];

    if (!players || players.length === 0) {
        sppStatus('No scores found in the uploaded file.', 'error');
        return;
    }

    // ── Duplicate detection ───────────────────────────────
    // Flag players appearing in more than one group only.
    // Must match on both rank AND last name -- multiple players
    // can legitimately share the same rank number.
    var duplicateKeys = {};
    var duplicateWarnings = [];

    // Build map: rank+lastname => list of groups
    var rankNameGroups = {};
    players.forEach(function(p) {
        var nameParts = p.name.replace(/^P-/, '').trim().split(' ');
        var lastName  = nameParts[nameParts.length - 1].toLowerCase();
        var key       = p.rank + '|' + lastName;
        if (!rankNameGroups[key]) rankNameGroups[key] = { groups: [], name: p.name };
        if (rankNameGroups[key].groups.indexOf(p.group) === -1) {
            rankNameGroups[key].groups.push(p.group);
        }
    });

    // Flag rank+name combos that appear in more than one group
    players.forEach(function(p) {
        var nameParts = p.name.replace(/^P-/, '').trim().split(' ');
        var lastName  = nameParts[nameParts.length - 1].toLowerCase();
        var key       = p.rank + '|' + lastName;
        if (rankNameGroups[key].groups.length > 1) {
            var dupKey = 'dup|' + key;
            if (!duplicateKeys[dupKey]) {
                duplicateKeys[dupKey] = true;
                duplicateWarnings.push('DUPLICATE: ' + p.name + ' (rank ' + p.rank + ') appears in multiple groups (' + rankNameGroups[key].groups.join(', ') + ') -- delete the incorrect row before saving.');
            }
        }
    });

    warnings = duplicateWarnings.concat(warnings);

    sppStatus('Found ' + players.length + ' player entries. Review scores below, then click Save.', 'info');

    var html = '';
    if (warnings.length) {
        html += '<div class="spp-status spp-status-error"><strong>Warnings:</strong><ul>';
        warnings.forEach(function(w) { html += '<li>' + w + '</li>'; });
        html += '</ul></div>';
    }

    html += '<table class="spp-review-table">';
    html += '<thead><tr><th>Group</th><th>Crt</th><th>Rank</th><th>Name</th><th>Rnd1</th><th>Rnd2</th><th>Rnd3</th><th>Rnd4</th><th>Rnd5</th><th>Total</th><th>Note</th><th>Del</th></tr></thead><tbody>';

    var lastGroup = '';
    players.forEach(function(p, i) {
        if (p.group !== lastGroup) {
            html += '<tr class="spp-group-header"><td colspan="12">' + p.group + ' - ' + p.court + '</td></tr>';
            lastGroup = p.group;
        }
        // Determine if this row is a duplicate (cross-group, same name)
        var nameParts2  = p.name.replace(/^P-/, '').trim().split(' ');
        var lastName2   = nameParts2[nameParts2.length - 1].toLowerCase();
        var isDuplicate = duplicateKeys['dup|' + p.rank + '|' + lastName2];
        var rowClass = isDuplicate ? 'spp-duplicate-row' : (p.substitution ? 'spp-sub-note' : '');
        var note     = isDuplicate ? 'DUPLICATE' : (p.substitution ? 'Sub' : (p.warning ? p.warning : ''));
        var total    = [p.rnd1, p.rnd2, p.rnd3, p.rnd4, p.rnd5]
            .filter(function(v) { return v !== null && v !== 'bye' && v !== '' && v !== 'x' && v !== 'xx'; })
            .reduce(function(a, b) { return a + (parseInt(b) || 0); }, 0);
        var fmt = function(v) { return v === 'bye' ? 'bye' : (v === null || v === '' ? '' : v); };

        html += '<tr class="' + rowClass + '" data-idx="' + i + '" id="spp-row-' + i + '">' +
            '<td>' + p.group + '</td><td>' + p.court + '</td><td>' + p.rank + '</td><td>' + p.name + '</td>' +
            '<td><input class="spp-score-input" data-field="rnd1" data-idx="' + i + '" value="' + fmt(p.rnd1) + '"></td>' +
            '<td><input class="spp-score-input" data-field="rnd2" data-idx="' + i + '" value="' + fmt(p.rnd2) + '"></td>' +
            '<td><input class="spp-score-input" data-field="rnd3" data-idx="' + i + '" value="' + fmt(p.rnd3) + '"></td>' +
            '<td><input class="spp-score-input" data-field="rnd4" data-idx="' + i + '" value="' + fmt(p.rnd4) + '"></td>' +
            '<td><input class="spp-score-input" data-field="rnd5" data-idx="' + i + '" value="' + fmt(p.rnd5) + '"></td>' +
            '<td>' + total + '</td><td>' + note + '</td>' +
            '<td><button class="spp-btn spp-btn-red" style="padding:2px 8px;font-size:11px;" onclick="sppDeleteRow(' + i + ')">X</button></td>' +
            '</tr>';
    });

    html += '</tbody></table>';
    html += '<br><button class="spp-btn spp-btn-green" onclick="sppSaveScores()">Save Scores to Database</button>';
    html += ' <button class="spp-btn spp-btn-red" onclick="sppCancelReview()">Cancel</button>';

    window.sppPlayers = players;
    document.getElementById('spp-review').innerHTML = html;

    // Add CSS for duplicate rows if not already present
    if (!document.getElementById('spp-scanner-styles')) {
        var style = document.createElement('style');
        style.id = 'spp-scanner-styles';
        style.textContent = '.spp-duplicate-row { background: #fde8e8 !important; border-left: 4px solid #c0392b !important; }';
        document.head.appendChild(style);
    }

    document.querySelectorAll('.spp-score-input').forEach(function(inp) {
        inp.addEventListener('change', function() {
            window.sppPlayers[parseInt(this.dataset.idx)][this.dataset.field] = this.value;
        });
    });
}

function sppDeleteRow(idx) {
    var row = document.getElementById('spp-row-' + idx);
    if (row) row.remove();
    // Mark player as deleted so it is skipped on save
    if (window.sppPlayers && window.sppPlayers[idx]) {
        window.sppPlayers[idx]._deleted = true;
    }
}

async function sppSaveScores() {
    if (!window.sppPlayers) return;
    document.querySelectorAll('.spp-score-input').forEach(function(inp) {
        window.sppPlayers[parseInt(inp.dataset.idx)][inp.dataset.field] = inp.value;
    });

    // Filter out deleted rows
    var playersToSave = window.sppPlayers.filter(function(p) { return !p._deleted; });

    sppStatus('Saving scores...', 'info');

    var resp = await fetch(sppScanner.ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'spp_save_scores',
            nonce: sppScanner.nonce,
            event_id: sppScanner.event,
            players: JSON.stringify(playersToSave)
        })
    });

    var data = await resp.json();
    if (data.success) {
        var msg = 'Scores saved! ' + data.data.saved + ' players updated.';
        if (data.data.errors && data.data.errors.length) {
            msg += ' Errors: ' + data.data.errors.join(', ');
        }
        sppStatus(msg, 'success');
        document.getElementById('spp-review').innerHTML = '';
        window.sppPlayers = null;
    } else {
        sppStatus('Error saving: ' + data.data, 'error');
    }
}

async function sppClearScores() {
    if (!confirm('Clear ALL scores? This cannot be undone.')) return;
    var resp = await fetch(sppScanner.ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'spp_clear_scores',
            nonce: sppScanner.nonce
        })
    });
    var data = await resp.json();
    if (data.success) sppStatus('All scores cleared.', 'success');
    else sppStatus('Error: ' + data.data, 'error');
}

function sppCancelReview() {
    document.getElementById('spp-review').innerHTML = '';
    document.getElementById('spp-status').innerHTML = '';
    window.sppPlayers = null;
}

function sppStatus(msg, type) {
    document.getElementById('spp-status').innerHTML =
        '<div class="spp-status spp-status-' + type + '">' + msg + '</div>';
}