<?php

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(LoadEnvironmentVariables::class)->bootstrap($app);
$app->make(LoadConfiguration::class)->bootstrap($app);
var_export(config('database.connections.master'));
echo "\n";
echo 'Default: '.config('database.default')."\n";
