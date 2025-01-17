<?php
/*
Plugin Name: Dynamic Shortcodes Manager
Description: Creates dynamic shortcodes using ACF Options page
Version: 1.1
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if ACF is active
if (!class_exists('ACF')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>Dynamic Shortcodes Manager requires Advanced Custom Fields to be installed and activated.</p></div>';
    });
    return;
}

// Add ACF Options Page
function mpf_add_options_page() {
    if (function_exists('acf_add_options_page') && current_user_can('manage_options')) {
        acf_add_options_page(array(
            'page_title' => 'Dynamic Shortcodes Manager',
            'menu_title' => 'Dynamic Shortcodes Manager',
            'menu_slug' => 'dynamic-shortcodes-manager',
            'capability' => 'manage_options',
            'icon_url' => 'dashicons-shortcode'
        ));
    }
}
add_action('acf/init', 'mpf_add_options_page');

// Add ACF Fields
function mpf_add_acf_fields() {
    if (function_exists('acf_add_local_field_group')) {
        acf_add_local_field_group(array(
            'key' => 'group_mpf_shortcodes',
            'title' => 'Dynamic Shortcodes Manager',
            'fields' => array(
                array(
                    'key' => 'field_shortcodes_repeater',
                    'label' => 'Shortcodes',
                    'name' => 'mpf_shortcodes',
                    'type' => 'repeater',
                    'layout' => 'table',
                    'sub_fields' => array(
                        array(
                            'key' => 'field_shortcode_name',
                            'label' => 'Shortcode Name',
                            'name' => 'shortcode_name',
                            'type' => 'text',
                            'required' => 1,
                            'instructions' => 'Enter any text - it will be automatically converted to a valid shortcode name',
                            'wrapper' => array(
                                'width' => '30',
                            ),
                        ),
                        array(
                            'key' => 'field_shortcode_value',
                            'label' => 'Value',
                            'name' => 'shortcode_value',
                            'type' => 'text',
                            'required' => 1,
                            'instructions' => 'This is the value that will be displayed when the shortcode is used.',
                            'wrapper' => array(
                                'width' => '40',
                            ),
                        ),
                        array(
                            'key' => 'field_shortcode_display',
                            'label' => 'Shortcode<p class="description">Paste this entire shortcode into your post or page</p>',
                            'name' => 'shortcode_display',
                            'type' => 'message',
                            'message' => 'Shortcode will appear here',
                            'wrapper' => array(
                                'width' => '30',
                                'class' => 'shortcode-display',
                            ),
                        ),
                    ),
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'options_page',
                        'operator' => '==',
                        'value' => 'dynamic-shortcodes-manager',
                    ),
                ),
            ),
        ));
    }
}
add_action('acf/init', 'mpf_add_acf_fields');

// Add this new function to display the shortcode
function mpf_acf_load_shortcode_display($field) {
    if ($field['name'] === 'shortcode_display') {
        $row = get_row();
        if ($row && isset($row['shortcode_name'])) {
            $shortcode = '[mpf name="' . esc_attr($row['shortcode_name']) . '"]';
            $field['message'] = '<code style="user-select: all;">' . $shortcode . '</code>';
        }
    }
    return $field;
}
add_filter('acf/load_field/key=field_shortcode_display', 'mpf_acf_load_shortcode_display');

// Modify validation function to handle auto-slugification and auto-numbering
function mpf_validate_shortcode_name($valid, $value, $field, $input) {
    if (!$valid) {
        return $valid;
    }

    // First, slugify the input value
    $slug = sanitize_title($value);
    
    // Get all values from the current form submission
    $form_values = array();
    if (isset($_POST['acf'])) {
        $repeater_values = $_POST['acf']['field_shortcodes_repeater'] ?? array();
        foreach ($repeater_values as $key => $row) {
            if (isset($row['field_shortcode_name'])) {
                preg_match('/^row-(\d+)$/', $key, $matches);
                $index = isset($matches[1]) ? (int)$matches[1] : -1;
                if ($index !== -1) {
                    $form_values[$index] = sanitize_title($row['field_shortcode_name']);
                }
            }
        }
    }

    // Extract current row index
    preg_match('/\[row-(\d+)\]/', $input, $matches);
    $current_row = isset($matches[1]) ? (int)$matches[1] : -1;

    // Check for duplicates and auto-number if needed
    $base_slug = $slug;
    $counter = 1;
    while (in_array($slug, $form_values) && array_search($slug, $form_values) !== $current_row) {
        $slug = $base_slug . '_' . $counter;
        $counter++;
    }

    // If the slug was modified, we need to update the field value
    if ($slug !== $value) {
        add_filter('acf/pre_update_value/key=' . $field['key'], function($value) use ($slug) {
            return $slug;
        }, 10, 1);
    }

    return true; // Always return true since we're handling duplicates with auto-numbering
}
add_filter('acf/validate_value/key=field_shortcode_name', 'mpf_validate_shortcode_name', 10, 4);

// Sanitize and validate shortcode output
function mpf_sanitize_shortcode_output($output) {
    // Prevent PHP code execution
    $output = wp_kses_post($output);
    
    // Remove any potential script injections
    $output = wp_strip_all_tags($output, true);
    
    return $output;
}

// Modify the shortcode handler to include security measures
function mpf_dynamic_shortcode($atts) {
    // Sanitize attributes
    $atts = wp_parse_args($atts, array(
        'name' => '',
    ));
    
    // Sanitize the name parameter
    $name = sanitize_text_field($atts['name']);
    
    if (empty($name)) {
        return '';
    }

    // Verify nonce for admin actions
    if (is_admin() && !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'mpf_shortcode_action')) {
        return '';
    }

    // Get all shortcodes from ACF with proper escaping
    $shortcodes = get_field('mpf_shortcodes', 'option');
    
    if (!$shortcodes || !is_array($shortcodes)) {
        return '';
    }

    // Look for matching shortcode with proper sanitization
    foreach ($shortcodes as $shortcode) {
        if (isset($shortcode['shortcode_name']) && 
            sanitize_text_field($shortcode['shortcode_name']) === $name) {
            // Sanitize and return the value
            return mpf_sanitize_shortcode_output($shortcode['shortcode_value']);
        }
    }

    return '';
}
add_shortcode('mpf', 'mpf_dynamic_shortcode');

// Add support for shortcodes in menus
add_filter('wp_nav_menu_items', 'do_shortcode');
add_filter('nav_menu_item_title', 'do_shortcode');
add_filter('nav_menu_description', 'do_shortcode');
add_filter('nav_menu_attr_title', 'do_shortcode');

// Modify the location support function to better handle titles
function mpf_add_shortcode_support() {
    // Only allow shortcodes in specific locations if user has appropriate permissions
    if (current_user_can('unfiltered_html')) {
        // Page/Post Titles - with sanitization
        // Remove default title filter ONLY for posts/pages that have our shortcode
        global $post;
        if ($post && has_shortcode($post->post_title, 'mpf')) {
            remove_filter('the_title', 'wptexturize');
            
            // Add our title filters with specific priorities
            add_filter('the_title', 'do_shortcode', 10);
            add_filter('the_title', 'mpf_sanitize_shortcode_output', 11);
            add_filter('the_title', 'wptexturize', 12); // Re-add texturize after our processing
        } else {
            // For titles without our shortcode, just add the filters without removing texturize
            add_filter('the_title', 'do_shortcode', 10);
            add_filter('the_title', 'mpf_sanitize_shortcode_output', 11);
        }
        
        // Support for title in admin area - only when needed
        add_filter('enter_title_here', function($title) {
            return has_shortcode($title, 'mpf') ? do_shortcode($title) : $title;
        });
        
        // Support raw title - only when needed
        add_filter('single_post_title', function($title) {
            return has_shortcode($title, 'mpf') ? mpf_sanitize_shortcode_output(do_shortcode($title)) : $title;
        });
        
        // Support for get_the_title - only when needed
        add_filter('get_the_title', function($title) {
            return has_shortcode($title, 'mpf') ? mpf_sanitize_shortcode_output(do_shortcode($title)) : $title;
        });
        
        // Yoast SEO - with sanitization
        add_filter('wpseo_title', function($title) {
            return has_shortcode($title, 'mpf') ? mpf_sanitize_shortcode_output(do_shortcode($title)) : $title;
        });
        add_filter('wpseo_metadesc', function($desc) {
            return has_shortcode($desc, 'mpf') ? mpf_sanitize_shortcode_output(do_shortcode($desc)) : $desc;
        });
        
        // Gravity Forms - with conditional processing
        if (class_exists('GFCommon')) {
            add_filter('gform_pre_render', 'mpf_gform_shortcode_support');
            add_filter('gform_pre_process', 'mpf_gform_shortcode_support');
            add_filter('gform_pre_submission_filter', 'mpf_gform_shortcode_support');
            add_filter('gform_admin_pre_render', 'mpf_gform_shortcode_support');
        }
    }
}
add_action('init', 'mpf_add_shortcode_support', 999);

// Update Gravity Forms support to only process when needed
function mpf_gform_shortcode_support($form) {
    if (!is_array($form)) {
        return $form;
    }

    // Only process if shortcodes are present
    $needs_processing = false;
    
    // Check title and description
    if ((isset($form['title']) && has_shortcode($form['title'], 'mpf')) ||
        (isset($form['description']) && has_shortcode($form['description'], 'mpf'))) {
        $needs_processing = true;
    }
    
    // Check fields for shortcodes
    if (isset($form['fields']) && is_array($form['fields'])) {
        foreach ($form['fields'] as $field) {
            if ((isset($field->label) && has_shortcode($field->label, 'mpf')) ||
                (isset($field->description) && has_shortcode($field->description, 'mpf'))) {
                $needs_processing = true;
                break;
            }
        }
    }
    
    // Only process if shortcodes are found
    if ($needs_processing) {
        // Process form title and description
        if (isset($form['title'])) {
            $form['title'] = mpf_sanitize_shortcode_output(do_shortcode($form['title']));
        }
        
        if (isset($form['description'])) {
            $form['description'] = mpf_sanitize_shortcode_output(do_shortcode($form['description']));
        }

        // Process fields
        if (isset($form['fields']) && is_array($form['fields'])) {
            foreach ($form['fields'] as &$field) {
                if (isset($field->label)) {
                    $field->label = mpf_sanitize_shortcode_output(do_shortcode($field->label));
                }
                if (isset($field->description)) {
                    $field->description = mpf_sanitize_shortcode_output(do_shortcode($field->description));
                }
            }
        }
    }

    return $form;
}

// Add JavaScript to handle real-time slugification display
function mpf_admin_scripts() {
    if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'dynamic-shortcodes-manager') {
        ?>
        <script type="text/javascript">
        (function($) {
            function slugify(text) {
                return text.toString().toLowerCase()
                    .replace(/\s+/g, '_')           // Replace spaces with _
                    .replace(/[^\w\-]+/g, '')       // Remove all non-word chars
                    .replace(/\-\-+/g, '_')         // Replace multiple - with single _
                    .replace(/^-+/, '')             // Trim - from start of text
                    .replace(/-+$/, '');            // Trim - from end of text
            }

            function getUniqueSlug(baseSlug, currentInput) {
                var existingSlugs = [];
                var counter = 1;
                var finalSlug = baseSlug;

                // Collect all existing slugs except the current one
                $('.acf-field-shortcode-name input').not(currentInput).each(function() {
                    // Check if this is an existing field (has a value and isn't being edited)
                    var isExisting = $(this).val() && !$(this).is(':focus');
                    if (isExisting) {
                        existingSlugs.push($(this).val());
                    }
                });

                // If this input already has a value and isn't being edited, preserve it
                if (currentInput.val() && !currentInput.is(':focus')) {
                    return currentInput.val();
                }

                // Check if we need to add a number
                while (existingSlugs.includes(finalSlug)) {
                    finalSlug = baseSlug + '_' + counter;
                    counter++;
                }

                return finalSlug;
            }

            function updateShortcodeDisplay() {
                $('.acf-field-shortcode-name input').each(function() {
                    var input = $(this);
                    
                    // Only modify if this is the active field
                    if (input.is(':focus')) {
                        var rawValue = input.val();
                        var slugValue = slugify(rawValue);
                        
                        // Get unique slug if needed
                        slugValue = getUniqueSlug(slugValue, input);
                        
                        // Update input value with unique slug
                        input.val(slugValue);
                    }
                    
                    // Always update the display
                    var displayField = $(this).closest('tr').find('.acf-field-shortcode-display .acf-input');
                    if (input.val()) {
                        displayField.html('<code style="user-select: all;">[mpf name="' + input.val() + '"]</code>');
                    } else {
                        displayField.html('Shortcode will appear here');
                    }
                });
            }

            // Update on keyup for immediate feedback
            $(document).on('keyup', '.acf-field-shortcode-name input', function() {
                updateShortcodeDisplay();
            });

            // Update on change for paste events
            $(document).on('change', '.acf-field-shortcode-name input', function() {
                updateShortcodeDisplay();
            });

            // Update when new row is added
            acf.add_action('append', function($el) {
                updateShortcodeDisplay();
            });

            // Initial update
            $(document).ready(function() {
                updateShortcodeDisplay();
            });
        })(jQuery);
        </script>
        <?php
    }
}
add_action('admin_footer', 'mpf_admin_scripts');