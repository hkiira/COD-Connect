<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Helper;
defined( 'ABSPATH' ) || exit; // block direct access.
class SpfHelper
{

    public function getProductInfo(array $products): string {
      $name = '';
      foreach($products as $product) {
        $name .= sprintf(', %s ',$product->get_name());
      }

      return ltrim( $name,',');
    }

    public static function trackingField($key) {
      $actionEnum = [
        '10' => __('Booked','speedaf-express-for-woocommerce'),//  10	已下单
        '-10' => __('Canceled','speedaf-express-for-woocommerce'),//  10	已下单
        '150' => __('At local facility','speedaf-express-for-woocommerce'),//  10	入库
        '181' => __('Packing','speedaf-express-for-woocommerce'),//  10	集包
        '190' => __('Departed  location','speedaf-express-for-woocommerce'),//  10	出库
        '402' => __('apply To customs','speedaf-express-for-woocommerce'),//  10	报关
        '220' => __('vehicle for delivery','speedaf-express-for-woocommerce'),//  10	航班起飞
        '230' => __('Flight arrival','speedaf-express-for-woocommerce'),//  10	航班落地
        '360' => __('clearance of customs','speedaf-express-for-woocommerce'),//  10	清关中
        '401' => __('exception of customs','speedaf-express-for-woocommerce'),//  10	清关异常
        '370' => __('Settle of customs','speedaf-express-for-woocommerce'),//  10	清关完成
        '1' => __('Delivered','speedaf-express-for-woocommerce'),//  10	已揽收
        '2' => __('Shipment','speedaf-express-for-woocommerce'),//  10	发件
        '3' => __('Shipment arriving On-Time','speedaf-express-for-woocommerce'),//  10	到件
        '4' => __('Delivering','speedaf-express-for-woocommerce'),//  10	派送中
        '5' => __('Delivered','speedaf-express-for-woocommerce'),//  10	已签收
        '-710' => __('returning packing','speedaf-express-for-woocommerce'),//  10	退件中
        '730' => __('Return receipt','speedaf-express-for-woocommerce'),//  10		退货签收
        '18' => __('Picked up','speedaf-express-for-woocommerce'),//  10	自提件扫描
        '16' => __('Third party collection','speedaf-express-for-woocommerce'),//第三方代收（自提入库)
        '-1' => __('Invalid','speedaf-express-for-woocommerce'),

    ];

    if(isset($actionEnum[$key])) return $actionEnum[$key];

       return $key;
      
    }

    /**
     * 
     * @param mixed $order 
     * @return mixed 
     */
    public static function unitWeight($order) {
      if(!$order) return '';
      $total = 0;
      foreach ($order->get_items() as  $item ) {
       
        $order_product = $item->get_product();
  
         $total += ($item['qty'] * (int)$order_product->get_weight());
      }

      return $total;
    }

    /**
     * 
     * @param mixed $dateTime 
     * @param bool $short 
     * @return string 
     */

    public static function date_format($dateTime,$short = false){
      $format = (get_locale() === 'zh_CN') ? ($short ? 'Y年n月j日':'Y年n月j日 H时i分s秒') : ($short ? 'Y-m-d':'Y-m-d H:i:s');
        if(!$dateTime instanceof \WC_DateTime) {
          $dateTime = new \WC_DateTime($dateTime);
        }
         return wc_format_datetime($dateTime,$format);
    }

   
    /**
     * cod 
     * @param mixed $order 
     * @return mixed 
     */

    public static function getCOD(\WC_Order $order) {
      $cod = $order->get_meta(\Wolf\Speedaf\Helper\Meta_box::SPF_ORDER_COD);
      return $cod ? $cod : ($order->get_payment_method() === 'cod' ? self::resovleOrderTotal($order) : 0);
    }

    /**
     * Undocumented function
     *
     * @param \WC_Order $order
     * @return void
     */
    public static function resovleOrderTotal(\WC_Order $order,$format=false) {
      $codFee = round((float)$order->get_total(false)-(float)$order->get_total_refunded(),2);
      if($format) return $codFee;
      
       return wc_price( $codFee, array( 'currency' => $order->get_currency() ) );
    }

    /**
     * 
     * @param string $html 
     * @return string 
     */

    public  function esc_html_raw(string $html) {
      $allowed_html = array_merge(wp_kses_allowed_html('post'),['input' => [
        'class' => [],
        'id'    =>  [],
        'name'  =>  [],
        'value' => [],
        'type'  => [],
        'checked' => [],
        'with'  => []
      ]]);

      return wp_kses($html,$allowed_html);
    }
}
