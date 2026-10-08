<?php

declare(strict_types=1);

namespace App\Services\ClinicalSafety;

use App\Enums\ClinicalSafety\{AllergyCategory, AllergyStatus, MedicationSource, MedicationStatus};
use App\Models\{MedicalRecord, Medicine, Patient, PatientAllergy, PatientMedication, User};
use App\Support\AuditContext;
use App\Support\ClinicalSafety\{AllergyConflictChecker, OcularRiskAlerts, TermNormalizer};
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alergias e medicações em uso do paciente (Fase 2 / A1 e A2) — regras de
 * negócio num lugar só (cadastro do paciente, prontuário, agenda, pronto
 * atendimento e receituário consomem daqui).
 *
 * Quem faz o quê (decisão do dono):
 *  - recepção (e qualquer perfil com acesso a Pacientes) registra alergia e
 *    medicação SEMPRE como "informada pelo paciente" e só corrige o que ainda
 *    está informado;
 *  - só o médico com cadastro ativo (Gate IssueReport — `$asDoctor`) confirma,
 *    corrige, descarta, suspende ou reativa.
 *
 * "Nega alergias" (quem/quando) fica no paciente e cai sozinho quando uma
 * alergia ativa é registrada.
 */
class PatientClinicalSafetyService
{
    public function __construct(private readonly AllergyConflictChecker $checker)
    {
    }

    // ── Leitura ─────────────────────────────────────────────────────────────

    /**
     * Situação completa do paciente: alergias (com histórico), "nega
     * alergias", medicações e alertas de risco ocular.
     *
     * @return array<string, mixed>
     */
    public function summary(Patient $patient, bool $withHistory = true): array
    {
        $allergies   = $this->allergies($patient, $withHistory);
        $medications = $this->medications($patient, $withHistory);
        $names       = $this->userNames([
            ...$allergies->flatMap(fn (PatientAllergy $a) => [$a->created_by, $a->confirmed_by, $a->discarded_by]),
            ...$medications->flatMap(fn (PatientMedication $m) => [$m->created_by, $m->confirmed_by, $m->discarded_by]),
            $patient->allergies_denied_by,
        ]);

        $active = $allergies->filter(fn (PatientAllergy $a) => $a->isActive());

        return [
            'allergy_state' => $this->state($patient, $active->count()),
            'denied'        => $patient->allergies_denied_at ? [
                'at'       => $patient->allergies_denied_at->toIso8601String(),
                'at_label' => $this->dateTimeLabel($patient->allergies_denied_at),
                'by'       => $names[(string) $patient->allergies_denied_by] ?? null,
            ] : null,
            'allergies'   => $allergies->map(fn (PatientAllergy $a) => $this->serializeAllergy($a, $names))->values()->all(),
            'medications' => $medications->map(fn (PatientMedication $m) => $this->serializeMedication($m, $names))->values()->all(),
            'risk_alerts' => $this->riskAlerts($patient),
        ];
    }

    /**
     * Resumo curto por paciente para LISTAS (cards da Agenda, fila do pronto
     * atendimento) — uma consulta para a página inteira.
     *
     * @param list<string> $patientIds
     *
     * @return array<string, array{state: string, items: list<array{substance: string, severe: bool}>, severe: bool}>
     */
    public function compactFor(string $entityId, array $patientIds): array
    {
        $patientIds = array_values(array_unique(array_filter(array_map('strval', $patientIds))));

        if ($patientIds === []) {
            return [];
        }

        $allergies = PatientAllergy::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->whereIn('patient_id', $patientIds)
            ->whereNull('deleted_at')
            ->active()
            ->orderBy('created_at')
            ->get(['patient_id', 'substance', 'severity', 'reaction'])
            ->groupBy('patient_id');

        $denied = Patient::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->whereIn('id', $patientIds)
            ->whereNotNull('allergies_denied_at')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->flip();

        $out = [];

        foreach ($patientIds as $id) {
            $list = $allergies->get($id, collect());

            $out[$id] = [
                'state' => $list->isNotEmpty() ? 'has' : ($denied->has($id) ? 'denied' : 'unknown'),
                // Gravidade por alergia: "Dipirona (grave), Penicilina".
                'items' => $list->map(fn (PatientAllergy $a) => [
                    'substance' => $a->substance,
                    'severe'    => $a->severity?->value === 'severe',
                ])->values()->all(),
                'severe' => $list->contains(fn (PatientAllergy $a) => $a->severity?->value === 'severe'),
            ];
        }

        return $out;
    }

    /**
     * Retrato gravado no prontuário a cada salvar (o prontuário reflete o
     * momento da consulta): alergias e medicações ATIVAS.
     *
     * @return array{allergies_snapshot: array<string, mixed>, medications_snapshot: list<array<string, mixed>>}
     */
    public function snapshot(Patient $patient): array
    {
        $allergies   = $this->allergies($patient, false);
        $medications = $this->medications($patient, false);

        return [
            'allergies_snapshot' => [
                'state'     => $this->state($patient, $allergies->count()),
                'denied_at' => $patient->allergies_denied_at?->toIso8601String(),
                'items'     => $allergies->map(fn (PatientAllergy $a) => [
                    'substance' => $a->substance,
                    'category'  => $a->category->value,
                    'reaction'  => $a->reaction,
                    'severity'  => $a->severity?->value,
                    'status'    => $a->status->value,
                ])->values()->all(),
            ],
            'medications_snapshot' => $medications->map(fn (PatientMedication $m) => [
                'name'              => $m->name,
                'active_ingredient' => $m->active_ingredient,
                'posology'          => $m->posology,
                'started_on'        => $m->started_on?->toDateString(),
                'source'            => $m->source->value,
                'confirmed'         => $m->confirmed_at !== null,
            ])->values()->all(),
        ];
    }

    /**
     * Dados para a checagem na tela do receituário (mesma regra do servidor).
     *
     * @return array<string, mixed>
     */
    public function clientCheck(Patient $patient): array
    {
        return $this->checker->clientCheck($patient);
    }

    // ── Alergias ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createAllergy(Patient $patient, array $data, bool $asDoctor): PatientAllergy
    {
        $this->assertNoDuplicateAllergy($patient, (string) ($data['substance'] ?? ''));

        return DB::transaction(function () use ($patient, $data, $asDoctor) {
            $confirmed = $asDoctor && ($data['status'] ?? AllergyStatus::Confirmed->value) === AllergyStatus::Confirmed->value;

            $allergy = new PatientAllergy([
                ...$this->allergyFields($patient, $data),
                'entity_id'  => $patient->entity_id,
                'patient_id' => $patient->getKey(),
                'status'     => $confirmed ? AllergyStatus::Confirmed : AllergyStatus::Reported,
            ]);

            if ($confirmed) {
                $allergy->confirmed_at = now();
                $allergy->confirmed_by = auth()->id();
            }

            $allergy->save();
            $this->clearDenial($patient);

            return $allergy;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateAllergy(PatientAllergy $allergy, array $data, bool $asDoctor): PatientAllergy
    {
        if ($allergy->status === AllergyStatus::Discarded) {
            throw ValidationException::withMessages(['substance' => __('clinical_safety.allergies.discarded_locked')]);
        }

        // Recepção corrige só o que ainda é "informado" — o confirmado pelo
        // médico é registro clínico dele.
        if (! $asDoctor && $allergy->status !== AllergyStatus::Reported) {
            throw new AuthorizationException(__('clinical_safety.allergies.doctor_only'));
        }

        $patient = $allergy->patient()->withoutGlobalScopes()->firstOrFail();
        $this->assertNoDuplicateAllergy($patient, (string) ($data['substance'] ?? ''), (string) $allergy->getKey());
        $allergy->fill($this->allergyFields($patient, $data));

        // Médico que corrige uma informada já a confirma (é ele revisando).
        if ($asDoctor && ($data['status'] ?? null) === AllergyStatus::Confirmed->value && $allergy->status !== AllergyStatus::Confirmed) {
            $allergy->status       = AllergyStatus::Confirmed;
            $allergy->confirmed_at = now();
            $allergy->confirmed_by = auth()->id();
        }

        $allergy->save();

        return $allergy;
    }

    public function confirmAllergy(PatientAllergy $allergy): PatientAllergy
    {
        if ($allergy->status === AllergyStatus::Discarded) {
            $this->assertNoDuplicateAllergy($allergy->patient()->withoutGlobalScopes()->firstOrFail(), $allergy->substance, (string) $allergy->getKey());
        }

        return DB::transaction(function () use ($allergy) {
            $allergy->forceFill([
                'status'         => AllergyStatus::Confirmed,
                'confirmed_at'   => now(),
                'confirmed_by'   => auth()->id(),
                'discarded_at'   => null,
                'discarded_by'   => null,
                'discard_reason' => null,
            ])->save();

            $this->clearDenial($allergy->patient()->withoutGlobalScopes()->firstOrFail());

            return $allergy;
        });
    }

    public function discardAllergy(PatientAllergy $allergy, ?string $reason): PatientAllergy
    {
        $allergy->forceFill([
            'status'         => AllergyStatus::Discarded,
            'discarded_at'   => now(),
            'discarded_by'   => auth()->id(),
            'discard_reason' => filled($reason) ? trim((string) $reason) : null,
        ])->save();

        return $allergy;
    }

    /** "Nega alergias" (true) ou desfaz (false). */
    public function setDenied(Patient $patient, bool $denied): Patient
    {
        if ($denied && $this->activeAllergyCount($patient) > 0) {
            throw ValidationException::withMessages(['denied' => __('clinical_safety.allergies.deny_with_active')]);
        }

        // AuditContext: na tela é o usuário logado; na importação por planilha
        // (job, sem login) é quem enviou o arquivo.
        $patient->forceFill([
            'allergies_denied_at' => $denied ? now() : null,
            'allergies_denied_by' => $denied ? AuditContext::userId() : null,
        ])->save();

        return $patient;
    }

    /**
     * Lista de substâncias digitada em UM campo (cadastro rápido, coluna da
     * planilha): separa por ";", "," ou quebra de linha, tira espaços e
     * repetições (sem acento/maiúsculas — mesma regra da duplicidade).
     *
     * @return list<string>
     */
    public static function splitSubstances(?string $text): array
    {
        $out = [];

        foreach (preg_split('/[;,\n\r]+/u', (string) $text) ?: [] as $part) {
            $substance = trim((string) preg_replace('/\s+/u', ' ', $part));
            $key       = TermNormalizer::core($substance) ?: TermNormalizer::plain($substance);

            if ($substance !== '' && $key !== '' && ! isset($out[$key])) {
                $out[$key] = $substance;
            }
        }

        return array_values($out);
    }

    /**
     * Alergias informadas pelo paciente vindas da importação por planilha
     * (G4): cada substância vira alergia "informada", categoria "outro", sem
     * repetir a que o paciente JÁ TEM — ativa ou descartada (reimportar a
     * mesma planilha não ressuscita o que o médico descartou). "Nega
     * alergias" só vale sem alergia na linha nem ativa no paciente, e não
     * reescreve um "nega" já registrado (quem/quando).
     *
     * @param list<string> $substances
     *
     * @return array{created: int, denied: bool}
     */
    public function registerReportedSubstances(Patient $patient, array $substances, bool $denied): array
    {
        $known = PatientAllergy::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)
            ->where('patient_id', $patient->getKey())
            ->whereNull('deleted_at')
            ->pluck('substance')
            ->mapWithKeys(fn ($existing) => [TermNormalizer::core((string) $existing) ?: TermNormalizer::plain((string) $existing) => true])
            ->all();

        $created = 0;

        foreach ($substances as $substance) {
            $key = TermNormalizer::core($substance) ?: TermNormalizer::plain($substance);

            if ($key === '' || isset($known[$key])) {
                continue;
            }

            $this->createAllergy($patient, ['category' => AllergyCategory::Other->value, 'substance' => $substance], asDoctor: false);
            $known[$key] = true;
            $created++;
        }

        $patient->refresh();

        if ($denied && $substances === [] && $patient->allergies_denied_at === null && $this->activeAllergyCount($patient) === 0) {
            $this->setDenied($patient, true);

            return ['created' => $created, 'denied' => true];
        }

        return ['created' => $created, 'denied' => false];
    }

    // ── Medicações ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createMedication(Patient $patient, array $data, bool $asDoctor): PatientMedication
    {
        $source = $data['source'] ?? null;
        $source = in_array($source, MedicationSource::values(), true)
            ? $source
            : ($asDoctor ? MedicationSource::Clinic->value : MedicationSource::Patient->value);

        // Recepção não registra "da clínica" — é informação do paciente.
        if (! $asDoctor && $source === MedicationSource::Clinic->value) {
            $source = MedicationSource::Patient->value;
        }

        $fields = $this->medicationFields($patient, $data);
        $this->assertNoDuplicateMedication($patient, $fields);

        $medication = new PatientMedication([
            ...$fields,
            'entity_id'  => $patient->entity_id,
            'patient_id' => $patient->getKey(),
            'status'     => MedicationStatus::Active,
            'source'     => $source,
        ]);

        if ($asDoctor) {
            $medication->confirmed_at = now();
            $medication->confirmed_by = auth()->id();
        }

        $medication->save();

        return $medication;
    }

    /** @param array<string, mixed> $data */
    public function updateMedication(PatientMedication $medication, array $data, bool $asDoctor): PatientMedication
    {
        if ($medication->status === MedicationStatus::Discarded) {
            throw ValidationException::withMessages(['name' => __('clinical_safety.medications.discarded_locked')]);
        }

        if (! $asDoctor && ($medication->confirmed_at !== null || $medication->status !== MedicationStatus::Active)) {
            throw new AuthorizationException(__('clinical_safety.medications.doctor_only'));
        }

        $fields = $this->medicationFields($medication->patient()->withoutGlobalScopes()->firstOrFail(), $data, $medication);
        $this->assertNoDuplicateMedication($medication->patient()->withoutGlobalScopes()->firstOrFail(), $fields, (string) $medication->getKey());
        $medication->fill($fields);

        // Recepção corrige entre "paciente"/"receita externa" igual já pode
        // ao CRIAR — só "da clínica" continua exclusivo do médico (10ª
        // revisão Codex, P2): antes, qualquer troca de recepção (mesmo pras
        // duas permitidas) era ignorada em silêncio.
        $source = $data['source'] ?? null;

        if (in_array($source, MedicationSource::values(), true) && ($asDoctor || $source !== MedicationSource::Clinic->value)) {
            $medication->source = $source;
        }

        $medication->save();

        return $medication;
    }

    public function confirmMedication(PatientMedication $medication): PatientMedication
    {
        if ($medication->status === MedicationStatus::Discarded) {
            throw ValidationException::withMessages(['status' => __('clinical_safety.medications.discarded_locked')]);
        }

        $medication->forceFill(['confirmed_at' => now(), 'confirmed_by' => auth()->id()])->save();

        return $medication;
    }

    public function changeMedicationStatus(PatientMedication $medication, MedicationStatus $status): PatientMedication
    {
        if ($status === MedicationStatus::Active && $medication->status !== MedicationStatus::Active) {
            $this->assertNoDuplicateMedication(
                $medication->patient()->withoutGlobalScopes()->firstOrFail(),
                ['medicine_id' => $medication->medicine_id, 'name' => $medication->name],
                (string) $medication->getKey(),
            );
        }

        $changes = ['status' => $status];

        if ($status === MedicationStatus::Suspended) {
            $changes['ended_on'] = $medication->ended_on ?? today();
        }

        if ($status === MedicationStatus::Active) {
            $changes['ended_on']     = null;
            $changes['discarded_at'] = null;
            $changes['discarded_by'] = null;
        }

        if ($status === MedicationStatus::Discarded) {
            $changes['discarded_at'] = now();
            $changes['discarded_by'] = auth()->id();
        }

        $medication->forceFill($changes)->save();

        return $medication;
    }

    /**
     * Alergias/medicações informadas no CADASTRO de um paciente novo (aba
     * Clínico) — sempre como informadas pelo paciente, nunca confirmadas.
     *
     * @param list<array<string, mixed>> $allergies
     * @param list<array<string, mixed>> $medications
     */
    public function registerFromPatientForm(Patient $patient, array $allergies, bool $denied, array $medications): void
    {
        foreach ($allergies as $index => $data) {
            try {
                $this->createAllergy($patient, $data, asDoctor: false);
            } catch (ValidationException $e) {
                throw $this->reindexValidationException($e, "allergies.{$index}.");
            }
        }

        foreach ($medications as $index => $data) {
            try {
                $this->createMedication($patient, $data, asDoctor: false);
            } catch (ValidationException $e) {
                throw $this->reindexValidationException($e, "medications.{$index}.");
            }
        }

        if ($denied && $allergies === []) {
            $this->setDenied($patient->refresh(), true);
        }
    }

    /**
     * createAllergy()/createMedication() foram escritos para a tela de UM
     * item só ("substance"/"name" bate certo lá). Aqui é lista — a mesma
     * chave crua colidia com um campo de MESMO NOME do formulário do
     * PACIENTE ("name" caía no nome do paciente, não no item clínico
     * duplicado) (10ª revisão Codex, P2). Reindexa pra "allergies.<i>.*" /
     * "medications.<i>.*", igual o front já manda pros campos normais.
     */
    private function reindexValidationException(ValidationException $e, string $prefix): ValidationException
    {
        $errors = [];

        foreach ($e->errors() as $key => $messages) {
            $errors[$prefix . $key] = $messages;
        }

        return ValidationException::withMessages($errors);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Mesma substância já ATIVA no paciente (sem acento/maiúsculas): o alerta
     * duplicaria — corrige-se a existente.
     */
    private function assertNoDuplicateAllergy(Patient $patient, string $substance, ?string $exceptId = null): void
    {
        $core = TermNormalizer::core($substance);

        $exists = $core !== '' && PatientAllergy::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)
            ->where('patient_id', $patient->getKey())
            ->whereNull('deleted_at')
            ->active()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->pluck('substance')
            ->contains(fn ($existing) => TermNormalizer::core((string) $existing) === $core);

        if ($exists) {
            throw ValidationException::withMessages(['substance' => __('clinical_safety.allergies.duplicate')]);
        }
    }

    /**
     * Mesma medicação já EM USO na lista (mesmo item do catálogo ou mesmo
     * nome, sem acento/maiúsculas).
     *
     * @param array<string, mixed> $fields
     */
    private function assertNoDuplicateMedication(Patient $patient, array $fields, ?string $exceptId = null): void
    {
        $name = TermNormalizer::plain((string) ($fields['name'] ?? ''));

        $exists = PatientMedication::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)
            ->where('patient_id', $patient->getKey())
            ->whereNull('deleted_at')
            ->active()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['medicine_id', 'name'])
            ->contains(fn (PatientMedication $m) => ($fields['medicine_id'] ?? null) !== null && (string) $m->medicine_id === (string) $fields['medicine_id']
                || ($name !== '' && TermNormalizer::plain($m->name) === $name));

        if ($exists) {
            throw ValidationException::withMessages(['name' => __('clinical_safety.medications.duplicate')]);
        }
    }

    /** @return Collection<int, PatientAllergy> */
    private function allergies(Patient $patient, bool $withHistory): Collection
    {
        return PatientAllergy::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)
            ->where('patient_id', $patient->getKey())
            ->whereNull('deleted_at')
            ->when(! $withHistory, fn ($q) => $q->active())
            ->with('medicine:id,name,concentration')
            // Ativas primeiro (grave antes), descartadas no fim.
            ->orderByRaw("CASE status WHEN 'confirmed' THEN 0 WHEN 'reported' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE severity WHEN 'severe' THEN 0 WHEN 'moderate' THEN 1 WHEN 'mild' THEN 2 ELSE 3 END")
            ->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, PatientMedication> */
    private function medications(Patient $patient, bool $withHistory): Collection
    {
        return PatientMedication::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)
            ->where('patient_id', $patient->getKey())
            ->whereNull('deleted_at')
            ->when(! $withHistory, fn ($q) => $q->active())
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'suspended' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();
    }

    private function activeAllergyCount(Patient $patient): int
    {
        return PatientAllergy::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)
            ->where('patient_id', $patient->getKey())
            ->whereNull('deleted_at')
            ->active()
            ->count();
    }

    private function state(Patient $patient, int $activeCount): string
    {
        return match (true) {
            $activeCount > 0                       => 'has',
            $patient->allergies_denied_at !== null => 'denied',
            default                                => 'unknown',
        };
    }

    private function clearDenial(Patient $patient): void
    {
        if ($patient->allergies_denied_at !== null) {
            $patient->forceFill(['allergies_denied_at' => null, 'allergies_denied_by' => null])->save();
        }
    }

    /**
     * Medicações ativas da lista + SUSPENSAS (só nas regras cujo risco
     * persiste depois de parar — IFIS da tansulosina etc.; ver
     * config/clinical_safety.php) + menção no texto livre de medicações da
     * última consulta (registros antigos, antes da lista estruturada).
     * Descartadas (registro indevido) ficam fora.
     *
     * Consulta própria: a lista da tela pode vir sem histórico (só ativas).
     *
     * @return list<array<string, mixed>>
     */
    private function riskAlerts(Patient $patient): array
    {
        // Todas, não só ativa/suspensa: "descartada" também vale pra tirar a
        // substância do texto livre antigo (abaixo) — o médico já revisou.
        $medications = PatientMedication::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)
            ->where('patient_id', $patient->getKey())
            ->whereNull('deleted_at')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        $entries = $medications
            ->filter(fn (PatientMedication $m) => in_array($m->status, [MedicationStatus::Active, MedicationStatus::Suspended], true))
            ->map(function (PatientMedication $m) {
                $suspendedOn = $m->status === MedicationStatus::Suspended ? ($m->ended_on ?? $m->updated_at) : null;

                return [
                    'text'  => trim(($m->active_ingredient ?? '') . ' ' . $m->name),
                    'label' => $suspendedOn === null ? $m->name : __('clinical_safety.risk.suspended_label', [
                        'name' => $m->name,
                        'date' => $this->dateLabel($suspendedOn),
                    ]),
                    'origin'       => 'list',
                    'suspended_on' => $suspendedOn,
                ];
            })
            ->values()
            ->all();

        $last = MedicalRecord::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)
            ->where('patient_id', $patient->getKey())
            ->whereNull('deleted_at')
            ->whereNotNull('medications_in_use')
            ->where('medications_in_use', '!=', '')
            ->latest('created_at')
            ->first(['medications_in_use', 'created_at']);

        if ($last !== null) {
            // Substância que JÁ tem registro estruturado (ativo, suspenso OU
            // descartado) sai do texto livre antes de alertar: é a lista quem
            // decide o risco a partir daqui — texto de consulta antiga não
            // pode reviver um risco que o médico já suspendeu ou descartou
            // (sem metadado de suspensão próprio, o texto livre não tinha
            // como respeitar esse status) (3ª revisão Codex, P2).
            $knownTerms = $medications
                ->flatMap(fn (PatientMedication $m) => $this->medicationReconciliationTerms($m))
                ->unique()
                ->all();

            $freeText = $this->stripKnownMedicationTerms($last->medications_in_use, $knownTerms);

            if ($freeText !== '') {
                $entries[] = [
                    'text'   => $freeText,
                    'label'  => __('clinical_safety.risk.free_text_label', ['date' => $this->dateLabel($last->created_at)]),
                    'origin' => 'free_text',
                ];
            }
        }

        return OcularRiskAlerts::for($entries);
    }

    /**
     * Termo canônico pra reconciliar com o texto livre antigo: com princípio
     * ativo, ele mesmo (pode ter mais de uma substância — "Dorzolamida +
     * Timolol" vira dois termos); sem ele, o NOME sem a dose. Nome livre
     * ("Varfarina 5 mg") inteiro nunca bate com outra dose/sem dose no texto
     * antigo ("Varfarina", "Varfarina 10mg") — TermNormalizer::terms() separa
     * substância composta, não separa nome de dose (5ª revisão Codex, P2).
     *
     * @return list<string>
     */
    private function medicationReconciliationTerms(PatientMedication $m): array
    {
        if (filled($m->active_ingredient)) {
            return TermNormalizer::terms($m->active_ingredient);
        }

        $nameWithoutDose = trim((string) preg_replace('/\s*\d.*$/u', '', (string) $m->name));

        return TermNormalizer::terms($nameWithoutDose !== '' ? $nameWithoutDose : $m->name);
    }

    /**
     * Tira de $text (já normalizado por TermNormalizer::core() de novo
     * dentro de OcularRiskAlerts — rodar aqui também é inofensivo) cada
     * termo já coberto por um registro estruturado, palavra(s) inteira(s).
     * O texto que sobra não aparece na tela (o rótulo do alerta é fixo,
     * "mencionado no texto livre") — só alimenta a checagem.
     *
     * @param list<string> $terms
     */
    private function stripKnownMedicationTerms(string $text, array $terms): string
    {
        $core = ' ' . TermNormalizer::core($text) . ' ';

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            $needle = ' ' . $term . ' ';

            // Ocorrência adjacente da mesma substância ("varfarina varfarina
            // sodica", depois do normalize() tirar o "(" e o "sódica" virar
            // stopword) compartilha o espaço de fronteira — uma passada só do
            // str_replace tira a 1ª e consome o espaço que a 2ª precisava
            // como fronteira, deixando a 2ª sobrar. Repete até não sobrar
            // nenhuma (9ª revisão Codex, P2).
            while (str_contains($core, $needle)) {
                $core = str_replace($needle, ' ', $core);
            }
        }

        return trim($core);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function allergyFields(Patient $patient, array $data): array
    {
        $medicine = $this->catalogMedicine($patient, $data['medicine_id'] ?? null);

        return [
            'category'    => $data['category'],
            'substance'   => trim((string) $data['substance']),
            'medicine_id' => $medicine?->getKey(),
            // Item do catálogo escolhido: o princípio ativo é o DO CATÁLOGO
            // (relido aqui, nunca o que veio da tela) — é ele que alimenta a
            // checagem da receita. Sem item (ou item sem princípio ativo), vale
            // o digitado.
            'active_ingredient' => filled($medicine?->active_ingredient)
                ? trim((string) $medicine->active_ingredient)
                : (filled($data['active_ingredient'] ?? null) ? trim((string) $data['active_ingredient']) : null),
            'reaction' => filled($data['reaction'] ?? null) ? trim((string) $data['reaction']) : null,
            'severity' => blank($data['severity'] ?? null) ? null : $data['severity'],
            'notes'    => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function medicationFields(Patient $patient, array $data, ?PatientMedication $existing = null): array
    {
        $medicine = $this->catalogMedicine($patient, $data['medicine_id'] ?? null);
        $name     = trim((string) ($data['name'] ?? '')) ?: trim((string) $medicine?->name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => __('clinical_safety.medications.name')]),
            ]);
        }

        return [
            'medicine_id' => $medicine?->getKey(),
            'name'        => $name,
            // Item do catálogo escolhido: o princípio ativo é o DO CATÁLOGO
            // (igual allergyFields() acima) — sem isto, um active_ingredient
            // desatualizado mandado junto com um medicine_id válido vencia o
            // do catálogo e podia apagar um alerta real (4ª revisão Codex,
            // P2: SECOTEX salvo com active_ingredient=losartana suprimia o
            // alerta de IFIS da tansulosina). Sem item (ou item sem
            // princípio ativo), vale o digitado.
            'active_ingredient' => filled($medicine?->active_ingredient)
                ? trim((string) $medicine->active_ingredient)
                : (filled($data['active_ingredient'] ?? null) ? trim((string) $data['active_ingredient']) : null),
            'posology'   => filled($data['posology'] ?? null) ? trim((string) $data['posology']) : null,
            'started_on' => blank($data['started_on'] ?? null) ? null : $data['started_on'],
            // O formulário de edição geral não tem campo de data de suspensão
            // (ela é da ação "Suspender" — changeMedicationStatus()) e nunca
            // manda `ended_on`; sem chave, preserva a data já gravada em vez
            // de zerar. riskAlerts() cai pra updated_at quando ended_on é
            // null e reinicia à toa a janela de persistência do risco
            // (3ª revisão Codex, P2). Chave presente (mesmo vazia) continua
            // limpando — é a reativação (changeMedicationStatus()) quem usa.
            'ended_on' => array_key_exists('ended_on', $data)
                ? (blank($data['ended_on']) ? null : $data['ended_on'])
                : $existing?->ended_on,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];
    }

    /** Item do catálogo (global ou da clínica do paciente) — de outra clínica, nada. */
    private function catalogMedicine(Patient $patient, mixed $id): ?Medicine
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Medicine::query()
            ->whereKey($id)
            ->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $patient->entity_id))
            ->first();
    }

    /**
     * @param array<string, string> $names
     *
     * @return array<string, mixed>
     */
    private function serializeAllergy(PatientAllergy $a, array $names): array
    {
        return [
            'id'                  => (string) $a->getKey(),
            'category'            => $a->category->value,
            'category_label'      => $a->category->label(),
            'substance'           => $a->substance,
            'active_ingredient'   => $a->active_ingredient,
            'medicine_id'         => $a->medicine_id ? (string) $a->medicine_id : null,
            'medicine_label'      => $a->medicine ? trim($a->medicine->name . ' ' . $a->medicine->concentration) : null,
            'reaction'            => $a->reaction,
            'severity'            => $a->severity?->value,
            'severity_label'      => $a->severity?->label(),
            'status'              => $a->status->value,
            'status_label'        => $a->status->label(),
            'active'              => $a->isActive(),
            'notes'               => $a->notes,
            'registered_by'       => $names[(string) $a->created_by] ?? null,
            'registered_at_label' => $this->dateTimeLabel($a->created_at),
            'confirmed_by'        => $names[(string) $a->confirmed_by] ?? null,
            'confirmed_at_label'  => $this->dateTimeLabel($a->confirmed_at),
            'discarded_by'        => $names[(string) $a->discarded_by] ?? null,
            'discarded_at_label'  => $this->dateTimeLabel($a->discarded_at),
            'discard_reason'      => $a->discard_reason,
        ];
    }

    /**
     * @param array<string, string> $names
     *
     * @return array<string, mixed>
     */
    private function serializeMedication(PatientMedication $m, array $names): array
    {
        return [
            'id'                  => (string) $m->getKey(),
            'medicine_id'         => $m->medicine_id ? (string) $m->medicine_id : null,
            'name'                => $m->name,
            'active_ingredient'   => $m->active_ingredient,
            'posology'            => $m->posology,
            'started_on'          => $m->started_on?->toDateString(),
            'started_on_label'    => $this->dateLabel($m->started_on),
            'ended_on'            => $m->ended_on?->toDateString(),
            'ended_on_label'      => $this->dateLabel($m->ended_on),
            'status'              => $m->status->value,
            'status_label'        => $m->status->label(),
            'source'              => $m->source->value,
            'source_label'        => $m->source->label(),
            'confirmed'           => $m->confirmed_at !== null,
            'confirmed_by'        => $names[(string) $m->confirmed_by] ?? null,
            'confirmed_at_label'  => $this->dateTimeLabel($m->confirmed_at),
            'discarded_by'        => $names[(string) $m->discarded_by] ?? null,
            'discarded_at_label'  => $this->dateTimeLabel($m->discarded_at),
            'registered_by'       => $names[(string) $m->created_by] ?? null,
            'registered_at_label' => $this->dateTimeLabel($m->created_at),
            'notes'               => $m->notes,
        ];
    }

    /**
     * @param iterable<mixed> $ids
     *
     * @return array<string, string>
     */
    private function userNames(iterable $ids): array
    {
        $ids = collect($ids)->filter()->map(fn ($id) => (string) $id)->unique()->values();

        return $ids->isEmpty() ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])
            ->all();
    }

    private function dateTimeLabel(?CarbonInterface $at): ?string
    {
        return $at?->copy()->locale(app()->getLocale())->isoFormat('L LT');
    }

    private function dateLabel(?CarbonInterface $at): ?string
    {
        return $at?->copy()->locale(app()->getLocale())->isoFormat('L');
    }
}
