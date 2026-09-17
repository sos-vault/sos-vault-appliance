<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ITSMProvider extends Model
{
    use HasFactory;

    protected $table = 'itsmproviders';

    protected $fillable = [
        'vid',
        'uid',
        'gid',
        'provider',
        'url',
        'tenenat',
        'client_id',
        'client_secret',
        'user',
        'password',
        'api_key',
        'api_token',
        'customer_field',
        'last_connection',
        'last_download',
    ];

    public static function boot()
    {
        parent::boot();
    }
}
