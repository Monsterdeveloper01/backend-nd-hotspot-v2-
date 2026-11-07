<?php
require 'backend/vendor/autoload.php';
$app = require_once 'backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
try {
    $tokens = DB::table('personal_access_tokens')->get(['id', 'name', 'abilities']);
    echo "Found " . $tokens->count() . " tokens\n";
    foreach ($tokens as $t) {
        echo "ID: {$t->id} | Name: {$t->name} | Abilities: {$t->abilities}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

