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

なぜ通るか：`TokenGuard::user()` には `is_string()` のチェックが無いため、配列 `[0]` は `! empty($token)` を通過して `EloquentUserProvider::retrieveByCredentials()` に渡り、そこで配列値は `whereIn('api_token', [0])` になります。MySQL/MariaDB は保存された文字列と整数 `0` を比較する際、文字列の**先頭から連続する数字部分**を読んで数値変換します（今回はハッシュの先頭が英字なので数字部分が無く、結果は `0`）。クエリ文字列で送る場合（`curl -G --data-urlencode 'api_token[]=0'`）は**これを再現しません**。クエリ文字列の値は常に PHP の文字列になり、文字列同士の完全一致比較にしかならず、数値への変換は起きないからです。

実際のアプリでは攻撃者が保存トークンの値を選べるわけではありませんが、数値変換は保存ハッシュの先頭1文字にしか依存しないため、`0`〜`9` を試せばほぼすべてのケースを網羅できます。仕組みの詳細は[`docs/analysis.ja.md`](docs/analysis.ja.md)を参照してください。

### 通常の認証が動くことも確認する

ログインすると、固定のデモトークンは新しいランダムなトークンに上書きされます。なので、この確認は上のバイパステストの**後**に行ってください：

```bash
curl -s -X POST http://localhost:8000/api/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"demo@example.com","password":"password"}'

curl -i http://localhost:8000/api/user -H 'Accept: application/json' \
  -H 'Authorization: Bearer <access_token>'
```

### `hash => true` はクラッシュするだけで修正ではない

`config/auth.php` の `'hash' => true,` のコメントを外し、`php artisan config:clear` をコンテナ内で実行してから、同じバイパスリクエストを再送してください。HTTP 200 ではなく **HTTP 500** になります。これは `hash('sha256', $token)` が比較の前に配列を拒否してクラッシュするからです。これは「正しく拒否された」のではなく「たまたま落ちた」だけです — `hash => true` が修正にならない理由は[下の影響確認手順](#1-自分のアプリは影響を受けるか約-1-分)も参照してください。再びコメントアウトして（`config:clear` も忘れずに）このリポジトリの既定の脆弱な状態（`hash` 省略 = `false` 扱い）に戻します。

### ✅ 修正を適用して効果を確認する

ここまでは**未修正**のまま実行しました。ここからは、`patches/tokenguard-is-string-fix.patch`（[下の「2. 修正」](#2-修正)にある、まさに2行のバックポート修正）を実際に適用し、同じ攻撃が通らなくなることを確認します。

`composer.json` の `extra.patches.laravel/framework` に `patches/tokenguard-is-string-fix.patch` を登録し、フレームワークを再インストールして設定キャッシュを消去します。

```bash
docker compose exec -u sail laravel.test composer reinstall laravel/framework --prefer-dist --no-interaction
docker compose exec -u sail laravel.test php artisan config:clear
```

`composer reinstall` が「Package is not installed」と報告する場合は、先に `composer install --prefer-dist --no-interaction` を一度実行してから `reinstall` をやり直してください。`extra.patches` の登録を外した上で同じ2コマンドを再実行すれば、既定の**未修正**状態に戻せます。

| | 修正前 | 修正後 |
|-|-|-|
| `hash` 省略（このリポジトリの既定、`false` 扱い）で配列を送信 | HTTP 200（認証バイパス） | **HTTP 401**（データベースに到達しない） |
| `hash => true` で配列を送信 | HTTP 500（例外） | **HTTP 401**（型チェックで即拒否） |

同じ `{"api_token":[0]}` のリクエストを `hash => true` と `hash => false` の両方で再送し、どちらも HTTP 401 になり、`EloquentUserProvider` のクエリまで到達しないことを確認してください。

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
> **既定ではパッチは登録されておらず、`guards.api.hash` も既定で省略**（`false` 扱い）**されています。** 未修正の `TokenGuard::user()` がそのまま動作し、デモユーザーのシードトークンもバイパスが毎回決定的に再現するよう選んであるため、以下で説明する脆弱な挙動は最初から有効です。実際の修正パッチを登録する方法は[分析](docs/analysis.ja.md#このリポジトリでのパッチ管理)を参照してください。

## さらに詳しく

[**詳細な分析**](docs/analysis.ja.md): 根本原因、`hash` が true/false の場合の観測結果、どこまで遡るか（5.5 は影響なし、5.6 が最初）、サポートポリシーと修正の範囲、スキャナーの空白の詳細。

## 参考資料

* [GitHub Security Advisory — GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
* [Laravel 8.5.0 の `TokenGuard.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/TokenGuard.php)
* [Laravel 8.5.0 の `EloquentUserProvider.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/EloquentUserProvider.php)
* [Laravel 11.56.1 の `TokenGuard.php`（修正済み）](https://github.com/laravel/framework/blob/v11.56.1/src/Illuminate/Auth/TokenGuard.php)
* [Laravel のサポートポリシー](https://github.com/laravel/docs/blob/13.x/releases.md#support-policy)
