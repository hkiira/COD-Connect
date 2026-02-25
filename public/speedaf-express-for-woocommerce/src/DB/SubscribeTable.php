<?php

declare(strict_types=1);

namespace Wolf\Speedaf\DB;
defined( 'ABSPATH' ) || exit; // block direct access.
use Wolf\Speedaf\Api\TableInterface;

class SubscribeTable extends Table
{

    /**
     * install table
     * @return string 
     */
    protected function get_install_query(): string {
        return <<< SQL
        CREATE TABLE `{$this->get_sql_safe_name()}` (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            bill_id varchar(50) NOT NULL DEFAULT '',
            subscribe_status tinyint(1) DEFAULT 0,
            created_at datetime NOT NULL,
            last_received_time datetime DEFAULT NULL,
            options text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY billid (bill_id),
            UNIQUE KEY order_bill (bill_id, order_id)
        ) {$this->get_collation()};
        SQL;
     }

     /**
      * 
      * @return string 
      */
    public static function get_raw_name(): string {
        return 'track_subscribe';
     }

     /**
      * 
      * @return array 
      */
    public function get_columns(): array {
        return [
			'id'       => true,
			'order_id'  => true,
			'bill_id'   => true,
			'created_at' => true,
			'subscribe_status'     => true,
			'options'  => true,
            'last_received_time'    => true
		];
     }

     
    
}
