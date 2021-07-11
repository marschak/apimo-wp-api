<?php
/**
 * Plugin Name: Apimo API & WP
 * Version: 1.0
 * Author: colibrity agancy
 * Author URI: 
 * Description: The plugin is used to synchronize Apimo estates entries with WP  through Apimo JSON API
 */

// Includes plugin components
include_once dirname(__FILE__) . '/apimo-wpre-synchronizer-options.php';
include_once dirname(__FILE__) . '/apimo-wpre-synchronizer-main.php';
include_once dirname(__FILE__) . '/apimo-wpre-synchronizer-posts-by-content.php';

// Register the cron job
register_activation_hook(__FILE__, array('ApimoWPRESynchronizer', 'install'));

// Unregister the cron job
register_deactivation_hook(__FILE__, array('ApimoWPRESynchronizer', 'uninstall'));

// Trigger the plugin
ApimoWPRESynchronizer::getInstance();

// Trigger the settings page
if (is_admin()) {
    $apimo_WPRE_synchronizer_settings_page = new ApimoWPRESynchronizerSettingsPage();
}