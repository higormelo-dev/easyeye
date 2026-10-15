<?php

declare(strict_types=1);

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\Surgery\{SurgicalAuthorizationEvent, SurgicalCase, SurgicalCaseIolPlan, SurgicalPostopEvaluation};
use App\Services\Surgery\PostOp\PostOpEvaluationService;
use App\Services\Surgery\{SurgeryIndicatorsService, SurgicalCaseService};
use Carbon\Carbon;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;

/*
 * Módulo cirúrgico (Fase 4) / M5 — indicadores: taxa de autorização, prazos,
 * cirurgias, complicações e erro refracional (ME, MAE, ±0,50 D, ±1,00 D), com
 * dados conhecidos; escopo do médico, amostra pequena, CSV sem PHI e cache.
 *
 * Relógio fixo: quinta-feira, 15/10/2026, 10:00.
 */

function sixCase(object $w, string $doctorId, array $attrs = [], ?float $predicted = null, ?float $obtained = null): SurgicalCase
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, [
        'patient_id' => (string) $w->patient->id, 'surgeon_doctor_id' => $doctorId,
        'title'      => 'Facoemulsificação', 'eye' => 'od', 'procedures' => [['description' => 'Faco']],
    ]);

    $case->forceFill(array_merge([
        'status' => SurgicalCaseStatus::Performed, 'requested_at' => now()->subDays(10), 'authorized_at' => now()->subDays(5), 'performed_at' => now()->subDays(2),
    ], $attrs))->save();

    if ($predicted !== null) {
        SurgicalCaseIolPlan::query()->create([
            'entity_id'   => $case->entity_id, 'surgical_case_id' => $case->id, 'eye' => 'od',
            'model_label' => 'LIO X', 'power' => 21, 'predicted_refraction' => $predicted,
        ]);
    }

    if ($obtained !== null) {
        $e = SurgicalPostopEvaluation::query()->create([
            'entity_id' => $case->entity_id, 'surgical_case_id' => $case->id, 'patient_id' => $case->patient_id, 'doctor_id' => $doctorId,
            'milestone' => 'd30', 'eye' => 'od', 'evaluated_at' => now()->subDay(), 'findings' => [], 'spherical_equivalent' => $obtained, 'severe' => false,
        ]);
        DB::table('surgical_postop_evaluations')->where('id', $e->id)->update(['status' => 'finalized', 'finalized_at' => now(), 'content_hash' => str_repeat('c', 64)]);
    }

    return $case->refresh();
}

function sixEvent(SurgicalCase $case, string $type, array $extra = []): void
{
    SurgicalAuthorizationEvent::query()->create(array_merge([
        'entity_id' => $case->entity_id, 'surgical_case_id' => $case->id, 'type' => $type, 'occurred_at' => now()->subDays(3),
    ], $extra));
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    Cache::flush();
    $this->w     = surgeryWorld();
    $this->other = createDoctorForEntity($this->w->entity);
    $this->svc   = app(SurgeryIndicatorsService::class);
    $this->from  = now()->subDays(30);
    $this->to    = now();
});

it('erro refracional: ME, MAE e % dentro de ±0,50 D / ±1,00 D com valores conhecidos', function () {
    // erros = obtido − previsto: -0,25 · +0,50 · -0,75 · +1,25
    sixCase($this->w, (string) $this->w->doctor->id, [], -0.25, -0.50);
    sixCase($this->w, (string) $this->w->doctor->id, [], 0.00, 0.50);
    sixCase($this->w, (string) $this->w->doctor->id, [], 0.25, -0.50);
    sixCase($this->w, (string) $this->w->doctor->id, [], 0.00, 1.25);
    sixCase($this->w, (string) $this->other->id, [], 0.00, 0.25); // outro cirurgião

    $r = $this->svc->summary((string) $this->w->entity->id, $this->from, $this->to)['refractive'];

    $mine = collect($r['by_surgeon'])->firstWhere('surgeon', 'DR. CIRURGIAO');

    expect($mine)->toMatchArray(['n' => 4, 'me' => 0.19, 'mae' => 0.69, 'within_050' => 50.0, 'within_100' => 75.0, 'small_sample' => true])
        ->and($r['by_surgeon'])->toHaveCount(2)
        ->and($r['overall']['n'])->toBe(5);
});

it('a refração corrigida por adendo substitui a original; olho sem previsão ou sem refração fica de fora', function () {
    $case = sixCase($this->w, (string) $this->w->doctor->id, [], 0.00, -2.00);
    sixCase($this->w, (string) $this->w->doctor->id, [], null, 0.50);   // sem LIO prevista
    sixCase($this->w, (string) $this->w->doctor->id, [], 0.00, null);   // sem avaliação

    $evaluation = SurgicalPostopEvaluation::query()->where('surgical_case_id', $case->id)->firstOrFail();
    DB::table('surgical_postop_evaluation_addenda')->insert([
        'id'               => (string) Str::uuid(), 'entity_id' => $case->entity_id, 'surgical_postop_evaluation_id' => $evaluation->id,
        'surgical_case_id' => $case->id, 'doctor_id' => $this->w->doctor->id, 'text' => 'Corrigido', 'corrected_spherical_equivalent' => -0.25,
        'content_hash'     => str_repeat('d', 64), 'created_at' => now(),
    ]);

    $r = $this->svc->summary((string) $this->w->entity->id, $this->from, $this->to)['refractive'];

    expect($r['overall'])->toMatchArray(['n' => 1, 'me' => -0.25, 'mae' => 0.25])
        ->and(PostOpEvaluationService::effectiveEquivalent($evaluation->refresh()))->toBe(-0.25);
});

it('taxa de autorização, motivos de negativa (com números mascarados) e prazos', function () {
    $a = sixCase($this->w, (string) $this->w->doctor->id, ['requested_at' => now()->subDays(10), 'authorized_at' => now()->subDays(6)]);
    $b = sixCase($this->w, (string) $this->w->doctor->id, ['requested_at' => now()->subDays(12), 'authorized_at' => now()->subDays(6), 'performed_at' => now()->subDay()]);

    sixEvent($a, 'requested');
    sixEvent($a, 'authorized', ['authorization_number' => 'X']);
    sixEvent($b, 'authorized');
    sixEvent($b, 'partially_authorized');
    sixEvent($b, 'waived');
    sixEvent($a, 'denied', ['denial_reason' => '  Fora da   COBERTURA ']);
    sixEvent($b, 'denied', ['denial_reason' => 'fora da cobertura']);
    sixEvent($b, 'denied', ['denial_reason' => 'FORA DA COBERTURA']);
    sixEvent($b, 'denied', ['denial_reason' => 'carteirinha 1234567890 vencida']);

    $s = $this->svc->summary((string) $this->w->entity->id, $this->from, $this->to);

    expect($s['authorization'])->toMatchArray(['requested' => 1, 'authorized' => 2, 'partial' => 1, 'waived' => 1, 'denied' => 4, 'responded' => 8, 'rate' => 50.0])
        ->and($s['authorization']['denial_reasons'][0])->toMatchArray(['reason' => 'fora da cobertura', 'other' => false, 'count' => 3])
        // O motivo que aparece só uma vez (e tem número digitado) vira "Outros": n mínimo contra reidentificação.
        ->and($s['authorization']['denial_reasons'][1])->toMatchArray(['reason' => null, 'other' => true, 'count' => 1])
        ->and(json_encode($s['authorization']['denial_reasons']))->not->toContain('1234567890')
        // pedido→autorização: 4 e 6 dias → média 5,0 e mediana 5,0
        ->and($s['times']['request_to_authorization'])->toBe(['n' => 2, 'mean' => 5.0, 'median' => 5.0])
        // autorização→cirurgia: 4 (a) e 5 (b) dias
        ->and($s['times']['authorization_to_surgery'])->toBe(['n' => 2, 'mean' => 4.5, 'median' => 4.5])
        ->and($s['surgeries']['total'])->toBe(2);
});

it('cancelamentos, suspensões e cirurgias por cirurgião/mês; período exclui o que está fora', function () {
    sixCase($this->w, (string) $this->w->doctor->id);
    sixCase($this->w, (string) $this->other->id);
    sixCase($this->w, (string) $this->w->doctor->id, ['performed_at' => now()->subDays(80)]); // fora do período
    sixCase($this->w, (string) $this->w->doctor->id, ['status' => SurgicalCaseStatus::Cancelled, 'performed_at' => null, 'cancelled_at' => now()->subDay(), 'cancellation_reason' => 'Paciente desistiu']);
    sixCase($this->w, (string) $this->w->doctor->id, ['status' => SurgicalCaseStatus::Suspended, 'performed_at' => null, 'suspended_at' => now()->subDay(), 'suspension_reason' => 'Febre', 'status_history' => [['to' => 'suspended', 'at' => now()->subDay()->toIso8601String(), 'reason' => 'Febre']]]);

    $s = $this->svc->summary((string) $this->w->entity->id, $this->from, $this->to);

    expect($s['surgeries']['total'])->toBe(2)
        ->and(collect($s['surgeries']['by_surgeon'])->pluck('count', 'label')->all())->toHaveCount(2)
        ->and($s['surgeries']['by_month'][0])->toBe(['label' => '2026-10', 'count' => 2])
        ->and($s['cancellations'])->toMatchArray(['cancelled' => 1, 'suspended' => 1])
        ->and($s['cancellations'])->not->toHaveKeys(['cancel_reasons', 'suspend_reasons']);
});

it('complicações por marco: avaliações finalizadas com achados e graves', function () {
    $case = sixCase($this->w, (string) $this->w->doctor->id);

    foreach ([['d1', ['corneal_edema'], false], ['d1', [], false], ['d7', ['endophthalmitis_suspected'], true]] as $i => [$m, $f, $severe]) {
        $e = SurgicalPostopEvaluation::query()->create([
            'entity_id' => $case->entity_id, 'surgical_case_id' => $case->id, 'patient_id' => $case->patient_id, 'doctor_id' => $this->w->doctor->id,
            'milestone' => $m, 'eye' => $i === 1 ? 'oe' : 'od', 'evaluated_at' => now(), 'findings' => $f, 'severe' => $severe,
        ]);
        DB::table('surgical_postop_evaluations')->where('id', $e->id)->update(['status' => 'finalized', 'finalized_at' => now(), 'content_hash' => str_repeat('e', 64)]);
    }

    $rows = collect($this->svc->summary((string) $this->w->entity->id, $this->from, $this->to)['complications']['by_milestone'])->keyBy('milestone');

    expect($rows['d1'])->toMatchArray(['evaluations' => 2, 'with_findings' => 1, 'severe' => 0])
        ->and($rows['d7'])->toMatchArray(['evaluations' => 1, 'with_findings' => 1, 'severe' => 1]);
});

it('o cache é por clínica e escopo: médico nunca lê o da clínica nem o de outro médico', function () {
    sixCase($this->w, (string) $this->w->doctor->id);
    sixCase($this->w, (string) $this->other->id);

    $clinic = $this->svc->summary((string) $this->w->entity->id, $this->from, $this->to);
    $mine   = $this->svc->summary((string) $this->w->entity->id, $this->from, $this->to, (string) $this->w->doctor->id);
    $theirs = $this->svc->summary((string) $this->w->entity->id, $this->from, $this->to, (string) $this->other->id);

    expect([$clinic['surgeries']['total'], $mine['surgeries']['total'], $theirs['surgeries']['total']])->toBe([2, 1, 1]);

    // Dentro do TTL o número não muda; outra clínica nunca enxerga estes dados.
    sixCase($this->w, (string) $this->w->doctor->id);
    expect($this->svc->summary((string) $this->w->entity->id, $this->from, $this->to)['surgeries']['total'])->toBe(2);

    $w2 = surgeryWorld();
    expect($this->svc->summary((string) $w2->entity->id, $this->from, $this->to)['surgeries']['total'])->toBe(0);
});

// ── Tela, gate e CSV ────────────────────────────────────────────────────────

it('admin, financeiro e médico abrem; secretária não; o médico vê só os próprios números', function () {
    sixCase($this->w, (string) $this->w->doctor->id);
    sixCase($this->other ? $this->w : $this->w, (string) $this->other->id);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.indicators'))->assertForbidden();

    surgeryAs($this->w, 'admin')->get(route('panel.surgery.indicators'))
        ->assertInertia(fn ($page) => $page->component('Panel/Surgery/Indicators')->where('scope', 'clinic')->where('indicators.surgeries.total', 2));

    surgeryAs($this->w, 'financial')->get(route('panel.surgery.indicators'))
        ->assertInertia(fn ($page) => $page->where('scope', 'clinic')->where('indicators.surgeries.total', 2));

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.indicators'))
        ->assertInertia(fn ($page) => $page->where('scope', 'doctor')->where('indicators.surgeries.total', 1)->has('t.indicators.title'));
});

it('clínica sem o recurso has_surgery não abre os indicadores', function () {
    $w = surgeryWorld(withModule: false);

    surgeryAs($w, 'admin')->getJson(route('panel.surgery.indicators'))->assertForbidden();
});

it('o período aceita datas, corrige intervalo invertido e limita o tamanho', function () {
    sixCase($this->w, (string) $this->w->doctor->id);

    surgeryAs($this->w, 'admin')->get(route('panel.surgery.indicators', ['from' => '2026-10-31', 'to' => '2026-10-01']))
        ->assertInertia(fn ($page) => $page->where('filters.from', '2026-10-01')->where('filters.to', '2026-10-31')->where('indicators.surgeries.total', 1));

    surgeryAs($this->w, 'admin')->get(route('panel.surgery.indicators', ['from' => '1990-01-01']))
        ->assertInertia(fn ($page) => $page->where('filters.from', now()->subDays(1096)->toDateString()));

    surgeryAs($this->w, 'admin')->get(route('panel.surgery.indicators', ['from' => 'lixo', 'to' => 'lixo']))->assertOk();
});

it('o CSV tem só agregados: sem nome de paciente, código do caso nem fórmula de planilha', function () {
    $case = sixCase($this->w, (string) $this->w->doctor->id, [], 0.0, 0.25);
    sixEvent($case, 'denied', ['denial_reason' => '=HYPERLINK("http://x")']);

    $response = surgeryAs($this->w, 'admin')->get(route('panel.surgery.indicators.export'));

    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain('Seção;Indicador;Valor')
        ->and($csv)->toContain('Taxa de autorização')
        ->and($csv)->not->toContain('PACIENTE CIRURGICO')
        ->and($csv)->not->toContain($case->code)
        ->and($csv)->not->toContain(';=HYPERLINK');

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.indicators.export'))->assertForbidden();
});

it('o CSV usa o formato do idioma: vírgula decimal e ";" em pt; ponto decimal e "," em en', function () {
    $a = sixCase($this->w, (string) $this->w->doctor->id);

    foreach (range(1, 4) as $i) {
        sixEvent($a, 'authorized');
    }

    foreach (range(1, 3) as $i) {
        sixEvent($a, 'denied', ['denial_reason' => 'x']);
    }

    $pt = surgeryAs($this->w, 'admin')->get(route('panel.surgery.indicators.export'))->streamedContent();

    expect($pt)->toContain('"Taxa de autorização (%)";57,1')->and($pt)->toContain('Seção;Indicador;Valor');

    $this->w->entity->update(['locale' => 'en']);

    $en = surgeryAs($this->w, 'admin')->get(route('panel.surgery.indicators.export'))->streamedContent();

    expect($en)->toContain('"Authorization rate (%)",57.1')->and($en)->toContain('Section,Indicator,Value');
});
