# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

WP LinkedIn Auto Share (`wplas`) 是一個 WordPress 外掛，在文章發布時自動同步到 LinkedIn。支援 OAuth 2.0 連結個人帳號，透過 LinkedIn UGC Posts API 發文。

## Architecture

單檔外掛，所有邏輯在 `wp-linkedin-auto-share.php` 的 `WP_LinkedIn_Auto_Share` singleton 類別中：

- **OAuth 流程**：LinkedIn OAuth 2.0 授權碼模式，state 用 transient 驗證，token 存 `wp_options`
- **發文觸發**：掛在 `transition_post_status` hook，只在文章首次從非 publish 轉為 publish 時觸發（防重複）
- **Meta Box**：文章編輯側欄提供逐篇勾選同步的 checkbox，也支援全域自動同步模式
- **貼文範本**：支援 `{title}`, `{excerpt}`, `{url}`, `{tags}` 變數，上限 3,000 字

## Key Options (wp_options)

| option key | 用途 |
|---|---|
| `wplas_client_id` / `wplas_client_secret` | LinkedIn App 憑證 |
| `wplas_access_token` | OAuth access token |
| `wplas_person_urn` / `wplas_person_name` | 連結的 LinkedIn 使用者 |
| `wplas_post_template` | 貼文範本 |
| `wplas_auto_all_posts` | 是否全自動同步 |

## Post Meta

- `_wplas_share` — 是否同步此篇（`1`/`0`）
- `_wplas_shared_at` — 同步成功時間戳
- `_wplas_share_error` — 最近一次同步錯誤訊息

## LinkedIn API

使用 UGC Posts API (`v2/ugcPosts`)，需要 `w_member_social` scope。Userinfo 端點 (`v2/userinfo`) 用於取得 person URN。

## Development

此外掛運行在本地 WordPress 環境中，路徑為 `/Users/oberonlai/Sites/lin/`。無建置步驟、無測試、無 npm/composer 依賴。直接編輯 PHP 檔案即可。

Text Domain: `wplas`
Requires PHP: 8.0
