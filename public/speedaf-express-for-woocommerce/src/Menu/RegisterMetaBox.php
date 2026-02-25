<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Menu;

defined( 'ABSPATH' ) || exit; // block direct access.
use Wolf\Speedaf\Core\Providers;
use Wolf\Speedaf\Helper\Meta_box;
use Wolf\Speedaf\Helper\SpfHelper;
class RegisterMetaBox extends Providers
{

    protected $_template = 'order/meta-box.php';

    public function register() {
        add_action( 'add_meta_boxes', function() {
            add_meta_box(
                '_spf-shipment-box',
                'Speedaf Shipment',
                [$this,'boxCallback'],
                'shop_order',
                'side',
                'core'
            );
        } );

        add_action('admin_enqueue_scripts', [__CLASS__,'enqueue_styles']);
      
      
    }


    public static function enqueue_styles() {
      
        wp_enqueue_script(  PLUGIN_NAME.'tracking', plugin_dir_url( SPF_ROOT_PATH ) . 'assets/dist/speedaf_tracking.bundle.js', array('jquery'), SPF()->getVersion().'0718', 'all' );
        OrderList::common_styles(PLUGIN_NAME.'tracking');
    }

    public function boxCallback($post) {
        if($post->post_type !== 'shop_order' || ! ($track_number =  Meta_box::getPostMeta($post->ID,Meta_box::SPF_ORDER_TRACKING)) ) return;

         $data = [];
       $data['tracking'] = $track_number;
       $data['shipping_status'] = SpfHelper::trackingField(Meta_box::getPostMeta($post->ID,Meta_box::SPF_ORDER_STATUS));
       $data['canel_status']   = Meta_box::getPostMeta($post->ID,Meta_box::SPF_ORDER_CANCEL_STATUS);
       
       $this->_data['data'] = $data;
        echo $this->getView()->render();
    }
    
}
