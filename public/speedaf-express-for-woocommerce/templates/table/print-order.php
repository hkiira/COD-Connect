
  <html>
  <head>
    <style>

        #iframe-print-wrapper{
            padding: 20px;
            font-size: 16px;
        }
        #iframe-print-wrapper  .print-header{
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
        }
        #iframe-print-wrapper  .print-logo img {
            vertical-align: middle;
            width: 100px;
            height: 50px;
        }
        #iframe-print-wrapper .print-title{
            width: 100%;
            text-align: center;
        }
        #iframe-print-wrapper table {
            width: 100%;
            border-collapse: collapse;
            text-align: center;
            border: 1px solid #ddd;
            margin: 5px;
        }

     #iframe-print-wrapper table   th,#iframe-print-wrapper table td {
          width: 50px;
          word-wrap: break-word;
          white-space: nowrap;
           border: 1px solid #ddd;
           padding: 10px;
     }

</style>
  </head>
  <body>
  <div id="iframe-print-wrapper">
    <div class="print-header">
        <div class="print-logo">
        <img src="<?php echo plugin_dir_url( SPF_ROOT_PATH ) . 'assets/img/speedaf-logo.png'; ?>" />
        </div>
        <div class="print-title">
           <h2><?php echo __('speedaf Load Sheet','speedaf-express-for-woocommerce');?></h2>
        </div>
    </div>
    <div class="print-content">
        <div class="c-header">
            <p><span style="font-size: 24px;font-weight:700;"><?php echo __('print time','speedaf-express-for-woocommerce')?>:</span> <?php echo wp_date('Y-m-d H:i:s')?></p>
            <p><span style="font-size: 24px;font-weight:700;"><?php echo __('Customer No','speedaf-express-for-woocommerce')?>:</span><?php echo esc_html( $api_key);?></p>
        </div>
        <div class="print-body">
            <table>
                <thead>
                    <tr style="background-color: #eee;">
                        <th><?php echo __('ordinal','speedaf-express-for-woocommerce')?></th>
                        <th><?php echo __('speedaf waybill','speedaf-express-for-woocommerce')?></th>
                        <th><?php echo __('S.O.','speedaf-express-for-woocommerce')?></th>
                        <th><?php echo __('Order time','speedaf-express-for-woocommerce')?></th>
                        <th><?php echo __('Send city','speedaf-express-for-woocommerce')?></th>
                        <th><?php echo __('AcceptName','speedaf-express-for-woocommerce')?></th>
                        <th><?php echo __('Receipt city','speedaf-express-for-woocommerce')?></th>
                        <th><?php echo __('QTY','speedaf-express-for-woocommerce')?></th>
                        <th><?php echo __('weight (KG)','speedaf-express-for-woocommerce')?></th>
                        <th><?php echo __('COD','speedaf-express-for-woocommerce')?></th>
                        <th><?php echo __('confirm')?></th>
                    </tr>
                </thead>
            
                <tbody>
                    <?php foreach($orders as $key => $order):?>
                          <tr>
                            <td class="spf-table__cell" rowspan="1" colspan="1"><?php echo esc_html( $key+1)?></td>
                            <td class="spf-table__cell" rowspan="1" colspan="1"><?php echo esc_html($order['bill_id'])?></td>
                            <td class="spf-table__cell" rowspan="1" colspan="1"><?php echo esc_html($order['order_id'])?></td>
                            <td class="spf-table__cell" rowspan="1" colspan="1" style="white-space: nowrap;"><?php echo \Wolf\Speedaf\Helper\SpfHelper::date_format($order['sender_time'],true)?></td>
                            <td class="spf-table__cell" rowspan="1" colspan="1"><?php echo esc_html($order['sender_city'])?></td>
                            <td class="spf-table__cell" rowspan="1" colspan="1"><?php echo esc_html($order['accept_name'])?></td>
                            <td class="spf-table__cell" rowspan="1" colspan="1"><?php echo esc_html($order['accept_city'])?></td>
                            <td class="spf-table__cell" rowspan="1" colspan="1"><?php echo esc_html($order['order_qty'])?></td>
                            <td class="spf-table__cell" rowspan="1" colspan="1"><?php echo esc_html($order['weight'])?></td>
                            <td class="spf-table__cell" rowspan="1" colspan="1"><?php echo wp_kses_post($order['cod'])?></td>
                            <td class="spf-table__cell" rowspan="1" colspan="1"></td>

                          </tr>

                    <?endforeach;?>
                </tbody>
            </table>
        </div>
    </div>
</div>
  </body>
  </html>

