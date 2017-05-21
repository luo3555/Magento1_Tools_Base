<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

$array = array(
    '1' => '1',
    '1.1' => '1.1',
    '1.1.1' => '1.1.1',
    '1.1.1.1' => '1.1.1.1',
    '1.1.1.1.1' => '1.1.1.1.1',
    '1.1.1.1.1.1' => '1.1.1.1.1.1',
    '1.1.1.1.1.1.1' => '1.1.1.1.1.1',
    '2' => '2',
    '2.1' => '2.1',
    '2.1.1' => '2.1.1',
    '2.1.1.1' => '2.1.1.1',
    '2.1.2' => '2.1.2',
    '2.1.2.1' => '2.1.2.1',
    '2.1.2.1.1' => '2.1.2.1.1.1',
);

// $tree = new stdClass();
// $tree->root = array();
// $list = array();
// foreach ($array as $key => $data) {
//     $path = explode('.', $key);
//     $el = implode('_', $path);
//     $tree->$el = new stdClass();
//     $tree->$el->attr = $data;
//     $tree->$el->data = array();
    
//     array_pop($path);
//     $parent = implode('_', $path);
    
//     if (!empty($parent)) {
//         $_child = $tree->$parent->data;
//         $_child[$el] = $tree->$el;
//         $tree->$parent->data = $_child;
//     } else {
//         $tree->root[$el] = $tree->$el;
//     }
// }

// // print_r($tree->root);


// $list = array();
// function getTreeData($tree)
// {
//     foreach ($tree as $key => $item) {
//         // 
//         if (property_exists($item, 'attr')) {
//             $list[$key]  = $item->attr;
//             echo $item->attr;
//         }

//         if (property_exists($item, 'data')) {
//             if (is_array($item->data)) {
//                 if (!empty($item->data)) {
//                     getTreeData($item->data);
//                 }
//             }
//         }
//     }
// }
// getTreeData($tree->root);



$array = array(
    '1' => '1',
    '1.1' => '1.1',
    '1.1.1' => '1.1.1',
    '1.1.1.1' => '1.1.1.1',
    '1.1.1.1.1' => '1.1.1.1.1',
    '1.1.1.1.1.1' => '1.1.1.1.1.1',
    '1.1.1.1.1.1.1' => '1.1.1.1.1.1',
    '2' => '2',
    '2.1' => '2.1',
    '2.1.1' => '2.1.1',
    '2.1.1.1' => '2.1.1.1',
    '2.1.2' => '2.1.2',
    '2.1.2.1' => '2.1.2.1',
    '2.1.2.1.1' => '2.1.2.1.1.1',
);

class Tree
{
    public $tree;

    public $list;

    public function listToTree($list)
    {
        if (is_array($list)) {
            if (is_null($this->tree)) {
                $this->tree = new stdClass();
            }

            foreach ($list as $key => $data) {
                $path = explode('.', $key);
                $item = implode('_', $path);

                is_null($this->tree->$item) ? $this->tree->$item = new stdClass() : '' ; 

                $this->tree->$item->attr = $data;
                $this->tree->$item->data = array();

                array_pop($path);
                $parent = implode('_', $path);

                if (empty($path)) {
                    if (is_null($this->tree->root)) {
                        $this->tree->root = new stdClass();
                    }
                    $this->tree->root->$item = $this->tree->$item;
                } else {
                    $this->tree->$parent->data[$item] = $this->tree->$item;
                }
            }
        }
    }

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
}

$obj = new Tree();
$obj->listToTree($array);
print_r($obj->tree->root);
$obj->treeToList($obj->tree->root);
print_r($obj->list);