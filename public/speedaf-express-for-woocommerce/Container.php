<?php
declare(strict_types=1);

namespace Wolf\Speedaf;
defined( 'ABSPATH' ) || exit; // block direct access.

use Exception;
use Wolf\Speedaf\Menu\Settings;
use Wolf\Speedaf\Menu\OrderList;
use Wolf\Speedaf\Menu\BulkAction;

use Wolf\Speedaf\Api\RegisterInterface;
use Wolf\Speedaf\Menu\RegisterMetaBox;
 class Container implements RegisterInterface{

    const PRE = 'spf_';

    const VERSION = '1.1.0';

    private $_providers = [

    ];

    private $_sdkApiServices = [
      
    ];

    private static $errors = [];

    private static $_instance = null;

  


    public function __construct()
    {
       
        register_activation_hook( SDF_PLUGIN_DIR, __CLASS__.'::activate_install' );
        register_deactivation_hook( SDF_PLUGIN_DIR, __CLASS__.'::activate_uninstall' );
        foreach ([
            'settings' => Settings::class,
        ] as $alias => $class) {
              $this->_providers[self::PRE.$alias] = new $class($this);
        }

        if( get_option(\Wolf\Speedaf\Menu\Settings::SDF_APK_KEY)) {
            foreach ([
                'orderList' => OrderList::class,
                'lang'      => \Wolf\Speedaf\Menu\Languages::class,
                'bulk_action' => BulkAction::class,
                'box'         => RegisterMetaBox::class,
                'account_tracking' => \Wolf\Speedaf\Menu\AccoutnTracking::class,
                'web_api'      => \Wolf\Speedaf\Menu\Api::class,
                'allow_shipment_opened' => \Wolf\Speedaf\Menu\AllowShipmentOpened::class,
            ] as $alias => $class) {
                  $this->_providers[self::PRE.$alias] = new $class($this);
            }

  
        }

     
    }

  

   
    public function register() { 
        foreach($this->_providers as $alias  => $provider){
          
            $provider->register();
        }
        
    }

  

    /**
     * Undocumented function
     *
     * @param string $alias
     * @return void
     */
    public function providers(string $alias = '') {
        if($alias && array_key_exists($alias,$this->_providers)) return $this->_providers[$alias];

        return $this->_providers;
     }

    public function setProviders($alias, $object) {
        if(!array_key_exists($alias,$this->_providers)) $this->_providers[$alias] = $object;
        return $this;
     }

    
     public static function instance() {
          if(self::$_instance === null) self::$_instance = new self();

          return self::$_instance;
     }

     /**
      * 
      * @param mixed $alias 
      * @param mixed $instance 
      * @return $this 
      */
     public function setSdkService($alias,$instance) {
        $this->_sdkApiServices[$alias] = $instance;
        return $this;
     }

     /**
      * 
      * @param string $alias 
      * @param string $type 
      * @return mixed 
      */
     public function getSdkService(string $alias,string $type = '') {
        if(array_key_exists($alias,$this->_sdkApiServices)) {
            if($type !== '') {
               return isset($this->_sdkApiServices[$alias][$type]) ? $this->_sdkApiServices[$alias][$type] : '';
            } 
            
            return $this->_sdkApiServices[$alias];
          
        }

        return null;
     }

     public function getVersion() {
        return self::VERSION;
     }
 
     public function setErrors($message) {
         self::$errors[] = $message;
     }

     public function getErrors() {
        return self::$errors;
     }

}