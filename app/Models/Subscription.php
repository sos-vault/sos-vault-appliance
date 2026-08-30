<?php

namespace App\Models;

use Wave\Plan;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $table = 'paddle_subscriptions';

    protected $fillable = [
        'subscription_id',
        'plan_id',
        'user_id',
        'status',
        'update_url',
        'cancel_url',
        'cancelled_at',
        'created_at',
        'updated_at',
        'last_payment_at',
        'next_payment_at',
        'delete_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'created_at' => 'datetime',
        'last_payment_at' => 'datetime',
        'next_payment_at' => 'datetime',
        'delete_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public static function boot()
    {
        parent::boot();

    }

}
