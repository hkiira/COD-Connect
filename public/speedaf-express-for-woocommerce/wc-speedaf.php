<?php
/**
 * Plugin Name: Speedaf Express for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/woocommerce-speedaf-express/
 * Description: Receive Shipping using the Speedaf Express provider.
 * Author: Speedaf
 * Author URI: https://wc.zbocloud.com/
 * Domain Path: /i18n/languages/
 * Version: 1.2.2
 * WC tested up to: 8.0.2
 * WC requires at least: 6.8.2
 * Woo: 178178:b271ae89b8b86c34020f58af2f4cbc81
 * php version 7.4
 *
 * @category Shipping
 * @class    WC_Speedaf
 * @package  WooCommerce
 * @author   MTA <wolf.tan@zbocloud.com>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     https://www.speedaf.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SDF_VERSION', '1.2.2' );
define('API_DEBUG',false);
define( 'SDF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define('SPF_ROOT_PATH',__FILE__);
define('PLUGIN_NAME','speedaf');
define('API_VERSION' ,'spf-express/v1');

// Include the main WooCommerce class.
 if (!isset($is_loading_speedaf_register) || $is_loading_speedaf_register === false) {
	include_once SDF_PLUGIN_DIR . 'bootstrap.php';
    $is_loading_speedaf_register = true;

}

function activate_install() {
    global $wpdb;
        (new \Wolf\Speedaf\Installr($wpdb))->install();
}
 
register_activation_hook( __FILE__, 'activate_install' );

function activate_uninstall () {
	//exit('uninstall');
    global $wpdb;
    include_once plugin_dir_path(SPF_ROOT_PATH).'src/Installr.php';
    (new Wolf\Speedaf\Installr($wpdb))->unstall();

} 

register_deactivation_hook( __FILE__, 'activate_uninstall' );


/**
 * Loads speedaf.
 */
add_action( 'plugins_loaded', 'spf_extension_initialize', 10 );


