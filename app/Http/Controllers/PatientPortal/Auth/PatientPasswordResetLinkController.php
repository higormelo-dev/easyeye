<?php

declare(strict_types=1);

namespace App\Http\Controllers\PatientPortal\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\{Auth, Password};
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

class PatientPasswordResetLinkController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (Auth::guard('patient')->check()) {
            return redirect()->route('patient-portal.dashboard');
        }

        return Inertia::render('PatientPortal/Auth/ForgotPassword', [
            'appName' => config('app.name', 'EasyEye'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * Password::broker('patients') usa a tabela/provider próprios do paciente
     * (patient_account_password_reset_tokens) — nunca o broker "users" de staff.
     *
     * BUGFIX (revisão de segurança — enumeração de conta): antes, e-mail
     * inexistente devolvia "Não existe nenhum usuário com o e-mail
     * indicado." (lang/pt_BR/passwords.php) numa mensagem de erro distinta
     * da de sucesso — um atacante confirmava em 1 requisição se um e-mail
     * tem conta ativa no portal (dado sensível em clínica: "esta pessoa é
     * paciente aqui"). Agora a resposta é SEMPRE a mesma, exista ou não a
     * conta. O rate limit 'patient-password-email' (ver routes/patient-
     * portal-auth.php) continua barrando volume de tentativas.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::broker('patients')->sendResetLink($request->only('email'));

        return back()->with(
            'status',
            'Se este e-mail estiver cadastrado, você vai receber um link de redefinição de senha.',
        );
    }
}
