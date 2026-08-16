<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ExamSource;
use Illuminate\Http\Request;

/**
 * Filtros do Gerenciador de Imagens (EyeImagesController), normalizados a
 * partir do request. Faz a allowlist de tudo que chega cru do cliente antes
 * de virar cláusula SQL — ver EyeImagesController::queryPatients().
 */
final readonly class EyeImageFilters
{
    private const array EYES = ['od', 'oe', 'ao'];

    private const array STATUSES = ['laudado'];

    public function __construct(
        public string $period = 'hoje',
        public string $search = '',
        public ?string $eye = null,
        public ?string $examTypeId = null,
        public ?string $equipmentId = null,
        public ?string $doctorId = null,
        public ?string $cidCode = null,
        public ?string $customDiagnosisId = null,
        public ?string $status = null,
        public ?string $source = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $eye    = $request->string('eye')->trim()->lower()->value();
        $status = $request->string('status')->trim()->lower()->value();
        $source = $request->string('source')->trim()->lower()->value();

        return new self(
            period: $request->string('period', 'hoje')->trim()->value() ?: 'hoje',
            search: $request->string('search')->trim()->value(),
            eye: in_array($eye, self::EYES, true) ? $eye : null,
            examTypeId: $request->string('exam_type_id')->trim()->value() ?: null,
            equipmentId: $request->string('equipment_id')->trim()->value() ?: null,
            doctorId: $request->string('doctor_id')->trim()->value() ?: null,
            cidCode: $request->string('cid_code')->trim()->value() ?: null,
            customDiagnosisId: $request->string('custom_diagnosis_id')->trim()->value() ?: null,
            status: in_array($status, self::STATUSES, true) ? $status : null,
            source: ExamSource::tryFrom($source) !== null ? $source : null,
            page: max(1, $request->integer('page', 1)),
            perPage: min(100, max(10, $request->integer('per_page', 25))),
        );
    }

    /**
     * Eco pro front — permite exibir os filtros ativos e, futuramente,
     * espelhar em history.replaceState() para sobreviver a refresh/bookmark.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period'              => $this->period,
            'search'              => $this->search,
            'eye'                 => $this->eye,
            'exam_type_id'        => $this->examTypeId,
            'equipment_id'        => $this->equipmentId,
            'doctor_id'           => $this->doctorId,
            'cid_code'            => $this->cidCode,
            'custom_diagnosis_id' => $this->customDiagnosisId,
            'status'              => $this->status,
            'source'              => $this->source,
            'page'                => $this->page,
            'per_page'            => $this->perPage,
        ];
    }
}
