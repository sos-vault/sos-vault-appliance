<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Vault extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_vault',
        'device',
        'header_file',
        'key',
        'status',
        'owner',
        'group',
        'perms',
        'shared_status',
        'description',
        'subscription_id',
        'plan_id',
        'role_id',
        'current_size',
        'plan_size',
        'last_open',
        'last_close',
        'newkey',
        'bookmarks',
        'always_open',
    ];

    public static function boot()
    {
        parent::boot();

        /*
        self::creating(function($model){
            $model->slug = Str::lower(Str::slug($model->name));
        });
        */
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
