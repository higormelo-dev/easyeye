<?php

namespace App\Http\Controllers;

use App\Models\{Doctor, Entity, Patient, ReportSetting};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\{Request, Response};

class TonometryPdfController extends Controller
{
    public function __invoke(Request $request, Patient $patient): Response
    {
        $validated = $request->validate([
            'od'        => ['nullable', 'numeric', 'min:0', 'max:999'],
            'oe'        => ['nullable', 'numeric', 'min:0', 'max:999'],
            'time'      => ['nullable', 'string', 'max:5'],
            'doctor_id' => ['nullable', 'uuid'],
        ]);

        $patient->loadMissing('person');

        $entity  = Entity::find(session('selected_entity_id'));
        $setting = ReportSetting::where('entity_id', $entity?->id)
            ->where('active', true)
            ->first();

        // Resolve médico:
        //   1. doctor_id explícito do request (admin selecionou)
        //   2. fallback: médico vinculado ao usuário logado (perfil médico)
        // Sem médico → aborta. PDF clínico exige autoria identificada.
        $doctor = isset($validated['doctor_id'])
            ? Doctor::with('person')->find($validated['doctor_id'])
            : null;

        if (! $doctor) {
            $doctor = Doctor::with('person')
                ->whereHas('entityUser', fn ($q) => $q
                    ->where('entity_id', $entity?->id)
                    ->where('user_id', auth()->id()))
                ->first();
        }

        abort_if(! $doctor, 422, 'Selecione o médico responsável antes de imprimir.');

        $time = $validated['time'] ?? now()->format('H:i');
        $od   = $validated['od'] ?? null;
        $oe   = $validated['oe'] ?? null;

        $addressParts = array_filter([
            $entity?->address,
            $entity?->number     ? 'nº ' . $entity->number : null,
            $entity?->complement ?: null,
            $entity?->district   ?: null,
            ($entity?->city && $entity?->state)
                ? $entity->city . '/' . $entity->state
                : ($entity?->city ?? $entity?->state ?? null),
            $entity?->zipcode ? 'CEP ' . $entity->zipcode : null,
        ]);

        $footerHtml = view('pdf.partials.footer', [
            'address'    => count($addressParts) ? implode(', ', $addressParts) : null,
            'telephone'  => $entity?->telephone  ?: null,
            'cellphone'  => $entity?->cellphone  ?: null,
            'email'      => $entity?->email      ?: null,
            'fontFamily' => $setting?->font_family ?? 'Arial',
        ])->render();

        $footerPath = tempnam(sys_get_temp_dir(), 'eeye_ftr_') . '.html';
        file_put_contents($footerPath, $footerHtml);

        $pdf = SnappyPdf::loadView('pdf.tonometry', compact('patient', 'entity', 'setting', 'time', 'od', 'oe', 'doctor'))
            ->setPaper('A5', 'portrait')
            ->setOption('margin-top', '1.5cm')
            ->setOption('margin-right', '1.5cm')
            ->setOption('margin-bottom', '2cm')
            ->setOption('margin-left', '1.5cm')
            ->setOption('encoding', 'UTF-8')
            ->setOption('footer-html', $footerPath)
            ->inline('TONOMETRIA.pdf');

        @unlink($footerPath);

        return $pdf;
    }
}
