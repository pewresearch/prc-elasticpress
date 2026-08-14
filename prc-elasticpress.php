<?php
/**
 * PRC ElasticPress
 *
 * @package           PRC_ELASTICPRESS
 * @author            Seth Rubenstein
 * @copyright         2024 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC ElasticPress
 * Plugin URI:        https://github.com/pewresearch/prc-elasticpress
 * Description:       Canonical ElasticPress integration for PRC Platform: search hardening, faceted filtering, blocks, and VIP Search configuration.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Seth Rubenstein
 * Author URI:        https://pewresearch.org
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-elasticpress
 * Requires Plugins:  prc-scripts, prc-publication-listing
 */

namespace PRC\Platform\ElasticPress;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// When running inside the PRC Platform monorepo the root autoloader already
// provides every dependency; skip per-plugin Jetpack Autoloader initialization.
if ( ! defined( 'PRC_PLATFORM' ) ) {
	$prc_elasticpress_autoloader = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $prc_elasticpress_autoloader ) ) {
		require_once $prc_elasticpress_autoloader;
	}
	unset( $prc_elasticpress_autoloader );
}

define( 'PRC_ELASTICPRESS_FILE', __FILE__ );
define( 'PRC_ELASTICPRESS_DIR', __DIR__ );
define( 'PRC_ELASTICPRESS_VERSION', '1.0.0' );

/**
 * Helper utilities
 */
require plugin_dir_path( __FILE__ ) . 'includes/utils.php';

/**
 * The core plugin class that is used to define the hooks that initialize the various components.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-plugin.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_prc_elasticpress() {
	$plugin = new Plugin();
	$plugin->run();
}
run_prc_elasticpress();
