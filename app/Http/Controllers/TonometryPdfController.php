<?php

namespace App\Http\Controllers;

use App\Enums\EntityGate;
use App\Models\{Entity, Patient, ReportSetting};
use App\Support\{BrazilianFormat, CurrentDoctor};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\{Request, Response};
use Illuminate\Support\Facades\Gate;

class TonometryPdfController extends Controller
{
    public function __invoke(Request $request, Patient $patient): Response
    {
        // Achado de segurança (auditoria panel.* IDOR): $patient chega via route
        // model binding, que roda antes de tenant.bind — EntityScope fica inerte
        // nesse momento e $patient podia ser de qualquer entity. Mesmo padrão já
        // corrigido em PatientsController::editData().
        abort_unless((string) $patient->entity_id === (string) session('selected_entity_id'), 404);

        // Laudo de tonometria é documento clínico: só médico com cadastro ativo
        // emite, e no nome dele (antes qualquer perfil escolhia o médico).
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail((string) session('selected_entity_id')));

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
        //   1. doctor_id explícito do request (admin selecionou) — escopado pela
        //      entity ativa (achado da mesma auditoria: doctor_id explícito não
        //      filtrava por entity, permitindo vazar nome de médico de outra clínica).
        //   2. fallback: médico vinculado ao usuário logado (perfil médico)
        // Sem médico → aborta. PDF clínico exige autoria identificada.
        //   Escopo pela clínica via entity_users (Doctor::ofEntity): `doctors` não
        //   tem entity_id — o where('entity_id') anterior era erro SQL (HTTP 500)
        //   sempre que doctor_id vinha no request.
        // doctor_id do request não escolhe mais o autor (aceito por
        // compatibilidade e ignorado): o laudo sai no nome de quem emite.
        $doctor = CurrentDoctor::issuer()?->loadMissing('person');
        abort_if($doctor === null, 403, CurrentDoctor::missingMessage());

        $time = $validated['time'] ?? now()->format('H:i');
        $od   = $validated['od'] ?? null;
        $oe   = $validated['oe'] ?? null;

        $addressParts = array_filter([
            $entity?->address,
            $entity?->number ? 'nº ' . $entity->number : null,
            $entity?->complement ?: null,
            $entity?->district ?: null,
            ($entity?->city && $entity?->state)
                ? $entity->city . '/' . $entity->state
                : ($entity?->city ?? $entity?->state ?? null),
            $entity?->zipcode ? 'CEP ' . BrazilianFormat::cep($entity->zipcode) : null,
        ]);

        $footerHtml = view('pdf.partials.footer', [
            'address'    => count($addressParts) ? implode(', ', $addressParts) : null,
            'telephone'  => $entity?->telephone ?: null,
            'cellphone'  => $entity?->cellphone ?: null,
            'email'      => $entity?->email ?: null,
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
