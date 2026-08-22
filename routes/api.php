<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__.'/api_v1_auth.php';

    /*
     * Route files belonging to later phases. Each module ships with its own
     * file; until that phase is handed over the file simply isn't in the
     * checkout, and the API has to boot without it.
     *
     * The guard is not cosmetic: `composer install` runs
     * `artisan package:discover`, which loads the route files. An
     * unconditional require on a missing file therefore kills the very first
     * setup command, before anyone can even run the test suite.
     */
    foreach (['api_v1_super_admin', 'api_v1_chain', 'api_v1_property'] as $module) {
        if (file_exists($file = __DIR__."/{$module}.php")) {
            require $file;
        }
    }
});
