<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'secured',
        'sosreport',
        'label',
        'host',
        'machine_id',
        'hostname',
        'case',
        'date',
        'sosid',
        'gpg',
        'compression',
        'tar',
        'obfuscated',
        'path',
        'serial',
        'customer',
        'version',
        'owner',
        'group',
        'perms',
        'link',
        'file_id',
        'vault_id',
        'status',
        'fstatus',
        'description',
        'root_cause',
        'recommendation',
        'os_version',
        'sos_version',
        'os_icon',
        'sort',
        'is_public',
        'self_hosted_user_id',
        'sha256',
    ];

    public function selfHostedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'self_hosted_user_id');
    }

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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner');
    }

    /**
     * One row per host, grouped by machine_id with the filename-derived
     * host as fallback for reports that carry no /etc/machine-id.
     * Scoping matches the cases page: own group OR public, minus hidden.
     */
    public static function fleetQuery(int $gid, int $uid): Builder
    {
        return static::query()
            ->selectRaw("
                MIN(id) as id,
                COALESCE(NULLIF(machine_id, ''), host) as fleet_key,
                MAX(COALESCE(NULLIF(hostname, ''), host)) as display_hostname,
                MAX(machine_id) as machine_id,
                MAX(os_version) as os_version,
                MAX(os_icon) as os_icon,
                COUNT(*) as report_count,
                MIN(date) as first_seen,
                MAX(date) as last_seen
            ")
            ->where(fn ($q) => $q->where('group', $gid)->orWhere('is_public', true))
            ->whereNotIn('id', fn ($q) => $q->select('case_id')->from('user_hidden_cases')->where('user_id', $uid))
            ->groupByRaw("COALESCE(NULLIF(machine_id, ''), host)");
    }

    /**
     * All of one host's reports: cases matching the fleet key either by
     * machine_id or, for identity-less reports, by the filename host.
     */
    public static function fleetHostQuery(string $fleetKey, int $gid, int $uid): Builder
    {
        return static::query()
            ->where(function ($q) use ($fleetKey) {
                $q->where('machine_id', $fleetKey)
                    ->orWhere(function ($q2) use ($fleetKey) {
                        $q2->where(fn ($q3) => $q3->whereNull('machine_id')->orWhere('machine_id', ''))
                            ->where('host', $fleetKey);
                    });
            })
            ->where(fn ($q) => $q->where('group', $gid)->orWhere('is_public', true))
            ->whereNotIn('id', fn ($q) => $q->select('case_id')->from('user_hidden_cases')->where('user_id', $uid));
    }
}
