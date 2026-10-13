<?php

declare(strict_types=1);

/**
 * Concorrência real (duas conexões PostgreSQL) em volta da assinatura do
 * prontuário — achado via auditoria de encerramento, EE-04.
 *
 * Os hooks updating/deleting de Signable só conferiam is_locked da instância
 * CARREGADA EM MEMÓRIA, nunca o estado atual persistido. Uma instância
 * carregada antes de outra sessão assinar — aba antiga, autosave atrasado —
 * conservava is_locked=false e passava pelo guard, mesmo com o registro já
 * assinado e travado no banco por outra conexão nesse meio-tempo.
 *
 * A "outra sessão" aqui é uma SEGUNDA conexão PostgreSQL real (igual
 * RecordVersionConcurrencyTest), não uma simulação dentro da mesma
 * transação: ela assina e COMMITA de verdade, sem a transação de wrap do
 * teste. Por isso este arquivo fica em tests/Integration (não
 * tests/Feature/RefreshDatabase) — uma linha criada dentro da transação de
 * RefreshDatabase nunca fica visível pra uma segunda conexão de verdade.
 * Roda explícito por caminho (não entra em ./vendor/bin/pest sem argumento
 * — tests/Integration não está nos testsuites do phpunit.xml).
 *
 * DatabaseMigrations não envolve o teste numa transação (a segunda conexão só
 * vê o que está REALMENTE commitado): o que cada teste cria — inclusive o
 * prontuário ASSINADO, com hash "2:" + sha256 (66 chars) — fica no banco até
 * o migrate:rollback automático do fim do teste. É de propósito: esse
 * rollback com assinaturas já gravadas é a condição de produção que a
 * migration de signature_hash precisa suportar (antes o down() estreitava a
 * coluna pra 64 e estourava; este arquivo apagava os assinados pra não ver o
 * erro — achado via revisão adversarial Codex).
 */

use App\Enums\{ClientRule, DocumentationType};
use App\Exceptions\{LockedMedicalRecordException, StaleMedicalRecordRevisionException};
use App\Models\{Covenant, Doctor, Entity, EntityUser, MedicalRecord, Patient, People, User};
use App\Models\MedicalRecordDocumentation;
use App\Services\{MedicalRecordService, MedicalRecordSignatureService};
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

const SIGC_PROBE_CONNECTION = 'pgsql_probe_signable';

/** Segunda sessão PostgreSQL real: o que ela grava já está commitado. */
function sigcProbe(): Connection
{
    config(['database.connections.' . SIGC_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    return DB::connection(SIGC_PROBE_CONNECTION);
}

/**
 * @return array{record: MedicalRecord, entityUser: EntityUser}
 */
function sigcNewRecord(): array
{
    $entity     = Entity::factory()->create(['is_client' => true]);
    $user       = User::factory()->create();
    $entityUser = createEntityUser($entity, $user, ClientRule::Doctor->value);
    $doctor     = Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);
    $patient = Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
    $record = MedicalRecord::create([
        'entity_id'      => $entity->id,
        'patient_id'     => $patient->id,
        'doctor_id'      => $doctor->id,
        'main_complaint' => 'Original',
    ]);

    return ['record' => $record, 'entityUser' => $entityUser->fresh()];
}

beforeEach(function () {
    sigcProbe();
});

afterEach(function () {
    DB::purge(SIGC_PROBE_CONNECTION);
});

it('[GAP] instância antiga tenta assinar depois que OUTRA conexão já assinou e commitou — rejeitada, nada sobrescreve', function () {
    ['record' => $record, 'entityUser' => $entityUser] = sigcNewRecord();

    // $stale representa a aba/requisição antiga: carregada ANTES de qualquer assinatura.
    $stale = MedicalRecord::find($record->id);

    // Outra conexão real assina e COMMITA de verdade (sem a transação do teste).
    MedicalRecord::on(SIGC_PROBE_CONNECTION)->find($record->id)->sign($entityUser);
    $signatureHashFromProbe = MedicalRecord::on(SIGC_PROBE_CONNECTION)->find($record->id)->signature_hash;

    expect(fn () => app(MedicalRecordSignatureService::class)->sign($stale, $entityUser))
        ->toThrow(LockedMedicalRecordException::class);

    $final = $record->fresh();
    expect($final->signature_hash)->toBe($signatureHashFromProbe)
        ->and($final->is_locked)->toBeTrue();
});

it('[GAP] instância antiga tenta editar depois que OUTRA conexão já assinou e commitou — rejeitada, edição não persiste', function () {
    ['record' => $record, 'entityUser' => $entityUser] = sigcNewRecord();

    $stale = MedicalRecord::find($record->id);

    MedicalRecord::on(SIGC_PROBE_CONNECTION)->find($record->id)->sign($entityUser);

    expect(fn () => app(MedicalRecordService::class)->update($stale, ['main_complaint' => 'Editado depois de assinado']))
        ->toThrow(LockedMedicalRecordException::class);

    expect($record->fresh()->main_complaint)->toBe('Original');
});

it('[GAP] instância antiga tenta excluir depois que OUTRA conexão já assinou e commitou — rejeitada', function () {
    ['record' => $record, 'entityUser' => $entityUser] = sigcNewRecord();

    $stale = MedicalRecord::find($record->id);

    MedicalRecord::on(SIGC_PROBE_CONNECTION)->find($record->id)->sign($entityUser);

    expect(fn () => app(MedicalRecordService::class)->delete($stale))
        ->toThrow(LockedMedicalRecordException::class);

    expect($record->fresh()->trashed())->toBeFalse();
});

/**
 * @return array{record: MedicalRecord, documentation: MedicalRecordDocumentation}
 */
function sigcNewRecordWithDocumentation(): array
{
    ['record' => $record] = sigcNewRecord();

    $documentation = MedicalRecordDocumentation::create([
        'medical_record_id' => $record->id,
        'patient_id'        => $record->patient_id,
        'doctor_id'         => $record->doctor_id,
        'type'              => DocumentationType::Report->value,
        'title'             => 'Laudo original',
        'content'           => '<p>Conteúdo original</p>',
    ]);

    return ['record' => $record, 'documentation' => $documentation];
}

it('[GAP] mutação de documentação BLOQUEIA enquanto sign() do prontuário dono está em voo (não comitado) — achado via revisão adversarial Codex', function () {
    ['record' => $record, 'documentation' => $documentation] = sigcNewRecordWithDocumentation();
    $probe                                                   = sigcProbe();

    try {
        // Simula sign() em voo: a MESMA trava que Signable::whileUnlocked()
        // tira (lockForUpdate na linha do prontuário) e a MESMA escrita que
        // sign() faria (is_locked=true) — só que SEM comitar ainda, pra
        // provar que quem tenta mutar a documentação do mesmo prontuário
        // precisa ESPERAR, não só ler um is_locked desatualizado.
        $probe->beginTransaction();
        $probe->table('medical_records')->where('id', $record->id)->lockForUpdate()->first();
        $probe->table('medical_records')->where('id', $record->id)->update(['is_locked' => true]);

        // Conexão principal: lock_timeout curto pra provar que BLOQUEOU (em
        // vez de só confiar num timeout de verdade/sleep, frágil e lento).
        // Sem o lockForUpdate() do fix, esta escrita nunca tentaria travar a
        // linha do prontuário — passaria direto lendo is_locked=false (não
        // comitado ainda do ponto de vista desta conexão) e a query abaixo
        // teria sucesso em vez de estourar o timeout.
        DB::statement("SET lock_timeout = '500ms'");

        // "55P03" é o SQLSTATE de verdade do Postgres pra "Lock not
        // available" — a mensagem detalhada do servidor vem no idioma da
        // sessão (pt_BR aqui), só o código é estável entre idiomas.
        expect(fn () => $documentation->update(['title' => 'Tentativa durante assinatura em voo']))
            ->toThrow(QueryException::class, '55P03');

        // Depois do rollback (sign() "em voo" nunca comitou): a
        // documentação nunca foi alterada e o prontuário continua destravado.
        expect($documentation->fresh()->title)->toBe('Laudo original');
    } finally {
        DB::statement('SET lock_timeout = DEFAULT');
        $probe->rollBack();
    }
});

it('[GAP] mutação de documentação é REJEITADA depois que sign() do prontuário dono comitou — nunca sobrescreve um registro já assinado', function () {
    ['record' => $record, 'documentation' => $documentation] = sigcNewRecordWithDocumentation();
    $entityUser                                              = EntityUser::find($record->doctor->entity_user_id);

    MedicalRecord::on(SIGC_PROBE_CONNECTION)->find($record->id)->sign($entityUser);

    expect(fn () => $documentation->update(['title' => 'Edição depois de assinado']))
        ->toThrow(LockedMedicalRecordException::class);

    expect(fn () => $documentation->delete())
        ->toThrow(LockedMedicalRecordException::class);

    expect($documentation->fresh()->title)->toBe('Laudo original')
        ->and($documentation->fresh()->trashed())->toBeFalse();
});

it('[GAP] bookkeeping pós-assinatura (saveQuietly/deleteQuietly) continua passando por cima do lock de propósito — não regride', function () {
    ['record' => $record, 'documentation' => $documentation] = sigcNewRecordWithDocumentation();
    $entityUser                                              = EntityUser::find($record->doctor->entity_user_id);

    MedicalRecord::on(SIGC_PROBE_CONNECTION)->find($record->id)->sign($entityUser);

    // Mesmo padrão de EyeImageReportController/EyeImageReportResendController
    // (bookkeeping de entrega de e-mail num documento de prontuário já
    // assinado) — saveQuietly() precisa continuar funcionando mesmo com
    // o prontuário travado, só a escrita COM eventos (save() normal) é
    // bloqueada.
    $documentation->forceFill(['title' => 'Atualizado via saveQuietly'])->saveQuietly();

    expect($documentation->fresh()->title)->toBe('Atualizado via saveQuietly');
});

it('[GAP] duas conexões tentam assinar o mesmo prontuário: só a primeira a commitar vence', function () {
    ['record' => $record, 'entityUser' => $entityUser] = sigcNewRecord();

    // $record (a instância ORIGINAL, carregada antes de qualquer assinatura)
    // tenta assinar pela conexão principal DEPOIS da outra conexão já ter
    // commitado — mesma instância antiga, agora no papel de "segunda a assinar".
    MedicalRecord::on(SIGC_PROBE_CONNECTION)->find($record->id)->sign($entityUser);
    $signatureHashFromProbe = MedicalRecord::on(SIGC_PROBE_CONNECTION)->find($record->id)->signature_hash;

    expect(fn () => app(MedicalRecordSignatureService::class)->sign($record, $entityUser))
        ->toThrow(LockedMedicalRecordException::class);

    expect($record->fresh()->signature_hash)->toBe($signatureHashFromProbe);
});

it('[GAP] documento sendo gravado em OUTRA sessão (ainda sem commit) segura o sign() até comitar — e a revisão que a tela mostrou deixa de valer (achado via revisão adversarial Codex)', function () {
    ['record' => $record, 'entityUser' => $entityUser] = sigcNewRecord();
    $seenRevision                                      = $record->fresh()->clinical_revision;
    $probe                                             = sigcProbe();

    try {
        // "Outra sessão" (a conexão principal, aberta numa transação): emite um
        // laudo. O guard trava a linha do prontuário, grava o documento e sobe
        // clinical_revision — na MESMA transação, ainda SEM comitar.
        DB::beginTransaction();
        MedicalRecordDocumentation::create([
            'medical_record_id' => $record->id,
            'patient_id'        => $record->patient_id,
            'doctor_id'         => $record->doctor_id,
            'type'              => DocumentationType::Report->value,
            'title'             => 'Laudo emitido durante a assinatura',
            'content'           => '<p>Novo</p>',
        ]);

        // Quem vai assinar (conexão real separada) com a revisão que a gaveta
        // mostrou antes do laudo: precisa ESPERAR a linha travada, não passar
        // direto lendo a revisão antiga ainda não comitada. lock_timeout curto
        // prova que bloqueou; "55P03" é o SQLSTATE de "Lock not available".
        $probe->statement("SET lock_timeout = '500ms'");

        expect(fn () => MedicalRecord::on(SIGC_PROBE_CONNECTION)->find($record->id)->sign($entityUser, $seenRevision))
            ->toThrow(QueryException::class, '55P03');

        // Laudo comitado: a revisão que a gaveta mostrava já não vale.
        DB::commit();
        $probe->statement('SET lock_timeout = DEFAULT');

        expect(fn () => MedicalRecord::on(SIGC_PROBE_CONNECTION)->find($record->id)->sign($entityUser, $seenRevision))
            ->toThrow(StaleMedicalRecordRevisionException::class);

        $final = $record->fresh();
        expect($final->isSigned())->toBeFalse()
            ->and($final->clinical_revision)->toBeGreaterThan($seenRevision)
            ->and(MedicalRecordDocumentation::count())->toBe(1);
    } finally {
        $probe->statement('SET lock_timeout = DEFAULT');

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});
