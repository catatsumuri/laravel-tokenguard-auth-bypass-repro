# Laravel TokenGuard 認証バイパス (GHSA-f9x3-7vq9-cph6)

[English](README.md) · [詳細な分析](docs/analysis.ja.md)

**有効なトークンがなくても API の認証をすり抜け、他のユーザーの情報を取得できる場合があります。** Laravel の従来のトークン認証 `Illuminate\Auth\TokenGuard` に存在する問題です（[GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)）。

まず、自分のアプリの `config/auth.php` を確認してください。**`'driver' => 'token'` を使うガードがあり、そのガードで API を保護している場合は確認が必要です。** 特に `hash` が `false` または省略され、Eloquent と MySQL/MariaDB を使っている構成では、認証をすり抜けられる可能性があります。詳しい条件と対応は[影響の確認手順](#1-自分のアプリは影響を受けるか約-1-分)にまとめています。

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

ログインもトークンも、認証情報も一切なしで、配列の `api_token` を保護されたルートに直接送ります。

```bash
curl -sS -G http://localhost:8000/api/user -H 'Accept: application/json' \
  --data-urlencode 'api_token[]=x'
```

既定ではパッチは登録されていないため、`TokenGuard::user()` はそのまま動作し、配列が `hash()` と `EloquentUserProvider` にそのまま渡されます。既定の `hash => true` では、途中で拒否されるのではなく HTTP 500 になります（下記参照）。未認証のリクエスト1発だけで、配列の入力がガードまで届いていることを確認できます。

次に、ログインしてトークンを取得します。これは、この後の通常の認証確認と、下のバイパステストで使う `api_token` をDBに保存するために必要です。

```bash
curl -s -X POST http://localhost:8000/api/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"demo@example.com","password":"password"}'
```

ログインで取得した `access_token` を使い、通常の認証を確認します（既定の `hash => true` の場合）。

```bash
curl -i http://localhost:8000/api/user -H 'Accept: application/json' \
  -H 'Authorization: Bearer <access_token>'
```

次に、数値要素を含む JSON 配列を送ります。クエリ文字列の `api_token[]=0` は文字列の配列になるため、この数値比較の再現には使いません。

```bash
curl -i -X GET http://localhost:8000/api/user \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"api_token":[0]}'
```

未修正の状態では、`hash => true` で HTTP 500 が観測されています。`config/auth.php` の `guards.api.hash` を `false` に変更し、`php artisan config:clear` をコンテナ内で実行して同じリクエストを送ると、数値比較に一致するトークンが保存されている場合は認証がバイパスされます。結果は保存値にも依存します。初期シードの `api_token` はすべて `null` なので、先にログインしてトークンを保存する必要があります。`hash` の変更だけなら Composer の再実行は不要です。

### ✅ 修正を適用して効果を確認する

ここまでは**未修正**のまま、`hash => true` で HTTP 500、`hash => false` で認証バイパス（HTTP 200）を再現しました。ここからは、`patches/tokenguard-is-string-fix.patch`（[下の「2. 修正」](#2-修正)にある、まさに2行のバックポート修正）を実際に適用し、同じ攻撃が通らなくなることを確認します。

`composer.json` の `extra.patches.laravel/framework` に `patches/tokenguard-is-string-fix.patch` を登録し、フレームワークを再インストールして設定キャッシュを消去します。

```bash
docker compose exec -u sail laravel.test composer reinstall laravel/framework --prefer-dist --no-interaction
docker compose exec -u sail laravel.test php artisan config:clear
```

| | 修正前 | 修正後 |
|-|-|-|
| `hash => true` で配列を送信 | HTTP 500（例外） | **HTTP 401**（型チェックで即拒否） |
| `hash => false` で配列を送信 | HTTP 200（認証バイパス） | **HTTP 401**（データベースに到達しない） |

同じ `{"api_token":[0]}` のリクエストを `hash => true` と `hash => false` の両方で再送し、どちらも HTTP 401 になり、`EloquentUserProvider` のクエリまで到達しないことを確認してください。ログイン処理はハッシュを保存するため、通常のトークン認証を確認する際は `hash => true` に戻して設定キャッシュを消去します。

`docker compose down -v` で停止・削除します（`-v` を付けると MySQL のデータボリュームも削除されます）。

## 1. 自分のアプリは影響を受けるか（約 1 分）

```bash
php artisan route:list | grep 'auth:'        # auth:api などを使うルート
grep -rn "'driver' => 'token'" config/       # 従来の TokenGuard を使うガード
grep -rn "TokenGuard" app/ config/           # コピー・オーバーライドした独自ガード
```

`'driver' => 'token'` のガードがあれば、`hash` オプションを確認します。

```php
'api' => [
    'driver' => 'token',
    'provider' => 'users',
    'hash' => false,   // 省略時も同じ。古い TokenGuard は hash 未指定を false として扱う
],
```

| 確認結果 | 対応 |
|-|-|
| `token` ドライバーのガードがない（Sanctum のみなど） | この問題の影響は受けません。 |
| `token` ドライバーで `hash => false` または省略、到達可能なルートがあり、Eloquent プロバイダーと MySQL/MariaDB を使用 | **脆弱とみなして、今すぐ修正してください。** |
| `token` ドライバーで `hash => true` | 修正にはなりません。HTTP 500 は偶発的な防壁にすぎず、`validate()` には型チェックがありません。**修正を適用してください。** |
| `TokenGuard::user()` / `validate()` をコピーした独自ガード | 個別に確認してください。フレームワークを更新しても、コピーは修正されません。 |

注意: `auth:api` があるだけでは何も言えません（`api` が Sanctum の場合もあり、TokenGuard が別のガード名で登録されている場合もあります）。grep に一致したことは「詳しく調べる」合図であり、「悪用可能」という意味ではありません。実際の影響は、データベースと保存された値にも依存します。

**Laravel 8 を使っている、または新しいメジャーバージョンに上げた、というだけでは解消しません。** `TokenGuard` は 9〜13 で削除も変更もされておらず、すでに `token` ガードを持つアプリケーションはそれを引き継ぎます。変わったのは、`laravel/laravel` スケルトンがそのガードを生成しなくなったこと（8.6.0 以降）だけです。

### 監査で報告されなくても確認が必要な理由

アドバイザリの構造化されたバージョン情報は `laravel/framework` の `< 13.29.0` と `< v12.68.0` のみを対象としていますが、脆弱なコードは 5.6 以降、変更されずに存在しています。このリポジトリの `v5.6.40` と `v8.5.0` のロックファイルに対して `composer audit` を実行したところ、無関係なアドバイザリは報告されましたが、この問題は報告されませんでした。Laravel 5.6〜10.x では、`composer audit` や Dependabot で報告されないことだけでは、影響がないとは判断できません。

## 2. 修正

### サポート中の系列: アップグレード

| Laravel | 修正バージョン |
|-|-|
| 11 | **11.56.1** |
| 12 | **12.68.0** |
| 13 | **13.29.0** |

### サポート終了の系列（5.6〜10）: 2 つのチェックをバックポート

5.6〜10 には公式修正が提供されることはありません。アップグレードできない場合は、`Illuminate\Auth\TokenGuard` に**両方**の変更を適用してください。

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

* トークンの保存方法をハッシュから平文に変更する必要はありません。
* `vendor/` を直接編集しないでください。Composer に上書きされます。再現可能なパッチ（このリポジトリでは [`cweagans/composer-patches`](https://github.com/cweagans/composer-patches) を使用。[パッチ管理](docs/analysis.ja.md#このリポジトリでのパッチ管理)を参照）か、登録した独自ガードで管理し、回帰テストを追加してください。
* これが対処するのは**今回の認証バイパスだけ**です。サポート終了したリリースのセキュリティサポートを復活させるものではありません。

## 3. 検証

* 有効なトークンでは引き続き認証に成功する。
* 保護されたルートにトークンなしでアクセスすると HTTP 401 が返る。
* 文字列ではないトークンは HTTP 401 で拒否される（認証されない。HTTP 500 にもならない）。
* 不正なトークンがユーザープロバイダーのデータベース検索に到達しない。
* `TokenGuard::validate()` を呼び出す独自の認証経路がテストされている。

## このリポジトリについて

ローカルで再現するための Laravel 8.5 アプリケーションです（`token` ガードを標準で生成する最後のスケルトン）。ブランチは、`main` と `laravel-8.5`（8.5.0）、`laravel-5.6`（5.6.40、最初に影響を受けるバージョン）です。

> [!WARNING]
> **ローカル環境でのセキュリティ研究・学習専用です。本番環境へのデプロイやインターネットへの公開は絶対にしないでください。** 認証設定は意図的に安全でない状態です。使い捨てのデータと、自分が所有するか、テストの許可を得たシステムだけを使用してください。
>
> **既定ではパッチは登録されていません。** 未修正の `TokenGuard::user()` がそのまま動作するため、以下で説明する脆弱な挙動は最初から有効です。実際の修正パッチを登録する方法は[分析](docs/analysis.ja.md#このリポジトリでのパッチ管理)を参照してください。

## さらに詳しく

[**詳細な分析**](docs/analysis.ja.md): 根本原因、`hash` が true/false の場合の観測結果、どこまで遡るか（5.5 は影響なし、5.6 が最初）、サポートポリシーと修正の範囲、スキャナーの空白の詳細。

## 参考資料

* [GitHub Security Advisory — GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
* [Laravel 8.5.0 の `TokenGuard.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/TokenGuard.php)
* [Laravel 8.5.0 の `EloquentUserProvider.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/EloquentUserProvider.php)
* [Laravel 11.56.1 の `TokenGuard.php`（修正済み）](https://github.com/laravel/framework/blob/v11.56.1/src/Illuminate/Auth/TokenGuard.php)
* [Laravel のサポートポリシー](https://github.com/laravel/docs/blob/13.x/releases.md#support-policy)
