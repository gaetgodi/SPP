<?php
/**
 * Plugin Name: FBL Contact Form System - Enhanced with Email
 * Description: Contact form with spam protection and email notifications
 * Version: 1.1.0
 * Author: Fish Buck Lake
 * 
 * ENHANCEMENTS IN v1.1.0:
 * - Admin email notification when form submitted
 * - Auto-reply confirmation to submitter
 * - Configurable email addresses
 * - Email only sent for non-spam submissions
 * 
 * FILE LOCATION: /wp-content/mu-plugins/fbl-contact-system.php
 * REPLACES: Previous version (1.0.0)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FBL_Contact_System {
    
    /**
     * Post type slug
     */
    const POST_TYPE = 'fbl_contact';
    
    /**
     * Taxonomy slug
     */
    const TAXONOMY = 'fbl_contact_category';
    
    /**
     * CONFIGURATION: Email Settings
     * Change these to your email addresses!
     */
    const ADMIN_EMAIL = 'info@fishbucklake.com'; // ← Change this to your email!
    const FROM_EMAIL = 'info@fishbucklake.com'; // ← Change this to your domain
    const FROM_NAME = 'Fish Buck Lake Contact Form (staging)';
    
    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Register post type and taxonomy
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_taxonomy'));
        
        // Add custom meta boxes
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
        
        // Admin customizations
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'custom_columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'custom_column_content'), 10, 2);
        add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', array($this, 'sortable_columns'));
        
        // REST API for form submission
        add_action('rest_api_init', array($this, 'register_rest_route'));
        
        // Admin notices for spam
        add_action('admin_notices', array($this, 'spam_notice'));
        
        // Quick actions
        add_filter('post_row_actions', array($this, 'row_actions'), 10, 2);
    }
    
    /**
     * Register Contact Submissions Custom Post Type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => 'Contact Submissions',
            'singular_name'         => 'Contact Submission',
            'menu_name'             => 'Contact Forms',
            'add_new'               => 'Add New Submission',
            'add_new_item'          => 'Add New Submission',
            'edit_item'             => 'View Submission',
            'new_item'              => 'New Submission',
            'view_item'             => 'View Submission',
            'search_items'          => 'Search Submissions',
            'not_found'             => 'No submissions found',
            'not_found_in_trash'    => 'No submissions found in trash',
            'all_items'             => 'All Submissions',
        );
        
        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'query_var'           => false,
            'rewrite'             => false,
            'capability_type'     => 'post',
            'has_archive'         => false,
            'hierarchical'        => false,
            'menu_position'       => 22,
            'menu_icon'           => 'dashicons-email',
            'show_in_rest'        => true,
            'rest_base'           => 'contact-submissions',
            'supports'            => array('title', 'editor'),
            'capabilities'        => array(
                'create_posts' => 'do_not_allow',
            ),
            'map_meta_cap'        => true,
        );
        
        register_post_type(self::POST_TYPE, $args);
    }
    
    /**
     * Register Contact Category Taxonomy
     */
    public function register_taxonomy() {
        $labels = array(
            'name'              => 'Request Categories',
            'singular_name'     => 'Request Category',
            'menu_name'         => 'Categories',
            'all_items'         => 'All Categories',
            'edit_item'         => 'Edit Category',
            'add_new_item'      => 'Add New Category',
            'update_item'       => 'Update Category',
            'search_items'      => 'Search Categories',
        );
        
        $args = array(
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rest_base'         => 'contact-categories',
            'rewrite'           => false,
        );
        
        register_taxonomy(self::TAXONOMY, array(self::POST_TYPE), $args);
        
        // Create default categories
        $this->create_default_categories();
    }
    
    /**
     * Create default categories
     */
    private function create_default_categories() {
        $defaults = array(
            'Request for Information',
            'Request for Booking',
            'Request for Brochure',
        );
        
        foreach ($defaults as $category) {
            if (!term_exists($category, self::TAXONOMY)) {
                wp_insert_term($category, self::TAXONOMY);
            }
        }
    }
    
    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        // Contact Information
        add_meta_box(
            'contact_info',
            'Contact Information',
            array($this, 'render_contact_info_meta_box'),
            self::POST_TYPE,
            'normal',
            'high'
        );
        
        // Submission Details
        add_meta_box(
            'submission_details',
            'Submission Details',
            array($this, 'render_submission_details_meta_box'),
            self::POST_TYPE,
            'side',
            'high'
        );
        
        // Spam Detection
        add_meta_box(
            'spam_detection',
            'Spam Detection',
            array($this, 'render_spam_meta_box'),
            self::POST_TYPE,
            'side',
            'default'
        );
        
        // Email Status (NEW!)
        add_meta_box(
            'email_status',
            'Email Notifications',
            array($this, 'render_email_status_meta_box'),
            self::POST_TYPE,
            'side',
            'default'
        );
    }
    
    /**
     * Render contact info meta box
     */
    public function render_contact_info_meta_box($post) {
        $name = get_post_meta($post->ID, '_contact_name', true);
        $email = get_post_meta($post->ID, '_contact_email', true);
        $phone = get_post_meta($post->ID, '_contact_phone', true);
        ?>
        <table class="form-table">
            <tr>
                <th><strong>Name:</strong></th>
                <td><?php echo esc_html($name); ?></td>
            </tr>
            <tr>
                <th><strong>Email:</strong></th>
                <td>
                    <a href="mailto:<?php echo esc_attr($email); ?>">
                        <?php echo esc_html($email); ?>
                    </a>
                </td>
            </tr>
            <tr>
                <th><strong>Phone:</strong></th>
                <td>
                    <?php if ($phone): ?>
                        <a href="tel:<?php echo esc_attr($phone); ?>">
                            <?php echo esc_html($phone); ?>
                        </a>
                    <?php else: ?>
                        <em>Not provided</em>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        
        <p>
            <strong>Quick Actions:</strong><br>
            <a href="mailto:<?php echo esc_attr($email); ?>" class="button">Email <?php echo esc_html($name); ?></a>
        </p>
        <?php
    }
    
    /**
     * Render submission details meta box
     */
    public function render_submission_details_meta_box($post) {
        $ip = get_post_meta($post->ID, '_contact_ip', true);
        $user_agent = get_post_meta($post->ID, '_contact_user_agent', true);
        $submitted = get_post_meta($post->ID, '_contact_submitted_date', true);
        ?>
        <p>
            <strong>Submitted:</strong><br>
            <?php echo $submitted ? date('F j, Y g:i a', strtotime($submitted)) : 'Unknown'; ?>
        </p>
        
        <p>
            <strong>IP Address:</strong><br>
            <?php echo esc_html($ip ?: 'Unknown'); ?>
        </p>
        
        <p>
            <strong>User Agent:</strong><br>
            <small><?php echo esc_html($user_agent ?: 'Unknown'); ?></small>
        </p>
        <?php
    }
    
    /**
     * Render spam detection meta box
     */
    public function render_spam_meta_box($post) {
        $spam_score = get_post_meta($post->ID, '_spam_score', true);
        $is_spam = get_post_meta($post->ID, '_is_spam', true);
        
        $spam_score = $spam_score ?: 0;
        $status_color = $is_spam ? '#dc3232' : ($spam_score > 50 ? '#ffb900' : '#46b450');
        $status_text = $is_spam ? 'Marked as Spam' : ($spam_score > 50 ? 'Suspicious' : 'Looks Good');
        ?>
        <div style="padding: 10px; background: <?php echo $status_color; ?>; color: white; text-align: center; border-radius: 4px; margin-bottom: 10px;">
            <strong><?php echo $status_text; ?></strong>
        </div>
        
        <p>
            <strong>Spam Score:</strong> <?php echo intval($spam_score); ?>/100
        </p>
        
        <p>
            <?php if ($is_spam): ?>
                <button type="button" class="button button-primary" onclick="fblMarkNotSpam(<?php echo $post->ID; ?>)">
                    Mark as Not Spam
                </button>
            <?php else: ?>
                <button type="button" class="button" onclick="fblMarkAsSpam(<?php echo $post->ID; ?>)">
                    Mark as Spam
                </button>
            <?php endif; ?>
        </p>
        
        <script>
        function fblMarkAsSpam(postId) {
            if (confirm('Mark this submission as spam?')) {
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=fbl_mark_spam&post_id=' + postId + '&nonce=<?php echo wp_create_nonce('fbl_spam'); ?>'
                }).then(() => location.reload());
            }
        }
        
        function fblMarkNotSpam(postId) {
            if (confirm('Mark this submission as legitimate?')) {
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=fbl_mark_not_spam&post_id=' + postId + '&nonce=<?php echo wp_create_nonce('fbl_spam'); ?>'
                }).then(() => location.reload());
            }
        }
        </script>
        <?php
    }
    
    /**
     * Render email status meta box (NEW!)
     */
    public function render_email_status_meta_box($post) {
        $admin_sent = get_post_meta($post->ID, '_email_admin_sent', true);
        $reply_sent = get_post_meta($post->ID, '_email_reply_sent', true);
        $admin_time = get_post_meta($post->ID, '_email_admin_time', true);
        $reply_time = get_post_meta($post->ID, '_email_reply_time', true);
        ?>
        <p>
            <strong>Admin Notification:</strong><br>
            <?php if ($admin_sent): ?>
                ✅ Sent to <?php echo esc_html(self::ADMIN_EMAIL); ?><br>
                <small><?php echo $admin_time ? date('M j, Y g:i a', strtotime($admin_time)) : ''; ?></small>
            <?php else: ?>
                ❌ Not sent
            <?php endif; ?>
        </p>
        
        <p>
            <strong>Auto-Reply:</strong><br>
            <?php if ($reply_sent): ?>
                ✅ Sent to submitter<br>
                <small><?php echo $reply_time ? date('M j, Y g:i a', strtotime($reply_time)) : ''; ?></small>
            <?php else: ?>
                ❌ Not sent
            <?php endif; ?>
        </p>
        
        <?php if (!$admin_sent || !$reply_sent): ?>
        <p>
            <em>Email not sent because submission marked as spam</em>
        </p>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Save meta boxes (not used - we save via REST API)
     */
    public function save_meta_boxes($post_id) {
        // Contact submissions are created via REST API only
    }
    
    /**
     * Custom admin columns
     */
    public function custom_columns($columns) {
        $new_columns = array();
        
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = 'Subject';
        $new_columns['contact_name'] = 'Name';
        $new_columns['contact_email'] = 'Email';
        $new_columns['contact_phone'] = 'Phone';
        $new_columns['spam_status'] = 'Status';
        $new_columns['taxonomy-' . self::TAXONOMY] = 'Category';
        $new_columns['date'] = 'Submitted';
        
        return $new_columns;
    }
    
    /**
     * Display custom column content
     */
    public function custom_column_content($column, $post_id) {
        switch ($column) {
            case 'contact_name':
                echo esc_html(get_post_meta($post_id, '_contact_name', true) ?: '—');
                break;
                
            case 'contact_email':
                $email = get_post_meta($post_id, '_contact_email', true);
                if ($email) {
                    echo '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
                } else {
                    echo '—';
                }
                break;
                
            case 'contact_phone':
                $phone = get_post_meta($post_id, '_contact_phone', true);
                echo $phone ? esc_html($phone) : '—';
                break;
                
            case 'spam_status':
                $is_spam = get_post_meta($post_id, '_is_spam', true);
                $spam_score = get_post_meta($post_id, '_spam_score', true) ?: 0;
                
                if ($is_spam) {
                    echo '<span style="color: #dc3232; font-weight: bold;">⚠️ Spam</span>';
                } elseif ($spam_score > 50) {
                    echo '<span style="color: #ffb900;">⚡ Suspicious</span>';
                } else {
                    echo '<span style="color: #46b450;">✅ Good</span>';
                }
                break;
        }
    }
    
    /**
     * Sortable columns
     */
    public function sortable_columns($columns) {
        $columns['contact_name'] = 'contact_name';
        $columns['spam_status'] = 'spam_score';
        return $columns;
    }
    
    /**
     * Register REST API route for form submission
     */
    public function register_rest_route() {
        register_rest_route('fbl/v1', '/contact', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_submission'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * Handle form submission
     */
    public function handle_submission($request) {
        $params = $request->get_json_params();
        
        // Validate required fields
        if (empty($params['name']) || empty($params['email']) || empty($params['message'])) {
            return new WP_Error('missing_fields', 'Please fill in all required fields', array('status' => 400));
        }
        
        // Validate email
        if (!is_email($params['email'])) {
            return new WP_Error('invalid_email', 'Please provide a valid email address', array('status' => 400));
        }
        
        // Spam detection
        $spam_score = $this->calculate_spam_score($params);
        $is_spam = $spam_score > 75;
        
        // Create submission
        $post_data = array(
            'post_title'   => 'Contact from ' . sanitize_text_field($params['name']),
            'post_content' => sanitize_textarea_field($params['message']),
            'post_status'  => $is_spam ? 'draft' : 'publish',
            'post_type'    => self::POST_TYPE,
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            return new WP_Error('submission_failed', 'Failed to save submission', array('status' => 500));
        }
        
        // Save meta data
        update_post_meta($post_id, '_contact_name', sanitize_text_field($params['name']));
        update_post_meta($post_id, '_contact_email', sanitize_email($params['email']));
        update_post_meta($post_id, '_contact_phone', sanitize_text_field($params['phone'] ?? ''));
        update_post_meta($post_id, '_contact_ip', $_SERVER['REMOTE_ADDR']);
        update_post_meta($post_id, '_contact_user_agent', $_SERVER['HTTP_USER_AGENT']);
        update_post_meta($post_id, '_contact_submitted_date', current_time('mysql'));
        update_post_meta($post_id, '_spam_score', $spam_score);
        update_post_meta($post_id, '_is_spam', $is_spam);
        
        // Set category
        if (!empty($params['category'])) {
            $term = term_exists($params['category'], self::TAXONOMY);
            if ($term) {
                wp_set_object_terms($post_id, intval($term['term_id']), self::TAXONOMY);
            }
        }
        
        // Send emails (only if not spam!)
        if (!$is_spam) {
            $this->send_admin_notification($post_id, $params);
            $this->send_auto_reply($post_id, $params);
        }
        
        return array(
            'success' => true,
            'message' => 'Thank you! Your message has been received. We\'ll respond soon.',
            'spam_detected' => $is_spam,
        );
    }
    
    /**
     * Send admin notification email (NEW!)
     */
    private function send_admin_notification($post_id, $data) {
        $to = self::ADMIN_EMAIL;
        $subject = 'New Contact Form Submission - ' . sanitize_text_field($data['name']);
        
        $category = !empty($data['category']) ? $data['category'] : 'None selected';
        
        $message = "New contact form submission received:\n\n";
        $message .= "Name: " . sanitize_text_field($data['name']) . "\n";
        $message .= "Email: " . sanitize_email($data['email']) . "\n";
        $message .= "Phone: " . sanitize_text_field($data['phone'] ?? 'Not provided') . "\n";
        $message .= "Category: " . $category . "\n";
        $message .= "Submitted: " . current_time('mysql') . "\n\n";
        $message .= "Message:\n" . sanitize_textarea_field($data['message']) . "\n\n";
        $message .= "---\n";
        $message .= "View in admin: " . admin_url('post.php?post=' . $post_id . '&action=edit') . "\n";
        
        $headers = array(
            'From: ' . self::FROM_NAME . ' <' . self::FROM_EMAIL . '>',
            'Reply-To: ' . sanitize_text_field($data['name']) . ' <' . sanitize_email($data['email']) . '>'
        );
        
        $sent = wp_mail($to, $subject, $message, $headers);
        
        // Log email status
        update_post_meta($post_id, '_email_admin_sent', $sent ? 1 : 0);
        update_post_meta($post_id, '_email_admin_time', current_time('mysql'));
        
        return $sent;
    }
    
    /**
     * Send auto-reply to submitter (NEW!)
     */
    private function send_auto_reply($post_id, $data) {
        $to = sanitize_email($data['email']);
        $subject = 'Thank you for contacting Fish Buck Lake';
        
        $message = "Dear " . sanitize_text_field($data['name']) . ",\n\n";
        $message .= "Thank you for contacting us! We have received your message and will respond as soon as possible.\n\n";
        $message .= "Here's a copy of what you sent:\n\n";
        $message .= "---\n";
        $message .= sanitize_textarea_field($data['message']) . "\n";
        $message .= "---\n\n";
        $message .= "If you have any urgent questions, please call us at (705) 534-1991).\n\n";
        $message .= "Best regards,\n";
        $message .= "Fish Buck Lake Team\n";
        $message .= "fishbucklake.com\n";
        
        $headers = array(
            'From: ' . self::FROM_NAME . ' <' . self::FROM_EMAIL . '>'
        );
        
        $sent = wp_mail($to, $subject, $message, $headers);
        
        // Log email status
        update_post_meta($post_id, '_email_reply_sent', $sent ? 1 : 0);
        update_post_meta($post_id, '_email_reply_time', current_time('mysql'));
        
        return $sent;
    }
    
    /**
     * Calculate spam score
     */
    private function calculate_spam_score($data) {
        $score = 0;
        
        // Honeypot
        if (!empty($data['website'])) {
            $score += 100;
        }
        
        // Timing check
        if (!empty($data['form_time']) && is_numeric($data['form_time'])) {
            $time_taken = time() - intval($data['form_time']);
            if ($time_taken < 3) {
                $score += 50;
            }
        }
        
        // Keyword detection
        $message = strtolower($data['message']);
        $spam_keywords = array('viagra', 'cialis', 'casino', 'lottery', 'winner', 'crypto', 'bitcoin');
        foreach ($spam_keywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                $score += 20;
            }
        }
        
        // Link counting
        $link_count = substr_count($message, 'http://') + substr_count($message, 'https://');
        if ($link_count > 2) {
            $score += 30;
        }
        
        // Email domain check
        $email_parts = explode('@', $data['email']);
        if (count($email_parts) === 2) {
            $suspicious_domains = array('tempmail.com', 'guerrillamail.com', '10minutemail.com');
            if (in_array($email_parts[1], $suspicious_domains)) {
                $score += 40;
            }
        }
        
        return min($score, 100);
    }
    
    /**
     * Admin notice for spam
     */
    public function spam_notice() {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === self::POST_TYPE) {
            $spam_count = wp_count_posts(self::POST_TYPE)->draft;
            if ($spam_count > 0) {
                echo '<div class="notice notice-warning">';
                echo '<p><strong>Note:</strong> ' . $spam_count . ' submission(s) marked as potential spam (in Drafts).</p>';
                echo '</div>';
            }
        }
    }
    
    /**
     * Custom row actions
     */
    public function row_actions($actions, $post) {
        if ($post->post_type === self::POST_TYPE) {
            $email = get_post_meta($post->ID, '_contact_email', true);
            if ($email) {
                $actions['email'] = '<a href="mailto:' . esc_attr($email) . '">Email</a>';
            }
        }
        return $actions;
    }
}

// Initialize the plugin
new FBL_Contact_System();

// AJAX handlers for spam marking
add_action('wp_ajax_fbl_mark_spam', function() {
    check_ajax_referer('fbl_spam', 'nonce');
    $post_id = intval($_POST['post_id']);
    update_post_meta($post_id, '_is_spam', true);
    update_post_meta($post_id, '_spam_score', 100);
    wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
    wp_send_json_success();
});

add_action('wp_ajax_fbl_mark_not_spam', function() {
    check_ajax_referer('fbl_spam', 'nonce');
    $post_id = intval($_POST['post_id']);
    update_post_meta($post_id, '_is_spam', false);
    update_post_meta($post_id, '_spam_score', 0);
    wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
    wp_send_json_success();
});

/**
 * EMAIL CONFIGURATION INSTRUCTIONS:
 * 
 * 1. Change email addresses at top of file (lines 23-25):
 *    - ADMIN_EMAIL: Where notifications are sent
 *    - FROM_EMAIL: "From" address (use your domain!)
 *    - FROM_NAME: Display name
 * 
 * 2. Customize auto-reply message in send_auto_reply() function
 *    - Add your phone number
 *    - Add your website
 *    - Change wording
 * 
 * 3. For SMTP (Gmail, etc.), install WP Mail SMTP plugin
 *    OR add to wp-config.php:
 *    
 *    define('SMTP_USER', 'your@gmail.com');
 *    define('SMTP_PASS', 'your-app-password');
 *    define('SMTP_HOST', 'smtp.gmail.com');
 *    define('SMTP_FROM', 'your@gmail.com');
 *    define('SMTP_NAME', 'Your Name');
 *    define('SMTP_PORT', '587');
 *    define('SMTP_SECURE', 'tls');
 *    define('SMTP_AUTH', true);
 * 
 * 4. Test emails work by submitting test form
 */