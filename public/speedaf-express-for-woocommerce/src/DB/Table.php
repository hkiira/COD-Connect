<?php

declare(strict_types=1);

namespace Wolf\Speedaf\DB;
defined( 'ABSPATH' ) || exit; // block direct access.
use Exception;
use wpdb;
use Wolf\Speedaf\Api\TableInterface;
abstract class Table implements TableInterface 
{

 
	/** @var wpdb */
	protected $wpdb;

	/**
	 * 
	 * @var mixed
	 */
	protected $where = [];

	/**
	 * 
	 * @var mixed
	 */
	protected $select = [];

	protected $order = '';
    /**
	 * 
	 * @var mixed
	 */
	protected $group = '';

	/**
	 * 
	 * @var mixed
	 */
	protected $orderby= '';

	/**
	 * 
	 * @var string
	 */
	protected $limit= '';

	/**
	 * 
	 * @var mixed
	 */
	protected $last_insert_id;

    /**
	 * 
	 * @var mixed
	 */
	protected $where_relation = null;

    const TABLE_SLUG = 'spf';


	/**
	 * 
	 * @param string $column 
	 * @return $this 
	 */

	public function select($column = '*')
	{
		if(is_array($column)) {
			foreach($column as $alias => $col) {
				if(!$this->validate_column($col)) continue;
				if(!is_numeric($alias)) $this->select[] = sprintf('`%s` as $s',$col,$alias);

				else  $this->select[] = $this->select[] = sprintf('%s',$col);
			}
		}else {
              
			$this->select[] = $column;
		}

		return $this;


	}



	/**
	 * 
	 * @param mixed $col 
	 * @return bool 
	 */
	protected function validate_column($col) {
		
		return  array_key_exists( $col, $this->get_columns() ) ?? false;
		
		
	}

	/**
	 * 
	 * @param mixed $column 
	 * @param mixed $value 
	 * @param mixed $compare 
	 * @return $this 
	 * @throws Exception 
	 */
	public function where($column,$value='',$compare='')
	{
		if(is_array($column)) {
			foreach($column as $col => $val) {
				$this->where[] = [
					'column'  => $col,
					'value'   => (string)$val,
					'compare' => '=',
				];
			}
		}
		else {
			$this->validate_compare( $compare );
			$this->where[] = [
				'column'  => $column,
				'value'   => $value,
				'compare' => $compare,
			];
	
		}
		
		return $this;
	}

	/**
	 * 
	 * @param string $group 
	 * @return $this 
	 */
	public function groupBy(string $group){
		$this->group = $group;
		return $this;
	}

	/**
	 * Validate that a compare operator is valid.
	 *
	 * @param string $compare
	 *
	 * @throws InvalidQuery When the compare value is not valid.
	 */
	protected function validate_compare( string $compare ) {
		switch ( $compare ) {
			case '=':
			case '>':
			case '<':
			case 'IN':
			case 'NOT IN':
				// These are all valid.
				return;

			default:
				throw new \Exception( $compare );
		}
	}


	/**
	 * 
	 * @param string $orderby 
	 * @return TableInterface 
	 */

	public function orderBy(string $orderby)
	{
		$this->order = $orderby;
		return $this;
	}

	/**
	 * 
	 * @param mixed $offset 
	 * @param int $num 
	 * @return $this 
	 */
	public function limit($offset, $num = 10)
	{
		$this->limit = sprintf('%d,%d',$offset,$num);
		return $this;
	}

	/**
	 * 
	 * @return mixed 
	 * @throws Exception 
	 */
	public function query()
	{
		$sql = $this->build_query();
		if(!$sql) throw new \Exception('this sql error'.$sql);

		return  $this->wpdb->get_results(
			$sql, // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	public function getOne() {
		$sql = $this->build_query();
		if(!$sql) throw new \Exception('this sql error'.$sql);

		return $this->wpdb->get_row($sql,ARRAY_A);
	}

	/**
	 * Sanitize a value for a given column before inserting it into the DB.
	 *
	 * @param string $column The column name.
	 * @param mixed  $value  The value to sanitize.
	 *
	 * @return mixed The sanitized value.
	 * @throws InvalidQuery When the code tries to set the ID column.
	 */
	protected function sanitize_value( string $column, $value ) {
		if ( 'id' === $column ) {
			throw new \Exception('Please updated data');
		}

		if ( 'options' === $column ) {
			if ( ! is_array( $value ) ) {
				throw new \Exception('Please check options col');
			}

			$value = json_encode( $value );
		}

		return $value;
	}

	/**
	 * Insert a row of data into the table.
	 *
	 * @param array $data
	 *
	 * @return int
	 * @throws InvalidQuery When there is an error inserting the data.
	 */
	public function insert( array $data ): int {
		foreach ( $data as $column => &$value ) {
			if(!$this->validate_column( $column ) )continue;
			if($column === $this->get_primary_column()) throw new \Exception('Please updated data');

			$value = $this->sanitize_value( $column, $value );
		}

		$result = $this->wpdb->insert( $this->get_name(), $data );

		if ( false === $result ) {
			throw new \Exception( $this->wpdb->last_error ?: 'Error inserting data.' );
		}

		// Save a local copy of the last inserted ID.
		$this->last_insert_id = $this->wpdb->insert_id;

		return $result;
	}

	/**
	 * get Instert ID
	 * @return mixed 
	 */
	public function getLastId()
	{
		return $this->last_insert_id;
	}


	/**
	 * 
	 * @return string 
	 */
	protected function build_query() {
		if(empty($this->select))  $this->select[] = '*';
		$pieces = [sprintf('SELECT %s FROM `%s`',implode(',',$this->select),$this->get_name())];

		$pieces = array_merge($pieces,$this->generate_where_pieces());
		if ( $this->order ) {
			$pieces[] = sprintf('ORDER BY %s', $this->order);
		}

		if ( $this->group ) {
			$pieces[] = sprintf('GROUP BY %s', $this->group);
		}

		if ( $this->limit ) {
			$pieces[] = sprintf('LIMIT %s', $this->limit);
		}


		return join(' ',$pieces);

		
	}

	/**
	 * Generate the pieces for the WHERE part of the query.
	 *
	 * @return string[]
	 */
	protected function generate_where_pieces(): array {
		if ( empty( $this->where ) ) {
			return [];
		}

		$where_pieces = [ 'WHERE' ];
		foreach ( $this->where as $where ) {
			$column  = $where['column'];
			$compare = $where['compare'];

			if ( $compare === 'IN' || $compare === 'NOT IN' ) {
				$value = sprintf(
					"('%s')",
					join(
						"','",
						array_map(
							function( $value ) {
								return $this->wpdb->_escape( $value );
							},
							$where['value']
						)
					)
				);
			} else {
				$value = "'{$this->wpdb->_escape( $where['value'] )}'";
			}

			if ( count( $where_pieces ) > 1 ) {
				$where_pieces[] = $this->where_relation ?? 'AND';
			}

			$where_pieces[] = "{$column} {$compare} {$value}";
		}

		return $where_pieces;
	}
	/**
	 * Table constructor.
	 *
	 * @param WP   $wp   The WP proxy object.
	 * @param wpdb $wpdb The wpdb object.
	 */
	public function __construct(wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Install the Database table.
	 */
	public function install(): void {
		$this->db_delta( $this->get_install_query() );
	}

    /**
	 * Run the WP dbDelta() function.
	 *
	 * @param string|string[] $sql The query or queries to run.
	 *
	 * @return array Results of the query or queries.
	 */
	private function db_delta( $sql ): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		return dbDelta( $sql );
	}

	/**
	 * Determine whether the table actually exists in the DB.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		$result = $this->wpdb->get_var(
			"SHOW TABLES LIKE '{$this->get_sql_safe_name()}'" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return $result === $this->get_name();
	}

	/**
	 * Delete the Database table.
	 */
	public function delete(): void {
		$this->wpdb->query( "DROP TABLE IF EXISTS `{$this->get_sql_safe_name()}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Truncate the Database table.
	 */
	public function truncate(): void {
		$this->wpdb->query( "TRUNCATE TABLE `{$this->get_sql_safe_name()}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Get the SQL escaped version of the table name.
	 *
	 * @return string
	 */
	protected function get_sql_safe_name(): string {
		return $this->wpdb->_escape( $this->get_name() );
	}

	/**
	 * Get the name of the Database table.
	 *
	 * The name is prefixed with the wpdb prefix, and our plugin prefix.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return "{$this->wpdb->prefix}{$this->get_slug()}_{$this->get_raw_name()}";
	}

	/**
	 * Get the primary column name for the table.
	 *
	 * @return string
	 */
	public function get_primary_column(): string {
		return 'id';
	}

	/**
     * 
     * @param string $index_name 
     * @return bool 
     */
	public function has_index( string $index_name ): bool {
		$result = $this->wpdb->get_results(
			$this->wpdb->prepare( "SHOW INDEX FROM `{$this->get_sql_safe_name()}` WHERE Key_name = %s ", [ $index_name ] )  // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return ! empty( $result );
	}

	/**
	 * Get the DB collation.
	 *
	 * @return string
	 */
	protected function get_collation(): string {
		return $this->wpdb->has_cap( 'collation' ) ? $this->wpdb->get_charset_collate() : '';
	}

    /**
     * 
     * @return string 
     */
    public function get_slug() {
        return self::TABLE_SLUG;
    }

	/**
	 * Get the schema for the DB.
	 *
	 * This should be a SQL string for creating the DB table.
	 *
	 * @return string
	 */
	abstract protected function get_install_query(): string;

	/**
	 * Get the un-prefixed (raw) table name.
	 *
	 * @return string
	 */
	abstract public static function get_raw_name(): string;
    
}
