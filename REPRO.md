# Laravel 5.6 TokenGuard repro

`main` ブランチの Laravel 8.5 版（平文 `api_token` 保存）と同じ現象を、
Laravel 5.6.40 のまっさらな `composer create-project` 上で確認するためのブランチ。

対応する公式の脆弱性情報: [GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)（CVE-2026-114509）。
5.6 はこの穴が入った最初のバージョン（5.5 には無い、下記参照）。公式に直ったのは
`11.56.1` / `12.68.0` / `13.29.0` のみで、5.6 はサポート外のため今後も直らない。

## 確認結果

### 1. `hash` オプション自体が無い

`vendor/laravel/framework/src/Illuminate/Auth/TokenGuard.php` に、8.x で
追加された `hash` オプション（`config/auth.php` の `api.hash`）自体が存在しない。
つまり 5.6 系では `api_token` を標準機能でハッシュ化保存する選択肢がなく、
常に平文トークンで `WHERE api_token = ?` の一致判定になる。

- `getTokenForRequest()` の挙動（Bearer / query / input / Basic認証パスワードを
  同様にトークンとして受理する）も 8.5 と同一。

### 2. 配列を渡すと `whereIn()` 経由で複数候補を一括総当たりできる（GHSA-f9x3-7vq9-cph6）

`TokenGuard::user()` は `$token` が文字列かどうかを検証しないまま
`EloquentUserProvider::retrieveByCredentials()` に渡す。そちらは
`is_array($value) → whereIn()` という分岐を持つ（5.6 で追加、13.x でも健在）ため、
クエリパラメータを配列で送るだけで、1リクエストで複数のトークン候補を一括照合できる。

```sh
# 事前に id=1 の api_token を real_token_value にしてある前提
curl "http://localhost:8056/api/users?api_token[]=guess1&api_token[]=real_token_value&api_token[]=guess2"
# -> 200 で認証成功。本物のトークンを単独では知らなくても、候補の中に紐づけば通る。
```

注: 空文字列（`api_token[]=`）を候補に混ぜる手口は、Laravel 標準の
`ConvertEmptyStringsToNull` ミドルウェアが入力の空文字列を `NULL` に変換するため、
`IN (NULL)` は何にも一致せず効かない。効くのは「既知/推測した値を複数まとめて送る」方。

5.5 では `EloquentUserProvider` に配列分岐が無いため、同じ入力は
`SQLSTATE[HY000]: ... column index out of range` で単に失敗する（再現しない）。
**5.6 が最初に影響を受けるバージョン。**

## patches/ について

`cweagans/composer-patches` で vendor にパッチを当てている。`composer.json` の
`extra.patches` に登録しているのは **再現用のデバッグ patch だけ**:

- `patches/tokenguard-debug-dump.patch` — `TokenGuard::user()` に
  `print_r($token); exit;` を挿入し、`$token` が文字列ではなく配列で届くことを
  可視化するデモ用の patch。`composer install` し直すたびに自動で当たる。

**本物の修正パッチ（`patches/tokenguard-is-string-fix.patch`）は用意しているが、
`extra.patches` には登録していない。** このブランチの目的は脆弱性の再現なので、
デフォルトでは当てない。中身は公式修正（`12.68.0`/`13.29.0` で入った
`is_string()` チェック）そのもの:

```diff
-        if (! empty($token)) {
+        if (is_string($token) && ! empty($token)) {
             $user = $this->provider->retrieveByCredentials(...);
...
-        if (empty($credentials[$this->inputKey])) {
+        if (! is_string($credentials[$this->inputKey] ?? null) || empty($credentials[$this->inputKey])) {
             return false;
```

PHP 7.1.33 / 7.4.33 の両方で `php -l` 済み。5.6.40 自身の `AuthManager.php` にも
同じ `$array[$key] ?? $default` の形が既にあるので、構文的な互換性問題は無い
（`??` は PHP 7.0 から。5.6 の要求は `^7.1.3`）。

### fix を試す場合の当て方

デモの patch を外して、fix の patch に切り替える:

```jsonc
// composer.json の extra.patches.laravel/framework を次のように変更
{
    "extra": {
        "patches": {
            "laravel/framework": {
                "Fix: TokenGuard non-string token (GHSA-f9x3-7vq9-cph6)": "patches/tokenguard-is-string-fix.patch"
            }
        }
    }
}
```

```sh
docker run --rm -v "$PWD":/work laravel56-repro:php74 rm -rf vendor composer.lock
docker run --rm -v "$PWD":/work -w /work laravel56-repro:php74 composer install --prefer-dist --no-interaction
# ログに "Applying patches for laravel/framework" と fix patch の名前が出ることを確認
```

当てた後は、同じ `curl .../api/users?api_token[]=...` が 401 になることを確認できる
（`print_r` 自体も `is_string($token)` のガードで素通りしなくなる）。

vendor を直接書き換えるだけだと `composer install` のたびに消える（今回それで
実際にハマった経緯がある）。検証やデモを再現可能な形で残したいなら、
patch ファイル化して `extra.patches` で管理するのが安全。

## 環境構築

ホストの PHP は 5.6 系の要件（`^7.1.3`）を満たさないため、同梯の
`docker/Dockerfile`（PHP 7.4-apache + Composer 2.2 LTS）でビルドして使う。
Debian bullseye が EOL のため `archive.debian.org` にミラーを切り替えている。

```sh
docker build -t laravel56-repro:php74 -f docker/Dockerfile .
docker run --rm -v "$PWD":/work -w /work laravel56-repro:php74 composer install --prefer-dist --no-interaction
cp .env.example .env
# .env の DB_CONNECTION=sqlite, DB_DATABASE=<絶対パス>/database/database.sqlite に変更
touch database/database.sqlite
docker run --rm -v "$PWD":/work -w /work laravel56-repro:php74 php artisan key:generate
docker run --rm -v "$PWD":/work -w /work laravel56-repro:php74 php artisan migrate --force
docker run --rm -v "$PWD":/work -w /work laravel56-repro:php74 php artisan db:seed --force
docker run -d --rm -p 8056:80 -v "$PWD":/var/www/html laravel56-repro:php74 \
  sh -c 'cd /var/www/html && php artisan serve --host=0.0.0.0 --port=80'
```

デモユーザー: `demo@example.com` / `password`
