document.addEventListener("DOMContentLoaded", function () {

    // =====================================================
    // SINGLE DRAWER SYSTEM
    // =====================================================
    const body    = document.body;
    const html    = document.documentElement;
    const overlay = document.getElementById("spp-mm-overlay");
    const openBtn = document.getElementById("spp-mm-open");
    const closeBtn = document.getElementById("spp-mm-close");

    if (!overlay) return;

    function closeAll() {
        body.classList.remove("spp-mm-open");
        html.classList.remove("spp-mm-open");
    }

    function toggleDrawer() {
        if (body.classList.contains("spp-mm-open")) {
            closeAll();
        } else {
            body.classList.add("spp-mm-open");
            html.classList.add("spp-mm-open");
        }
    }

    openBtn?.addEventListener("click", toggleDrawer);
    closeBtn?.addEventListener("click", closeAll);
    overlay.addEventListener("click", closeAll);

    document.addEventListener("click", function(e) {
        if (!body.classList.contains("spp-mm-open")) return;
        const el = e.target;
        if (!el.closest('#spp-mm-bottom-sheet') &&
            !el.closest('#spp-mm-open') &&
            el.id !== 'spp-mm-overlay') {
            closeAll();
        }
    });

    document.addEventListener("keydown", function(e) {
        if (e.key === "Escape") closeAll();
    });

    // =====================================================
    // COLLAPSIBLE ACCORDION SIDE NAV
    // - All sections closed by default
    // - Heading link navigates, arrow button toggles
    // - spp-always-open sections stay open always
    // =====================================================
    document.querySelectorAll('nav.spp-side-nav--collapsible').forEach(function(nav) {

        nav.querySelectorAll('.spp-mm-section').forEach(function(section) {
            const heading = section.querySelector('.spp-mm-heading');
            const list    = section.querySelector('.spp-mm-list');

            if (!heading || !list) return;

            // Create toggle button (arrow)
            const toggleBtn = document.createElement('button');
            toggleBtn.className = 'spp-accordion-toggle';
            toggleBtn.setAttribute('aria-label', 'Toggle section');
            toggleBtn.innerHTML = '&#9658;'; // ▶
            heading.appendChild(toggleBtn);

            // Toggle button click — expand/collapse only
            toggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const isOpen = section.classList.contains('spp-open');

                // Close all non-always-open sections
                nav.querySelectorAll('.spp-mm-section.spp-open:not(.spp-always-open)').forEach(function(openSection) {
                    openSection.classList.remove('spp-open');
                });

                // Open clicked section if it was closed
                if (!isOpen && !section.classList.contains('spp-always-open')) {
                    section.classList.add('spp-open');
                }
            });

            // If heading has no link, clicking heading text also toggles
            const headingLink = heading.querySelector('a');
            if (!headingLink) {
                heading.style.cursor = 'pointer';
                heading.addEventListener('click', function(e) {
                    if (e.target === toggleBtn) return;
                    const isOpen = section.classList.contains('spp-open');
                    nav.querySelectorAll('.spp-mm-section.spp-open:not(.spp-always-open)').forEach(function(openSection) {
                        openSection.classList.remove('spp-open');
                    });
                    if (!isOpen && !section.classList.contains('spp-always-open')) {
                        section.classList.add('spp-open');
                    }
                });
            }
        });
    });

});

// =====================================================
// WPDA TABLE SCROLL MANAGEMENT
// - Adds mirrored top scrollbar
// - Hides bottom scrollbar when content fits
// =====================================================
(function() {

    function addTopScroll(container) {
        if (container.dataset.topScrollAdded) return;
        container.dataset.topScrollAdded = '1';

        var topScroll = document.createElement('div');
        topScroll.style.cssText = 'overflow-x:auto;overflow-y:hidden;height:12px;margin-bottom:4px;';
        var inner = document.createElement('div');
        inner.style.height = '1px';
        topScroll.appendChild(inner);
        container.parentNode.insertBefore(topScroll, container);

        function syncWidth() {
            var table = container.querySelector('table');
            var tableWidth = table ? table.offsetWidth : container.scrollWidth;
            inner.style.width = tableWidth + 'px';
            topScroll.style.display = tableWidth > container.clientWidth ? 'block' : 'none';
        }
        syncWidth();
        setTimeout(syncWidth, 500);
        setTimeout(syncWidth, 1500);

        topScroll.addEventListener('scroll', function() { container.scrollLeft = topScroll.scrollLeft; });
        container.addEventListener('scroll', function() { topScroll.scrollLeft = container.scrollLeft; });
        window.addEventListener('resize', syncWidth);
    }

    function manageBottomScroll(container) {
        if (container.dataset.bottomScrollManaged) return;
        container.dataset.bottomScrollManaged = '1';
    
        var resizeTimer;
        function check() {
            var table = container.querySelector('table');
            var tableWidth = table ? table.offsetWidth : 0;
            if (tableWidth <= container.clientWidth - 20) {
                container.parentElement.classList.add('spp-no-hscroll');
            } else {
                container.parentElement.classList.remove('spp-no-hscroll');
            }
        }
        check();
        setTimeout(check, 500);
        setTimeout(check, 1500);
    
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(check, 150);
        });
    }

    var observer = new MutationObserver(function() {
        document.querySelectorAll('.MuiTableContainer-root').forEach(function(el) {
            addTopScroll(el);
            manageBottomScroll(el);
        });
    });

    observer.observe(document.body, { childList: true, subtree: true });

})();