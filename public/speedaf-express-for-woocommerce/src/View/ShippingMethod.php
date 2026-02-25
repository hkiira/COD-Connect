<?php

declare(strict_types=1);

namespace Wolf\Speedaf\View;

defined( 'ABSPATH' ) || exit; // block direct access.
use WC_Shipping_Method;
if(!class_exists('WC_Shipping_Method')) {
   
}
class ShippingMethod extends WC_Shipping_Method 
{
    const OPTIONS_KEY = 'wc_spf_main_settings';
      /**
         * Constructor for your shipping class
         *
         * @access public
         * @return void
         */
        public function __construct() {
            $this->id                 = 'wc_spf';
			$this->method_title       = __( 'Speedaf Express','speedaf-express-for-woocommerce' );  // Title shown in admin
			$this->title       = __( 'Speedaf Express' ,'speedaf-express-for-woocommerce');
            $this->method_description = __( 'speedaf','speedaf-express-for-woocommerce' ); //
            $this->enabled            = "yes"; // This can be added as an setting but for this example its forced enabled
            $this->init();
        }

         /**
         * Init your settings
         *
         * @access public
         * @return void
         */
        function init() {
            // Load the settings API
            $this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings
            $this->init_settings(); // This is part of the settings API. Loads settings you previously init.

            // Save settings in admin if you have any defined
            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $this->form_fields = array('wc_spf' => array('type'=>'wc_spf'));
       }

       public function generate_wc_spf_html() {
        $general_settings = get_option(self::OPTIONS_KEY);
			$general_settings = empty($general_settings) ? array() : $general_settings;
			//if(!empty($general_settings)){
				wp_redirect(admin_url('options-general.php?page=spf-configuration'));
			//}
       }
}
