<?php

declare(strict_types=1);

namespace App\Services\EyeImages;

use App\Models\{MedicalRecordDocumentation, PatientExam};
use Illuminate\Support\{Carbon, Collection};

/**
 * Lista "quais exames fizeram parte do laudo" em forma legível (PDF do
 * laudo). Um exame, pro médico, é o GRUPO de imagens que a galeria do
 * Gerenciador de Imagens mostra junto — mesma chave de examGroupKey() em
 * Pages/Panel/EyeImages/Index.vue: sessão mesclada (exam_session_id) ou
 * data + equipamento + tipo. Cada grupo vira uma linha com os olhos e a
 * quantidade de imagens.
 *
 * O resumo é CONGELADO na emissão (snapshotForExamIds() →
 * metadata.exam_summary): o laudo assinado — que também sai pelo link público
 * do médico solicitante — não pode mudar porque o exame de origem foi editado
 * depois (lateralidade trocada, exame mesclado/dividido, renomeado, excluído).
 * Antes ele era remontado dos exames A CADA render: trocar o exame de OD para OE
 * imprimia "OE" ao lado da imagem OD já congelada, e nem o clinical_revision
 * nem o hash da assinatura enxergam essa troca (achado via revisão adversarial
 * Codex, 20ª rodada). O retrato guarda valores BRUTOS — data ISO, código de
 * lateralidade —, nunca texto já formatado: data e rótulos dos olhos são
 * localizados no momento de imprimir (present()), no idioma de quem vê.
 */
class ReportExamSummary
{
    /** Chave, dentro de MedicalRecordDocumentation::$metadata, do retrato congelado na emissão. */
    public const SNAPSHOT_KEY = 'exam_summary';

    /**
     * Retrato BRUTO dos exames, pra gravar em metadata.exam_summary no momento
     * em que o laudo é emitido (e o vínculo com os exames é criado).
     *
     * @param list<string> $examIds
     *
     * @return list<array{type: ?string, date: ?string, equipment: ?string, eyes: list<int>, images: int}>
     */
    public function snapshotForExamIds(array $examIds): array
    {
        if ($examIds === []) {
            return [];
        }

        return $this->snapshot(
            PatientExam::query()->whereIn('id', $examIds)->with(['examType', 'equipment'])->get(),
        );
    }

    /**
     * O que o PDF imprime. Do retrato congelado na emissão; documento emitido
     * ANTES do congelamento (sem retrato) cai no resumo ao vivo, com o risco de
     * sempre — não há como congelar retroativamente o que já foi assinado.
     *
     * @return list<array{type: ?string, date: ?string, equipment: ?string, eyes: list<string>, images: int}>
     */
    public function forDocumentation(MedicalRecordDocumentation $documentation): array
    {
        $frozen = $documentation->metadata[self::SNAPSHOT_KEY] ?? null;

        return $this->present(is_array($frozen) ? $frozen : $this->snapshot(
            $documentation->patientExams()->with(['examType', 'equipment'])->get(),
        ));
    }

    /**
     * @param Collection<int, PatientExam> $exams
     *
     * @return list<array{type: ?string, date: ?string, equipment: ?string, eyes: list<string>, images: int}>
     */
    public function summarize(Collection $exams): array
    {
        return $this->present($this->snapshot($exams));
    }

    /**
     * @param Collection<int, PatientExam> $exams
     *
     * @return list<array{type: ?string, date: ?string, equipment: ?string, eyes: list<int>, images: int}>
     */
    private function snapshot(Collection $exams): array
    {
        return $exams
            ->sortBy(fn (PatientExam $e) => ($e->exam_performed_at ?? $e->created_at)?->timestamp)
            ->groupBy(function (PatientExam $e) {
                $date = ($e->exam_performed_at ?? $e->created_at)?->toDateString() ?? '';

                return $e->exam_session_id
                    ? 's:' . $e->exam_session_id
                    : implode('|', [$date, $e->entity_integrator_equipment_id, $e->exam_id]);
            })
            ->map(function (Collection $group) {
                /** @var PatientExam $first */
                $first = $group->first();
                $date  = $first->exam_performed_at ?? $first->created_at;

                return [
                    'type'      => $first->examType?->name,
                    'date'      => $date?->toDateString(),
                    'equipment' => $first->equipment?->name,
                    'eyes'      => $group->map(fn (PatientExam $e) => $this->eyeCode($e->laterality))
                        ->unique()->sort()->values()->all(),
                    'images' => $group->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Retrato (bruto) → linhas prontas pro PDF, com data e rótulos dos olhos no
     * idioma de quem está imprimindo.
     *
     * @param array<int, array<string, mixed>> $snapshot
     *
     * @return list<array{type: ?string, date: ?string, equipment: ?string, eyes: list<string>, images: int}>
     */
    private function present(array $snapshot): array
    {
        return array_values(array_map(fn (array $item) => [
            'type'      => $item['type'] ?? null,
            'date'      => ! empty($item['date']) ? Carbon::parse((string) $item['date'])->isoFormat('L') : null,
            'equipment' => $item['equipment'] ?? null,
            'eyes'      => array_map(fn ($code) => $this->eyeLabel((int) $code), (array) ($item['eyes'] ?? [])),
            'images'    => (int) ($item['images'] ?? 0),
        ], $snapshot));
    }

    /** 1 = OD, 2 = OE, 0 = ambos/não informado. */
    private function eyeCode(?int $laterality): int
    {
        return in_array($laterality, [1, 2], true) ? $laterality : 0;
    }

    private function eyeLabel(int $code): string
    {
        return match ($code) {
            1       => __('eye_images.eye_od'),
            2       => __('eye_images.eye_oe'),
            default => __('eye_images.eye_ao'),
        };
    }
}
