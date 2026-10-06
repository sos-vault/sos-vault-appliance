<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalysisRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'case_id',
        'vault_id',
        'dir_id',
        'status',
        'triggered_by',
        'started_at',
        'completed_at',
        'error_message',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ComplianceFinding::class);
    }

    public function scopeLatestForCase(Builder $query, $caseId): Builder
    {
        return $query->where('case_id', $caseId)->latest();
    }

    public function markRunning(): void
    {
        $this->status = 'running';
        $this->started_at = now();
        $this->save();
    }

    public function markCompleted(array $summary): void
    {
        $this->status = 'completed';
        $this->completed_at = now();
        $this->summary = $summary;
        $this->save();
    }

    public function markFailed(string $message): void
    {
        $this->status = 'failed';
        $this->completed_at = now();
        $this->error_message = $message;
        $this->save();
    }
}
