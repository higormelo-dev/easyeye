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

        $doctor = isset($validated['doctor_id'])
            ? Doctor::with('person')->find($validated['doctor_id'])
            : null;

        $time = $validated['time'] ?? now()->format('H:i');
        $od   = $validated['od'] ?? null;
        $oe   = $validated['oe'] ?? null;

        return SnappyPdf::loadView('pdf.tonometry', compact('patient', 'entity', 'setting', 'time', 'od', 'oe', 'doctor'))
            ->setPaper('A5', 'portrait')
            ->setOption('margin-top', '1.5cm')
            ->setOption('margin-right', '1.5cm')
            ->setOption('margin-bottom', '1.5cm')
            ->setOption('margin-left', '1.5cm')
            ->setOption('encoding', 'UTF-8')
            ->inline('TONOMETRIA.pdf');
    }
}
