<?php

namespace Wave;

use App\Models\PlanFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Translatable\HasTranslations;

class Plan extends Model
{
    use HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $guarded = [];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'features',
        'role_id',
        'default',
        'active',
        'price',
        'monthly_price_id',
        'monthly_price',
        'yearly_price_id',
        'yearly_price',
        'onetime_price_id',
        'onetime_price',
        'trial_days',
        'created_at',
        'updated_at',
        'type',
        'product_id',
        'status',
        'display_order',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function boot()
    {
        parent::boot();

        self::creating(function ($model) {
            $model->slug = Str::lower(Str::slug($model->name));
        });
    }

    /** Query by the English (canonical) name stored in the JSON column. */
    public function scopeWhereEnglishName(Builder $query, string $name): Builder
    {
        return $query->whereRaw("json_extract(name, '$.en') = ?", [$name]);
    }

    public function scopeWhereEnglishNameNot(Builder $query, string $name): Builder
    {
        return $query->whereRaw("json_extract(name, '$.en') != ?", [$name]);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function planFeatures(): HasMany
    {
        return $this->hasMany(PlanFeature::class)->orderBy('sort_order');
    }

    public function getDiskSize(): string
    {
        $feature = $this->planFeatures()->whereRaw("json_extract(name, '$.en') = ?", ['Vault Size'])->first();
        if ($feature) {
            return $feature->amount.' '.$feature->units;
        }

        $features = json_decode($this->features);

        if (! is_object($features) || ! isset($features->{'Vault Size'})) {
            return '0 GB';
        }

        return $features->{'Vault Size'}->amount.' '.$features->{'Vault Size'}->units;
    }

    public function getTokenAmount(): string
    {
        $feature = $this->planFeatures()->whereRaw("json_extract(name, '$.en') = ?", ['Included Tokens'])->first();
        if ($feature) {
            return $feature->amount.' '.$feature->units;
        }

        $features = json_decode($this->features);

        if (! is_object($features) || ! isset($features->{'Included Tokens'})) {
            return '0 M';
        }

        return $features->{'Included Tokens'}->amount.' '.$features->{'Included Tokens'}->units;
    }

    public function getFeatureDescription(string $feature): string
    {
        $planFeature = $this->planFeatures()->whereRaw("json_extract(name, '$.en') = ?", [$feature])->first();
        if ($planFeature) {
            return $planFeature->description ?? '';
        }

        $features = json_decode($this->features);

        return $features->{$feature}->description;
    }

    public function hasFeature(string $feature): bool
    {
        $planFeature = $this->planFeatures()->whereRaw("json_extract(name, '$.en') = ?", [$feature])->first();
        if ($planFeature) {
            return (bool) $planFeature->enabled;
        }

        $features = json_decode($this->features);

        return isset($features->{$feature}) ? (bool) $features->{$feature}->enable : false;
    }
}
