/**
 * SPP Media Curator (scaled down)
 * Admin tool: album-scoped title/caption editing + copy-to-album.
 *
 * Scaled down from fishbucklake/js/media-curator.js: no custom
 * suggestion-dropdown widget (native <datalist> in the markup covers
 * that), no remove-from-folder / switch-album confirmation flow.
 * Everything else — inline field autosave, flag+commit captions,
 * select+copy, hover preview — behaves the same, just against
 * spp_curator_* AJAX actions.
 */
(function () {
    'use strict';

    var cfg = window.SPP_CURATOR || {};
    var els = {};
    var currentAlbum = '';

    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    document.addEventListener('DOMContentLoaded', function () {
        els.source    = $('#spp-curator-source');
        els.count     = $('#spp-curator-count');
        els.actions   = $('.spp-curator-actions');
        els.table     = $('.spp-curator-table');
        els.rows      = $('#spp-curator-rows');
        els.empty     = $('#spp-curator-empty');
        els.target    = $('#spp-curator-target');
        els.status    = $('#spp-curator-status');
        els.copycap   = $('#spp-curator-copycaptions');
        els.batchcopy = $('#spp-curator-batchcopy');
        els.flagall   = $('#spp-curator-flagall');
        els.selectall = $('#spp-curator-selectall');
        els.hover     = $('#spp-curator-hover');

        if (!els.source) return;

        els.source.addEventListener('change', function () { loadAlbum(els.source.value); });
        els.copycap.addEventListener('click', onCopyCaptions);
        els.batchcopy.addEventListener('click', onBatchCopy);
        els.flagall.addEventListener('change', function () { toggleColumn('.spp-curator-flag', els.flagall.checked); });
        els.selectall.addEventListener('change', function () { toggleColumn('.spp-curator-select', els.selectall.checked); });
    });

    function ajax(action, data) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', cfg.nonce);
        Object.keys(data || {}).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) {
                v.forEach(function (item) { body.append(k + '[]', item); });
            } else {
                body.append(k, v);
            }
        });
        return fetch(cfg.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function loadAlbum(album) {
        currentAlbum = album;
        if (els.flagall) els.flagall.checked = false;
        if (els.selectall) els.selectall.checked = false;

        if (!album) {
            els.table.style.display = 'none';
            els.actions.style.display = 'none';
            els.empty.style.display = 'none';
            els.count.textContent = '';
            return;
        }

        els.count.textContent = 'loading…';
        els.rows.innerHTML = '';

        ajax('spp_curator_load', { album: album }).then(function (res) {
            if (!res.success) {
                els.count.textContent = 'error: ' + (res.data || 'load failed');
                return;
            }
            var items = res.data.items || [];
            els.count.textContent = res.data.count + ' image' + (res.data.count === 1 ? '' : 's');
            if (!items.length) {
                els.table.style.display = 'none';
                els.actions.style.display = 'none';
                els.empty.style.display = '';
                return;
            }
            items.forEach(function (it) { els.rows.appendChild(buildRow(it)); });
            els.table.style.display = '';
            els.actions.style.display = '';
            els.empty.style.display = 'none';
        });
    }

    function buildRow(it) {
        var tr = document.createElement('tr');
        tr.dataset.id = it.id;

        var tdImg = document.createElement('td');
        var img = document.createElement('img');
        img.src = it.thumb || '';
        img.alt = '';
        img.className = 'spp-curator-thumb';
        img.title = 'Click to select' + (it.large ? ' — hover for larger preview' : '');
        if (it.large) {
            img.dataset.large = it.large;
            img.dataset.dims = it.dims || '';
            img.addEventListener('mouseenter', showHover);
            img.addEventListener('mousemove', moveHover);
            img.addEventListener('mouseleave', hideHover);
        }
        img.addEventListener('click', function () {
            var cb = $('.spp-curator-select', tr);
            if (!cb) return;
            cb.checked = !cb.checked;
            syncRowSelected(tr);
            if (!cb.checked && els.selectall) els.selectall.checked = false;
        });
        tdImg.appendChild(img);
        var fn = document.createElement('div');
        fn.className = 'spp-curator-fn';
        fn.textContent = it.filename || '';
        tdImg.appendChild(fn);
        tr.appendChild(tdImg);

        tr.appendChild(fieldCell(it.id, 'title', it.title, ''));
        tr.appendChild(fieldCell(it.id, 'caption', it.caption, it.suggestion));

        var tdFlag = document.createElement('td');
        tdFlag.style.textAlign = 'center';
        var flag = document.createElement('input');
        flag.type = 'checkbox';
        flag.className = 'spp-curator-flag';
        tdFlag.appendChild(flag);
        tr.appendChild(tdFlag);

        var tdSel = document.createElement('td');
        tdSel.style.textAlign = 'center';
        var sel = document.createElement('input');
        sel.type = 'checkbox';
        sel.className = 'spp-curator-select';
        sel.addEventListener('change', function () { syncRowSelected(tr); });
        tdSel.appendChild(sel);
        tr.appendChild(tdSel);

        var tdCopy = document.createElement('td');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button button-small spp-curator-rowcopy';
        btn.textContent = 'Copy →';
        btn.addEventListener('click', function () { rowCopy(it.id, tr); });
        tdCopy.appendChild(btn);
        tr.appendChild(tdCopy);

        return tr;
    }

    function syncRowSelected(tr) {
        var cb = $('.spp-curator-select', tr);
        if (!cb) return;
        tr.classList.toggle('is-selected', cb.checked);
    }

    /* ---- hover preview (enlarged image + pixel dimensions) ---- */
    function showHover(e) {
        var large = e.target.dataset.large;
        if (!large || !els.hover) return;
        var dims = e.target.dataset.dims || '';
        var caption = dims
            ? '<div class="spp-curator-hover-dims">' + dims + '</div>'
            : '';
        els.hover.innerHTML = '<img src="' + large + '" alt="">' + caption;
        els.hover.classList.add('is-visible');
        moveHover(e);
    }
    function moveHover(e) {
        if (!els.hover) return;
        var pad = 24;
        var w = 340;
        var x = e.clientX + pad;
        var y = e.clientY + pad;
        if (x + w > window.innerWidth) x = e.clientX - w - pad;
        els.hover.style.left = x + 'px';
        els.hover.style.top  = y + 'px';
    }
    function hideHover() {
        if (!els.hover) return;
        els.hover.classList.remove('is-visible');
        els.hover.innerHTML = '';
    }

    function fieldCell(id, field, value, suggestion) {
        var td = document.createElement('td');
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'spp-curator-field spp-curator-' + field;

        var hasValue = (value !== '' && value !== null && typeof value !== 'undefined');
        if (hasValue) {
            inp.value = value;
            inp.dataset.orig = value;
        } else if (suggestion) {
            inp.value = suggestion;
            inp.dataset.orig = '';
            inp.dataset.suggestion = '1';
            inp.classList.add('is-suggestion');
        } else {
            inp.value = '';
            inp.dataset.orig = '';
        }

        inp.addEventListener('input', function () {
            if (inp.dataset.suggestion) {
                delete inp.dataset.suggestion;
                inp.classList.remove('is-suggestion');
            }
        });

        var save = function () {
            if (inp.dataset.suggestion) return;
            if (inp.value === inp.dataset.orig) return;
            inp.classList.add('is-saving');
            ajax('spp_curator_save', { id: id, field: field, value: inp.value }).then(function (res) {
                inp.classList.remove('is-saving');
                if (res.success) {
                    inp.dataset.orig = inp.value;
                    flash(inp);
                    if (field === 'title') resort();
                } else {
                    inp.classList.add('is-error');
                }
            });
        };

        inp.addEventListener('blur', save);
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
        });
        td.appendChild(inp);
        return td;
    }

    function flash(el) {
        el.classList.add('is-saved');
        setTimeout(function () { el.classList.remove('is-saved'); }, 900);
    }

    function toggleColumn(sel, on) {
        $all(sel, els.rows).forEach(function (cb) {
            cb.checked = on;
            var tr = cb.closest('tr');
            if (tr && cb.classList.contains('spp-curator-select')) syncRowSelected(tr);
        });
    }

    function resort() {
        var rows = $all('tr', els.rows);
        rows.sort(function (a, b) {
            var ta = $('.spp-curator-title', a).value.toLowerCase();
            var tb = $('.spp-curator-title', b).value.toLowerCase();
            return ta < tb ? -1 : (ta > tb ? 1 : 0);
        });
        rows.forEach(function (r) { els.rows.appendChild(r); });
    }

    /* ---- caption commit (flagged rows) ---- */
    function onCopyCaptions() {
        var flagged = $all('tr', els.rows).filter(function (r) {
            return $('.spp-curator-flag', r).checked;
        });
        if (!flagged.length) { els.status.textContent = 'No rows flagged.'; return; }
        var ids = [], vals = [];
        flagged.forEach(function (r) {
            ids.push(r.dataset.id);
            vals.push($('.spp-curator-caption', r).value);
        });
        els.status.textContent = 'committing captions…';
        ajax('spp_curator_copycaptions', { ids: ids, vals: vals }).then(function (res) {
            if (!res.success) { els.status.textContent = 'error: ' + (res.data || 'failed'); return; }
            flagged.forEach(function (r) {
                var cap = $('.spp-curator-caption', r);
                cap.dataset.orig = cap.value;
                delete cap.dataset.suggestion;
                cap.classList.remove('is-suggestion');
                flash(cap);
                $('.spp-curator-flag', r).checked = false;
            });
            if (els.flagall) els.flagall.checked = false;
            els.status.textContent = 'Committed ' + res.data.updated + ' caption(s).';
        });
    }

    /* ---- album copy ---- */
    function validTarget(target) {
        if (!target) {
            els.status.textContent = 'Enter a target album name.';
            return false;
        }
        return true;
    }

    function rowCopy(id, tr) {
        var target = (els.target.value || '').trim();
        if (!validTarget(target)) return;
        var btn = $('.spp-curator-rowcopy', tr);
        btn.disabled = true; btn.textContent = '…';
        ajax('spp_curator_copyalbum', { ids: [id], target: target }).then(function (res) {
            btn.disabled = false;
            if (!res.success) { btn.textContent = 'Copy →'; els.status.textContent = 'error: ' + (res.data || 'failed'); return; }
            btn.textContent = res.data.copied ? '✓ Copied' : '· In album';
            setTimeout(function () { btn.textContent = 'Copy →'; }, 1500);
            els.status.textContent = 'Sent to ' + res.data.target +
                ' (' + res.data.copied + ' copied, ' + res.data.skipped + ' already there).';
        });
    }

    function onBatchCopy() {
        var target = (els.target.value || '').trim();
        if (!validTarget(target)) return;
        var selected = $all('tr', els.rows).filter(function (r) {
            return $('.spp-curator-select', r).checked;
        });
        if (!selected.length) { els.status.textContent = 'No rows selected.'; return; }
        var ids = selected.map(function (r) { return r.dataset.id; });
        els.batchcopy.disabled = true;
        els.status.textContent = 'copying ' + ids.length + ' image(s) to ' + target + '…';
        ajax('spp_curator_copyalbum', { ids: ids, target: target }).then(function (res) {
            els.batchcopy.disabled = false;
            if (!res.success) { els.status.textContent = 'error: ' + (res.data || 'failed'); return; }
            selected.forEach(function (r) {
                $('.spp-curator-select', r).checked = false;
                syncRowSelected(r);
            });
            if (els.selectall) els.selectall.checked = false;
            els.status.textContent = 'Copied ' + res.data.copied + ' to ' + res.data.target +
                ' (' + res.data.skipped + ' already there).';
        });
    }
})();
