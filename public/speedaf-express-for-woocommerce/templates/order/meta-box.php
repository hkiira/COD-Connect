
<div class="inside">
    <label><span><?php echo __('Shipment Status','speedaf-express-for-woocommerce')?>:</span><?php echo isset($data['shipping_status']) ? esc_html( $data['shipping_status']): ''?> </label>
</div>
<div class="inside">
<label><span><?php echo __('Shipment Tracking','speedaf-express-for-woocommerce')?>:</span> <?php echo isset($data['tracking']) ? esc_html($data['tracking']): ''?></label>
</div>

<div class="inside">
<button name="hitshippo_fedex_create_return_label" id="print-tracking" value="<?php echo isset($data['tracking']) ? esc_html($data['tracking']): ''?>" style="margin-right: 10px;background:#533e8c; color: #fff;border-color: #533e8c;box-shadow: 0px 1px 0px #533e8c;" class="button button-primary" type="button"><?php echo __('Tranck Shipment','speedaf-express-for-woocommerce')?></button>
<button type="button" id="print-label" value="<?php echo isset($data['tracking']) ? esc_html($data['tracking']): ''?>" class="add_note button" style="height: 32px;"><?php echo __('Print Label','speedaf-express-for-woocommerce')?></button>
</div>
