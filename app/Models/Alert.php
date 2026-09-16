<?php

namespace App\Models;

use App\Services\AlertContactPointEncryptionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alert extends Model
{
    use HasFactory;

    public const SEVERITIES = ['INFO', 'WARNING', 'ERROR', 'CRITICAL', 'FATAL'];

    public const TYPES = ['grep', 'levels', 'diff'];

    public const LEVELS_METRICS = ['cpu', 'disk', 'inodes', 'memory', 'swap', 'processes', 'open_files', 'connections'];

    public const CONTACT_POINT_DESTINATIONS = ['slack', 'pagerduty', 'msteams', 'opsgenie', 'googlechat', 'generic_api', 'snmp_trap'];

    /** Event-log type per alert type — matches the doc-comment enum in addEvent(). */
    public const EVENT_TYPES = [
        'grep' => 'ALERT_GRP',
        'levels' => 'ALERT_THRESH',
        'diff' => 'ALERT_DIFF',
    ];

    protected $fillable = [
        'vault_id',
        'user_id',
        'name',
        'description',
        'severity',
        'enabled',
        'type',
        'grep_path',
        'grep_regex',
        'grep_match_mode',
        'levels_metric',
        'levels_threshold',
        'levels_mount_path',
        'diff_path',
        'diff_reference_case_id',
        'notify_enabled',
        'email_enabled',
        'email_addresses',
        'event_enabled',
        'contact_point_enabled',
        'contact_point_destination',
        'contact_point_webhook_url',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'levels_threshold' => 'decimal:2',
            'notify_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'event_enabled' => 'boolean',
            'contact_point_enabled' => 'boolean',
        ];
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class, 'vault_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function referenceCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'diff_reference_case_id');
    }

    public function triggers(): HasMany
    {
        return $this->hasMany(AlertTrigger::class);
    }

    /** Plaintext accessor for the encrypted Slack webhook URL column. */
    public function getContactPointWebhookUrlAttribute(): string
    {
        return app(AlertContactPointEncryptionService::class)->decrypt($this->contact_point_webhook_url_encrypted);
    }

    public function setContactPointWebhookUrlAttribute(?string $value): void
    {
        $this->attributes['contact_point_webhook_url_encrypted'] = app(AlertContactPointEncryptionService::class)->encrypt((string) $value);
    }

    /** Comma-separated email_addresses, split and trimmed. */
    public function emailAddressList(): array
    {
        return collect(explode(',', (string) $this->email_addresses))
            ->map(fn ($e) => trim($e))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The shared param set every delivery channel's body is built from:
     * name, description, severity, pathname, metric, threshold, reference case.
     */
    public function deliveryPayload(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'severity' => $this->severity,
            'pathname' => match ($this->type) {
                'grep' => $this->grep_path,
                'diff' => $this->diff_path,
                default => null,
            },
            'metric' => $this->levels_metric,
            'threshold' => $this->levels_threshold !== null ? (string) $this->levels_threshold : null,
            'mount_path' => $this->levels_mount_path,
            'match_mode' => $this->grep_match_mode,
            'regex' => $this->grep_regex,
            'reference_case_id' => $this->diff_reference_case_id,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
