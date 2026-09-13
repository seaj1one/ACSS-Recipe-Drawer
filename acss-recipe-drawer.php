<?php
/**
 * Plugin Name: ACSS Recipe Drawer
 * Description: Renders a shadow-DOM recipe drawer at the bottom of the Builderius canvas, exposing ACSS built-in recipes plus user-defined custom recipes via autocomplete, with auto-unwrapped CSS output ready to copy into the CSS IDE.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Author: Stephen Jeffers
 * License: GPL-2.0-or-later
 *
 * @package ACSS_Recipe_Drawer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACSS_RECIPE_DRAWER_VERSION', '1.0.0' );
define( 'ACSS_RECIPE_DRAWER_FILE', __FILE__ );
define( 'ACSS_RECIPE_DRAWER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACSS_RECIPE_DRAWER_URL', plugin_dir_url( __FILE__ ) );

require_once ACSS_RECIPE_DRAWER_DIR . 'includes/class-acss-recipe-drawer-recipes.php';
require_once ACSS_RECIPE_DRAWER_DIR . 'includes/class-acss-recipe-drawer.php';
require_once ACSS_RECIPE_DRAWER_DIR . 'includes/class-acss-recipe-drawer-settings.php';

// Initialize the plugin on plugins_loaded so ACSS and Builderius are available.
add_action( 'plugins_loaded', array( 'ACSS_Recipe_Drawer', 'get_instance' ) );
