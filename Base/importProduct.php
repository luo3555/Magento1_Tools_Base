<?php
/**
 * 1. if want to show product in grid page, status and visibility is required field, expect catalog_product_entity_xx table you must add entity data to catalog_product_index_price. if not product can not show on frontend
 * 2. About configurable product catalog_product_super_attribute define which attribute need config
 * 3. catalog_product_super_attribute_labe configurable product frontend swatch show label
 * 4. catalog_product_super_link, simple and configurable product relationship
 * 5. catalog_product_index_price field tire_price and group_price only save min price
 * 6. configurable product must set attribute options_container it value must is 'container1'
 */
class ImportProduct
{
    const DSN = 'mysql:dbname=ak_pro_mg;host=localhost';
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

    protected $_flatPrefix = 'catalog_product_flat';

    /** @var array
     *  In the array's field will not search attribute table, they are both in the $_prefix table,
     *  All value will direct insert to $_prefix table
     */
    protected $_entityFields = array(
        'entity_type_id', 'attribute_set_id', 'type_id', 'sku',
        'has_options', 'required_options', 'created_at', 'updated_at',
    );

    /** @var array
     *  Independent Fields use for solve special field
     *  eg: tier_price, group_price they are both have independent table
     */
    protected $_independentFields = array(
        'tier_price', 'group_price' ,
        'attribute_groups' ,
    );

    protected $_cache = array();

    protected $_addLanguage = false;

    public function __construct($dsn=null, $user=null, $password=null)
    {
        try {
            if (!empty($dsn) && !empty($user) && !empty($password)) {
                $this->_pdo = new PDO($dsn, $user, $password, array(PDO::ATTR_PERSISTENT => true));
            } else {
                $this->_pdo = new PDO(self::DSN, self::USER, self::PASSWORD, array(PDO::ATTR_PERSISTENT => true));
            }
            $this->_pdo->exec("SET NAMES 'utf8'");
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
            $sql = "SELECT attribute_id, backend_type, frontend_input, frontend_label, source_model FROM `eav_attribute` WHERE `entity_type_id` = :entity_type_id AND attribute_code=:attribute_code";
            $sth = $this->_pdo->prepare($sql);
            $sth->execute(array(':entity_type_id' => self::ATTR_ENTITY_TYPE_ID, ':attribute_code' => $attributeCode));
            $this->_cache[$attributeCode] = $sth->fetchObject();
        }
        return $this->_cache[$attributeCode];
    }

    public function getOptionValue($attributeCode, $optionCode)
    {
        $attribute = $this->_cache[$attributeCode];
        if ($optionCode && $attribute->attribute_id) {
            $sql = "SELECT  eao.option_id, store_id, `value`  FROM `eav_attribute_option_value`  AS eaov LEFT JOIN `eav_attribute_option` AS eao ON eaov.option_id=eao.option_id WHERE store_id=0 AND eaov.`value`=:optionCode AND eao.`attribute_id`=:attributeId";
            $sth = $this->_pdo->prepare($sql);
            $sth->execute(array(':optionCode' => $optionCode, ':attributeId' => $attribute->attribute_id));
            $option = $sth->fetchObject();
        }
        return $option;
    }

    public function setOptionValue($attributeId, $optionCode)
    {
        $firstSql = "INSERT INTO `eav_attribute_option`  (`attribute_id`,`sort_order`) VALUES (:attribute_id,0)";
        $firstSth = $this->_pdo->prepare($firstSql);
        $firstSth->execute(array(':attribute_id' => $attributeId));
        $optionId = $this->_pdo->lastInsertId();

        $sql = "INSERT INTO `eav_attribute_option_value`  (`option_id`,`store_id`,`value`) VALUES (:option_id,:store_id,:value)";
        $sth = $this->_pdo->prepare($sql);
        $sth->execute(array(':option_id' => $optionId , ':store_id' => 0, 'value' => $optionCode));

        foreach ($this->getFrontendStores() as $store) {
            $sql = "INSERT INTO `eav_attribute_option_value`  (`option_id`,`store_id`,`value`) VALUES (:option_id,:store_id,:value)";
            $sth = $this->_pdo->prepare($sql);
            $sth->execute(array(':option_id' => $optionId , ':store_id' => $store->store_id, 'value' => $optionCode));
        }
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
        unset($insertData['store_id']);

        // just base on input array create insert cache
        if (!isset($this->_cache[$attributeSetId][$typeId])) {
            // get insert data fields
            foreach ($insertData as $attributeCode => $value) {
                if ($attributeCode == 'config_attr' && is_array($value)) {
                    foreach ($value as  $index => $info) {
                        $_attrCode = is_array($info) ? $info['code'] : $info ;
                        $type = '';
                        if (!in_array($_attrCode, $this->_entityFields)) {
                            // get attribute id and type by code
                            $attribute = $this->getAttribute($_attrCode);
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
                        $this->_cache[$attributeSetId][$typeId][$this->_prefix . $type]['fields'][$_attrCode] = !empty($attribute) ? $attribute->frontend_input : '' ;
                        unset($attribute);
                    }
                } else {
                    $type = '';
                    // if fiele in $_independentFields mean's it have independent tabel solve this attribute
                    if (!in_array($attributeCode, $this->_independentFields)) {
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
                }
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
                    $band[]   = ':store_id';
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
        ksort($this->_cache[$attributeSetId][$typeId]);
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
        if (!isset($this->_cache[$sku]) || empty($this->_cache[$sku])) {
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
            $this->addProductOneRecord($row);
        }
    }

    public function addProductOneRecord($row)
    {
        $status = false;
        // set attribute entity type id
        $row = $this->addDefaultField($row);
        $insertCache = $this->getInsertCache($row);
        ////////////////////////////////////////////////////////
        if (!empty($insertCache)) {
            try {
                $this->_pdo->beginTransaction();
                $this->_insertProduct($row, $insertCache);
                $this->_pdo->commit();
                $status = true;
            } catch (Exception $e) {
                $this->_pdo->rollBack();
                echo $e->getMessage();
                $status = false;
            }
        }

        ////////////////////////////////////////////////////////
        return $status;
    }

    public function _insertProduct($row, $insertCache)
    {
        // if exist and not more language
        $entityId = (int)$this->getIdBySku($row['sku']);

        $typeId = $row['type_id'];
        $storeId = isset($row['store_id']) ? $row['store_id'] : self::DEFAULT_STORE ;

        // Add product entity
        foreach ($insertCache as $table => $info) {
            // start create entity
            // create entity id
            if (!empty($info['fields'])) {
                $sth = $this->_pdo->prepare($info['sql']);
                $band = array();
                // if is not catalog_product_entity table
                // add require default field
                if ($table == $this->_prefix) {
                    // if is add multi language
                    if (!$entityId) {
                        foreach ($info['fields'] as $field => $type) {
                            // if is default field
                            $band[':' . $field] = $row[$field];
                        }
                        // create entity record
                        $sth->execute($band);
                        // get entity id
                        $entityId = $this->_pdo->lastInsertId();
                    }
                } else {
                    foreach ($info['fields'] as $field => $type) {
                        if (!isset($row[$field])) {
                            continue;
                        }
                        if ($row[$field]==='') {
                            continue;
                        }
                        if ($this->_cache[$field]->frontend_input == 'select' &&
                            ($this->_cache[$field]->source_model == null || $this->_cache[$field]->source_model == 'eav/entity_attribute_source_table')) {
                            if (!$this->getOptionValue($field, $row[$field])->option_id) {
                                $this->setOptionValue($this->_cache[$field]->attribute_id ,$row[$field]);
                            }
                            $band[':value'] = $this->getOptionValue($field, $row[$field])->option_id;
                        } else {
                            $band[':value'] = $row[$field];
                        }

                        $band[':attribute_id'] = $this->_cache[$field]->attribute_id;
                        $band[':entity_id'] = $entityId;
                        $band[':store_id']  = $storeId;
                        $sth = $this->_pdo->prepare($info['sql']);
                        $sth->execute($band);
                    }
                }
            }
        }

        // add data to flat table, if exist
        $this->insertProductFlatData($entityId, $row);

        // The following attribute is global, only need import once
        if ($row['store_id']===self::DEFAULT_STORE) {
            // assigen product to main website
            $this->assignedWebsite($entityId);
            // set product price and tax class
            $this->setProductPrice($entityId, $row);
            // set product tier price
            // Independent setting
            isset($row['tier_price']) ? $this->setProductTierPrice($entityId, $row['tier_price']) : '' ;
            isset($row['group_price']) ? $this->setProductGroupPrice($entityId, $row['group_price']) : '' ;
            // set product to category
            $this->assignedCagetories($entityId, $row);
            // set product stock
            $this->setProductStock($entityId, $row);
            // set product gallery
            if (isset($row['gallery']) && is_array($row['gallery'])) {
                foreach ($row['gallery'] as $gallery) {
                    $this->setMediaGallery($entityId, $gallery);
                }
            }

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
        }
        unset($confEntityId);
        unset($entityId);
    }

    protected function assignedWebsite($entityId)
    {
        $sth = $this->_pdo->prepare("INSERT INTO `catalog_product_website` (`product_id`, `website_id`) VALUES(:product_id, :website_id)");
        $sth->execute(array(':product_id' => $entityId, ':website_id' => self::DEFAULT_WEBSITE));
    }

    public function setProductPrice($entityId, $rowData)
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

    public function updateProductPrice($entityId, $rowData)
    {
        // change price attribute
        $sql = "UPDATE `catalog_product_entity_decimal` SET `value`=:value WHERE entity_id=:entity_id AND store_id=0 AND attribute_id=75 AND entity_type_id = 4";
        $price = $this->_pdo->prepare($sql);

        // If have category, when you change price you must chagne related table catalog_product_index_price
        $indexSql = "UPDATE `catalog_product_index_price` SET price=:price, final_price=:final_price, min_price=:min_price, max_price=:max_price WHERE entity_id=:entity_id AND customer_group_id=:customer_group_id AND website_id=:website_id";
        $priceIndex = $this->_pdo->prepare($indexSql);

        $band = array(
            ':entity_id' => $entityId,
            ':value' => $rowData['price'],
        );
        $price->execute($band);

        foreach ($this->getGroups() as $group) {
            $band = array(
                ':entity_id' => $entityId,
                ':customer_group_id' => $group->customer_group_id,
                ':website_id' => self::DEFAULT_WEBSITE,
                ':price' => $rowData['price'],
                ':final_price' => $rowData['price'],
                ':min_price' => $rowData['price'],
                ':max_price' => $rowData['price'],
            );
            $priceIndex->execute($band);
        }
    }

    public function setProductTierPrice($entityId, $row)
    {
        // 32000 mean this tier price adapter all groups
        // 1. delete tier price entity
        // 2. set tier price entity
        // 3. set tier price index
        // 4. set tier price in catalog list
        $sql = "DELETE FROM `catalog_product_entity_tier_price` WHERE entity_id = :entity_id";
        $tierEntityDelete = $this->_pdo->prepare($sql);
        $deleteBand = array(':entity_id' => $entityId);
        $tierEntityDelete->execute($deleteBand);

        $sql = "INSERT INTO `catalog_product_entity_tier_price` (entity_id, all_groups, customer_group_id, qty, value, website_id) VALUES (:entity_id, :all_groups, :customer_group_id, :qty, :value, :website_id)";
        $tierEntity = $this->_pdo->prepare($sql);

        $sql = "UPDATE `catalog_product_index_price` SET tier_price=:tire_price WHERE entity_id=:entity_id AND customer_group_id=:customer_group_id AND website_id=:website_id";
        $tierIndex = $this->_pdo->prepare($sql);

        foreach ($row as $item) {
            if ($item['group_id'] == self::ALL_GROUPS) {
                $band = array(
                    ':entity_id' => $entityId,
                    ':all_groups' => 1,
                    ':customer_group_id' => 0,
                    ':qty' => $item['qty'],
                    ':value' => $item['tier_price'],
                    ':website_id' => 0, // @TODO why here is Zero not self::DEFAULT_WEBSITE
                );
                $tierEntity->execute($band);
                foreach ($this->getGroups() as $group) {
                    // @TODO update tier price min price
                    // upate catalog_product_index_price
                    $band = array(
                        ':tire_price' => $item['tier_price'],
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
                    ':customer_group_id' => $item['group_id'],
                    ':qty' => $item['qty'],
                    ':value' => $item['tier_price'],
                    ':website_id' => 0, // @TODO why here is Zero not self::DEFAULT_WEBSITE
                );
                $tierEntity->execute($band);
                // upate catalog_product_index_price
                $band = array(
                    ':tire_price' => $item['tier_price'],
                    ':entity_id' => $entityId,
                    ':customer_group_id' => $item['group_id'],
                    ':website_id' => self::DEFAULT_WEBSITE
                );
                $tierIndex->execute($band);
            }
        }
    }

    public function setProductGroupPrice($entityId, $row)
    {
        $sql = "DELETE FROM `catalog_product_entity_group_price` WHERE entity_id = :entity_id";
        $groupEntityDelete = $this->_pdo->prepare($sql);
        $deleteBand = array(':entity_id' => $entityId);
        $groupEntityDelete->execute($deleteBand);

        $sql = "INSERT INTO `catalog_product_entity_group_price` (entity_id, all_groups, customer_group_id, value, website_id) VALUES (:entity_id, :all_groups, :customer_group_id, :value, :website_id)";
        $groupEntity = $this->_pdo->prepare($sql);

        $sql = "INSERT INTO `catalog_product_index_group_price` (entity_id, customer_group_id, website_id, price) VALUES (:entity_id, :customer_group_id, :website_id, :price)";
        $groupPriceIndex = $this->_pdo->prepare($sql);

        $sql = "UPDATE `catalog_product_index_price` SET group_price=:group_price, final_price=:final_price, min_price=:min_price, max_price=:max_price WHERE entity_id=:entity_id AND customer_group_id=:customer_group_id AND website_id=:website_id";
        $priceIndex = $this->_pdo->prepare($sql);

        foreach ($row as $item) {
            // catalog_product_entity_group_price
            $band = array(
                ':entity_id' => $entityId,
                ':all_groups' => 0,
                ':customer_group_id' => $item['group_id'],
                ':value' => $item['group_price'],
                ':website_id' => 0 // self::DEFAULT_WEBSITE
            );
            $groupEntity->execute($band);
            // @TODO update tier price min price

            // add group price index
            $band = array(
                ':entity_id' => $entityId,
                ':customer_group_id' => $item['group_id'],
                ':website_id' => self::DEFAULT_WEBSITE,
                ':price' => $item['group_price']
            );
            $groupPriceIndex->execute($band);

            // upate catalog_product_index_price
            $band = array(
                ':entity_id' => $entityId,
                ':final_price' => $item['group_price'],
                ':min_price' => $item['group_price'],
                ':max_price' => $item['group_price'],
                ':group_price' => $item['group_price'],
                ':entity_id' => $entityId,
                ':customer_group_id' => $item['group_id'],
                ':website_id' => self::DEFAULT_WEBSITE
            );
            $priceIndex->execute($band);
        }
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
            // if product id exist in this table
            // this product will direct show on product list page
            /**
            $sql = "INSERT INTO `catalog_category_product_index` (category_id, product_id, position, is_parent, store_id, visibility) VALUES (:category_id, :product_id, :position, :is_parent, :store_id, :visibility)";
            $sth = $this->_pdo->prepare($sql);
            foreach ($this->getFrontendStores() as $store) {
                if ($store->store_id) {
                    $band = array(
                    ':category_id' => $row['category_id'],
                    ':product_id' => $entityId,
                    ':position' => isset($row['position']) ? isset($row['position']) : 0,
                    ':is_parent' => 1, // @TODO not sure mean
                    ':store_id' => $store->store_id ,// self::DEFAULT_STOCK_ID,
                    ':visibility' => isset($row['visibility']) ? $row['visibility'] : 1 // 0 is not show, defautl show on each cateogry
                    );
                    $sth->execute($band);
                }
            }
            //*/

        }
    }

    public function setConfigurableField($entityId, $row)
    {
        $sql = "INSERT INTO `catalog_product_super_attribute` (product_id, attribute_id) VALUES (:product_id, :attribute_id)";
        $sthAttr = $this->_pdo->prepare($sql);

        $sql = "INSERT INTO `catalog_product_super_attribute_label` (product_super_attribute_id, store_id, use_default, value) VALUES (:product_super_attribute_id, :store_id, :use_default, :value)";
        $sthAttrLabel = $this->_pdo->prepare($sql);

        $attributes = $row['config_attr'];
        foreach ($attributes as $info) {
            $code = is_array($info) ? $info['code'] : $info ;
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
                // @add custom attribute label
                if (is_array($info)) {
                    $band = array(
                        ':product_super_attribute_id' => $lastId,
                        //@TODO here need get current store id
                        ':store_id' => 4,
                        ':use_default' => 0,
                        ':value' => $info['label']
                    );
                    $sthAttrLabel->execute($band);
                }
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

            $sql = "INSERT INTO `cataloginventory_stock_item` (product_id, stock_id, qty, is_in_stock , use_config_min_sale_qty, min_sale_qty, use_config_manage_stock) VALUES (:product_id, :stock_id, :qty, :is_in_stock , :use_config_min_sale_qty, :min_sale_qty, :use_config_manage_stock)";
            $sth = $this->_pdo->prepare($sql);
            $band = array(
                    ':product_id' => $entityId,
                    ':stock_id' => self::DEFAULT_STOCK_ID,
                    ':qty' => $row['qty'],
                    ':is_in_stock' => $row['in_stock'] ,
                    ':use_config_min_sale_qty'=> isset($row['min_sale_qty']) ? (int)$row['min_sale_qty'] : 1 ,
                    ':min_sale_qty' => isset($row['min_sale_qty']) ? ($row['min_sale_qty'] > 0 ? $row['min_sale_qty'] : 1) : 1,
                    ':use_config_manage_stock' => isset($row['use_config_manage_stock']) ? $row['use_config_manage_stock'] : 1,
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

            if (isset($row['group_price'])) {
                $compare = join('-', array_merge(
                    array(0,$row['group_id'])
                ));
                $newRows[$entityId]['group'][$compare] = $row;
            }

            if (isset($row['tier_price'])) {
                $compare = join('-', array_merge(
                    array(0,$row['group_id'],$row['qty'])
                ));
                $newRows[$entityId]['tier'][$compare] = $row;
            }
        }

        foreach($newRows as $entityId => $newRow) {
            if ($entityId) {
                if (!empty($newRow['group'])) {
                    $this->setProductGroupPrice($entityId, $newRow['group']);
                }

                if (!empty($newRow['tier'])) {
                    $this->setProductTierPrice($entityId, $newRow['tier']);
                }
            }
        }
    }

    public function importMediaGallery($rows)
    {
        // through SKU check this product weather exist
        foreach ($rows as $row) {
            $currentSku  =$row['sku'];
            if (empty($currentSku)) {
                continue;
            }

            // get product entity id
            $entityId = $this->getEntityIdBySku($currentSku);

            // if product exist start import media
            if ($entityId) {
                $this->setMediaGallery($entityId, $row);
            }
        }
    }

    public function setMediaGallery($entityId, $row)
    {

        if (!$entityId) {
            $currentSku  = $row['sku'];
            if (empty($currentSku)) {
                return;
            }
            // get product entity id
            $entityId = $this->getEntityIdBySku($currentSku);
        }

        // insert media entity
        $sql = "INSERT INTO `catalog_product_entity_media_gallery` (attribute_id, entity_id, value) VALUES(:attribute_id, :entity_id, :value)";
        $sth = $this->_pdo->prepare($sql);
        $band = array(
            ':attribute_id' => $row['attribute_id'],
            ':entity_id' => $entityId,
            ':value' => $row['path']
        );
        $sth->execute($band);

        $valueId = $this->_pdo->lastInsertId();
        if ($valueId) {
            $sql = "INSERT INTO `catalog_product_entity_media_gallery_value` (value_id, store_id, label, position, disabled) VALUES(:value_id, :store_id, :label, :position, :disabled)";
            $sth = $this->_pdo->prepare($sql);
            $band = array(
                ":value_id" => $valueId,
                ":store_id" => self::DEFAULT_STORE,
                ":label" => $row['name'],
                ":position" => $row['position'],
                ":disabled" => 0
            );
            $sth->execute($band);

            // if is main image
            if ($row['main']) {
                $sql = "INSERT INTO `catalog_product_entity_varchar` (entity_type_id, attribute_id, store_id, entity_id, value) VALUES(:entity_type_id, :attribute_id, :store_id, :entity_id, :value)";
                $sth = $this->_pdo->prepare($sql);
                // image, small_image, thumbnail
                $attributeIds = array(85, 86, 87);
                foreach ($attributeIds as $attributeId) {
                    $band = array(
                        ':entity_type_id' => self::ATTR_ENTITY_TYPE_ID,
                        ':attribute_id' => $attributeId,
                        ':store_id' => self::DEFAULT_STORE,
                        ':entity_id' => $entityId,
                        ':value' => $row['path']
                    );
                    $sth->execute($band);
                }
            }
        }
    }

    public function insertProductFlatData($entityId, $row)
    {
        $row['entity_id'] = $entityId;

        // Note:
        // store 0 not need flat table, flat table suffix start from 1
        // eg: catalog_product_flat_1
        if (isset($row['store_id'])) {
            $storeId = $row['store_id'];
            if ($storeId) {
                $flatTable = sprintf('%s_%d', $this->_flatPrefix, $storeId);
                if ($this->existTable($flatTable)) {
                    $fields = $this->getTableFields($flatTable);
                    // get intersection
                    $fields = array_intersect($fields, array_keys($row));
                    // set insert data
                    $insertData = [];
                    foreach ($fields as $field) {
                        $insertData[':'.$field] = $row[$field];
                    }
                    if (!empty($fields)) {
                        // add special field
                        // @TODO need get url rewrite
                        $fields[] = 'url_path';
                        $insertData[':url_path'] = strtolower($row['url_key']) . '.html';

                        // insert to flat table
                        $sql = "INSERT IGNORE INTO `%s` (%s) VALUES(%s)";
                        $sql = sprintf($sql, $flatTable, implode(',', $fields), implode(',', array_keys($insertData)));
                        $sth = $this->_pdo->prepare($sql);
                        $sth->execute($insertData);
                    }
                }
            }
        }
    }

    public function existTable($table)
    {
        if (!isset($this->_cache[$table])) {
            $sql = "SHOW TABLES LIKE :table";
            $sth = $this->_pdo->prepare($sql);
            $sth->execute([':table' => $table]);
            $this->_cache[$table] = $sth->rowCount();
        }
        return $this->_cache[$table];
    }

    public function getTableFields($table)
    {
        if (!isset($this->_cache[$table . '_fields'])) {
            $fields = [];

            $sql = "SHOW COLUMNS FROM %s";
            $sth = $this->_pdo->prepare(sprintf($sql, $table));
            $sth->execute();
            $columns  = $sth->fetchAll(PDO::FETCH_NUM);
            if (!empty($columns)) {
                foreach ($columns as $field) {
                    $fields[] = $field[0];
                }
            }
            $this->_cache[$table . '_fields'] = $fields;
        }
        return $this->_cache[$table . '_fields'];
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
        date_default_timezone_set("PRC");
        $row['created_at'] = date('Y-m-d H:i:s', time());
        // $row['updated_at'] = '';
//        $row['visibility'] = 4;
        return $row;
    }

    public function setAddLanguage($isAddNewLanguage=false)
    {
        $this->_addLanguage = $isAddNewLanguage;
        return $this;
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
/**
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
'visibility' => 3,
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
'group_price' => 60
),
1 => array(
'sku' => $rows[2]['sku'],
// 'tier_price' => 66,
'qty' => 5,
'group_id' => 0,
'group_price' => 67
)
);
$mediaGallery = array(
0 => array(
'sku' => 'config-1498032157',
'name' => 'Image File Name 1' . time(),
'path' => '/uploads/products/Shengyuan/SY-A02/21_8_102_SY-A02-1_5948b3849818d_116.jpg',
'position' => 0,
'main' => 1,
'attribute_id' => 88
),
1 => array(
'sku' =>'config-1498032157',
'name' => 'Image File Name 2' . time(),
'path' => '/uploads/products/Shengyuan/SY-A02/21_8_102_SY-A02-1_5948b3849818d_117.jpg',
'position' => 1,
'main' => 0,
'attribute_id' => 88
),
2 => array(
'sku' => 'config-1498032157',
'name' => 'Image File Name 3' . time(),
'path' => '/uploads/products/Shengyuan/SY-A02/21_8_102_SY-A02-1_5948b3849818d_118.jpg',
'position' => 2,
'main' => 0,
'attribute_id' => 88
),
);
$obj->addProductRecord($rows);
//$obj->importGroupAndTierPrice($tirePrice);
//$obj->importMediaGallery($mediaGallery);
//*/
/**
$configSku = uniqid('test_');
$productTmp = array (
    0 =>
        array (
            'sku' => $configSku,
            'type_id' => 'configurable',
            'vendor_id' => 9,
            'attribute_set_id' => 15,
            'name' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'price' => '188.00 - 269.00',
            'special_price' => '188.00',
            'short_description' => '',
            'description' => '&nbsp;',
            'detail_info' => '&nbsp;',
            'min_sale_qty' => 0,
            'qty' => 99,
            'weight' => 0.10000000000000001,
            'in_stock' => 1,
            'ak_certification' => 0,
            'status' => 1,
            'visibility' => 4,
            'url_key' => '0566359563692',
            'tax_class_id' => 0,
            'config_attr' =>
                array (
                    0 => 'custom_option_0',
                    1 => 'custom_option_1',
                ),
            'super_attr_mapping' => '{"custom_option_0":"20509","custom_option_1":"1627207"}',
            'grasp_type' => 0,
            'grasp_num_id' => '566359563692',
        ),
    1 =>
        array (
            'sku' => $configSku . '_child',
            'type_id' => 'simple',
            'vendor_id' => 9,
            'attribute_set_id' => 15,
            'name' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'price' => 349.69999999999999,
            'special_price' => '',
            'short_description' => '&nbsp;',
            'description' => '&nbsp;',
            'detail_info' => '&nbsp;',
            'min_sale_qty' => NULL,
            'qty' => '2',
            'weight' => 0.10000000000000001,
            'in_stock' => 1,
            'status' => 1,
            'visibility' => 1,
            'url_key' => '03599033985201',
            'tax_class_id' => 0,
            'config_sku' => $configSku,
            'grasp_type' => 0,
            'grasp_num_id' => '566359563692',
            'custom_option_0_code' => '20509',
            'custom_option_0' => '938381723',
            'custom_option_1_code' => '1627207',
            'custom_option_1' => '918495117',
        ),
);
$obj = new ImportProduct();
$obj->addProductRecord($productTmp);
// add other language we need add field store_id
$productTmp = array (
    0 =>
        array (
            'sku' => $configSku,
            'type_id' => 'configurable',
            'store_id' => 4,
            'vendor_id' => 9,
            'attribute_set_id' => 15,
            'name' => 'Лин Shanshan 2018 оны хавар, зуны шидэт номын хавтасны цамц хэвлэх сур Siamese Flounced өмд хувцас',
            'price' => '188.00 - 269.00',
            'special_price' => '188.00',
            'short_description' => '',
            'description' => '&nbsp;',
            'detail_info' => '&nbsp;',
            'min_sale_qty' => 0,
            'qty' => 99,
            'weight' => 0.10000000000000001,
            'in_stock' => 1,
            'ak_certification' => 0,
            'status' => 1,
            'visibility' => 4,
            'url_key' => '0566359563692',
            'tax_class_id' => 0,
            'config_attr' =>
                array (
                    0 => 'custom_option_0',
                    1 => 'custom_option_1',
                ),
            'super_attr_mapping' => '{"custom_option_0":"20509","custom_option_1":"1627207"}',
            'grasp_type' => 0,
            'grasp_num_id' => '566359563692',
        ),
    1 =>
        array (
            'sku' => $configSku . '_child',
            'store_id' => 4,
            'type_id' => 'simple',
            'vendor_id' => 9,
            'attribute_set_id' => 15,
            'name' => 'Лин Shanshan 2018 оны хавар, зуны шидэт номын хавтасны цамц хэвлэх сур Siamese Flounced өмд хувцас',
            'price' => 349.69999999999999,
            'special_price' => '',
            'short_description' => '&nbsp;',
            'description' => '&nbsp;',
            'detail_info' => '&nbsp;',
            'min_sale_qty' => NULL,
            'qty' => '2',
            'weight' => 0.10000000000000001,
            'in_stock' => 1,
            'status' => 1,
            'visibility' => 1,
            'url_key' => '03599033985201',
            'tax_class_id' => 0,
            'config_sku' => $configSku,
            'grasp_type' => 0,
            'grasp_num_id' => '566359563692',
            'custom_option_0_code' => '20509',
            'custom_option_0' => '938381723',
            'custom_option_1_code' => '1627207',
            'custom_option_1' => '918495117',
        ),
);
$obj->setAddLanguage(true);
$obj->addProductRecord($productTmp);
//*/
/**
// Import product data to flat table, Note: eneity_id is required
$obj = new ImportProduct();
$row =         array (
    'sku' => '9999999',
    'store_id' => 1,
    'type_id' => 'simple',
    'vendor_id' => 9,
    'attribute_set_id' => 15,
    'name' => 'Лин Shanshan 2018 оны хавар, зуны шидэт номын хавтасны цамц хэвлэх сур Siamese Flounced өмд хувцас',
    'price' => 349.69999999999999,
    'special_price' => '',
    'short_description' => '&nbsp;',
    'description' => '&nbsp;',
    'detail_info' => '&nbsp;',
    'min_sale_qty' => NULL,
    'qty' => '2',
    'weight' => 0.10000000000000001,
    'in_stock' => 1,
    'status' => 1,
    'visibility' => 1,
    'url_key' => '03599033985201',
    'tax_class_id' => 0,
    'config_sku' => '999999',
    'grasp_type' => 0,
    'grasp_num_id' => '566359563692',
    'custom_option_0_code' => '20509',
    'custom_option_0' => '938381723',
    'custom_option_1_code' => '1627207',
    'custom_option_1' => '918495117',
);
$entityId = 591;
$obj->insertProductFlatData($entityId, $row);
echo PHP_EOL;
//*/