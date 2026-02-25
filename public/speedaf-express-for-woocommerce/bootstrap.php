<?php
defined( 'ABSPATH' ) || exit; // block direct access.
$is_loading_speedaf_register = false;

if(!class_exists('SPF_ERROR')) {
    class SPF_ERROR{
        private static $errors = [];
        const SPF_ERROR_NOTE = 'spf_g_error_note';
        const GROUP = 'spf_error';

        private static $selfObj = null;
        public static function setErrors(string $message) {
           // wp_cache_set(self::SPF_ERROR_NOTE,$message);
            file_put_contents(plugin_dir_path(SPF_ROOT_PATH).'error.log',$message);
            
        }
     
       
        public  static function getErrors() {
            //var_dump(wp_cache_get(self::SPF_ERROR_NOTE));exit;
            if(!file_exists(plugin_dir_path(SPF_ROOT_PATH).'error.log')) return '';
            
             if( $msg =  file_get_contents(plugin_dir_path(SPF_ROOT_PATH).'error.log')) {
                @unlink(plugin_dir_path(SPF_ROOT_PATH).'error.log');
             }

             return $msg;

        }
    }

  
}




spl_autoload_register(function($class){
 
    if(strpos($class,'Wolf\Speedaf') !== false) {
        $path = str_replace(['Wolf\Speedaf','\\'],['src','/'],$class);
        $fileName = SDF_PLUGIN_DIR.$path.'.php';
       if(!file_exists($fileName)) throw new \Exception('not found class '.$class);
       
       include $fileName;
  }

   include_once SDF_PLUGIN_DIR.'Container.php';


    /* if(isset($map[$class]) && file_exists(SDF_PLUGIN_DIR.$map[$class])) {
        include SDF_PLUGIN_DIR.$map[$class];
    } */
});






function spf_extension_initialize() {
 
    // This is also a great place to check for the existence of the WooCommerce class
    if ( ! class_exists( 'WooCommerce' ) ) {
    // You can handle this situation in a variety of ways,
    //   but adding a WordPress admin notice is often a good tactic.
        return;
    }
 // return \Wolf\Speedaf\Init::getInstance(\Wolf\Speedaf\App\Factory::getInstance());
    $config = include_once SDF_PLUGIN_DIR.'sdk/Configuration.php';
    if(!class_exists('ApiServices')) {
        include_once SDF_PLUGIN_DIR.'sdk/ApiServices.php';
    }
    $pluginFactory =  \Wolf\Speedaf\Container::instance();
    $pluginFactory->setSdkService('config',$config);
    $pluginFactory->setSdkService('api',new \Wolf\Speedaf\Speedaf\Carrier(new \Wolf\Speedaf\sdk\ApiServices()));
     $pluginFactory->register();
    
    $GLOBALS['wc_spf_plug'] = $pluginFactory;
}

function SPF(){
    return $GLOBALS['wc_spf_plug'];
}
