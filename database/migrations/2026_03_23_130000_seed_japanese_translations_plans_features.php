<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Japanese translations for plan names and descriptions
    private array $planTranslations = [
        'Minimal' => ['ja_name' => 'ミニマル',        'ja_desc' => '基本的な機能の一部にアクセスできます。'],
        'Basic' => ['ja_name' => 'ベーシック',       'ja_desc' => 'すべての基本機能にアクセスできます。'],
        'Team' => ['ja_name' => 'チーム',          'ja_desc' => '最大8名のメンバー、すべての機能にアクセス。'],
        'Enterprise' => ['ja_name' => 'エンタープライズ', 'ja_desc' => '最大20名のメンバーが大規模な共有ボールトですべての機能にアクセス。'],
        'Free' => ['ja_name' => '無料',            'ja_desc' => '21日間フルアクセス無料トライアル'],
        '10GB' => ['ja_name' => '10GB',            'ja_desc' => '10GBボールト増量'],
        '30GB' => ['ja_name' => '30GB',            'ja_desc' => '30GBボールト増量'],
        '50GB' => ['ja_name' => '50GB',            'ja_desc' => '50GBボールト増量'],
        '100GB' => ['ja_name' => '100GB',           'ja_desc' => '100GBボールト増量'],
        '4MToken' => ['ja_name' => '4Mトークン',      'ja_desc' => '400万トークン'],
        '14MToken' => ['ja_name' => '14Mトークン',     'ja_desc' => '1,400万トークン'],
        '30MToken' => ['ja_name' => '30Mトークン',     'ja_desc' => '3,000万トークン'],
        '70MToken' => ['ja_name' => '70Mトークン',     'ja_desc' => '7,000万トークン'],
    ];

    // Japanese translations for feature names (canonical English → Japanese)
    private array $featureNameTranslations = [
        'API access' => 'APIアクセス',
        'Advanced Tools' => '高度なツール',
        'Assistant' => 'アシスタント',
        'Basic Tools' => '基本ツール',
        'Direct Upload' => 'ダイレクトアップロード',
        'ITSM Integration' => 'ITSM連携',
        'Included Tokens' => '付属トークン',
        'Special Tools' => '特別ツール',
        'Support' => 'サポート',
        'Team Size' => 'チームサイズ',
        'Token Store Access' => 'トークンストアアクセス',
        'User Admin' => 'ユーザー管理',
        'Vault Increase Access' => 'ボールト拡張アクセス',
        'Vault Size' => 'ボールトサイズ',
    ];

    // Japanese translations for feature descriptions (exact English → Japanese)
    private array $featureDescTranslations = [
        'sos report command direct upload access.' => 'sosレポートコマンドの直接アップロードアクセス。',
        'External ticket system integration access.' => '外部チケットシステム連携アクセス。',
        'Assistant tokens / month.' => 'アシスタントトークン数/月。',
        'Assistant tokens per account is 8 M / month.' => 'アカウントあたりのアシスタントトークン数は月800万です。',
        'Shared assistant tokens / month.' => '共有アシスタントトークン数/月。',
        'Human email support' => '人によるメールサポート',
        'Single person account.' => '個人アカウント。',
        'Team of individual accounts with individual vaults.' => '個別ボールトを持つ個人アカウントのチーム。',
        'Team sharing the same vault.' => '同一ボールトを共有するチーム。',
        'Role based user administration panel.' => 'ロールベースのユーザー管理パネル。',
        'Storage capacity per vault is 10 GB.' => 'ボールトあたりのストレージ容量は10GBです。',
        'Shared storage capacity.' => '共有ストレージ容量。',
    ];

    public function up(): void
    {
        // Add Japanese translations to plans
        foreach ($this->planTranslations as $enName => $translations) {
            DB::update(
                "UPDATE plans
                 SET name        = json_set(name,        '$.ja', ?),
                     description = json_set(description, '$.ja', ?)
                 WHERE json_extract(name, '$.en') = ?",
                [$translations['ja_name'], $translations['ja_desc'], $enName]
            );
        }

        // Add Japanese translations to feature names
        foreach ($this->featureNameTranslations as $enName => $jaName) {
            DB::update(
                "UPDATE plan_features
                 SET name = json_set(name, '$.ja', ?)
                 WHERE json_extract(name, '$.en') = ?",
                [$jaName, $enName]
            );
        }

        // Add Japanese translations to feature descriptions (only non-empty ones)
        foreach ($this->featureDescTranslations as $enDesc => $jaDesc) {
            DB::update(
                "UPDATE plan_features
                 SET description = json_set(description, '$.ja', ?)
                 WHERE json_extract(description, '$.en') = ?",
                [$jaDesc, $enDesc]
            );
        }
    }

    public function down(): void
    {
        // Remove Japanese keys from plans
        DB::update("UPDATE plans SET name        = json_remove(name,        '$.ja')");
        DB::update("UPDATE plans SET description = json_remove(description, '$.ja')");

        // Remove Japanese keys from plan_features
        DB::update("UPDATE plan_features SET name        = json_remove(name,        '$.ja')");
        DB::update("UPDATE plan_features SET description = json_remove(description, '$.ja')");
    }
};
