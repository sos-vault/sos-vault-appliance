<?php

return [
    'page_title' => 'アラート',
    'page_description' => 'このボールトのアラートルールを管理します。ルールはメンバーがアップロードするすべてのケースに適用され、新規アップロードのたびに自動的に評価されます。',

    'file_add_alert_label' => 'アラートを追加',
    'file_add_alert_tooltip' => 'このファイルに基づいてアラートルールを作成する',

    'table_empty_heading' => 'アラートはまだありません',
    'table_empty_description' => 'ファイル、メトリクス、または参照ケースとの差分が条件に一致したときに通知を受け取るアラートルールを作成してください。',

    'column_name' => '名前',
    'column_severity' => '重大度',
    'column_type' => '種類',
    'column_enabled' => '有効',
    'column_creator' => '作成者',
    'column_delivery_notification' => '通知',
    'column_delivery_email' => 'メール',
    'column_delivery_event' => 'イベント / SIEM',
    'column_delivery_contact_point' => 'コンタクトポイント',
    'column_created_at' => '作成日時',

    'action_create' => '新しいアラート',
    'action_edit' => '編集',
    'action_delete' => '削除',

    'field_name' => '名前',
    'placeholder_name' => '例: Webサーバーの高CPU使用率',
    'field_description' => '説明',
    'placeholder_description' => 'このアラートルールが検出する内容についての任意のメモ',
    'field_severity' => '重大度',
    'field_type' => 'アラートの種類',

    'type_grep' => 'ファイルパターン一致',
    'type_levels' => 'メトリクスしきい値',
    'type_diff' => '参照ケースとの差分',

    'field_grep_path' => 'ファイルパス',
    'placeholder_grep_path' => '/var/log/messages',
    'field_grep_regex' => '正規表現',
    'placeholder_grep_regex' => 'error|failed|panic',
    'field_grep_match_mode' => '発火条件',
    'grep_match_found' => 'パターンが見つかった場合',
    'grep_match_not_found' => 'パターンが見つからない場合',

    'field_levels_metric' => 'メトリクス',
    'field_levels_threshold' => 'しきい値',
    'placeholder_levels_threshold' => '80',
    'field_levels_mount_path' => 'マウントパス',
    'placeholder_levels_mount_path' => '/',

    'metric_cpu' => 'CPU',
    'metric_disk' => 'ディスク',
    'metric_inodes' => 'inode',
    'metric_memory' => 'メモリ',
    'metric_swap' => 'スワップ',
    'metric_processes' => 'プロセス',
    'metric_open_files' => 'オープンファイル',
    'metric_connections' => '接続数',

    'field_diff_path' => 'ファイルパス',
    'placeholder_diff_path' => '/etc/passwd',
    'field_diff_reference_case' => '参照ケース',

    'section_delivery' => '配信',
    'field_delivery_notification' => '通知',
    'field_delivery_email' => 'メール',
    'field_email_addresses' => 'メールアドレス（カンマ区切り）',
    'placeholder_email_addresses' => 'user@example.com, user2@example.com',
    'field_delivery_event' => 'イベント（Sysevent / SIEM）',
    'field_delivery_contact_point' => 'コンタクトポイント',
    'field_contact_point_destination' => '送信先',
    'field_contact_point_webhook_url' => 'Webhook URL',
    'placeholder_contact_point_webhook_url' => 'https://hooks.slack.com/services/...',

    'destination_slack' => 'Slack',
    'destination_pagerduty' => 'PagerDuty（準備中）',
    'destination_msteams' => 'MS Teams（準備中）',
    'destination_opsgenie' => 'OpsGenie（準備中）',
    'destination_googlechat' => 'Google Chat（準備中）',
    'destination_generic_api' => '汎用API（準備中）',
    'destination_snmp_trap' => 'SNMP Trap（準備中）',

    'contact_point_coming_soon' => '現在利用できるのはSlackのみです。他の送信先は準備中です。',
    'contact_point_license_required' => 'コンタクトポイントチャンネルには有効なアプライアンスライセンスが必要です。',
    'contact_point_test' => 'テスト',
    'contact_point_test_success' => 'Slackにテストメッセージを送信しました。',
    'contact_point_test_failure' => 'そのWebhook URLではSlackに接続できませんでした。',

    'severity_INFO' => '情報',
    'severity_WARNING' => '警告',
    'severity_ERROR' => 'エラー',
    'severity_CRITICAL' => '重大',
    'severity_FATAL' => '致命的',

    'validation_email_too_many' => 'メールアドレスは10件以内で入力してください。',
    'validation_email_invalid' => '無効なメールアドレスです: :address',
];
