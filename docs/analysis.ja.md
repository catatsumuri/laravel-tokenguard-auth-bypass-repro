# 詳細な分析: Laravel TokenGuard 認証バイパス

[← README に戻る](../README.ja.md) · [English](analysis.md)

[GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6) の背景（根本原因、観測結果、どこまで遡るか、スキャナーが見逃す理由）をまとめています。「自分は影響を受けるか、どう直すか」は [README](../README.ja.md) から読んでください。以下の内容は、このリポジトリの Laravel 8.5 と 5.6 のアプリケーションで検証したものです。サポート期限は 2026-10 時点です。

> [!WARNING]
> **ローカル環境でのセキュリティ研究・学習専用です。** デプロイやインターネットへの公開は絶対にしないでください。

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

### エンドポイントを隠すことは対策にならない

ルートを公開ドキュメントに書かない・わかりにくい名前にするといった対策は、見つけるコストを上げるだけです。モバイルアプリや JS クライアントを持つアプリでは、そのコストはほぼゼロです。APK を逆コンパイルする、あるいは配信されている JS バンドルを読むだけで、正確なルート、正確なパラメータ名（`api_token`）、正確なレスポンス形式が、推測の必要なく丸ごと手に入ります。この手口（「A2B」、app-to-backend 攻撃と呼ばれることもあります）は、まさに「隠すことによる防御」を無力化するものです。「脆弱なエンドポイントを見つける」という作業が「アプリを1回インストールする」だけに変わってしまいます。狙えるのは `/api/user` だけではなく、同じガードの背後にあるルートすべてなので、攻撃者はその中のどれか1つを見つければ十分です。

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

脆弱な分岐を含んでいたその他の系列、つまり **5.6〜5.8 と、6、7、8、9、10 のすべて**は、すでにサポートが終了しています。おおむね 2018 年から 2023 年初頭までのリリースが該当します。これらには、深刻度にかかわらず、この修正も今後のセキュリティ修正も提供されません。この問題を解消するには、[README](../README.ja.md)で説明するバックポートか、メジャーバージョンのアップグレードが必要です。この不具合を含んでいたリリース系列全体のうち、公式修正が提供された系列（11、12、13）は少数です。

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

どちらのロックファイルでも、無関係な別のアドバイザリは多数報告されています。そのため、使用したローカルのアドバイザリデータベースが古いわけではありません。しかし、どちらの実行でも GHSA-f9x3-7vq9-cph6 は表示されません。Laravel 5.6〜10.x（または未修正の 11/12/13 のポイントリリース）のアプリケーションでは、この問題が悪用可能な状態でも、`composer audit` や Dependabot によるこの問題の警告が出ないことがあります。未修正の 11.x（`< 11.56.1`）をスキャナーが警告するかどうかは確認していません。確実に判断するには、[README](../README.ja.md)の説明どおり、自分で `config/auth.php` を確認してください。

## このリポジトリでのパッチ管理

このリポジトリでは、[README](../README.ja.md)の「再現可能なパッチとして管理する」方法を、[`cweagans/composer-patches`](https://github.com/cweagans/composer-patches) を使って実践しています。これにより、`composer install` をやり直しても `vendor/` への変更が再適用されます。

```json
"extra": {
    "laravel": {
        "dont-discover": []
    }
}
```

* 既定ではパッチは登録されていません（`composer.json` に `extra.patches` はありません）。`composer install` はフレームワークをアップストリームのまま適用するため、`TokenGuard::user()` はそのまま動作し、脆弱な挙動を最初から確認できます。このリポジトリの目的は再現なので、標準では脆弱性の修正を適用していません。
* [`patches/tokenguard-is-string-fix.patch`](../patches/tokenguard-is-string-fix.patch) には、実際のバックポート（[README](../README.ja.md) の 2 つの差分）が含まれています。ただし、**`extra.patches` から参照していない**ため、自動では適用されません。

実際の修正を検証するには、このパッチを登録してから `laravel/framework` を再インストールし、プラグインに適用させます。

```sh
# composer.json の extra.patches.laravel/framework に
# patches/tokenguard-is-string-fix.patch を追加してから実行:
rm -rf vendor composer.lock
composer install --prefer-dist --no-interaction
# インストールログに次の表示があることを確認:
#   Applying patches for laravel/framework
#       patches/tokenguard-is-string-fix.patch (...)
```

修正パッチを登録すると、配列の `api_token` を持つリクエストはデータベースに到達せず、HTTP 401 を返すはずです。

## 参考資料

* [GitHub Security Advisory — GHSA-f9x3-7vq9-cph6](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
* [Laravel 8.5.0 の `TokenGuard.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/TokenGuard.php)
* [Laravel 8.5.0 の `EloquentUserProvider.php`](https://github.com/laravel/framework/blob/v8.5.0/src/Illuminate/Auth/EloquentUserProvider.php)
* [Laravel 11.56.1 の `TokenGuard.php`（修正済み）](https://github.com/laravel/framework/blob/v11.56.1/src/Illuminate/Auth/TokenGuard.php)
* [Laravel のサポートポリシー](https://github.com/laravel/docs/blob/13.x/releases.md#support-policy)
* このリポジトリの `laravel-5.6` ブランチ — 新規インストールした Laravel 5.6.40 での同じ再現（影響あり）
* このリポジトリの `laravel-8.5` ブランチ — このブランチの Laravel 8.5 の状態を保存したスナップショット
