<?php
/**
 * save order hook
 */
declare(strict_types=1);

namespace Wolf\Speedaf\Menu;
use  Wolf\Speedaf\Core\Providers;
use Wolf\Speedaf\Helper\Meta_box;
defined( 'ABSPATH' ) || exit; // block direct access.
class AllowShipmentOpened extends Providers{

    const ALLOW_UPDATE_ORDER_FLAG = '_spf_update_flag';
    
     const STATE_UPDATED   = 1;
     const STATE_PUSHED    = 2;

     const ALLOW_OPENED_CHECK_BOX = '_spf_allow_opened_check_box';
     

    public function register()
    {
        add_action('woocommerce_after_order_object_save',[$this,'_saveState']);
    }
    /**
     * save shipment opened flag
     *
     * @param [type] $order
     * @return void
     */
    public function _saveState($order) {
        $order_id = $order->get_id();
       
      if(isset($_REQUEST['action']) 
      && $_REQUEST['action'] === 'editpost' 
      && is_numeric($order_id)
      && !empty(Meta_box::getPostMeta($order_id,Meta_box::SPF_ORDER_TRACKING))
      ) {
     
        \Wolf\Speedaf\Helper\Meta_box::createMeta($order->get_id(),[self::ALLOW_UPDATE_ORDER_FLAG => self::STATE_UPDATED]);
      }
      
    }
}

