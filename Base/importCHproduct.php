<?php
require 'Data.php';
require 'importProduct.php';
require 'DataSource.php';

$hp = new SiteFactory_Transliteration_Helper_Data();
$import = new ImportProduct();

// init csv
$csv = new File_CSV_DataSource('product.csv');

foreach ($csv->connect() as $row) {
    $name = trim($row['name']);
    // echo mb_detect_encoding($name);
    // exit;
    // $name = mb_convert_encoding($name, "UTF-8", "GBK");
    $row['name'] = $name;
    $description  = trim($row['description']);
    $row['description'] = $description;
    $url = $hp->translate($name);
    $row['type_id'] = 'simple';
    $row['attribute_set_id'] = 22;
    $row['url_key'] = $url;
    $row['price'] = 99999;
    $row['status'] = 1;
    $row['visibility'] = 2;
    $row['tax_class_id'] = 0;
    $row['qty'] = 100;
    $row['in_stock'] = 1;
    $row['vendor_id'] = 0;
    // $row['category_id'] = 10;
    $import->addProductOneRecord($row);
    echo $url . PHP_EOL;
    // exit;
}


