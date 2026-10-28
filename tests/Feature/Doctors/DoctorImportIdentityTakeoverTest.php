<?php

declare(strict_types=1);

/**
 * Regressão de segurança (CRITICAL) — takeover de conta entre clínicas.
 *
 * Cadeia antes da correção:
 *  1. A importação de médicos da clínica A achava QUALQUER User pelo e-mail
 *     da planilha (ex.: o admin da clínica B, staff SaaS) e criava para ele
 *     um vínculo de médico em A — o DoctorRequest barra e-mail existente,
 *     mas a importação contornava.
 *  2. Em seguida o admin de A editava o médico (ou o usuário em Controle de
 *     acesso) e trocava users.email para um e-mail seu.
 *  3. "Esqueci minha senha" no e-mail novo => login como a vítima, com
 *     acesso à clínica B.
 *
 * Agora: (1) login existente só é aceito se já for médico de A; (2) nome e
 * e-mail de um login que acessa outra clínica (ou o portal de parceiros) só
 * mudam pelo próprio dono.
 */

use App\Enums\{ClientRule, FeatureKey, ImportStatus, PartnerType, SubscriptionStatus};
use App\Models\{Doctor, DoctorImport, Entity, EntityUser, Partner, People, Plan, PlanFeature, Subscription, User};
use App\Services\DoctorImportService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\{DB, Notification, Storage};
use Illuminate\Support\Str;

function ditEntity(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxDoctors->value, 'value' => '0']);
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

function ditImport(Entity $entity, string $csv): DoctorImport
{
    Storage::fake();

    $path = "imports/doctors/{$entity->id}/dit.csv";
    Storage::disk()->put($path, "\xEF\xBB\xBF" . $csv);

    return DoctorImport::create([
        'entity_id' => $entity->id, 'user_id' => User::factory()->create()->id,
        'status'    => ImportStatus::Pending, 'file_path' => $path, 'original_name' => 'dit.csv',
    ]);
}

function ditCsvRow(string $email, string $cpf = '11122233344'): string
{
    return "nome;apelido;cpf;crm;crm_especialidade;cor;email\nFulano Medico;Dr Fulano;{$cpf};999;Oftalmo;#123456;{$email}\n";
}

function ditPartnerFor(User $user): Partner
{
    return Partner::query()->create([
        'name'    => 'Parceiro ' . Str::random(6),
        'email'   => $user->email,
        'type'    => PartnerType::Consultant,
        'token'   => Str::random(32),
        'user_id' => $user->id,
    ]);
}

function ditErrorReason(DoctorImport $import): string
{
    $lines = array_values(array_filter(explode("\n", Storage::disk()->get($import->errors_file_path))));

    return str_getcsv($lines[1], ';')[1];
}

/** Médico de A (o login pode ser também de outra clínica — vínculo legado da importação antiga). */
function ditDoctorFor(Entity $entity, User $user): Doctor
{
    $person = People::factory()->create([
        'national_registry'      => (string) random_int(10000000000, 99999999999),
        'telephone'              => '1133334444',
        'cellphone'              => '11999998888',
        'whatsapp'               => true,
        'zipcode'                => '01001000',
        'address'                => 'RUA TESTE',
        'number'                 => '123',
        'complement'             => null,
        'district'               => 'CENTRO',
        'city'                   => 'SAO PAULO',
        'state'                  => 'SP',
        'country'                => 'BRASIL',
        'mother_name'            => 'MAE TESTE',
        'father_name'            => 'PAI TESTE',
        'state_registry'         => '123456',
        'state_registry_agency'  => 'SSP',
        'state_registry_initial' => 'SP',
    ]);

    return Doctor::query()->create([
        'entity_user_id'   => createEntityUser($entity, $user, ClientRule::Doctor->value)->id,
        'person_id'        => $person->id,
        'record'           => 'CRM' . random_int(10000, 99999),
        'record_specialty' => 'OFTALMO-' . random_int(100, 999),
        'color'            => '#' . Str::upper(Str::random(6)),
        'active'           => true,
    ]);
}

/** Payload do modal de edição com os dados ATUAIS (como DoctorsController::editData devolve). */
function ditDoctorPayload(Doctor $doctor, array $overrides = []): array
{
    $doctor->loadMissing(['person', 'entityUser.user']);
    $person = $doctor->person;

    return array_merge([
        'name'                   => $person->full_name,
        'nickname'               => $doctor->entityUser->user->name,
        'national_registry'      => $person->national_registry,
        'birth_date'             => $person->birth_date?->format('Y-m-d'),
        'gender'                 => $person->gender,
        'marital_status'         => $person->marital_status,
        'email'                  => $doctor->entityUser->user->email,
        'mother_name'            => $person->mother_name,
        'father_name'            => $person->father_name,
        'state_registry'         => $person->state_registry,
        'state_registry_agency'  => $person->state_registry_agency,
        'state_registry_initial' => $person->state_registry_initial,
        'state_registry_date'    => $person->state_registry_date?->format('Y-m-d'),
        'telephone'              => $person->telephone,
        'cellphone'              => $person->cellphone,
        'whatsapp'               => (bool) $person->whatsapp,
        'zipcode'                => $person->zipcode,
        'address'                => $person->address,
        'number'                 => $person->number,
        'complement'             => $person->complement,
        'district'               => $person->district,
        'city'                   => $person->city,
        'state'                  => $person->state,
        'record'                 => $doctor->record,
        'record_specialty'       => $doctor->record_specialty,
        'color'                  => $doctor->color,
        'active'                 => true,
    ], $overrides);
}

beforeEach(function (): void {
    $this->clinicA = ditEntity();
    $this->clinicB = ditEntity();

    $this->attacker   = User::factory()->create();
    $this->attackerEu = createEntityUser($this->clinicA, $this->attacker, ClientRule::Admin->value, true, true);

    $this->victim = User::factory()->create(['email' => 'vitima@clinicab.com', 'name' => 'Dona Da Clinica B']);
    createEntityUser($this->clinicB, $this->victim, ClientRule::Admin->value, true, true);
});

describe('importação não vincula login existente de outra pessoa', function (): void {
    it('e-mail do admin de OUTRA clínica vira erro de linha: nenhum vínculo/médico em A e a conta da vítima intacta', function (): void {
        Notification::fake();

        $import = ditImport($this->clinicA, ditCsvRow('vitima@clinicab.com'));
        app(DoctorImportService::class)->process($import);

        $import->refresh();
        expect($import->imported_rows)->toBe(0)
            ->and($import->error_rows)->toBe(1)
            ->and(ditErrorReason($import))->toBe(__('shared_identity.import.email_in_use'))
            ->and(EntityUser::withTrashed()->where('user_id', $this->victim->id)->where('entity_id', $this->clinicA->id)->exists())->toBeFalse()
            ->and(Doctor::whereHas('entityUser', fn ($q) => $q->where('user_id', $this->victim->id))->exists())->toBeFalse()
            ->and($this->victim->fresh()->email)->toBe('vitima@clinicab.com');

        Notification::assertNothingSent();
    });

    it('login sem vínculo nenhum (ex.: parceiro) também é recusado — mesma regra do DoctorRequest', function (): void {
        $partnerUser = User::factory()->create(['email' => 'parceiro@example.com']);
        ditPartnerFor($partnerUser);

        $import = ditImport($this->clinicA, ditCsvRow('parceiro@example.com'));
        app(DoctorImportService::class)->process($import);

        expect($import->fresh()->error_rows)->toBe(1)
            ->and(EntityUser::where('user_id', $partnerUser->id)->exists())->toBeFalse();
    });

    it('login excluído (soft delete) não é ressuscitado pela importação', function (): void {
        $deleted = User::factory()->create(['email' => 'excluido@example.com']);
        $deleted->delete();

        $import = ditImport($this->clinicA, ditCsvRow('excluido@example.com'));
        app(DoctorImportService::class)->process($import);

        expect($import->fresh()->error_rows)->toBe(1)
            ->and(User::withTrashed()->find($deleted->id)->trashed())->toBeTrue();
    });

    it('membro de A com outro papel (admin/dono) não vira médico pela planilha — papel intacto', function (): void {
        $import = ditImport($this->clinicA, ditCsvRow($this->attacker->email));
        app(DoctorImportService::class)->process($import);

        expect($import->fresh()->error_rows)->toBe(1)
            ->and($this->attackerEu->fresh()->rule)->toBe(ClientRule::Admin->value);
    });

    it('médico de A (reimportação) é reaproveitado sem reativar vínculo desativado pelo admin', function (): void {
        Notification::fake();

        $doctorUser = User::factory()->create(['email' => 'medico.a@example.com']);
        $membership = createEntityUser($this->clinicA, $doctorUser, ClientRule::Doctor->value, active: false);

        $import = ditImport($this->clinicA, ditCsvRow('medico.a@example.com'));
        app(DoctorImportService::class)->process($import);

        expect($import->fresh()->imported_rows)->toBe(1)
            ->and($membership->fresh()->active)->toBeFalse()
            ->and(EntityUser::where('user_id', $doctorUser->id)->count())->toBe(1);

        Notification::assertNotSentTo($doctorUser, ResetPassword::class);
    });
});

describe('edição não reescreve login que acessa outra clínica', function (): void {
    beforeEach(function (): void {
        // Estado deixado pela importação antiga: a vítima (admin de B) virou médica em A.
        $this->victimDoctorA = ditDoctorFor($this->clinicA, $this->victim);
    });

    it('[médicos] trocar o e-mail do login compartilhado é recusado (422) e users.email fica intacto', function (): void {
        $this->actingAs($this->attacker)
            ->withSession(panelSession($this->attackerEu))
            ->putJson(route('panel.doctors.update', $this->victimDoctorA->id), ditDoctorPayload($this->victimDoctorA, ['email' => 'evil@example.com']))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('shared_identity.user_shared_readonly'));

        $victim = $this->victim->fresh();
        expect($victim->email)->toBe('vitima@clinicab.com')
            ->and($victim->email_verified_at)->not->toBeNull();
    });

    it('[médicos] trocar o nome (apelido) do login compartilhado também é recusado', function (): void {
        $this->actingAs($this->attacker)
            ->withSession(panelSession($this->attackerEu))
            ->putJson(route('panel.doctors.update', $this->victimDoctorA->id), ditDoctorPayload($this->victimDoctorA, ['nickname' => 'Outro Nome']))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('shared_identity.user_shared_readonly'));

        expect($this->victim->fresh()->name)->toBe('DONA DA CLINICA B');
    });

    it('[médicos] editar o restante com nome/e-mail inalterados continua funcionando', function (): void {
        $this->actingAs($this->attacker)
            ->withSession(panelSession($this->attackerEu))
            ->putJson(route('panel.doctors.update', $this->victimDoctorA->id), ditDoctorPayload($this->victimDoctorA, ['color' => '#ABCDEF']))
            ->assertOk();

        expect($this->victimDoctorA->fresh()->color)->toBe('#ABCDEF')
            ->and($this->victim->fresh()->email)->toBe('vitima@clinicab.com');
    });

    it('[controle de acesso] trocar o e-mail do login compartilhado é recusado (422)', function (): void {
        $victimEuA = EntityUser::where('user_id', $this->victim->id)->where('entity_id', $this->clinicA->id)->sole();

        $this->actingAs($this->attacker)
            ->withSession(panelSession($this->attackerEu))
            ->putJson(route('panel.accesscontrol.users.update', $victimEuA->id), [
                'name'   => $this->victim->name,
                'email'  => 'evil@example.com',
                'rule'   => ClientRule::Doctor->value,
                'active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('shared_identity.user_shared_readonly'));

        expect($this->victim->fresh()->email)->toBe('vitima@clinicab.com');
    });

    it('vínculo REMOVIDO em outra clínica também conta (pode ser restaurado)', function (): void {
        EntityUser::where('user_id', $this->victim->id)->where('entity_id', $this->clinicB->id)->sole()->delete();

        $this->actingAs($this->attacker)
            ->withSession(panelSession($this->attackerEu))
            ->putJson(route('panel.doctors.update', $this->victimDoctorA->id), ditDoctorPayload($this->victimDoctorA, ['email' => 'evil@example.com']))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('shared_identity.user_shared_readonly'));

        expect($this->victim->fresh()->email)->toBe('vitima@clinicab.com');
    });

    it('login de parceiro (portal de parceiros) só com vínculo em A também é protegido', function (): void {
        $partnerUser = User::factory()->create(['email' => 'parceiro@example.com']);
        ditPartnerFor($partnerUser);
        $doctor = ditDoctorFor($this->clinicA, $partnerUser);

        $this->actingAs($this->attacker)
            ->withSession(panelSession($this->attackerEu))
            ->putJson(route('panel.doctors.update', $doctor->id), ditDoctorPayload($doctor, ['email' => 'evil@example.com']))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('shared_identity.user_shared_readonly'));

        expect($partnerUser->fresh()->email)->toBe('parceiro@example.com');
    });

    it('login exclusivo da clínica continua editável pelo admin (comportamento anterior)', function (): void {
        $own    = User::factory()->create(['email' => 'medico.exclusivo@example.com']);
        $doctor = ditDoctorFor($this->clinicA, $own);

        $this->actingAs($this->attacker)
            ->withSession(panelSession($this->attackerEu))
            ->putJson(route('panel.doctors.update', $doctor->id), ditDoctorPayload($doctor, ['email' => 'medico.novo@example.com']))
            ->assertOk();

        expect($own->fresh()->email)->toBe('medico.novo@example.com');
    });

    it('cadeia completa do achado: importar o e-mail da vítima e depois trocá-lo não altera users.email', function (): void {
        $fresh = User::factory()->create(['email' => 'outra.vitima@clinicab.com']);
        createEntityUser($this->clinicB, $fresh, ClientRule::Admin->value);

        $import = ditImport($this->clinicA, ditCsvRow('outra.vitima@clinicab.com', '55566677788'));
        app(DoctorImportService::class)->process($import);

        $doctor = Doctor::query()
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('entity_users.user_id', $fresh->id)
            ->where('entity_users.entity_id', $this->clinicA->id)
            ->select('doctors.*')
            ->first();

        expect($import->fresh()->error_rows)->toBe(1)
            ->and($doctor)->toBeNull()
            ->and($fresh->fresh()->email)->toBe('outra.vitima@clinicab.com')
            ->and(DB::table('password_reset_tokens')->where('email', 'outra.vitima@clinicab.com')->exists())->toBeFalse();
    });
});
