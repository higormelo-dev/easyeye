<?php

declare(strict_types=1);

namespace App\Domains\CareGuidance\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PDF anexo de um modelo de orientação (preparo, termo): até 5 por modelo, 5 MB cada. O arquivo mora em disco privado;
 * a emissão COPIA o arquivo para a pasta da emissão (o modelo pode mudar depois sem tocar no que foi entregue).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $template_id
 * @property string $original_name
 * @property string $path
 * @property int    $size_bytes
 * @property string $sha256
 */
class CareGuidanceAttachment extends Model
{
    use BelongsToEntity;
    use HasUuids;

    protected $table = 'care_guidance_attachments';

    protected $fillable = ['entity_id', 'template_id', 'original_name', 'path', 'size_bytes', 'sha256', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CareGuidanceTemplate::class, 'template_id');
    }
}
