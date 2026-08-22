<?php

/*
|--------------------------------------------------------------------------
| Front controller shim (local Apache / XAMPP convenience)
|--------------------------------------------------------------------------
|
| Laravel's real front controller is public/index.php and its real document
| root is public/. This shim exists only so the project can be dropped into
| htdocs and reached at http://localhost/<folder> without configuring a vhost
| or running `php artisan serve`.
|
| Why a shim and not just an .htaccess rewrite into public/: rewriting
| /<folder>/api/... to public/api/... leaves Apache reporting SCRIPT_NAME as
| /<folder>/public/index.php while REQUEST_URI stays /<folder>/api/... .
| Symfony's base-URL detection can't reconcile the two, gives up, and hands
| Laravel the literal path "<folder>/api/..." — every route then 404s.
| Keeping the entry point at the project root makes SCRIPT_NAME and
| REQUEST_URI share the /<folder> prefix, so routing resolves normally.
|
| ON A REAL SERVER: point the vhost DocumentRoot at public/ and delete both
| this file and the root .htaccess. This layout puts the project root inside
| the web root, which is strictly weaker than serving public/ directly.
|
*/

/*
 * Windows filesystems are case-insensitive, so Apache happily serves
 * /aadexx-backend/... for a folder named Aadexx-Backend — but it reports
 * SCRIPT_NAME with the on-disk casing while REQUEST_URI keeps whatever the
 * visitor typed. Symfony compares the two with a case-sensitive prefix match
 * (Request::prepareBaseUrl), finds no common prefix, and falls back to an
 * empty base URL, so the folder name ends up inside the route path and every
 * route 404s. Restating SCRIPT_NAME in the casing the request actually used
 * makes the prefix match again.
 *
 * Only the base directory is touched, and only when it differs by case
 * alone — a genuinely different path is left to fail as it should.
 */
(static function (): void {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';

    $baseDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    if ($baseDir === '' || $requestUri === '') {
        return;
    }

    $path = strtok($requestUri, '?');

    if (str_starts_with($path, $baseDir)) {
        return; // Casing already matches; nothing to reconcile.
    }

    // Compare on a segment boundary, so /aadexx-backend-something can never be
    // mistaken for a differently-cased /Aadexx-Backend.
    $candidate = substr($path, 0, strlen($baseDir));
    $next = substr($path, strlen($baseDir), 1);

    if (strtolower($candidate) !== strtolower($baseDir) || ($next !== '' && $next !== '/')) {
        return;
    }

    $_SERVER['SCRIPT_NAME'] = $candidate.substr($scriptName, strlen($baseDir));
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
})();

require __DIR__.'/public/index.php';
