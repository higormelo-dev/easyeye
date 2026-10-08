<?php

declare(strict_types=1);

use App\Enums\{ClientRule, FeatureKey, ImportStatus, SubscriptionStatus};
use App\Enums\ClinicalSafety\{AllergyCategory, AllergyStatus};
use App\Events\ImportProgressUpdated;
use App\Jobs\ProcessPatientImportJob;
use App\Models\{AuditLog, Covenant, Entity, Patient, PatientAllergy, PatientImport, Plan, PlanFeature, Subscription, User};
use App\Services\PatientImportService;
use Illuminate\Support\Facades\{Event, Storage};

/*
 * Fase 2 / G4 — alergias na importação de pacientes por planilha.
 *
 * Colunas opcionais: `alergias` (substâncias separadas por ";") → cada item
 * vira alergia "informada pelo paciente" (categoria "outro"), em nome de quem
 * enviou a planilha; nunca repete a que o paciente já tem (ativa OU
 * descartada; sem acento/maiúsculas) — reimportar a mesma planilha não
 * duplica nem ressuscita o que o médico descartou. `nega_alergias` (sim/não)
 * só vale sem alergia na linha nem ativa no paciente. Progresso segue por
 * broadcast (sem endpoint de status).
 */
beforeEach(function () {
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();
    $this->entity = $entity;

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxPatients->value, 'value' => '0']);
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
    Covenant::factory()->create(['entity_id' => $entity->id, 'name' => 'Particular']);

    $this->uploader = User::factory()->create(['name' => 'Rita Recepção']);
    Storage::fake();
});

function g4fase2Import(object $test, string $csv): PatientImport
{
    $path = "imports/patients/{$test->entity->id}/" . uniqid('g4_', true) . '.csv';
    Storage::disk()->put($path, "\xEF\xBB\xBF" . $csv);

    return PatientImport::create([
        'entity_id'     => $test->entity->id,
        'user_id'       => $test->uploader->id,
        'status'        => ImportStatus::Pending,
        'file_path'     => $path,
        'original_name' => 'pacientes.csv',
    ]);
}

function g4fase2Run(object $test, string $csv): PatientImport
{
    $import = g4fase2Import($test, $csv);
    app(PatientImportService::class)->process($import);

    return $import->refresh();
}

function g4fase2Patient(object $test, string $name): Patient
{
    return Patient::query()->withoutGlobalScopes()->where('entity_id', $test->entity->id)
        ->whereHas('person', fn ($q) => $q->where('full_name', $name))->sole();
}

/** @return list<string> */
function g4fase2Substances(Patient $patient): array
{
    return PatientAllergy::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->orderBy('created_at')->orderBy('substance')->pluck('substance')->all();
}

/** Linhas do CSV de erros/avisos baixável. @return list<array<string, string>> */
function g4fase2ErrorRows(PatientImport $import): array
{
    if (! $import->errors_file_path) {
        return [];
    }

    $lines  = array_values(array_filter(explode("\n", ltrim((string) Storage::disk()->get($import->errors_file_path), "\xEF\xBB\xBF"))));
    $header = str_getcsv(array_shift($lines), ';');

    return array_map(fn (string $line) => array_combine($header, str_getcsv($line, ';')), $lines);
}

describe('coluna alergias', function () {
    it('cada substância vira alergia "informada pelo paciente" (categoria outro), em nome de quem enviou; sem repetir na linha', function () {
        $import = g4fase2Run($this, "nome;celular;convenio;alergias\nAna Alergica;11999990001;Particular;\"Dipirona; DIPÍRONA ; penicilina;  Látex \"\n");

        expect($import->status)->toBe(ImportStatus::Done)
            ->and($import->imported_rows)->toBe(1)
            ->and($import->error_rows)->toBe(0);

        $patient   = g4fase2Patient($this, 'ANA ALERGICA');
        $allergies = PatientAllergy::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->get();

        expect($allergies->pluck('substance')->sort()->values()->all())->toBe(['Dipirona', 'Látex', 'penicilina'])
            ->and($allergies->every(fn (PatientAllergy $a) => $a->status === AllergyStatus::Reported
                && $a->category === AllergyCategory::Other
                && $a->entity_id === $this->entity->id
                && $a->created_by === $this->uploader->id))->toBeTrue();

        // Trilha de auditoria em nome de quem enviou a planilha (dado sensível).
        expect(AuditLog::query()->where('auditable_type', PatientAllergy::class)->where('event', 'created')->pluck('user_id')->unique()->values()->all())
            ->toBe([$this->uploader->id]);
    });

    it('reimportar a MESMA planilha não duplica; variação de acento/maiúsculas também não; o descartado pelo médico não volta', function () {
        $csv = "nome;celular;convenio;alergias\nBeto Reimport;11999990002;Particular;\"Dipirona; Penicilina\"\n";
        g4fase2Run($this, $csv);
        $patient = g4fase2Patient($this, 'BETO REIMPORT');

        // Médico descartou a penicilina (teste negativo).
        PatientAllergy::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->where('substance', 'Penicilina')
            ->update(['status' => AllergyStatus::Discarded->value, 'discarded_at' => now()]);

        $second = g4fase2Run($this, $csv);
        expect($second->skipped_rows)->toBe(1);

        $third = g4fase2Run($this, "nome;celular;convenio;alergias\nBeto Reimport;11999990002;Particular;\"DIPÍRONA; penicilina; Látex\"\n");
        expect($third->skipped_rows)->toBe(1)->and($third->error_rows)->toBe(0);

        // Paciente existente não é sobrescrito; só a alergia NOVA (látex) entra.
        expect(g4fase2Substances($patient))->toHaveCount(3)
            ->and(PatientAllergy::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->active()->pluck('substance')->sort()->values()->all())
            ->toBe(['Dipirona', 'Látex']);
    });

    it('item curto/longo demais é ignorado com aviso; a linha é importada', function () {
        $import = g4fase2Run($this, "nome;celular;convenio;alergias\nCarla Aviso;11999990003;Particular;\"X; Iodo; " . str_repeat('a', 161) . "\"\n");

        expect($import->imported_rows)->toBe(1)->and($import->warning_rows)->toBe(1);
        expect(g4fase2Substances(g4fase2Patient($this, 'CARLA AVISO')))->toBe(['Iodo'])
            ->and(g4fase2ErrorRows($import)[0]['_erro'])->toContain('alergia "X" ignorada');
    });
});

describe('coluna nega_alergias', function () {
    it('"sim" registra que o paciente nega (quem enviou, quando); "não"/vazio = sem registro', function () {
        $import = g4fase2Run($this, "nome;celular;convenio;nega_alergias\nDora Nega;11999990004;Particular;Sim\nEdu Vazio;11999990005;Particular;não\nFabi Vazia;11999990006;Particular;\n");

        expect($import->imported_rows)->toBe(3)->and($import->warning_rows)->toBe(0);

        $dora = g4fase2Patient($this, 'DORA NEGA');
        expect($dora->allergies_denied_at)->not->toBeNull()
            ->and($dora->allergies_denied_by)->toBe($this->uploader->id)
            ->and(g4fase2Patient($this, 'EDU VAZIO')->allergies_denied_at)->toBeNull()
            ->and(g4fase2Patient($this, 'FABI VAZIA')->allergies_denied_at)->toBeNull();
    });

    it('ignorado quando a linha tem alergias (com aviso) ou o paciente já tem alergia ativa; não reescreve um "nega" já registrado', function () {
        $first = g4fase2Run($this, "nome;celular;convenio;alergias;nega_alergias\nGabi Conflito;11999990007;Particular;Dipirona;sim\nHugo Nega;11999990008;Particular;;sim\n");

        $gabi = g4fase2Patient($this, 'GABI CONFLITO');
        expect($gabi->allergies_denied_at)->toBeNull()
            ->and(g4fase2Substances($gabi))->toBe(['Dipirona'])
            ->and($first->warning_rows)->toBe(1)
            ->and(g4fase2ErrorRows($first)[0]['_erro'])->toContain('"nega_alergias" ignorado');

        $hugo     = g4fase2Patient($this, 'HUGO NEGA');
        $deniedAt = $hugo->allergies_denied_at;
        expect($deniedAt)->not->toBeNull();

        // Reimporta: Gabi (alergia ativa) continua sem "nega"; Hugo mantém quem/quando.
        $this->travel(2)->days();
        g4fase2Run($this, "nome;celular;convenio;nega_alergias\nGabi Conflito;11999990007;Particular;sim\nHugo Nega;11999990008;Particular;sim\n");

        expect($gabi->refresh()->allergies_denied_at)->toBeNull()
            ->and($hugo->refresh()->allergies_denied_at->equalTo($deniedAt))->toBeTrue();
    });

    it('alergia nova na reimportação desfaz o "nega" anterior', function () {
        g4fase2Run($this, "nome;celular;convenio;nega_alergias\nIvo Muda;11999990009;Particular;sim\n");
        g4fase2Run($this, "nome;celular;convenio;alergias\nIvo Muda;11999990009;Particular;Látex\n");

        $ivo = g4fase2Patient($this, 'IVO MUDA');
        expect($ivo->allergies_denied_at)->toBeNull()->and(g4fase2Substances($ivo))->toBe(['Látex']);
    });

    it('valor não reconhecido vira aviso e é ignorado', function () {
        $import = g4fase2Run($this, "nome;celular;convenio;nega_alergias\nJu Talvez;11999990010;Particular;talvez\n");

        expect($import->imported_rows)->toBe(1)->and($import->warning_rows)->toBe(1)
            ->and(g4fase2Patient($this, 'JU TALVEZ')->allergies_denied_at)->toBeNull()
            ->and(g4fase2ErrorRows($import)[0]['_erro'])->toContain('"talvez"');
    });
});

describe('job, pré-visualização e modelo', function () {
    it('o job processa as alergias e o progresso continua saindo por broadcast', function () {
        Event::fake([ImportProgressUpdated::class]);
        $import = g4fase2Import($this, "nome;celular;convenio;alergias\nKleber Job;11999990011;Particular;Iodo\n");

        (new ProcessPatientImportJob($import))->handle(app(PatientImportService::class));

        expect(g4fase2Substances(g4fase2Patient($this, 'KLEBER JOB')))->toBe(['Iodo'])
            ->and($import->refresh()->status)->toBe(ImportStatus::Done);
        Event::assertDispatched(ImportProgressUpdated::class, fn (ImportProgressUpdated $e) => $e->channel === $import->broadcastChannelName());
    });

    it('a pré-visualização reconhece as colunas de alergia', function () {
        $preview = app(PatientImportService::class)->generatePreview(
            g4fase2Import($this, "nome;celular;alergias;nega_alergias\nLia;11999990012;Dipirona;não\n"),
        );

        expect(collect($preview['mapped_columns'])->pluck('label')->all())->toContain('Alergias', 'Nega alergias')
            ->and($preview['unmapped_columns'])->toBe([]);
    });

    it('o modelo de planilha traz as colunas e o exemplo (lista entre aspas)', function () {
        $secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);

        $content = $this->actingAs($secretary->user)->withSession(panelSession($secretary))
            ->get(route('panel.patients.import.template'))
            ->assertOk()
            ->streamedContent();

        [$header, $example] = array_map(fn (string $l) => str_getcsv($l, ';'), array_values(array_filter(explode("\n", ltrim($content, "\xEF\xBB\xBF")))));

        $row = array_combine($header, $example);
        expect($row['alergias'])->toBe('Dipirona; Penicilina')
            ->and($row['nega_alergias'])->toBe('não');
    });
});
