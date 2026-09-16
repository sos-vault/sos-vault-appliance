<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceFinding extends Model
{
    use HasFactory;

    public const RULESETS = ['cis', 'stig', 'docker_bench', 'exposure'];

    public const STATUSES = ['pass', 'fail', 'not_applicable', 'error'];

    public const SEVERITIES = ['INFO', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    protected $fillable = [
        'analysis_run_id',
        'case_id',
        'vault_id',
        'ruleset',
        'rule_id',
        'title',
        'description',
        'severity',
        'status',
        'category',
        'matched_path',
        'evidence',
        'remediation',
        'reference_url',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class, 'analysis_run_id');
    }

    public function scopeFailing(Builder $query): Builder
    {
        return $query->where('status', 'fail');
    }

    public function scopeForCase(Builder $query, $caseId): Builder
    {
        return $query->where('case_id', $caseId);
    }

    public function scopeRuleset(Builder $query, $name): Builder
    {
        return $query->where('ruleset', $name);
    }
}
