<?php

declare(strict_types=1);

use App\Enums\{ClientRule, Permission};
use App\Models\{AuditLog, Entity, Patient, PatientExam, PermissionRecord, Role, User};
use App\Services\AuditService;
use Database\Seeders\PermissionsSeeder;

/**
 * Histórico (somente leitura) do cadastro e dos exames do paciente no Gerenciador
 * de Imagens: GET exam-manager/patients/{patient}/audit. Lê audit_logs; devolve o
 * quê/quando/quem, nunca conteúdo clínico, IP ou user-agent.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->doctorUser = User::factory()->create();
    $this->doctorEu   = createEntityUser($this->entity, $this->doctorUser, ClientRule::Doctor->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->exam    = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'laterality' => 1]);

    // O histórico começa do zero: a criação via factory não é o assunto destes testes.
    AuditLog::query()->delete();
});

function patientAuditAs($test, User $user, $entityUser, Patient $patient, array $query = [])
{
    return $test->actingAs($user)->withSession(panelSession($entityUser))
        ->getJson(route('panel.exam-manager.patients.audit', ['patient' => $patient] + $query));
}

/** Linha de audit_logs montada à mão (valores controlados). */
function auditRow(string $type, string $id, string $event, ?array $old, ?array $new, array $extra = []): AuditLog
{
    return AuditLog::query()->create(array_merge([
        'auditable_type' => $type,
        'auditable_id'   => $id,
        'event'          => $event,
        'old_values'     => $old,
        'new_values'     => $new,
        'created_at'     => now(),
    ], $extra));
}

function auditEntryFor(array $entries, string $target, string $event): ?array
{
    return collect($entries)->first(fn (array $e) => $e['target'] === $target && $e['event'] === $event);
}

describe('conteúdo do histórico', function () {
    it('alteração de exame (lateralidade 1→2) sai com campo, valores, código do exame e autor', function () {
        $this->actingAs($this->doctorUser);
        session(panelSession($this->doctorEu));

        $this->exam->update(['laterality' => 2]);

        $entries = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk()->json('entries');
        $entry   = auditEntryFor($entries, 'exam', 'updated');

        expect($entry)->not->toBeNull()
            ->and($entry['target_code'])->toBe($this->exam->code)
            ->and($entry['actor_name'])->toBe($this->doctorUser->name)
            ->and($entry['actor_kind'])->toBe('user')
            ->and($entry['changes'])->toBe([['field' => 'laterality', 'old' => 1, 'new' => 2, 'redacted' => false]]);
    });

    it('campo fora da allowlist (observation) sai só com o nome — o texto clínico nunca aparece', function () {
        $this->actingAs($this->doctorUser);
        session(panelSession($this->doctorEu));

        $this->exam->update(['observation' => 'Suspeita de glaucoma avançado em OD']);

        $response = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk();
        $entry    = auditEntryFor($response->json('entries'), 'exam', 'updated');

        expect($entry['changes'])->toBe([['field' => 'observation', 'old' => null, 'new' => null, 'redacted' => true]])
            ->and($response->getContent())->not->toContain('glaucoma');
    });

    it('urgente aparece com valor (a marcação é auditada)', function () {
        $this->actingAs($this->doctorUser);
        session(panelSession($this->doctorEu));

        $this->exam->update(['urgent' => true]);

        $entry = auditEntryFor(patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->json('entries'), 'exam', 'updated');

        expect($entry['changes'])->toHaveCount(1)
            ->and($entry['changes'][0]['field'])->toBe('urgent')
            ->and($entry['changes'][0]['redacted'])->toBeFalse()
            ->and((bool) $entry['changes'][0]['new'])->toBeTrue();
    });

    it('alteração do cadastro: prioridade sai com valor; campo pessoal (card_number) sai redigido', function () {
        $this->actingAs($this->doctorUser);
        session(panelSession($this->doctorEu));

        $this->patient->update(['priority_rating' => 3, 'card_number' => '1234567890']);

        $response = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk();
        $entry    = auditEntryFor($response->json('entries'), 'patient', 'updated');
        $byField  = collect($entry['changes'])->keyBy('field');

        expect($entry['target_code'])->toBe($this->patient->code)
            ->and($byField['priority_rating']['new'])->toBe(3)
            ->and($byField['priority_rating']['redacted'])->toBeFalse()
            ->and($byField['card_number'])->toBe(['field' => 'card_number', 'old' => null, 'new' => null, 'redacted' => true])
            ->and($response->getContent())->not->toContain('1234567890');
    });

    it('created, deleted e restored devolvem changes vazio (o evento é a informação, não o retrato do cadastro)', function () {
        $this->actingAs($this->doctorUser);
        session(panelSession($this->doctorEu));

        $other = Patient::factory()->create(['entity_id' => $this->entity->id]);
        $exam  = PatientExam::factory()->create(['patient_id' => $other->id]);
        $exam->delete();

        $other->delete();
        $other->restore();

        $entries = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $other)->assertOk()->json('entries');

        $events = collect($entries)->pluck('event')->unique();

        expect($events->all())->toContain('created', 'deleted', 'restored');

        // O restore também dispara um `updated` (deleted_at, que a trilha nunca guarda): sem campos.
        foreach ($entries as $entry) {
            expect($entry['changes'])->toBe([], "evento {$entry['event']}");
        }
    });

    it('merged (evento do merge de pacientes) sai com origem e contagens; campo extra fica redigido', function () {
        auditRow(Patient::class, (string) $this->patient->id, 'merged', null, [
            'source_id' => 'b2c1d1a8-0000-4000-8000-000000000001', 'source_code' => 'PAC-0000000009', 'moved' => 14, 'renumbered' => 2,
            'full_name' => 'NOME QUE NÃO PODE VAZAR',
        ], ['entity_id' => $this->entity->id]);

        $response = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk();
        $entry    = auditEntryFor($response->json('entries'), 'patient', 'merged');
        $byField  = collect($entry['changes'])->keyBy('field');

        expect($byField['source_code']['new'])->toBe('PAC-0000000009')
            ->and($byField['moved']['new'])->toBe(14)
            ->and($byField['renumbered']['new'])->toBe(2)
            ->and($byField['full_name']['redacted'])->toBeTrue()
            ->and($response->getContent())->not->toContain('NÃO PODE VAZAR');
    });

    it('valor estruturado em campo permitido não sai (array vira redigido)', function () {
        auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['source' => 'a'], ['source' => ['x' => 'y']], ['entity_id' => $this->entity->id]);

        $entry = auditEntryFor(patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->json('entries'), 'exam', 'updated');

        expect($entry['changes'])->toBe([['field' => 'source', 'old' => null, 'new' => null, 'redacted' => true]]);
    });

    it('origem do autor: usuário, integrador e sistema', function () {
        $common = ['entity_id' => $this->entity->id];

        auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['active' => true], ['active' => false], $common + ['user_id' => $this->doctorUser->id]);
        auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['active' => false], ['active' => true], $common + ['actor_type' => 'integrator']);
        auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['laterality' => 1], ['laterality' => 2], $common);

        $entries = collect(patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk()->json('entries'));

        expect($entries->pluck('actor_kind')->sort()->values()->all())->toBe(['integrator', 'system', 'user'])
            ->and($entries->firstWhere('actor_kind', 'user')['actor_name'])->toBe($this->doctorUser->name)
            ->and($entries->firstWhere('actor_kind', 'integrator')['actor_name'])->toBeNull()
            ->and($entries->firstWhere('actor_kind', 'system')['actor_name'])->toBeNull();
    });

    it('a ordem é do mais recente para o mais antigo', function () {
        auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['active' => true], ['active' => false], ['entity_id' => $this->entity->id, 'created_at' => now()->subHours(2)]);
        auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['active' => false], ['active' => true], ['entity_id' => $this->entity->id, 'created_at' => now()->subHour()]);

        $entries = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk()->json('entries');

        expect(collect($entries)->pluck('changes.0.new')->all())->toBe([true, false]);
    });
});

describe('contrato do payload (LGPD)', function () {
    it('nunca devolve ip_address, user_agent nem o retrato do registro', function () {
        auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['laterality' => 1], ['laterality' => 2], [
            'entity_id' => $this->entity->id, 'ip_address' => '203.0.113.77', 'user_agent' => 'AgenteSecreto/9.9',
        ]);
        auditRow(Patient::class, (string) $this->patient->id, 'created', null, ['code' => $this->patient->code, 'card_number' => 'CARTAO-999'], [
            'entity_id' => $this->entity->id, 'ip_address' => '203.0.113.77', 'user_agent' => 'AgenteSecreto/9.9',
        ]);

        $response = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk();

        expect($response->getContent())->not->toContain('203.0.113.77')
            ->and($response->getContent())->not->toContain('AgenteSecreto')
            ->and($response->getContent())->not->toContain('CARTAO-999');

        foreach ($response->json('entries') as $entry) {
            expect(array_keys($entry))->toBe(['id', 'at', 'event', 'target', 'target_code', 'actor_name', 'actor_kind', 'changes']);
        }

        expect(array_keys($response->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total']);
    });
});

describe('paginação', function () {
    it('20 por página, com meta; "Carregar mais" pede a página seguinte', function () {
        foreach (range(1, 25) as $i) {
            auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['quality_rating' => $i], ['quality_rating' => $i + 1], [
                'entity_id' => $this->entity->id, 'created_at' => now()->subMinutes(100 - $i),
            ]);
        }

        $first = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk();

        expect($first->json('entries'))->toHaveCount(20)
            ->and($first->json('meta'))->toBe(['current_page' => 1, 'last_page' => 2, 'per_page' => 20, 'total' => 25]);

        $second = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient, ['page' => 2])->assertOk();

        expect($second->json('entries'))->toHaveCount(5)
            ->and($second->json('meta.current_page'))->toBe(2)
            ->and(collect($first->json('entries'))->pluck('id')->intersect(collect($second->json('entries'))->pluck('id'))->all())->toBe([]);
    });

    it('o cliente não escolhe o tamanho da página (per_page é ignorado)', function () {
        foreach (range(1, 25) as $i) {
            auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['quality_rating' => 1], ['quality_rating' => 2], ['entity_id' => $this->entity->id]);
        }

        $response = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient, ['per_page' => 100])->assertOk();

        expect($response->json('entries'))->toHaveCount(20)->and($response->json('meta.per_page'))->toBe(20);
    });

    it('sem nenhum registro: lista vazia e uma página', function () {
        $response = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk();

        expect($response->json('entries'))->toBe([])->and($response->json('meta.total'))->toBe(0);
    });
});

describe('isolamento', function () {
    it('não mistura logs de outro paciente da mesma clínica nem de exame de outro paciente', function () {
        $other     = Patient::factory()->create(['entity_id' => $this->entity->id]);
        $otherExam = PatientExam::factory()->create(['patient_id' => $other->id]);

        auditRow(Patient::class, (string) $other->id, 'updated', ['priority_rating' => 1], ['priority_rating' => 5], ['entity_id' => $this->entity->id]);
        auditRow(PatientExam::class, (string) $otherExam->id, 'updated', ['active' => true], ['active' => false], ['entity_id' => $this->entity->id]);
        auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['active' => true], ['active' => false], ['entity_id' => $this->entity->id]);

        $entries = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk()->json('entries');

        expect($entries)->toHaveCount(1)
            ->and($entries[0]['target_code'])->toBe($this->exam->code);
    });

    it('log do exame com entity_id nulo (ação de integrador) aparece; de outra clínica não', function () {
        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);

        auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['active' => true], ['active' => false], ['entity_id' => null, 'actor_type' => 'integrator']);
        auditRow(PatientExam::class, (string) $this->exam->id, 'updated', ['laterality' => 1], ['laterality' => 2], ['entity_id' => $otherEntity->id]);

        $entries = patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->assertOk()->json('entries');

        expect($entries)->toHaveCount(1)
            ->and($entries[0]['actor_kind'])->toBe('integrator');
    });

    it('paciente de outra clínica: 404', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);

        patientAuditAs($this, $this->doctorUser, $this->doctorEu, $otherPatient)->assertNotFound();
    });
});

describe('acesso', function () {
    it('admin, médico, secretária e financeiro abrem', function () {
        foreach ([ClientRule::Admin, ClientRule::Doctor, ClientRule::Secretary, ClientRule::Financial] as $rule) {
            $user   = User::factory()->create();
            $member = createEntityUser($this->entity, $user, $rule->value);

            patientAuditAs($this, $user, $member, $this->patient)->assertOk();
        }
    });

    it('visitante é barrado', function () {
        $this->getJson(route('panel.exam-manager.patients.audit', ['patient' => $this->patient]))->assertUnauthorized();
    });

    it('perfil usuário sem patients.manage: 403; com a permissão customizada: 200', function () {
        $this->seed(PermissionsSeeder::class);

        $user   = User::factory()->create();
        $member = createEntityUser($this->entity, $user, ClientRule::User->value);

        patientAuditAs($this, $user, $member, $this->patient)->assertForbidden();

        $permission = PermissionRecord::query()->where('key', Permission::PatientsManage->value)->firstOrFail();
        $role       = Role::query()->create(['entity_id' => $this->entity->id, 'name' => 'Cadastro de pacientes']);
        $role->permissions()->sync([$permission->id]);
        $member->roles()->sync([$role->id]);

        patientAuditAs($this, User::find($user->id), $member, $this->patient)->assertOk();
    });

    it('a tela devolve can_view_patient_audit e a rota do histórico, espelhando o acesso da rota', function () {
        $this->seed(PermissionsSeeder::class);

        $expected = [
            ClientRule::Admin->value     => true,
            ClientRule::Doctor->value    => true,
            ClientRule::Secretary->value => true,
            ClientRule::Financial->value => true,
            ClientRule::User->value      => false,
        ];

        foreach ($expected as $rule => $can) {
            $user   = User::factory()->create();
            $member = createEntityUser($this->entity, $user, $rule);

            $this->actingAs($user)->withSession(panelSession($member))
                ->get(route('panel.exam-manager.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('can_view_patient_audit', $can)
                    ->where('urls.patient_audit', route('panel.exam-manager.patients.audit', ['patient' => '__ID__'])));
        }
    });

    it('perfil usuário com a permissão customizada patients.manage também vê o botão', function () {
        $this->seed(PermissionsSeeder::class);

        $user   = User::factory()->create();
        $member = createEntityUser($this->entity, $user, ClientRule::User->value);

        $permission = PermissionRecord::query()->where('key', Permission::PatientsManage->value)->firstOrFail();
        $role       = Role::query()->create(['entity_id' => $this->entity->id, 'name' => 'Cadastro de pacientes']);
        $role->permissions()->sync([$permission->id]);
        $member->roles()->sync([$role->id]);

        $this->actingAs(User::find($user->id))->withSession(panelSession($member))
            ->get(route('panel.exam-manager.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can_view_patient_audit', true));
    });
});

describe('o evento merged é gravado pelo AuditService (formato esperado pelo histórico)', function () {
    it('AuditService::log("merged", paciente, null, resumo) vira entrada com changes do resumo', function () {
        $this->actingAs($this->doctorUser);
        session(panelSession($this->doctorEu));

        app(AuditService::class)->log('merged', $this->patient, null, ['source_id' => 'x', 'source_code' => 'PAC-0000000002', 'moved' => 3, 'renumbered' => 0]);

        $entry = auditEntryFor(patientAuditAs($this, $this->doctorUser, $this->doctorEu, $this->patient)->json('entries'), 'patient', 'merged');

        expect(collect($entry['changes'])->pluck('field')->all())->toBe(['source_id', 'source_code', 'moved', 'renumbered']);
    });
});
