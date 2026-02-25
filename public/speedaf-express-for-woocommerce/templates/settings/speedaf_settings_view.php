<?php
  $apikeyDisabled = get_option('spf_speedaf_api_key') ? 'disabled=disabled' : '';
?>
<style>
    #sdf-settings{
        margin-top: 2rem;
       display:  flex;
       padding: 2rem;
    }
    #sdf-settings input.disabled,
    #sdf-settings input:disabled,
    #sdf-settings select.disabled,
     #sdf-settings select:disabled, 
     #sdf-settings textarea.disabled, 
     #sdf-settings textarea:disabled {
        background-color: #ccc;
     }
    #sdf-settings > div {
        flex: 1 1 auto;
    }
    #sdf-settings .spf-col{
        padding:10px 5px;
    }
    #sdf-settings #sdf-form {
        display:  flex;
        flex-direction: column;
    }
    #sdf-settings #sdf-form label span{
        display: inline-block;
        width: 200px;
    }
    #sdf-settings #sdf-form label b{
        color: red;
    }
    #sdf-settings #sdf-form  input[type="text"],#sdf-settings #sdf-form input[type="email"] {
        width: 400px;
        height: 45px;
    }
    #sdf-settings .spf-col button {
       
        width: 8rem;
        height: 3rem;
    }
    #sdf-settings .spf-col.spf-button {
        margin-top: 4rem;
        margin-bottom: 2rem;
    }
</style>
<div id="sdf-settings">
    <div class="table">
        <fieldset>
            <div class="title"><h1><?php echo __('Speedaf Configuration','speedaf-express-for-woocommerce')?></h1></div>
            <form action="options.php" method="POST" id="sdf-form">
                <?php
                 settings_fields( 'spf-speedaf-settings-group' );
                do_settings_sections( 'spf-speedaf-settings-group' );
                ?>
                <div class="spf-col">
                    <label>
                    <span><?php echo __('Enable Speedaf API','speedaf-express-for-woocommerce')?></span>
                    <input type="checkbox" name="spf_speedaf_enable" value="1"  <?php checked( 1, get_option( 'spf_speedaf_enable' ) )?> />
                </label>
                </div>
                <div class="spf-col">
                    <label>
                    <b>*</b>
                    <span><?php echo __('API Key','speedaf-express-for-woocommerce')?></span>
                    <input  name="spf_speedaf_api_key" type="text"  <?php echo esc_attr($apikeyDisabled) ?> value="<?php echo esc_attr( get_option('spf_speedaf_api_key') ); ?>" />
                </label>
                </div>
                <div class="spf-col">
               <label>
               <b>*</b>
                <span><?php echo __('sender name','speedaf-express-for-woocommerce')?></span>
                <input name="spf_speedaf_name" type="text"/ value="<?php echo esc_attr( get_option('spf_speedaf_name') ); ?>">
               </label>
               </div>
               <div class="spf-col">
               <label>
               <b>*</b>
                <span><?php echo __('sender phone','speedaf-express-for-woocommerce')?></span>
                <input name="spf_speedaf_phone" type="text" value="<?php echo esc_attr( get_option('spf_speedaf_phone') ); ?>"/>
               </label>
               </div>
               <div class="spf-col">
               <label>
               <b>*</b>
                <span><?php echo __('sender Email','speedaf-express-for-woocommerce')?></span>
                <input name="spf_speedaf_email" type="email" value="<?php echo esc_attr( get_option('spf_speedaf_email') ); ?>"/>
               </label>
               </div>
               <div class="spf-col">
               <label>
               <b>*</b>
                <span></span>
                <input name="spf_speedaf_allow_opened" type="checkbox" value="1" <?php checked( 1, get_option( 'spf_speedaf_allow_opened' ) )?>/>
                <span><?php echo __('Allow shipment to be opened','speedaf-express-for-woocommerce')?></span>
               </label>
               </div>
               <div class="spf-col spf-button" style="text-align: center;">
                <button type="submit" class="button button-primary"><?php echo __('Save Changes')?></button>
               </div>
            </form>
        </fieldset>
        <div class="">
            <h1>Speedaf</h1>
            <p> <?php echo __('Here you can enter your Speedaf settings. For any help contact us via Live chat or Email.','speedaf-express-for-woocommerce')?>( morocco@speedaf.com )</p>
        </div>
    </div>
    <div class="info">
        <p><h1><?php echo __('Guide','speedaf-express-for-woocommerce')?></h1></p>
        <p><h4><?php echo __('Please follow the setup instructions before upload bookings','speedaf-express-for-woocommerce')?></h4></p>
        <p><h4><?php echo __('Step 1: To use this App you must have an account with ','speedaf-express-for-woocommerce')?><a href="https://csp.speedaf.com/" target="__blank"><?php echo __('speedaf','speedaf-express-for-woocommerce')?></a></h4></p>
        <p><h4><?php echo __('Step 2: After account approval you will be provided API credentails','speedaf-express-for-woocommerce')?></h4></p>
        <p><h4><?php echo __('Step 3: Enter the API key Submit in Setting','speedaf-express-for-woocommerce')?></h4></p>
      
        <p><h4><?php echo __('Step 4: How to setup and use application please check our','speedaf-express-for-woocommerce')?><a href="" target="__blank"><?php echo __('guidelines','speedaf-express-for-woocommerce')?></a></h4></p>
        <p><h4><?php echo __('Step 5: You can now upload bulk bookings from order page','speedaf-express-for-woocommerce')?></h4></p>

    </div>
</div>