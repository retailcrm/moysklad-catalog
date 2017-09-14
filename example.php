<?php

include __DIR__ . '/MoySkladICMLParser.php';

// configure
$parser = new MoySkladICMLParser(
    'login@moysklad',
    'password',
    'shopname',
    array(
        'directory' => __DIR__,
        'file' => 'test.xml',
        'archivedGoods' => false,
    )
);

// generate
$parser->generateICML();
