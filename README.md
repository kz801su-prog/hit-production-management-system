# 椅子製造 工程管理・標準時間・進捗・評価管理システム

大津家具の椅子製造現場を管理するためのWebアプリです。  
PHP 8系 / MySQL / PDO / Bootstrap 5 / Chart.js で構成。Laravelは使用しません。

---

## システム構成の概要

| 項目 | 内容 |
|------|------|
| 言語 | PHP 8系 |
| DB | MySQL（Xserver） |
| DB接続 | PDO |
| フロント | Bootstrap 5 / Chart.js / JavaScript |
| サーバー | Xserver |
| フレームワーク | なし（スクラッチ） |

---

## ディレクトリ構成

```
OtsuFurniture/
├── config/
│   └── config.php          ← DB接続情報・アプリ定数（★必ず編集）
├── app/
│   ├── db.php              ← PDO接続
│   ├── auth.php            ← ログイン・CSRF
│   ├── permissions.php     ← 権限管理（RBAC）
│   ├── functions.php       ← 共通ヘルパー関数
│   ├── logger.php          ← 操作ログ（audit_logs）
│   ├── chair_type_service.php
│   ├── standard_time_service.php
│   ├── order_service.php
│   ├── progress_service.php
│   ├── evaluation_service.php
│   └── backup_service.php
├── public/                 ← Webサーバーのドキュメントルートに設定
│   ├── index.php
│   ├── login.php
│   ├── dashboard.php
│   └── ...（全25ページ）
├── database/
│   ├── schema.sql          ← テーブル定義（初回のみ実行）
│   ├── seed.sql            ← 初期データ（初回のみ実行）
│   └── backup/            ← バックアップSQLの保存先
├── assets/
│   ├── css/style.css
│   └── js/main.js
└── uploads/
    ├── csv/
    └── chair_type_media/   ← 椅子タイプの画像・図面のアップロード先
```

---

## Xserver セットアップ手順

### 1. ファイルのアップロード

FTP/SFTPでXserverへアップロードします。

```
アップロード先の例:
/home/xs000000/example.com/public_html/system/
```

`public_html` 直下に `system/` フォルダを作り、その中に全ファイルを配置します。  
ブラウザからは `https://example.com/system/public/` でアクセスします。

> **注意:** `public/` フォルダだけをドキュメントルートに設定できる場合はそちらが望ましいですが、Xserver共用サーバーでは `public_html` 直下に置く方が簡単です。

---

### 2. MySQLデータベースの作成

Xserver **サーバーパネル** → **MySQL設定** で操作します。

**① データベースを追加**

| 項目 | 入力値の例 |
|------|-----------|
| データベース名 | `xs000000_chair` |

**② MySQLユーザーを追加**

| 項目 | 入力値の例 |
|------|-----------|
| ユーザー名 | `xs000000_chair` |
| パスワード | 任意の強力なパスワード |

**③ ユーザーをデータベースに紐づける**

「MySQLユーザーをデータベースへ追加」から、作成したユーザーとDBを紐づけます。

---

### 3. config.php を編集

[config/config.php](config/config.php) を開き、以下の3箇所を変更します。

```php
// ↓ 手順2で作成したDB名・ユーザー名・パスワード
define('DB_NAME', 'xs000000_chair');
define('DB_USER', 'xs000000_chair');
define('DB_PASS', 'YOUR_DB_PASSWORD');

// ↓ 実際のURL（末尾スラッシュなし）
define('APP_URL', 'https://example.com/system/public');
```

また、セキュリティキーを必ず変更してください（32文字以上の任意文字列）。

```php
define('ENCRYPTION_KEY', 'ここをランダムな文字列に変更する_32文字以上');
```

---

### 4. SQLの実行（テーブル作成 → 初期データ投入）

Xserver **サーバーパネル** → **phpmyadmin** → 対象データベースを選択して実行します。

**① テーブル定義を作成**

`database/schema.sql` の内容をphpMyAdminの「SQLタブ」に貼り付けて実行。

**② 初期データを投入**

`database/seed.sql` の内容を同様に実行。

> schema.sql → seed.sql の順で実行してください（順序を逆にすると外部キーエラーが出ます）。

---

### 5. アップロードディレクトリのパーミッション設定

FTPクライアント（FileZillaなど）で以下のディレクトリを **755** に設定します。

```
uploads/
uploads/csv/
uploads/chair_type_media/
database/backup/
```

Xserverのファイルマネージャーから変更することもできます。

---

### 6. 動作確認

ブラウザで `https://example.com/system/public/login.php` にアクセスします。

---

## 初期ログイン情報

> **本番稼働前に全員のパスワードを必ず変更してください。**

| ログインID | パスワード | 権限 | 氏名 |
|-----------|----------|------|------|
| `president` | `password123` | 社長 | 大津 一郎 |
| `admin`     | `password123` | 管理者 | 田中 健二 |
| `yamada`    | `password123` | 工程リーダー | 山田 太郎 |
| `sato`      | `password123` | 作業員 | 佐藤 花子 |
| `suzuki`    | `password123` | 作業員 | 鈴木 次郎 |
| `ito`       | `password123` | 作業員 | 伊藤 美咲 |

---

## 権限レベル

```
社長（president）
  ↓
管理者（admin）            : 社員・ユーザー管理、バックアップ、社長の言葉
  ↓
工場長（factory_manager）  : 作業指示の取消、評価・改善管理
  ↓
工程リーダー（process_leader）: 作業指示作成、椅子タイプ登録、標準時間設定
  ↓
作業員（worker）           : 作業開始・終了、進捗確認、問題点報告
```

---

## 主な画面一覧

| URL | 画面名 | 必要権限 |
|-----|--------|---------|
| `/login.php` | ログイン | 全員 |
| `/dashboard.php` | ダッシュボード | 全員 |
| `/chair_types.php` | 椅子タイプ一覧・検索 | 全員 |
| `/chair_type_form.php` | 椅子タイプ登録・編集 | リーダー以上 |
| `/standards.php` | 工程標準時間設定 | リーダー以上 |
| `/adjustments.php` | 差分工程設定 | リーダー以上 |
| `/chair_type_media.php` | 画像・図面管理 | リーダー以上 |
| `/orders.php` | 作業指示一覧 | 全員 |
| `/order_form.php` | 作業指示作成 | リーダー以上 |
| `/progress_board.php` | 進捗ボード | 全員 |
| `/gantt.php` | ガントチャート | 全員 |
| `/work_start.php` | 作業開始登録 | 全員 |
| `/work_finish.php` | 作業終了登録 | 全員 |
| `/evaluations.php` | 個人評価 | リーダー以上 |
| `/improvements.php` | 改善管理 | 全員（登録）/ リーダー（対策） |
| `/simulator.php` | 人員シミュレーター | リーダー以上 |
| `/employees.php` | 社員管理 | 管理者以上 |
| `/users.php` | ユーザー管理 | 管理者以上 |
| `/words.php` | 社長の言葉管理 | 管理者以上 |
| `/backup.php` | バックアップ管理 | 管理者以上 |

---

## 標準時間の計算式

```
標準時間 =
  段取り時間
  ＋ (正味作業時間 ÷ 基準本数 × 注文数量)
  ＋ 差分加算時間
  − 差分減算時間
  × (1 ＋ アローアンス率 ÷ 100)
  ＋ 固定アローアンス時間
```

**計算例（縫製工程、CHAIR-A-02、30本）**

```
数量換算正味 = 120分 ÷ 10本 × 30本 = 360分
差分後      = 360 + 12 = 372分（柄合わせ加算）
アローアンス = 372 × 1.08 = 401.76分
固定アロー  = 401.76 + 10 = 411.76分
段取り加算  = 411.76 + 20 = 431.76分 → 約432分
```

---

## バックアップについて

- 管理画面（`/backup.php`）から手動バックアップが実行できます
- バックアップファイルは `database/backup/` に保存されます（SQLダンプ形式）
- 直近30日分のみ保持（古いものは自動削除）
- バックアップ失敗時は `config.php` の `MAIL_ADMIN` 宛にメールが送信されます

> Xserverでは毎日自動バックアップ機能もあります（サーバーパネル → バックアップ）。  
> 併用することを推奨します。

---

## 椅子タイプのバージョン管理ルール

> **仕様が1つでも違う椅子は、同じタイプに上書きせず、必ず新バージョンとして登録する。**

```
CHAIR-A     ← 基本形（version_no = 0）
CHAIR-A-01  ← 肘付き（version_no = 1）
CHAIR-A-02  ← 柄合わせ（version_no = 2）
```

作業指示作成時に椅子タイプ情報のスナップショットが保存されるため、  
後からマスターを変更しても過去の作業指示の標準時間は変わりません。

---

## セキュリティチェックリスト（本番稼働前）

- [ ] `config.php` の `DB_PASS` を強力なパスワードに変更した
- [ ] `config.php` の `ENCRYPTION_KEY` をランダムな32文字以上の文字列に変更した
- [ ] `config.php` の `APP_URL` を実際のURLに変更した
- [ ] `config.php` の `MAIL_FROM` / `MAIL_ADMIN` を実際のメールアドレスに変更した
- [ ] 全ユーザーの初期パスワード（`password123`）を変更した
- [ ] `uploads/` と `database/backup/` のパーミッションを755に設定した
- [ ] `app/` と `config/` に直接ブラウザからアクセスできないことを確認した

---

*椅子製造 工程管理システム v2.0.0*
