<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Annotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'vault_id',
        'dir_id',
        'file_id',
        'owner',
        'group',
        'perms',
        'title',
        'status',
        'locked',
        'acetate',
        'expire',
        'subscription_id',
        'plan_id',
        'role_id',
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
}
