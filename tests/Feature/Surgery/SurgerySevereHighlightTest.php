<?php

declare(strict_types=1);

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Services\Surgery\SurgicalCaseService;
use Carbon\Carbon;

/*
 * Complicação grave marcada no pós-operatório (ex.: endoftalmite suspeita)
 * destaca o caso na lista, no cabeçalho e na aba — texto e cor, nunca só cor.
 */
function ssh_case(object $w, string $title)
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, [
        'patient_id' => (string) $w->patient->id, 'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'      => $title, 'eye' => 'od',
        'procedures' => [['description' => 'Facoemulsificação', 'tuss_code' => '30306019']],
    ]);
    $case->forceFill(['status' => SurgicalCaseStatus::Performed, 'performed_at' => now()])->save();

    return $case->refresh();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();
});

it('a lista, o cabeçalho e a aba destacam só o caso com complicação grave', function () {
    $severe = ssh_case($this->w, 'Caso com complicação');
    $calm   = ssh_case($this->w, 'Caso tranquilo');

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.postop.evaluations.store', $severe), [
        'milestone' => 'd1', 'eye' => 'od', 'evaluated_at' => '2026-10-15 09:30:00', 'iop_mmhg' => 14,
        'findings'  => ['endophthalmitis_suspected'], 'adherence' => 'good', 'plan' => 'Internar.',
    ])->assertCreated();

    $list = surgeryAs($this->w, 'doctor')->get(route('panel.surgery.index'));
    $list->assertInertia(fn ($page) => $page
        ->where('cases.data', fn ($rows) => collect($rows)->firstWhere('title', 'Caso com complicação')['severe_complication'] === true
            && collect($rows)->firstWhere('title', 'Caso tranquilo')['severe_complication'] === false));

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $severe))->assertInertia(fn ($page) => $page
        ->where('surgicalCase.severe_complication', true)
        ->where('tabs', fn ($tabs) => collect($tabs)->firstWhere('key', 'postop')['badge']['tone'] === 'danger'));

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $calm))->assertInertia(fn ($page) => $page
        ->where('surgicalCase.severe_complication', false)
        ->where('tabs', fn ($tabs) => collect($tabs)->firstWhere('key', 'postop')['badge'] === null));
});
