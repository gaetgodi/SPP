<?php
/**
 * Plugin Name: FBL Testimonials System
 * Description: Portable Testimonials Custom Post Type. Drop into any WordPress site.
 * Version: 1.0.0
 * Author: Fish Bucket Lake
 * 
 * PORTABILITY: This is a must-use plugin. Simply copy to /wp-content/mu-plugins/ on any site.
 * Based on the same pattern as the FAQ system - proven and portable!
 * 
 * FILE LOCATION: /wp-content/mu-plugins/fbl-testimonials-system.php
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FBL_Testimonials_System {
    
    /**
     * Post type slug
     */
    const POST_TYPE = 'fbl_testimonial';
    
    /**
     * Taxonomy slug (optional - for categorizing testimonials)
     */
    const TAXONOMY = 'fbl_testimonial_category';
    
    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Register post type and taxonomy
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_taxonomy'));
        
        // Add custom meta boxes for author info
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
        
        // Admin customizations
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'custom_columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'custom_column_content'), 10, 2);
        
        // REST API customization
        add_action('rest_api_init', array($this, 'register_rest_fields'));
    }
    
    /**
     * Register Testimonials Custom Post Type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => 'Testimonials',
            'singular_name'         => 'Testimonial',
            'menu_name'             => 'Testimonials',
            'add_new'               => 'Add New Testimonial',
            'add_new_item'          => 'Add New Testimonial',
            'edit_item'             => 'Edit Testimonial',
            'new_item'              => 'New Testimonial',
            'view_item'             => 'View Testimonial',
            'search_items'          => 'Search Testimonials',
            'not_found'             => 'No testimonials found',
            'not_found_in_trash'    => 'No testimonials found in trash',
            'all_items'             => 'All Testimonials',
        );
        
        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => true,
            'query_var'           => true,
            'rewrite'             => false,
            'capability_type'     => 'post',
            'has_archive'         => false,
            'hierarchical'        => false,
            'menu_position'       => 21,
            'menu_icon'           => 'dashicons-testimonial',
            'show_in_rest'        => true,
            'rest_base'           => 'testimonials',
            'supports'            => array('title', 'editor', 'revisions', 'excerpt'),
        );
        
        register_post_type(self::POST_TYPE, $args);
    }
    
    /**
     * Register Testimonial Category Taxonomy (optional)
     */
    public function register_taxonomy() {
        $labels = array(
            'name'              => 'Testimonial Categories',
            'singular_name'     => 'Testimonial Category',
            'menu_name'         => 'Categories',
            'all_items'         => 'All Categories',
            'edit_item'         => 'Edit Category',
            'add_new_item'      => 'Add New Category',
        );
        
        $args = array(
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rest_base'         => 'testimonial-categories',
            'rewrite'           => false,
        );
        
        register_taxonomy(self::TAXONOMY, array(self::POST_TYPE), $args);
    }
    
    /**
     * Add meta boxes for author information
     */
    public function add_meta_boxes() {
        add_meta_box(
            'testimonial_author_info',
            'Author Information',
            array($this, 'render_author_meta_box'),
            self::POST_TYPE,
            'normal',
            'high'
        );
    }
    
    /**
     * Render author info meta box
     */
    public function render_author_meta_box($post) {
        wp_nonce_field('testimonial_author_info', 'testimonial_author_info_nonce');
        
        $author_name = get_post_meta($post->ID, '_testimonial_author_name', true);
        $author_location = get_post_meta($post->ID, '_testimonial_author_location', true);
        $rating = get_post_meta($post->ID, '_testimonial_rating', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="testimonial_author_name">Author Name</label></th>
                <td>
                    <input type="text" id="testimonial_author_name" name="testimonial_author_name" 
                           value="<?php echo esc_attr($author_name); ?>" class="regular-text">
                    <p class="description">Example: Dan McHugh</p>
                </td>
            </tr>
            <tr>
                <th><label for="testimonial_author_location">Location</label></th>
                <td>
                    <input type="text" id="testimonial_author_location" name="testimonial_author_location" 
                           value="<?php echo esc_attr($author_location); ?>" class="regular-text">
                    <p class="description">Example: Oak Park, IL</p>
                </td>
            </tr>
            <tr>
                <th><label for="testimonial_rating">Rating (optional)</label></th>
                <td>
                    <select id="testimonial_rating" name="testimonial_rating">
                        <option value="">No rating</option>
                        <option value="5" <?php selected($rating, '5'); ?>>5 Stars</option>
                        <option value="4" <?php selected($rating, '4'); ?>>4 Stars</option>
                        <option value="3" <?php selected($rating, '3'); ?>>3 Stars</option>
                        <option value="2" <?php selected($rating, '2'); ?>>2 Stars</option>
                        <option value="1" <?php selected($rating, '1'); ?>>1 Star</option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Save meta box data
     */
    public function save_meta_boxes($post_id) {
        // Check nonce
        if (!isset($_POST['testimonial_author_info_nonce']) || 
            !wp_verify_nonce($_POST['testimonial_author_info_nonce'], 'testimonial_author_info')) {
            return;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save author name
        if (isset($_POST['testimonial_author_name'])) {
            update_post_meta($post_id, '_testimonial_author_name', 
                sanitize_text_field($_POST['testimonial_author_name']));
        }
        
        // Save author location
        if (isset($_POST['testimonial_author_location'])) {
            update_post_meta($post_id, '_testimonial_author_location', 
                sanitize_text_field($_POST['testimonial_author_location']));
        }
        
        // Save rating
        if (isset($_POST['testimonial_rating'])) {
            update_post_meta($post_id, '_testimonial_rating', 
                sanitize_text_field($_POST['testimonial_rating']));
        }
    }
    
    /**
     * Add custom columns to admin list
     */
    public function custom_columns($columns) {
        $new_columns = array();
        
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = 'Title / Excerpt';
        $new_columns['author_info'] = 'Author';
        $new_columns['rating'] = 'Rating';
        $new_columns['taxonomy-' . self::TAXONOMY] = 'Category';
        $new_columns['date'] = $columns['date'];
        
        return $new_columns;
    }
    
    /**
     * Display custom column content
     */
    public function custom_column_content($column, $post_id) {
        switch ($column) {
            case 'author_info':
                $name = get_post_meta($post_id, '_testimonial_author_name', true);
                $location = get_post_meta($post_id, '_testimonial_author_location', true);
                
                if ($name) {
                    echo '<strong>' . esc_html($name) . '</strong><br>';
                }
                if ($location) {
                    echo '<small>' . esc_html($location) . '</small>';
                }
                break;
                
            case 'rating':
                $rating = get_post_meta($post_id, '_testimonial_rating', true);
                if ($rating) {
                    echo str_repeat('⭐', intval($rating));
                } else {
                    echo '—';
                }
                break;
        }
    }
    
    /**
     * Add custom REST API fields
     */
    public function register_rest_fields() {
        // Add author info to REST response
        register_rest_field(
            self::POST_TYPE,
            'author_info',
            array(
                'get_callback' => array($this, 'get_author_info'),
                'schema'       => array(
                    'description' => 'Testimonial author information',
                    'type'        => 'object',
                ),
            )
        );
        
        // Add category names
        register_rest_field(
            self::POST_TYPE,
            'category_names',
            array(
                'get_callback' => array($this, 'get_category_names'),
                'schema'       => array(
                    'description' => 'Testimonial category names',
                    'type'        => 'array',
                ),
            )
        );
    }
    
    /**
     * Get author info for REST API
     */
    public function get_author_info($object) {
        return array(
            'name'     => get_post_meta($object['id'], '_testimonial_author_name', true),
            'location' => get_post_meta($object['id'], '_testimonial_author_location', true),
            'rating'   => get_post_meta($object['id'], '_testimonial_rating', true),
        );
    }
    
    /**
     * Get category names for REST API
     */
    public function get_category_names($object) {
        $terms = get_the_terms($object['id'], self::TAXONOMY);
        
        if (empty($terms) || is_wp_error($terms)) {
            return array();
        }
        
        return wp_list_pluck($terms, 'name');
    }
}

// Initialize the plugin
new FBL_Testimonials_System();

/**
 * PORTABILITY NOTES:
 * 
 * 1. Drop this file into /wp-content/mu-plugins/ on any site
 * 2. Automatically activates (no manual activation needed)
 * 3. "Testimonials" menu appears in WordPress admin
 * 4. REST API endpoint: /wp-json/wp/v2/testimonials
 * 5. Custom fields for author name and location
 * 6. Optional rating system (1-5 stars)
 * 7. Optional categories for organizing testimonials
 * 
 * USAGE:
 * - Add testimonials like you add posts
 * - Title = Brief title or first line
 * - Content = Full testimonial text
 * - Author Info box = Name and location
 * - Categories = Optional (Fishing, Accommodations, Service, etc.)
 */