<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Aadex HMS API',
    description: <<<'TEXT'
    Multi-tenant chain hotel management API.

    **This page currently documents the Authentication API only.** Roles
    (`super_admin`, `hotel_chain_admin`, `hotel_admin`, `staff`) and the
    per-module rights taxonomy are seeded and enforced in the database — see
    the README for the model — but the Super Admin, Chain and Property
    endpoints belong to later phases and are not published here yet.

    **To authorise:** call `POST /auth/login`, copy `token` from the response,
    then click **Authorize** above and paste it as the bearer token.
    TEXT
)]
// Driven by L5_SWAGGER_CONST_HOST (config/l5-swagger.php `constants`), which
// defaults to "{APP_URL}/api/v1". It must be absolute: a relative "/api/v1"
// is resolved by Swagger UI against the origin only, so "Try it out" would
// hit http://localhost/api/v1/... and miss the /Aadex-Backend prefix when the
// app is served from a subdirectory under Apache. Set APP_URL to match how
// you actually reach the app.
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'API v1')]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum personal access token'
)]
abstract class Controller
{
    //
}
