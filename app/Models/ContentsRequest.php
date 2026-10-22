<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentsRequest extends Model
{
    use HasFactory;

    // contentsRequest table is used to share documents as it provides the assosiation needed between a document
    // identifier (vault, dir, case and file) with the shared url as well as expiration and permissions controls.

    protected $fillable = [
        'vault_id',
        'dir_id',
        'file_id',
        'case_id',
        'tool_name',
        'owner',
        'group',
        'perms',
        'status',
        'comments',
        'expire',
        'subscription_id',
        'plan_id',
        'role_id',
        'url',
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
