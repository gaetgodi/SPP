<?php
/**
 * Plugin Name: FBL FAQ System
 * Description: Portable FAQ Custom Post Type with category taxonomy. Drop into any WordPress site.
 * Version: 1.0.1
 * Author: Fish Bucket Lake
 * 
 * PORTABILITY: This is a must-use plugin. Simply copy to /wp-content/mu-plugins/ on any site.
 * It will automatically activate and work with zero configuration.
 * 
 * FILE LOCATION: /wp-content/mu-plugins/fbl-faq-system.php
 * 
 * VERSION 1.0.1 CHANGES:
 * - Added explicit capability mapping for Editor role access
 * - Editors can now fully manage FAQs
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FBL_FAQ_System {
    
    /**
     * Post type slug - change this to rename across entire system
     */
    const POST_TYPE = 'fbl_faq';
    
    /**
     * Taxonomy slug - change this to rename taxonomy
     */
    const TAXONOMY = 'fbl_faq_category';
    
    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Register post type and taxonomy
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_taxonomy'));
        
        // Admin customizations
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'custom_columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'custom_column_content'), 10, 2);
        add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', array($this, 'sortable_columns'));
        
        // Add category filter to admin
        add_action('restrict_manage_posts', array($this, 'add_category_filter'));
        
        // REST API customization
        add_action('rest_api_init', array($this, 'register_rest_fields'));
        
        // Admin notices
        add_action('admin_notices', array($this, 'show_import_notice'));
    }
    
    /**
     * Register FAQ Custom Post Type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => 'FAQs',
            'singular_name'         => 'FAQ',
            'menu_name'             => 'FAQs',
            'add_new'               => 'Add New FAQ',
            'add_new_item'          => 'Add New FAQ',
            'edit_item'             => 'Edit FAQ',
            'new_item'              => 'New FAQ',
            'view_item'             => 'View FAQ',
            'view_items'            => 'View FAQs',
            'search_items'          => 'Search FAQs',
            'not_found'             => 'No FAQs found',
            'not_found_in_trash'    => 'No FAQs found in trash',
            'all_items'             => 'All FAQs',
            'archives'              => 'FAQ Archives',
            'attributes'            => 'FAQ Attributes',
            'insert_into_item'      => 'Insert into FAQ',
            'uploaded_to_this_item' => 'Uploaded to this FAQ',
        );
        
        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => true,
            'capabilities'        => array(
                'edit_post'          => 'edit_post',
                'read_post'          => 'read_post',
                'delete_post'        => 'delete_post',
                'edit_posts'         => 'edit_posts',
                'edit_others_posts'  => 'edit_others_posts',
                'publish_posts'      => 'publish_posts',
                'read_private_posts' => 'read_private_posts',
                'delete_posts'       => 'delete_posts',
            ),
            'map_meta_cap'        => true,
            'query_var'           => true,
            'rewrite'             => false,
            'capability_type'     => 'post',
            'has_archive'         => false,
            'hierarchical'        => false,
            'menu_position'       => 20,
            'menu_icon'           => 'dashicons-editor-help',
            'show_in_rest'        => true, // Enable REST API
            'rest_base'           => 'faqs',
            'supports'            => array('title', 'editor', 'revisions'),
        );
        
        register_post_type(self::POST_TYPE, $args);
    }
    
    /**
     * Register FAQ Category Taxonomy
     */
    public function register_taxonomy() {
        $labels = array(
            'name'                       => 'FAQ Categories',
            'singular_name'              => 'FAQ Category',
            'menu_name'                  => 'Categories',
            'all_items'                  => 'All Categories',
            'edit_item'                  => 'Edit Category',
            'view_item'                  => 'View Category',
            'update_item'                => 'Update Category',
            'add_new_item'               => 'Add New Category',
            'new_item_name'              => 'New Category Name',
            'parent_item'                => 'Parent Category',
            'parent_item_colon'          => 'Parent Category:',
            'search_items'               => 'Search Categories',
            'popular_items'              => 'Popular Categories',
            'separate_items_with_commas' => 'Separate categories with commas',
            'add_or_remove_items'        => 'Add or remove categories',
            'choose_from_most_used'      => 'Choose from most used categories',
            'not_found'                  => 'No categories found',
        );
        
        $args = array(
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud'     => false,
            'show_in_rest'      => true, // Enable REST API
            'rest_base'         => 'faq-categories',
            'rewrite'           => false,
        );
        
        register_taxonomy(self::TAXONOMY, array(self::POST_TYPE), $args);
    }
    
    /**
     * Add custom columns to admin list
     */
    public function custom_columns($columns) {
        $new_columns = array();
        
        // Checkbox
        $new_columns['cb'] = $columns['cb'];
        
        // Question (title)
        $new_columns['title'] = 'Question';
        
        // Category
        $new_columns['taxonomy-' . self::TAXONOMY] = 'Category';
        
        // Answer preview
        $new_columns['answer_preview'] = 'Answer';
        
        // Date
        $new_columns['date'] = $columns['date'];
        
        return $new_columns;
    }
    
    /**
     * Display custom column content
     */
    public function custom_column_content($column, $post_id) {
        switch ($column) {
            case 'answer_preview':
                $content = get_post_field('post_content', $post_id);
                $content = wp_strip_all_tags($content);
                $content = substr($content, 0, 100);
                echo esc_html($content) . '...';
                break;
        }
    }
    
    /**
     * Make columns sortable
     */
    public function sortable_columns($columns) {
        $columns['title'] = 'title';
        return $columns;
    }
    
    /**
     * Add category filter dropdown to admin
     */
    public function add_category_filter() {
        global $typenow;
        
        if ($typenow == self::POST_TYPE) {
            $selected = isset($_GET[self::TAXONOMY]) ? $_GET[self::TAXONOMY] : '';
            
            wp_dropdown_categories(array(
                'show_option_all' => 'All Categories',
                'taxonomy'        => self::TAXONOMY,
                'name'            => self::TAXONOMY,
                'orderby'         => 'name',
                'selected'        => $selected,
                'show_count'      => true,
                'hide_empty'      => false,
                'value_field'     => 'slug',
            ));
        }
    }
    
    /**
     * Add custom REST API fields
     */
    public function register_rest_fields() {
        // Add category names to REST response
        register_rest_field(
            self::POST_TYPE,
            'category_names',
            array(
                'get_callback' => array($this, 'get_category_names'),
                'schema'       => array(
                    'description' => 'FAQ Category names',
                    'type'        => 'array',
                ),
            )
        );
        
        // Add clean answer text
        register_rest_field(
            self::POST_TYPE,
            'answer',
            array(
                'get_callback' => array($this, 'get_answer_html'),
                'schema'       => array(
                    'description' => 'FAQ Answer with HTML',
                    'type'        => 'string',
                ),
            )
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
    
    /**
     * Get answer HTML for REST API
     */
    public function get_answer_html($object) {
        return apply_filters('the_content', get_post_field('post_content', $object['id']));
    }
    
    /**
     * Show admin notice about CSV import
     */
    public function show_import_notice() {
        $screen = get_current_screen();
        
        if ($screen->post_type == self::POST_TYPE) {
            $faq_count = wp_count_posts(self::POST_TYPE);
            
            if ($faq_count->publish == 0) {
                ?>
                <div class="notice notice-info">
                    <p>
                        <strong>First time setup?</strong> 
                        You can import your existing CSV data. 
                        <a href="<?php echo admin_url('admin.php?page=fbl-faq-import'); ?>">Import FAQs from CSV</a>
                    </p>
                </div>
                <?php
            }
        }
    }
}

// Initialize the plugin
new FBL_FAQ_System();

/**
 * PORTABILITY NOTES:
 * 
 * 1. This file is self-contained - no external dependencies
 * 2. Change POST_TYPE and TAXONOMY constants to rename
 * 3. Works on any WordPress site (5.0+)
 * 4. No theme dependencies
 * 5. REST API enabled for frontend JavaScript
 * 6. Editors can fully manage FAQs (v1.0.1)
 * 
 * TO USE ON ANOTHER SITE:
 * 1. Copy this file to /wp-content/mu-plugins/
 * 2. Refresh WordPress admin
 * 3. You'll see "FAQs" in admin menu
 * 4. Import CSV or add FAQs manually
 * 5. Use REST API endpoint: /wp-json/wp/v2/faqs
 */