<?php

declare(strict_types=1);

namespace Wolf\Speedaf\DB;
defined( 'ABSPATH' ) || exit; // block direct access.
use Exception;
use wpdb;

class TableManager
{

    private $wpdb;

    private static $instance = null;

       /** */
       const TABELS = [
        'track_sub' => SubscribeTable::class,
        'test'
    ];

    private $tables;

    /**
     * 
     * @param wpdb $wpdb 
     * @return void 
     */
    public function __construct(wpdb $wpdb)
    {
        $this->wpdb    = $wpdb;
    }
    /**
     * 
     * @param wpdb $wpdb 
     * @return TableManage 
     */
    public static function getInstance(wpdb $wpdb) {

        if(self::$instance === null) self::$instance = new self($wpdb);

        return self::$instance;

    }

    /**
     * 
     * @param mixed $alias 
     * @return Table 
     * @throws Exception 
     */
    public function getTable($alias) {

        if( !array_key_exists($alias,self::TABELS)  ){
            if(array_search($alias,self::TABELS) === false) throw new \Exception('Please checked table');
        } 
      
      //  if( !array_search($alias,self::TABELS)) throw new \Exception('Please checked table');
        $table = self::TABELS[$alias];
        return new $table($this->wpdb);
    }

    
}
