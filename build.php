#!/usr/bin/env php
<?php

// ~~~~~~~~~~~~~~~~ build phar ~~~~~~~~~~~~~~~~~~~~~~~ //

$phar = new Phar('mongo-migrate.phar');
$phar->buildFromDirectory(__DIR__);
$phar->setDefaultStub('index.php');
