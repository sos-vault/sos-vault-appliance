<?php

return [
    'tool_name' => 'Compliance',
    'tool_title' => 'コンプライアンスと露出',
    'tool_tooltip' => 'コンプライアンスと露出タブを開く',
    'tool_description' => 'CIS、DOD STIG、Docker Bench、および露出の検出結果を新規sosレポートのアップロードごとに自動評価し、失敗した各チェックに対する修復ガイダンスを提供します。',

    'table_empty_heading' => 'まだコンプライアンス分析がありません',
    'table_empty_description' => '分析を実行して、このケースをCIS、STIG、Docker Bench、および露出チェックに照らして評価してください。',

    'ruleset_cis' => 'CIS',
    'ruleset_stig' => 'STIG',
    'ruleset_docker_bench' => 'Docker Bench',
    'ruleset_exposure' => '露出',

    'column_rule_id' => 'ルール',
    'column_title' => 'タイトル',
    'column_category' => 'カテゴリ',
    'column_severity' => '重大度',
    'column_status' => 'ステータス',
    'column_matched_path' => '一致したパス',

    'filter_ruleset' => 'ルールセット',
    'filter_severity' => '重大度',
    'filter_status' => 'ステータス',

    'severity_INFO' => '情報',
    'severity_LOW' => '低',
    'severity_MEDIUM' => '中',
    'severity_HIGH' => '高',
    'severity_CRITICAL' => '重大',

    'status_pass' => '合格',
    'status_fail' => '不合格',
    'status_not_applicable' => 'N/A',
    'status_error' => 'エラー',

    'action_run_now' => '今すぐ分析を実行',
    'run_now_success' => 'コンプライアンス分析が完了しました。',
    'run_now_case_not_found' => '分析対象のケースが見つかりませんでした。',

    'field_description' => '説明',
    'field_remediation' => '修復方法',
    'field_reference_url' => '参照',
];
