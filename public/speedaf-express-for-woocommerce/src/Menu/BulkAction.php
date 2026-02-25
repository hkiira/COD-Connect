<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Menu;

defined( 'ABSPATH' ) || exit; // block direct access.
use Automattic\Jetpack\Sync\Modules\Meta;
use Wolf\Speedaf\Core\Providers;
use Wolf\Speedaf\Helper\Meta_box;
use Wolf\Speedaf\Helper\SpfHelper;

class BulkAction extends Providers
{

    const ACTION_PATH ='spf_create_order';
    const CANCEL_SDF_SHIPPING = 'sdf_cancel_shipping';

    private static $errors;


    public function register() {
        //checked 
        if(! get_option(\Wolf\Speedaf\Menu\Settings::SDF_ENABELD_STATUS_KEY)) return;

        add_filter('bulk_actions-edit-shop_order', function($actions){
            $actions[self::ACTION_PATH]             = __('Speedaf Bulk Booking','speedaf-express-for-woocommerce');
            $actions[self::CANCEL_SDF_SHIPPING]     = __('Cancel Speedaf','speedaf-express-for-woocommerce');
    
    
            return $actions;
        }, 30);
        //order detail
        add_action('woocommerce_order_actions', function($actions){
            if(isset($_REQUEST['action']) && $_REQUEST['action'] === 'edit') {
                $actions[self::ACTION_PATH] = __('Shipment to Speedaf','speedaf-express-for-woocommerce');
            }
           
            return $actions;
        } );
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'createHandleShipment'], 10, 3);
        //detail
        add_action( 'woocommerce_order_action_'.self::ACTION_PATH, [$this,'createShipment' ]);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'CancelHandleOrder'], 10, 3);
        add_filter('manage_edit-shop_order_columns', [$this, 'addColumnHeader']);
		add_action('manage_shop_order_posts_custom_column', [$this, 'tableColumnContent'], 10, 2);
        
        add_action('admin_notices', [$this, 'show_notices']);
     }

     public function createShipment($order_id) {
        $screen = get_current_screen();
        if($screen->post_type !== 'shop_order') return;
         if($order_id instanceof \Automattic\WooCommerce\Admin\Overrides\Order) {
        
            $order_id = $order_id->get_id();
         }
         exit(wp_redirect($this->getCreateUrl((string)$order_id)));
     }
    
     private function getCreateUrl(string $id) {
        return   add_query_arg(['spf_ids' => $id,'source' => 'bulk'],admin_url('admin.php?page='.PLUGIN_NAME));
     }

     /**
      * create Order
      * @param mixed $redirect_to 
      * @param mixed $action 
      * @param mixed $post_ids 
      * @return false 
      */

     public function createHandleShipment($redirect_to, $action, $post_ids) {
 
        if($action !== self::ACTION_PATH) return false;
        $ids = implode('-',$post_ids);
        
         $redirect_to = $this->getCreateUrl($ids);
         //var_dump($redirect_to);
         exit( wp_redirect( $redirect_to) );
     }

     /**
      * cancel order
      * @param mixed $redirect_to 
      * @param mixed $action 
      * @param mixed $post_ids 
      * @return false|void 
      */

     public function CancelHandleOrder($redirect_to, $action, $post_ids) {
        
        if($action !== self::CANCEL_SDF_SHIPPING) return false;
       
        try{
            $service = SPF()->getSdkService('api');
            $msg =  $service->cancelOrder($post_ids);
        }catch(\Wolf\Speedaf\Core\SpfException $e) {
            $msg = $e->getMessage();
        }
    
       
        \SPF_ERROR::setErrors($msg);
        $redirect_to = add_query_arg(array(
            'spf'	=> 'cancel',
            //'msg' => $msg,
        ), $redirect_to);
       
       return $redirect_to;
     }

     /**
      * 
      * @param mixed $columns 
      * @return mixed 
      */
     public function addColumnHeader($columns) {
        $columns[Meta_box::SPF_ORDER_STATUS]         = __('Speedaf Status','speedaf-express-for-woocommerce');
		$columns[Meta_box::SPF_ORDER_TRACKING]       = __('Speedaf Tacking','speedaf-express-for-woocommerce');
        $columns[Meta_box::SPF_ORDER_CANCEL_STATUS]  = __('Speedaf Cancelled','speedaf-express-for-woocommerce');
		return $columns;
     }

      /**
       * 
       * @param mixed $column 
       * @param mixed $order_id 
       * @return void 
       */
     public function tableColumnContent($column,$order_id) {
            switch($column) {
               case Meta_box::SPF_ORDER_STATUS:
                   echo SpfHelper::trackingField( Meta_box::getPostMeta($order_id,Meta_box::SPF_ORDER_STATUS));break;
               case Meta_box::SPF_ORDER_CANCEL_STATUS:
                    $cancel_status  = Meta_box::getPostMeta($order_id,Meta_box::SPF_ORDER_CANCEL_STATUS);
                    if($cancel_status) echo __('Cancel Booked','speedaf-express-for-woocommerce');
                    break;
               case Meta_box::SPF_ORDER_TRACKING:
                    echo Meta_box::getPostMeta($order_id,Meta_box::SPF_ORDER_TRACKING);break;
            }
     }

     public function show_notices() {
        $screen            = get_current_screen()->id;
 

        if ($screen !== 'edit-shop_order' ) {
			return '';
		}
        if( ! ($msg = \SPF_ERROR::getErrors())) return '';
 
        echo '<div class="notice notice-warning is-dismissible"><p><strong style="color:red;">'.esc_html( $msg).'</strong> </p><button type="button" class="notice-dismiss"><span class="screen-reader-text">忽略此通知。</span></button></div>';

     }
    
}
