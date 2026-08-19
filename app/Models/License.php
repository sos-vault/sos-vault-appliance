<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Wave\Subscription;

class License extends Model
{
    protected $fillable = [
        'uuid',
        'customer_id',
        'subscription_id',
        'machine_tokens',
        'seats',
        'features',
        'status',
        'signed_license',
        'revocation_reason',
        'issued_at',
        'expires_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (License $license): void {
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
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'EXPIRED');
    }

    public function scopeRevoked(Builder $query): Builder
    {
        return $query->where('status', 'REVOKED');
    }

    public function scopeForCustomer(Builder $query, int $userId): Builder
    {
        return $query->where('customer_id', $userId);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function isExpired(): bool
    {
        return $this->status === 'EXPIRED';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'REVOKED';
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->isActive() && $this->expires_at->diffInDays(now()) <= $days;
    }

    public function hasMachineToken(string $token): bool
    {
        return in_array($token, $this->machine_tokens ?? [], true);
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }
}
