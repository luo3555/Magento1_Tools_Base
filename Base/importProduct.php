<?php
/**
 * 1. if want to show product in grid page, status and visibility is required field, expect catalog_product_entity_xx table you must add entity data to catalog_product_index_price. if not product can not show on frontend
 * 2. About configurable product catalog_product_super_attribute define which attribute need config
 * 3. catalog_product_super_attribute_labe configurable product frontend swatch show label
 * 4. catalog_product_super_link, simple and configurable product relationship
 * 5. catalog_product_index_price field tire_price and group_price only save min price
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
    const DEFAULT_STOCK_ID = 1;
    const ALL_GROUPS = 32000;

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
            $sql = "SELECT attribute_id, backend_type, frontend_input, frontend_label FROM `eav_attribute` WHERE `entity_type_id` = :entity_type_id AND attribute_code=:attribute_code";
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
        $typeId = $insertData['type_id'];

        // just base on input array create insert cache
        if (!isset($this->_cache[$attributeSetId][$typeId])) {
            // get insert data fields
            foreach ($insertData as $attributeCode => $value) {
                $type = '';
                if (!in_array($attributeCode, $this->_entityFields)) {
                    // get attribute id and type by code
                    $attribute = $this->getAttribute($attributeCode);
                    // if not get attribute
                    if (empty($attribute)) continue;
                    // mapping static to entity table
                    $type = $attribute->backend_type == 'static' ? '' : $attribute->backend_type;
                    $type = empty($type) ? '' : '_' . $type ;
                }
                // construct insert cache
                if (!isset($this->_cache[$attributeSetId][$typeId][$this->_prefix . $type])) {
                    $this->_cache[$attributeSetId][$typeId][$this->_prefix . $type] = array();
                    $this->_cache[$attributeSetId][$typeId][$this->_prefix . $type]['fields'] = array();
                }
                $this->_cache[$attributeSetId][$typeId][$this->_prefix . $type]['fields'][$attributeCode] = !empty($attribute) ? $attribute->frontend_input : '' ;
                unset($attribute);
            }
            // create insert sql cache
            foreach ($this->_cache[$attributeSetId][$typeId] as $table => $data) {
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
                $this->_cache[$attributeSetId][$typeId][$table]['sql'] = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $fileds, $band);
                unset($fileds);
                unset($band);
            }
        }
        return $this->_cache[$attributeSetId][$typeId];
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

    public function getFrontendStores()
    {
        if (!isset($this->_cache['stores'])) {
            $sql = "SELECT * FROM `core_store` WHERE website_id=:website_id";
            $sth = $this->_pdo->prepare($sql);
            $sth->execute(array(':website_id' => self::DEFAULT_WEBSITE
                ));
            $this->_cache['stores'] = $sth->fetchAll(PDO::FETCH_CLASS);
        }
        return $this->_cache['stores'];
    }

    public function getIdBySku($sku)
    {
        if (!isset($this->_cache[$sku])) {
            $sql = "SELECT entity_id FROM `catalog_product_entity` WHERE `sku`=:sku";
            $sth = $this->_pdo->prepare($sql);
            $sth->execute(array(':sku' => $sku));
            $this->_cache[$sku] = $sth->fetchColumn();
        }
        return $this->_cache[$sku];
    }

    public function addProductRecord($rows)
    {
        // current attribute set cache
        // insert record one by one
        foreach ($rows as $row) {
            // set attribute entity type id
            $row = $this->addDefaultField($row);
            $typeId = $row['type_id'];

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
                // assigen product to main website
                $this->assignedWebsite($entityId);
                // set product price and tax class
                $this->setProductPrice($entityId, $row);
                // set product tier price
                // Independent setting
                // $this->setProductTierPrice($entityId, $row);
                // set product to category
                $this->assignedCagetories($entityId, $row);
                // set product stock
                $this->setProductStock($entityId, $row);
                // set configurable product config field
                if ($typeId == 'configurable') {
                    $this->setConfigurableField($entityId, $row);
                }
                
                // add configurable relationship
                if (isset($row['config_sku'])) {
                    $confEntityId = $this->getIdBySku($row['config_sku']);
                    // if exist configurable product
                    if ($confEntityId) {
                        $this->assignedSimpleToConfigurable($entityId, $confEntityId);
                    }
                }
                unset($confEntityId);
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
        // If have category, when you change price you must chagne related table catalog_product_index_price
        $sql = "INSERT INTO `catalog_product_index_price` (entity_id, customer_group_id, website_id, tax_class_id, price, final_price, min_price, max_price) VALUES(:entity_id, :customer_group_id, :website_id, :tax_class_id, :price, :final_price, :min_price, :max_price)";
        $sth = $this->_pdo->prepare($sql);

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
            $sth->execute($band);
        }
    }

    public function setProductTierPrice($entityId, $row)
    {
        // 32000 mean this tier price adapter all groups
        // 1. set tier price entity
        // 2. set tier price index
        // 3. set tier price in catalog list
        $sql = "INSERT INTO `catalog_product_entity_tier_price` (entity_id, all_groups, customer_group_id, qty, value, website_id) VALUES (:entity_id, :all_groups, :customer_group_id, :qty, :value, :website_id)";
        $tierEntity = $this->_pdo->prepare($sql);

        $sql = "UPDATE `catalog_product_index_price` SET tier_price=:tire_price WHERE entity_id=:entity_id AND customer_group_id=:customer_group_id AND website_id=:website_id";
        $tierIndex = $this->_pdo->prepare($sql);


        if ($row['group_id'] == self::ALL_GROUPS) {
            $band = array(
                    ':entity_id' => $entityId,
                    ':all_groups' => 1,
                    ':customer_group_id' => 0,
                    ':qty' => $row['qty'],
                    ':value' => $row['tier_price'],
                    ':website_id' => 0, // @TODO why here is Zero not self::DEFAULT_WEBSITE
                );
            $tierEntity->execute($band);
            foreach ($this->getGroups() as $group) {
                // @TODO update tier price min price
                // upate catalog_product_index_price
                $band = array(
                        ':tire_price' => $row['tier_price'],
                        ':entity_id' => $entityId,
                        ':customer_group_id' => $group->customer_group_id,
                        ':website_id' => self::DEFAULT_WEBSITE
                    );
                $tierIndex->execute($band);
            }
        } else {
            $band = array(
                    ':entity_id' => $entityId,
                    ':all_groups' => 0,
                    ':customer_group_id' => $row['group_id'],
                    ':qty' => $row['qty'],
                    ':value' => $row['tier_price'],
                    ':website_id' => 0, // @TODO why here is Zero not self::DEFAULT_WEBSITE
                );
            $tierEntity->execute($band);
            // upate catalog_product_index_price
            $band = array(
                    ':tire_price' => $row['tier_price'],
                    ':entity_id' => $entityId,
                    ':customer_group_id' => $row['group_id'],
                    ':website_id' => self::DEFAULT_WEBSITE
                );
            $tierIndex->execute($band);
        }
    }

    public function setProductGroupPrice($entityId, $row)
    {
        $sql = "INSERT INTO `catalog_product_entity_group_price` (entity_id, all_groups, customer_group_id, value, website_id) VALUES (:entity_id, :all_groups, :customer_group_id, :value, :website_id)";
        $groupEntity = $this->_pdo->prepare($sql);

        $sql = "INSERT INTO `catalog_product_index_group_price` (entity_id, customer_group_id, website_id, price) VALUES (:entity_id, :customer_group_id, :website_id, :price)";
        $groupPriceIndex = $this->_pdo->prepare($sql);

        $sql = "UPDATE `catalog_product_index_price` SET group_price=:group_price, final_price=:final_price, min_price=:min_price, max_price=:max_price WHERE entity_id=:entity_id AND customer_group_id=:customer_group_id AND website_id=:website_id";
        $priceIndex = $this->_pdo->prepare($sql);

        // catalog_product_entity_group_price
        $band = array(
                ':entity_id' => $entityId,
                ':all_groups' => 0,
                ':customer_group_id' => $row['group_id'],
                ':value' => $row['group_price'],
                ':website_id' => 0 // self::DEFAULT_WEBSITE
            );
        $groupEntity->execute($band);
        // @TODO update tier price min price
        
        // add group price index
        $band = array(
                ':entity_id' => $entityId,
                ':customer_group_id' => $row['group_id'],
                ':website_id' => self::DEFAULT_WEBSITE,
                ':price' => $row['group_price']
            );
        $groupPriceIndex->execute($band);

        // upate catalog_product_index_price
        $band = array(
                ':entity_id' => $entityId,
                ':final_price' => $row['group_price'],
                ':min_price' => $row['group_price'],
                ':max_price' => $row['group_price'],
                ':group_price' => $row['group_price'],
                ':entity_id' => $entityId,
                ':customer_group_id' => $row['group_id'],
                ':website_id' => self::DEFAULT_WEBSITE
            );
        $priceIndex->execute($band);

    }

    public function assignedCagetories($entityId, $row)
    {
        if (isset($row['category_id'])) {
            // @TODO direct insert category id
            // related tables
            // catalog_category_product_index, catalog_product_index_price
            // @TODO catalog_product_index_price and product price
            $sql = "INSERT INTO `catalog_category_product` (category_id, product_id) VALUE(:category_id, :product_id)";
            $sth = $this->_pdo->prepare($sql);
            // assigned product to category
            $sth->execute(array(':category_id' => $row['category_id'], ':product_id' => $entityId));

            // product and store relationship
            $sql = "INSERT INTO `catalog_category_product_index` (category_id, product_id, position, is_parent, store_id, visibility) VALUES (:category_id, :product_id, :position, :is_parent, :store_id, :visibility)";
            $sth = $this->_pdo->prepare($sql);
            foreach ($this->getFrontendStores() as $store) {
                $band = array(
                    ':category_id' => $row['category_id'],
                    ':product_id' => $entityId,
                    ':position' => isset($row['position']) ? isset($row['position']) : 0,
                    ':is_parent' => 0, // @TODO not sure mean
                    ':store_id' => self::DEFAULT_STOCK_ID,
                    ':visibility' => isset($row['visibility']) ? $row['visibility'] : 1 // 0 is not show, defautl show on each cateogry
                    );
                $sth->execute($band);
            }

            // category price
            // @TODO setProductPrice have do
            // $sql = "INSERT INTO `catalog_product_index_price` (customer_group_id, website_id, tax_class_id, price, final_price, min_price, max_price) VALUES (:customer_group_id, :website_id, :tax_class_id, :price, :final_price, :min_price, :max_price)";
            // $sth = $this->_pdo->prepare($sql);
            // foreach ($this->getGroups() as $group) {
            //     $band = array(
            //             ':customer_group_id' => $group->customer_group_id,
            //             ':website_id' => self::DEFAULT_WEBSITE,
            //             ':tax_class_id' => $row['tax_class_id'],
            //             ':price' => $row['price'],
            //             ':final_price' => $row['price'],
            //             ':min_price' => $row['price'],
            //             ':max_price' => $row['price']
            //         );
            //     $sth->execute($band);
            // }
        }
    }

    public function setConfigurableField($entityId, $row)
    {
        $sql = "INSERT INTO `catalog_product_super_attribute` (product_id, attribute_id) VALUES (:product_id, :attribute_id)";
        $sthAttr = $this->_pdo->prepare($sql);

        $sql = "INSERT INTO `catalog_product_super_attribute_label` (product_super_attribute_id, store_id, use_default, value) VALUES (:product_super_attribute_id, :store_id, :use_default, :value)";
        $sthAttrLabel = $this->_pdo->prepare($sql);

        $attributes = $row['config_attr'];
        foreach ($attributes as $code) {
            $band = array(
                    ':product_id' => $entityId,
                    ':attribute_id' => $this->_cache[$code]->attribute_id
                );
            $sthAttr->execute($band);
            $lastId = $this->_pdo->lastInsertId();
            if ($lastId) {
                $band = array(
                        ':product_super_attribute_id' => $lastId,
                        ':store_id' => self::DEFAULT_STORE,
                        ':use_default' => 1,
                        ':value' => $this->_cache[$code]->frontend_label
                    );
                $sthAttrLabel->execute($band);
            }
            unset($band);
            unset($sth);
        }
    }

    public function setProductStock($entityId, $row)
    {
        // DEFAULT_STOCK_ID
        // cataloginventory_stock_item, cataloginventory_stock_status
        if (isset($row['qty']) && isset($row['in_stock'])) {
            $sql = "INSERT INTO `cataloginventory_stock_status` (product_id, website_id, stock_id, qty, stock_status) VALUES (:product_id, :website_id, :stock_id, :qty, :stock_status)";
            $sth = $this->_pdo->prepare($sql);
            $band = array(
                    ':product_id' => $entityId,
                    ':website_id' => self::DEFAULT_WEBSITE,
                    ':stock_id' => self::DEFAULT_STOCK_ID,
                    ':qty' => $row['qty'],
                    ':stock_status' => $row['in_stock']
                );
            $sth->execute($band);

            $sql = "INSERT INTO `cataloginventory_stock_item` (product_id, stock_id, qty, is_in_stock) VALUES (:product_id, :stock_id, :qty, :is_in_stock)";
            $sth = $this->_pdo->prepare($sql);
            $band = array(
                    ':product_id' => $entityId,
                    ':stock_id' => self::DEFAULT_STOCK_ID,
                    ':qty' => $row['qty'],
                    ':is_in_stock' => $row['in_stock']
                );
            $sth->execute($band);
        }
    }

    public function assignedSimpleToConfigurable($productId, $parentId) {
        $sql = "INSERT INTO `catalog_product_super_link` (product_id, parent_id) VALUES (:product_id, :parent_id)";
        $sth = $this->_pdo->prepare($sql);
        $sth->execute(array(':product_id' => $productId, ':parent_id' => $parentId));
    }


    /////////////////////////////////////////////////////////////////////
    //
    // START IMPORT GROUP PRICE AND TIER PRICE
    //
    /////////////////////////////////////////////////////////////////////
    public function getEntityIdBySku($sku)
    {
        $sql = "SELECT entity_id FROM `catalog_product_entity` WHERE `sku` = :sku";
        $sth = $this->_pdo->prepare($sql);
        $sth->execute(array(':sku' => $sku));
        $entityId = $sth->fetchColumn();
        return (int)$entityId;
    }

    public function importGroupAndTierPrice($rows)
    {
        $currentSku = '';
        $entityId = 0;
        foreach ($rows as $row) {
            if ($currentSku != $row['sku']) {
                $currentSku = $row['sku'];
                $entityId = $this->getEntityIdBySku($currentSku);
            }

            if ($entityId) {
                // add group price
                if (isset($row['group_price'])) {
                    $this->setProductGroupPrice($entityId, $row);
                }
                // add tier price
                if (isset($row['tier_price'])) {
                    $this->setProductTierPrice($entityId, $row);
                }
            }
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
            // if not set this filed, in product detail page
            // config product will not show swatch
            $row['options_container'] = 'container1';
            // 'has_options', 'required_options'
        } else {
            $row['has_options'] = 0;
            $row['required_options'] = 0;
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
$time = time();
$configSku = 'config-' . $time;
$rows = array(
    0 => array(
        'attribute_set_id' => 13, 
        // 'color' => 'res', 
        'sku'=> $configSku, 
        'name' => 'import config test ' . time(),
        'type_id' => 'configurable', // configurable
        'vendor_id' => 1111,
        'status' => 1,
        'visibility' => 4,
        'tax_class_id' => 0,
        'price' => 798,
        'category_id' => 10,
        'color' => '',
        'size' => '',
        'config_attr' => array('color','size'),
        'qty' => 10,
        'in_stock' => 1,
    ),
    1 => array(
        'attribute_set_id' => 13, 
        // 'color' => 'res', 
        'sku'=> time() . uniqid(), 
        'name' => 'import simple test' . time(),
        'type_id' => 'simple', // configurable
        'vendor_id' => 1111,
        'status' => 1,
        'visibility' => 2,
        'tax_class_id' => 0,
        'price' => 798,
        'category_id' => 10,
        'config_sku' => $configSku,
        'color' => 24,
        'size' => 78,
        'qty' => 10,
        'in_stock' => 1,
    ),
    2 => array(
        'attribute_set_id' => 13, 
        // 'color' => 'res', 
        'sku'=> time() . uniqid(), 
        'name' => 'import simple test 2' . time(),
        'type_id' => 'simple', // configurable
        'vendor_id' => 1111,
        'status' => 1,
        'visibility' => 2,
        'tax_class_id' => 0,
        'price' => 798,
        'category_id' => 10,
        'config_sku' => $configSku,
        'color' => 20,
        'size' => 230,
        'qty' => 10,
        'in_stock' => 1,
    ),
);

$tirePrice = array(
        0 => array(
            'sku' => $rows[2]['sku'],
            // 'tier_price' => 66,
            'qty' => 5,
            'group_id' => 1,
            'group_price' => 67
        )
    );

$obj->addProductRecord($rows);
$obj->importGroupAndTierPrice($tirePrice);

