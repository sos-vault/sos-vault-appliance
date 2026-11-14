<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = ['title', 'description', 'body'];

    public function users()
    {
        return $this->belongsToMany('Wave\User');
    }
}
