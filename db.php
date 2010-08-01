<?php
/*
 * allow this version to run on 2.9.X
 */
if( !function_exists( 'is_multisite' ) ) {
	function is_multisite() { return true; }
}

define('OBJECT', 'OBJECT', true);
define('OBJECT_K', 'OBJECT_K', false);
define('ARRAY_K', 'ARRAY_K', false);
define('ARRAY_A', 'ARRAY_A', false);
define('ARRAY_N', 'ARRAY_N', false);

if (!defined('SAVEQUERIES'))
	define('SAVEQUERIES', false);

if ( !class_exists('db') ) :
class db {
	/* Update for WP 3.0 */
	var $blog_tables = array( 'posts', 'comments', 'links', 'options', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'commentmeta' );
	var $user_tables = array( 'users', 'usermeta' );
	var $old_tables = array( 'categories', 'post2cat', 'link2cat' );
	var $ms_global_tables = array( 'blogs', 'signups', 'site', 'sitemeta', 'sitecategories', 'registration_log', 'blog_versions' );
	var $global_tables = false;
	var $site_tables = false;

	var $show_errors = false;
	var $suppress_errors = false;
	var $last_error;

	var $num_queries = 0;
	var $last_query;
	var $last_table;
	var $last_found_rows_result;
	var $col_info;
	
	var $queries = array();
	var $field_types = array();
	var $save_queries = false;

	var $charset;
	var $collate;
	var $real_escape = false;

	var $dbh;
	var $dbhs;
	var $single_db = false;
	var $db_server = array();
	var $db_servers = array();
	var $db_tables = array();

	var $persistent = false;
	var $max_connections = 10;
	var $srtm = false;
	var $db_connections;
	var $current_host;
	var $dbh2host = array();
	var $last_used_server;
	var $used_servers = array();
	var $written_servers = array();

	function db( $args = array() ) {
		return $this->__construct( $args );
	}

	function __construct( $args = null ) {
		if ( is_array( $args ) )
			foreach ( get_class_vars(__CLASS__) as $var => $value )
				if ( isset( $args[$var] ) )
					$this->$var = $args[$var];
		if ( !$this->single_db ) {
			if ( empty( $this->db_servers ) && isset( $GLOBALS['db_servers'] ) && is_array( $GLOBALS['db_servers'] ) )
				$this->db_servers =& $GLOBALS['db_servers'];
			if ( empty( $this->db_tables ) && isset( $GLOBALS['db_tables'] ) && is_array( $GLOBALS['db_tables'] ) )
				$this->db_tables =& $GLOBALS['db_tables'];
		}
		if ( empty( $this->db_servers ) ) {
			if ( empty( $this->db_server ) )
				$this->bail( 'No database servers have been set up.' );
			else
				$this->single_db = true;
		}
		if( is_multisite() )
			$this->global_tables = array_merge( $this->user_tables, $this->ms_global_tables );
		else
			$this->global_tables = $this->user_tables;
		
		$this->site_tables = array_merge( $this->blog_tables, $this->old_tables );
	}
	/*
	 * weak & strong escaping functions
	 */
	function _weak_escape( $string ) {
		return addslashes( $string );
	}

	function _real_escape( $string ) {
		if ( $this->dbh && $this->real_escape )
			return mysql_real_escape_string( $string, $this->dbh );
		else
			return addslashes( $string );
	}

	function _escape( $data ) {
		return is_array( $data ) ? array_map( array( &$this, '_escape' ), $data ) : $this->_real_escape( $data );
	}

	function escape( $data ) {
		return is_array( $data ) ? array_map( array( &$this, 'escape' ), $data ) : $this->_weak_escape( $data );
	}

	function escape_by_ref( &$string ) {
		$string = $this->_real_escape( $string );
	}

	function escape_deep( $data ) {
		return is_array( $data ) ? array_map( array( &$this, 'escape_deep' ), $data ) : $this->escape( $data );
	}

	/**
	 * Prepares a SQL query for safe execution.  Uses sprintf()-like syntax.
	 *
	 * This function only supports a small subset of the sprintf syntax; it only supports %d (decimal number), %s (string).
	 * Does not support sign, padding, alignment, width or precision specifiers.
	 * Does not support argument numbering/swapping.
	 *
	 * May be called like {@link http://php.net/sprintf sprintf()} or like {@link http://php.net/vsprintf vsprintf()}.
	 *
	 * Both %d and %s should be left unquoted in the query string.
	 *
	 * <code>
	 * wpdb::prepare( "SELECT * FROM `table` WHERE `column` = %s AND `field` = %d", "foo", 1337 )
	 * </code>
	 *
	 * @link http://php.net/sprintf Description of syntax.
	 * @since 2.3.0
	 *
	 * @param string $query Query statement with sprintf()-like placeholders
	 * @param array|mixed $args The array of variables to substitute into the query's placeholders if being called like {@link http://php.net/vsprintf vsprintf()}, or the first variable to substitute into the query's placeholders if being called like {@link http://php.net/sprintf sprintf()}.
	 * @param mixed $args,... further variables to substitute into the query's placeholders if being called like {@link http://php.net/sprintf sprintf()}.
	 * @return null|string Sanitized query string
	 */
	function prepare( $query = null ) { // ( $query, *$args )
		if ( is_null( $query ) )
			return;
		$args = func_get_args();
		array_shift($args);
		// If args were passed as an array (as in vsprintf), move them up
		if ( isset($args[0]) && is_array($args[0]) )
			$args = $args[0];
		$query = str_replace("'%s'", '%s', $query); // in case someone mistakenly already singlequoted it
		$query = str_replace('"%s"', '%s', $query); // doublequote unquoting
		$query = str_replace('%s', "'%s'", $query); // quote the strings
		array_walk($args, array(&$this, 'escape_by_ref'));
		return @vsprintf($query, $args);
	}

	/**
	 * Get SQL/DB error
	 * @param string $str Error string
	 */
	function get_error( $str = '' ) {
		if ( empty($str) ) {
			if ( $this->last_error )
				$str = $this->last_error;
			else
				return false;
		}

		$error_str = "WordPress database error $str for query $this->last_query";

		if ( $caller = $this->get_caller() )
			$error_str .= " made by $caller";

		if ( class_exists( 'WP_Error' ) )
			return new WP_Error( 'db_query', $error_str, array( 'query' => $this->last_query, 'error' => $str, 'caller' => $caller ) );
		else
			return array( 'query' => $this->last_query, 'error' => $str, 'caller' => $caller, 'error_str' => $error_str );
	}

	/**
	 * Print SQL/DB error
	 * @param string $str Error string
	 */
	function print_error($str = '') {
		if ( $this->suppress_errors )
			return false;

		$error = $this->get_error( $str );
		if ( is_object( $error ) && is_a( $error, 'WP_Error' ) ) {
			$err = $error->get_error_data();
			$err['error_str'] = $error->get_error_message();
		} else {
			$err =& $error;
		}

		$log_file = ini_get('error_log');
		if ( !empty($log_file) && ('syslog' != $log_file) && !is_writable($log_file) && function_exists( 'error_log' ) )
			error_log($err['error_str'], 0);

		// Is error output turned on or not
		if ( !$this->show_errors )
			return false;

		$str = htmlspecialchars($err['str'], ENT_QUOTES);
		$query = htmlspecialchars($err['query'], ENT_QUOTES);

		// If there is an error then take note of it
		print "<div id='error'>
		<p class='dberror'><strong>Database error:</strong> [$str]<br />
		<code>$query</code></p>
		</div>";
	}

	/**
	 * Turn error output on or off
	 * @param bool $show
	 * @return bool previous setting
	 */
	function show_errors( $show = true ) {
		$errors = $this->show_errors;
		$this->show_errors = $show;
		return $errors;
	}

	/**
	 * Turn error output off
	 * @return bool previous setting of show_errors
	 */
	function hide_errors() {
		return $this->show_errors( false );
	}

	/**
	 * Turn error logging on or off
	 * @param bool $suppress
	 * @return bool previous setting
	 */
	function suppress_errors( $suppress = true ) {
		$errors = $this->suppress_errors;
		$this->suppress_errors = $suppress;
		return $errors;
	}

	/**
	 * Find the first table name referenced in a query
	 * @param string query
	 * @return string table
	 */
	function get_table_from_query ( $q ) {
		// Remove characters that can legally trail the table name
		$q = rtrim($q, ';/-#');
		// allow (select...) union [...] style queries. Use the first queries table name.
		$q = ltrim($q, "\t ("); 

		// Quickly match most common queries
		if ( preg_match('/^\s*(?:'
				. 'SELECT.*?\s+FROM'
				. '|INSERT(?:\s+IGNORE)?(?:\s+INTO)?'
				. '|REPLACE(?:\s+INTO)?'
				. '|UPDATE(?:\s+IGNORE)?'
				. '|DELETE(?:\s+IGNORE)?(?:\s+FROM)?'
				. ')\s+`?(\w+)`?/is', $q, $maybe) )
			return $maybe[1];

		// Refer to the previous query
		if ( preg_match('/^\s*SELECT.*?\s+FOUND_ROWS\(\)/is', $q) )
			return $this->last_table;

		// Big pattern for the rest of the table-related queries in MySQL 5.0
		if ( preg_match('/^\s*(?:'
				. '(?:EXPLAIN\s+(?:EXTENDED\s+)?)?SELECT.*?\s+FROM'
				. '|INSERT(?:\s+LOW_PRIORITY|\s+DELAYED|\s+HIGH_PRIORITY)?(?:\s+IGNORE)?(?:\s+INTO)?'
				. '|REPLACE(?:\s+LOW_PRIORITY|\s+DELAYED)?(?:\s+INTO)?'
				. '|UPDATE(?:\s+LOW_PRIORITY)?(?:\s+IGNORE)?'
				. '|DELETE(?:\s+LOW_PRIORITY|\s+QUICK|\s+IGNORE)*(?:\s+FROM)?'
				. '|DESCRIBE|DESC|EXPLAIN|HANDLER'
				. '|(?:LOCK|UNLOCK)\s+TABLE(?:S)?'
				. '|(?:RENAME|OPTIMIZE|BACKUP|RESTORE|CHECK|CHECKSUM|ANALYZE|OPTIMIZE|REPAIR).*\s+TABLE'
				. '|TRUNCATE(?:\s+TABLE)?'
				. '|CREATE(?:\s+TEMPORARY)?\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?'
				. '|ALTER(?:\s+IGNORE)?\s+TABLE'
				. '|DROP\s+TABLE(?:\s+IF\s+EXISTS)?'
				. '|CREATE(?:\s+\w+)?\s+INDEX.*\s+ON'
				. '|DROP\s+INDEX.*\s+ON'
				. '|LOAD\s+DATA.*INFILE.*INTO\s+TABLE'
				. '|(?:GRANT|REVOKE).*ON\s+TABLE'
				. '|SHOW\s+(?:.*FROM|.*TABLE|.*TABLES\sLIKE)'
				. ')\s+[`\']?(\S+)[`\']?/is', $q, $maybe) )
			return str_replace('\\', '', $maybe[1]);

		// All unmatched queries automatically fall to the global master
		return '';
	}

	/**
	 * Determine the likelihood that this query could alter anything
	 * @param string query
	 * @return bool
	 */
	function is_write_query( $q ) {
		// Quick and dirty: only send SELECT statements to slaves
		$q = ltrim($q, "\t (");
		$word = strtoupper( substr( trim( $q ), 0, 6 ) );
		return 'SELECT' != $word;
	}

	/**
	 * Set a flag to prevent reading from slaves which might be lagging after a write
	 */
	function send_reads_to_masters() {
		$this->srtm = true;
	}

	/**
	 * Get the dataset and partition from the table name. E.g.:
	 * wp_ds_{$dataset}_{$partition}_tablename where $partition is ctype_digit
	 * wp_{$dataset}_{$hash}_tablename where $hash is 1-3 chars of ctype_xdigit
	 * @param unknown_type $table
	 * @return unknown
	 */
	function get_ds_part_from_table( $table ) {
		global $shardb_hash_length, $shardb_dataset, $shardb_num_db, $vip_db;
		
		$table = str_replace( '\\', '', $table );

		if ( substr( $table, 0, strlen( $this->base_prefix ) ) != $this->base_prefix
			|| !isset( $shardb_hash_length )
			|| !preg_match( '/^' . $this->base_prefix . '([0-9]+)_/', $table, $matches ) )
			return false;

		$dataset = $shardb_dataset;
		$hash = substr( md5( $matches[ 1 ] ), 0, $shardb_hash_length );
		$partition = hexdec( $hash );
		$table_blog_id = $matches[ 1 ];
// VIP Blog Check.
// Added by: Luke Poland
		if ( is_array( $vip_db ) && array_key_exists( $table_blog_id, $vip_db ) )
			$partition = $shardb_num_db + intval( $vip_db[ $table_blog_id ] );
// End VIP Addition
		return compact( 'dataset', 'hash', 'partition' );
	}

	function get_dataset_from_table( $table ) {
		if ( isset( $this->db_tables[$table] ) )
			return $this->db_tables[$table];
		foreach ( $this->db_tables as $pattern => $dataset ) {
			if ( '/' == substr( $pattern, 0, 1 ) && preg_match( $pattern, $table ) ) 
				return $dataset;
		}
		return false;
	}

	/**
	 * Figure out which database server should handle the query, and connect to it.
	 * @param string query
	 * @return resource mysql database connection
	 */
	function &db_connect( $query = '' ) {
		global $vip_db, $shardb_local_db;
		$connect_function = $this->persistent ? 'mysql_pconnect' : 'mysql_connect';
		if ( $this->single_db ) {
			if ( is_resource( $this->dbh ) )
				return $this->dbh;
			$this->dbh = $connect_function($this->db_server['host'], $this->db_server['user'], $this->db_server['password'], true);
			if ( ! is_resource( $this->dbh ) )
				$this->bail("We were unable to connect to the database at {$this->db_server['host']}.");
			if ( ! mysql_select_db($this->db_server['name'], $this->dbh) )
				$this->bail("We were unable to select the database.");
			if ( !empty( $this->charset ) ) {
				$collation_query = "SET NAMES '$this->charset'";
				if ( !empty( $this->collate ) )
					$collation_query .= " COLLATE '$this->collate'";
				mysql_query($collation_query, $this->dbh);
			}
			return $this->dbh;
		} else {
			if ( empty( $query ) )
				return false;

			$write = $this->is_write_query( $query );
			$table = $this->get_table_from_query( $query );
			$this->last_table = $table;
			$partition = 0;

			 if( ( $ds_part = $this->get_ds_part_from_table( $table ) ) ) {
				extract( $ds_part, EXTR_OVERWRITE );
				$dbhname = "{$dataset}_{$partition}";
			} else {
				$dbhname = $dataset = 'global';
			}
			if ( $this->srtm || $write || array_key_exists("{$dbhname}_w", $this->written_servers) ) {
				$read_dbh = $dbhname . '_r';
				$dbhname .= '_w';
				$operation = 'write';
			} else {
				$dbhname .= '_r';
				$operation = 'read';
			}

			if ( isset( $this->dbhs[$dbhname] ) && is_resource( $this->dbhs[$dbhname] ) ) { // We're already connected!
				// Keep this connection at the top of the stack to prevent disconnecting frequently-used connections
				if ( $k = array_search($dbhname, $this->open_connections) ) {
					unset($this->open_connections[$k]);
					$this->open_connections[] = $dbhname;
				}
				
				// Using an existing connection, select the db we need and if that fails, disconnect and connect anew.
				if ( ( isset($_server['name']) && mysql_select_db($_server['name'], $this->dbhs[$dbhname]) ) ||
						( isset($this->used_servers[$dbhname]['db']) && mysql_select_db($this->used_servers[$dbhname]['db'], $this->dbhs[$dbhname]) ) ) {
					$this->last_used_server = $this->used_servers[$dbhname];
					$this->current_host = $this->dbh2host[$dbhname];
					return $this->dbhs[$dbhname];
				} else {
					$this->disconnect($dbhname);
				}
			}

			if ( $write && defined( "MASTER_DB_DEAD" ) )
				$this->bail("We're updating the database, please try back in 5 minutes. If you are posting to your site please hit the refresh button on your browser in a few minutes to post the data again. It will be posted as soon as the database is back online again.");

			// Group eligible servers by R (plus 10,000 if remote)
			$server_groups = array();
			foreach ( $this->db_servers[$dataset][$partition] as $server ) {
				// $o = $server['read'] or $server['write']. If false, don't use this server.
				if ( !($o = $server[$operation]) )
					continue;

				if ( $server['dc'] != DATACENTER )
					$o += 10000;

				if ( isset($_server) && is_array($_server) )
					$server = array_merge($server, $_server);

				// Try the local hostname first when connecting within the DC
				if ( $server['dc'] == DATACENTER && isset($server['lhost']) ) {
					$lserver = $server;
					$lserver['host'] = $lserver['lhost'];
					$server_groups[$o - 0.5][] = $lserver;
				}

				$server_groups[$o][] = $server;
			}

			// Randomize each group and add its members to
			$servers = array();
			ksort($server_groups);
			foreach ( $server_groups as $group ) {
				if ( count($group) > 1 )
					shuffle($group);
				$servers = array_merge($servers, $group);
			}

			// at the following index # we have no choice but to connect
			$max_server_index = count($servers) - 1;

			// Connect to a database server
			foreach ( $servers as $server_index => $server ) {
				$this->timer_start();

				// make sure there's always a port #
				list($host, $port) = explode(':', $server['host']);
				if ( empty($port) )
					$port = 3306;

				// reduce the timeout if the host is on the lan
				$mctime = 0.2; // Default
				if ( $shardb_local_db || strtolower(substr($host, -3)) == 'lan' )
					$mctime = 0.05;

				// connect if necessary or possible
				if ( $write || $server_index == $max_server_index || $this->check_tcp_responsiveness($host, $port, $mctime) ) {
					$this->dbhs[$dbhname] = false;
					$try_count = 0;
					while ( $this->dbhs[$dbhname] === false ) {
						$try_count++;
						$this->dbhs[$dbhname] = $connect_function( "$host:$port", $server['user'], $server['password'] );
						if ( $try_count == 4 ) {
							break;
						} else {
							if ( $this->dbhs[$dbhname] === false )
								// Possibility of waiting up to 3 seconds!
								usleep( (500000 * $try_count) );
						}
					}
				} else {
					$this->dbhs[$dbhname] = false;
				}

				if ( $this->dbhs[$dbhname] && is_resource($this->dbhs[$dbhname]) ) {
					$this->db_connections[] = array( "{$server['user']}@$host:$port", number_format( ( $this->timer_stop() ), 7) );
					$this->dbh2host[$dbhname] = $this->current_host = "$host:$port";
					$this->open_connections[] = $dbhname;
					break;
				} else {
					$error_details = array (
						'referrer' => "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}",
						'host' => $host,
						'error' => mysql_error(),
						'errno' => mysql_errno(),
						'tcp_responsive' => $this->tcp_responsive,
					);
					$msg = date( "Y-m-d H:i:s" ) . " Can't select $dbhname - ";
					$msg .= "\n" . print_r($error_details, true);

					$this->print_error( $msg );
				}
			} // end foreach ( $servers as $server )

			if ( ! is_resource( $this->dbhs[$dbhname] ) ) {
				echo "Unable to connect to $host:$port while querying table '$table' ($dbhname)";
				return $this->bail("Unable to connect to $host:$port while querying table '$table' ($dbhname)");
			}
			if ( ! mysql_select_db( $server['name'], $this->dbhs[$dbhname] ) ) {
				echo "Connected to $host:$port but unable to select database '{$server['name']}' while querying table '$table' ($dbhname)";
				return $this->bail("Connected to $host:$port but unable to select database '{$server['name']}' while querying table '$table' ($dbhname)");
			}
			if ( !empty($server['charset']) )
				$collation_query = "SET NAMES '{$server['charset']}'";
			elseif ( !empty($this->charset) )
				$collation_query = "SET NAMES '$this->charset'";
			if ( !empty($collation_query) && !empty($server['collate']) )
				$collation_query .= " COLLATE '{$server['collate']}'";
			if ( !empty($collation_query) && !empty($this->collation) )
				$collation_query .= " COLLATE '$this->collation'";
			mysql_query($collation_query, $this->dbhs[$dbhname]);

			$this->last_used_server = array( "server" => $server['host'], "db" => $server['name'] );

			$this->used_servers[$dbhname] = $this->last_used_server;

			// Close current and prevent future read-only connections to the written cluster
			if ( $write ) {
				if ( isset($db_clusters[$clustername]['read']) )
					unset( $db_clusters[$clustername]['read'] );

				if ( is_resource($this->dbhs[$read_dbh]) && $this->dbhs[$read_dbh] != $this->dbhs[$dbhname] )
					$this->disconnect( $read_dbh );

				$this->dbhs[$read_dbh] = & $this->dbhs[$dbhname];

				$this->written_servers[$dbhname] = true;
			}

			while ( count($this->open_connections) > $this->max_connections ) {
				$oldest_connection = array_shift($this->open_connections);
				if ( $this->dbhs[$oldest_connection] != $this->dbhs[$dbhname] )
					$this->disconnect($oldest_connection);
			}
		}
		return $this->dbhs[$dbhname];
	}

	/**
	 * Disconnect and remove connection from open connections list
	 * @param string $dbhname
	 */
	function disconnect($dbhname) {
		if ( $k = array_search($dbhname, $this->open_connections) )
			unset($this->open_connections[$k]);

		if ( is_resource($this->dbhs[$dbhname]) )
			mysql_close($this->dbhs[$dbhname]);

		unset($this->dbhs[$dbhname]);
	}

	/**
	 * Kill cached query results
	 */
	function flush() {
		$this->last_result = array();
		$this->col_info = null;
		$this->last_query = null;
		$this->last_error = '';
		$this->last_table = '';
		$this->num_rows = 0;
	}

	/**
	 * Basic query. See docs for more details.
	 * @param string $query
	 * @return int number of rows
	 */
	function query($query) {
		// filter the query, if filters are available
		// NOTE: some queries are made before the plugins have been loaded, and thus cannot be filtered with this method
		if ( function_exists('apply_filters') )
			$query = apply_filters('query', $query);

		// initialise return
		$return_val = 0;
		$this->flush();

		// Log how the function was called
		$this->func_call = "\$db->query(\"$query\")";

		// Keep track of the last query for debug..
		$this->last_query = $query;

		if ( $this->save_queries )
			$this->timer_start();

		if ( preg_match('/^\s*SELECT\s+FOUND_ROWS(\s*)/i', $query) && is_resource($this->last_found_rows_result) ) {
			$this->result = $this->last_found_rows_result;
		} else {
			$this->dbh = $this->db_connect( $query );

			if ( ! is_resource($this->dbh) )
				return false;

			$this->result = mysql_query($query, $this->dbh);
			++$this->num_queries;

			if ( preg_match('/^\s*SELECT\s+SQL_CALC_FOUND_ROWS\s/i', $query) ) {
				$this->last_found_rows_result = mysql_query("SELECT FOUND_ROWS()", $this->dbh);
				++$this->num_queries;
			}
		}

		if ( $this->save_queries )
			$this->queries[] = array( $query, $this->timer_stop(), $this->get_caller() );

		// If there is an error then take note of it
		if ( $this->last_error = mysql_error($this->dbh) ) {
			$this->print_error($this->last_error);
			return false;
		}

		if ( preg_match("/^\\s*(insert|delete|update|replace|alter) /i",$query) ) {
			$this->rows_affected = mysql_affected_rows($this->dbh);

			// Take note of the insert_id
			if ( preg_match("/^\\s*(insert|replace) /i",$query) ) {
				$this->insert_id = mysql_insert_id($this->dbh);
			}
			// Return number of rows affected
			$return_val = $this->rows_affected;
		} else {
			$i = 0;
			$this->col_info = array();
			while ($i < @mysql_num_fields($this->result)) {
				$this->col_info[$i] = @mysql_fetch_field($this->result);
				$i++;
			}
			$num_rows = 0;
			$this->last_result = array();
			while ( $row = @mysql_fetch_object($this->result) ) {
				$this->last_result[$num_rows] = $row;
				$num_rows++;
			}

			@mysql_free_result($this->result);

			// Log number of rows the query returned
			$this->num_rows = $num_rows;

			// Return number of rows selected
			$return_val = $this->num_rows;
		}

		return $return_val;
	}

	/**
	 * Insert a row into a table.
	 *
	 * <code>
	 * wpdb::insert( 'table', array( 'column' => 'foo', 'field' => 1337 ), array( '%s', '%d' ) )
	 * </code>
	 *
	 * @since 2.5.0
	 * @see wpdb::prepare()
	 *
	 * @param string $table table name
	 * @param array $data Data to insert (in column => value pairs).  Both $data columns and $data values should be "raw" (neither should be SQL escaped).
	 * @param array|string $format (optional) An array of formats to be mapped to each of the value in $data.  If string, that format will be used for all of the values in $data.  A format is one of '%d', '%s' (decimal number, string).  If omitted, all values in $data will be treated as strings.
	 * @return int|false The number of rows inserted, or false on error.
	 */
	function insert($table, $data, $format = null) {
		$formats = $format = (array) $format;
		$fields = array_keys($data);
		$formatted_fields = array();
		foreach ( $fields as $field ) {
			if ( !empty($format) )
				$form = ( $form = array_shift($formats) ) ? $form : $format[0];
			elseif ( isset($this->field_types[$field]) )
				$form = $this->field_types[$field];
			else
				$form = '%s';
			$formatted_fields[] = $form;
		}
		$sql = "INSERT INTO `$table` (`" . implode( '`,`', $fields ) . "`) VALUES ('" . implode( "','", $formatted_fields ) . "')";
		return $this->query( $this->prepare( $sql, $data) );
	}


	/**
	 * Update a row in the table
	 *
	 * <code>
	 * wpdb::update( 'table', array( 'column' => 'foo', 'field' => 1337 ), array( 'ID' => 1 ), array( '%s', '%d' ), array( '%d' ) )
	 * </code>
	 *
	 * @since 2.5.0
	 * @see wpdb::prepare()
	 *
	 * @param string $table table name
	 * @param array $data Data to update (in column => value pairs).  Both $data columns and $data values should be "raw" (neither should be SQL escaped).
	 * @param array $where A named array of WHERE clauses (in column => value pairs).  Multiple clauses will be joined with ANDs.  Both $where columns and $where values should be "raw".
	 * @param array|string $format (optional) An array of formats to be mapped to each of the values in $data.  If string, that format will be used for all of the values in $data.  A format is one of '%d', '%s' (decimal number, string).  If omitted, all values in $data will be treated as strings.
	 * @param array|string $format_where (optional) An array of formats to be mapped to each of the values in $where.  If string, that format will be used for all of  the items in $where.  A format is one of '%d', '%s' (decimal number, string).  If omitted, all values in $where will be treated as strings.
	 * @return int|false The number of rows updated, or false on error.
	 */
	function update($table, $data, $where, $format = null, $where_format = null) {
		if ( !is_array( $where ) )
			return false;

		$formats = $format = (array) $format;
		$bits = $wheres = array();
		foreach ( (array) array_keys($data) as $field ) {
			if ( !empty($format) )
				$form = ( $form = array_shift($formats) ) ? $form : $format[0];
			elseif ( isset($this->field_types[$field]) )
				$form = $this->field_types[$field];
			else
				$form = '%s';
			$bits[] = "`$field` = {$form}";
		}

		$where_formats = $where_format = (array) $where_format;
		foreach ( (array) array_keys($where) as $field ) {
			if ( !empty($where_format) )
				$form = ( $form = array_shift($where_formats) ) ? $form : $where_format[0];
			elseif ( isset($this->field_types[$field]) )
				$form = $this->field_types[$field];
			else
				$form = '%s';
			$wheres[] = "`$field` = {$form}";
		}

		$sql = "UPDATE `$table` SET " . implode( ', ', $bits ) . ' WHERE ' . implode( ' AND ', $wheres );
		return $this->query( $this->prepare( $sql, array_merge(array_values($data), array_values($where))) );
	}

	/**
	 * Retrieve one variable from the database.
	 *
	 * Executes a SQL query and returns the value from the SQL result.
	 * If the SQL result contains more than one column and/or more than one row, this function returns the value in the column and row specified.
	 * If $query is null, this function returns the value in the specified column and row from the previous SQL result.
	 *
	 * @since 0.71
	 *
	 * @param string|null $query SQL query.  If null, use the result from the previous query.
	 * @param int $x (optional) Column of value to return.  Indexed from 0.
	 * @param int $y (optional) Row of value to return.  Indexed from 0.
	 * @return string Database query result
	 */
	function get_var($query=null, $x = 0, $y = 0) {
		$this->func_call = "\$db->get_var(\"$query\",$x,$y)";
		if ( $query )
			$this->query($query);

		// Extract var out of cached results based x,y vals
		if ( !empty( $this->last_result[$y] ) ) {
			$values = array_values(get_object_vars($this->last_result[$y]));
		}

		// If there is a value return it else return null
		return (isset($values[$x]) && $values[$x]!=='') ? $values[$x] : null;
	}

	/**
	 * Retrieve one row from the database.
	 *
	 * Executes a SQL query and returns the row from the SQL result.
	 *
	 * @since 0.71
	 *
	 * @param string|null $query SQL query.
	 * @param string $output (optional) one of ARRAY_A | ARRAY_N | OBJECT constants.  Return an associative array (column => value, ...), a numerically indexed array (0 => value, ...) or an object ( ->column = value ), respectively.
	 * @param int $y (optional) Row to return.  Indexed from 0.
	 * @return mixed Database query result in format specifed by $output
	 */
	function get_row($query = null, $output = OBJECT, $y = 0) {
		$this->func_call = "\$db->get_row(\"$query\",$output,$y)";
		if ( $query )
			$this->query($query);
		else
			return null;

		if ( !isset($this->last_result[$y]) )
			return null;

		if ( $output == OBJECT ) {
			return $this->last_result[$y] ? $this->last_result[$y] : null;
		} elseif ( $output == ARRAY_A ) {
			return $this->last_result[$y] ? get_object_vars($this->last_result[$y]) : null;
		} elseif ( $output == ARRAY_N ) {
			return $this->last_result[$y] ? array_values(get_object_vars($this->last_result[$y])) : null;
		} else {
			$this->print_error(/*WP_I18N_DB_GETROW_ERROR*/" \$db->get_row(string query, output type, int offset) -- Output type must be one of: OBJECT, ARRAY_A, ARRAY_N"/*/WP_I18N_DB_GETROW_ERROR*/);
		}
	}

	/**
	 * Retrieve one column from the database.
	 *
	 * Executes a SQL query and returns the column from the SQL result.
	 * If the SQL result contains more than one column, this function returns the column specified.
	 * If $query is null, this function returns the specified column from the previous SQL result.
	 *
	 * @since 0.71
	 *
	 * @param string|null $query SQL query.  If null, use the result from the previous query.
	 * @param int $x Column to return.  Indexed from 0.
	 * @return array Database query result.  Array indexed from 0 by SQL result row number.
	 */
	function get_col($query = null , $x = 0) {
		if ( $query )
			$this->query($query);

		$new_array = array();
		// Extract the column values
		for ( $i=0; $i < count($this->last_result); $i++ ) {
			$new_array[$i] = $this->get_var(null, $x, $i);
		}
		return $new_array;
	}

	/**
	 * Retrieve an entire SQL result set from the database (i.e., many rows)
	 *
	 * Executes a SQL query and returns the entire SQL result.
	 *
	 * @since 0.71
	 *
	 * @param string $query SQL query.
	 * @param string $output (optional) ane of ARRAY_A | ARRAY_N | ARRAY_K | OBJECT | OBJECT_K constants.  With one of the first three, return an array of rows indexed from 0 by SQL result row number.  Each row is an associative array (column => value, ...), a numerically indexed array (0 => value, ...), or an object. ( ->column = value ), respectively.  With OBJECT_K, return an associative array of row objects keyed by the value of each row's first column's value.  Duplicate keys are discarded.
	 * @return mixed Database query results
	 */
	function get_results($query = null, $output = OBJECT) {
		$this->func_call = "\$db->get_results(\"$query\", $output)";

		if ( $query )
			$this->query($query);
		else
			return null;

		if ( $output == OBJECT ) {
			// Return an integer-keyed array of row objects
			return $this->last_result;
		} elseif ( $output == OBJECT_K || $output == ARRAY_K ) {
			// Return an array of row objects with keys from column 1
			// (Duplicates are discarded)
			$key = $this->col_info[0]->name;
			foreach ( (array) $this->last_result as $row )
				if ( !isset( $new_array[ $row->$key ] ) )
					$new_array[ $row->$key ] = $row;
			if ( $output == ARRAY_K )
				return array_map('get_object_vars', $new_array);
			return $new_array;
		} elseif ( $output == ARRAY_A || $output == ARRAY_N ) {
			// Return an integer-keyed array of...
			if ( $this->last_result ) {
				$i = 0;
				foreach( (array) $this->last_result as $row ) {
					if ( $output == ARRAY_N ) {
						// ...integer-keyed row arrays
						$new_array[$i] = array_values( get_object_vars( $row ) );
					} else {
						// ...column name-keyed row arrays
						$new_array[$i] = get_object_vars( $row );
					}
					++$i;
				}
				return $new_array;
			}
		}
	}

	/**
	 * Retrieve column metadata from the last query.
	 *
	 * @since 0.71
	 *
	 * @param string $info_type one of name, table, def, max_length, not_null, primary_key, multiple_key, unique_key, numeric, blob, type, unsigned, zerofill
	 * @param int $col_offset 0: col name. 1: which table the col's in. 2: col's max length. 3: if the col is numeric. 4: col's type
	 * @return mixed Column Results
	 */
	function get_col_info($info_type = 'name', $col_offset = -1) {
		if ( $this->col_info ) {
			if ( $col_offset == -1 ) {
				$i = 0;
				foreach( (array) $this->col_info as $col ) {
					$new_array[$i] = $col->{$info_type};
					$i++;
				}
				return $new_array;
			} else {
				return $this->col_info[$col_offset]->{$info_type};
			}
		}
	}

	/**
	 * Starts the timer, for debugging purposes.
	 *
	 * @since 1.5.0
	 *
	 * @return true
	 */
	function timer_start() {
		$mtime = microtime();
		$mtime = explode(' ', $mtime);
		$this->time_start = $mtime[1] + $mtime[0];
		return true;
	}

	/**
	 * Stops the debugging timer.
	 *
	 * @since 1.5.0
	 *
	 * @return int Total time spent on the query, in milliseconds
	 */
	function timer_stop() {
		$mtime = microtime();
		$mtime = explode(' ', $mtime);
		$time_end = $mtime[1] + $mtime[0];
		$time_total = $time_end - $this->time_start;
		return $time_total;
	}

	/**
	 * Wraps errors in a nice header and footer and dies.
	 *
	 * Will not die if wpdb::$show_errors is true
	 *
	 * @since 1.5.0
	 *
	 * @param string $message
	 * @return false|void
	 */
	function bail($message) {
		if ( $this->show_errors )
			wp_die( $message );

		if ( class_exists('WP_Error') )
			$this->error = new WP_Error('500', $message);
		else
			$this->error = $message;

		return false;
	}

	/**
	 * Checks wether of not the database version is high enough to support the features WordPress uses
	 * @global $wp_version
	 */
	function check_database_version( $dbh_or_table = false ) {
		global $wp_version;
		// Make sure the server has MySQL 4.0
		$mysql_version = preg_replace( '|[^0-9\.]|', '', $this->db_version( $dbh_or_table ) );
		if ( version_compare($mysql_version, '4.3.0', '<') )
			return new WP_Error( 'database_version', sprintf(__('<strong>ERROR</strong>: WordPress %s requires MySQL 4.0.0 or higher'), $wp_version) );
	}

	/**
	 * This function is called when WordPress is generating the table schema to determine wether or not the current database
	 * supports or needs the collation statements.
	 * @return bool
	 */
	function supports_collation() {
		return $this->has_cap( 'collation' );
	}

	/**
	 * Generic function to determine if a database supports a particular feature
	 * @param string $db_cap the feature
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 * @return bool
	 */
	function has_cap( $db_cap, $dbh_or_table = false ) {
		$version = $this->db_version( $dbh_or_table );

		switch ( strtolower( $db_cap ) ) :
		case 'collation' :
		case 'group_concat' :
		case 'subqueries' :
			return version_compare($version, '4.1', '>=');
			break;
		endswitch;

		return false;
	}

	/**
	 * The database version number
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 * @return false|string false on failure, version number on success
	 */
	function db_version( $dbh_or_table = false ) {
		if ( !$dbh_or_table && $this->dbh )
			$dbh =& $this->dbh;
		elseif ( is_resource( $dbh_or_table ) )
			$dbh =& $dbh_or_table;
		else
			$dbh = $this->db_connect( "SELECT FROM $dbh_or_table $this->users" );

		if ( $dbh )
			return preg_replace('/[^0-9.].*/', '', mysql_get_server_info( $dbh ));
		return false;
	}

	/**
	 * Get the name of the function that called wpdb.
	 * @return string the name of the calling function
	 */
	function get_caller() {
		// requires PHP 4.3+
		if ( !is_callable('debug_backtrace') )
			return '';

		$bt = debug_backtrace();
		$caller = '';

		foreach ( (array) $bt as $trace ) {
			if ( isset($trace['class']) && is_a( $this, $trace['class'] ) )
				continue;
			elseif ( !isset($trace['function']) )
				continue;
			elseif ( strtolower($trace['function']) == 'call_user_func_array' )
				continue;
			elseif ( strtolower($trace['function']) == 'apply_filters' )
				continue;
			elseif ( strtolower($trace['function']) == 'do_action' )
				continue;

			if ( isset($trace['class']) )
				$caller = $trace['class'] . '::' . $trace['function'];
			else
				$caller = $trace['function'];
			break;
		}
		return $caller;
	}

	/**
	 * Check the responsiveness of a tcp/ip daemon
	 * @return (bool) true when $host:$post responds within $float_timeout seconds, else (bool) false
	 */
	function check_tcp_responsiveness($host, $port, $float_timeout) {
		if ( 1 == 2 && function_exists('apc_store') ) {
			$use_apc = true;
			$apc_key = "{$host}{$port}";
			$apc_ttl = 10;
		} else {
			$use_apc = false;
		}
		if ( $use_apc ) {
			$cached_value=apc_fetch($apc_key);
			switch ( $cached_value ) {
				case 'up':
					$this->tcp_responsive = 'true';
					return true;
				case 'down':
					$this->tcp_responsive = 'false';
					return false;
			}
		}
	        $socket = fsockopen($host, $port, $errno, $errstr, $float_timeout);
	        if ( $socket === false ) {
			if ( $use_apc )
				apc_store($apc_key, 'down', $apc_ttl);
			$this->tcp_responsive = "false [ > $float_timeout] ($errno) '$errstr'";
	                return false;
		}
		fclose($socket);
		if ( $use_apc )
			apc_store($apc_key, 'up', $apc_ttl);
		$this->tcp_responsive = 'true';
	        return true;
	}
	/* WP 3.0 */
	function tables( $scope = 'all', $prefix = true, $blog_id = 0 ) {
		$key = $scope . '_tables';
		if( 'all' == $scope )
			$tables = array_merge( $this->global_tables, $this->blog_tables );
		elseif( isset( $this->$key ) )
			$tables = $this->$key;
		else
			return array();

		if ( !$prefix )
			return $tables;

		if ( ! $blog_id )
			$blog_id = $this->blogid;

		$blog_prefix = $this->get_blog_prefix( $blog_id );
		$pre_tables = array();

		foreach ( $tables as $table ) {
			if ( in_array( $table, $this->global_tables ) )
				$pre_tables[ $table ] = $this->base_prefix . $table;
			else
				$pre_tables[ $table ] = $blog_prefix . $table;
		}

		if ( isset( $tables['users'] ) ) {
			if( defined( 'CUSTOM_USER_TABLE' ) )
				$pre_tables['users'] = CUSTOM_USER_TABLE;
			if ( defined( 'CUSTOM_USER_META_TABLE' ) )
				$pre_tables['usermeta'] = CUSTOM_USER_META_TABLE;
		}
		return $pre_tables;
	}
	function get_blog_prefix( $blog_id = null ) {
		return $this->base_prefix;
	}
	function set_prefix( $prefix, $set_table_names = true ) {

		if ( preg_match( '|[^a-z0-9_]|i', $prefix ) )
			return new WP_Error('invalid_db_prefix', /*WP_I18N_DB_BAD_PREFIX*/'Invalid database prefix'/*/WP_I18N_DB_BAD_PREFIX*/);

		$old_prefix = $prefix;

		if ( isset( $this->base_prefix ) )
			$old_prefix = $this->base_prefix;

		$this->base_prefix = $prefix;

		if ( $set_table_names ) {
			foreach ( $this->tables( 'all' ) as $table => $prefixed_table )
				$this->$table = $prefixed_table;

			$this->prefix = $this->get_blog_prefix();
		}
		return $old_prefix;
	}
} // class db
endif;


if ( !class_exists( 'wpdb' ) && is_multisite() ) :
class wpdb extends db {
	var $prefix = '';
	var $ready = true;
	var $blogid = 0;
	var $siteid = 0;
	var $blogs, $signups, $site, $sitemeta, $users, $usermeta, $sitecategories, $registration_log, $blog_versions, $posts, $categories, $post2cat, $comments, $links, $link2cat, $options, $postmeta, $terms, $term_taxonomy, $term_relationships;

	function wpdb($dbuser, $dbpassword, $dbname, $dbhost) {
		return $this->__construct($dbuser, $dbpassword, $dbname, $dbhost);
	}

	function __construct($dbuser, $dbpassword, $dbname, $dbhost) {
		$args = array();

		if ( defined('WP_DEBUG') and WP_DEBUG == true )
			$args['show_errors'] = true;

		if ( defined('DB_CHARSET') )
			$args['charset'] = DB_CHARSET;
		else
			$args['charset'] = 'utf8';

		if ( defined('DB_COLLATE') )
			$args['collate'] = DB_COLLATE;
		elseif ( $args['charset'] == 'utf8' )
			$args['collate'] = 'utf8_general_ci';

		$args['save_queries'] = (bool) constant('SAVEQUERIES');

		$args['db_server'] = array(
			'user'     => $dbuser,
			'password' => $dbpassword,
			'name'     => $dbname,
			'host'     => $dbhost
		);

		return parent::__construct($args);
	}

	function set_prefix( $prefix, $set_table_names = true ) {

		if ( preg_match( '|[^a-z0-9_]|i', $prefix ) )
			return new WP_Error('invalid_db_prefix', /*WP_I18N_DB_BAD_PREFIX*/'Invalid database prefix'/*/WP_I18N_DB_BAD_PREFIX*/);

		$old_prefix = '';
		if ( isset( $this->base_prefix ) )
			$old_prefix = $this->base_prefix;

		$this->base_prefix = $prefix;

		if ( $set_table_names ) {
			if( empty( $this->blogid ) )
				$scope = 'global';
			else
				$scope = 'all';

			$this->prefix = $this->get_blog_prefix();

			foreach ( $this->tables( $scope ) as $table => $prefixed_table )
				$this->$table = $prefixed_table;
		}
		return $old_prefix;
	}

	function set_blog_id( $blog_id, $site_id = 0 ) {
		if ( ! empty( $site_id ) )
			$this->siteid = $site_id;

		$old_blog_id  = $this->blogid;
		$this->blogid = $blog_id;

		$this->prefix = $this->get_blog_prefix();

		foreach ( $this->tables( 'site' ) as $table => $prefixed_table )
			$this->$table = $prefixed_table;

		return $old_blog_id;
	}

	function get_blog_prefix( $blog_id = null ) {
		if ( null === $blog_id )
			$blog_id = $this->blogid;
		if ( defined( 'MULTISITE' ) && ( 0 == $blog_id || 1 == $blog_id ) )
			return $this->base_prefix;

		return $this->base_prefix . $blog_id . '_';
	}

	function print_error($str = '') {
		global $EZSQL_ERROR;

		if (!$str) $str = mysql_error($this->dbh);
		$EZSQL_ERROR[] = array ('query' => $this->last_query, 'error_str' => $str);

		if ( $this->suppress_errors )
			return false;

		if ( $caller = $this->get_caller() )
			$error_str = sprintf(/*WP_I18N_DB_QUERY_ERROR_FULL*/'WordPress database error %1$s for query %2$s made by %3$s'/*/WP_I18N_DB_QUERY_ERROR_FULL*/, $str, $this->last_query, $caller);
		else
			$error_str = sprintf(/*WP_I18N_DB_QUERY_ERROR*/'WordPress database error %1$s for query %2$s'/*/WP_I18N_DB_QUERY_ERROR*/, $str, $this->last_query);

		$log_error = true;
		if ( ! function_exists('error_log') )
			$log_error = false;

		$log_file = @ini_get('error_log');
		if ( !empty($log_file) && ('syslog' != $log_file) && !is_writable($log_file) )
			$log_error = false;

		if ( $log_error )
			@error_log($error_str, 0);

		// Is error output turned on or not..
		if ( !$this->show_errors )
			return false;

		// If there is an error then take note of it
		$msg = "WordPress database error: [$str]\n{$this->query}\n";
		if( defined( 'ERRORLOGFILE' ) )
			error_log( $msg, 3, CONSTANT( 'ERRORLOGFILE' ) );
		if( defined( 'DIEONDBERROR' ) )
			die( $msg );
	}
} // class wpdb

$wpdb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
endif;

?>
