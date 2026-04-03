<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ReportSettingContent extends Model
{
    use HasUuids;

    protected $fillable = [
        'report_setting_id',
        'type',
        'label',
        'content',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function reportSetting(): BelongsTo
    {
        return $this->belongsTo(ReportSetting::class);
    }

    public function variables(): HasMany
    {
        return $this->hasMany(ReportSettingVariable::class);
    }
}
