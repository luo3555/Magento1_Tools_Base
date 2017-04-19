<?php
/**
 * 1. if want to show product in grid page, status and visibility is required field, expect catalog_product_entity_xx table you must add entity data to catalog_product_index_price. if not product can not show on frontend
 */
class ImportProduct
{
    const DSN = 'mysql:dbname=magento_1932;host=localhost';
    const USER = 'root';
    const PASSWORD = '12345abc';

    const ATTR_ENTITY_TYPE_ID = 4;
    const DEFAULT_STORE = 0;
    const DEFAULT_WEBSITE = 1;
    const DEFAULT_TAX_CLASS_ID = 0;

    protected $_pdo;

    protected $_prefix = 'catalog_product_entity';

    protected $_entityFields = array(
            'entity_type_id', 'attribute_set_id', 'type_id', 'sku', 
            'has_options', 'required_options', 'created_at', 'updated_at',
            // custome field
            'vendor_id'
        );

    protected $_cache = array();

    public function __construct()
    {
        try {
            $this->_pdo = new PDO(self::DSN, self::USER, self::PASSWORD, array(PDO::ATTR_PERSISTENT => true));
        } catch (Exception $e) {
            $this->_error($e->getMessage());
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

    /**
     * Access this function we can auto get table and insert sql and need insert fields
     * 
     * array(
     *     'table1' => array(
     *         'fields' => array(
     *             'name' => text,
     *             ...
     *         ),
     *         'sql' => 'INSERT INTO catalog_product_entity_varchar (`entity_type_id`,`attribute_id`,`store_id`,`entity_id`,`value`) VALUES (4,:attribute_id,0,:entity_id,:value)
'
     *     ),
     *     'table2' => array(
     *         ...
     *     ),
     * )
     * 
     */
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
                $band = array();
                $fields = array();
                // if is main table
                if ($table == $this->_prefix) {
                    foreach (array_keys($data['fields']) as $field) {
                        $band[] = ':' . $field;
                    }
                    $fileds = implode(',', array_keys($data['fields']));
                    $band = implode(',', $band);
                } else {
                    // if is eav table
                    $fields[] = '`entity_type_id`';
                    $band[]   = self::ATTR_ENTITY_TYPE_ID;
                    $fields[] = '`attribute_id`';
                    $band[]   = ':attribute_id';
                    $fields[] = '`store_id`';
                    $band[]   = self::DEFAULT_STORE;
                    $fields[] = '`entity_id`';
                    $band[]   = ':entity_id';
                    $fields[] = '`value`';
                    $band[]   = ':value';
                    $fileds   = implode(',', $fields);
                    $band     = implode(',', $band);
                }
                $this->_cache[$attributeSetId][$table]['sql'] = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $fileds, $band);
                unset($fileds);
                unset($band);
            }
        }
        return $this->_cache[$attributeSetId];
    }

    protected function getGroups()
    {
        if (!isset($this->_cache['customer_group'])) {
            $sth = $this->_pdo->prepare("SELECT * FROM `customer_group`");
            $sth->execute();
            foreach ($sth->fetchAll(PDO::FETCH_CLASS) as $item) {
                $this->_cache['customer_group'][] = $item;
            }
        }
        return $this->_cache['customer_group'];
    }

    public function addProductRecord($rows)
    {
        // current attribute set cache
        // insert record one by one
        foreach ($rows as $row) {
            // set attribute entity type id
            $row = $this->addDefaultField($row);

            $insertCache = $this->getInsertCache($row);
            ////////////////////////////////////////////////////////
            if (!empty($insertCache)) {
                $entityId = '';
                foreach ($insertCache as $table => $info) {
                    // start create entity
                    // create entity id
                    if (!empty($info['fields'])) {
                        $sth = $this->_pdo->prepare($info['sql']);
                        $band = array();
                        // if is not catalog_product_entity table
                        // add require default field
                        if ($table == $this->_prefix) {
                            foreach ($info['fields'] as $field => $type) {
                                // if is default field
                                $band[':' . $field] = $row[$field];
                            }
                            // create entity record
                            $sth->execute($band);
                            // get entity id
                            $entityId = $this->_pdo->lastInsertId();
                            // assigen product to main website
                            $this->assignedWebsite($entityId);

                            // set product price and tax class
                            $this->setProductPrice($entityId, $row);
                        } else {
                            foreach ($info['fields'] as $field => $type) {
                                $band[':attribute_id'] = $this->_cache[$field]->attribute_id;
                                $band[':entity_id'] = $entityId;
                                $band[':value'] = $row[$field];
                                $sth = $this->_pdo->prepare($info['sql']);
                                $sth->execute($band);
                            }
                        }
                    }
                }
                unset($entityId);
            }
            ////////////////////////////////////////////////////////
        }
    }

    protected function assignedWebsite($entityId)
    {
        $sth = $this->_pdo->prepare("INSERT INTO `catalog_product_website` (`product_id`, `website_id`) VALUES(:product_id, :website_id)");
        $sth->execute(array(':product_id' => $entityId, ':website_id' => self::DEFAULT_WEBSITE));
    }

    protected function setProductPrice($entityId, $rowData)
    {
        // if not tier and group price
        // @TODO insert tier and group price
        $sql = "INSERT INTO `catalog_product_index_price` (entity_id, customer_group_id, website_id, tax_class_id, price, final_price, min_price, max_price) VALUES(:entity_id, :customer_group_id, :website_id, :tax_class_id, :price, :final_price, :min_price, :max_price)";

        foreach ($this->getGroups() as $group) {
            $band = array(
                    ':entity_id' => $entityId,
                    ':customer_group_id' => $group->customer_group_id,
                    ':website_id' => self::DEFAULT_WEBSITE,
                    ':tax_class_id' => self::DEFAULT_TAX_CLASS_ID,
                    ':price' => $rowData['price'],
                    ':final_price' => $rowData['price'],
                    ':min_price' => $rowData['price'],
                    ':max_price' => $rowData['price'],
                );
            $sth = $this->_pdo->prepare($sql);
            $sth->execute($band);
        }

    }

    protected function addDefaultField($row)
    {
        // set attribute entity type id
        $row['entity_type_id'] = self::ATTR_ENTITY_TYPE_ID;
        // set options and required options
        if ($row['type_id'] == 'configurable') {
            $row['has_options'] = 1;
            $row['required_options'] = 1;
            // 'has_options', 'required_options'
        }
        // add time
        $row['created_at'] = date('Y-m-d h:m:i', time());
        // $row['updated_at'] = '';
        $row['visibility'] = 4;
        return $row;
    }



    // Create simple product
    protected function _error($msg)
    {
        echo PHP_EOL;
        print_r($msg);
        echo PHP_EOL;
        exit;
    }
    

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
        'name' => 'import test' . time(),
        'type_id' => 'simple',
        'vendor_id' => 1111,
        'status' => 1,
        'visibility' => 4,
        'tax_class_id' => 0,
        'price' => 99
    )
);
$obj->addProductRecord($rows);
