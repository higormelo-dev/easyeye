<?php

declare(strict_types=1);

namespace App\Services\Surgery\PostOp;

use App\Enums\Surgery\{SurgeryDocumentType, SurgeryEye, SurgicalCaseStatus};
use App\Exceptions\AllergyConflictException;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\{Medicine, Patient, SurgeryType};
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase};
use App\Services\Surgery\{SurgeryDocumentIssueService, SurgicalCaseService};
use App\Support\ClinicalSafety\PrescriptionAllergyGuard;
use Illuminate\Support\Facades\DB;

/**
 * Colírios e orientações pós-operatórias (ADR 0003, D4/D12): documento
 * CONGELADO do tipo `post_op_guidance` — nunca um MedicalRecordDocumentation.
 *
 * É uma PRESCRIÇÃO: ato do médico com cadastro ativo, e cada colírio passa
 * pela MESMA trava de alergia da receita do prontuário
 * (PrescriptionAllergyGuard sobre as alergias vigentes do paciente — que
 * incluem as do retrato do caso e as registradas depois). Conflito sem motivo
 * recusa (AllergyConflictException, 422 com a alergia e a reação); com motivo,
 * segue e o motivo fica no documento (`safety_override`) e na auditoria.
 *
 * O texto das orientações nasce de um padrão por categoria de cirurgia
 * (lang), é editável antes de emitir e é sanitizado; os colírios saem
 * estruturados (nome, posologia, duração, desmame, olho) e entram no conteúdo
 * congelado como tabela. Reemitir cria nova linha e aposenta a anterior.
 */
class PostOpGuidanceService
{
    /** Categoria de SurgeryType → chave do texto padrão (lang surgery_postop.guidance.categories). */
    private const CATEGORY_KEYS = [
        0  => 'other',
        1  => 'cataract',
        2  => 'refractive',
        3  => 'retina',
        4  => 'glaucoma',
        5  => 'cornea',
        6  => 'pterygium',
        7  => 'oculoplasty',
        8  => 'lacrimal',
        9  => 'strabismus',
        10 => 'orbit',
        11 => 'trauma',
        12 => 'removal',
        13 => 'laser',
    ];

    public function __construct(
        private readonly SurgeryDocumentIssueService $documents,
        private readonly SurgicalCaseService $cases,
        private readonly PrescriptionAllergyGuard $allergyGuard,
    ) {
    }

    /**
     * Texto padrão do caso (HTML) e chave da categoria — ponto de partida da
     * tela; o médico edita antes de emitir.
     *
     * @return array{category: string, html: string}
     */
    public function defaults(SurgicalCase $case): array
    {
        $category = self::CATEGORY_KEYS[(int) ($case->surgeryType?->category ?? 0)] ?? 'other';

        $items = array_values(array_filter((array) trans("surgery_postop.guidance.categories.{$category}"), 'is_string'));
        $items = array_merge($items, array_values(array_filter((array) trans('surgery_postop.guidance.common'), 'is_string')));

        $html = '<ul>' . implode('', array_map(fn (string $line) => '<li>' . e($line) . '</li>', $items)) . '</ul>'
            . '<p><strong>' . e((string) __('surgery_postop.guidance.warning_title')) . '</strong> '
            . e((string) __('surgery_postop.guidance.warning_text')) . '</p>';

        return ['category' => $category, 'html' => $html];
    }

    /**
     * Emite o documento. `$data`: guidance_html, eyedrops[{name, medicine_id,
     * posology, duration, taper, eye}], allergy_override_reason.
     *
     * @param array<string, mixed> $data
     *
     * @throws AllergyConflictException
     */
    public function issue(SurgicalCase $case, array $data): SurgeryDocumentIssue
    {
        $eyedrops = $this->normalizeEyedrops($case, (array) ($data['eyedrops'] ?? []));
        $guidance = trim((string) ($data['guidance_html'] ?? ''));

        if ($eyedrops === [] && strip_tags($guidance) === '') {
            throw SurgeryRuleException::invalid('surgery_postop.errors.guidance_empty');
        }

        return DB::transaction(function () use ($case, $eyedrops, $guidance, $data): SurgeryDocumentIssue {
            $locked = $this->cases->lock((string) $case->getKey());

            if (! in_array($locked->status, [SurgicalCaseStatus::Performed, SurgicalCaseStatus::Discharged], true)) {
                throw SurgeryRuleException::state('surgery_postop.errors.guidance_needs_performed', ['status' => $locked->status->label()]);
            }

            // A trava vale para o DOCUMENTO inteiro: os colírios estruturados (catálogo, posologia,
            // duração, desmame) e o texto livre das orientações. Medicação escrita só no texto
            // ("pomada de neomicina 3x ao dia") com a lista estruturada vazia passava direto.
            $patient  = Patient::query()->where('entity_id', $locked->entity_id)->whereKey($locked->patient_id)->firstOrFail();
            $override = $this->allergyGuard->guard(
                $patient,
                $this->allergyText($eyedrops, $guidance),
                array_map(fn (array $d) => ['medicine_id' => $d['medicine_id'], 'name' => $d['name']], $eyedrops),
                $data['allergy_override_reason'] ?? null,
            );

            $issue = $this->documents->issue(
                $locked,
                SurgeryDocumentType::PostOpGuidance,
                (string) __('surgery_postop.guidance.title'),
                $this->content($eyedrops, $guidance),
                [
                    'eyedrops'        => $eyedrops,
                    'category'        => $this->defaults($locked)['category'],
                    'safety_override' => $override,
                ],
                scope: 'main',
            );

            return $issue;
        });
    }

    /**
     * Colírios estruturados, validados: nome obrigatório, posologia obrigatória,
     * olho dentro do olho operado, medicamento (se informado) do catálogo.
     *
     * @param array<int, mixed> $rows
     *
     * @return list<array<string, string|null>>
     */
    private function normalizeEyedrops(SurgicalCase $case, array $rows): array
    {
        $out = [];

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name     = trim((string) ($row['name'] ?? ''));
            $posology = trim((string) ($row['posology'] ?? ''));

            if ($name === '' && $posology === '') {
                continue;
            }

            if ($name === '' || $posology === '') {
                throw SurgeryRuleException::invalid('surgery_postop.errors.eyedrop_incomplete');
            }

            $eye = SurgeryEye::tryFrom((string) ($row['eye'] ?? $case->eye->value));

            if ($eye === null || ($case->eye !== SurgeryEye::Both && $eye !== $case->eye)) {
                throw SurgeryRuleException::invalid('surgery_postop.errors.eyedrop_eye_invalid');
            }

            $medicineId = filled($row['medicine_id'] ?? null) ? (string) $row['medicine_id'] : null;

            if ($medicineId !== null) {
                $ok = Medicine::query()
                    ->where(fn ($q) => $q->where('entity_id', $case->entity_id)->orWhereNull('entity_id'))
                    ->whereKey($medicineId)
                    ->exists();

                if (! $ok) {
                    throw SurgeryRuleException::invalid('surgery_postop.errors.medicine_not_found');
                }
            }

            $out[] = [
                'name'        => mb_substr($name, 0, 150),
                'medicine_id' => $medicineId,
                'posology'    => mb_substr($posology, 0, 255),
                'duration'    => filled($row['duration'] ?? null) ? mb_substr(trim((string) $row['duration']), 0, 120) : null,
                'taper'       => filled($row['taper'] ?? null) ? mb_substr(trim((string) $row['taper']), 0, 255) : null,
                'eye'         => $eye->value,
            ];
        }

        return $out;
    }

    /**
     * Tudo que o documento diz, para a trava de alergia: cada colírio com posologia, duração e
     * desmame, e o texto das orientações.
     *
     * @param list<array<string, string|null>> $eyedrops
     */
    private function allergyText(array $eyedrops, string $guidance): string
    {
        $lines = array_map(
            fn (array $d) => trim(implode(' ', array_filter([$d['name'], $d['posology'], $d['duration'], $d['taper']], fn ($part) => filled($part)))),
            $eyedrops,
        );

        $lines[] = $guidance;

        return implode("\n", array_filter($lines, fn (string $line) => trim($line) !== ''));
    }

    /** HTML congelado: tabela de colírios + orientações (escapado aqui, purificado na emissão). @param list<array<string, string|null>> $eyedrops */
    private function content(array $eyedrops, string $guidance): string
    {
        $html = '';

        if ($eyedrops !== []) {
            $html .= '<h3>' . e((string) __('surgery_postop.guidance.eyedrops_title')) . '</h3>'
                . '<table border="1" cellpadding="4" cellspacing="0" style="width:100%"><thead><tr>'
                . '<th>' . e((string) __('surgery_postop.guidance.col_eyedrop')) . '</th>'
                . '<th>' . e((string) __('surgery_postop.guidance.col_eye')) . '</th>'
                . '<th>' . e((string) __('surgery_postop.guidance.col_posology')) . '</th>'
                . '<th>' . e((string) __('surgery_postop.guidance.col_duration')) . '</th>'
                . '<th>' . e((string) __('surgery_postop.guidance.col_taper')) . '</th>'
                . '</tr></thead><tbody>';

            foreach ($eyedrops as $drop) {
                $html .= '<tr><td>' . e($drop['name']) . '</td><td>' . e(SurgeryEye::from((string) $drop['eye'])->label()) . '</td>'
                    . '<td>' . e((string) $drop['posology']) . '</td><td>' . e((string) ($drop['duration'] ?? '')) . '</td>'
                    . '<td>' . e((string) ($drop['taper'] ?? '')) . '</td></tr>';
            }

            $html .= '</tbody></table>';
        }

        if (strip_tags($guidance) !== '') {
            $html .= '<h3>' . e((string) __('surgery_postop.guidance.orientations_title')) . '</h3>' . $guidance;
        }

        return $html;
    }

    /** Categoria de cirurgia do caso (rótulo para a tela). */
    public function categoryLabel(SurgicalCase $case): ?string
    {
        return $case->surgeryType !== null ? (SurgeryType::$categories[(int) $case->surgeryType->category] ?? null) : null;
    }
}
