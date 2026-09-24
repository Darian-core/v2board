<?php

// Standalone test bootstrap: never load .env, app providers, Redis or the live DB.
$autoload = getenv('CHECKIN_TEST_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Install Composer dependencies or set CHECKIN_TEST_AUTOLOAD to an isolated vendor/autoload.php');
}
$loader = require $autoload;
$loader->addPsr4('App\\', __DIR__ . '/../app');
