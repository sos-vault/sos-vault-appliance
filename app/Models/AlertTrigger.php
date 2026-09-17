<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertTrigger extends Model
{
    use HasFactory;

    protected $fillable = [
        'alert_id',
        'case_id',
        'vault_id',
        'dir_id',
        'matched_path',
        'detail',
        'delivered_channels',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'delivered_channels' => 'array',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }
}
