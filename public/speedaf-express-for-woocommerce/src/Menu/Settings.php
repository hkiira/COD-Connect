<?php

declare(strict_types=1);

namespace Wolf\Speedaf\Menu;
defined( 'ABSPATH' ) || exit; // block direct access.
use Wolf\Speedaf\Api\RegisterInterface;
use Wolf\Speedaf\Core\Providers;
use Wolf\Speedaf\View\ShippingMethod;
class Settings extends Providers
{

    protected $_template         = 'settings/speedaf_settings_view.php';

    const SDF_SETTINGS_GROUP     = 'spf-speedaf-settings-group';
   
    const SDF_ENABELD_STATUS_KEY = 'spf_speedaf_enable';

    const SDF_APK_KEY            = 'spf_speedaf_api_key'; 
    const SDF_CUSTOMER_CODE      =  'spf_speedaf_customer_code';

    const SDF_ALLOW_SHIPMENT_OPENED = 'spf_speedaf_allow_opened';


    public function register() { 

       add_action( 'admin_init', [$this,'speedaf_plugin_settings'] );
         add_action(
			'admin_menu',
			function() {
                add_options_page(
                     'Speedaf Config', 
                     'Speedaf Config', 
                     'manage_options', 
                     'spf-configuration', 
                      [$this, 'settings_page_contents']
                     );
			}
		);
	    
        add_filter( 'woocommerce_shipping_methods', [$this,'sdf_shipping_method'] );
       
        add_filter('woocommerce_settings_tabs_array',function($settings_tabs){
            $settings_tabs['spf_settings'] = __( 'Speedaf Configuration', 'speedaf-express-for-woocommerce' );
            return $settings_tabs;
        },30);

        add_action( 'woocommerce_settings_spf_settings', [$this,'settingsTab'] );
       
        add_action('add_option_'.self::SDF_APK_KEY,[$this,'saveCustomerCode'],10,2);
      
        add_action('update_option_'.self::SDF_APK_KEY,[$this,'saveCustomerCode'],10,2);
       
       // delete_option(self::SDF_APK_KEY);delete_option(self::SDF_CUSTOMER_CODE);

    }

    public function saveCustomerCode($old,$new) {
     
        if(!$new) return;
        $api = $this->_service->getSdkService('api');
        $api->getCustomerCode($new);
        global $spf_api_error;
        if($spf_api_error)  {
           // var_dump($spf_api_error);exit;
            delete_option(self::SDF_APK_KEY);
            add_settings_error(self::SDF_SETTINGS_GROUP,self::SDF_APK_KEY,$spf_api_error);
          //  return '';
        }
       //var_dump($res);exit;
        
    }
    /**
     * 
     * @return never 
     */

    public function settingsTab() {
        exit(wp_redirect(add_query_arg(['page' =>'spf-configuration'],admin_url('options-general.php'))));
    }

  


  public  function speedaf_plugin_settings() {
        register_setting( self::SDF_SETTINGS_GROUP, 'spf_speedaf_enable' );	
        register_setting( self::SDF_SETTINGS_GROUP, self::SDF_ALLOW_SHIPMENT_OPENED );	
        if(!get_option(self::SDF_APK_KEY)) {
            register_setting( self::SDF_SETTINGS_GROUP, 'spf_speedaf_api_key' ,function($arg) {
                if(!empty($arg)) return $arg;
                 add_settings_error(self::SDF_SETTINGS_GROUP,'spf_speedaf_api_key',__('Please setting Api Key','speedaf-express-for-woocommerce'));
             });
        }
       
        register_setting( self::SDF_SETTINGS_GROUP, 'spf_speedaf_name',function($arg) {
            if(!empty($arg)) return sanitize_text_field($arg);
             add_settings_error(self::SDF_SETTINGS_GROUP,'spf_speedaf_name',__('Please setting Sender Name','speedaf-express-for-woocommerce'));
         } );
        register_setting( self::SDF_SETTINGS_GROUP, 'spf_speedaf_phone' ,function($arg) {
            if(empty($arg)) {
                add_settings_error(self::SDF_SETTINGS_GROUP,'spf_speedaf_phone',__('Please setting Phone','speedaf-express-for-woocommerce'));
                return;
            }
            
            if(!\WC_Validation::is_phone($arg)) {
                add_settings_error(self::SDF_SETTINGS_GROUP,'spf_speedaf_phone',__('The format Error of Phone','speedaf-express-for-woocommerce'));
                return;
            }

             return $arg;
         });
      
         
        register_setting( self::SDF_SETTINGS_GROUP, 'spf_speedaf_email' ,function($arg) {
            if(empty($arg) || !\WC_Validation::is_email($arg)) {
                add_settings_error(self::SDF_SETTINGS_GROUP,'spf_speedaf_email',__('Please setting Email Address OR the Email Incorrect email address','speedaf-express-for-woocommerce'));
                return;
            }
            
            return $arg;
             
         });
                                    
    }

   
    
    public function settings_page_contents() {
      echo  $this->getView()->render();

    }
    public  function sdf_shipping_method( $methods )
    {
       
        $methods['wc_sdf'] = ShippingMethod::class;
       // var_dump($methods);exit;
        return $methods;
    }


    
}
