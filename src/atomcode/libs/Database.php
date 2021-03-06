<?php
class Database {
	private static $links = array();
	/**
	 * @var PDO
	 */
	private $link;
	
	/**
	 * @var PDOStatement
	 */
	private $statement;
	
	private static $instances = array();
	private $messages = array();
	
	private $log_sql = false;
	
	/**
	 * 
	 * @param string $specify_db
	 * @return Database
	 */
	public static function &get($specify_db = 'default') {
		$configs = AtomCode::$config['db'];
		
		if (!isset(self::$instances[$specify_db]) && is_array($configs[$specify_db])) {
			self::$instances[$specify_db] = new Database($specify_db, $configs[$specify_db]);
		}
		return self::$instances[$specify_db];
	}
	
	public function __construct($specify_db, $config) {
		$dsn = "mysql:host=$config[hostname];dbname=$config[database];port=$config[port];charset=$config[char_set]";
		$this->log_sql = isset($config['log']) && $config['log'];
		try {
			self::$links[$specify_db] = new PDO($dsn, $config['username'], $config['password']);
			$this->link = &self::$links[$specify_db];
			$stmt = $this->link->prepare('SET NAMES ?'); 
			if (!$stmt->execute(array($config['char_set']))) {
				$this->messages[] = "unsupport charset: " . $config['char_set'] . "\nPDO: " . var_export($stmt->errorInfo(), true);
				
				log_err("unsupport charset: " . $config['char_set']);
				log_err("PDO: " . var_export($stmt->errorInfo(), true));
			}
		} catch (PDOException $e) {
			log_err("cannot connect to db: $dsn, user: $config[username], error: " . $e->getMessage());
			$this->messages[] = "cannot connect to db: $dsn, user: $config[username], error: " . $e->getMessage();
		}
	}

	/**
	 * @return PDOStatement
	 */
	public function bind($sql, $binding) {
		$this->_binding = &$binding;
		$sql = preg_replace_callback("/::(\\w+)/", array($this, 'repl_origen'), $sql);
		
// 		foreach ($binding as $k => $_) {
// 			if ($k{0} == ':') {
// 				$sql = str_replace(":$k", $_, $sql);
// 				unset($binding[$k]);
// 			}
// 		}
		
		$stmt = $this->link->prepare($sql);
		
		foreach ($binding as $k => $_) {
			$stmt->bindParam(':' . $k, $binding[$k]);
		}
		
		return $stmt;
	}
	
	private function repl_origen($matches) {
		if (!isset($this->_binding[$matches[1]])) {
			$this->messages[] = "binding value to param fail, $matches[1] has not been set.";
			return "";
		}
		
		$val = $this->_binding[$matches[1]];
		unset($this->_binding[$matches[1]]);
		
		return $val;
	}
	
	/**
	 * @param PDOStatement $stmt
	 * @return boolean
	 */
	public function query($stmt, $binding = array()) {
		if (is_string($stmt)) {
			$stmt = $this->bind($stmt, $binding);
		}
		
		if ($this->log_sql) {
			log_err("query sql: " . $stmt->queryString);
		}
		
		if (!$stmt->execute()) {
			log_err("fail sql: " . $stmt->queryString);
			$error = $stmt->errorInfo();
			$this->messages[] = "query error: " . print_r($error, true);
			log_err("query error: " . print_r($error, true));
			return false;
		}
	
		$this->statement = $stmt;
		return $stmt;
	}
	
	/**
	 * @param PDOStatement|String $stmt
	 * @return boolean
	 */
	public function queryArray($stmt, $binding = array()) {
		$res = $this->query($stmt, $binding);
	
		if ($res) {
			return $res->fetchAll(PDO::FETCH_ASSOC);
		} else {
			return array();
		}
	}

	function queryRow($sql, $binding = array()) {
		$array = call_user_func_array(array($this, 'queryArray'), func_get_args());
		
		if (!$array) {
			return array();
		} else {
			return $array[0];
		}
	}
	
	public function getErrors() {
		return $this->messages;
	}
	
	public function affectRows() {
		return $this->statement->rowCount();
	}
	
	public function lastInsertId() {
		return $this->link->lastInsertId();
	}
}