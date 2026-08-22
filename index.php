<?php

/*
|--------------------------------------------------------------------------
| Front controller shim (local Apache / XAMPP convenience)
|--------------------------------------------------------------------------
|
| Laravel's real front controller is public/index.php and its real document
| root is public/. This shim exists only so the project can be dropped into
| htdocs and reached at http://localhost/Aadex-Backend without configuring a
| vhost or running `php artisan serve`.
|
| Why a shim and not just an .htaccess rewrite into public/: rewriting
| /Aadex-Backend/api/... to public/api/... leaves Apache reporting
| SCRIPT_NAME as /Aadex-Backend/public/index.php while REQUEST_URI stays
| /Aadex-Backend/api/... . Symfony's base-URL detection can't reconcile the
| two, gives up, and hands Laravel the literal path "Aadex-Backend/api/..." —
| every route then 404s. Keeping the entry point at the project root makes
| SCRIPT_NAME and REQUEST_URI share the /Aadex-Backend prefix, so routing
| resolves normally.
|
| The accompanying .htaccess denies direct access to everything except this
| file and the whitelisted static files in public/.
|
| ON A REAL SERVER: point the vhost DocumentRoot at public/ and delete both
| this file and the root .htaccess. This layout puts the project root inside
| the web root, which is strictly weaker than serving public/ directly.
|
*/

require __DIR__.'/public/index.php';
