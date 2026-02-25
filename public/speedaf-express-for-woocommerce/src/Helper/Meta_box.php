<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Helper;
defined( 'ABSPATH' ) || exit; // block direct access.
class Meta_box
{
    const SPF_ORDER_TRACKING              = '_spf_order_tracking';
    const SPF_ORDER_STATUS                = '_spf_order_status';
    const SPF_ORDER_LABEL                 = '_spf_order_row_label';
    const SPF_PACK_ROW_WEIGHT             = '_spf_order_row_weight';
    const SPF_ORDER_COD                   = '_spf_order_row_cod';
    const SPF_ORDER_CANCEL_STATUS         = '_spf_order_cancel';
    const SPF_SHIPMENT_TIME               = '_spf_shipment_time';
    const SPF_SHIPMENT_SENDER_CITY        = '_spf_shipment_sender_city';   
    


    public static function createMeta($orderId,$data) {
      //  update_post_meta( $orderId, 'spf_order_type', 'Speedaf');   
        foreach ($data as $key => $val) {
            update_post_meta( $orderId, $key, is_array($val) ? wp_json_encode($val) : $val);   
        }
    }
    public static function getPostMeta($orderId,$key) {
        return get_post_meta($orderId,$key,true);
    }

    public static function isEnabledSdf():bool {

        return(bool) get_option(\Wolf\Speedaf\Menu\Settings::SDF_ENABELD_STATUS_KEY);

    }
}
