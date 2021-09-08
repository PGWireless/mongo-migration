#!/usr/bin/env php
<?php

require './vendor/autoload.php';

if (!isset($argv[1])) {
    echo 'config file required', PHP_EOL;
    exit(1);
}

(new Startup($argv[1]))->run();

/**
 * Usage:
 * DEBUG_SECRET_KEY=secretKey  php index.php  config.json
 */
