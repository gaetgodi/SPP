/**
 * SPP Registrant List - AJAX dropdown handler
 * File: js/spp-registrant-list.js
 * Version: 2.0.0
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
