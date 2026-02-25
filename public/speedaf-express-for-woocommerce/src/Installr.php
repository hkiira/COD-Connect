<?php

declare(strict_types=1);

namespace Wolf\Speedaf;
defined( 'ABSPATH' ) || exit; // block direct access.
use Exception;
use Wolf\Speedaf\Api\TableInterface;
use Wolf\Speedaf\DB\SubscribeTable;

class Installr
{

    /** */
    const INSTALL_DEFAULT_TABEL = [
        SubscribeTable::class
    ];

    /**
     * 
     * @var array
     */
    private $tables = [];

    private $wp;

    private $wpdb;

    public function __construct($wpdb,array $tables = []){
        $this->tables   = $tables;
        $this->wpdb     = $wpdb;

    }

    private function vaild_table($table) {
       
        if(! $table instanceof TableInterface) {
            throw new \Exception( sprintf(
				'The class "%s" must implement the "%s" interface.',
				$table,
				TableInterface::class
			) );
        }
    }

    /**
     * 
     * @param mixed $table 
     * @return Wolf\Speedaf\Api\TableInterface;
     */
    private function createTable(string $table):TableInterface {
       return new $table($this->wpdb);
    }

    /**
     * 
     * @return void 
     * @throws Exception 
     */
    public function install():void {
      
        $this->_handlerTable(self::INSTALL_DEFAULT_TABEL);
        $this->_handlerTable($this->tables);



    }

    /**
     * 
     * @param array $tables 
     * @return void 
     * @throws Exception 
     */

    private function _handlerTable (array $tables):void {
        foreach($tables as $table) {
            if(!is_object($table)) $table = $this->createTable($table);
            $this->vaild_table($table);
          if($table->exists()) continue;
          
            $table->install();

        }
    }


    public function unstall() {
       
        $this->_unTables(self::INSTALL_DEFAULT_TABEL);
        $this->_unTables($this->tables);
        delete_option(\Wolf\Speedaf\Menu\Settings::SDF_APK_KEY);
        delete_option(\Wolf\Speedaf\Menu\Settings::SDF_CUSTOMER_CODE);
	}
    
    private function _unTables($tables) {
        foreach($tables as $table) {
            if(!is_object($table)) $table = $this->createTable($table);
            $tableName  = $table->get_raw_name();
            $this->wpdb->query( "DROP TABLE IF EXISTS {$this->wpdb->prefix}" . \Wolf\Speedaf\DB\Table::TABLE_SLUG . "_{$tableName}" );
        }
    }
    
}
