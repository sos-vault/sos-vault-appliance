<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    protected $fillable = [
        'user_id',
        'group_id',
        'case_directory_id',
        'case_id',
        'title',
        'is_group',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'last_activity_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id');
    }

    public function touchActivity(): void
    {
        $this->last_activity_at = now();
        $this->save();
    }
}
