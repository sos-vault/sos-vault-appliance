<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LocalLicense extends Model
{
    protected $fillable = [
        'uuid',
        'customer_id',
        'machine_tokens',
        'seats',
        'features',
        'status',
        'signed_license',
        'issued_at',
        'expires_at',
        'uploaded_by',
        'expiry_event_logged_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (LocalLicense $license): void {
            if (empty($license->uuid)) {
                $license->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'machine_tokens' => 'array',
            'features' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_event_logged_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE'
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }

    /**
     * Most recently uploaded license that is still ACTIVE and not expired.
     * Returns null when nothing is installed or the only installed license has lapsed.
     */
    public static function current(): ?self
    {
        return static::query()
            ->where('status', 'ACTIVE')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();
    }
}
