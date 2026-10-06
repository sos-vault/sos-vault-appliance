<?php

return [
    // Fleet list page
    'table_title' => 'フリート',
    'table_description' => 'sosレポートをアップロードした全システムを、ホストID（machine-id）でグループ化して表示します。',
    'col_hostname' => 'ホスト名',
    'col_machine_id' => 'マシンID',
    'col_os' => 'OS',
    'col_reports' => 'レポート数',
    'col_first_seen' => '初回確認',
    'col_last_seen' => '最終確認',
    'machine_id_unknown' => '不明',
    'empty_heading' => 'システムはまだありません',
    'empty_description' => 'sosレポートをアップロードすると、そのホストがここに表示されます。',

    // Host timeline page
    'host_title' => 'システム履歴 — :host',
    'host_description_machine_id' => 'machine-id :machine_id',
    'host_description_no_machine_id' => 'ファイル名由来のホスト名でグループ化されています — これらのレポートに machine-id は見つかりませんでした。',
    'col_date' => '日付',
    'col_case' => 'ケース',
    'col_label' => 'ラベル',
    'col_sos_version' => 'sosバージョン',
    'col_status' => 'ステータス',
    'col_sha256' => 'SHA-256',
    'host_empty_heading' => 'このシステムのレポートはありません',
    'host_empty_description' => 'このホストIDに一致する閲覧可能なレポートはありません。',
    'action_browse' => 'レポートを閲覧',
    'action_summary' => 'サマリー',
    'action_compare' => '比較',
    'back_to_fleet' => 'フリートに戻る',
];
