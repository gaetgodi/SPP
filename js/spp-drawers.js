document.addEventListener("DOMContentLoaded", function () {

    const body    = document.body;
    const html    = document.documentElement;
    const overlay = document.getElementById("spp-mm-overlay");

    const openMain   = document.getElementById("spp-mm-open");
    const closeMain  = document.getElementById("spp-mm-close");

    const openFooter  = document.getElementById("spp-footer-mm-open");
    const closeFooter = document.getElementById("spp-footer-mm-close");

    if (!overlay) return;

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
        const isDrawer = clickedElement.closest('#spp-mm-bottom-sheet, #spp-footer-mm-bottom-sheet');
        const isButton = clickedElement.closest('#spp-mm-drawer');
        const isOverlay = clickedElement.id === 'spp-mm-overlay';

        if (!isDrawer && !isButton && !isOverlay) {
            closeAll();
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") closeAll();
    });
});
