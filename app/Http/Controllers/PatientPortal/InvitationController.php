<?php

declare(strict_types=1);

namespace App\Http\Controllers\PatientPortal;

use App\Http\Controllers\Controller;
use App\Models\{PatientAccount, People};
use Illuminate\Database\QueryException;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\{Inertia, Response};

/**
 * Aceite de convite do Portal do Paciente — SEM auto-cadastro por CPF nesta
 * fase. A única porta de entrada é o link assinado (temporarySignedRoute,
 * 3 dias) disparado pelo staff via PatientPortalInvitationsController.
 *
 * `person_id` é lido SEMPRE de $request->query('person_id') — nunca de um
 * campo de formulário — porque o middleware `signed` já garante que a
 * querystring (incluindo person_id) não foi adulterada pelo cliente. Aceitar
 * um person_id vindo do body permitiria trocar de pessoa mantendo uma
 * assinatura válida de outro convite.
 */
class InvitationController extends Controller
{
    public function accept(Request $request): Response|RedirectResponse
    {
        $person = People::find((string) $request->query('person_id'));

        if (! $person) {
            abort(404);
        }

        // Link já usado: a conta para este person_id já existe — não duplica,
        // manda para o login com mensagem clara em vez de dar erro genérico.
        if (PatientAccount::where('person_id', $person->id)->exists()) {
            return redirect()->route('patient-portal.login')
                ->with('status', 'Este convite já foi utilizado. Faça login com sua senha.');
        }

        return Inertia::render('PatientPortal/Auth/AcceptInvitation', [
            'appName'  => config('app.name', 'EasyEye'),
            'personId' => $person->id,
            'name'     => $person->full_name,
            'email'    => $person->email,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $person = People::find((string) $request->query('person_id'));

        if (! $person) {
            abort(404);
        }

        if (PatientAccount::where('person_id', $person->id)->exists()) {
            return redirect()->route('patient-portal.login')
                ->with('status', 'Este convite já foi utilizado. Faça login com sua senha.');
        }

        abort_if(blank($person->email), 422, 'Paciente sem e-mail cadastrado — contate a clínica.');

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        try {
            // email_verified_at fica de fora de $fillable de propósito (o
            // paciente nunca deve conseguir setar isso via mass-assignment
            // em outro endpoint) — forceFill() aqui é o único lugar
            // autorizado a marcá-lo, no momento exato em que o e-mail do
            // convite (o mesmo já cadastrado pela clínica) é confirmado
            // implicitamente pelo aceite do link assinado.
            $account = new PatientAccount([
                'person_id' => $person->id,
                'email'     => $person->email,
                'password'  => $data['password'],
            ]);
            $account->forceFill(['email_verified_at' => now()]);
            $account->save();
        } catch (QueryException) {
            // Corrida rara (duplo clique/duas abas): o UNIQUE(person_id) do
            // banco pegou o que o check acima não pegou. Nunca duplica.
            return redirect()->route('patient-portal.login')
                ->with('status', 'Este convite já foi utilizado. Faça login com sua senha.');
        }

        Auth::guard('patient')->login($account);

        $request->session()->regenerate();

        return redirect()->route('patient-portal.dashboard');
    }
}
