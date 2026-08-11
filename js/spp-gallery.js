/* =========================================================
   SPP GALLERY - carousel autoplay/prev/next
   Ported from fishbucklake/js/gallery.js, fbl- classes/attrs
   renamed to spp-.
   ========================================================= */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.spp-gallery--carousel').forEach(function (carousel) {
        var slides = carousel.querySelectorAll('.spp-gallery-item');
        if (slides.length < 2) return;

        var index = 0;
        var delay = parseInt(carousel.getAttribute('data-spp-autoplay') || '5000', 10);
        var timer = null;

        function show(i) {
            slides.forEach(function (s, idx) {
                s.classList.toggle('is-active', idx === i);
            });
        }

        function next() { index = (index + 1) % slides.length; show(index); }
        function prev() { index = (index - 1 + slides.length) % slides.length; show(index); }

        function start() { stop(); timer = setInterval(next, delay); }
        function stop()  { if (timer) { clearInterval(timer); timer = null; } }

        var prevBtn = carousel.querySelector('.spp-gallery-prev');
        var nextBtn = carousel.querySelector('.spp-gallery-next');

        if (prevBtn) prevBtn.addEventListener('click', function () { prev(); start(); });
        if (nextBtn) nextBtn.addEventListener('click', function () { next(); start(); });

        carousel.addEventListener('mouseenter', stop);
        carousel.addEventListener('mouseleave', start);

        show(0);
        start();
    });
});
