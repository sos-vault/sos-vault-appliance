<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseVerification extends Model
{
    protected $fillable = [
        'user_id',
        'file_path',
        'status',
        'machine_tokens',
        'requirements_check',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'machine_tokens' => 'array',
            'requirements_check' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPassed(): bool
    {
        return $this->status === 'passed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }
}
