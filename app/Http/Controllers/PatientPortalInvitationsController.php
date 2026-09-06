<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{Patient, PatientAccount};
use App\Notifications\PatientPortalInvitation;
use Illuminate\Http\RedirectResponse;

/**
 * Disparo do convite do Portal do Paciente pelo staff.
 *
 * ACL: checagem EXPLÍCITA de entity_id abaixo — NÃO confiar apenas no route
 * model binding de {patient} + EntityScope global do model Patient. Verificado
 * nesta entrega (achado de segurança, fora do escopo desta Fase 1 corrigir em
 * outros lugares): o middleware framework `SubstituteBindings` roda ANTES de
 * `tenant.bind` na ordem de prioridade do Laravel, então o TenantContext AINDA
 * NÃO está vinculado no momento em que {patient} é resolvido — o EntityScope
 * fica inerte e o binding sozinho NÃO impede acesso cross-tenant. Reproduzido
 * com o mesmo padrão em PatientsController::editData() (também usa
 * `Patient $patient` bindado sem checagem própria) — staff de uma entity
 * consegue ler PII completo (CPF, endereço, telefone) de paciente de OUTRA
 * entity só sabendo o UUID. Vulnerabilidade pré-existente, fora do escopo
 * desta entrega corrigir (reportar ao revisor de segurança); a defesa real
 * aqui é a comparação explícita abaixo, não o binding.
 */
class PatientPortalInvitationsController extends Controller
{
    public function store(Patient $patient): RedirectResponse
    {
        abort_unless(
            (string) $patient->entity_id === (string) session('selected_entity_id'),
            404,
        );

        $person = $patient->person;

        abort_if(blank($person?->email), 422, 'Este paciente não possui e-mail cadastrado. Atualize o cadastro antes de convidar.');

        if (PatientAccount::where('person_id', $person->id)->exists()) {
            return back()->with('error', 'Este paciente já possui conta no Portal do Paciente.');
        }

        $person->notify(new PatientPortalInvitation(
            personId: $person->id,
            patientName: $person->full_name,
            clinicName: $patient->entity?->name ?? config('app.name', 'EasyEye'),
        ));

        return back()->with('success', "Convite enviado para {$person->email}.");
    }
}
