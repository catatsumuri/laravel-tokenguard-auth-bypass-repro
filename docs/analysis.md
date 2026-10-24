# Detailed analysis: Laravel TokenGuard authentication bypass

[← Back to README](../README.md) · [日本語](analysis.ja.md)

Background for [GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6): root cause, observed results, how far back it goes, and why scanners miss it. For "am I affected, and how do I fix it", start with the [README](../README.md). Findings below were verified in this repository's Laravel 8.5 and 5.6 applications. Support dates are as of 2026-10.

> [!WARNING]
> **Local security research and education only.** Do not deploy this project or expose it to the internet.

## Scope and prerequisites

This demonstration focuses on the combination of:

* Laravel's legacy `TokenGuard` (`driver => 'token'`);
* API routes protected by `auth:api`;
* An Eloquent user provider;
* MySQL or MariaDB's implicit conversion between strings and numbers; and
* The value of `guards.api.hash` in `config/auth.php`.

**Using Laravel 8 does not, by itself, make an application vulnerable.** Laravel 8 already supported Sanctum. The relevant factors are the authentication guard actually in use, its configuration, the database, and whether the affected endpoints are reachable.

### Why this demonstration targets 8.5, specifically

`v8.5.*` is the last `laravel/laravel` skeleton that scaffolds the `token`-driver `api` guard by default. Starting at `v8.6.0`, a fresh `composer create-project`/`laravel new` no longer generates it: `config/auth.php` drops the `api` guard entirely, `routes/api.php`'s default route switches to `auth:sanctum`, and `laravel/sanctum` is added to `composer.json`. 8.5 is therefore the newest version where this configuration shows up without anyone having to add it back by hand.

That skeleton change does not mean upgrading is itself a fix. **`Illuminate\Auth\TokenGuard` was not removed or changed by this** — it is still present and unchanged in `laravel/framework` today. An existing application that already had a `token`-driver `api` guard before upgrading carries that guard, and this bug, forward through 9, 10, 11, 12, and 13 untouched, for as long as `config/auth.php` keeps that guard. The clearest evidence of this: an official fix had to be shipped for 11.56.1, 12.68.0, and 13.29.0 (see [How far back this goes](#how-far-back-this-goes-and-how-little-of-it-is-patched) below). If simply moving to a newer major version closed this on its own, none of those patches would have been necessary. A naive "just bump the Laravel version" upgrade, without reviewing `config/auth.php`, leaves this open.

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

Every other line that ever shipped the vulnerable branch — **5.6 through 5.8, and all of 6, 7, 8, 9, and 10** — is permanently end-of-life, spanning releases from roughly 2018 through early 2023. None of them will ever receive this fix, or any future security fix, no matter how severe; the only way to close this issue on those lines is the backport in the [README](../README.md#2-fix), or a major-version upgrade. Of all the release lines that ever carried this bug, the ones that got an official fix (11, 12, 13) are the minority.

### Dependency scanners don't cover this gap either

This isn't only a "no patch exists" problem. The advisory's structured `vulnerable_version_range` data — the thing `composer audit`, GitHub Dependabot alerts, and similar scanners actually match against — only contains entries for `< 13.29.0` and `< v12.68.0`. Nothing in that structured data mentions 5.6 through 10.x, so tooling built on it has nothing to match an old pin against.

Confirmed directly against this repository's own lockfiles:

```sh
$ composer audit --locked   # against this branch's composer.lock, laravel/framework v5.6.40
Found 19 security vulnerability advisories affecting 8 packages:
...none of them GHSA-f9x3-7vq9-cph6...

$ composer audit --locked   # against main's composer.lock, laravel/framework v8.5.0
...same result, this CVE never appears...
```

Both lockfiles report plenty of *other*, unrelated advisories — so the local advisory database being used is current, not stale — but neither run ever surfaces GHSA-f9x3-7vq9-cph6. An application on Laravel 5.6–10.x (or an unpatched 11/12/13 point release) gets a clean `composer audit` and a quiet Dependabot tab while still being fully exploitable. Whether scanners flag unpatched 11.x (`< 11.56.1`) is not something I verified. The only reliable check is the manual one in the [README](../README.md#1-are-you-affected-about-1-minute).

## How this repository manages patches

This is a worked example of the "maintain a reproducible patch" advice in the [README](../README.md#2-fix), using [`cweagans/composer-patches`](https://github.com/cweagans/composer-patches) so edits to `vendor/` survive a fresh `composer install`:

```json
"extra": {
    "patches": {
        "laravel/framework": {
            "Demo: dump api_token before auth lookup (TokenGuard)": "patches/tokenguard-debug-dump.patch"
        }
    }
}
```

* `patches/tokenguard-debug-dump.patch` is **wired in and active by default**. It inserts a `print_r($token); exit;` marker in `TokenGuard::user()` so that submitting an array-valued `api_token` visibly dumps a PHP array instead of a string — this repository's purpose is reproduction, so the vulnerable behavior stays on by default.
* `patches/tokenguard-is-string-fix.patch` contains the real backport (the two diffs in the [README](../README.md#2-fix)) but is **deliberately not referenced from `extra.patches`**, so it never applies on its own.

To verify the real fix instead of the demo marker, swap which patch is registered, then reinstall `laravel/framework` so the plugin reapplies it:

```sh
# edit extra.patches.laravel/framework in composer.json to point at
# patches/tokenguard-is-string-fix.patch instead, then:
rm -rf vendor composer.lock
composer install --prefer-dist --no-interaction
# the install log should read:
#   Applying patches for laravel/framework
#       patches/tokenguard-is-string-fix.patch (...)
```

After switching, a request with an array-valued `api_token` should receive HTTP 401 instead of reaching the database.

## References

* [GitHub Security Advisory — GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
* [Laravel 8.5.0 `TokenGuard.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/TokenGuard.php)
* [Laravel 8.5.0 `EloquentUserProvider.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/EloquentUserProvider.php)
* [Laravel 11.56.1 `TokenGuard.php` (patched)](https://github.com/laravel/framework/blob/v11.56.1/src/Illuminate/Auth/TokenGuard.php)
* [Laravel support policy](https://github.com/laravel/docs/blob/13.x/releases.md#support-policy)
* `laravel-5.6` branch of this repository — same reproduction on a fresh Laravel 5.6.40 install (affected)
* `laravel-8.5` branch of this repository — snapshot of this branch's Laravel 8.5 state
