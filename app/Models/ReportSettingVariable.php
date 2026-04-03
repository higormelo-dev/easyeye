<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSettingVariable extends Model
{
    use HasUuids;

    protected $fillable = [
        'report_setting_content_id',
        'placeholder',
        'source_type',
        'source_field',
        'default_value',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(ReportSettingContent::class, 'report_setting_content_id');
    }
}
