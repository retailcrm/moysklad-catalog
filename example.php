<?php

include __DIR__ . '/MoySkladICMLParser.php';

// configure
$parser = new MoySkladICMLParser(
        
        'admin2@kh',
        'indoor',
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
