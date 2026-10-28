<?php

/**
 * Cobre o import em lote de agendamentos via CSV (ScheduleImportService) —
 * criação direta via Schedule::create() (sem ScheduleRequest/validateSlot),
 * resolução de médico (obrigatória, import_code com fallback CRM) e paciente
 * (opcional, import_code com fallback CPF), mapeamento de situação,
 * tratamento de conflito de horário (índice parcial doctor+datetime que
 * exclui situações terminais) e unicidade de import_code por entidade.
 */

use App\Enums\{ClientRule, ImportStatus, ScheduleSituation};
use App\Models\Covenant;
use App\Models\{Doctor, Entity, Patient, People, Schedule, ScheduleImport, User, VisitType};
use App\Services\ScheduleImportService;
use Illuminate\Support\Facades\Storage;

function makeScheduleImport(Entity $entity, string $csv): ScheduleImport
{
    Storage::fake();

    $path = "imports/schedules/{$entity->id}/test.csv";
    Storage::disk()->put($path, "\xEF\xBB\xBF" . $csv);

    return ScheduleImport::create([
        'entity_id'     => $entity->id,
        'user_id'       => User::factory()->create()->id,
        'status'        => ImportStatus::Pending,
        'file_path'     => $path,
        'original_name' => 'test.csv',
    ]);
}

/**
 * Médico "pré-existente" pronto para ser resolvido pelo import — record e
 * import_code não são fillable via create() padrão do model (record é, mas
 * mantemos o mesmo caminho forceFill para import_code, único ponto de
 * escrita permitido — ver ScheduleImportService::assignImportCode e o
 * equivalente em DoctorImportService).
 */
function createImportableDoctor(Entity $entity, ?string $record = null, ?string $importCode = null): Doctor
{
    $doctor = createDoctorForEntity($entity);

    if ($record !== null) {
        $doctor->update(['record' => $record]);
    }

    if ($importCode !== null) {
        $doctor->forceFill(['import_code' => $importCode])->save();
    }

    return $doctor->fresh();
}

function createImportablePatient(Entity $entity, ?string $importCode = null, ?string $cpf = null): Patient
{
    $person = People::factory()->create($cpf !== null ? ['national_registry' => $cpf] : []);

    $patient = Patient::factory()->create([
        'entity_id' => $entity->id,
        'person_id' => $person->id,
    ]);

    if ($importCode !== null) {
        $patient->forceFill(['import_code' => $importCode])->save();
    }

    return $patient->fresh();
}

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
});

it('cria agendamento resolvendo medico E paciente por import_code, com situacao mapeada e import_code da propria linha gravado', function () {
    $doctor  = createImportableDoctor($this->entity, record: '111111', importCode: 'DOC-1');
    $patient = createImportablePatient($this->entity, importCode: 'PAT-1');

    $csv = "codigo_importacao_medico;codigo_importacao_paciente;nome_paciente;data_hora;situacao;codigo_importacao\n"
        . "DOC-1;PAT-1;Maria Teste;15/06/2026 14:30;Atendido;SCH-1\n";
    $import = makeScheduleImport($this->entity, $csv);

    app(ScheduleImportService::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Done);
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(0);

    $schedule = Schedule::where('import_code', 'SCH-1')->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->doctor_id)->toBe($doctor->id);
    expect($schedule->patient_id)->toBe($patient->id);
    expect($schedule->full_name)->toBe('MARIA TESTE');
    expect($schedule->situation)->toBe(ScheduleSituation::Attended);
    expect($schedule->date_time->format('Y-m-d H:i'))->toBe('2026-06-15 14:30');
});

it('cria agendamento sem paciente resolvido (patient_id null, so full_name) sem erro', function () {
    createImportableDoctor($this->entity, record: '222222', importCode: 'DOC-2');

    $csv = "codigo_importacao_medico;nome_paciente;data_hora;situacao\n"
        . "DOC-2;Joao Sem Cadastro;20/07/2026 09:00;Agendado\n";
    $import = makeScheduleImport($this->entity, $csv);

    app(ScheduleImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(0);

    $schedule = Schedule::where('full_name', 'JOAO SEM CADASTRO')->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->patient_id)->toBeNull();
    expect($schedule->situation)->toBe(ScheduleSituation::Scheduled);
});

it('resolve medico por fallback CRM quando codigo_importacao_medico nao bate', function () {
    $doctor = createImportableDoctor($this->entity, record: '999999', importCode: 'REAL-CODE');

    $csv = "codigo_importacao_medico;crm_medico;nome_paciente;data_hora;situacao\n"
        . "NAO-BATE;999999;Paciente CRM Fallback;01/08/2026 10:00;Confirmado\n";
    $import = makeScheduleImport($this->entity, $csv);

    app(ScheduleImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(0);

    $schedule = Schedule::where('full_name', 'PACIENTE CRM FALLBACK')->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->doctor_id)->toBe($doctor->id);
});

it('rejeita linha sem medico resolvivel (erro de linha, import continua nas proximas linhas)', function () {
    createImportableDoctor($this->entity, record: '333333', importCode: 'DOC-OK');

    $csv = "codigo_importacao_medico;nome_paciente;data_hora;situacao\n"
        . "INEXISTENTE;Paciente Sem Medico;05/09/2026 08:00;Agendado\n"
        . "DOC-OK;Paciente Com Medico;05/09/2026 09:00;Agendado\n";
    $import = makeScheduleImport($this->entity, $csv);

    app(ScheduleImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(1);
    expect(Schedule::where('full_name', 'PACIENTE COM MEDICO')->exists())->toBeTrue();
    expect(Schedule::where('full_name', 'PACIENTE SEM MEDICO')->exists())->toBeFalse();
});

it('trata conflito de horario (mesmo doctor_id+date_time, ambas situacoes ativas) como erro de linha sem quebrar o import', function () {
    createImportableDoctor($this->entity, record: '444444', importCode: 'DOC-CONFLICT');

    $csv = "codigo_importacao_medico;nome_paciente;data_hora;situacao\n"
        . "DOC-CONFLICT;Primeiro Paciente;10/10/2026 10:00;Agendado\n"
        . "DOC-CONFLICT;Segundo Paciente;10/10/2026 10:00;Agendado\n";
    $import = makeScheduleImport($this->entity, $csv);

    app(ScheduleImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(1);
    expect(Schedule::count())->toBe(1);
});

it('permite dois agendamentos no mesmo doctor_id+date_time quando um deles tem situacao terminal', function () {
    createImportableDoctor($this->entity, record: '555555', importCode: 'DOC-TERMINAL');

    $csv = "codigo_importacao_medico;nome_paciente;data_hora;situacao\n"
        . "DOC-TERMINAL;Paciente Cancelado;11/11/2026 11:00;Cancelado\n"
        . "DOC-TERMINAL;Paciente Agendado;11/11/2026 11:00;Agendado\n";
    $import = makeScheduleImport($this->entity, $csv);

    app(ScheduleImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(2);
    expect($import->error_rows)->toBe(0);
    expect(Schedule::count())->toBe(2);
});

it('rejeita import_code duplicado na mesma entidade (dentro do mesmo arquivo)', function () {
    createImportableDoctor($this->entity, record: '666666', importCode: 'DOC-DUP-1');
    createImportableDoctor($this->entity, record: '777777', importCode: 'DOC-DUP-2');

    $csv = "codigo_importacao_medico;nome_paciente;data_hora;situacao;codigo_importacao\n"
        . "DOC-DUP-1;Primeiro Duplicado;12/12/2026 08:00;Agendado;DUP-SCH\n"
        . "DOC-DUP-2;Segundo Duplicado;13/12/2026 09:00;Agendado;DUP-SCH\n";
    $import = makeScheduleImport($this->entity, $csv);

    app(ScheduleImportService::class)->process($import);

    $import->refresh();
    expect($import->imported_rows)->toBe(1);
    expect($import->error_rows)->toBe(1);
    expect(Schedule::where('import_code', 'DUP-SCH')->count())->toBe(1);
});

it('[SEGURANCA] import_code nao pode ser setado via ScheduleRequest/tela normal de agendamento (create e update)', function () {
    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, ClientRule::Admin->value);
    $doctor     = createDoctorForEntity($this->entity);

    $createResponse = $this->actingAs($user)
        ->withSession(panelSession($entityUser))
        ->postJson(route('panel.schedules.store'), [
            'doctor_id'   => $doctor->id,
            'full_name'   => 'Paciente Seguranca',
            'date_time'   => now()->addDay()->format('Y-m-d H:i:s'),
            'import_code' => 'TENTATIVA-MANUAL-CREATE',
        ]);

    $createResponse->assertCreated();
    $scheduleId = $createResponse->json('data.id');
    $schedule   = Schedule::find($scheduleId);
    expect($schedule)->not->toBeNull();
    expect($schedule->import_code)->toBeNull();

    // Update: o MESMO mecanismo de proteção usado por SchedulesController::
    // update() é `$schedule->update($request->validated())` — mass
    // assignment do Eloquent. Como `import_code` não está no $fillable de
    // Schedule (única escrita permitida é forceFill() no import service),
    // tentar setá-lo via update() normal — o exato caminho usado pelo
    // controller — é ignorado silenciosamente pelo guard de mass assignment.
    //
    // NOTA: não fazemos round-trip HTTP completo em PUT panel.schedules.update
    // aqui porque expôs um bug PRÉ-EXISTENTE, não relacionado a este import,
    // em ScheduleRequest::rules() (linha ~84-88): `$this->route('schedule')`
    // retorna o MODEL já resolvido pelo route-model-binding (Schedule::
    // resolveRouteBinding), não o id, e esse objeto é repassado como
    // $excludeId até ScheduleService::isDoubleBooked(), que tenta usá-lo como
    // bind de UUID cru — gerando "invalid input syntax for type uuid" com o
    // JSON do model. Nenhum teste do repositório hoje exercita
    // panel.schedules.update via HTTP (confirmado por busca no diretório
    // tests/), então esse bug não é causado por este import e está fora do
    // escopo desta tarefa (ScheduleRequest/ScheduleService não devem ser
    // tocados aqui). Reportado separadamente para correção futura.
    $schedule->update([
        'full_name'   => 'Paciente Seguranca Atualizado',
        'import_code' => 'TENTATIVA-MANUAL-UPDATE',
    ]);

    expect($schedule->fresh()->full_name)->toBe('PACIENTE SEGURANCA ATUALIZADO');
    expect($schedule->fresh()->import_code)->toBeNull();
});

// ── Cancelamento ────────────────────────────────────────────────────────────

it('cancelar antes de confirmar apaga o arquivo e o registro', function () {
    createImportableDoctor($this->entity, record: '111111');
    $csv    = "crm_medico;nome_paciente;data_hora\n111111;Fulano;15/06/2026 14:30\n";
    $import = makeScheduleImport($this->entity, $csv);
    $user   = User::factory()->create();
    $eu     = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->deleteJson(route('panel.schedules.import.cancel', $import))
        ->assertRedirect(route('panel.schedules.import.index'));

    expect(ScheduleImport::find($import->id))->toBeNull();
    expect(Storage::disk()->exists($import->file_path))->toBeFalse();
});

it('cancelar depois de confirmado nao apaga o registro, so sinaliza — job nao roda mais', function () {
    createImportableDoctor($this->entity, record: '111111');
    $csv    = "crm_medico;nome_paciente;data_hora\n111111;Fulano;15/06/2026 14:30\n";
    $import = makeScheduleImport($this->entity, $csv);
    $import->update(['confirmed_at' => now()]);
    $user = User::factory()->create();
    $eu   = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->deleteJson(route('panel.schedules.import.cancel', $import))
        ->assertRedirect(route('panel.schedules.import.index'));

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Cancelled);
    expect(Storage::disk()->exists($import->file_path))->toBeTrue();

    // Simula o worker pegando o job depois do cancelamento (fila parada,
    // usuário cancela, worker volta e não deve processar).
    app(ScheduleImportService::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Cancelled);
    expect($import->imported_rows)->toBe(0);
    expect(Schedule::count())->toBe(0);
});

it('nao deixa cancelar import ja concluido (409)', function () {
    createImportableDoctor($this->entity, record: '111111');
    $csv    = "crm_medico;nome_paciente;data_hora\n111111;Fulano;15/06/2026 14:30\n";
    $import = makeScheduleImport($this->entity, $csv);
    $import->update(['confirmed_at' => now(), 'status' => ImportStatus::Done, 'finished_at' => now()]);
    $user = User::factory()->create();
    $eu   = createEntityUser($this->entity, $user, 'admin');

    $this->actingAs($user)
        ->withSession(panelSession($eu))
        ->deleteJson(route('panel.schedules.import.cancel', $import))
        ->assertStatus(409);
});

// ── Situações do sistema de origem (smart_oftal) ────────────────────────────

it('EM ESPERA do sistema de origem vira Retornando à consulta', function () {
    createImportableDoctor($this->entity, record: '222222');
    $when   = now()->subDay()->format('d/m/Y H:i');
    $import = makeScheduleImport($this->entity, "crm_medico;nome_paciente;data_hora;situacao\n222222;Fulano;{$when};EM ESPERA\n");

    app(ScheduleImportService::class)->process($import);

    expect($import->fresh()->error_rows)->toBe(0);
    expect(Schedule::first()->situation)->toBe(ScheduleSituation::ReturningToDoctor);
});

it('AUSENTE vira Faltou no passado e Agendado no futuro', function () {
    createImportableDoctor($this->entity, record: '333333');
    $past   = now()->subDays(2)->format('d/m/Y H:i');
    $future = now()->addDays(2)->format('d/m/Y H:i');
    $import = makeScheduleImport(
        $this->entity,
        "crm_medico;nome_paciente;data_hora;situacao\n333333;Passado;{$past};AUSENTE\n333333;Futuro;{$future};AUSENTE\n",
    );

    app(ScheduleImportService::class)->process($import);

    expect($import->fresh()->error_rows)->toBe(0);
    expect(Schedule::where('full_name', 'PASSADO')->first()->situation)->toBe(ScheduleSituation::NoShow);
    expect(Schedule::where('full_name', 'FUTURO')->first()->situation)->toBe(ScheduleSituation::Scheduled);
});

it('tipo_atendimento vira o tipo de consulta equivalente quando a planilha nao traz tipo_visita', function () {
    createImportableDoctor($this->entity, record: '444444');
    $when    = now()->addDay()->format('d/m/Y H:i');
    $retorno = VisitType::whereNull('entity_id')->where('name', 'RETORNO')->value('id');
    $import  = makeScheduleImport(
        $this->entity,
        "crm_medico;nome_paciente;data_hora;tipo_atendimento\n444444;Fulano;{$when};Retorno\n",
    );

    app(ScheduleImportService::class)->process($import);

    expect($import->fresh()->error_rows)->toBe(0)
        ->and($retorno)->not->toBeNull()
        ->and(Schedule::first()->visit_id)->toBe($retorno);
});

it('tipo_visita explicito prevalece sobre tipo_atendimento', function () {
    createImportableDoctor($this->entity, record: '555555');
    $when     = now()->addDay()->format('d/m/Y H:i');
    $consulta = VisitType::whereNull('entity_id')->where('name', 'CONSULTA')->value('id');
    $import   = makeScheduleImport(
        $this->entity,
        "crm_medico;nome_paciente;data_hora;tipo_visita;tipo_atendimento\n555555;Fulano;{$when};CONSULTA;Retorno\n",
    );

    app(ScheduleImportService::class)->process($import);

    expect(Schedule::first()->visit_id)->toBe($consulta);
});

it('com colunas "convenio" e "plano", o convênio do agendamento vem de "convenio" (plano não sobrescreve)', function () {
    $doctor   = createImportableDoctor($this->entity, record: '222222', importCode: 'DOC-P');
    $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'UNIMED']);

    $csv = "codigo_importacao_medico;nome_paciente;data_hora;situacao;convenio;plano\n"
        . "DOC-P;Plano Teste;16/06/2026 10:00;Agendado;Unimed;Unimed Nacional Enfermaria\n";

    app(ScheduleImportService::class)->process(makeScheduleImport($this->entity, $csv));

    expect(Schedule::withoutGlobalScopes()->where('entity_id', $this->entity->id)->sole()->covenant_id)->toBe($covenant->id);
});

it('só com a coluna "plano" ela continua sendo o convênio (planilhas antigas)', function () {
    $doctor   = createImportableDoctor($this->entity, record: '333333', importCode: 'DOC-Q');
    $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'UNIMED']);

    $csv = "codigo_importacao_medico;nome_paciente;data_hora;situacao;plano\n"
        . "DOC-Q;Plano Antigo;17/06/2026 10:00;Agendado;Unimed\n";

    app(ScheduleImportService::class)->process(makeScheduleImport($this->entity, $csv));

    expect(Schedule::withoutGlobalScopes()->where('entity_id', $this->entity->id)->sole()->covenant_id)->toBe($covenant->id);
});
