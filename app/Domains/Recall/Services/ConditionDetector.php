<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Enums\{ConditionOrigin, ConditionStatus};
use App\Domains\Recall\Models\PatientCondition;
use App\Domains\Recall\Support\ProtocolContentSchema;
use App\Enums\ClinicalSafety\MedicationStatus;
use App\Enums\Eye;
use App\Support\ClinicalSafety\TermNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Detecta, no prontuário, os pacientes que entram num protocolo que a clínica ADOTOU (ADR 0002, item 8):
 *
 *  - CID `strong` em prontuário ASSINADO → condição ATIVA (o médico assinou o diagnóstico); em prontuário ainda
 *    editável, ou CID `weak`, só SUGERIDA — o médico confirma ou descarta; CID `exclude` nunca entra;
 *  - medicação do protocolo em uso e CONFIRMADA pelo médico → ATIVA (início = início do uso; sem data → o retorno
 *    fica "falta informação"); medicação ainda não confirmada → sugerida.
 *
 * Nunca decide o que o médico já decidiu: condição descartada ou com alta não volta sozinha; condição aberta
 * (inclusive a manual, de olho escolhido) não é duplicada; condição ativa nunca é rebaixada. A evidência gravada é
 * SÓ id, código e data — nada de texto livre do prontuário. Sem escrita quando nada mudou.
 *
 * Roda dentro da avaliação do paciente (sob a trava dele); o olho do CID é desconhecido (o CID-10 não diz o lado):
 * a condição nasce em AO e o médico ajusta se for o caso.
 */
class ConditionDetector
{
    /** Quantos prontuários a evidência guarda (o que basta para auditar; o resto é ruído). */
    private const EVIDENCE_RECORDS = 5;

    /**
     * @param array<string, array<string, mixed>> $contents conteúdo NORMALIZADO das versões em uso, por chave de protocolo
     */
    public function detect(string $entityId, string $patientId, array $contents, CarbonImmutable $today): void
    {
        if ($contents === []) {
            return;
        }

        $records     = $this->records($entityId, $patientId, $today);
        $medications = $this->medications($entityId, $patientId);

        foreach ($contents as $key => $content) {
            $candidate = $this->candidate((string) $key, $content, $records, $medications);

            if ($candidate !== null) {
                $this->apply($entityId, $patientId, (string) $key, $candidate);
            }
        }
    }

    /**
     * O que o prontuário diz sobre este protocolo: o melhor status (ativa > sugerida), a data mais antiga da
     * evidência forte e a evidência (ids, código, data).
     *
     * @param array<string, mixed>                                                                 $content
     * @param list<array{id: string, on: CarbonImmutable, signed: bool, codes: list<string>}>      $records
     * @param list<array{core: string, confirmed: bool, started_on: ?CarbonImmutable, id: string}> $medications
     *
     * @return array{status: ConditionStatus, origin: ConditionOrigin, started_on: ?CarbonImmutable, evidence: array<string, mixed>}|null
     */
    private function candidate(string $key, array $content, array $records, array $medications): ?array
    {
        $best = null;

        foreach ($records as $record) {
            foreach ($record['codes'] as $code) {
                $class = ProtocolContentSchema::classifyCid($content, $code);

                if ($class === null || $class === 'exclude') {
                    continue;
                }

                $status = $class === 'strong' && $record['signed'] ? ConditionStatus::Active : ConditionStatus::Suggested;
                $best   = $this->better($best, [
                    'status'     => $status,
                    'origin'     => ConditionOrigin::Cid,
                    'started_on' => $record['on'],
                    'evidence'   => ['records' => [$record['id']], 'code' => ProtocolContentSchema::cidKey($code), 'first_on' => $record['on']->toDateString(), 'signed' => $record['signed']],
                ]);
            }
        }

        foreach ((array) ($content['entry']['medications'] ?? []) as $medicationKey) {
            foreach ($this->ingredients((string) $medicationKey) as $ingredient) {
                foreach ($medications as $medication) {
                    if (! TermNormalizer::contains($medication['core'], $ingredient)) {
                        continue;
                    }

                    $best = $this->better($best, [
                        'status'     => $medication['confirmed'] ? ConditionStatus::Active : ConditionStatus::Suggested,
                        'origin'     => ConditionOrigin::Medication,
                        'started_on' => $medication['started_on'],
                        'evidence'   => ['medication_id' => $medication['id'], 'medication' => $medicationKey, 'first_on' => $medication['started_on']?->toDateString(), 'confirmed' => $medication['confirmed']],
                    ]);
                }
            }
        }

        return $best;
    }

    /**
     * Junta duas evidências do mesmo protocolo: vale o status mais forte; no mesmo status, a data mais antiga (o
     * início do acompanhamento) e os prontuários acumulados.
     *
     * @param array<string, mixed>|null $current
     * @param array<string, mixed>      $other
     *
     * @return array<string, mixed>
     */
    private function better(?array $current, array $other): array
    {
        if ($current === null) {
            return $other;
        }

        $rank = static fn (array $item): int => $item['status'] === ConditionStatus::Active ? 2 : 1;

        if ($rank($other) !== $rank($current)) {
            return $rank($other) > $rank($current) ? $other : $current;
        }

        $winner = ($other['started_on'] !== null && ($current['started_on'] === null || $other['started_on']->lessThan($current['started_on']))) ? $other : $current;
        $loser  = $winner === $other ? $current : $other;

        if (isset($winner['evidence']['records'], $loser['evidence']['records']) && $winner['evidence']['code'] === $loser['evidence']['code']) {
            $winner['evidence']['records'] = array_slice(array_values(array_unique([...$winner['evidence']['records'], ...$loser['evidence']['records']])), 0, self::EVIDENCE_RECORDS);
        }

        return $winner;
    }

    /**
     * @param array{status: ConditionStatus, origin: ConditionOrigin, started_on: ?CarbonImmutable, evidence: array<string, mixed>} $candidate
     */
    private function apply(string $entityId, string $patientId, string $key, array $candidate): void
    {
        $rows = PatientCondition::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->where('protocol_key', $key)
            ->get();

        // O médico já decidiu: descartada ou com alta não volta sozinha.
        if ($rows->contains(static fn (PatientCondition $row): bool => in_array($row->status, [ConditionStatus::Rejected, ConditionStatus::Discharged], true))
            && ! $rows->contains(static fn (PatientCondition $row): bool => $row->status->isOpen())) {
            return;
        }

        $open = $rows->first(static fn (PatientCondition $row): bool => $row->status->isOpen());

        if ($open === null) {
            PatientCondition::query()->withoutGlobalScopes()->create([
                'entity_id'    => $entityId,
                'patient_id'   => $patientId,
                'protocol_key' => $key,
                'eye'          => Eye::Both->value,
                'status'       => $candidate['status']->value,
                'origin'       => $candidate['origin']->value,
                'started_on'   => $candidate['started_on']?->toDateString(),
                'evidence'     => $candidate['evidence'],
            ]);

            return;
        }

        // A condição que o médico escolheu à mão (olho, início) é dele: o detector não a toca.
        if ($open->origin === ConditionOrigin::Manual) {
            return;
        }

        // Condição aberta do sistema: só se completa. Sugerida vira ativa quando a evidência pede; ativa nunca é
        // rebaixada; o início só adianta (a evidência mais antiga) ou preenche o que faltava.
        $changes = [];

        if ($open->status === ConditionStatus::Suggested && $candidate['status'] === ConditionStatus::Active) {
            $changes['status'] = ConditionStatus::Active->value;
        }

        $started = $open->started_on !== null ? CarbonImmutable::parse($open->started_on)->startOfDay() : null;

        if ($candidate['started_on'] !== null && ($started === null || $candidate['started_on']->lessThan($started))) {
            $changes['started_on'] = $candidate['started_on']->toDateString();
        }

        if ((array) $open->evidence !== $candidate['evidence']) {
            $changes['evidence'] = $candidate['evidence'];
        }

        if ($changes !== []) {
            $open->fill($changes)->save();
        }
    }

    /**
     * Prontuários do paciente com diagnóstico, com a data da consulta (a do agendamento vinculado, senão a do
     * registro; nunca no futuro) e se estão assinados.
     *
     * @return list<array{id: string, on: CarbonImmutable, signed: bool, codes: list<string>}>
     */
    private function records(string $entityId, string $patientId, CarbonImmutable $today): array
    {
        $rows = DB::table('medical_records as mr')
            ->leftJoin('schedules as s', static function ($join): void {
                $join->on('s.id', '=', 'mr.schedule_id')->whereNull('s.deleted_at');
            })
            ->where('mr.entity_id', $entityId)
            ->where('mr.patient_id', $patientId)
            ->whereNull('mr.deleted_at')
            ->whereNotNull('mr.diagnosis_cids')
            ->get(['mr.id', 'mr.diagnosis_cids', 'mr.signed_at', 'mr.created_at', 's.date_time as scheduled_at']);

        $records = [];

        foreach ($rows as $row) {
            $on = $row->scheduled_at !== null ? CarbonImmutable::parse($row->scheduled_at)->startOfDay() : null;

            if ($on === null || $on->greaterThan($today)) {
                $on = CarbonImmutable::parse($row->created_at)->startOfDay();
            }

            if ($on->greaterThan($today)) {
                continue;
            }

            $decoded = json_decode((string) $row->diagnosis_cids, true);
            $codes   = [];

            foreach (is_array($decoded) ? $decoded : [] as $item) {
                if (is_array($item) && is_string($item['code'] ?? null) && $item['code'] !== '') {
                    $codes[] = $item['code'];
                }
            }

            if ($codes !== []) {
                $records[] = ['id' => (string) $row->id, 'on' => $on, 'signed' => $row->signed_at !== null, 'codes' => $codes];
            }
        }

        usort($records, static fn (array $a, array $b): int => $a['on'] <=> $b['on']);

        return $records;
    }

    /**
     * Medicações em uso do paciente, já normalizadas (princípio ativo + nome) para a comparação por palavras.
     *
     * @return list<array{core: string, confirmed: bool, started_on: ?CarbonImmutable, id: string}>
     */
    private function medications(string $entityId, string $patientId): array
    {
        return DB::table('patient_medications')
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->whereNull('deleted_at')
            ->where('status', MedicationStatus::Active->value)
            ->get(['id', 'name', 'active_ingredient', 'started_on', 'confirmed_at'])
            ->map(static fn (object $row): array => [
                'id'         => (string) $row->id,
                'core'       => TermNormalizer::core(trim($row->name . ' ' . $row->active_ingredient)),
                'confirmed'  => $row->confirmed_at !== null,
                'started_on' => $row->started_on !== null ? CarbonImmutable::parse($row->started_on)->startOfDay() : null,
            ])
            ->all();
    }

    /**
     * Os princípios ativos (já normalizados) que a chave de medicação do protocolo reconhece.
     *
     * @return list<string>
     */
    private function ingredients(string $medicationKey): array
    {
        return array_values(array_filter(array_map(
            static fn (string $ingredient): string => TermNormalizer::core($ingredient),
            (array) config("recall.medications.{$medicationKey}.ingredients", []),
        )));
    }
}
