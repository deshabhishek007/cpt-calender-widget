<?php
/*
Plugin Name: CPT Calendar Widget
Description: This plugin creates a new widget in the widget area for Custom Post Types, allowing users to select a CPT and display a calendar in the sidebar for searching posts and events.
Version: 1.0.1
Author: Team Peenak
Author URI: https://peenak.com
Text Domain: cpt-calendar-widget
*/

/**
 * CPT Calendar Widget Class
 */

class HMT_CPT_Calendar extends WP_Widget {

    /** Constructor */
    public function __construct() {
        parent::__construct(
            'hmt_cpt_calendar', // Base ID
            __('CPT Calendar', 'cpt-calendar-widget'), // Name
            array('description' => __('A widget to display a calendar based on custom post types', 'cpt-calendar-widget'), ) // Args
        );
    }

    /** @see WP_Widget::widget */
    public function widget($args, $instance) {
        $title = apply_filters('widget_title', $instance['title']);
        $posttype_enabled = !empty($instance['posttype_enabled']) ? $instance['posttype_enabled'] : false;
        $posttype = !empty($instance['posttype']) ? $instance['posttype'] : '';

        echo $args['before_widget'];
        if ($title) {
            echo $args['before_title'] . $title . $args['after_title'];
        }

        echo '<div class="widget_calendar"><div id="calendar_wrap">';
        if($posttype_enabled && !empty($posttype)) {
            ucc_get_calendar(array($posttype));
        } else {
            ucc_get_calendar();
        }
        echo '</div></div>';
        echo $args['after_widget'];
    }

    /** @see WP_Widget::update */
    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? strip_tags($new_instance['title']) : '';
        $instance['posttype_enabled'] = (!empty($new_instance['posttype_enabled'])) ? (bool) $new_instance['posttype_enabled'] : false;
        $instance['posttype'] = (!empty($new_instance['posttype'])) ? $new_instance['posttype'] : '';

        return $instance;
    }

    /** @see WP_Widget::form */
    public function form($instance) {
        $title = !empty($instance['title']) ? esc_attr($instance['title']) : __('New title', 'cpt-calendar-widget');
        $posttype_enabled = !empty($instance['posttype_enabled']) ? (bool) $instance['posttype_enabled'] : false;
        $posttype = !empty($instance['posttype']) ? $instance['posttype'] : '';
        $posttypes = get_post_types(array('public' => true), 'objects');

        // Widget admin form
        ?>
        <p>
          <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:', 'cpt-calendar-widget'); ?></label> 
          <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" />
        </p>
        <p>
          <input id="<?php echo $this->get_field_id('posttype_enabled'); ?>" name="<?php echo $this->get_field_name('posttype_enabled'); ?>" type="checkbox" <?php checked($posttype_enabled, true); ?>/>
          <label for="<?php echo $this->get_field_id('posttype_enabled'); ?>"><?php _e('Show only one post type?', 'cpt-calendar-widget'); ?></label> 
        </p>
        <p>
          <label for="<?php echo $this->get_field_id('posttype'); ?>"><?php _e('Choose the Post Type to display:', 'cpt-calendar-widget'); ?></label> 
          <select name="<?php echo $this->get_field_name('posttype'); ?>" id="<?php echo $this->get_field_id('posttype'); ?>" class="widefat">
            <?php
            foreach ($posttypes as $option) {
                echo '<option value="' . esc_attr($option->name) . '" ' . selected($posttype, $option->name, false) . '>' . esc_html($option->name) . '</option>';
            }
            ?>
          </select>   
        </p>
        <?php 
    }
}



// Register CPT Calendar widget
add_action('widgets_init', function() {
    register_widget('HMT_CPT_Calendar');
});


function ucc_get_calendar($post_types = '', $initial = true, $echo = true) {
    global $wpdb, $m, $monthnum, $year, $wp_locale, $posts;

    // Define $week_begins based on the 'start_of_week' option
    $week_begins = intval(get_option('start_of_week'));

    // Determine the post types to include
    if (empty($post_types) || !is_array($post_types)) {
        $args = array(
            'public' => true,
            '_builtin' => false
        );
        $post_types = get_post_types($args, 'names', 'and');
        $post_types[] = 'post'; // Include standard posts by default
    } else {
        $post_types = array_filter($post_types, 'post_type_exists');
    }

    $post_types_key = implode('', $post_types);
    $post_types_sql = "'" . implode("', '", $post_types) . "'";

    // Cache key
    $key = md5($m . $monthnum . $year . $post_types_key);
    $cache = wp_cache_get('get_calendar', 'calendar');

    if (is_array($cache) && isset($cache[$key])) {
        if ($echo) {
            echo $cache[$key];
            return;
        } else {
            return $cache[$key];
        }
    }

    if (!is_array($cache)) {
        $cache = array();
    }

    // Quick check for posts
    $sql = "SELECT 1 FROM $wpdb->posts WHERE post_type IN ($post_types_sql) AND post_status = 'publish' LIMIT 1";
    if (!$wpdb->get_var($sql)) {
        return;
    }

    // Determine the date
    $thisyear = !empty($year) ? intval($year) : gmdate('Y', current_time('timestamp'));
    $thismonth = !empty($monthnum) ? zeroise(intval($monthnum), 2) : gmdate('m', current_time('timestamp'));
    $unixmonth = mktime(0, 0, 0, $thismonth, 1, $thisyear);

    // Retrieve the next and previous months
    
    // Get the next and previous month and year with at least one post
    $previous = $wpdb->get_row($wpdb->prepare(
        "SELECT MONTH(post_date) AS month, YEAR(post_date) AS year
        FROM $wpdb->posts
        WHERE post_date < %s
        AND post_type IN ($post_types_sql) AND post_status = 'publish'
        ORDER BY post_date DESC
        LIMIT 1",
        "$thisyear-$thismonth-01"
    ));

    $next = $wpdb->get_row($wpdb->prepare(
        "SELECT MONTH(post_date) AS month, YEAR(post_date) AS year
        FROM $wpdb->posts
        WHERE post_date > %s
        AND MONTH(post_date) != MONTH(%s)
        AND post_type IN ($post_types_sql) AND post_status = 'publish'
        ORDER BY post_date ASC
        LIMIT 1",
        "$thisyear-$thismonth-01", "$thisyear-$thismonth-01"
    ));


    // Start building calendar output
    // Start building the calendar HTML
    $calendar_output = '<table id="wp-calendar" summary="' . esc_attr__('Calendar', 'cpt-calendar-widget') . '">';
    $calendar_output .= '<caption>' . sprintf(__('%1$s %2$s', 'cpt-calendar-widget'), $wp_locale->get_month($thismonth), date('Y', $unixmonth)) . '</caption>';
    $calendar_output .= '<thead><tr>';

    // The headers for days of the week
    $myweek = array();
    for ($wdcount = 0; $wdcount <= 6; $wdcount++) {
        $myweek[] = $wp_locale->get_weekday(($wdcount + $week_begins) % 7);
    }

    foreach ($myweek as $wd) {
        $day_name = $initial ? $wp_locale->get_weekday_initial($wd) : $wp_locale->get_weekday_abbrev($wd);
        $calendar_output .= '<th scope="col" title="' . esc_attr($wd) . '">' . esc_html($day_name) . '</th>';
    }

    $calendar_output .= '</tr></thead>';

    $calendar_output .= '<tbody><tr>';

    // Get days with posts
    $dayswithposts = $wpdb->get_results("SELECT DISTINCT DAYOFMONTH(post_date) FROM $wpdb->posts WHERE MONTH(post_date) = '$thismonth' AND YEAR(post_date) = '$thisyear' AND post_type IN ($post_types_sql) AND post_status = 'publish' AND post_date < '" . current_time('mysql') . "'", ARRAY_N);
    $daywithpost = array();
    if ($dayswithposts) {
        foreach ($dayswithposts as $daywith) {
            $daywithpost[] = $daywith[0];
        }
    }

    // Padding for the first week
    $pad = calendar_week_mod(date('w', $unixmonth) - $week_begins);
    if ($pad != 0) {
        $calendar_output .= '<td colspan="' . esc_attr($pad) . '" class="pad">&nbsp;</td>';
    }

    $daysinmonth = intval(date('t', $unixmonth));
    for ($day = 1; $day <= $daysinmonth; ++$day) {
        if (isset($newrow) && $newrow) {
            $calendar_output .= '</tr><tr>';
        }
        $newrow = false;

        // Check if the day is today
        if ($day == gmdate('j', current_time('timestamp')) && $thismonth == gmdate('m', current_time('timestamp')) && $thisyear == gmdate('Y', current_time('timestamp'))) {
            $calendar_output .= '<td id="today">';
        } else {
            $calendar_output .= '<td>';
        }

        // Add the day number and link if it has posts
        if (in_array($day, $daywithpost)) {
            $calendar_output .= '<a href="' . get_day_link($thisyear, $thismonth, $day) . '">' . $day . '</a>';
        } else {
            $calendar_output .= $day;
        }
        $calendar_output .= '</td>';

        // End the row at the end of the week
        if (6 == calendar_week_mod(date('w', mktime(0, 0, 0, $thismonth, $day, $thisyear)) - $week_begins)) {
            $newrow = true;
        }
    }

    // Final row padding
    $pad = 7 - calendar_week_mod(date('w', mktime(0, 0, 0, $thismonth, $daysinmonth, $thisyear)) - $week_begins);
    if ($pad != 0 && $pad != 7) {
        $calendar_output .= '<td class="pad" colspan="' . esc_attr($pad) . '">&nbsp;</td>';
    }

    $calendar_output .= '</tr></tbody>';

    // Footer of the calendar for navigating between months
    $calendar_output .= '<tfoot><tr>';

    if ($previous) {
        $calendar_output .= '<td colspan="3" id="prev"><a href="' . get_month_link($previous->year, $previous->month) . '" title="' . sprintf(__('View posts for %1$s %2$s', 'cpt-calendar-widget'), $wp_locale->get_month($previous->month), date('Y', mktime(0, 0, 0, $previous->month, 1, $previous->year))) . '">&laquo; ' . $wp_locale->get_month_abbrev($wp_locale->get_month($previous->month)) . '</a></td>';
    } else {
        $calendar_output .= '<td colspan="3" id="prev" class="pad">&nbsp;</td>';
    }

    $calendar_output .= '<td class="pad">&nbsp;</td>';

    if ($next) {
        $calendar_output .= '<td colspan="3" id="next"><a href="' . get_month_link($next->year, $next->month) . '" title="' . esc_attr(sprintf(__('View posts for %1$s %2$s', 'cpt-calendar-widget'), $wp_locale->get_month($next->month), date('Y', mktime(0, 0, 0, $next->month, 1, $next->year)))) . '">' . $wp_locale->get_month_abbrev($wp_locale->get_month($next->month)) . ' &raquo;</a></td>';
    } else {
        $calendar_output .= '<td colspan="3" id="next" class="pad">&nbsp;</td>';
    }

    $calendar_output .= '</tr></tfoot>';



    $calendar_output .= '</table>';

    // Set and return the calendar output
    $cache[$key] = $calendar_output;
    wp_cache_set('get_calendar', $cache, 'calendar');

    if ($echo) {
        echo $calendar_output;
    } else {
        return $calendar_output;
    }

}

// Hook into 'get_calendar' to modify its output
add_filter('get_calendar', 'ucc_get_calendar_filter', 10, 2);

/**
 * Filter for the 'get_calendar' to use custom calendar output.
 * 
 * @param string $content The original calendar output.
 * @return string Modified calendar output.
 */
function ucc_get_calendar_filter($content) {
    // Get the custom calendar output.
    // Note: We don't need to pass arguments explicitly as they are defaulted in the function definition.
    $output = ucc_get_calendar();

    // Return the modified calendar output.
    return $output;
}
