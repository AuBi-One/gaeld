<?php

// Pin the Laravel application base path so plugin autoloaders cannot
// pollute Application::inferBasePath() during the test run. Without this,
// once plugins/gaeld-ee/vendor/autoload.php registers a ClassLoader,
// inferBasePath() may resolve to the plugin directory and cause
// "Failed opening required '.../plugins/gaeld-ee/bootstrap/app.php'".
$basePath = dirname(__DIR__);
$_ENV['APP_BASE_PATH'] = $basePath;
$_SERVER['APP_BASE_PATH'] = $basePath;
putenv('APP_BASE_PATH='.$basePath);

// Refuse to run with a cached config. With bootstrap/cache/config.php present,
// Laravel ignores the environment phpunit.xml sets (DB_DATABASE=testing), so
// the suite would run against the development database and RefreshDatabase
// would drop its tables. Idea from Ideall-Engineering/Gaeld 028e6b2e.
if (file_exists($basePath.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, PHP_EOL.'  Refusing to run the tests: bootstrap/cache/config.php exists.'.PHP_EOL
        .'  A cached config overrides phpunit.xml, so the tests would use the development'.PHP_EOL
        .'  database and wipe it. Remove it first:  php artisan config:clear'.PHP_EOL.PHP_EOL);

    exit(1);
}

// The complete suite exceeds the PHP CLI timeout on slower CI runners.
set_time_limit(0);
ini_set('max_execution_time', '0');

require __DIR__.'/../vendor/autoload.php';
