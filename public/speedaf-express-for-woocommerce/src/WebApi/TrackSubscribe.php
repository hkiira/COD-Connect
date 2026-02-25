<?php

declare(strict_types=1);

namespace Wolf\Speedaf\WebApi;
defined( 'ABSPATH' ) || exit; // block direct access.
use Wolf\Speedaf\Api\RestApiInterface;
use Wolf\Speedaf\DB\SubscribeTable;
use  Wolf\Speedaf\Helper\Meta_box;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
class TrackSubscribe implements RestApiInterface
{

    public static function getRoute() {
        return 'track';
     }



    public function exec(WP_REST_Request $request) {
        global $wpdb;
        try{
        
            $subTable = new SubscribeTable($wpdb);
           
            
            if($res  = $request->get_json_params()) {
            
                foreach($res as $item) {
                    if($item['action'] === '1') continue;

                    $orderInfo =  $subTable->select(['order_id','bill_id'])->where('bill_id',$item['mailNo'],'=')->getOne();
                 
                   if(!is_array($orderInfo) ) continue;
                    Meta_box::createMeta($orderInfo['order_id'],[ Meta_box::SPF_ORDER_STATUS => $item['action']]);
                    

                }
         }
        }catch(\Exception $e) {
            file_put_contents(plugin_dir_path(SPF_ROOT_PATH).'logs/api.log',$e->getMessage()."\n",FILE_APPEND);
        }
   
        file_put_contents(plugin_dir_path(SPF_ROOT_PATH).'api.log',json_encode($request->get_json_params())." [:json]\n",FILE_APPEND);
     }
    
}
