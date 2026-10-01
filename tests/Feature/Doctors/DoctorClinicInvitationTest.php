<?php

declare(strict_types=1);

/**
 * Mesmo médico em várias clínicas, sem clínica nenhuma mexer no login dele:
 * a clínica B tenta cadastrar quem já tem login no EasyEye → recebe só o aviso
 * "já possui cadastro no EasyEye, mas não nesta clínica" (sem nome/e-mail/
 * clínicas) e pode enviar um convite, que vai para o e-mail do login EXISTENTE.
 * Só o próprio médico, logado, aceita — e aí entra em B com os dados que B
 * informou (cadastro próprio de B; o de A e o login ficam intocados).
 */

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Enums\ImportStatus;
use App\Models\{Doctor, DoctorInvitation, Entity, EntityUser, Patient, People, Plan, PlanFeature, Subscription, User};
use App\Models\DoctorImport;
use App\Notifications\DoctorClinicInvitation;
use App\Services\DoctorImportService;
use Illuminate\Support\Facades\{DB, Notification, URL};
use Illuminate\Support\Facades\Storage;

function dciEntity(string $name, string $maxDoctors = '0'): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => $name]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxDoctors->value, 'value' => $maxDoctors]);

    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

function dciPayload(array $overrides = []): array
{
    return array_merge([
        'name'                  => 'Joao Medico',
        'nickname'              => 'Dr Joao',
        'national_registry'     => '52998224725',
        'record'                => 'CRM-12345',
        'record_specialty'      => 'Oftalmologia',
        'color'                 => '#123456',
        'email'                 => 'dr.joao@example.com',
        'cellphone'             => '68931467577',
        'whatsapp'              => false,
        'partner'               => false,
        'password'              => 'NovaSenhaForte123!',
        'password_confirmation' => 'NovaSenhaForte123!',
    ], $overrides);
}

/** Link assinado que o médico recebe por e-mail. */
function dciLink(DoctorInvitation $invitation, string $name = 'doctor-invitations.show'): string
{
    return URL::temporarySignedRoute($name, now()->addDays(7), ['invitation' => $invitation->id]);
}

beforeEach(function (): void {
    $this->clinicA = dciEntity('Clínica A');
    $this->clinicB = dciEntity('Clínica B');
    $this->adminA  = User::factory()->create();
    $this->adminB  = User::factory()->create();
    $this->euA     = createEntityUser($this->clinicA, $this->adminA, ClientRule::Admin->value, true, true);
    $this->euB     = createEntityUser($this->clinicB, $this->adminB, ClientRule::Admin->value, true, true);

    $this->asB = fn () => $this->actingAs($this->adminB)->withSession(panelSession($this->euB));

    // Médico cadastrado normalmente na clínica A.
    $this->actingAs($this->adminA)->withSession(panelSession($this->euA))
        ->postJson(route('panel.doctors.store'), dciPayload())->assertOk();
    $this->doctorUser   = User::query()->where('email', 'dr.joao@example.com')->sole();
    $this->doctorPerson = Doctor::query()->sole()->person;
});

describe('cadastro na clínica B detecta o médico que já tem login', function (): void {
    it('mesmo e-mail e CPF: não cadastra; oferece convite sem revelar nada do médico', function (): void {
        $response = ($this->asB)()->postJson(route('panel.doctors.store'), dciPayload(['name' => 'NOME DIGITADO POR B']));

        $response->assertStatus(422)->assertJsonValidationErrors('existing_doctor')
            ->assertJsonMissingValidationErrors(['email', 'national_registry']);
        expect(json_encode($response->json()))->not->toContain('JOAO MEDICO')
            ->and(EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->doctorUser->id)->exists())->toBeFalse()
            ->and(User::query()->count())->toBe(3);
    });

    it('mesmo CPF com OUTRO e-mail: identifica pelo CPF e oferece convite (não cria segundo login)', function (): void {
        ($this->asB)()->postJson(route('panel.doctors.store'), dciPayload(['email' => 'outro.email@example.com']))
            ->assertStatus(422)->assertJsonValidationErrors('existing_doctor');

        expect(User::query()->where('email', 'outro.email@example.com')->exists())->toBeFalse();
    });

    it('CPF de um médico com e-mail de OUTRA pessoa: erro genérico, sem convite', function (): void {
        $other = User::factory()->create(['email' => 'secretaria@outra.com']);
        createEntityUser(dciEntity('Clínica C'), $other, ClientRule::Secretary->value);

        ($this->asB)()->postJson(route('panel.doctors.store'), dciPayload(['email' => 'secretaria@outra.com']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['national_registry', 'email'])
            ->assertJsonMissingValidationErrors('existing_doctor');
    });

    it('e-mail de quem JÁ está nesta clínica: erro normal de duplicidade', function (): void {
        ($this->asB)()->postJson(route('panel.doctors.store'), dciPayload(['email' => $this->adminB->email, 'national_registry' => '11144477735']))
            ->assertStatus(422)->assertJsonValidationErrors('email')->assertJsonMissingValidationErrors('existing_doctor');
    });

    it('CPF que só existe como PACIENTE em outra clínica não bloqueia: B cadastra com cadastro próprio', function (): void {
        $patientPerson = People::factory()->create(['national_registry' => '11144477735', 'full_name' => 'PACIENTE X', 'email' => 'x@example.com']);
        Patient::factory()->create(['entity_id' => $this->clinicA->id, 'person_id' => $patientPerson->id]);

        ($this->asB)()->postJson(route('panel.doctors.store'), dciPayload([
            'national_registry' => '11144477735', 'email' => 'novo.medico@example.com', 'record' => 'CRM-999',
        ]))->assertOk();

        $doctorB = Doctor::query()->whereHas('entityUser', fn ($q) => $q->where('entity_id', $this->clinicB->id))->sole();
        expect($doctorB->person_id)->not->toBe($patientPerson->id)
            ->and($patientPerson->fresh()->full_name)->toBe('PACIENTE X');
    });

    it('mesmo CPF de médico da PRÓPRIA clínica continua recusado (duplicidade)', function (): void {
        ($this->actingAs($this->adminA)->withSession(panelSession($this->euA)))
            ->postJson(route('panel.doctors.store'), dciPayload(['email' => 'duplicado@example.com', 'record' => 'CRM-777']))
            ->assertStatus(422)->assertJsonValidationErrors('national_registry');
    });
});

describe('envio do convite pela clínica B', function (): void {
    it('vai para o e-mail do login EXISTENTE (não o digitado), guarda os dados de B criptografados e não os devolve', function (): void {
        Notification::fake();

        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload(['email' => 'digitado.por.b@example.com']))
            ->assertRedirect()->assertSessionHas('success', __('doctors.invitation.sent'));

        $invitation = DoctorInvitation::query()->sole();
        expect($invitation->entity_id)->toBe($this->clinicB->id)
            ->and($invitation->user_id)->toBe($this->doctorUser->id)
            ->and($invitation->status)->toBe('pending')
            ->and($invitation->payload['record'])->toBe('CRM-12345');

        // Em repouso: nenhum dado pessoal em texto puro.
        $raw = DB::table('doctor_invitations')->where('id', $invitation->id)->value('payload');
        expect($raw)->not->toContain('52998224725')->not->toContain('JOAO');

        Notification::assertSentTo($this->doctorUser, DoctorClinicInvitation::class);
        Notification::assertSentTimes(DoctorClinicInvitation::class, 1);
    });

    it('sem médico existente: recusado (usar o cadastro normal)', function (): void {
        Notification::fake();

        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload(['national_registry' => '11144477735', 'email' => 'ninguem@example.com']))
            ->assertSessionHasErrors('existing_doctor');

        expect(DoctorInvitation::query()->count())->toBe(0);
        Notification::assertNothingSent();
    });

    it('limite de médicos do plano atingido: não envia', function (): void {
        Notification::fake();
        $clinicD = dciEntity('Clínica D', '1');
        $adminD  = User::factory()->create();
        $euD     = createEntityUser($clinicD, $adminD, ClientRule::Admin->value, true, true);
        $this->actingAs($adminD)->withSession(panelSession($euD))
            ->postJson(route('panel.doctors.store'), dciPayload(['national_registry' => '11144477735', 'email' => 'unico@example.com', 'record' => 'CRM-1']))
            ->assertOk();

        $this->actingAs($adminD)->withSession(panelSession($euD))
            ->post(route('panel.doctors.invitations.store'), dciPayload())
            ->assertSessionHasErrors('existing_doctor');

        expect(DoctorInvitation::query()->count())->toBe(0);
    });

    it('reenviar para o mesmo médico atualiza o convite pendente (não duplica)', function (): void {
        Notification::fake();

        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload());
        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload(['record' => 'CRM-NOVO']));

        expect(DoctorInvitation::query()->where('status', 'pending')->sole()->payload['record'])->toBe('CRM-NOVO');
    });

    it('clínica cancela o convite pendente; outra clínica não consegue (404)', function (): void {
        Notification::fake();
        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload());
        $invitation = DoctorInvitation::query()->sole();

        $this->actingAs($this->adminA)->withSession(panelSession($this->euA))
            ->delete(route('panel.doctors.invitations.destroy', $invitation))->assertNotFound();

        ($this->asB)()->delete(route('panel.doctors.invitations.destroy', $invitation))->assertRedirect();
        expect($invitation->fresh()->status)->toBe('cancelled')
            ->and($invitation->fresh()->payload)->toBeNull();
    });
});

describe('aceite pelo médico', function (): void {
    beforeEach(function (): void {
        Notification::fake();
        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload(['name' => 'Joao na Clinica B', 'record' => 'CRM-B-1']));
        $this->invitation = DoctorInvitation::query()->sole();
    });

    it('sem login vai para o login; outro usuário logado recebe 404', function (): void {
        $this->app['auth']->forgetGuards(); // sai da sessão do admin do beforeEach
        $this->get(dciLink($this->invitation))->assertRedirect(route('login'));

        $this->actingAs($this->adminA)->get(dciLink($this->invitation))->assertNotFound();
        $this->actingAs($this->adminA)->post(dciLink($this->invitation, 'doctor-invitations.accept'))->assertNotFound();

        expect($this->invitation->fresh()->status)->toBe('pending');
    });

    it('link adulterado ou vencido é recusado (assinatura)', function (): void {
        $this->actingAs($this->doctorUser)->get(dciLink($this->invitation) . 'x')->assertForbidden();

        $expired = URL::temporarySignedRoute('doctor-invitations.show', now()->subMinute(), ['invitation' => $this->invitation->id]);
        $this->actingAs($this->doctorUser)->get($expired)->assertForbidden();
    });

    it('médico vê só o nome da clínica e aceita: entra em B com os dados de B; login e cadastro de A intocados', function (): void {
        $passwordHash = $this->doctorUser->password;

        $this->actingAs($this->doctorUser)->get(dciLink($this->invitation), inertiaHeaders())
            ->assertOk()
            ->assertJsonPath('component', 'Auth/ClinicInvitation')
            ->assertJsonPath('props.clinicName', $this->clinicB->fresh()->name);

        $this->actingAs($this->doctorUser)->post(dciLink($this->invitation, 'doctor-invitations.accept'))
            ->assertRedirect(route('selectentity.create'))
            ->assertSessionHas('success');

        $euDoctorB = EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->doctorUser->id)->sole();
        $doctorB   = Doctor::query()->where('entity_user_id', $euDoctorB->id)->sole();

        expect($euDoctorB->rule)->toBe(ClientRule::Doctor->value)
            ->and($doctorB->record)->toBe('CRM-B-1')
            ->and($doctorB->person_id)->not->toBe($this->doctorPerson->id)
            ->and($doctorB->person->full_name)->toBe('JOAO NA CLINICA B')
            ->and($this->doctorPerson->fresh()->full_name)->toBe('JOAO MEDICO')
            ->and($this->doctorUser->fresh()->password)->toBe($passwordHash)
            ->and($this->invitation->fresh()->status)->toBe('accepted')
            ->and($this->invitation->fresh()->payload)->toBeNull();

        // As duas clínicas no seletor, com o médico certo em cada uma.
        $entities = $this->actingAs($this->doctorUser)->get(route('selectentity.create'), inertiaHeaders())->json('props.entities');
        expect(array_values($entities))->toContain($this->clinicA->fresh()->name, $this->clinicB->fresh()->name);
    });

    it('aceitar de novo não duplica; convite recusado ou cancelado não aceita', function (): void {
        $accept = dciLink($this->invitation, 'doctor-invitations.accept');
        $this->actingAs($this->doctorUser)->post($accept);
        $this->actingAs($this->doctorUser)->post($accept)->assertRedirect(route('selectentity.create'));

        expect(Doctor::query()->whereHas('entityUser', fn ($q) => $q->where('entity_id', $this->clinicB->id))->count())->toBe(1);

        $this->invitation->forceFill(['status' => 'declined'])->save();
        $this->actingAs($this->doctorUser)->post($accept);
        expect(Doctor::query()->whereHas('entityUser', fn ($q) => $q->where('entity_id', $this->clinicB->id))->count())->toBe(1);
    });

    it('convite cancelado pela clínica não pode ser aceito', function (): void {
        ($this->asB)()->delete(route('panel.doctors.invitations.destroy', $this->invitation));

        // Médico só em A: volta ao painel (uma clínica), com o aviso.
        $this->actingAs($this->doctorUser)->post(dciLink($this->invitation, 'doctor-invitations.accept'))
            ->assertRedirect(route('panel.dashboard'))->assertSessionHas('error', __('doctors.invitation.result.closed'));

        expect(EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->doctorUser->id)->exists())->toBeFalse();
    });

    it('recusar: nada é criado e os dados de B são descartados', function (): void {
        $this->actingAs($this->doctorUser)->post(dciLink($this->invitation, 'doctor-invitations.decline'))
            ->assertRedirect(route('panel.dashboard'))
            ->assertSessionHas('success', __('doctors.invitation.result.declined'));

        expect($this->invitation->fresh()->status)->toBe('declined')
            ->and($this->invitation->fresh()->payload)->toBeNull()
            ->and(EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->doctorUser->id)->exists())->toBeFalse();
    });
});

describe('endurecimento (revisão de segurança)', function (): void {
    it('e-mail de login que NÃO é de médico: erro comum de e-mail em uso, sem aviso de convite (não revela staff)', function (): void {
        $secretary = User::factory()->create(['email' => 'secretaria@outra.com']);
        createEntityUser(dciEntity('Clínica C'), $secretary, ClientRule::Secretary->value);

        ($this->asB)()->postJson(route('panel.doctors.store'), dciPayload(['email' => 'secretaria@outra.com', 'national_registry' => '11144477735']))
            ->assertStatus(422)->assertJsonValidationErrors('email')->assertJsonMissingValidationErrors('existing_doctor');
    });

    it('reenvio antes de 24h atualiza o convite sem mandar outro e-mail; depois de 24h manda', function (): void {
        Notification::fake();

        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload());
        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload(['record' => 'CRM-2']));
        Notification::assertSentTimes(DoctorClinicInvitation::class, 1);

        $this->travel(25)->hours();
        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload(['record' => 'CRM-3']));
        Notification::assertSentTimes(DoctorClinicInvitation::class, 2);
    });

    it('os dados digitados (payload) nunca vão para a trilha de auditoria', function (): void {
        Notification::fake();
        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload());
        $invitation = DoctorInvitation::query()->sole();
        ($this->asB)()->delete(route('panel.doctors.invitations.destroy', $invitation));

        $logs = DB::table('audit_logs')->where('auditable_type', DoctorInvitation::class)->get();
        expect($logs)->not->toBeEmpty();

        foreach ($logs as $log) {
            expect((string) $log->old_values . (string) $log->new_values)->not->toContain('payload');
        }
    });

    it('convite vencido sem resposta: rotina diária expira e descarta os dados', function (): void {
        Notification::fake();
        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload());

        $this->travel(DoctorInvitation::VALID_DAYS + 1)->days();
        $this->artisan('clinic-invitations:expire')->assertSuccessful();

        $invitation = DoctorInvitation::query()->sole();
        expect($invitation->status)->toBe(DoctorInvitation::STATUS_EXPIRED)
            ->and($invitation->payload)->toBeNull();
    });

    it('aceite revalida: se outro médico da clínica passou a usar o mesmo CRM, não entra (nada duplicado)', function (): void {
        Notification::fake();
        ($this->asB)()->post(route('panel.doctors.invitations.store'), dciPayload(['record' => 'CRM-B-1']));
        $invitation = DoctorInvitation::query()->sole();

        ($this->asB)()->postJson(route('panel.doctors.store'), dciPayload([
            'national_registry' => '11144477735', 'email' => 'outro.medico@example.com', 'record' => 'CRM-B-1', 'color' => '#654321', 'record_specialty' => 'Retina',
        ]))->assertOk();

        $this->actingAs($this->doctorUser)->post(dciLink($invitation, 'doctor-invitations.accept'))
            ->assertSessionHas('error', __('doctors.invitation.result.conflict', ['clinic' => $this->clinicB->fresh()->name]));

        expect(EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->doctorUser->id)->exists())->toBeFalse()
            ->and($invitation->fresh()->status)->toBe('pending');
    });

    it('importação: médico com login em outra clínica vira erro de linha orientando o convite (sem segundo login)', function (): void {
        Storage::fake('private');
        $path = "imports/doctors/{$this->clinicB->id}/dci.csv";
        Storage::disk('private')->put($path, "\xEF\xBB\xBFnome;apelido;cpf;crm;crm_especialidade;cor;email\nJOAO;Dr Joao;52998224725;CRM-9;Retina;#999999;joao.novo@example.com\n");
        $import = DoctorImport::create([
            'entity_id' => $this->clinicB->id, 'user_id' => $this->adminB->id,
            'status'    => ImportStatus::Pending, 'file_path' => $path, 'original_name' => 'dci.csv',
        ]);

        app(DoctorImportService::class)->process($import);

        $import->refresh();
        $lines = array_values(array_filter(explode("\n", Storage::disk('private')->get($import->errors_file_path))));
        expect($import->imported_rows)->toBe(0)
            ->and(str_getcsv($lines[1], ';')[1])->toBe(__('doctors.invitation.import_use_invite'))
            ->and(User::query()->where('email', 'joao.novo@example.com')->exists())->toBeFalse();
    });

    it('cadastro de médico tem limite de tentativas (varredura de e-mail/CPF)', function (): void {
        for ($i = 0; $i < 20; $i++) {
            ($this->asB)()->postJson(route('panel.doctors.store'), [])->assertStatus(422);
        }

        ($this->asB)()->postJson(route('panel.doctors.store'), [])->assertStatus(429);
    });
});
