<?php
/**
 * SPP Blog Reminder Modal
 * File: spp-blog-reminder.php
 * Location: wp-content/themes/divi-spp-child/inc/
 *
 * Shortcode: [spp_blog_reminder]
 *
 * Shows a dismissible modal on the home page on first login of the day.
 * Displays the 3 most recent blog posts with links, plus a link to /blog/.
 * Uses a cookie (spp_blog_seen) set to today's date to prevent repeat shows.
 *
 * Usage: Add [spp_blog_reminder] to a Divi Code module on the home page.
 *
 * Version: 1.0.0
 * Date: 2026-05-22
 */

add_shortcode( 'spp_blog_reminder', 'spp_blog_reminder_shortcode' );
function spp_blog_reminder_shortcode() {
    if ( ! is_user_logged_in() ) return '';

    // Get 3 most recent published posts
    $posts = get_posts( array(
        'numberposts' => 3,
        'post_status' => 'publish',
        'post_type'   => 'post',
    ) );

    if ( empty( $posts ) ) return '';

    $all_blogs_url = home_url( '/blog/' );
    $today         = date( 'Y-m-d' );

    ob_start();
    ?>
    <div id="spp-blog-modal-overlay" class="spp-blog-overlay">
        <div class="spp-blog-modal">
            <div class="spp-blog-modal-header">
                <span class="spp-blog-modal-icon">&#128240;</span>
                <h3>Latest from the SPP Blog</h3>
                <button id="spp-blog-dismiss" class="spp-blog-close" aria-label="Close">&times;</button>
            </div>
            <div class="spp-blog-modal-body">
                <p class="spp-blog-intro">Stay informed — here are the latest posts from your fellow members:</p>
                <ul class="spp-blog-list">
                    <?php foreach ( $posts as $post ): ?>
                    <li class="spp-blog-item">
                        <a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" class="spp-blog-link">
                            <?php echo esc_html( $post->post_title ); ?>
                        </a>
                        <span class="spp-blog-date"><?php echo date( 'F j, Y', strtotime( $post->post_date ) ); ?></span>
                        <?php if ( $post->post_excerpt ): ?>
                        <p class="spp-blog-excerpt"><?php echo esc_html( $post->post_excerpt ); ?></p>
                        <?php else: ?>
                        <p class="spp-blog-excerpt"><?php echo esc_html( wp_trim_words( $post->post_content, 20, '...' ) ); ?></p>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="spp-blog-modal-footer">
                <a href="<?php echo esc_url( $all_blogs_url ); ?>" class="spp-blog-all-link">View All Blog Posts &rarr;</a>
                <button id="spp-blog-dismiss-btn" class="spp-blog-dismiss-btn">Got it, thanks!</button>
            </div>
        </div>
    </div>

    <style>
        .spp-blog-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .spp-blog-overlay.spp-hidden { display: none; }
        .spp-blog-modal {
            background: #fff;
            border-radius: 10px;
            max-width: 520px;
            width: 92%;
            box-shadow: 0 8px 32px rgba(0,0,0,0.22);
            font-family: 'Open Sans', Arial, sans-serif;
            overflow: hidden;
        }
        .spp-blog-modal-header {
            background: var(--spp-primary, #00897B);
            color: #fff;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .spp-blog-modal-header h3 {
            margin: 0;
            flex: 1;
            font-size: 1.1rem;
            color: #fff;
        }
        .spp-blog-modal-icon { font-size: 1.4rem; }
        .spp-blog-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.6rem;
            cursor: pointer;
            line-height: 1;
            padding: 0 4px;
        }
        .spp-blog-close:hover { opacity: 0.7; }
        .spp-blog-modal-body { padding: 20px 24px 8px; }
        .spp-blog-intro {
            margin: 0 0 14px;
            color: #555;
            font-size: 0.95rem;
        }
        .spp-blog-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .spp-blog-item {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .spp-blog-item:last-child { border-bottom: none; }
        .spp-blog-link {
            font-weight: bold;
            color: var(--spp-primary, #00897B);
            text-decoration: none;
            font-size: 1rem;
            display: block;
        }
        .spp-blog-link:hover { text-decoration: underline; }
        .spp-blog-date {
            font-size: 0.8rem;
            color: #999;
            display: block;
            margin: 2px 0 4px;
        }
        .spp-blog-excerpt {
            margin: 0;
            font-size: 0.88rem;
            color: #666;
            line-height: 1.5;
        }
        .spp-blog-modal-footer {
            padding: 14px 24px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid #eee;
            background: #f9f9f9;
        }
        .spp-blog-all-link {
            color: var(--spp-link, #00897B);
            font-size: 0.95rem;
            text-decoration: none;
            font-weight: bold;
        }
        .spp-blog-all-link:hover { text-decoration: underline; }
        .spp-blog-dismiss-btn {
            background: var(--spp-primary, #00897B);
            color: #fff;
            border: none;
            border-radius: 5px;
            padding: 8px 18px;
            font-size: 0.95rem;
            cursor: pointer;
        }
        .spp-blog-dismiss-btn:hover { background: var(--spp-accent, #004D40); }
    </style>

    <script>
    (function() {
        var TODAY       = '<?php echo $today; ?>';
        var COOKIE_NAME = 'spp_blog_seen';

        function getCookie(name) {
            var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? match[2] : null;
        }

        function setCookie(name, value, days) {
            var expires = new Date();
            expires.setTime(expires.getTime() + days * 24 * 60 * 60 * 1000);
            document.cookie = name + '=' + value + ';expires=' + expires.toUTCString() + ';path=/';
        }

        function dismiss() {
            document.getElementById('spp-blog-modal-overlay').classList.add('spp-hidden');
            setCookie(COOKIE_NAME, TODAY, 1);
        }

        // Check cookie before showing — hide immediately if already seen today
        if (getCookie(COOKIE_NAME) === TODAY) {
            document.getElementById('spp-blog-modal-overlay').classList.add('spp-hidden');
        }

        document.getElementById('spp-blog-dismiss').addEventListener('click', dismiss);
        document.getElementById('spp-blog-dismiss-btn').addEventListener('click', dismiss);

        // Dismiss on overlay click (outside modal)
        document.getElementById('spp-blog-modal-overlay').addEventListener('click', function(e) {
            if (e.target === this) dismiss();
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
