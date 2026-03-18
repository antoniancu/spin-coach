# SpinCoach — Claude Code Context

## What this app is
A local-network PWA for indoor cycling on a Bowflex C6 stationary bike.
Served from a Mac Mini (hostname: norford) on the home LAN.
Accessed on iPad and iPhone by family members. No internet required at ride time.
No sensitive personal data. No passwords. No email addresses.

## Host environment (norford)
- User: `antoniancu`
- App lives at: `/Users/antoniancu/Development/spin-coach/`
- Web root (Laravel public/): `/Users/antoniancu/Development/spin-coach/public/`
- Domain: `https://spin.norford.home` (matches the `.norford.home` pattern used by all services)
- Caddy and PHP-FPM run in Docker (OrbStack)
- PHP-FPM container name: `php-fpm`, port: `9000`
- Caddyfile at: `/Users/orfie/docker/proxy/Caddyfile` (uses import glob — see Caddy section)
- MariaDB runs in a separate Docker container (see Docker section below)

## Docker volume — symlink setup (one-time, manual)
The proxy containers mount `/Users/orfie/www` → `/srv/www`. Rather than changing
that mount, a symlink bridges your Development folder into the existing mount:

```bash
# Run once on norford
ln -s /Users/antoniancu/Development/spin-coach /Users/orfie/www/spin-coach

# Verify
ls -la /Users/orfie/www/spin-coach
# → spin-coach -> /Users/antoniancu/Development/spin-coach
```

OrbStack follows symlinks in bind mounts, so Caddy and PHP-FPM will see
`/srv/www/spin-coach/` → your Development folder transparently.
No changes needed to the proxy docker-compose. All other apps unaffected.
Do this BEFORE running `php artisan` commands or testing the site.

## Caddy site config (manual step — not done by Claude Code)
The proxy Caddyfile uses an import glob. New sites are added by dropping a `.caddy`
file into the caddy sites folder — no editing the main Caddyfile.

The site config is in `deploy/spin.caddy`. To deploy:
```bash
# Copy site config into the caddy sites folder
cp blueprints/deploy/spin.caddy /Users/orfie/docker/proxy/config/caddy/spin.caddy

# Reload Caddy
docker exec caddy caddy reload --config /etc/caddy/Caddyfile
```

If the import glob is not yet set up in the main Caddyfile, add this line
after the global options block (`{ local_certs }`), before the first site:
```
import /config/caddy/*.caddy
```
Then reload. After that, the glob is permanent — no further Caddyfile edits needed.

## Stack
- Laravel 11, PHP 8.3+, `declare(strict_types=1)` in every PHP file
- Database: MariaDB 11.2 in Docker (see below)
- Frontend: Blade templates + vanilla JS (no npm build pipeline, no bundler)
  - JS files live in `public/js/` and loaded via `<script>` tags directly
  - CSS lives in `public/css/`
- PWA: Web App Manifest + Service Worker

## Docker — MariaDB
MariaDB runs as its own container defined in `docker-compose.yml` in the repo root.
Binds to `127.0.0.1:3306` only (not exposed to LAN).
Laravel connects via `DB_HOST=127.0.0.1`, `DB_PORT=3306`.

`docker-compose.yml` in repo root:
```yaml
services:
  db:
    image: mariadb:11.2
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MARIADB_DATABASE: ${DB_DATABASE}
      MARIADB_USER: ${DB_USERNAME}
      MARIADB_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:3306:3306"
    volumes:
      - mariadb_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  mariadb_data:
```

Start with: `docker compose up -d`
OrbStack routes host-network ports into containers, so `127.0.0.1:3306` is
reachable from inside the php-fpm container automatically.

## Sub-systems (each has a spec in blueprints/docs/)
1. Users          — simple profile selector, no passwords, session-based identity
2. Workout engine — interval timer, phase sequencing, session logging
3. Spotify        — OAuth via Authorization Code flow, server-side playback proxy
4. Street View    — virtual route engine, panorama advance driven by cadence
5. BLE bridge     — separate Node.js process in ble-bridge/, WebSocket server

## Auth model — NO PASSWORDS
This is a family home app on a trusted LAN. There is no authentication.

- `users` table has: id, name, avatar_emoji, color_hex, created_at, updated_at
- No password column. No email column. No remember tokens.
- No Laravel Breeze. No Sanctum. No Gates or Policies.
- Session key `user_id` tracks who is currently riding.
- On every request: `App\Http\Middleware\CurrentUser` checks session for `user_id`
  - If present: loads User model, binds to `$request->user()`
  - If absent: redirects to `/select-user`
- Profile picker: full-screen, large tap targets, one per family member
- "Add rider": name + pick emoji + pick color → creates user, sets session, redirects home
- "Switch rider": clears `user_id` from session, redirects to picker
- API routes protected by same CurrentUser middleware (returns 401 JSON if no session)

## Conventions
- Strict types: every `.php` file opens with `<?php declare(strict_types=1);`
- Models use typed properties; use `readonly` for immutable value objects
- Controllers are thin — business logic lives in `app/Services/`
- No Livewire. No Inertia. No Alpine. Blade + vanilla JS only.
- API routes return JSON. Web routes return Blade views.
- All API responses use this shape:
  ```json
  { "data": {}, "error": null }
  { "data": null, "error": "human-readable message" }
  ```
- Use Laravel Form Requests for validation on anything with >2 inputs
- Migrations explicit: always specify column types, nullable(), default()
- Never use `DB::statement()` raw SQL — use Schema builder or Eloquent
- Use `$fillable` (not `$guarded`) on all models
- MariaDB supports native ENUM and JSON column types — use them

## File structure
```
app/
  Http/
    Controllers/
      UserController.php       ← profile picker, create user, switch user
      WorkoutController.php    ← start/log/finish sessions
      SpotifyController.php    ← OAuth + playback proxy
      RouteController.php      ← virtual route listing + waypoints
      DashboardController.php  ← history + stats per user
    Middleware/
      CurrentUser.php          ← binds session user_id to request
  Models/
    User.php                   ← id, name, avatar_emoji, color_hex
    Workout.php                ← template: name, intensity, phases JSON
    WorkoutSession.php         ← one ride: user, workout, timing, stats
    SessionInterval.php        ← per-interval detail within a session
    VirtualRoute.php           ← route metadata + waypoints JSON
    SpotifyToken.php           ← single-row OAuth token store
  Services/
    SpotifyService.php         ← token refresh, playback control
    WorkoutEngine.php          ← phase resolution, BPM targeting
    RouteService.php           ← waypoint math, heading calculation
resources/
  views/
    layouts/app.blade.php      ← dark shell, PWA meta tags, nav
    users/select.blade.php     ← profile picker (full-screen tap targets)
    users/create.blade.php     ← add new rider form
    home.blade.php             ← intensity + duration picker
    ride.blade.php             ← full-screen workout player
    routes/index.blade.php     ← virtual route browser
    history/index.blade.php    ← session history list
    history/show.blade.php     ← single session detail
    settings.blade.php         ← Spotify connect, BLE status, prefs
public/
  js/
    workoutPlayer.js           ← RAF-based interval timer engine
    streetView.js              ← panorama route driver
    spotify.js                 ← playback UI (calls /api/spotify/*)
    bleClient.js               ← WebSocket client for BLE bridge
    audio.js                   ← Web Audio API beeps + voice cues
  css/
    app.css                    ← dark theme, CSS custom properties
  data/
    workouts.json              ← source of truth for WorkoutSeeder
    routes.json                ← source of truth for RouteSeeder
  manifest.json
  service-worker.js
ble-bridge/                    ← standalone Node.js project (NOT Laravel)
  server.js
  bleScanner.js
  package.json
database/
  migrations/
  seeders/
    DatabaseSeeder.php
    WorkoutSeeder.php          ← reads public/data/workouts.json
    RouteSeeder.php            ← reads public/data/routes.json
docker-compose.yml             ← MariaDB only (in blueprints/)
blueprints/
  docs/
    api.md
    database.md
    workouts.md
    spotify.md
    streetview.md
    ble-bridge.md
  deploy/
    spin.caddy                 ← drop into /Users/orfie/docker/proxy/config/caddy/
  workouts.json                ← seeder source of truth
  routes.json                  ← seeder source of truth
```

## Key architectural constraints
- The **BLE bridge** (`ble-bridge/`) runs directly on macOS — NOT in Docker.
  OrbStack cannot pass through the Mac's Bluetooth adapter to containers.
  Run it with: `node ble-bridge/server.js`
  The browser JS connects to `ws://norford.local:8765` directly.
  Laravel never touches Bluetooth.
- **Spotify tokens never reach the browser.** All Spotify API calls proxied
  through Laravel. JS only calls `/api/spotify/*`.
- **Google Maps / Street View** is entirely client-side. Laravel serves
  waypoint lat/lng from DB; browser JS calls Maps API directly.
  API key injected into Blade via `config('services.google.maps_key')`.
  Never hardcode it.
- **One Laravel app, two route groups.** `routes/web.php` for Blade views,
  `routes/api.php` for JSON endpoints.
- **MariaDB specific**: use native `->enum()` and `->json()` column types in
  migrations.

## Environment variables
```
APP_NAME=SpinCoach
APP_ENV=local
APP_URL=https://spin.norford.home

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=spincoach
DB_USERNAME=spincoach
DB_PASSWORD=           ← set a local password
DB_ROOT_PASSWORD=      ← set a root password

SPOTIFY_CLIENT_ID=
SPOTIFY_CLIENT_SECRET=
SPOTIFY_REDIRECT_URI=https://spin.norford.home/spotify/callback

GOOGLE_MAPS_KEY=
BLE_BRIDGE_WS_URL=ws://norford.local:8765

SESSION_DRIVER=database
SESSION_LIFETIME=10080
```

## First-run checklist (for Claude Code to follow in order)
1. `docker compose up -d` — start MariaDB
2. `cp .env.example .env` and fill in DB credentials
3. `php artisan key:generate`
4. `php artisan migrate`
5. `php artisan db:seed`
6. Copy `blueprints/deploy/spin.caddy` to `/Users/orfie/docker/proxy/config/caddy/spin.caddy`
7. `docker exec caddy caddy reload --config /etc/caddy/Caddyfile`
8. Visit `https://spin.norford.home/select-user` — should show empty picker
9. Create first rider

## What NOT to do
- Do not install Livewire, Inertia, Vue, React, or Alpine.js
- Do not add Breeze, Sanctum, Fortify, or Jetstream
- Do not create password, email, or remember_token columns on users
- Do not add an npm/vite build step
- Do not put Spotify access tokens in JS variables or Blade templates
- Do not use `Auth::` facade — use CurrentUser middleware + `$request->user()`
- Do not seed fake workout sessions — seeders are for templates and routes only
- Do not run MariaDB directly on the Mac — always via Docker
- Do not expose MariaDB port beyond 127.0.0.1

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan Commands

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`, `php artisan tinker --execute "..."`).
- Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Debugging

- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.
- To execute PHP code for debugging, run `php artisan tinker --execute "your code here"` directly.
- To read configuration values, read the config files directly or run `php artisan config:show [key]`.
- To inspect routes, run `php artisan route:list` directly.
- To check environment variables, read the `.env` file directly.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
