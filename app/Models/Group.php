<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Wave\Plan;

class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    protected $fillable = ['name', 'owner_id', 'plan_id', 'vault_id', 'max_members'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'group_id');
    }

    /** True when the member slots are full (manager occupies 1 of max_members). */
    public function isFull(): bool
    {
        return $this->members()->count() >= ($this->max_members - 1);
    }
}
