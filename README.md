# Laravel TokenGuard Authentication Bypass (GHSA-f9x3-7vq9-cph6)

[日本語](README.ja.md) · [Detailed analysis](docs/analysis.md)

**An attacker without a valid token may be able to bypass API authentication and retrieve another user’s information.** This issue affects Laravel's legacy token authentication guard, `Illuminate\Auth\TokenGuard` ([GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)).

Start by checking your app's `config/auth.php`. **If a guard uses `'driver' => 'token'` and protects your API routes, check whether your app is affected.** Authentication may be bypassed when `hash` is `false` or omitted and the app uses Eloquent with MySQL/MariaDB. See [the configuration check below](#1-are-you-affected-about-1-minute) for the conditions and what to do.

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

Why this works: `TokenGuard::user()` has no `is_string()` check, so the array `[0]` passes `! empty($token)` and reaches `EloquentUserProvider::retrieveByCredentials()`, which turns an array value into `whereIn('api_token', [0])`. MySQL/MariaDB then compares the stored string to the integer `0` by reading the string's *leading run of digits* (none here, since the hash starts with a letter) — so it matches. A request using a query string instead of a JSON body (`curl -G --data-urlencode 'api_token[]=0'`) does **not** reproduce this: query-string values are always PHP strings, which compare as exact strings, never coerced to numbers.

In a real app the attacker doesn't control the seed, but the coercion only depends on the stored hash's leading character, so `0`–`9` covers almost every case; see [`docs/analysis.md`](docs/analysis.md) for the full mechanism.

### Confirm normal authentication still works

Logging in overwrites the fixed demo token with a new random one, so do this after the bypass check above, not before:

```bash
curl -s -X POST http://localhost:8000/api/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"demo@example.com","password":"password"}'

curl -i http://localhost:8000/api/user -H 'Accept: application/json' \
  -H 'Authorization: Bearer <access_token>'
```

### `hash => true`: a crash, not a fix

Uncomment `'hash' => true,` in `config/auth.php`, run `php artisan config:clear` inside the container, and repeat the bypass request: instead of HTTP 200 you get **HTTP 500**, because `hash('sha256', $token)` rejects the array before the comparison happens. That's an incidental crash, not a rejection — see [the configuration check below](#1-are-you-affected-about-1-minute) for why `hash => true` is not a fix either. Comment it back out (and `config:clear`) to return to this repository's default, vulnerable state (`hash` omitted, treated as `false`).

### ✅ Apply the fix and confirm it works

So far, everything above ran **unpatched**. Now apply `patches/tokenguard-is-string-fix.patch` — the exact two-line backport from [Fix](#2-fix) below — and confirm the same attack no longer works.

Register `patches/tokenguard-is-string-fix.patch` under `extra.patches.laravel/framework` in `composer.json`, then reinstall the framework and clear the configuration cache:

```bash
docker compose exec -u sail laravel.test composer reinstall laravel/framework --prefer-dist --no-interaction
docker compose exec -u sail laravel.test php artisan config:clear
```

If `composer reinstall` reports "Package is not installed", run `composer install --prefer-dist --no-interaction` once first, then retry the `reinstall`. The same two commands, run again after removing the `extra.patches` entry, put the app back in its default **unpatched** state.

| | Before the fix | After the fix |
|-|-|-|
| Array sent with `hash` omitted (this repo's default, treated as `false`) | HTTP 200 (auth bypass) | **HTTP 401** (never reaches the database) |
| Array sent with `hash => true` | HTTP 500 (exception) | **HTTP 401** (rejected by the type check) |

Resend the same `{"api_token":[0]}` request under both `hash => true` and `hash => false`: both should now return HTTP 401, and the request should never reach `EloquentUserProvider`'s query.

Tear down with `docker compose down -v` (the `-v` also removes the MySQL data volume).

## 1. Are you affected? (about 1 minute)

```bash
php artisan route:list | grep 'auth:'        # routes using auth:api or similar
grep -rn "'driver' => 'token'" config/       # guards using the legacy TokenGuard
grep -rn "TokenGuard" app/ config/           # custom guards copying or overriding it
```

If a guard uses `'driver' => 'token'`, look at its `hash` option:

```php
'api' => [
    'driver' => 'token',
    'provider' => 'users',
    'hash' => false,   // or omitted: older TokenGuard treats a missing hash as false
],
```

| What you find | What to do |
|-|-|
| No `token`-driver guard (e.g. Sanctum only) | Not affected by this issue. |
| `token` driver, `hash => false` or omitted, reachable routes, Eloquent provider, MySQL/MariaDB | **Treat as vulnerable. Fix now.** |
| `token` driver, `hash => true` | Not a fix. The HTTP 500 is an incidental barrier and `validate()` still lacks the type check. **Apply the fix.** |
| Custom guard copying `TokenGuard::user()` / `validate()` | Inspect it separately. Updating the framework will not repair a copy. |

Caveats: `auth:api` alone proves nothing, since `api` may be Sanctum, and `TokenGuard` may be registered under another guard name. A grep match means "look closer", not "exploitable". Exposure also depends on the database and stored values.

**Using Laravel 8 (or upgrading to a newer major) does not by itself fix this.** `TokenGuard` was not removed or changed in 9–13; an app that already had a `token` guard carries it forward. Only the `laravel/laravel` skeleton stopped generating that guard (from 8.6.0).

### Why a clean audit is not enough

The advisory's structured version data only covers `laravel/framework` `< 13.29.0` and `< v12.68.0`, yet the vulnerable code has been present, unchanged, since 5.6. Running `composer audit` against this repository's `v5.6.40` and `v8.5.0` lockfiles reported unrelated advisories, but not this one. For Laravel 5.6–10.x, the absence of a report from `composer audit` or Dependabot does not establish that your app is unaffected.

## 2. Fix

### Supported lines: upgrade

| Laravel | Fixed in |
|-|-|
| 11 | **11.56.1** |
| 12 | **12.68.0** |
| 13 | **13.29.0** |

### End-of-life lines (5.6–10): backport both checks

5.6 through 10 will never receive an official fix. If you cannot upgrade, apply **both** changes to `Illuminate\Auth\TokenGuard`:

```diff
 // user()
- if (! empty($token)) {
+ if (is_string($token) && ! empty($token)) {
```

```diff
 // validate()
- if (empty($credentials[$this->inputKey])) {
+ if (! is_string($credentials[$this->inputKey] ?? null) || empty($credentials[$this->inputKey])) {
```

* No need to change token storage from hashed to plaintext.
* Do not hand-edit `vendor/`; Composer will overwrite it. Keep a reproducible patch (this repository does it with [`cweagans/composer-patches`](https://github.com/cweagans/composer-patches), see [patch management](docs/analysis.md#how-this-repository-manages-patches)) or a registered custom guard, and add regression tests.
* This fixes **this bypass only**. It does not restore security support to an EOL release.

## 3. Verify

* A valid token still authenticates.
* No token returns HTTP 401 on a protected route.
* A non-string token returns HTTP 401, not an authenticated user and not HTTP 500.
* The invalid token never reaches the user provider's database lookup.
* Custom code paths that call `TokenGuard::validate()` are covered by tests.

## About this repository

A Laravel 8.5 application (the last skeleton that scaffolds the `token` guard) kept for local reproduction. Branches: `main` and `laravel-8.5` (8.5.0), `laravel-5.6` (5.6.40, the first affected version).

> [!WARNING]
> **Local security research and education only. Never deploy this or expose it to the internet.** Its authentication setup is intentionally insecure. Use disposable data and systems you own or are authorized to test.
>
> **No patch is registered by default, and `guards.api.hash` is omitted by default** (treated as `false`). The app runs the unpatched `TokenGuard::user()` as-is, and the demo user's seeded token is chosen to make the bypass reproduce deterministically, so the vulnerable behavior described below is live out of the box. Registering the real fix patch is covered in the [analysis](docs/analysis.md#how-this-repository-manages-patches).

## Learn more

[**Detailed analysis**](docs/analysis.md): root cause, observed results with `hash` true/false, how far back it goes (5.5 is not affected, 5.6 is the first affected version), support-policy coverage, and the scanner gap in detail.

## References

* [GitHub Security Advisory — GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
* [Laravel 8.5.0 `TokenGuard.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/TokenGuard.php)
* [Laravel 8.5.0 `EloquentUserProvider.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/EloquentUserProvider.php)
* [Laravel 11.56.1 `TokenGuard.php` (patched)](https://github.com/laravel/framework/blob/v11.56.1/src/Illuminate/Auth/TokenGuard.php)
* [Laravel support policy](https://github.com/laravel/docs/blob/13.x/releases.md#support-policy)
