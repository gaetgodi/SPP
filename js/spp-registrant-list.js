/**
 * SPP Registrant List - AJAX dropdown handler with CSV export
 * File: js/spp-registrant-list.js
 * Version: 2.2.0
 */

jQuery(document).ready(function ($) {

    var $select  = $('#spp-event-select');
    var $results = $('#spp-registrant-results');

    if ( ! $select.length ) return;

    $select.on('change', function () {
        var event_id    = $(this).val();
        var event_title = $('option:selected', this).data('title') || '';
        var event_date  = $('option:selected', this).data('date')  || '';

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
                } else {
                    $results.html('<p class="spp-registrant-error">Could not load registrants.</p>');
                }
            }
        );
    });

});

// CSV export - builds from rendered table and triggers download
function sppExportCSV() {
    var table = document.querySelector('.spp-registrant-table');
    if ( ! table ) return;

    var title = '';
    var titleEl = document.querySelector('.spp-registrant-title');
    var dateEl  = document.querySelector('.spp-registrant-date');
    if ( titleEl ) title = titleEl.innerText;
    if ( dateEl )  title += ' - ' + dateEl.innerText;

    var rows = [];

    // Header row
    rows.push( ['#', 'Name', 'Email', 'Phone'] );

    // Data rows
    var trs = table.querySelectorAll('tbody tr');
    trs.forEach(function(tr) {
        var cells = tr.querySelectorAll('td');
        if ( cells.length < 4 ) return;
        rows.push([
            cells[0].innerText.trim(),
            cells[1].innerText.trim(),
            cells[2].innerText.trim(),
            cells[3].innerText.trim()
        ]);
    });

    // Build CSV string
    var csv = rows.map(function(row) {
        return row.map(function(cell) {
            // Wrap in quotes, escape internal quotes
            return '"' + cell.replace(/"/g, '""') + '"';
        }).join(',');
    }).join('\n');

    // Trigger download
    var filename = title
        ? title.replace(/[^a-z0-9 _-]/gi, '').replace(/\s+/g, '_') + '.csv'
        : 'registrants.csv';

    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}