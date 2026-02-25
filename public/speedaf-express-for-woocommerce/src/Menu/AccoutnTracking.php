<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Menu;
defined( 'ABSPATH' ) || exit; // block direct access.
use Wolf\Speedaf\Core\Providers;
use Wolf\Speedaf\Helper\Meta_box;
class AccoutnTracking extends Providers
{
    protected $_template = 'account/tracking.php';

    public function register() { 
        add_filter('woocommerce_my_account_my_orders_columns',function($columns){
            $columns['spf_track_number'] = __('Speedaf tracking');

            return $columns;
        });
        add_action('woocommerce_my_account_my_orders_column_spf_track_number',function($order){
            $track_number = Meta_box::getPostMeta($order->get_id(),Meta_box::SPF_ORDER_TRACKING);
            if($track_number) {
                $this->_data['track_number'] = $track_number;
                echo $this->getView()->render(); 
            }
        
        });
        add_action('wp_enqueue_scripts', function(){
            wp_enqueue_script(  PLUGIN_NAME.'tracking', plugin_dir_url( SPF_ROOT_PATH ) . 'assets/dist//speedaf_tracking.bundle.js', array('jquery'), SPF()->getVersion().rand(1,5000), 'all' );
            OrderList::common_styles(PLUGIN_NAME.'tracking');
        });
       
    }
    
}
