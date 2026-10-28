<?php

namespace App\Exceptions\Billing;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Erro do checkout transparente com código estável para o front
 * (`code`) e mensagem pronta para o usuário (`message`, no idioma dele).
 */
class CheckoutException extends BillingException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function make(string $code, int $status = 422, array $replace = []): self
    {
        return new self($code, __("checkout.errors.{$code}", $replace), $status);
    }

    /** Erro esperado do fluxo (recusa, forma indisponível): não vai para o log/Sentry. */
    public function report(): void
    {
    }

    /**
     * XHR/JSON (o checkout chama por axios): {message, code}. Navegação no
     * navegador (ex.: abrir /panel/my-subscription sem permissão): a página
     * de erro do sistema, nunca o JSON cru — SPA do Inertia volta com o aviso.
     */
    public function render(Request $request): SymfonyResponse
    {
        if ($request->hasHeader('X-Inertia')) {
            return redirect()->back()->with('error', $this->getMessage());
        }

        if ($request->expectsJson() || $request->ajax() || $request->is('api/*')) {
            return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
        }

        if ($request->is('panel/*')) {
            Inertia::setRootView('panel-app');
        }

        $response = Inertia::render('Error', ['status' => $this->status, 'message' => $this->getMessage()])
            ->toResponse($request)
            ->setStatusCode($this->status);

        // Já é a página de erro (com o motivo): o respond() de bootstrap/app.php não a refaz.
        $response->headers->set('X-Error-Page', '1');

        return $response;
    }
}
