<?php

declare(strict_types=1);

use App\Domains\Tiss\Models\{TissBatch, TissGuide, TissOperator};
use App\Enums\ClientRule;
use App\Models\{AuditLog, Covenant, Entity, EntityUser, Patient, People, User};
use Illuminate\Support\Facades\Route;

/*
 * Débitos pequenos da Fase 0:
 *  - "Restaurar" paciente excluído (a listagem mostrava, a rota não existia);
 *  - rota schedules/ajaxlist apontava para método inexistente (removida);
 *  - pivots com `id` uuid sem default (tiss_batch_guides, entity_users):
 *    attach() pela relação inseria id NULL;
 *  - selected_entity_doctor_id gravado no login e nunca lido (removido).
 */

describe('restaurar paciente excluído', function () {
    beforeEach(function () {
        $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        $this->member   = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);

        $this->patient = Patient::create([
            'entity_id'   => $this->entity->id,
            'person_id'   => People::factory()->create(['full_name' => 'ANA RESTAURADA', 'birth_date' => '1970-01-01', 'national_registry' => '11122233344'])->id,
            'covenant_id' => $this->covenant->id,
            'active'      => true,
        ]);
        $this->patient->delete();
    });

    function f0RestoreAs(object $test, EntityUser $eu): object
    {
        return $test->actingAs($eu->user)->withSession(panelSession($eu));
    }

    it('restaura (mesma permissão da exclusão), limpa deleted_by e registra a auditoria', function () {
        f0RestoreAs($this, $this->member)
            ->patchJson(route('panel.patients.restore', $this->patient->id))
            ->assertOk();

        $restored = Patient::find($this->patient->id);

        expect($restored)->not->toBeNull()
            ->and($restored->deleted_by)->toBeNull()
            ->and(AuditLog::query()->where('auditable_type', Patient::class)->where('auditable_id', $this->patient->id)->where('event', 'restored')->exists())->toBeTrue();
    });

    it('não reabre duplicado: outro paciente ativo com o mesmo nome e nascimento, ou o mesmo CPF, barra (422)', function () {
        $twin = Patient::create([
            'entity_id'   => $this->entity->id,
            'person_id'   => People::factory()->create(['full_name' => 'Ana Restaurada', 'birth_date' => '1970-01-01'])->id,
            'covenant_id' => $this->covenant->id,
            'active'      => true,
        ]);

        f0RestoreAs($this, $this->member)
            ->patchJson(route('panel.patients.restore', $this->patient->id))
            ->assertUnprocessable()
            ->assertJsonPath('message', __('validation.custom.patient.duplicate', ['codes' => $twin->code]));

        $twin->person->update(['full_name' => 'OUTRA PESSOA', 'national_registry' => '11122233344']);

        f0RestoreAs($this, $this->member)
            ->patchJson(route('panel.patients.restore', $this->patient->id))
            ->assertUnprocessable()
            ->assertJsonPath('message', __('validation.custom.patient.duplicate_cpf', ['codes' => $twin->code]));

        expect(Patient::find($this->patient->id))->toBeNull();
    });

    it('paciente de outra clínica: 404; perfil sem acesso a pacientes: 403', function () {
        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherEu = createEntityUser($other, User::factory()->create(), ClientRule::Admin->value);

        f0RestoreAs($this, $otherEu)->patchJson(route('panel.patients.restore', $this->patient->id))->assertNotFound();

        $plain = createEntityUser($this->entity, User::factory()->create(), ClientRule::User->value);
        f0RestoreAs($this, $plain)->patchJson(route('panel.patients.restore', $this->patient->id))->assertForbidden();

        expect(Patient::find($this->patient->id))->toBeNull();
    });
});

it('rota morta schedules/ajaxlist não existe mais', function () {
    expect(Route::has('panel.schedules.ajaxlist'))->toBeFalse();
});

it('pivots com id uuid: attach() pela relação grava a linha com id gerado', function () {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $user   = User::factory()->create();

    $entity->users()->attach($user->id, ['rule' => ClientRule::Secretary->value, 'active' => true, 'joined_at' => now()]);

    $membership = EntityUser::query()->where('entity_id', $entity->id)->where('user_id', $user->id)->sole();
    expect($membership->id)->not->toBeEmpty()
        ->and($membership->code)->not->toBeEmpty()
        ->and($user->entities()->pluck('entities.id')->all())->toBe([$entity->id]);

    $operator = TissOperator::query()->create(['ans_code' => '326305', 'name' => 'Operadora Teste', 'active' => true]);
    $batch    = TissBatch::query()->create([
        'entity_id'       => $entity->id, 'operator_id' => $operator->id, 'batch_number' => 'LOT-F0-0001',
        'reference_month' => now()->format('Y-m'), 'status' => 'sent',
    ]);
    $guide = TissGuide::query()->create([
        'entity_id'             => $entity->id, 'operator_id' => $operator->id, 'guide_type' => 'consultation',
        'guide_number_provider' => 'GUI-F0-1', 'status' => 'sent', 'attendance_date' => now()->toDateString(), 'total_amount' => 100,
    ]);

    $batch->guides()->attach($guide->id, ['entity_id' => $entity->id, 'attached_at' => now()]);

    expect($batch->guides()->first()?->pivot->id)->not->toBeEmpty()
        ->and($guide->batches()->pluck('tiss_batches.id')->all())->toBe([$batch->id]);
});

it('login de médico não grava mais selected_entity_doctor_id (ninguém lê)', function () {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $doctor = createDoctorForEntity($entity);
    $user   = $doctor->entityUser->user;
    $user->forceFill(['password' => bcrypt('Senha#123'), 'email_verified_at' => now()])->save();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'Senha#123']);

    expect(session()->has('selected_entity_id'))->toBeTrue()
        ->and(session()->has('selected_entity_doctor_id'))->toBeFalse();
});
