document.addEventListener("DOMContentLoaded", function () {

    // =====================================================
    // DRAWER SYSTEM
    // =====================================================
    const body    = document.body;
    const html    = document.documentElement;
    const overlay = document.getElementById("spp-mm-overlay");

    const openMain   = document.getElementById("spp-mm-open");
    const closeMain  = document.getElementById("spp-mm-close");

    const openFooter  = document.getElementById("spp-footer-mm-open");
    const closeFooter = document.getElementById("spp-footer-mm-close");

    if (overlay) {
        function closeAll() {
            body.classList.remove("spp-mm-open", "spp-footer-mm-open");
            html.classList.remove("spp-mm-open", "spp-footer-mm-open");
        }

        function toggleMainDrawer() {
            if (body.classList.contains("spp-mm-open")) {
                closeAll();
            } else {
                closeAll();
                body.classList.add("spp-mm-open");
                html.classList.add("spp-mm-open");
            }
        }

        function toggleFooterDrawer() {
            if (body.classList.contains("spp-footer-mm-open")) {
                closeAll();
            } else {
                closeAll();
                body.classList.add("spp-footer-mm-open");
                html.classList.add("spp-footer-mm-open");
            }
        }

        openMain?.addEventListener("click", toggleMainDrawer);
        closeMain?.addEventListener("click", closeAll);

        openFooter?.addEventListener("click", toggleFooterDrawer);
        closeFooter?.addEventListener("click", closeAll);

        overlay.addEventListener("click", closeAll);

        document.addEventListener("click", function(e) {
            if (!body.classList.contains("spp-mm-open") && !body.classList.contains("spp-footer-mm-open")) {
                return;
            }
            const clickedElement = e.target;
            const isDrawer  = clickedElement.closest('#spp-mm-bottom-sheet, #spp-footer-mm-bottom-sheet');
            const isButton  = clickedElement.closest('#spp-mm-drawer');
            const isOverlay = clickedElement.id === 'spp-mm-overlay';

            if (!isDrawer && !isButton && !isOverlay) {
                closeAll();
            }
        });

        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape") closeAll();
        });
    }

    // =====================================================
    // COLLAPSIBLE ACCORDION SIDE NAV
    // - Heading text (if a link) navigates on click
    // - Arrow toggle button expands/collapses section
    // =====================================================
    document.querySelectorAll('nav.spp-side-nav--collapsible').forEach(function(nav) {

        nav.querySelectorAll('.spp-mm-section').forEach(function(section) {
            const heading = section.querySelector('.spp-mm-heading');
            const list    = section.querySelector('.spp-mm-list');

            // Sections without a heading (direct links) stay visible
            if (!heading || !list) return;

            // Create a separate toggle button (arrow)
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

                // Close all sections in this nav (single open)
                nav.querySelectorAll('.spp-mm-section.spp-open').forEach(function(openSection) {
                    openSection.classList.remove('spp-open');
                });

                // Open clicked section if it was closed
                if (!isOpen) {
                    section.classList.add('spp-open');
                }
            });

            // If heading has no link, clicking the heading text also toggles
            const headingLink = heading.querySelector('a');
            if (!headingLink) {
                heading.style.cursor = 'pointer';
                heading.addEventListener('click', function(e) {
                    if (e.target === toggleBtn) return; // already handled
                    const isOpen = section.classList.contains('spp-open');
                    nav.querySelectorAll('.spp-mm-section.spp-open').forEach(function(openSection) {
                        openSection.classList.remove('spp-open');
                    });
                    if (!isOpen) {
                        section.classList.add('spp-open');
                    }
                });
            }
        });
    });

});