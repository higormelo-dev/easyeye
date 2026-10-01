<?php

declare(strict_types=1);

namespace App\Http\Controllers\PatientPortal;

use App\Http\Controllers\Controller;
use App\Models\{PatientAccount, People};
use App\Services\PatientAccountLinkService;
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
 *
 * Várias clínicas: cada clínica tem o seu cadastro (People) do paciente. O
 * convite de uma segunda clínica NÃO cria outra conta — e pensa no paciente
 * leigo: logado, um clique ("Adicionar clínica"); deslogado, só o login (que
 * já conclui o vínculo — PatientAuthenticatedSessionController). Exige posse
 * do link (enviado ao e-mail cadastrado pela clínica) + conta com o MESMO
 * e-mail: a clínica nunca vincula nada sozinha, e convite para outro e-mail
 * não entra nesta conta (sai e aceita com aquele e-mail: acesso separado).
 * O CADASTRO do paciente na clínica nunca depende do portal.
 */
class InvitationController extends Controller
{
    /** Chave de sessão PRÓPRIA do portal — não a url.intended do staff. */
    public const INTENDED_KEY = 'patient_portal.intended_invitation';

    public function __construct(
        private readonly PatientAccountLinkService $links,
    ) {
    }

    public function accept(Request $request): Response|RedirectResponse
    {
        $person = $this->invitedPerson($request);

        // Link já usado: o cadastro já está numa conta — não duplica, manda
        // para o login com mensagem clara em vez de dar erro genérico.
        if ($this->links->isLinked($person->id)) {
            return $this->alreadyUsed();
        }

        if ($account = $this->activeAccount()) {
            return Inertia::render('PatientPortal/Auth/LinkClinic', [
                'appName'      => config('app.name', 'EasyEye'),
                'clinics'      => $this->links->clinicNames($person),
                'accountEmail' => $account->email,
                'inviteEmail'  => $person->email,
                'emailMatches' => $this->links->sameEmail($account->email, $person->email),
                't'            => trans('patient_portal.link'),
            ]);
        }

        if ($existing = $this->accountForEmail($person->email)) {
            return $this->loginToLink($request, $existing, $person);
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
        $person = $this->invitedPerson($request);

        if ($this->links->isLinked($person->id)) {
            return $this->alreadyUsed();
        }

        if ($account = $this->activeAccount()) {
            // Convite para outro e-mail nunca entra nesta conta (a tela nem
            // oferece o botão; aqui só chega requisição forjada). Com o MESMO
            // e-mail, um clique basta: o convite é do próprio dono da conta.
            abort_unless($this->links->sameEmail($account->email, $person->email), 403);

            if (! $this->links->link($account, $person)) {
                return $this->alreadyUsed();
            }

            return redirect()->route('patient-portal.dashboard')
                ->with('status', __('patient_portal.invitation.linked', ['clinic' => $this->links->clinicLabel($person)]));
        }

        abort_if(blank($person->email), 422, __('patient_portal.invitation.no_email'));

        // E-mail do convite já tem conta (cadastro de outra clínica): entra
        // nela e volta a este convite — nunca uma segunda conta.
        if ($existing = $this->accountForEmail($person->email)) {
            return $this->loginToLink($request, $existing, $person);
        }

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        try {
            $account = $this->links->createAccount($person, $data['password']);
        } catch (QueryException) {
            // Corrida rara (e-mail criado em outra aba): o UNIQUE do banco
            // pegou o que o check acima não pegou. Nunca duplica.
            $account = null;
        }

        if ($account === null) {
            return $this->alreadyUsed();
        }

        Auth::guard('patient')->login($account);

        $request->session()->regenerate();

        return redirect()->route('patient-portal.dashboard');
    }

    private function invitedPerson(Request $request): People
    {
        $person = People::find((string) $request->query('person_id'));

        abort_unless($person !== null, 404);

        return $person;
    }

    /**
     * Conta logada no guard do portal e ativa (kill-switch de suporte) —
     * status relido do banco: vincular clínica é passo sensível, não confia
     * no objeto em memória da sessão.
     */
    private function activeAccount(): ?PatientAccount
    {
        $account = Auth::guard('patient')->user();
        $fresh   = $account instanceof PatientAccount ? $account->fresh() : null;

        return $fresh?->active ? $fresh : null;
    }

    /** Conta (inclusive desativada/excluída) com o e-mail do convite. */
    private function accountForEmail(?string $email): ?PatientAccount
    {
        if (blank($email)) {
            return null;
        }

        return PatientAccount::query()
            ->withTrashed()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $email))])
            ->first();
    }

    /**
     * Guarda o PRÓPRIO link assinado (gerado pelo servidor) para voltar a ele
     * depois do login — PatientAuthenticatedSessionController só redireciona
     * para URLs deste convite (sem redirecionamento aberto).
     */
    private function loginToLink(Request $request, PatientAccount $existing, People $person): RedirectResponse
    {
        // Conta desativada/excluída não loga: mensagem clara em vez de mandar
        // para um login que nunca passa.
        if ($existing->trashed() || ! $existing->active) {
            return redirect()->route('patient-portal.login')
                ->with('status', __('patient_portal.invitation.account_disabled'));
        }

        $request->session()->put(self::INTENDED_KEY, $request->fullUrl());

        return redirect()->route('patient-portal.login')
            ->with('status', __('patient_portal.invitation.login_to_link', ['clinic' => $this->links->clinicLabel($person)]));
    }

    private function alreadyUsed(): RedirectResponse
    {
        return redirect()->route('patient-portal.login')
            ->with('status', __('patient_portal.invitation.already_used'));
    }
}
