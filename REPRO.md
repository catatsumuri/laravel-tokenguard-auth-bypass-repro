# Laravel 5.6 TokenGuard repro

`main` ブランチの Laravel 8.5 版（平文 `api_token` 保存）と同じ現象を、
Laravel 5.6.40 のまっさらな `composer create-project` 上で確認するためのブランチ。

## 確認結果

`vendor/laravel/framework/src/Illuminate/Auth/TokenGuard.php` に、8.x で
追加された `hash` オプション（`config/auth.php` の `api.hash`）自体が存在しない。
つまり 5.6 系では `api_token` を標準機能でハッシュ化保存する選択肢がなく、
常に平文トークンで `WHERE api_token = ?` の一致判定になる。

- `getTokenForRequest()` の挙動（Bearer / query / input / Basic認証パスワードを
  同様にトークンとして受理する）も 8.5 と同一。

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
