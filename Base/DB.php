<?php
 /**
  * PDO base class
  *
  * @package   Tools/Magento
  * @author      Daniel.luo <daniel.luo@silksoftware.com>
  * 
  * $dns = [
  *     'dsn' => 'mysql:dbname=mysql;host=127.0.0.1',
  *     'user' => 'root',
  *     'password' => '12345abc'
  * ];
  * 
  * $pdo = new Base\DB($dns);
  * foreach ($pdo->getDb()->query('SELECT * FROM user') as $row) {
          * print_r($row);
          * echo PHP_EOL;
  * }
  * unset($pdo);
  */
namespace Base;

class DB extends Object
{
    /**
    * PDO object
    *
     * @var object \PDO 
     */
    protected $_db;

    /**
     * Connect DB required field
     *
     * @var array
     */
    protected $_required = ['dsn', 'user', 'password'];


    public function __construct($info)
    {
        parent::__construct($info);
        $this->_requiredCheck();
        $this->init();
    }


    /**
     * Required field validate
     *
     * @throws  Exception required field not exist or empty
     */
    protected function _requiredCheck()
    {
        foreach ($this->_required as $key => $field) {
            // if not set this filed or empty
            if (!$this->hasData($field) || $this->isEmpty($this->getDataSetDefault($field, null))) {
                throw new \Exception($field . ' is required.', 10001);
            }
        }
    }


    /**
     * Init DB connect
     *
     * @throws Exception
     */
    public function init()
    {
        try {
            $this->_db = new \PDO($this->getDsn(), $this->getUser(), $this->getPassword(), array(\PDO::ATTR_PERSISTENT => true));
            $this->_db->query("set names utf8");
        } catch ( \Exception $e ) {
            print_r($e->getMessage());
        }

        return $this->_db;
    }


    /**
     * Get PDO object
     *
     * @throws \Exception
     * @return null | PDO object
     */
    public function getDb()
    {
        if ($this->_db instanceof \PDO) {
            if (empty($this->_db)) {
                $this->_init();
            }
            return $this->_db;
        }

        throw new \Exception('Please init first', 10002);
    }
}
