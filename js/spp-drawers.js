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
