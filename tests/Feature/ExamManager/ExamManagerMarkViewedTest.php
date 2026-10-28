<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{AuditLog, Entity, Patient, PatientExam, User};
use Illuminate\Support\Facades\DB;

/**
 * Visto/não visto por exame (Gerenciador de exames): PUT .../exams/{exam}/viewed
 * grava `viewed_at` na primeira exibição, sem auditoria e sem mexer em
 * updated_at; o filtro `viewed` e o payload `viewed_at` fecham o circuito.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->members = [];

    foreach ([ClientRule::Admin, ClientRule::Doctor, ClientRule::Secretary, ClientRule::Financial] as $rule) {
        $user = User::factory()->create();

        $this->members[$rule->value] = [$user, createEntityUser($this->entity, $user, $rule->value)];
    }

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->exam    = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
});

function markViewedAs($test, string $rule, PatientExam $exam)
{
    [$user, $member] = $test->members[$rule];

    return $test->actingAs($user)->withSession(panelSession($member))
        ->putJson(route('panel.exam-manager.exams.viewed', $exam));
}

describe('PUT exam-manager/exams/{exam}/viewed', function () {
    it('qualquer perfil que abre o Gerenciador marca o exame como visto', function (string $rule) {
        $exam = PatientExam::factory()->create(['patient_id' => $this->patient->id]);

        expect($exam->fresh()->viewed_at)->toBeNull();

        $response = markViewedAs($this, $rule, $exam)->assertOk();

        expect($response->json('id'))->toBe((string) $exam->id)
            ->and($response->json('viewed_at'))->not->toBeNull()
            ->and($exam->fresh()->viewed_at)->not->toBeNull()
            ->and($exam->fresh()->viewed_at->toIso8601String())->toBe($response->json('viewed_at'));
    })->with(['admin', 'doctor', 'secretary', 'financial']);

    it('é idempotente: a segunda chamada mantém o instante original', function () {
        $this->travelTo(now()->setMicrosecond(0));

        markViewedAs($this, 'doctor', $this->exam)->assertOk();
        $first = $this->exam->fresh()->viewed_at;

        $this->travel(2)->hours();

        $second = markViewedAs($this, 'secretary', $this->exam)->assertOk();

        expect($this->exam->fresh()->viewed_at->equalTo($first))->toBeTrue()
            ->and($second->json('viewed_at'))->toBe($first->toIso8601String());
    });

    it('exame de outra clínica dá 404 e não é marcado', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
        $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id]);

        markViewedAs($this, 'admin', $otherExam)->assertNotFound();

        expect($otherExam->fresh()->viewed_at)->toBeNull();
    });

    it('visitante não marca', function () {
        $this->putJson(route('panel.exam-manager.exams.viewed', $this->exam))->assertUnauthorized();

        expect($this->exam->fresh()->viewed_at)->toBeNull();
    });

    it('não gera auditoria nem mexe em updated_at (só registra quando foi visto)', function () {
        $this->exam->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
        $updatedAt = $this->exam->fresh()->updated_at;
        $before    = AuditLog::query()->where('auditable_type', PatientExam::class)->where('auditable_id', $this->exam->id)->count();

        markViewedAs($this, 'doctor', $this->exam)->assertOk();

        $after = AuditLog::query()->where('auditable_type', PatientExam::class)->where('auditable_id', $this->exam->id)->count();

        expect($after)->toBe($before)
            ->and($this->exam->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    });
});

describe('payload e filtro de visto', function () {
    function searchViewed($test, array $query = [])
    {
        [$user, $member] = $test->members['doctor'];

        return $test->actingAs($user)->withSession(panelSession($member))
            ->getJson(route('panel.exam-manager.search', $query));
    }

    it('o exame sai com viewed_at nulo até ser visto e preenchido depois', function () {
        $exam = searchViewed($this)->assertOk()->json('patients.0.exams.0');

        expect($exam)->toHaveKey('viewed_at')->and($exam['viewed_at'])->toBeNull();

        markViewedAs($this, 'doctor', $this->exam)->assertOk();

        expect(searchViewed($this)->json('patients.0.exams.0.viewed_at'))->not->toBeNull();
    });

    it('viewed=nao_vistos traz só os não vistos; viewed=vistos só os vistos', function () {
        $seen = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
        markViewedAs($this, 'doctor', $seen)->assertOk();

        $unseenIds = collect(searchViewed($this, ['viewed' => 'nao_vistos'])->assertOk()->json('patients.0.exams'))->pluck('id')->all();
        $seenIds   = collect(searchViewed($this, ['viewed' => 'vistos'])->assertOk()->json('patients.0.exams'))->pluck('id')->all();

        expect($unseenIds)->toBe([(string) $this->exam->id])
            ->and($seenIds)->toBe([(string) $seen->id]);
    });

    it('o contador total_exams acompanha o filtro', function () {
        markViewedAs($this, 'doctor', PatientExam::factory()->create(['patient_id' => $this->patient->id]))->assertOk();

        expect(searchViewed($this, ['viewed' => 'nao_vistos'])->json('total_exams'))->toBe(1)
            ->and(searchViewed($this, ['viewed' => 'vistos'])->json('total_exams'))->toBe(1)
            ->and(searchViewed($this)->json('total_exams'))->toBe(2);
    });

    it('valor fora da allowlist é ignorado (sem filtro, sem erro)', function () {
        markViewedAs($this, 'doctor', PatientExam::factory()->create(['patient_id' => $this->patient->id]))->assertOk();

        $response = searchViewed($this, ['viewed' => "x'; DROP TABLE patient_exams;--"])->assertOk();

        expect($response->json('total_exams'))->toBe(2);
    });

    it('a página index devolve a rota de marcação e o eco do filtro', function () {
        [$user, $member] = $this->members['doctor'];

        $this->actingAs($user)->withSession(panelSession($member))
            ->get(route('panel.exam-manager.index', ['viewed' => 'nao_vistos']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('urls.exam_viewed_update', route('panel.exam-manager.exams.viewed', ['exam' => '__ID__']))
                ->where('filters.viewed', 'nao_vistos'));
    });
});

describe('migration viewed_at (backfill)', function () {
    it('exames que já existiam nascem vistos (viewed_at = created_at); os criados depois começam nulos', function () {
        $migration = require database_path('migrations/2026_11_01_000100_add_viewed_at_to_patient_exams_table.php');
        $migration->down();

        $old   = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
        $older = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
        DB::table('patient_exams')->where('id', $old->id)->update(['created_at' => '2026-03-10 08:15:00']);
        DB::table('patient_exams')->where('id', $older->id)->update(['created_at' => '2025-11-01 21:40:00']);

        $migration->up();

        expect(DB::table('patient_exams')->where('id', $old->id)->value('viewed_at'))->toBe('2026-03-10 08:15:00')
            ->and(DB::table('patient_exams')->where('id', $older->id)->value('viewed_at'))->toBe('2025-11-01 21:40:00')
            // Exame criado depois da migration: não visto até alguém abrir.
            ->and(PatientExam::factory()->create(['patient_id' => $this->patient->id])->fresh()->viewed_at)->toBeNull();
    });
});
