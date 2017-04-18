<?php
class ImportProduct
{
    const DSN = 'mysql:dbname=magento1922;host=localhost';
    const USER = 'root';
    const PASSWORD = '12345abc';

    const ATTR_ENTITY_TYPE_ID = 4;

    protected $_pdo;

    protected $_prefix = 'catalog_product_entity';

    protected $_entityFields = array(
            'entity_type_id', 'attribute_set_id', 'type_id', 'sku', 
            'has_options', 'required_options', 'created_at', 'updated_at'
        );

    protected $_cache = array();

    public function __construct()
    {
        try {
            $this->_pdo = new PDO(self::DSN, self::USER, self::PASSWORD, array(PDO::ATTR_PERSISTENT => true));
        } catch (Exception $e) {
            $this->msg($e->getMessage());
        }
    }

    /**
     * Access attribute code get attribute type
     **/
    public function getAttribute($attributeCode)
    {
        if (!isset($this->_cache[$attributeCode])) {
            $sql = "SELECT attribute_id, backend_type, frontend_input FROM `eav_attribute` WHERE `entity_type_id` = :entity_type_id AND attribute_code=:attribute_code";
            $sth = $this->_pdo->prepare($sql);
            $sth->execute(array(':entity_type_id' => self::ATTR_ENTITY_TYPE_ID, ':attribute_code' => $attributeCode));
            $this->_cache[$attributeCode] = $sth->fetchObject();
        }
        return $this->_cache[$attributeCode];
    }

    public function getInsertCache($insertData)
    {
        $attributeSetId = $insertData['attribute_set_id'];

        // just base on input array create insert cache
        if (!isset($this->_cache[$attributeSetId])) {
            // get insert data fields
            foreach ($insertData as $attributeCode => $value) {
                $type = '';
                if (!in_array($attributeCode, $this->_entityFields)) {
                    // get attribute id and type by code
                    $attribute = $this->getAttribute($attributeCode);
                    // mapping static to entity table
                    $type = $attribute->backend_type == 'static' ? '' : $attribute->backend_type;
                    $type = empty($type) ? '' : '_' . $type ;
                }
                // construct insert cache
                if (!isset($this->_cache[$attributeSetId][$this->_prefix . $type])) {
                    $this->_cache[$attributeSetId][$this->_prefix . $type] = array();
                    $this->_cache[$attributeSetId][$this->_prefix . $type]['fields'] = array();
                }
                $this->_cache[$attributeSetId][$this->_prefix . $type]['fields'][$attributeCode] = isset($attribute) ? $attribute->frontend_input : '' ;
                unset($attribute);
            }
            // create insert sql cache
            foreach ($this->_cache[$attributeSetId] as $table => $data) {
                // if php version > 5.6 we can use array_walk replace
                $fields = array();
                foreach (array_keys($data['fields']) as $field) {
                    $fields[] = ':' . $field;
                }
                $this->_cache[$attributeSetId][$table]['sql'] = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', array_keys($data['fields'])), implode(',', $fields));
            }
        }
        return $this->_cache[$attributeSetId];
    }

    public function addProductRecord($rows)
    {
        // current attribute set cache
        // insert record one by one
        foreach ($rows as $row) {
            $insertCache = $this->getInsertCache($row);
            ////////////////////////////////////////////////////////
            if (!empty($insertCache)) {
                foreach ($insertCache as $table => $info) {
                    // start create entity
                    // create entity id
                    if (!empty($info['fields'])) {
                        $sth = $this->_pdo->prepare($info['sql']);
                        $band = array();
                        foreach ($info['fields'] as $field => $type) {
                            $band[':' . $field] = $row[$field];
                        }
                        $sth->execute($band);
                        print_r($band);
                    }
                }
            }
            ////////////////////////////////////////////////////////
        }
    }



    // Create simple product
    

    public function msg($msg, $level = 'error')
    {
        echo PHP_EOL . $msg . PHP_EOL;
    }
}

$obj = new ImportProduct();
$rows = array(
    0 => array(
        'attribute_set_id' => 4, 
        // 'color' => 'res', 
        'sku'=> time(), 
        'name' => 'import test',
        'type_id' => 'simple',
    )
);
// $res = $obj->getInsertCache(array(
//     'attribute_set_id' => 4, 
//     // 'color' => 'res', 
//     'sku'=> time(), 
//     'name' => 'import test',
//     'type_id' => 'simple',
//     ));
// print_r($res);
$obj->addProductRecord($rows);
