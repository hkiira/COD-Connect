<?php
use \Wolf\Speedaf\Helper\SpfHelper;
?>
<style>
.tracking-content table {
    width: 100%;
   
    border-collapse: collapse;
}
.tracking-footer {
    margin-top: 1.5rem;
    text-align: right;
}
.tracking-content table th,.tracking-content table td {
    width: 20px;
    border-bottom: 1px solid #ddd;
    padding: 9px 0;
}
.tracking-content tr:nth-child(even) {
      background-color: #f9f9f9;
    }

</style>
<?
  $msgType =  (get_locale() === 'zh_CN') ?  'message' : 'msgEng';
 
?>
 <button data-remodal-action="close" class="remodal-close"></button>
<div class="tracking-content">
    <div class="logo">
    <img  src="<?php echo plugin_dir_url( SPF_ROOT_PATH ) . 'assets/img/speedaf-logo.png'; ?>" />
    </div>
    <?php if(empty($tracking)):?>
      <h3><?php echo __('Please contact customer service','speedaf-express-for-woocommerce')?></h3>
    <?else :?>
        <h3><?php echo __('Shipment Tracking','speedaf-express-for-woocommerce')?>:<?php echo esc_html($tracking['mailNo'])?></h3>
            <table class="tracking-info">
                <thead>
                    <tr><th><?php echo __('Time')?></th><th><?php echo __('Status')?></th><th><?php echo __('Comments')?></th></tr>
                </thead>
               <tbody>
                  <?php foreach($tracking['tracks'] as $info):?>
                    <tr><td><?php echo esc_html($info['time']);?></td><td><?php echo (get_locale() === 'zh_CN') ? esc_html( $info['actionName'] ): SpfHelper::trackingField($info['action']);?></td><td><?php echo esc_html( $info[$msgType]);?></td></tr>
                    <?endforeach;?>
                   
               </tbody>
               
            </table>

    <?endif?>
    <div class="tracking-footer">
    <button data-remodal-action="cancel" class="remodal-cancel"><?php echo __('Confirm');?></button>
    </div>
</div>
