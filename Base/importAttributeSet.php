<?php
/**
 * @authors daniel (25713438@qq.com)
 * @date    17-5-23 下午4:20
 * @version 0.1.0
 */
require 'abstract.php';

class Mage_Shell_AddAttributeSet extends Mage_Shell_Abstract
{
    protected $_adapter = null;

    public function run()
    {
        $attrSets = array(
            '衣服', '裤子', '鞋子', '帽子', '内衣', '包', '饰品',
            '美状', '户外', '玩具', '家居', '家电', '办公', '健康', '五金'
        );
        $attributeSetName = 'Default';
        $attrSetApi = $this->_getAttributeSetApi();
        foreach ($attrSets as $_name) {
            $attrSetId = $this->getAttributeSetId($attributeSetName, 4);
            if (!$this->getAttributeSetId($_name, 4)) {
                $newId = $attrSetApi->create($_name, $attrSetId);
                if ($newId) {
                    echo sprintf('Create Success: %s', $_name) . PHP_EOL;
                }
            }
        }
        echo PHP_EOL . 'FINISH' . PHP_EOL;
    }

    protected function getAttributeSetId($name, $type)
    {
        $band = array(
            ':attribute_set_name' => $name,
            ':entity_type_id' => $type
        );
        $query = $this->getAdapter()->select()->from('eav_attribute_set')->where('attribute_set_name=:attribute_set_name AND entity_type_id=:entity_type_id');
        return $this->getAdapter()->fetchOne($query, $band);
    }

    protected function _getAttributeSetApi()
    {
        return Mage::getModel('catalog/product_attribute_set_api');
    }

    protected function getAdapter()
    {
        if (is_null($this->_adapter)) {
            $this->_adapter = $adapter = Mage::getModel('core/store')->getResource()->getReadConnection();
        }
        return $this->_adapter;
    }
}
$obj = new Mage_Shell_AddAttributeSet();
$obj->run();