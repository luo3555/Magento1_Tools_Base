<?php
 /**
  * PDO base class
  *
  * @package   Tools/Magento
  * @author      Daniel.luo <daniel.luo@silksoftware.com>
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


    public function __construct()
    {
        parent::__construct();
        $this->_requiredCheck();
        $this->_init();
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
    protected function _init()
    {
        try {
            $this->_db = new \PDO($this->getDns(), $this->getUser(), $this->getPassword(), array(PDO::ATTR_PERSISTENT => true));
        } catch ( \Exception $e ) {
            print_r($e->getMessage());
        }
    }


    /**
     * Get PDO object
     *
     * @throws \Exception
     * @return null | PDO object
     */
    public function getDb()
    {
        if ($this->_db instdnceof \PDO) {
            return $this->_db;
        }

        throw new \Exception('Please init first', 10002);
    }
}
