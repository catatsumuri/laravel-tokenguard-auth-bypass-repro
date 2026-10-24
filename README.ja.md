# Laravel TokenGuard 認証バイパス — ローカルでの再現

[English](README.md)

このプロジェクトは、Laravel の従来の `Illuminate\Auth\TokenGuard` に存在する認証バイパスの脆弱性 [**GHSA-f9x3-7vq9-cph6**](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6) を再現し、その仕組みを説明するものです。

Laravel 8.5 当時のアプリケーションを使い、文字列ではない API トークンが Eloquent のデータベースクエリに到達する理由と、`hash` 設定が観測結果に与える影響を示します。

> [!WARNING]
> **ローカル環境でのセキュリティ研究・学習専用です。本番環境にデプロイしたり、インターネットに公開したりしないでください。** 従来の認証設定を意図的に安全でない状態にしています。使い捨てのテストデータと、自分が所有するか、テストの許可を得たシステムだけを使用してください。

> [!IMPORTANT]
> **古いアプリケーションでは、`composer audit` や GitHub Dependabot のアラートでこの問題を検出できません。** アドバイザリの構造化されたバージョン情報は `laravel/framework` の `< 13.29.0` と `< v12.68.0` のみを対象としており、5.6 から 10.x は記載されていません。しかし、脆弱なコードは 5.6 以降、変更されずに存在しています（このリポジトリで検証済み。[影響の始まりと修正の範囲](#affected-versions)を参照）。このリポジトリの `laravel/framework` `v5.6.40` と `v8.5.0` のロックファイルに対して `composer audit` を実行すると、無関係なアドバイザリは報告されますが、この問題は報告されません。Laravel 11 より前のアプリケーションでは、依存関係スキャナーだけで影響の有無を判断せず、`config/auth.php` を確認してください。

## 対象範囲と前提条件

この再現では、次の組み合わせに注目します。

* Laravel の従来の `TokenGuard`（`driver => 'token'`）
* `auth:api` で保護された API ルート
* Eloquent ユーザープロバイダー
* MySQL または MariaDB による文字列と数値の暗黙的な型変換
* `config/auth.php` の `guards.api.hash` の値

**Laravel 8 を使っているだけで脆弱になるわけではありません。** Laravel 8 はすでに Sanctum に対応していました。実際に使っている認証ガード、その設定、データベース、対象エンドポイントにアクセスできるかどうかが関係します。

### この再現で 8.5 を対象にする理由

`v8.5.*` は、`token` ドライバーの `api` ガードを標準で生成する最後の `laravel/laravel` スケルトンです。`v8.6.0` 以降、`composer create-project` / `laravel new` で新規作成したアプリケーションでは、このガードは生成されません。`config/auth.php` から `api` ガードが削除され、`routes/api.php` の既定のルートが `auth:sanctum` に切り替わり、`composer.json` に `laravel/sanctum` が追加されました。そのため 8.5 は、手動で設定を追加しなくてもこの構成が現れる最新バージョンです。

このスケルトンの変更は、アップグレードするだけで問題が修正されるという意味ではありません。**この変更で `Illuminate\Auth\TokenGuard` が削除・変更されたわけではなく**、`laravel/framework` には現在も同クラスが存在します。アップグレード前から `token` ドライバーの `api` ガードを持つ既存アプリケーションは、`config/auth.php` にそのガードが残る限り、9、10、11、12、13 へとガードと不具合を引き継ぎます。その明確な証拠が、11.56.1、12.68.0、13.29.0 で公式修正が必要になったことです（[影響の始まりと修正の範囲](#affected-versions)を参照）。新しいメジャーバージョンへの移行だけで解消するなら、これらのパッチは必要なかったはずです。`config/auth.php` を確認せずに Laravel のバージョンだけを上げても、問題が残ります。

## 根本原因

古い `TokenGuard::user()` は、渡された API トークンが文字列かどうかを確認していませんでした。

```php
$token = $this->getTokenForRequest();

if (! empty($token)) {
    $user = $this->provider->retrieveByCredentials([
        $this->storageKey => $this->hash ? hash('sha256', $token) : $token,
    ]);
}
```

`hash => false` の場合、配列のトークンが `EloquentUserProvider::retrieveByCredentials()` に到達します。このプロバイダーは、配列の認証情報を `whereIn()` 条件として扱います。MySQL/MariaDB の暗黙的な型変換と組み合わさると、呼び出し元が有効な API トークンを渡していなくても、クエリがユーザーに一致することがあります。

これは既存トークンの窃取や復元ではなく、**認証バイパス**です。ユーザーに一致するか、どのユーザーに一致するかは、データベースと保存された値に依存します。この挙動によって、任意のアカウントを確実に選択できると示されたわけではありません。

### ローカルテストでの観測結果

同じ Laravel 8.5 のテストアプリケーションで、`GET /api/user` に対する JSON リクエストに、文字列ではない `api_token` を渡しました。

|`guards.api.hash`|観測されたレスポンス|説明|
|-|-|-|
|`true`|HTTP 500|`hash('sha256', $token)` に配列が渡され、データベースへの問い合わせ前に失敗した。|
|`false`|HTTP 200|配列が `whereIn()` に到達し、有効なトークンなしでデータベースからユーザーが返された。|

これは**あるローカル環境での観測結果**であり、すべてのデータベースやデータセットで同じ結果になることを保証するものではありません。

**重要:** `hash => true` は完全な修正ではありません。通常の `user()` の処理で例外が発生し、結果として処理を阻止しているだけです。また、古い `TokenGuard::validate()` にも必要な型チェックがありません。適切な修正では、入力の型を明示的に検証します。

<a id="affected-versions"></a>

## 影響の始まりと修正の範囲

脆弱なコードは最近追加されたものではありません。`EloquentUserProvider::retrieveByCredentials()` 内で、配列の認証情報を `whereIn()` 条件に変換する分岐は **Laravel 5.6** で導入され、それ以降のすべてのリリースに変更されずに存在しています。現在の `13.x` 系でも、汎用的な `whereIn()` の挙動自体は意図的に維持されており、修正されたのは文字列以外の入力を拒否しない `TokenGuard` 側の問題だけです。

このリポジトリの `laravel-5.6` ブランチを含め、ローカルで次の結果を確認しました。

|Laravel バージョン|配列の `api_token` を送信した結果|
|-|-|
|5.5.28（`composer create-project laravel/laravel:5.5.*` で一時的に確認。ブランチとしては保存していない）|`EloquentUserProvider` に配列用の分岐がなく、通常の `where()` に配列が渡され、`SQLSTATE[HY000]: ... column index out of range` でクエリが失敗する。**この方法では悪用できない。**|
|5.6.40（`laravel-5.6` ブランチ）|配列が `whereIn()` に到達し、有効なトークンなしで認証される。|
|8.5.0（`laravel-8.5` ブランチ / `main`）|5.6.40 と同じ。|

**最初に影響を受けるバージョンは 5.6 です。** 5.6 以降には同じ `whereIn()` の分岐がありますが、5.5 以前にはありません。

Laravel のサポートポリシーでは、各メジャーバージョンのリリースから 18 か月間はバグ修正、2 年間はセキュリティ修正が提供されます。通常、バグ修正の対象となるのは最新のメジャーバージョンのみです。実際に、この問題の公式修正が提供されたのは次の 3 系列だけです。

|バージョン|セキュリティ修正の提供期限|修正バージョン|
|-|-|-|
|11|2026-03-12（終了済み）|11.56.1|
|12|2027-02-24|12.68.0|
|13|2028-03-17（現行）|13.29.0|

脆弱な分岐を含んでいたその他の系列、つまり **5.6〜5.8 と、6、7、8、9、10 のすべて**は、すでにサポートが終了しています。おおむね 2018 年から 2023 年初頭までのリリースが該当します。これらには、深刻度にかかわらず、この修正も今後のセキュリティ修正も提供されません。この問題を解消するには、[対処方法](#remediation)で説明するバックポートか、メジャーバージョンのアップグレードが必要です。この不具合を含んでいたリリース系列全体のうち、公式修正が提供された系列（11、12、13）は少数です。

### 依存関係スキャナーでもこの空白を埋められない

問題は「パッチが存在しない」ことだけではありません。`composer audit`、GitHub Dependabot のアラートなどのスキャナーが実際に照合する、アドバイザリの構造化された `vulnerable_version_range` データには、`< 13.29.0` と `< v12.68.0` の項目しかありません。5.6〜10.x に言及する構造化データがないため、これに基づくツールは古い固定バージョンに照合できません。

このリポジトリのロックファイルで直接確認しています。

```sh
$ composer audit --locked   # laravel-5.6 ブランチの composer.lock、laravel/framework v5.6.40
Found 19 security vulnerability advisories affecting 8 packages:
...none of them GHSA-f9x3-7vq9-cph6...

$ composer audit --locked   # main の composer.lock、laravel/framework v8.5.0
...same result, this CVE never appears...
```

どちらのロックファイルでも、無関係な別のアドバイザリは多数報告されています。そのため、使用したローカルのアドバイザリデータベースが古いわけではありません。しかし、どちらの実行でも GHSA-f9x3-7vq9-cph6 は表示されません。Laravel 5.6〜10.x（または未修正の 11/12/13 のポイントリリース）のアプリケーションでは、この問題が悪用可能な状態でも、`composer audit` や Dependabot によるこの問題の警告が出ないことがあります。確実に判断するには、以下の手順で手動確認してください。

## アプリケーションが影響を受けるか確認する

まず、登録済みのルートを確認します。

```bash
php artisan route:list
```

`auth:api` を使うルートが見つかったら、`config/auth.php` の `guards.api` を確認します。

```php
'api' => [
    'driver' => 'token',
    'provider' => 'users',
    'hash' => false,
],
```

古い TokenGuard 実装では、**`hash` オプションを省略した場合も `false` になります**。

`auth:api` の存在は調査のきっかけにはなりますが、**脆弱性が外部から悪用可能である証拠ではありません**。`api` ガードが Sanctum などの別のドライバーを使っている可能性があります。逆に、TokenGuard が別のガード名で登録されている場合もあります。URL に `/api/` という接頭辞があるだけでは、脆弱性の有無は判断できません。

アクセス可能な API が、`hash => false` の従来の TokenGuard、Eloquent プロバイダー、MySQL/MariaDB を組み合わせて使っている場合は、優先的に確認してください。TokenGuard のメソッドをコピーまたはオーバーライドした独自ガードも確認対象です。

<a id="remediation"></a>

## 対処方法

### 公式修正が提供された Laravel 系列

この TokenGuard の問題に対処するには、少なくとも次のリリースに更新してください。

|Laravel バージョン|修正済みリリース|
|-|-|
|11|**11.56.1**|
|12|**12.68.0**|
|13|**13.29.0**|

アプリケーションに合わせてフレームワークと依存関係を更新し、テストスイート全体を実行してください。

### サポートが終了した Laravel バージョン

すぐにアップグレードできない場合は、上流の修正にある 2 つの入力型チェックを `Illuminate\Auth\TokenGuard` にバックポートしてください。

**`user()` 内:**

```diff
- if (! empty($token)) {
+ if (is_string($token) && ! empty($token)) {
```

**`validate()` 内:**

```diff
- if (empty($credentials[$this->inputKey])) {
+ if (! is_string($credentials[$this->inputKey] ?? null) || empty($credentials[$this->inputKey])) {
```

**両方**の変更を適用してください。この問題を修正するために、トークンの保存方法をハッシュから平文に変更する必要はありません。

`vendor/` 内を記録せずに直接編集する方法は避けてください。Composer が変更を上書きする可能性があります。再現可能なパッチ、または適切に登録した独自ガードとして管理し、回帰テストで修正を検証してください。アプリケーションが `TokenGuard::user()` または `validate()` をオーバーライドしている場合は、そのコードも個別に確認してください。コピーされた実装は、フレームワークを更新しても修正されない可能性があります。

このバックポートが対処するのは、**今回の認証バイパスだけ**です。サポートが終了した Laravel リリースのセキュリティサポートを復活させるものでも、ほかの既知の脆弱性を修正するものでもありません。

### このリポジトリでのパッチ管理

このリポジトリでは、上記の「再現可能なパッチとして管理する」方法を、[`cweagans/composer-patches`](https://github.com/cweagans/composer-patches) を使って実践しています。これにより、`composer install` をやり直しても `vendor/` への変更が再適用されます。

```json
"extra": {
    "patches": {
        "laravel/framework": {
            "Demo: dump api_token before auth lookup (TokenGuard)": "patches/tokenguard-debug-dump.patch"
        }
    }
}
```

* `patches/tokenguard-debug-dump.patch` は、**標準で登録・有効化されています**。`TokenGuard::user()` に `print_r($token); exit;` を挿入し、配列の `api_token` を送信すると、文字列ではなく PHP の配列であることを出力で確認できます。このリポジトリの目的は再現なので、標準では脆弱性の修正を適用していません。
* `patches/tokenguard-is-string-fix.patch` には、実際のバックポート（上記の 2 つの差分）が含まれています。ただし、**意図的に `extra.patches` から参照していない**ため、自動では適用されません。

デモ用の出力ではなく実際の修正を検証するには、登録するパッチを切り替えてから `laravel/framework` を再インストールし、プラグインに再適用させます。

```sh
# 上記の extra.patches.laravel/framework の参照先を
# patches/tokenguard-is-string-fix.patch に変更してから実行:
rm -rf vendor composer.lock
composer install --prefer-dist --no-interaction
# インストールログに次の表示があることを確認:
#   Applying patches for laravel/framework
#       patches/tokenguard-is-string-fix.patch (...)
```

切り替え後は、配列の `api_token` を持つリクエストがデータベースに到達せず、HTTP 401 を返すはずです。

## 検証チェックリスト

修正を適用したら、次の点を確認してください。

* 有効なトークンでは引き続き認証に成功する。
* 保護されたルートにトークンなしでアクセスすると HTTP 401 が返る。
* 文字列ではないトークンは HTTP 401 で拒否され、ユーザーとして認証されたり、HTTP 500 が発生したりしない。
* 不正なトークンがユーザープロバイダーのデータベース検索に到達しない。
* `TokenGuard::validate()` を呼び出す独自の認証経路が、テストの対象になっている。

再現テストはすべて隔離した環境で行い、ダミーアカウントと、実際のユーザー情報を含まないデータベースを使用してください。

## 参考資料

* [GitHub Security Advisory — GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
* [Laravel 8.5.0 の `TokenGuard.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/TokenGuard.php)
* [Laravel 8.5.0 の `EloquentUserProvider.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/EloquentUserProvider.php)
* [Laravel 11.56.1 の `TokenGuard.php`（修正済み）](https://github.com/laravel/framework/blob/v11.56.1/src/Illuminate/Auth/TokenGuard.php)
* [Laravel のサポートポリシー](https://github.com/laravel/docs/blob/13.x/releases.md#support-policy)
* このリポジトリの `laravel-5.6` ブランチ — 新規インストールした Laravel 5.6.40 での同じ再現（影響あり）
* このリポジトリの `laravel-8.5` ブランチ — このブランチの Laravel 8.5 の状態を保存したスナップショット
