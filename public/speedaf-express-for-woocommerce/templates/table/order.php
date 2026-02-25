<?php
   $helper = new  \Wolf\Speedaf\Helper\SpfHelper();
?>


<div id="spf-create-order">
    <div class="spf-create-order wrap">
        <div class="spf-create-order-header-wrapper wiz-header">
                  <div class="header-title">
                    <div class="goback">
                        <a   href="<?php echo isset($go_back) ? esc_url_raw($go_back): 'javascript::void(0);';?>">Go Back</a>
                    </div>
                    <div class="speedaf-content">
                        <h1><?php echo  __('Speedaf  Orders','speedaf-express-for-woocommerce')?></h1>
                    </div>
                  </div>
                    <div class="spf-create-order-header">
                    <form method="GET" action="<?php echo admin_url(add_query_arg(['page' => PLUGIN_NAME],'admin.php'))?>">
                    <input type="hidden" name="page" value="<?php echo esc_attr( PLUGIN_NAME)?>" />
                        <div class="search">
                            <div class="order_search">
                                    <label for="order_search_id">
                                        <input type="text" name="order_search_id" value="<?php echo empty($order_search_id) ? '': esc_attr( $order_search_id)?>" placeholder="<?php echo __('Please enter order Number OR tracking number','speedaf-express-for-woocommerce')?>" />
                                    </label>
                                </div>
                                <div class="date">
                                    <input type="datetime-local" value="" name="date_start" placeholder="start date" /> - <input type="datetime-local" value="" name="date_end" placeholder="end Date" />
                                </div>
                                <div class="search-botton">
                                <button type="submit" name="search-botton" value="1" class="button button-primary"><?php echo __('Filter')?></button>
                            </div>
                          
                           
                         </div>
                        </form>
                        <div class="order-button-group">
                           <div class="export-order">
                               <button type="button" id="export-order-botton" class="button button-primary"><?php echo __('export Order','speedaf-express-for-woocommerce')?></button>
                           </div>
                           <div class="print-label-order">
                               <button type="button" data-remodal-target="print-label-modal" class="button button-primary"><?php echo __('Print Label','speedaf-express-for-woocommerce')?></button>
                                    <div class="remodal print-label" data-remodal-id="print-label-modal">
                                           <div id="print-label-type">
                                            <div class="print-label-content">
                                                <?php if($labelType && is_array($labelType)):?>
                                                    <?php foreach($labelType as $key  => $label):?>
                                                        <label>
                                                            <input type="radio" name="print-label-type" value="<?php echo esc_attr( $key)?>" />
                                                            <span><?php  echo __(sprintf('%s',$label),'speedaf-express-for-woocommerce')?></span>
                                                        </label>
                                                <?php endforeach;?>
                                               <?php endif;?>
                                            </div>
                                           </div>
                                            <button data-remodal-action="cancel" class="remodal-cancel">Cancel</button>
                                            <button data-remodal-action="confirm" class="remodal-confirm">OK</button>
                                    </div>
                           </div>
                           <div class="create-order">
                               <button id="create-order-botton" type="button" class="button button-primary"><?php echo __(isset($isUpdateOrder) && $isUpdateOrder === true ? 'update Order': 'create Order','speedaf-express-for-woocommerce')?></button>
                           </div>
                           <div class="edit-sender">
                               <button type="button" id="edit_sender_popup" class="button button-primary"><?php echo __('Edit Sender','speedaf-express-for-woocommerce')?></button>
                           </div>
                        </div>
                    </div>
            <div class="tab-content-wrapper">
            <form id="order-body-form">
                <table>
                    <thead>
                    <th class="th-table__cell" colspan="1" rowspan="1"><div class="cell"><label class="el-checkbox"><span class="el-checkbox__input"><span class="el-checkbox__inner"></span><input class="el-checkbox__original" id="checked-spf" type="checkbox"  value="false"></span></label></div></th>
                      <?php foreach($table_header as $key => $title):?>
                           <th colspan="1" rowspan="1"><?php echo esc_html($title)?></th>
         
                         <?php endforeach;?>
                    
                    </thead>
                    
                    <tbody id="order_html_content">
                   
                        <?php if(empty($order_html)):?>
                       <tr><td><?php echo __('not found orders','speedaf-express-for-woocommerce')?></td></tr>
                       <?php else:?> 
                        <?php echo $helper->esc_html_raw($order_html);?>
                       <?php endif;?>
                     </tbody>
                    
                </table>
                </form>
        </div>                 

    
    </div>
    <div id="error-alert" >
    <div class=" spf-error" data-remodal-id="spf-error-modal">
        <button data-remodal-action="close" class="remodal-close"></button>
            <div id="spf-error">
            <div class="error-content">
                error
            </div>
            </div>
      
                                        
      </div>
    </div>
</div>

<div class="remodal" id="edit-sender" data-remodal-id="edit-sender-modal">
    <fieldset>
         <h2><?php echo __('Edit Sender','speedaf-express-for-woocommerce')?></h2>
       <form action="" id="edit-form" method="POST">
        <div id="edit-form-tabel" style="position:relative;">
 <?php $c = [
              
				array(
					'title'    => __( 'Address line 1' ),
					'desc'     => __( 'The street address for your business location.' ),
					'id'       => 'woocommerce_store_address',
					'default'  => '',
					'type'     => 'text',
					'desc_tip' => true,
				),

				array(
					'title'    => __( 'Address line 2'),
					'desc'     => __( 'An additional, optional address line for your business location.' ),
					'id'       => 'woocommerce_store_address_2',
					'default'  => '',
					'type'     => 'text',
					'desc_tip' => true,
				),

				array(
					'title'    => __( 'City' ),
					'desc'     => __( 'The city in which your business is located.' ),
					'id'       => 'woocommerce_store_city',
					'default'  => '',
					'type'     => 'text',
					'desc_tip' => true,
				),

				array(
					'title'    => __( 'Country / State' ),
					'desc'     => __( 'The country and state or province, if any, in which your business is located.'),
					'id'       => 'woocommerce_default_country',
					'default'  => 'US:CA',
					'type'     => 'single_select_country',
					'desc_tip' => true,
				),

				array(
					'title'    => __( 'Postcode / ZIP'),
					'desc'     => __( 'The postal code, if any, in which your business is located.' ),
					'id'       => 'woocommerce_store_postcode',
					'css'      => 'min-width:50px;',
					'default'  => '',
					'type'     => 'text',
					'desc_tip' => true,
				),
               
                ];	
            
               \WC_Admin_Settings::output_fields( $c );
            ?>
             <label><?php echo __('sender name','speedaf-express-for-woocommerce')?></label>
                <input name="spf_speedaf_name" type="text"/ value="<?php echo esc_attr( get_option('spf_speedaf_name') ); ?>">
               <label><?php echo __('sender phone','speedaf-express-for-woocommerce')?></label>
                <input name="spf_speedaf_phone" type="text" value="<?php echo esc_attr( get_option('spf_speedaf_phone') ); ?>"/>
           

         </div>
           <div class="edit-sender-footer" style="position:relative;margin-top: 2rem;">
           <button type="button" id="submit-edit-sender-form" class="button button-primary"><?php echo __('Save Changes')?></button>
         </div>

       
        </form>
    </fieldset>
  
</div>

<iframe id="print-order-iframe"></iframe>
<?php if(isset($isUpdateOrder) && $isUpdateOrder === true):?>
<script>
    window.onload = function() {
        window.sdfOrderData.createMsg = "<?php echo __( 'Do you want to update Shipments?','speedaf-express-for-woocommerce');?>"
       // console.log(window.sdfOrderData);
    }
</script>
<?php endif;?>