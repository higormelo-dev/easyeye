<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Services\Surgery\SurgeryIndicatorsService;
use App\Support\CurrentDoctor;
use App\Support\Surgery\SurgeryLang;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Inertia\{Inertia, Response as InertiaResponse};
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Indicadores cirúrgicos (ADR 0003, D13). Gate ViewSurgeryIndicators (admin,
 * financeiro, médico); o médico vê SÓ os próprios números (cirurgião do caso) e
 * perfil médico sem cadastro não vê nada. Só agregados — a exportação CSV
 * também (nenhum paciente, nenhum caso).
 */
class SurgeryIndicatorsController extends Controller
{
    use AuthorizesSurgery;

    private const MAX_DAYS = 1096;

    public function __construct(private readonly SurgeryIndicatorsService $service)
    {
    }

    public function index(Request $request): InertiaResponse
    {
        [$from, $to] = $this->period($request);
        $doctorId    = $this->scopeDoctorId();

        return Inertia::render('Panel/Surgery/Indicators', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('surgery.title'), 'url' => route('panel.surgery.index'), 'active' => false],
                ['label' => __('surgery_indicators.title'), 'url' => '#', 'active' => true],
            ],
            'indicators' => $this->service->summary((string) session('selected_entity_id'), $from, $to, $doctorId),
            'filters'    => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'scope'      => $doctorId === null ? 'clinic' : 'doctor',
            'urls'       => [
                'index'  => route('panel.surgery.indicators'),
                'export' => route('panel.surgery.indicators.export', ['from' => $from->toDateString(), 'to' => $to->toDateString()]),
            ],
            't' => SurgeryLang::all(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $data        = $this->service->summary((string) session('selected_entity_id'), $from, $to, $this->scopeDoctorId());
        $t           = fn (string $key): string => (string) __('surgery_indicators.csv.' . $key);

        // Número no formato do idioma (vírgula decimal em pt) e delimitador que o Excel do idioma abre (pt: ';').
        $locale    = app()->getLocale();
        $delimiter = str_starts_with($locale, 'pt') ? ';' : ',';
        $rows      = [[$t('section'), $t('indicator'), $t('value')]];
        $add       = function (string $section, string $indicator, mixed $value) use (&$rows, $t, $locale): void {
            $cell = match (true) {
                $value === null                    => '',
                is_int($value) || is_float($value) => (string) Number::format((float) $value, maxPrecision: 2, locale: $locale),
                default                            => (string) $value,
            };
            $rows[] = [$t($section), $indicator, $cell];
        };

        $a = $data['authorization'];

        foreach (['requested', 'authorized', 'partial', 'waived', 'denied', 'rate'] as $k) {
            $add('authorization', (string) __("surgery_indicators.authorization.{$k}"), $a[$k]);
        }

        foreach ($a['denial_reasons'] as $r) {
            $add('authorization', (string) __('surgery_indicators.authorization.reason', ['reason' => $r['other'] ? __('surgery_indicators.authorization.other_reasons') : $r['reason']]), $r['count']);
        }

        foreach ($data['times'] as $name => $stats) {
            $add('times', (string) __("surgery_indicators.times.{$name}") . ' (' . $t('mean') . ')', $stats['mean']);
            $add('times', (string) __("surgery_indicators.times.{$name}") . ' (' . $t('median') . ')', $stats['median']);
        }
        $add('cancellations', (string) __('surgery_indicators.cancellations.cancelled'), $data['cancellations']['cancelled']);
        $add('cancellations', (string) __('surgery_indicators.cancellations.suspended'), $data['cancellations']['suspended']);
        $add('surgeries', (string) __('surgery_indicators.surgeries.total'), $data['surgeries']['total']);

        foreach (['by_surgeon', 'by_facility', 'by_month'] as $group) {
            foreach ($data['surgeries'][$group] as $row) {
                $add('surgeries', (string) __("surgery_indicators.surgeries.{$group}") . ': ' . $row['label'], $row['count']);
            }
        }

        foreach ($data['complications']['by_milestone'] as $row) {
            $add('complications', $row['label'] . ' — ' . $t('with_findings'), $row['with_findings']);
            $add('complications', $row['label'] . ' — ' . $t('severe'), $row['severe']);
        }

        foreach ($data['refractive']['by_surgeon'] as $row) {
            foreach (['n', 'me', 'mae', 'within_050', 'within_100'] as $k) {
                $add('refractive', $row['surgeon'] . ' — ' . (string) __("surgery_indicators.refractive.{$k}"), $row[$k]);
            }
        }

        $filename = sprintf('indicadores-cirurgicos-%s-%s.csv', $from->format('Ymd'), $to->format('Ymd'));

        return response()->streamDownload(function () use ($rows, $delimiter): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                // Neutraliza fórmula de planilha (=, +, -, @) em texto digitado (motivos).
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) && ! is_numeric($cell) ? "'" . $cell : $cell, $row), $delimiter);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /** Médico → o próprio id; perfil médico sem cadastro → 403; demais → null (clínica toda). */
    private function scopeDoctorId(): ?string
    {
        Gate::authorize(EntityGate::ViewSurgeryIndicators->value, $this->surgeryEntity());

        if (! CurrentDoctor::isDoctorProfile()) {
            return null;
        }

        $doctor = CurrentDoctor::doctor();

        abort_if($doctor === null, 403, CurrentDoctor::missingMessage());

        return (string) $doctor->id;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        try {
            $to   = $request->filled('to') ? Carbon::parse((string) $request->query('to'))->endOfDay() : now()->endOfDay();
            $from = $request->filled('from') ? Carbon::parse((string) $request->query('from'))->startOfDay() : $to->copy()->subDays(89)->startOfDay();
        } catch (Throwable) {
            $to   = now()->endOfDay();
            $from = $to->copy()->subDays(89)->startOfDay();
        }

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS)->startOfDay();
        }

        return [$from, $to];
    }
}
