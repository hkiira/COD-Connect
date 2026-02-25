<div class="popup-contianr" style="max-width: 75%;
    margin: 0 auto;">
<div class="sdf-order-popup-header">
    <div class="logo">
        <img src="<?php echo plugin_dir_url( SPF_ROOT_PATH ) . 'assets/img/speedaf-logo.png'; ?>" />
      </div>
        <div class="sdf-popup-title">
            <p><?php echo __('Speedaf  Booking Summary','sdf-lang')?></p>
        </div>
    </div>
    <div class="sdf-popup-content">
        <div class="count" style="    text-align: left;
    padding: 1rem;"><?php echo __('Booked  Packets')?> = <?php echo isset($pack_num) ? esc_html($pack_num):  0;?></div>
        <ul class="order-content" style="    width: 60%;
    margin: auto;">
            <?php if(isset($trackinfo)): ?>
               <?php foreach($trackinfo as $key => $info):?>
            <li style="background-color: rgb(245, 154, 35);
    padding: 5px;
    margin: 5px 0;"><?php echo esc_attr( $key+1);?>、<?php echo esc_html($info)?></li>
              <?php endforeach;?>
            <?php endif;?>
        </ul>

       
  </div>
  <div class="print" style="display: flex;justify-content: space-between; margin-top: 2rem;">
            <a class="remodal-cancel" style="background-color: rgba(245, 154, 35,1);" href="<?php echo isset($label_url) ? esc_url_raw( $label_url): '';?>" target="_blank"><?php echo __('Print Labels','speedaf-express-for-woocommerce')?></a>
            <button data-remodal-action="cancel" class="remodal-cancel">Cancel</button>
   </div>
</div>
</div>
