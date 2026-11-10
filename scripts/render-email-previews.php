<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$exitCode = Artisan::call('emails:preview');
fwrite(STDOUT, Artisan::output());

exit($exitCode);
