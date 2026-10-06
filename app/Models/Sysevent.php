<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sysevent extends Model
{
    use HasFactory;

    protected $table = 'sysevents';

    protected $fillable = [
        'vault_id',
        'dir_id',
        'case_id',
        'status',
        'type',
        'class',
        'payload',
        'owner',
        'group',
        'ip',
        'iso_code',
        'country',
        'state',
        'city',
        'timezone',
    ];

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner');
    }
}
