<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_type',
        'module_id',
        'name',
        'version',
        'description',
        'author',
        'provider',
        'tool_name',
        'tool_slug',
        'tool_icon',
        'is_enabled',
        'installed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'installed_at' => 'datetime',
        ];
    }

    /** @param Builder<Module> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    /** @param Builder<Module> $query */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('package_type', $type);
    }
}
