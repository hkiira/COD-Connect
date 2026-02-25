<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Menu;
defined( 'ABSPATH' ) || exit; // block direct access.

use Wolf\Speedaf\Core\Providers;
class Languages extends Providers
{

    public function register() {
        add_action( 'init', function(){
            load_plugin_textdomain( 'speedaf-express-for-woocommerce', false, dirname( plugin_basename( SPF_ROOT_PATH ) ) . '/i18n/languages' );
        } );
     }
   
    
}
