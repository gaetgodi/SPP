/**
 * SPP Registrant List - AJAX handler with rich editor, BCC, group send, filters, CSV
 * File: js/spp-registrant-list.js
 * Version: 2.7.0
 *
 * Changes from 2.6.2:
 *   - sppGroupChanged() — shows filter panel and loads group on selection
 *   - sppApplyGroupFilter() — reads filter values and reloads group list
 *   - sppClearGroupFilter() — resets filters and reloads group list
 *   - Ladder filter shown only for members group, hidden for ladder group
 */

jQuery(document).ready(function ($) {

    // ── Event dropdown ────────────────────────────────────
    var $select  = $('#spp-event-select');
    var $results = $('#spp-registrant-results');

    if ( $select.length ) {
        $select.on('change', function () {
            var event_id    = $(this).val();
            var event_title = $('option:selected', this).data('title') || '';
            var event_date  = $('option:selected', this).data('date')  || '';

            // Clear group section and close all compose forms
            $('#spp-group-results').html('');
            $('#spp-group-filters').hide();
            $('#spp-group-select').val('');
            $('.spp-compose-form').slideUp(200);

            if ( ! event_id ) {
                $results.html('');
                return;
            }

            $results.html('<p class="spp-registrant-loading">Loading...</p>');

            $.post(
                sppRL.ajaxUrl,
                {
                    action:      'spp_get_registrants',
                    event_id:    event_id,
                    event_title: event_title,
                    event_date:  event_date
                },
                function (response) {
                    if ( response.success ) {
                        $results.html( response.data.html );
                        sppInitPlaceholders();
                    } else {
                        $results.html('<p class="spp-registrant-error">Could not load registrants.</p>');
                    }
                }
            );
        });
    }

});

// ============================================================
// Group dropdown changed — show filters and load group
// ============================================================
function sppGroupChanged(group) {
    // Clear event section and close all compose forms
    jQuery('#spp-registrant-results').html('');
    jQuery('#spp-event-select').val('');
    jQuery('.spp-compose-form').slideUp(200);

    var $filters = jQuery('#spp-group-filters');
    var $results = jQuery('#spp-group-results');

    if ( ! group ) {
        $results.html('');
        $filters.slideUp(200);
        return;
    }

    // Show/hide ladder filter depending on group
    if ( group === 'ladder' ) {
        jQuery('#spp-ladder-filter-wrap').hide();
        jQuery('#spp-filter-ladder').val('');
    } else {
        jQuery('#spp-ladder-filter-wrap').show();
    }

    // Reset filters when switching groups
    jQuery('#spp-filter-rating').val('');
    jQuery('#spp-filter-ladder').val('');

    $filters.slideDown(200);
    sppLoadGroup(group);
}

// ============================================================
// Apply filters and reload group
// ============================================================
function sppApplyGroupFilter() {
    var group = jQuery('#spp-group-select').val();
    if ( ! group ) return;
    sppLoadGroup(group);
}

// ============================================================
// Clear filters and reload group
// ============================================================
function sppClearGroupFilter() {
    jQuery('#spp-filter-rating').val('');
    jQuery('#spp-filter-ladder').val('');
    var group = jQuery('#spp-group-select').val();
    if ( group ) sppLoadGroup(group);
}

// ============================================================
// Load group email list with current filter values
// ============================================================
function sppLoadGroup(group) {
    var $results = jQuery('#spp-group-results');
    var rating   = jQuery('#spp-filter-rating').val() || '';
    var ladder   = jQuery('#spp-filter-ladder').val() || '';

    $results.html('<p class="spp-registrant-loading">Loading...</p>');

    jQuery.post(
        sppRL.ajaxUrl,
        {
            action: 'spp_get_group_emails',
            group:  group,
            rating: rating,
            ladder: ladder
        },
        function(response) {
            if ( response.success ) {
                $results.html( response.data.html );
                sppInitPlaceholders();
            } else {
                $results.html('<p class="spp-registrant-error">Could not load group.</p>');
            }
        }
    );
}

// ============================================================
// Show inline compose form
// ============================================================
function sppShowCompose(type, emails, defaultSubject) {
    // Close all other open compose forms
    jQuery('.spp-compose-form').not('#spp-compose-' + type).slideUp(200);

    // Clear the opposite section's results
    if ( type.startsWith('group_') ) {
        jQuery('#spp-registrant-results').html('');
        jQuery('#spp-event-select').val('');
    } else {
        jQuery('#spp-group-results').html('');
        jQuery('#spp-group-select').val('');
        jQuery('#spp-group-filters').slideUp(200);
    }

    var $form    = jQuery('#spp-compose-' + type);
    var $subject = jQuery('#spp-subject-' + type);
    var $bcc     = jQuery('#spp-bcc-' + type);
    var $message = jQuery('#spp-message-' + type);
    var $status  = jQuery('#spp-send-status-' + type);

    $bcc.val( emails.join(', ') );
    $subject.val(defaultSubject);
    $message.html('');
    $status.html('');

    $form.slideDown(200);
    $message.focus();
}

// ============================================================
// Hide inline compose form
// ============================================================
function sppHideCompose(type) {
    jQuery('#spp-compose-' + type).slideUp(200);
}

// ============================================================
// Rich text formatting
// ============================================================
function sppFormat(command) {
    document.execCommand(command, false, null);
}

function sppInsertLink(type) {
    var url = prompt('Enter URL:', 'https://');
    if ( url ) {
        document.execCommand('createLink', false, url);
    }
}

// ============================================================
// Placeholder behaviour for contenteditable divs
// ============================================================
function sppInitPlaceholders() {
    jQuery('.spp-compose-message').each(function() {
        var $el = jQuery(this);
        var placeholder = $el.data('placeholder') || 'Type your message here...';

        $el.off('focus blur').on('focus', function() {
            if ( $el.hasClass('spp-placeholder') ) {
                $el.html('');
                $el.removeClass('spp-placeholder');
            }
        }).on('blur', function() {
            if ( $el.text().trim() === '' ) {
                $el.html(placeholder);
                $el.addClass('spp-placeholder');
            }
        });

        if ( $el.text().trim() === '' ) {
            $el.html(placeholder);
            $el.addClass('spp-placeholder');
        }
    });
}

// ============================================================
// Send email via AJAX → wp_mail → Fluent SMTP → Brevo
// ============================================================
function sppSendEmail(type) {
    var $subject = jQuery('#spp-subject-' + type);
    var $bcc     = jQuery('#spp-bcc-' + type);
    var $message = jQuery('#spp-message-' + type);
    var $status  = jQuery('#spp-send-status-' + type);
    var $sendBtn = jQuery('#spp-compose-' + type + ' .spp-send-btn');

    var subject = $subject.val().trim();
    var bcc     = $bcc.val().trim();
    var message = $message.html().trim();

    if ( $message.hasClass('spp-placeholder') ) message = '';

    if ( ! subject ) { $status.html('<span style="color:#c0392b;">Please enter a subject.</span>'); return; }
    if ( ! message ) { $status.html('<span style="color:#c0392b;">Please enter a message.</span>'); return; }
    if ( ! bcc )     { $status.html('<span style="color:#c0392b;">No recipients in BCC field.</span>'); return; }

    var recipientCount = bcc.split(/[\s,]+/).filter(function(e) { return e.length > 0; }).length;

    $sendBtn.prop('disabled', true).text('Sending...');
    $status.html('<span style="color:#666;">Sending to ' + recipientCount + ' recipient' + (recipientCount !== 1 ? 's' : '') + '...</span>');

    jQuery.post(
        sppRL.ajaxUrl,
        { action: 'spp_send_registrant_email', subject: subject, message: message, bcc: bcc },
        function(response) {
            $sendBtn.prop('disabled', false).html('&#9993; Send Now');
            if ( response.success ) {
                $status.html('<span style="color:#27ae60;">' + response.data.message + '</span>');
                setTimeout(function() { sppHideCompose(type); }, 3000);
            } else {
                $status.html('<span style="color:#c0392b;">Error: ' + (response.data || 'Send failed.') + '</span>');
            }
        }
    ).fail(function() {
        $sendBtn.prop('disabled', false).html('&#9993; Send Now');
        $status.html('<span style="color:#c0392b;">Server error — please try again.</span>');
    });
}

// ============================================================
// CSV export
// ============================================================
function sppExportCSV() {
    var table = document.querySelector('.spp-registrant-table');
    if ( ! table ) return;

    var title = '';
    var titleEl = document.querySelector('.spp-registrant-title');
    var dateEl  = document.querySelector('.spp-registrant-date');
    if ( titleEl ) title = titleEl.innerText;
    if ( dateEl )  title += ' - ' + dateEl.innerText;

    var rows = [];
    var headers = [];
    var ths = table.querySelectorAll('thead th');
    ths.forEach(function(th) { headers.push(th.innerText.trim()); });
    rows.push(headers);

    var trs = table.querySelectorAll('tbody tr');
    trs.forEach(function(tr) {
        var cells = tr.querySelectorAll('td');
        var row = [];
        cells.forEach(function(td) { row.push(td.innerText.trim()); });
        if ( row.length > 0 ) rows.push(row);
    });

    var csv = rows.map(function(row) {
        return row.map(function(cell) {
            return '"' + cell.replace(/"/g, '""') + '"';
        }).join(',');
    }).join('\n');

    var filename = title
        ? title.replace(/[^a-z0-9 _-]/gi, '').replace(/\s+/g, '_') + '.csv'
        : 'registrants.csv';

    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}