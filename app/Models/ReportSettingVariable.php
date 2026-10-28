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
        'label',
        'group',
        'source_type',
        'source_field',
        'default_value',
        'sort_order',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active'     => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(ReportSettingContent::class, 'report_setting_content_id');
    }
}
