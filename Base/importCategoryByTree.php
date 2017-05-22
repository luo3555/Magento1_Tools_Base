<?php
/**
 * 
 */
// tree.csv
// lavel,store_id,name,url
// 1,4,测试 1,
// 1.1,4,测试 2,
// 1.1.1,4,测试 3,
// 1.2,4,测试 4,
// 1.2.1,4,测试 5,
// 2,4,测试 6,
// 2.1,4,测试 7,

// Category tree
// 
// $array = array(
//     '1' => '1',
//     '1.1' => '1.1',
//     '1.1.1' => '1.1.1',
//     '1.1.1.1' => '1.1.1.1',
//     '1.1.1.1.1' => '1.1.1.1.1',
//     '1.1.1.1.1.1' => '1.1.1.1.1.1',
//     '1.1.1.1.1.1.1' => '1.1.1.1.1.1',
//     '2' => '2',
//     '2.1' => '2.1',
//     '2.1.1' => '2.1.1',
//     '2.1.1.1' => '2.1.1.1',
//     '2.1.2' => '2.1.2',
//     '2.1.2.1' => '2.1.2.1',
//     '2.1.2.1.1' => '2.1.2.1.1.1',
// );
require 'abstract.php';
require 'DataSource.php';

class Mage_Shell_Test extends Mage_Shell_Abstract
{
    const DEFAULT_CATEGORY = 2;

    const IMPORT_CSV_FILE = 'categoryTree.csv';

    private $_delete = false;

    private $_minCid = 55;

    protected $tree;

    protected $list;

    protected $_categories;

    protected $_csv;

    public function run()
    {
        $this->_csv = new File_CSV_DataSource();
        $this->_csv->load(self::IMPORT_CSV_FILE);

        if ($this->_delete) {
            // delete test data
            $this->deleteTestData($this->_minCid);
        } else {
            $this->listToTree($this->_csv->connect());
            $this->createCategory($this->getCategories());
        }

        echo PHP_EOL . 'FINISH' . PHP_EOL;
    }

    public function createCategory($categories, $parentId=null)
    {
        foreach ($categories as $lavel => $item) {
            // get default parentId
            $parentId = is_null($parentId) ? self::DEFAULT_CATEGORY : $parentId;

            // create category start
            $_emptyCategory = array(
                    'name' => $item->attr['name'],
                    'meta_title' => $this->defautlValue($item, 'meta_title', $item->attr['name']),
                    'meta_description' => $this->defautlValue($item, 'meta_description', $item->attr['name']),
                    'is_active' => $this->defautlValue($item, 'is_active', 1),
                    'url_key' => $this->defautlValue($item, 'url_key', time() . uniqid()),
                    'image' => $this->defautlValue($item, 'image', null),
                    'display_mode' => $this->defautlValue($item, 'display_mode', 'PRODUCTS_AND_PAGE'),
                    'is_anchor' => $this->defautlValue($item, 'is_anchor', 1),
                    'parent' => $parentId,
                );
            $catMod = Mage::getModel('catalog/category');

            $parent = $catMod->getCollection()->addFieldToFilter('entity_id', $parentId)->getFirstItem();
            // category set parent path
            $_emptyCategory['path'] = $parent->getPath();
            $_emptyCategory['attribute_set_id'] = $catMod->getResource()->getEntityType()->getDefaultAttributeSetId();

            try {
                $catMod->addData($_emptyCategory)->save();
                $item->attr['entity_id'] = $catMod->getEntityId();
                // $parentId = $this->createCategory($item->attr);
                if (!empty($item->data) && $item->attr['entity_id']) {
                    echo 'Create Success:' . $catMod->getName() . PHP_EOL;
                    $this->createCategory($item->data, $item->attr['entity_id']);
                } elseif($item->attr['entity_id']) {
                    echo 'Create Success:' . $catMod->getName() . PHP_EOL;
                }
            } catch (Exception $e) {
                echo $e->getMessage();
            }


            // create category end
        }
    }

    protected function defautlValue($category, $field, $value)
    {
        return array_key_exists($field, $category->attr) ? $category->attr[$field] : $value;
    }

    protected function deleteTestData($minCatId)
    {
        // delete test data
        $collection = Mage::getModel('catalog/category')->getCollection()->addFieldToFilter('entity_id', array('gt' => $minCatId));
        $collection->load()->delete();
    }

    /**
     * @param  $list array
     * $list = [
     *     '1' => ['data'],
     *     '1.1' => ['data'],
     *     '1.1.1' => ['data'],
     *     '1.2' => ['data'],
     *     '1.2.1' => ['data'],
     *     '2' => ['data']
     * ];
     */
    public function listToTree($list)
    {
        if (is_array($list)) {
            if (is_null($this->tree)) {
                $this->tree = new stdClass();
            }

            foreach ($list as $key => $data) {
                $path = explode('.', $key);
                $item = implode('_', $path);

                !property_exists($this->tree, $item) ? $this->tree->$item = new stdClass() : '' ; 

                $this->tree->$item->attr = $data;
                $this->tree->$item->data = array();

                array_pop($path);
                $parent = implode('_', $path);

                if (empty($path)) {
                    if (!property_exists($this->tree, 'root')) {
                        $this->tree->root = new stdClass();
                    }
                    $this->tree->root->$item = $this->tree->$item;
                } else {
                    $this->tree->$parent->data[$item] = $this->tree->$item;
                }
            }
            if (property_exists($this->tree, 'root')) {
                $this->_categories = $this->tree->root;
            }
        }
    }

    /**
     * @param  $tree $this->tree
     */
    public function treeToList($tree)
    {
        foreach ($tree as $key => $item) {
            // 
            if (property_exists($item, 'attr')) {
                $this->list[$key]  = $item->attr;
            }

            if (property_exists($item, 'data')) {
                if (is_array($item->data)) {
                    if (!empty($item->data)) {
                        $this->treeToList($item->data);
                    }
                }
            }
        }
    }

    public function getTree()
    {
        return $this->tree;
    }

    public function getList()
    {
        return $this->list;
    }

    public function getCategories()
    {
        return $this->_categories;
    }
}
$obj = new Mage_Shell_Test();
$obj->run();