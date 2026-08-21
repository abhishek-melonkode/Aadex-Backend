# Aadex HMS — Backend API

Laravel 12 REST API for the Aadashi Group hotel management platform: a multi-tenant
chain-hotel aggregator covering property onboarding, rooms & rate engine, bookings and
folios, restaurant/POS, reporting and OTA integrations.

**This repository is the backend only.** No frontend code lives here — the React /
React Native apps are a separate effort built against this API. Published endpoints are
documented in Swagger and browsable at `/api/documentation`.

---

## Requirements

| | Version | Notes |
|---|---|---|
| PHP | 8.2+ | with `pdo_mysql`, `mbstring`, `gd`, `zip`, `bcmath` |
| Composer | 2.x | |
| MySQL | 8.0 / MariaDB 10.6+ | XAMPP is fine for local dev |
| Redis | optional | not required yet — cache/session/queue currently run on the `database` driver |

Node/npm are **not** needed. `package.json` and `vite.config.js` are leftovers from the
Laravel skeleton and are unused by the API.

---

## Setup

```bash
git clone <repo-url> Aadex-Backend
cd Aadex-Backend
composer install
```

Create the database (any MySQL client; from XAMPP's shell for example):

```bash
mysql -u root -e "CREATE DATABASE aadex_backend CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Then copy the env file, generate a key, migrate, seed and build the API docs — all four
steps are wrapped in one command:

```bash
composer setup
```

If your MySQL credentials differ from the defaults, edit `.env` (`DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`) **before** running `composer setup`.

<details>
<summary>What <code>composer setup</code> runs</summary>

```
php -r "file_exists('.env') || copy('.env.example', '.env');"
php artisan key:generate
php artisan migrate --seed
php artisan l5-swagger:generate
```
</details>

---

## Running it

```bash
php artisan serve
```

The API is then at **http://127.0.0.1:8000/api/v1**.

Some features need extra long-running processes. Start whichever you need:

```bash
php artisan queue:work
```

```bash
php artisan schedule:work
```

```bash
php artisan reverb:start
```

- **queue worker** — bulk email, zero-inventory alerts, booking-hold expiry, report exports
- **scheduler** — hourly zero-inventory scan, 5-minute booking-hold sweep
- **Reverb** — WebSocket broadcasting (Restaurant/KOT module, Phase 4)

Or run the API + queue + log tail together:

```bash
composer dev
```

---

## API documentation

| | |
|---|---|
| Swagger UI (interactive, "Try it out") | http://127.0.0.1:8000/api/documentation |
| Raw OpenAPI 3 JSON | http://127.0.0.1:8000/docs |
| On disk | `storage/api-docs/api-docs.json` |

> **Scope of the published docs.** Swagger currently shows the **Authentication API only**
> (10 endpoints under `/auth`). Controllers for later phases still carry their OpenAPI
> annotations, but `config/l5-swagger.php` only scans the Auth module, so they are not
> published yet. To publish a module, uncomment its line in that config's `annotations`
> array and run `composer docs`.

Regenerate the spec after changing any controller annotation:

```bash
composer docs
```

**Postman:** import either the URL `http://127.0.0.1:8000/docs` or the
`storage/api-docs/api-docs.json` file (File → Import). Postman builds the full
collection from the OpenAPI spec, so there is no hand-maintained collection to drift.

To authorise requests in Swagger UI: call `POST /auth/login`, copy the `token` from the
response, click **Authorize**, and paste it as `Bearer <token>`.

---

## Demo login credentials

`composer setup` (or `php artisan migrate:fresh --seed`) seeds one demo chain with two
hotels and one user per role. Password for all of them is `password`:

| Email | Role | Scope |
|---|---|---|
| `superadmin@aadex.test` | `super_admin` | everything, all hotels |
| `chainadmin@aadex.test` | `hotel_chain_admin` | Demo Hospitality Group (2 hotels) |
| `propertyadmin@aadex.test` | `hotel_admin` | Demo Grand Hotel |
| `frontdesk@aadex.test` | `staff` | Demo Grand Hotel, `bookings.view` + `bookings.create` only |

Demo Grand Hotel also gets 2 room types, 4 rooms and 2 rate plans — enough to exercise
the quote to booking to check-in/check-out flow immediately. Demo Seaside Resort is
deliberately left empty so it can serve as the "other tenant" fixture in isolation tests.

---

## Roles

| Role | Who it is |
|---|---|
| `super_admin` | Aadex platform operator — all hotels, all modules, billing, CRM, support |
| `hotel_chain_admin` | Owner of a hotel chain — every active property under their `chain_id` |
| `hotel_admin` | Property-level admin — one hotel, all of its modules |
| `staff` | Property staff — one hotel, only the permissions their Hotel Admin grants |
| `guest` | Placeholder. Guests are **not** `users`; they live in the `guests` table with their own guard (Guest Portal phase) |

Permissions are named `{module}.{action}` (`bookings.cancel`, `reports.export`, ...) and
the single source of truth is [`database/seeders/RolePermissionSeeder.php`](database/seeders/RolePermissionSeeder.php).
Controllers reference permissions by name via `permission:` middleware — never invent a
new permission string inline, add it to the seeder.

### Sessions and token expiry

A session is one Sanctum token — one login on one device. Tokens expire after
`SANCTUM_TOKEN_EXPIRATION` minutes (default **7 days**); `POST /auth/login` returns the
`expires_at` so a client knows when to re-authenticate. A signed-in user can see their live
devices with `GET /api/v1/auth/sessions` (device name, IP, user agent, last used), drop one
with `DELETE /api/v1/auth/sessions/{session}`, or sign out everywhere with
`POST /api/v1/auth/logout-all`. Expired token rows are pruned daily by the scheduler.

### Signup and approval

`POST /api/v1/auth/register` is a public hotel self-signup. It creates the hotel and its
Hotel Admin user with status `pending` and issues **no** token; login is refused with 403
until a Super Admin approves the account with
`POST /api/v1/super-admin/hotels/{hotel}/activate`, which flips the hotel and its pending
users to `active` in one call.

---

## Project layout

```
app/
  Domain/            business logic, grouped by bounded context - NOT by file type
    Bookings/          Actions, Models, Jobs, Enums, Exceptions, Exports
    RateEngine/        rate plans, calendars, seasons, yield rules, promotions
    Rooms/  Guests/  Payments/  SuperAdmin/  Chain/  Identity/
    Tenancy/           Hotel/HotelChain + TenantContext, BelongsToTenant, TenantScope
  Http/
    Controllers/Api/V1/   Auth - SuperAdmin - Chain - Property
    Requests/             one FormRequest per write endpoint
    Resources/            one API Resource per response shape
    Middleware/           ResolveTenant, EnsureHotelActive, EnsureHotelInChain
routes/
  api.php               mounts the v1 prefix
  api_v1_auth.php  api_v1_super_admin.php  api_v1_chain.php  api_v1_property.php
database/migrations/  database/seeders/  database/factories/
tests/Feature/  tests/Unit/
```

Controllers stay thin: validation lives in `Http/Requests`, response shaping in
`Http/Resources`, and anything multi-step (booking creation, rate resolution, tax) in a
`Domain/*/Actions` or `Domain/*/Services` class.

### Multi-tenancy in one paragraph

Every tenant-scoped table carries `hotel_id` and its model uses `BelongsToTenant`, which
registers a global scope reading the request's `TenantContext`. `TenantContext` is bound
by the `tenant` middleware from the authenticated user's role: `super_admin` bypasses,
`hotel_chain_admin` resolves to every active hotel in their chain, everyone else to their
single `hotel_id`, and a user with neither fails closed to an empty set. Controllers never
write `where('hotel_id', ...)` by hand.

**Middleware order is load-bearing here.** Laravel enforces its own priority for framework
middleware, so `SubstituteBindings` (which resolves route-model-bound params) would
otherwise run *before* the `tenant` middleware — leaving every implicit-binding route
unscoped, so a Hotel Admin could fetch another hotel's record by guessing its id even
though list endpoints filter it out correctly. `bootstrap/app.php` pins the order via
`$middleware->priority([...])`; read the comments there before touching middleware
registration, and re-run `php artisan test --filter=TenantIsolation` afterwards — those
by-id tests exist precisely to catch this regression.

---

## Tests

```bash
composer test
```

The suite runs against an in-memory SQLite database (configured in `phpunit.xml`), so it
needs no MySQL and leaves your dev data alone.

Run a single file, or filter by name:

```bash
php artisan test tests/Feature/Auth/LoginTest.php
```

```bash
php artisan test --filter=tenant
```

---

## Code style

Formatting is enforced by [Laravel Pint](https://laravel.com/docs/pint) using the rules in
`pint.json`.

```bash
composer lint
```

```bash
composer lint:fix
```

Run style and tests together — do this before every push:

```bash
composer check
```

