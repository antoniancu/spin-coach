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
