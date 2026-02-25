<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Speedaf;
defined( 'ABSPATH' ) || exit; // block direct access.

use Automattic\Jetpack\Sync\Modules\Meta;
use Wolf\Speedaf\Core\SpfException;
use Wolf\Speedaf\Speedaf\CreateOrder;
use Wolf\Speedaf\Menu\Settings;
use Wolf\Speedaf\Helper\Meta_box;
use Wolf\Speedaf\DB\TableManager;
use Wolf\Speedaf\Menu\AllowShipmentOpened;
use Wolf\Speedaf\sdk\ApiServices;

class Carrier
{
    /**
     * Speedaf serive 
     * @var ApiServices
     */
    private $api;

    /**
     * 
     * @var array
     */
    private $data;

    /**
     * error info
     * @var array
     */
    private $errors = [];

    /**
     * 
     * @var Wolf\Speedaf\DB\TableManage
     */
    private $tableManage;
     /**
     * 
     * @var string
     */
    const TARACK_SUB_TABLE =  'track_sub';

    private $currentCustomerCode = '';
    public function __construct(\Wolf\Speedaf\sdk\ApiServices $api,array $data = []) 
    {
        global $wpdb;
        $this->api  = $api;
        $this->data = $data;
        if($apiKey = get_option(Settings::SDF_APK_KEY)) $this->currentCustomerCode= $this->getCustomerCode($apiKey);
        add_action( 'admin_notices', [$this,'error_notice']  );
        $this->tableManage = TableManager::getInstance($wpdb);
        
    }

    /**
     * 
     * @param mixed $key 
     * @param string $value 
     * @return $this 
     */
    public function setData($key,$value = '') {
        if(is_array($key))  {
            foreach($key as $k => $v) {
                $this->data[$k] = $v;
            }
        }else {
            $this->data[$key] = $value;
        }

        return $this;
    }

    /**
     * 
     * @return array 
     * @throws SpfException 
     */
    public function senderOrder() {
        try{
            $result = [
                'printLabel' => [
                    'waybillNoList' => [],
                    'labelType' => 1
                ],
               'note' => [
                'trackinfo' => [],
                'label_url' => '',
                'pack_num' => 0,
                ]
            ];
            $track_subscribe = [];

             $_updateOrderData = [];
    
            if(!$this->currentCustomerCode) throw new \Exception(sprintf(__('get Customer Code  Faild, Because: %1$s','speedaf-express-for-woocommerce'),'Please confirm Api key'));

            if(empty($this->data['ids'])) throw new \Exception(__('Please confirm Shipping Id,it is null','speedaf-express-for-woocommerce'));

              $object_ids = $this->data['ids'];
              //batch
        
              if((count($object_ids)) > 20) throw new \Exception(__('Batch order up to 20','speedaf-express-for-woocommerce'));

              foreach($object_ids as $order_id) {
                
                $order = wc_get_order($order_id);
               // var_dump(Meta_box::getPostMeta($order->get_id(),Meta_box::SPF_ORDER_TRACKING));exit;
                //has sender shipping
                $isUpdateOrder  = (int)Meta_box::getPostMeta($order->get_id(),AllowShipmentOpened::ALLOW_UPDATE_ORDER_FLAG);
               // $isAllowOpened =  Meta_box::getPostMeta($order->get_id(),AllowShipmentOpened::ALLOW_OPEND_CHECK_BOX);
               // $opened = isset($this->data['customData']['allow_shipment_opened'][$order_id]) ? $this->data['customData']['allow_shipment_opened'][$order_id] : 0;
                if(Meta_box::getPostMeta($order->get_id(),Meta_box::SPF_ORDER_TRACKING) &&  ($isUpdateOrder  === 2 || $isUpdateOrder === 0 )) {
                    $this->errors[] = $order_id;
                    continue;
                }
             
                
                $orderData = $this->resolveOrder($order,$order_id);
                $data = $orderData->getData();
              
                //update API
                if($isUpdateOrder === 1 
                && Meta_box::getPostMeta($order->get_id(),Meta_box::SPF_ORDER_TRACKING)
                ) {
                    $this->_updateOrder($data,$order,$order_id,$result);
                   // add API
                }else if(empty( Meta_box::getPostMeta($order->get_id(),Meta_box::SPF_ORDER_TRACKING))) {
                     
              
                    $res = $this->api->createOrder($data);
                    
                    if(isset($res['success']) && $res['success'] === true) {
                        $metaBox = [
                        \Wolf\Speedaf\Helper\Meta_box::SPF_ORDER_TRACKING => $res['billCode'],
                        \Wolf\Speedaf\Helper\Meta_box::SPF_ORDER_STATUS   => 10,
                        \Wolf\Speedaf\Helper\Meta_box::SPF_SHIPMENT_TIME  => wp_date('Y-m-d H:i:s'),
                        \Wolf\Speedaf\Helper\Meta_box::SPF_SHIPMENT_SENDER_CITY => $orderData->getData('sendCityName')

                        ];
                    
                        \Wolf\Speedaf\Helper\Meta_box::createMeta($order_id,$metaBox);
                    
                        $track_subscribe[$order_id] = $res['billCode'];
                        $this->_resultReturn($res,$result,$order);
                    
                    }else {
                      
                    $this->errors[] = $res['msg'];
                    }
                }
               
              

                unset($orderData);

             }

             if(count($this->errors)) throw new \Exception(sprintf(__('Please confirm Order Id(s) %1$s,May have been Booked?','speedaf-express-for-woocommerce'),implode(' , ',$this->errors)));

             //get Print label;
             if(!empty($result['printLabel']['waybillNoList'])) {
                $labelRes = $this->api->print($result['printLabel']);
                $result['note']['label_url'] = $labelRes['compressUrl'];
             }
            
        }catch(\Exception $e) {
            throw new SpfException($e->getMessage(),$e->getCode());
        }
      

        //track subscribe
        if(count($track_subscribe)) $this->_pushTrackSubscribe($track_subscribe);
        return $result;

    }

    private function _updateOrder($data,$order,$order_id,&$result) {
                //unset($data['itemList']);
                $errors = [];
                $data['billCode'] = Meta_box::getPostMeta($order->get_id(),Meta_box::SPF_ORDER_TRACKING);
                                
                $res = $this->api->updateOrder([$data]); 
                 foreach($res as $item) {
                     if($item['success'] === false) {
                        $errors[]=sprintf(__('the billId %1$s error info: %2$s','speedaf-express-for-woocommerce'),$data['billCode'],__($item['message'],'speedaf-express-for-woocommerce'));
                         continue;
                     }
                  
                    $metaBox = [\Wolf\Speedaf\Menu\AllowShipmentOpened::ALLOW_UPDATE_ORDER_FLAG => \Wolf\Speedaf\Menu\AllowShipmentOpened::STATE_PUSHED];
                    
                    \Wolf\Speedaf\Helper\Meta_box::createMeta($order_id,$metaBox);
                    $this->_resultReturn($item,$result,$order,"Updated Booked");
                    
                    
                 }
                 if(count($errors)) throw new \Exception(implode(' ',$errors));
           
    }
    /**
     * resolve order
     *
     * @param [type] $order
     * @param [type] $order_id
     * @return CreateOrder
     */
    private function resolveOrder($order,$order_id) :CreateOrder {
      //create push fileds
      $orderData = new CreateOrder($order);
      //custom weight
       if(isset($this->data['customData']['row_weight'][$order_id])) {
           $orderData->setData('parcelWeight',(float)$this->data['customData']['row_weight'][$order_id]);
           Meta_box::createMeta($order_id,[
               Meta_box::SPF_PACK_ROW_WEIGHT => $orderData->getData('parcelWeight')
           ]);
       }
       
       $opened = isset($this->data['customData']['allow_shipment_opened'][$order_id]) ? $this->data['customData']['allow_shipment_opened'][$order_id] : 0;
       Meta_box::createMeta($order_id,[
         AllowShipmentOpened::ALLOW_OPENED_CHECK_BOX => $opened
       ]);
        $orderData->setData('isAllowOpen',$opened);
        if($this->currentCustomerCode) $orderData->setData('customerCode',$this->currentCustomerCode);
       
      return $orderData;
    }
    /**
     * Undocumented function
     *
     * @param [array] $res
     * @param [array] $result
     * @param [type] $order
     * @return void
     */
    private function _resultReturn($res,&$result,$order,$tips = ' Booked') {
        //get Print Label
        $result['printLabel']['waybillNoList'][] = $res['billCode'];
        $result['note']['trackinfo'][] =  sprintf('%s   #%s  [%s:%s]',__('Order','woocommerce'),__($tips,'speedaf-express-for-woocommerce'),$order->get_order_number(),$res['billCode']);
        $result['note']['pack_num'] += 1;
        $order->save();


    }
    /**{
    "mailNo": "86254200001257",
    "customerCode": "232001",
    "notifyUrl": "http://localhost:8094/open-api/express/track/callback"
}

     * 
     * @param array $bill 
     * @return void 
     */

    private function _pushTrackSubscribe(array $bill) {
        try{
            $data = [];
            foreach($bill as $order_id => $id) {
                 $data = [
                    'mailNo' => $id,
                    'customerCode' => $this->getCustomerCode( get_option(Settings::SDF_APK_KEY)),
                    'notifyUrl'   => network_site_url(sprintf('/wp-json/%s/%s',API_VERSION,\Wolf\Speedaf\WebApi\TrackSubscribe::getRoute()))
                 ];
                 $res =  $this->api->track_subscribe($data);
                 if($res && isset($res['mailNo'])) {
                   $this->tableManage->getTable(self::TARACK_SUB_TABLE)->insert([
                       'order_id' => $order_id,
                       'bill_id'  => $id,
                       'subscribe_status' => 1,
                       'created_at'   => wp_date('Y-m-d H:i:s'),
                       'options'      => $data
       
                   ]);
                 }

            }
         
          $this->writeLog($res);
        }catch(\Exception $e) {
             $this->writeLog($e->getMessage());
             $this->tableManage->getTable(self::TARACK_SUB_TABLE)->insert([
                'order_id' => $order_id,
                'bill_id'  => $id,
                'subscribe_status' => 0,
                'created_at'   => wp_date('Y-m-d H:i:s'),
                'options'      => $data

            ]);
             
        }
        $this->writeLog($data);
    }
    

    /**
     * 
     * @param array $data 
     * @return mixed 
     * @throws SpfException 
     */
    public function printLabel(array $data) {
        try{
            if(!in_array((int)$data['labelType'],[1,2,3,5,6],true)) throw new \Exception(__('the Lable Type invalid','speedaf-express-for-woocommerce'));
            return $this->api->print($data);
        }catch(\Exception $e) {
            throw new SpfException($e->getMessage(),$e->getCode());
        }
      
    }

    /**
     * [
        {
            "customerCode":"860002",
            "billCode":"47233200734710",
            "cancelReason":"客户取消发货",
            "cancelBy":"TONY",
            "cancelTel":"135xxxx1029"
        }
        ...
    ]
     * @param array $data 
     * @return void 
     */
    public function cancelOrder(array $orderIds) {
         try{
            $data = [];
            if(empty($orderIds)) throw new \Exception(__('Please checking Cancel Id(s)','speedaf-express-for-woocommerce'));
            
            $msg = '';
            $phone = get_user_meta( get_current_user_id(),'billing_phone',true );
         
           $orderMap = [];
            foreach($orderIds as $key =>  $id) {
                $data[$key] = [
                    'customerCode' =>(string)$this->getCustomerCode( get_option(Settings::SDF_APK_KEY)),
                    'billCode'     => (string)Meta_box::getPostMeta($id,Meta_box::SPF_ORDER_TRACKING),
                    'cancelReason'  => __('Customer cancels shipment','speedaf-express-for-woocommerce'),
                    'cancelBy'      => wp_get_current_user()->user_nicename,
                    'cancelTel'     => $phone ? $phone : get_option('spf_speedaf_phone'),

                ];
                $orderMap[$data[$key]['billCode']] = $id;
              
         
           
            }
            //var_dump($data);exit;
          $res =   $this->api->cancel($data);

          $billId = '';
          if(is_array($res) && count($res)) {
            $msg = '';
            foreach($res as $data) {
                 if($data['success'] === false) {
                   $this->errors[] = sprintf(__('Cancel Faild: Id %1$s, %2$s','speedaf-express-for-woocommerce'),$orderMap[$data['billCode']],$data['message']);
                   continue;
                 }
                 if($data['success'] === true) {
                  // // $this->writeLog($orderMap[$data['billCode']],)
                   //$this->writeLog('#id'.$orderMap[$data['billCode']]);
                   $billId .= " #ID:".$orderMap[$data['billCode']];
                     Meta_box::createMeta($orderMap[$data['billCode']],[Meta_box::SPF_ORDER_CANCEL_STATUS => 'Cancel Booked']);
                     Meta_box::createMeta($orderMap[$data['billCode']],[Meta_box::SPF_ORDER_STATUS => '-10']);
                 }
            }
          }
          if($billId) $msg = sprintf(__('Cancel shipment(s) success, %1$s','speedaf-express-for-woocommerce'),$billId);
          $this->writeLog($this->errors,'canel:');
          if(count($this->errors))  $msg .=sprintf('%s', implode( ',',$this->errors));
          \SPF_ERROR::setErrors($msg);
       
         // $this->writeLog($res,'canel:');
         }catch(\Exception $e) {
          $msg = $e->getMessage().implode(',',$orderIds);
          \SPF_ERROR::setErrors($msg);
            throw new SpfException($msg,$e->getCode());
         }
         return $msg;
    }

    /**
     * 
     * @param array $billIds 
     * @return void 
     * @throws SpfException 
     */

    public function getTracking(array $billIds) {
       try{
           return $this->api->track(['mailNoList' => $billIds]);
       }catch(\Exception $e){
          throw new SpfException(sprintf(__('BillId %1$s Faild, Because: %2$s','speedaf-express-for-woocommerce'),implode('-',$billIds),$e->getMessage()));
       }
    }

    /**
     * 
     * @param string $apiKey 
     * @return mixed 
     * @throws SpfException 
     * @throws SpfException 
     */
    public function getCustomerCode(string $apiKey) {
        try{
            if($customerCode = get_option(Settings::SDF_CUSTOMER_CODE))  return $customerCode;

            if($this->currentCustomerCode) return $this->currentCustomerCode;

            $code  = $this->api->getCustomerCode(['apiKey' => $apiKey]);
            add_option(Settings::SDF_CUSTOMER_CODE,$code);
            return $code;
         
        }catch(\Exception $e){
            $GLOBALS['spf_api_error'] = sprintf(__('get Customer Code  Faild, Because: %1$s','speedaf-express-for-woocommerce'),$e->getMessage());
           //throw new SpfException(sprintf(__('get Customer Code  Faild, Because: %1$s','speedaf-express-for-woocommerce'),$e->getMessage()));
        }
    }

    /**
     * 
     * @param mixed $res 
     * @param string $type 
     * @return void 
     */

    private function writeLog($res,$type = 'create:') {

        if(!API_DEBUG) return;
        if(is_array($res)) $res = json_encode($res);
        file_put_contents(plugin_dir_path(SPF_ROOT_PATH).'test.log',$type.' '.$res."\n",FILE_APPEND);
    }

    /**
     * 
     * @return void 
     */
    public function error_notice() {
        global $spf_api_error;
        if(!$spf_api_error) return;
        echo '<div class="notice notice-error"><p>' . esc_html( $spf_api_error ) . '</p></div>';
    }
   
}
