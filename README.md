# Laravel TokenGuard Authentication Bypass — Local Reproduction

This project demonstrates and explains the authentication bypass vulnerability in Laravel's legacy `Illuminate\Auth\TokenGuard`, tracked as [**GHSA-f9x3-7vq9-cph6**](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6).

It uses a Laravel 8.5-era application to illustrate why a non-string API token can reach an Eloquent database query, and how the `hash` configuration affects the observed behavior.

> [!WARNING]
> **For local security research and education only. Do not deploy this project to production or expose it to the public internet.** Its legacy authentication configuration is intentionally insecure. Use only disposable test data and systems you own or are authorized to test.

## Scope and prerequisites

This demonstration focuses on the combination of:

* Laravel's legacy `TokenGuard` (`driver => 'token'`);
* API routes protected by `auth:api`;
* An Eloquent user provider;
* MySQL or MariaDB's implicit conversion between strings and numbers; and
* The value of `guards.api.hash` in `config/auth.php`.

**Using Laravel 8 does not, by itself, make an application vulnerable.** Laravel 8 already supported Sanctum. The relevant factors are the authentication guard actually in use, its configuration, the database, and whether the affected endpoints are reachable.

## Root cause

Older versions of `TokenGuard::user()` did not verify that the supplied API token was a string:

```php
$token = $this->getTokenForRequest();

if (! empty($token)) {
    $user = $this->provider->retrieveByCredentials([
        $this->storageKey => $this->hash ? hash('sha256', $token) : $token,
    ]);
}
```

When `hash => false`, an array-valued token reaches `EloquentUserProvider::retrieveByCredentials()`. That provider interprets array credential values as `whereIn()` conditions. Combined with MySQL/MariaDB's implicit type conversion, a query can match a user even though the caller has not supplied a valid API token.

This is an **authentication bypass**, not theft or recovery of an existing token. Which user, if any, matches depends on the database and stored values; the behavior does not establish reliable selection of an arbitrary account.

### Observed results in the local test

The same Laravel 8.5 test application received a non-string `api_token` in a JSON request to `GET /api/user`:

|`guards.api.hash`|Observed response|Explanation|
|-|-|-|
|`true`|HTTP 500|`hash('sha256', $token)` received an array and failed before querying the database.|
|`false`|HTTP 200|The array reached `whereIn()`, and the database returned a user without a valid token.|

These are **observations from one local environment**, not guaranteed outcomes for every database or dataset.

**Important:** `hash => true` is *not* a complete fix. The exception is an incidental barrier in the normal `user()` path, and older `TokenGuard::validate()` implementations also lack the necessary type check. A proper patch validates the input type explicitly.

## How far back this goes, and how little of it is patched

The vulnerable code is not new. The branch inside `EloquentUserProvider::retrieveByCredentials()` that turns an array-valued credential into a `whereIn()` condition was introduced in **Laravel 5.6** and has been present, unchanged, through every release since — including the current `13.x` line, where the general-purpose `whereIn()` behavior itself was intentionally kept; only `TokenGuard`'s failure to reject non-string input was fixed.

Verified locally, including in the sibling `laravel-5.6` branch of this repository:

|Laravel version|Result when an array-valued `api_token` is submitted|
|-|-|
|5.5.28 (`composer create-project laravel/laravel:5.5.*`, checked ad hoc — not kept as a branch)|`EloquentUserProvider` has no array branch; a plain `where()` call receives the array and the query fails with `SQLSTATE[HY000]: ... column index out of range`. **Not exploitable.**|
|5.6.40 (`laravel-5.6` branch)|Array reaches `whereIn()`; authenticates without a valid token.|
|8.5.0 (`laravel-8.5` branch / `main`)|Same as 5.6.40.|

**5.6 is the first affected version.** Everything from 5.6 onward carries the same `whereIn()` branch; 5.5 and earlier do not.

Laravel's support policy provides bug fixes for 18 months and security fixes for 2 years, counted from each major version's release, and normally only the current major receives bug fixes at all. In practice, this issue has an official fix in exactly three release lines:

|Version|Security fixes until|Fixed in|
|-|-|-|
|11|2026-03-12 (already ended)|11.56.1|
|12|2027-02-24|12.68.0|
|13|2028-03-17 (current)|13.29.0|

Every other line that ever shipped the vulnerable branch — **5.6 through 5.8, and all of 6, 7, 8, 9, and 10** — is permanently end-of-life, spanning releases from roughly 2018 through early 2023. None of them will ever receive this fix, or any future security fix, no matter how severe; the only way to close this issue on those lines is the backport in [Remediation](#remediation), or a major-version upgrade. Of all the release lines that ever carried this bug, the ones that got an official fix (11, 12, 13) are the minority.

## Check whether your application is affected

Start by reviewing your registered routes:

```bash
php artisan route:list
```

If you find routes using `auth:api`, inspect `guards.api` in `config/auth.php`:

```php
'api' => [
    'driver' => 'token',
    'provider' => 'users',
    'hash' => false,
],
```

For older TokenGuard implementations, **omitting the `hash` option also means `false`**.

The presence of `auth:api` is a reason to investigate, **not proof of exposure**: the `api` guard might use a different driver, such as Sanctum. Conversely, TokenGuard may be registered under another guard name. An `/api/` URL prefix alone does not establish vulnerability.

Prioritize review when a reachable API uses legacy TokenGuard with `hash => false`, an Eloquent provider, and MySQL/MariaDB. Also inspect custom guards that copy or override TokenGuard methods.

## Remediation

### Supported Laravel branches

Update to at least the following releases for this TokenGuard issue:

|Laravel version|Fixed release|
|-|-|
|11|**11.56.1**|
|12|**12.68.0**|
|13|**13.29.0**|

Upgrade the framework and its dependencies as appropriate for your application, and run the full test suite.

### Unsupported Laravel versions

If an immediate upgrade is not possible, backport the two input-type checks from the upstream fix into `Illuminate\Auth\TokenGuard`.

**In `user()`:**

```diff
- if (! empty($token)) {
+ if (is_string($token) && ! empty($token)) {
```

**In `validate()`:**

```diff
- if (empty($credentials[$this->inputKey])) {
+ if (! is_string($credentials[$this->inputKey] ?? null) || empty($credentials[$this->inputKey])) {
```

Apply **both** changes. There is no need to switch token storage from hashed to plaintext to fix this issue.

Avoid undocumented, one-off edits inside `vendor/`: Composer can overwrite them. Maintain a reproducible patch or an appropriately registered custom guard, and verify the fix with regression tests. If your application overrides `TokenGuard::user()` or `validate()`, inspect that code separately; updating the framework may not repair copied implementations.

This backport addresses **this specific authentication bypass only**. It does not restore security support to an end-of-life Laravel release or address other known vulnerabilities.

## Verification checklist

After applying the fix, confirm that:

* A valid token still authenticates successfully.
* A request with no token receives HTTP 401 on a protected route.
* A non-string token is rejected with HTTP 401 rather than authenticating a user or producing HTTP 500.
* The invalid token never reaches the user provider's database lookup.
* Any custom authentication paths that call `TokenGuard::validate()` are covered by tests.

Keep all reproduction tests isolated, with dummy accounts and a database that contains no real user information.

## References

* [GitHub Security Advisory — GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
* [Laravel 8.5.0 `TokenGuard.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/TokenGuard.php)
* [Laravel 8.5.0 `EloquentUserProvider.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/EloquentUserProvider.php)
* [Laravel 11.56.1 `TokenGuard.php` (patched)](https://github.com/laravel/framework/blob/v11.56.1/src/Illuminate/Auth/TokenGuard.php)
* [Laravel support policy](https://github.com/laravel/docs/blob/13.x/releases.md#support-policy)
* `laravel-5.6` branch of this repository — same reproduction on a fresh Laravel 5.6.40 install (affected)
* `laravel-8.5` branch of this repository — snapshot of this branch's Laravel 8.5 state
