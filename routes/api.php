<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__.'/api_v1_auth.php';
    require __DIR__.'/api_v1_super_admin.php';
    require __DIR__.'/api_v1_chain.php';
    require __DIR__.'/api_v1_property.php';
});
