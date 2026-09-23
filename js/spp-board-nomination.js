(function () {
    'use strict';
    function init() {
        document.querySelectorAll('.spp-bn-form').forEach(function (form) {
            if (form.dataset.initialized) return;
            form.dataset.initialized = '1';
            var busy = false;
            var status = form.parentElement.querySelector('.spp-bn-status');
            var button = form.querySelector('button[type="submit"]');
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (busy || !form.reportValidity()) return;
                if (!form.elements.mobile.value.trim() && !form.elements.home.value.trim()) {
                    status.textContent = 'Please provide a mobile or home phone number.';
                    form.elements.mobile.focus();
                    return;
                }
                busy = true;
                button.disabled = true;
                status.textContent = 'Submitting nomination…';
                fetch(form.dataset.ajaxUrl, {
                    method: 'POST', credentials: 'same-origin', body: new FormData(form)
                }).then(function (response) {
                    return response.json();
                }).then(function (response) {
                    if (!response || typeof response.success !== 'boolean' || typeof response.data !== 'string') {
                        throw new Error('Unexpected response');
                    }
                    status.textContent = response.data;
                    if (response.success) {
                        form.hidden = true;
                        status.focus();
                    } else {
                        busy = false;
                        button.disabled = false;
                    }
                }).catch(function () {
                    busy = false;
                    button.disabled = false;
                    status.textContent = 'We could not confirm submission. Your answers are still here. Please try again; if this continues, contact board@pickleballstouffville.ca.';
                });
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
