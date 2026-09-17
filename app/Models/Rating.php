<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rating extends Model {

    protected $fillable = [
        'rating',
        'owner',
        'group',
        'question',
        'comments',
        'status',     // ['ACTIVE', 'INACTIVE','REJECTED','PEND','DONE'])->default('PEND');
    ];

    public static function boot() {
        parent::boot();
        /*
        self::creating(function($model){
            $model->slug = Str::lower(Str::slug($model->name));
        });
        */
    }
}
