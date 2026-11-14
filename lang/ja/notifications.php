<?php

return [
    'title' => '通知',
    'no_notifications' => '通知はありません。',
    'mark_all_read' => 'すべて既読にする',

    // Notifications page (pages/notifications/index.blade.php)
    'page_heading' => '通知',
    'page_description' => '現在の通知です。',
    'page_empty_heading' => '通知が見つかりません',
    'page_empty_description' => 'すべての通知がここに表示されます。',
    'page_mark_as_read' => '既読にする',
    'page_from' => '送信者: :name',

    // Announcements page (pages/announcements/)
    'ann_heading' => 'お知らせ',
    'ann_description' => 'システム全般のお知らせです。',
    'ann_empty_heading' => 'お知らせが見つかりません',
    'ann_empty_description' => 'すべてのお知らせがここに表示されます。',
    'ann_view_all' => 'すべてのお知らせを見る',
    'ann_written_on' => ':date に投稿',

    // -------------------------------------------------------------------------
    // ユーザー通知メッセージ（notifyUser ヘルパー）
    // -------------------------------------------------------------------------

    // vaultライフサイクル — リサイズリスナー（ResizeVault）
    'vault_no_associated' => 'ユーザー :username に関連付けられたvaultがありません。',
    'vault_not_found' => ':username のvaultが見つかりません。',
    'vault_not_open' => ':username のvaultが開いていません。',
    'vault_expand_failed' => 'vaultを :size に拡張できませんでした。',
    'vault_expand_success' => 'vaultを :size に正常に拡張しました。',
    'vault_shrink_failed' => 'vaultを :size に縮小できませんでした。',
    'vault_shrink_success' => 'vaultを :size に正常に縮小しました。',
    'vault_adjust_failed' => 'vaultの調整に失敗しました。',
    'vault_adjust_success' => 'vaultの調整が完了しました。',
    'vault_busy' => 'vaultは現在使用中です。しばらくしてから再試行してください。',
    'vault_busy_stale' => '再試行してください。',
    'vault_reopen_failed' => ' vaultを再度開けませんでした。',
    'vault_size_changed' => ' vaultのサイズが :old から :new に変わりました。',
    'vault_tokens_adjusted' => ' トークン残高が :old から :new に調整されました。',
    'vault_resize_unexpected' => 'vaultのサイズ変更が予期せず失敗しました。サポートにお問い合わせください。',

    // 管理者によるvault操作（Filamentパネル — UserResource）
    'admin_vault_expand_success' => 'システム管理者によってvaultが :size MB 正常に拡張されました。',
    'admin_vault_expand_failed' => 'システム管理者によるvaultの :size MB 拡張に失敗しました。',
    'admin_vault_shrink_success' => 'システム管理者によってvaultが :size MB 正常に縮小されました。',
    'admin_vault_shrink_failed' => 'システム管理者によるvaultの :size MB 縮小に失敗しました。',

    // スケジューラー — スケジュール縮小
    'scheduler_shrink_no_space' => 'スケジュールされた :size のvault縮小を実行できませんでした：空き容量（:free）が必要な最小値（:required）を下回っています。容量を解放すると自動的に再試行されます。',

    // アップロードと解凍（VaultTools）
    'decrypt_complete' => '復号が完了しました。復号後のファイル名：:filename',
    'extract_complete' => '解凍が完了しました。元のファイルを削除します：:filename',
    'case_create_failed' => '関連するケースを作成できませんでした。',
    'case_created' => '関連ケース :case シリアル :serial が正常に作成されました。',

    // 解凍エラーメッセージ（doDecryptAndExtract / decrypt / xtract）
    'unpack_file_not_found' => 'ファイルが見つかりません。処理を続行できません。',
    'unpack_decrypt_failed' => '復号に失敗しました。パスフレーズが正しいことを確認してください。',
    'unpack_decrypt_pass_error' => '復号用パスフレーズを解読できませんでした。',
    'unpack_decrypt_pass_empty' => '復号用パスフレーズが空です。',
    'unpack_file_decrypt_failed' => 'ファイルを復号できませんでした。',
    'unpack_size_calc_failed' => '解凍後の予想サイズを計算できませんでした。',
    'unpack_count_calc_failed' => '解凍後の予想ファイル数を計算できませんでした。',
    'unpack_no_space' => 'vaultにファイルを解凍するための空き容量が不足しています。',
    'unpack_dir_unknown' => '解凍先のディレクトリを特定できませんでした。',
    'unpack_extract_failed' => '解凍コマンドが失敗しました。',
    'unpack_extract_unknown_dir' => '解凍が予期しないディレクトリに行われました。',
    'unpack_dir_exists' => '解凍先のディレクトリがすでに存在します。解凍を中断します。',
    'unpack_dir_not_found' => 'ケース作成後にディレクトリが見つかりません。',
    'unpack_dir_not_readable' => 'ケース作成後にディレクトリを読み込めませんでした。',
    'unpack_extraction_complete' => 'ファイルの解凍が完了しました。',
    'unpack_kept_in_vault' => ':reason ファイルはvault内に保持されています。',
    'unpack_decrypt_no_key' => 'ファイルの復号に失敗しました。処理を続行できません。',

    // APIによるアップロードと解凍（AuthController）
    'upload_auto_failed' => '自動アップロードが失敗しました：:reason',
    'upload_file_success' => 'ファイルのアップロードが完了しました：:filename',
    'upload_auto_success' => ':message :filename',
    'unpack_auto_failed' => '自動解凍が失敗しました：:reason',
    'upload_success_unpack_failed' => 'ファイルは正常にアップロードされましたが、自動解凍が失敗しました：:reason',
    'file_upload_unpack_failed' => 'ファイル :filename は正常にアップロードされましたが、自動解凍が失敗しました：:reason。ファイルはvaultで利用可能です。',
    'upload_auto_complete' => '自動ファイルアップロード成功。:details',

    // JIRA連携（JIRADownloader）
    'jira_download_failed' => '課題 :issueid の添付ファイル :filename のダウンロードに失敗しました。:error',
    'jira_download_success' => '課題 :issueid の添付ファイル :filename のダウンロードが完了しました。',

    // 請求とサブスクリプション（SubscriptionController）
    'subscription_product_error' => 'サブスクリプション製品IDの特定中にエラーが発生しました。これが誤りだと思われる場合はお問い合わせください。',
    'transaction_error' => 'トランザクションの処理中にエラーが発生しました。再試行してください。',
    'wrong_plan_disk_expand' => 'ディスク拡張に対してプランの種類が正しくありません。',
    'wrong_plan_disk_cancel' => 'ディスクキャンセルに対してプランの種類が正しくありません。',
    'wrong_plan_disk_schedule' => 'スケジュールされたディスクキャンセルに対してプランの種類が正しくありません。',
    'wrong_plan_tokens' => 'トークン購入に対してプランの種類が正しくありません。',
    'vault_expand_requested' => ':size のvault拡張がリクエストされました。この操作には数分かかる場合があります。',
    'vault_shrink_requested' => ':size のvault縮小がリクエストされました。この操作には数分かかる場合があります。',
    'vault_shrink_scheduled' => ':size のvault縮小が :date に予定されています。',
    'vault_shrink_no_disk' => 'vault縮小リクエストが失敗しました。アクティブなディスク拡張が見つかりません。',
    'tokens_purchase_success' => 'トークンの購入が成功しました。新しいトークン残高は :tokens トークンです。',

    // Paddleウェブフック（WebhookController）
    'subscription_payment_received' => 'サブスクリプションの支払いが受領されました。新しいトークン残高：:tokens。',
    'subscription_cancelled' => 'サブスクリプションがキャンセルされました。',
    'disk_expansion_cancelled' => 'ディスク拡張サブスクリプションがキャンセルされました。',

    // プラン切り替え（Checkout::switchPlan）
    'plan_upgraded' => 'プランが :plan にアップグレードされました。vaultとトークンを調整しています。',
    'plan_downgraded_scheduled' => 'プランが :plan に変更されました。vaultは :date に縮小されます。',
    'plan_downgrade_blocked' => 'アクティブなディスク拡張がある間はプランをダウングレードできません。まずキャンセルしてください。',
    'plan_switch_error' => 'プランの変更中にエラーが発生しました。再試行してください。',
];
