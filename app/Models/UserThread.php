<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'gid',
        'thread_id',
        'uploadFiles',
        'wordLimit',
    ];
}
