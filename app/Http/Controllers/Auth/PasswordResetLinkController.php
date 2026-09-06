<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'appName' => config('app.name', 'EasyEye'),
            't'       => trans('auth'),
        ])->rootView('guest-app');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * BUGFIX (revisão de segurança — enumeração de conta, achada na área do
     * paciente e replicada aqui do mesmo padrão): antes, e-mail inexistente
     * devolvia "Não existe nenhum usuário com o e-mail indicado."
     * (lang/pt_BR/passwords.php) numa mensagem de erro distinta da de
     * sucesso — confirmava em 1 requisição se um e-mail tem conta de staff.
     * Agora a resposta é SEMPRE a mesma, exista ou não a conta.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        return back()->with(
            'status',
            'Se este e-mail estiver cadastrado, você vai receber um link de redefinição de senha.',
        );
    }
}
