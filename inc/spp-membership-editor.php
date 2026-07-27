<?php
/* =========================================================
   SPP Membership Editor
   Version: 1.0.0
   Date: 2026-07-13

   Replaces UM form 1433 on the /members/ page.

   - Admin/Editor (spp_is_admin_or_editor()):
     full sortable, searchable table of every Master-list
     member, any field inline-editable for any member.
   - Everyone else logged in: their own row only, same
     inline-edit mechanism, no table, no visibility into
     other members' data.

   Roster (who appears) comes from the Master table.
   Field VALUES are read live from usermeta, not Master,
   so edits made here show up immediately regardless of
   when the separate Master-sync (on membership-list view)
   next runs. Writes go to usermeta only -- Master stays in
   sync via that existing mechanism.

   Editable fields, whitelisted both client- and server-side:
     first_name, last_name -> usermeta first_name/last_name
     Phone Number          -> usermeta user_phone
     Mobile Number         -> usermeta user_mobile
     Rating                -> usermeta Rating (fixed 8-value list)
     Travel                -> usermeta Travel (free text + datalist
                              of existing distinct values)
   ========================================================= */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'spp_membership_editor', 'spp_membership_editor_shortcode' );

function spp_membership_editor_shortcode() {
    ob_start();
    spp_membership_editor_render();
    return ob_get_clean();
}

function spp_membership_editor_ratings() {
    return array( 'Beginner', '2.5', '3.0', '3.5', '4.0', '4.5', '5.0', 'Professional' );
}

function spp_membership_editor_render() {
    global $wpdb;

    if ( ! is_user_logged_in() ) {
        echo '<p>Please log in to view or edit your membership profile.</p>';
        return;
    }

    $is_admin     = spp_is_admin_or_editor();
    $current_uid  = get_current_user_id();

    $roster = $wpdb->get_col( "SELECT user_id FROM Master ORDER BY user_id" );

    if ( ! $is_admin ) {
        if ( ! in_array( $current_uid, array_map( 'intval', $roster ), true ) ) {
            echo '<p>You are not currently on the ladder Master list.</p>';
            return;
        }
        $roster = array( $current_uid );
    }

    $meta_keys = array(
        'first_name'  => 'first_name',
        'last_name'   => 'last_name',
        'user_phone'  => 'user_phone',
        'user_mobile' => 'user_mobile',
        'rating'      => 'Rating',
        'travel'      => 'Travel',
    );

    $rows = array();
    foreach ( $roster as $uid ) {
        $uid = (int) $uid;
        $row = array( 'uid' => $uid );
        foreach ( $meta_keys as $field => $meta_key ) {
            $row[ $field ] = get_user_meta( $uid, $meta_key, true );
        }
        $rows[] = $row;
    }

    usort( $rows, function( $a, $b ) {
        return strcasecmp( $a['last_name'] . $a['first_name'], $b['last_name'] . $b['first_name'] );
    } );

    $ratings = spp_membership_editor_ratings();

    $travel_options = array();
    if ( $is_admin ) {
        $prefix = $wpdb->prefix;
        $travel_options = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$prefix}usermeta
             WHERE meta_key = 'Travel' AND meta_value != ''
             ORDER BY meta_value"
        );
    } else {
        $own_travel = $rows[0]['travel'] ?? '';
        if ( $own_travel ) $travel_options[] = $own_travel;
    }

    $nonce = wp_create_nonce( 'spp_membership_editor' );
    ?>
    <style>
.um-account { padding: 0 20px !important; box-sizing: border-box !important; }
        .mem-editor { font-family: Arial, sans-serif; font-size: 14px; color: #333; }
        .mem-editor input[type=text], .mem-editor input[list], .mem-editor select {
            padding: 3px 6px; font-size: 14px; border: 1px solid #3766AB; border-radius: 3px; width: 100%; box-sizing: border-box;
        }
        .mem-search { margin-bottom: 12px; }
        .mem-search input { padding: 6px 10px; font-size: 14px; width: 260px; border: 1px solid #ccc; border-radius: 4px; }
        .mem-table { border-collapse: collapse; width: 100%; }
        .mem-table th, .mem-table td { padding: 6px 10px; border-bottom: 1px solid #eee; text-align: left; }
        @media (max-width: 600px) {
            .mem-editor { font-size: 12px; }
            .mem-table th, .mem-table td { padding: 1px !important; font-size: 12px; }
            .mem-cell { min-width: 20px; }
            .mem-search input { width: 100%; max-width: 220px; }
        }
        .mem-table th, .mem-table th a, .mem-table th:visited {
            background: #3766AB; color: #ffffff !important; cursor: pointer; user-select: none; white-space: nowrap;
        }
        .mem-suggest {
            position: absolute; z-index: 9999; background: #fff; border: 1px solid #3766AB;
            border-radius: 0 0 4px 4px; max-height: 200px; overflow-y: auto; box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .mem-suggest-item { padding: 6px 10px; cursor: pointer; font-size: 14px; }
        .mem-suggest-item:hover { background: #fff3cd; }
        .mem-table th.sorted-asc::after  { content: " \25B2"; }
        .mem-table th.sorted-desc::after { content: " \25BC"; }
        .mem-table tr:nth-child(even) { background: #f9f9f9; }
        .mem-cell { cursor: pointer; display: inline-block; min-width: 60px; min-height: 18px; }
        .mem-cell:hover { background: #fff3cd; }
        .mem-cell.saving { opacity: 0.5; }
        .mem-cell.error { background: #fdf3f2; color: #c0392b; }
        .mem-profile-row { display: flex; padding: 8px 0; border-bottom: 1px solid #eee; }
        .mem-profile-label { width: 160px; font-weight: bold; color: #555; }
        .mem-msg { margin-top: 10px; font-size: 13px; }
        .mem-msg.ok  { color: #2a7a2a; }
        .mem-msg.err { color: #c0392b; }
    </style>

    <div class="mem-editor">
        <?php if ( $is_admin ): ?>
            <div class="mem-search">
                <input type="text" id="mem_search" placeholder="Search by name...">
            </div>
            <table class="mem-table" id="mem_table">
                <thead>
                    <tr>
                        <th data-sort="first_name">First Name</th>
                        <th data-sort="last_name">Last Name</th>
                        <th data-sort="user_phone">Phone Number</th>
                        <th data-sort="user_mobile">Mobile Number</th>
                        <th data-sort="rating">Rating</th>
                        <th data-sort="travel">Travel</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ): ?>
                    <tr data-first="<?php echo esc_attr( strtolower( $row['first_name'] ) ); ?>"
                        data-last="<?php echo esc_attr( strtolower( $row['last_name'] ) ); ?>">
                        <?php foreach ( array_keys( $meta_keys ) as $field ): ?>
                        <td data-sortval="<?php echo esc_attr( strtolower( $row[ $field ] ) ); ?>">
                            <span class="mem-cell" data-uid="<?php echo (int) $row['uid']; ?>" data-field="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $row[ $field ] ); ?></span>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <?php $row = $rows[0]; $labels = array( 'first_name' => 'First Name', 'last_name' => 'Last Name', 'user_phone' => 'Phone Number', 'user_mobile' => 'Mobile Number', 'rating' => 'Rating', 'travel' => 'Travel' ); ?>
            <?php foreach ( $labels as $field => $label ): ?>
            <div class="mem-profile-row">
                <div class="mem-profile-label"><?php echo esc_html( $label ); ?></div>
                <div><span class="mem-cell" data-uid="<?php echo (int) $row['uid']; ?>" data-field="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $row[ $field ] ); ?></span></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="mem-msg" id="mem_msg"></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var ajaxurl  = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        var nonce    = '<?php echo esc_js( $nonce ); ?>';
        var ratings  = <?php echo wp_json_encode( $ratings ); ?>;
        var travelOpts = <?php echo wp_json_encode( array_values( $travel_options ) ); ?>;
        var msg      = document.getElementById('mem_msg');

        function showMsg(text, ok) {
            msg.textContent = text;
            msg.className = 'mem-msg ' + (ok ? 'ok' : 'err');
            setTimeout(function(){ msg.textContent = ''; msg.className = 'mem-msg'; }, 4000);
        }

        function saveField(cell, value) {
            cell.classList.add('saving');
            var data = new FormData();
            data.append('action', 'spp_save_membership_field');
            data.append('nonce', nonce);
            data.append('user_id', cell.getAttribute('data-uid'));
            data.append('field', cell.getAttribute('data-field'));
            data.append('value', value);

            fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    cell.classList.remove('saving');
                    if (res.success) {
                        cell.textContent = res.data.value;
                        var tr = cell.closest('tr');
                        if (tr) {
                            var td = cell.closest('td');
                            if (td) td.setAttribute('data-sortval', res.data.value.toLowerCase());
                            if (cell.getAttribute('data-field') === 'first_name') tr.setAttribute('data-first', res.data.value.toLowerCase());
                            if (cell.getAttribute('data-field') === 'last_name')  tr.setAttribute('data-last', res.data.value.toLowerCase());
                        }
                        showMsg('Saved.', true);
                    } else {
                        cell.classList.add('error');
                        showMsg(res.data && res.data.message ? res.data.message : 'Save failed.', false);
                    }
                })
                .catch(function() {
                    cell.classList.remove('saving');
                    cell.classList.add('error');
                    showMsg('Save failed -- network error.', false);
                });
        }

        function startEdit(cell) {
            if (cell.querySelector('input, select')) return; // already editing
            var field    = cell.getAttribute('data-field');
            var original = cell.textContent;
            var input;

            if (field === 'rating') {
                input = document.createElement('select');
                var blank = document.createElement('option');
                blank.value = ''; blank.textContent = '-- select --';
                input.appendChild(blank);
                ratings.forEach(function(r) {
                    var opt = document.createElement('option');
                    opt.value = r; opt.textContent = r;
                    if (r === original) opt.selected = true;
                    input.appendChild(opt);
                });
            } else if (field === 'travel') {
                input = document.createElement('input');
                input.type = 'text';
                input.value = original;
                input.autocomplete = 'off';
            } else {
                input = document.createElement('input');
                input.type = 'text';
                input.value = original;
            }

            cell.textContent = '';
            cell.appendChild(input);
            input.focus();
            if (input.select) input.select();

            var suggestBox = null;
            if (field === 'travel' && travelOpts.length) {
                suggestBox = document.createElement('div');
                suggestBox.className = 'mem-suggest';
                document.body.appendChild(suggestBox);

                function positionSuggest() {
                    var r = input.getBoundingClientRect();
                    suggestBox.style.left  = (r.left + window.scrollX) + 'px';
                    suggestBox.style.top   = (r.bottom + window.scrollY) + 'px';
                    suggestBox.style.width = Math.max(r.width, 140) + 'px';
                }

                function renderSuggest() {
                    var q = input.value.toLowerCase();
                    var matches = travelOpts.filter(function(o) { return o.toLowerCase().indexOf(q) !== -1; });
                    if (!matches.length) { suggestBox.style.display = 'none'; return; }
                    suggestBox.innerHTML = '';
                    matches.forEach(function(o) {
                        var item = document.createElement('div');
                        item.className = 'mem-suggest-item';
                        item.textContent = o;
                        item.addEventListener('mousedown', function(e) {
                            e.preventDefault();
                            input.value = o;
                            suggestBox.style.display = 'none';
                            finish(true);
                        });
                        suggestBox.appendChild(item);
                    });
                    positionSuggest();
                    suggestBox.style.display = 'block';
                }

                input.addEventListener('focus', renderSuggest);
                input.addEventListener('input', renderSuggest);
                renderSuggest();
            }

            var finished = false;
            function finish(save) {
                if (finished) return;
                finished = true;
                var newVal = input.value;
                cell.removeChild(input);
                if (suggestBox && suggestBox.parentNode) suggestBox.parentNode.removeChild(suggestBox);
                if (save && newVal !== original) {
                    cell.textContent = newVal;
                    saveField(cell, newVal);
                } else {
                    cell.textContent = original;
                }
            }

            input.addEventListener('blur', function() {
                setTimeout(function() { finish(true); }, 150);
            });
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); finish(true); }
                if (e.key === 'Escape') { e.preventDefault(); finish(false); }
            });
            if (input.tagName === 'SELECT') {
                input.addEventListener('change', function() { finish(true); });
            }
        }

        document.querySelectorAll('.mem-cell').forEach(function(cell) {
            cell.addEventListener('click', function() { startEdit(cell); });
        });

        var searchBox = document.getElementById('mem_search');
        if (searchBox) {
            searchBox.addEventListener('keyup', function() {
                var q = this.value.toLowerCase();
                document.querySelectorAll('#mem_table tbody tr').forEach(function(tr) {
                    var match = tr.getAttribute('data-first').indexOf(q) !== -1 ||
                                tr.getAttribute('data-last').indexOf(q) !== -1;
                    tr.style.display = match ? '' : 'none';
                });
            });
        }

        var table = document.getElementById('mem_table');
        if (table) {
            var sortDir = {};
            table.querySelectorAll('th[data-sort]').forEach(function(th, colIndex) {
                th.addEventListener('click', function() {
                    var key = th.getAttribute('data-sort');
                    var dir = sortDir[key] === 'asc' ? 'desc' : 'asc';
                    sortDir = {}; sortDir[key] = dir;

                    table.querySelectorAll('th').forEach(function(h) { h.classList.remove('sorted-asc', 'sorted-desc'); });
                    th.classList.add(dir === 'asc' ? 'sorted-asc' : 'sorted-desc');

                    var tbody = table.querySelector('tbody');
                    var rowsArr = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                    rowsArr.sort(function(a, b) {
                        var av = a.children[colIndex].getAttribute('data-sortval');
                        var bv = b.children[colIndex].getAttribute('data-sortval');
                        var cmp = av < bv ? -1 : (av > bv ? 1 : 0);
                        return dir === 'asc' ? cmp : -cmp;
                    });
                    rowsArr.forEach(function(tr) { tbody.appendChild(tr); });
                });
            });
        }
    });
    </script>
    <?php
}

add_action( 'wp_ajax_spp_save_membership_field', function() {

    if ( ! check_ajax_referer( 'spp_membership_editor', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please reload the page.' ) );
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Not logged in.' ) );
    }

    global $wpdb;

    $target_uid = (int) ( $_POST['user_id'] ?? 0 );
    $field      = sanitize_key( $_POST['field'] ?? '' );
    $value      = wp_unslash( $_POST['value'] ?? '' );

    $meta_keys = array(
        'first_name'  => 'first_name',
        'last_name'   => 'last_name',
        'user_phone'  => 'user_phone',
        'user_mobile' => 'user_mobile',
        'rating'      => 'Rating',
        'travel'      => 'Travel',
    );

    if ( ! $target_uid || ! isset( $meta_keys[ $field ] ) ) {
        wp_send_json_error( array( 'message' => 'Invalid field.' ) );
    }

    $is_admin = spp_is_admin_or_editor();

    if ( ! $is_admin && $target_uid !== get_current_user_id() ) {
        wp_send_json_error( array( 'message' => 'You can only edit your own profile.' ) );
    }

    $in_master = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM Master WHERE user_id = %d", $target_uid
    ) );
    if ( ! $in_master ) {
        wp_send_json_error( array( 'message' => 'That member is not on the Master list.' ) );
    }

    if ( $field === 'rating' ) {
        if ( $value !== '' && ! in_array( $value, spp_membership_editor_ratings(), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid rating value.' ) );
        }
    } else {
        $value = sanitize_text_field( $value );
    }

    update_user_meta( $target_uid, $meta_keys[ $field ], $value );

    wp_send_json_success( array( 'value' => $value ) );
} );