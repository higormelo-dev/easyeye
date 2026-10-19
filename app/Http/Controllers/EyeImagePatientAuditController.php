<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{AuditLog, Patient, PatientExam, User};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Histórico (somente leitura) do cadastro do paciente e dos exames de imagem
 * dele, a partir da trilha que já existe (audit_logs, via Auditable). Aparece
 * no Gerenciador de Imagens para quem tem acesso a Pacientes.
 *
 * LGPD: a tela mostra O QUE mudou e QUANDO/QUEM, não o conteúdo clínico.
 * Só campos das allowlists abaixo saem com valor; qualquer outro campo sai só
 * com o nome (`redacted: true`, old/new nulos) — ex.: `observation` do exame
 * nunca é devolvida. Eventos created/deleted/restored não devolvem o retrato
 * do registro (`changes: []`). IP e user-agent nunca saem.
 */
class EyeImagePatientAuditController extends Controller
{
    /** Tamanho da página — fixo, o cliente não escolhe. */
    private const int PER_PAGE = 20;

    /** Campos do cadastro cujo valor pode aparecer (atributos de triagem/estado, sem dado pessoal). */
    private const array PATIENT_FIELDS = [
        'active', 'priority_rating', 'covenant_id', 'covenant_plan_id', 'skin_id', 'iris_id',
        'allergies_denied_at', 'merged_into_id',
    ];

    /** Campos do evento `merged` (gravado pelo PatientMergeService no cadastro mantido). */
    private const array MERGED_FIELDS = ['source_id', 'source_code', 'moved', 'renumbered'];

    /** Campos do exame cujo valor pode aparecer (metadado de captura/triagem; nunca observation/diagnóstico). */
    private const array EXAM_FIELDS = [
        'code', 'active', 'laterality', 'quality_rating', 'urgent', 'exam_session_id', 'exam_id', 'doctor_id',
        'schedule_id', 'source', 'exam_performed_at',
    ];

    public function __invoke(Patient $patient): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        abort_unless($entityId !== '' && (string) $patient->entity_id === $entityId, 404);

        $examIds = PatientExam::query()->where('patient_id', $patient->id)->select('id');

        // `entity_id` nulo cobre ação de integrador (sem sessão); `auditable_id` é global, então o
        // filtro por paciente/exames dele é o que impede vazar outra clínica.
        $paginator = AuditLog::query()
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('auditable_type', Patient::class)->where('auditable_id', $patient->id))
                ->orWhere(fn ($e) => $e->where('auditable_type', PatientExam::class)->whereIn('auditable_id', $examIds)))
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $logs = $paginator->getCollection();

        $actors = User::withTrashed()
            ->whereIn('id', $logs->pluck('user_id')->filter()->unique()->values())
            ->pluck('name', 'id');
        $examCodes = PatientExam::query()
            ->whereIn('id', $logs->where('auditable_type', PatientExam::class)->pluck('auditable_id')->unique()->values())
            ->pluck('code', 'id');

        return response()->json([
            'entries' => $logs->map(fn (AuditLog $log): array => $this->entry($log, $patient, $actors, $examCodes))->values()->all(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * @param Collection<string, string|null> $actors
     * @param Collection<string, string|null> $examCodes
     *
     * @return array<string, mixed>
     */
    private function entry(AuditLog $log, Patient $patient, Collection $actors, Collection $examCodes): array
    {
        $isExam = $log->auditable_type === PatientExam::class;

        return [
            'id'          => (string) $log->id,
            'at'          => $log->created_at?->toIso8601String(),
            'event'       => (string) $log->event,
            'target'      => $isExam ? 'exam' : 'patient',
            'target_code' => $isExam ? $examCodes->get($log->auditable_id) : $patient->code,
            'actor_name'  => $log->user_id !== null ? $actors->get($log->user_id) : null,
            'actor_kind'  => $this->actorKind($log),
            'changes'     => $this->changes($log, $isExam),
        ];
    }

    private function actorKind(AuditLog $log): string
    {
        if ($log->user_id !== null) {
            return 'user';
        }

        return $log->actor_type === 'integrator' || $log->entity_user_integrator_id !== null ? 'integrator' : 'system';
    }

    /**
     * @return list<array{field: string, old: mixed, new: mixed, redacted: bool}>
     */
    private function changes(AuditLog $log, bool $isExam): array
    {
        $event = (string) $log->event;

        // Só `updated` (diff) e `merged` (resumo do merge) trazem campos; o resto é o próprio evento.
        if ($event !== 'updated' && $event !== 'merged') {
            return [];
        }

        $new = (array) $log->new_values;
        $old = (array) $log->old_values;

        $allowed = $event === 'merged'
            ? self::MERGED_FIELDS
            : ($isExam ? self::EXAM_FIELDS : self::PATIENT_FIELDS);

        $changes = [];

        foreach (array_keys($new) as $field) {
            $field = (string) $field;

            // Valor estruturado (array/objeto) nunca sai, mesmo em campo permitido.
            $visible = in_array($field, $allowed, true)
                && (is_scalar($new[$field] ?? null) || ($new[$field] ?? null) === null)
                && (is_scalar($old[$field] ?? null) || ($old[$field] ?? null) === null);

            $changes[] = [
                'field'    => $field,
                'old'      => $visible ? ($old[$field] ?? null) : null,
                'new'      => $visible ? ($new[$field] ?? null) : null,
                'redacted' => ! $visible,
            ];
        }

        return $changes;
    }
}
