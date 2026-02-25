<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Menu;
defined( 'ABSPATH' ) || exit; // block direct access.
use Automattic\Jetpack\Sync\Modules\Meta;
use Exception;
use Wolf\Speedaf\Api\RegisterInterface;
use Wolf\Speedaf\Core\Providers;

use Wolf\Speedaf\Helper\Meta_box;
use Wolf\Speedaf\Core\SpfException;
class OrderList extends Providers
{
    protected $_template = 'table/order.php';


   //updated API
    protected static $isUpdateOrder = false;


    public function register() {
        add_action( 'admin_menu', function(){
            wc_admin_connect_page(
				array(
					'id'        => PLUGIN_NAME,
					'screen_id' => 'woocommerce_page_'.PLUGIN_NAME,
					'title'     => __( 'Speedaf Order for WooCommerce', 'speedaf-express-for-woocommerce' ),
				)
			);
           
             add_submenu_page( 
                'woocommerce', 
                __( 'Speedaf Express for WooCommerce','speedaf-express-for-woocommerce'), 
                __( 'Speedaf Express','speedaf-express-for-woocommerce'),
                 'manage_woocommerce',
                 PLUGIN_NAME,                
                [$this, 'displayTable']
            );  

         //   remove_submenu_page('admin.php',PLUGIN_NAME);
        });
      
       
        add_action('admin_enqueue_scripts', [$this,'enqueue_styles']);
        add_action('wp_ajax_spf_handler',[$this,'ajax_handler']);
     }


     public function ajax_handler() {
         header( 'Content-Type: application/json' );
          $request = $this->getRequest();
          $response = [
            'msg' => __('error not params','speedaf-express-for-woocommerce'),
            'data' => [ 
                'confirm_html' => '',
                'order_html' => '',
            ],
            'type' => '',
            'success' => false
          ];
        // var_dump( check_ajax_referer('spf-create-label')); // dies without valid nonce
          if(empty($request)) return wp_json_encode($response);

          if(empty($request['action']) || $request['action'] !== 'spf_handler') return wp_json_encode($response);

          if(empty($request['type'])) return wp_json_encode($response);
          try{
            $response['type'] = $request['type'];
            $api = $this->_service->getSdkService('api');

            $data = [];
            switch($request['type']) {
               case 'spfLabel':
                  $req = [
                    'waybillNoList' => [],
                    'labelType' => 1
                  ];
                   $request =  $this->getRequest();
                   $ids = isset($request['ids']) ? $this->sanitize_filterIds($request['ids']) : (isset($request['billID']) ? $request['billID'] : '');

                  if(empty($ids)) throw new SpfException(__('not found Order Id','speedaf-express-for-woocommerce'));
                   
                   
                  if(isset($request['labelType'])) {
                    $req['labelType'] =(int) $request['labelType'];
                  }
                  if(isset($request['ids'])) {
                    foreach($ids as $id) {
                 
                      $req['waybillNoList'][] = Meta_box::getPostMeta($id,Meta_box::SPF_ORDER_TRACKING);
                    }
                  }else if(isset($request['billID'])) {
                       $req['waybillNoList'][] =  $ids;
                  }
              //  var_dump($req);exit;

                  if(count($req['waybillNoList']) === 0) throw new SpfException(__(sprintf('the ids:%s not sender order',implode(',',$ids)),'speedaf-express-for-woocommerce'));

                 
                  $result = $api->printLabel($req);
                  if(empty($result['compressUrl'])) throw new SpfException(__('Please contact customer service','speedaf-express-for-woocommerce'),401);
                  $data['label_url'] = $result['compressUrl'];
               
                   break;
               case 'spfCreate':
                  
                  if(empty($request['ids'])) throw new SpfException('not found ID',401);
                  $orderIds = [];
                  $customData = [];
                  foreach($request['ids'] as $item) {
                    $oid = 0;
                     if($item['name'] === 'order_id') {
                        $orderIds[] = $item['value'];
                        $oid  = $item['value'];
                     }
                    $filed =  explode('-',$item['name']);
                    if($oid) $customData['allow_shipment_opened'][$oid] = 0;
                    if(count($filed) !== 2) continue;
                    $customData[$filed[0]][$filed[1]] = $item['value'];
                  }
         
                   $orderIds = $this->sanitize_filterIds($orderIds);
                   //checked id
                   if(empty($orderIds)) throw new SpfException(__('not found ID','speedaf-express-for-woocommerce'),401);

                 $result = $api->setData(['ids' => $orderIds,'customData' => $customData])->senderOrder();
               
                  $confirmData = $result['note'];
                   $confirmData['template'] = 'table/order-sender-popup.php';
                   $confirmHtml = $this->getView()->setData($confirmData)->render();
                  $data['confirm_html'] = $confirmHtml;
                   
                   $data['order_html'] = $this->getTableList($orderIds);
                   $response['success'] = true;
                    break;
                case 'spfEditSender':
                    $request = array_filter( $this->getRequest());
                   // var_dump($request);exit;
                    //validate
                    $this->validateShipping($request);
                    $optionKeys = array_keys($request);
                    foreach($optionKeys as $option) {
                       if(in_array($option,[
                          'woocommerce_store_address',
                          'woocommerce_store_city',
                          'woocommerce_store_postcode',
                          'woocommerce_default_country',
                          'spf_speedaf_name',
                          'spf_speedaf_phone',
                          'woocommerce_store_address_2',
                       ],true) && get_option($option) !== $request[$option]) {
                          switch($option) {
                            case 'woocommerce_store_postcode':
                              $location = wc_format_country_state_string($request['woocommerce_default_country']);
                              $value   = wc_format_postcode(  $request[$option], $location['country'] );
                              if(empty($value))  throw new SpfException(__('Please Enter Store Postcode','speedaf-express-for-woocommerce'));
                              if(!\WC_Validation::is_postcode( $value,  $location['country'] ) ) throw new SpfException(__('Store Postcode Invalid','speedaf-express-for-woocommerce'));
                             break;
                            case 'spf_speedaf_phone':
                              if(!\WC_Validation::is_phone($request[$option])) throw new SpfException(__('Sender Phone Invalid','speedaf-express-for-woocommerce'));
                              break;
                           
                          }
                           update_option($option,sanitize_text_field($request[$option]));
                       }
                    }
                   
                    $response['msg'] = __('updated success','speedaf-express-for-woocommerce');
                    break;
                case "spfTracking":
                    if(empty($request['bill'])) throw new SpfException(__('Please Enter Tacking Id ','speedaf-express-for-woocommerce'));
                     $res = $api->getTracking([$request['bill']]);
                   //  var_dump($res);exit;
                     if(!isset($res[0])) throw new SpfException(__('Please contact customer service','speedaf-express-for-woocommerce'));
                     $track = [
                      'template' => 'order/tracking.php'
                     ];
                     $track['tracking'] = $res[0];
                     $data['tracking_html'] = $this->getView()->setData($track)->render();
                     break;
   
               
               
               case 'spfPrintOrder':
                    $request =  $this->getRequest();
                    if(empty($request['ids'])) throw new SpfException(__('not found ID','speedaf-express-for-woocommerce'));
                    $ids =  $this->sanitize_filterIds($request['ids']);
                    if(empty($request['ids'])) throw new SpfException(__('not found ID','speedaf-express-for-woocommerce'));
                    $data['print_html'] = $this->getPrintOrder($ids);
                    break;
                default:
                
            }
            $response['success'] = true;
          }catch(SpfException $e) {
               $response['msg'] = $e->getMessage();
               $response['code'] = $e->getCode();
               $response['success'] = false;
          }
      
         $response['data'] = $data;
         
         echo  wp_json_encode($response);
         wp_die();
     }

     private function validateShipping($request){
      if(!array_key_exists('woocommerce_store_address',$request)) throw new SpfException(__('Please Enter Store Address','speedaf-express-for-woocommerce'));

      //if(!array_key_exists('woocommerce_store_city',$request)) throw new SpfException(__('Please Enter Store Address','speedaf-express-for-woocommerce'));
      if(!array_key_exists('woocommerce_default_country',$request)) throw new SpfException(__('Please Enter Store Country','speedaf-express-for-woocommerce'));
      if(!array_key_exists('woocommerce_store_postcode',$request)) throw new SpfException(__('Please Enter Store Postcode','speedaf-express-for-woocommerce'));
      if(!array_key_exists('spf_speedaf_name',$request)) throw new SpfException(__('Please Enter Shipping Name','speedaf-express-for-woocommerce'));
      if(!array_key_exists('spf_speedaf_phone',$request)) throw new SpfException(__('Please Enter Shipping Phone','speedaf-express-for-woocommerce'));

     }
   
     /**
      * Validation data
      * @return array 
      */
  
     private function getRequest() {
        return array_map(function($val){
         return wc_clean( wp_unslash( $val));
        },$_REQUEST);
       
     }

     /**
      * 
      * @param mixed $js_id 
      * @return void 
      */
     private static function ajax_enqueue($js_id) {
       // if ( strpos($hook, 'page_speedaf') !== false ) {
           $create_label_nonce = wp_create_nonce('spf-create-label');
           wp_localize_script( $js_id, 'sdfOrderData', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'action'   => 'spf_handler',
            'printLabel' => 'spfLabel',
            'createOrder' => 'spfCreate',
            'printOrder' => 'spfPrintOrder',
            'editSender'  => 'spfEditSender',
            'tracking'   => 'spfTracking',
            'Warning'    => __('Warning'),
            'Success'     => __('Success'),
            'createMsg'   => __( 'Do you want to create a Shipments?','speedaf-express-for-woocommerce'),
            'updateMsg'   => __( 'Do you want to update Shipments?','speedaf-express-for-woocommerce'),
            'not_found'   => __('Please checked ID','speedaf-express-for-woocommerce'),
            'please_enter' => __('Please Enter ','speedaf-express-for-woocommerce'),
            'select_ids'   => __('Please choose Ids','speedaf-express-for-woocommerce'),
            'confirm_button'    =>  __('Confirm'),
            'Closed'           => __('Closed'),
            'edit_note'   => sprintf('%s',__('Update Sender info success','speedaf-express-for-woocommerce')),
            'loading'     => plugin_dir_url( SPF_ROOT_PATH ) . 'assets/img/loading2.gif',
            'nonce' => $create_label_nonce,
            'cancelBtn'    => __('Cancel','speedaf-express-for-woocommerce'),
            'okBtn'        => __('Yes','speedaf-express-for-woocommerce')
        ));
      //  }
     }

     /**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles($hook) {

		if ( strpos($hook, 'page_speedaf') !== false ) {
		
     
		//	wp_style_add_data( PLUGIN_NAME."-settings", 'rtl', 'replace' );
          wp_enqueue_script( PLUGIN_NAME, plugin_dir_url( SPF_ROOT_PATH ) . 'assets/dist/speedaf_admin.bundle.js', array( 'jquery' ), $this->_service->getVersion().'0909', 'all' );
        //  wp_register_script('spfConfirm', plugin_dir_url( SPF_ROOT_PATH ) . 'assets/js/confirm.min.js', '', SPF()->getVersion(), false);	
          //  self::common_styles(PLUGIN_NAME);
			   
		}
         
        
	}

  public static function common_styles($js_id) {

    wp_enqueue_style( PLUGIN_NAME."-settings-confirm", plugin_dir_url( SPF_ROOT_PATH ) . 'assets/css/speedaf-confirm.min.css', array(), SPF()->getVersion(), 'all' );
    wp_enqueue_style( PLUGIN_NAME."-order", plugin_dir_url( SPF_ROOT_PATH ) . 'assets/css/order.min.css', array(),SPF()->getVersion().'11', 'all' );
       wp_enqueue_style( PLUGIN_NAME."-settings-remodal", plugin_dir_url( SPF_ROOT_PATH ) . 'assets/css/remodal.css', array(), SPF()->getVersion(), 'all' );
   
        wp_enqueue_style( PLUGIN_NAME."-settings-remodal-theme", plugin_dir_url( SPF_ROOT_PATH ) . 'assets/css/remodal-default-theme.css', array(), SPF()->getVersion(), 'all' );
       //  wp_register_script('remodal', plugin_dir_url( SPF_ROOT_PATH ) . 'assets/js/remodal.min.js', '', SPF()->getVersion(), false);
        // wp_register_script('spfLoading', plugin_dir_url( SPF_ROOT_PATH ) . 'assets/js/loading.min.js', '', SPF()->getVersion().rand(0,500), false);
         
         self::ajax_enqueue($js_id);
  }
  /**
   * 
   * @return void 
   * @throws Exception 
   */

     public function displayTable() {
      
      $LIST_HEADER_FILEDS = [
        0 => __('Order #','woocommerce'),
       2 =>  __('COD','speedaf-express-for-woocommerce'),
       3 => __('Total','speedaf-express-for-woocommerce'),
       4 => __('Weight(KG)','speedaf-express-for-woocommerce'),
       5 => __('Adjustment Status','speedaf-express-for-woocommerce'),
       6 =>  __('Shipping Status','speedaf-express-for-woocommerce'),
       7 => __('tracking number','speedaf-express-for-woocommerce'),
       8 =>  __('Product info','speedaf-express-for-woocommerce'),
       9 =>  __('Date'),
       10 =>  __('AcceptName','speedaf-express-for-woocommerce'),
        11 =>  __('Accept Telephone','speedaf-express-for-woocommerce'),
        12 =>  __('Accept City','speedaf-express-for-woocommerce'),
        13 =>  __('PDF','speedaf-express-for-woocommerce')
    ];
       
         $this->_data['labelType'] = $this->_service->getSdkService('config','labelType');
        // var_dump($this->getTableList(),$this->getView()->render());exit;
        
         $this->_data['order_html'] = $this->getTableList();
         $this->_data['go_back'] = add_query_arg(['post_type' => 'shop_order'],admin_url('edit.php'));
        if((int)get_option(\Wolf\Speedaf\Menu\Settings::SDF_ALLOW_SHIPMENT_OPENED) === 1) {
           $LIST_HEADER_FILEDS[1] = __("Allow shipment to be opened",'speedaf-express-for-woocommerce');
        }
        ksort( $LIST_HEADER_FILEDS);
         $this->_data['table_header'] =  $LIST_HEADER_FILEDS;
         $this->_data['isUpdateOrder'] = self::$isUpdateOrder;
         $this->_data['vip_url_path'] = sprintf('%slogin#/order/order-search?key=%s&bill=',$this->_service->getSdkService('config')['vip_path'],get_option(\Wolf\Speedaf\Menu\Settings::SDF_APK_KEY));
         $this->_data['order_search_id'] = isset($_REQUEST['order_search_id']) ?sanitize_text_field($_REQUEST['order_search_id']): '';
       echo  $this->getView()->render();
       
     }


     private function getPrintOrder($ids) {
            $orders = [];
            $args = [
              'post__in' => $ids
           ];
           $args['meta_key'] =  Meta_box::SPF_ORDER_TRACKING;
           $args['meta_value'] = 0;
            $args['meta_compare'] = '>';
            $result= wc_get_orders($args);
            foreach($result as  $order) {
              $orders[] = [
                'bill_id' => $order->get_meta(Meta_box::SPF_ORDER_TRACKING),
                'order_id' => $order->get_id(),
                'sender_time' => $order->get_meta(Meta_box::SPF_SHIPMENT_TIME),
                'sender_city'  => $order->get_meta(Meta_box::SPF_SHIPMENT_SENDER_CITY),
                'accept_name'  => trim($order->get_formatted_shipping_full_name()) ? $order->get_formatted_shipping_full_name() : $order->get_formatted_billing_full_name(),
                'accept_city'  => trim($order->get_shipping_city()) ? $order->get_shipping_city(): $order->get_billing_city(),
                'order_qty'    => $order->get_total(),
                'weight'       => $order->get_meta(Meta_box::SPF_PACK_ROW_WEIGHT),
                 'cod'         => $order->get_meta(Meta_box::SPF_ORDER_COD)
              ];
            }
          

            if(empty($orders)) return '';

            return $this->getView()->setData(['template' => 'table/print-order.php','orders' => $orders,'api_key' => SPF()->getSdkService('api')->getCustomerCode( get_option(Settings::SDF_APK_KEY))])->render();
     }

     /**
      * 
      * @param array $ids 
      * @return mixed 
      * @throws Exception 
      */

     private function getTableList($ids = []) {
        $reqest = $this->getRequest();
    
        //default
        $args = [
          'date_created' => '>' . ( time() - DAY_IN_SECONDS ),
        ];
        $exData = [
          'vip_url_path' => sprintf('%slogin#/order/order-search?key=%s&bill=',$this->_service->getSdkService('config')['vip_path'],get_option(\Wolf\Speedaf\Menu\Settings::SDF_APK_KEY))
        ];
        //from order
        if(!empty($reqest['spf_ids'])) {
            $orderIds = $this->sanitize_filterIds(explode('-',$reqest['spf_ids']));
             $args = [
                'post__in' => $orderIds
             ];
             $exData['box_check'] = true;
        }
        //order query
        if(!empty($ids)) {
          $args = [
            'post__in' => $ids,
         ];
        }
       
        if(isset($reqest['search-botton']) && (int)$reqest['search-botton'] === 1 ) {
          $args = [];
       
            if(isset($reqest['order_search_id']) && $reqest['order_search_id']) {
                $orderId = $reqest['order_search_id'];
                $orders = wc_get_orders( array(
                  'meta_key'     => Meta_box::SPF_ORDER_TRACKING, // The postmeta key field
                  'meta_value' => $orderId, // The comparison argument
              ));
              
             if(count($orders) === 0) {
                $args = [
                  'post__in' => [$orderId],
                ];
               
             }
              
            }
            else {
              $args['meta_key'] =  Meta_box::SPF_ORDER_TRACKING;
              $args['meta_value'] = 0;
             $args['meta_compare'] = '>';
            }
            //date search
            if(!empty($reqest['date_end'])) {
              $args['date_before']  = $reqest['date_end'];
            }
            if(!empty($reqest['date_start'])) {
              $args['date_after']  = $reqest['date_start'];
            }
          
         
            
        } 

        if(empty($orders)) $orders = wc_get_orders($args);
     //  var_dump($orders);
      if(count($orders) === 0) return '';
      //check updated status
       foreach($orders as $order) {
         if((int)$order->get_meta(\Wolf\Speedaf\Menu\AllowShipmentOpened::ALLOW_UPDATE_ORDER_FLAG) === 1 
         && Meta_box::getPostMeta($order->get_id(),Meta_box::SPF_ORDER_TRACKING)) {
           self::$isUpdateOrder = true;break;
         }
       }
       // $this->_data['orders'] = $orders;
       return $this->getView()->setData(array_merge(['template' => 'table/order-body.php','orders' => $orders,'helper' =>  new  \Wolf\Speedaf\Helper\SpfHelper()],$exData))->render();
     }

     /**
      * 
      * @param array $ids 
      * @return array 
      */

     private function sanitize_filterIds(array $ids) {
        return array_filter($ids,function($val){
            return (is_numeric($val));
       });
     }

    
    
}
