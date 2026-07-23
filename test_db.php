<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
print_r(DB::select("select meta from orders where meta like '%{%' limit 5"));
