<?php

include __DIR__ . '/MoySkladICMLParser.php';

// configure
$parser = new MoySkladICMLParser(
    'adminlogin',
    'password',
    'shopname',
    array(
        'directory' => __DIR__,
        'file' => 'test.xml',
    )
);

// generate
$parser->generateICML();
