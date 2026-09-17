<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    protected $fillable = ['from_path', 'to_path', 'status_code', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'status_code' => 'integer',
        ];
    }
}
