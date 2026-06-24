# OtsuFurniture PWA設定

## 概要

- PWA化方式: manual manifest + service worker
- インストール確認: ブラウザのアドレスバーやメニューから「アプリをインストール」を選択
- 更新方式: 新しいビルドを配置したあと、再読み込みで最新アセットへ追従

## 追加内容

- Web App Manifest
- ホーム画面追加用アイコン
- Service Worker
- スタンドアロン表示向けのテーマカラー設定

## 運用メモ

- PHP 構成のため vite-plugin-pwa は使わず、共通ヘッダー経由で manifest と service worker を配信しています。
- 本番反映後は一度ハードリロードして Service Worker を更新してください。
