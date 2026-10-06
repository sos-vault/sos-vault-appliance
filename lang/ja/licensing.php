<?php

/**
 * Open-core licensing strings (日本語).
 */
return [

    'status' => [
        'active' => '有効',
        'expired' => '期限切れ',
        'revoked' => '取り消し',
        'none' => 'なし',
    ],

    'banner' => [
        'unlicensed_summary' => 'オープンコア・ベースラインで動作中：管理者1名、暗号化なしのボールトディレクトリ。ライセンスをインストールすると、複数ユーザー、グループ、モジュール、ITSM連携、暗号化ボールト、イベントログが有効になります。',
        'expired_summary' => 'ライセンスの有効期限が切れました。有償機能は一時的に無効になっています。アクセスを復元するには更新してください。',
        'cta_install' => 'ライセンスをインストール',
    ],

    'request' => [
        'section_heading' => 'ライセンスをリクエスト',
        'section_description' => 'このサーバーのハードウェアにバインドされた短いキーを生成します。コピーして sos-vault.com (ライセンスリクエストの検証) に貼り付けると、このサーバー専用のライセンスを購入できます。',
        'button_generate' => 'ライセンスリクエストを生成',
        'button_copy' => 'キーをコピー',
        'button_copied' => 'コピーしました',
        'key_heading' => 'ライセンスリクエストキー',
        'key_helper' => 'このキーをコピーして sos-vault.com の「ライセンスリクエストの検証」に貼り付けてください。このサーバーのハードウェア指紋のみを含むため、共有しても安全です。',
        'notif_key_ready' => 'ライセンスリクエストキーが準備完了',
        'notif_key_ready_body' => '下のキーをコピーして sos-vault.com の「ライセンスリクエストの検証」に貼り付けてください。',
        'notif_failed' => 'ライセンスリクエストを生成できませんでした',
    ],

    'expired_non_admin_blocked' => 'このアプライアンスには現在有効なライセンスがありません。管理者のみサインインできます。オペレータにライセンスの更新またはインストールを依頼してください。',

    'user_creating_single_admin' => 'オープンコア・ベースラインでは管理者1名のみ許可されます。さらにユーザーを追加するにはライセンスをインストールしてください。',

    'modules_unavailable' => 'モジュールのインストールには有効なライセンスが必要です。',
    'event_log_unavailable' => 'イベントログはライセンスのあるアプライアンスでのみ利用可能です。',

    'disk_manager' => [
        'unlicensed_title' => 'ボールトディレクトリ',
        'vault_dir_label' => 'ボールトディレクトリへのパス',
        'vault_dir_helper' => 'オープンコア・ベースラインは、暗号化されていないディレクトリをボールトとして使用します。既定値：/vault。ホスト上の絶対パスである必要があります。',
        'save_button' => '保存',
        'save_notif' => 'ボールトディレクトリを保存しました。',
    ],

    'dashboard' => [
        'unlicensed_title' => 'ライセンス',
        'unlicensed_value' => 'オープンコア',
        'unlicensed_callout' => 'ライセンスをインストールすると、複数ユーザー、グループ、モジュール、ITSM、暗号化ボールト、イベントログが解放されます。',
    ],

    'event' => [
        'request_generated' => 'ライセンスリクエストを生成しました',
        'license_installed' => 'ライセンスをインストールしました',
        'license_expired' => 'ライセンスが期限切れになりました',
        'login_blocked' => '非管理者のログインがブロックされました（ライセンスなし）',
    ],

];
