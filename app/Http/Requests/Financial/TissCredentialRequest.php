<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Domains\Tiss\Exceptions\TissEndpointNotAllowedException;
use App\Domains\Tiss\Models\TissEntityOperatorCredential;
use App\Domains\Tiss\Services\Transport\TissEndpointGuard;
use App\Http\Requests\BaseRequest;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\ExcludeIf;

/**
 * Credencial TISS (Financeiro > Credenciais TISS). Operadora e ambiente só no cadastro: depois são a identidade da
 * credencial. Segredos são opcionais (vazio = manter o salvo). Quem pode é decidido no controller (gate).
 */
class TissCredentialRequest extends BaseRequest
{
    public function rules(): array
    {
        $creating = ! $this->route('credential') instanceof TissEntityOperatorCredential;

        return [
            'covenant_id'         => $creating ? ['required', 'uuid'] : ['exclude'],
            'environment'         => $creating ? ['required', Rule::in(TissEntityOperatorCredential::ENVIRONMENTS)] : ['exclude'],
            'endpoint_submission' => $this->endpointRules(),
            'endpoint_status'     => $this->endpointRules(),
            'username'            => ['nullable', 'string', 'max:255'],
            // "Apagar" marcado: o campo (desabilitado na tela) nem é validado; o salvo sai.
            'password'             => [$this->excludeWhen('remove_password'), 'nullable', 'string', 'max:1000'],
            'token'                => [$this->excludeWhen('remove_token'), 'nullable', 'string', 'max:4000'],
            'certificate'          => [$this->excludeWhen('remove_certificate'), 'nullable', 'file', 'max:64', 'extensions:pfx,p12,pem,crt,cer'],
            'private_key'          => [$this->excludeWhen('remove_certificate'), 'nullable', 'file', 'max:64', 'extensions:pem,key'],
            'certificate_password' => [$this->excludeWhen('remove_certificate'), 'nullable', 'string', 'max:255'],
            'remove_password'      => ['boolean'],
            'remove_token'         => ['boolean'],
            'remove_certificate'   => ['boolean'],
            'ssl_enabled'          => ['boolean'],
            'active'               => ['boolean'],
        ];
    }

    private function excludeWhen(string $flag): ExcludeIf
    {
        return Rule::excludeIf(fn (): bool => $this->boolean($flag));
    }

    /** @return list<mixed> */
    private function endpointRules(): array
    {
        $production = $this->credentialEnvironment() === TissEntityOperatorCredential::ENV_PRODUCTION;

        return [
            'bail',
            'nullable',
            'string',
            'max:255',
            $production ? 'url:https' : 'url:http,https',
            function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    TissEndpointGuard::check((string) $value);
                } catch (TissEndpointNotAllowedException $e) {
                    // DNS fora do ar não impede salvar: o envio e o teste conferem de novo na hora.
                    if ($e->reason !== 'unresolvable') {
                        $fail($e->getMessage());
                    }
                }
            },
        ];
    }

    private function credentialEnvironment(): ?string
    {
        $credential = $this->route('credential');

        return $credential instanceof TissEntityOperatorCredential ? $credential->environment : $this->input('environment');
    }

    public function messages(): array
    {
        return [
            'endpoint_submission.url' => $this->urlMessage(),
            'endpoint_status.url'     => $this->urlMessage(),
        ];
    }

    private function urlMessage(): string
    {
        return $this->credentialEnvironment() === TissEntityOperatorCredential::ENV_PRODUCTION
            ? (string) __('tiss_credentials.errors.https_required')
            : (string) __('tiss_credentials.errors.endpoint_invalid');
    }

    public function attributes(): array
    {
        return [
            'covenant_id'          => (string) __('tiss_credentials.form.covenant'),
            'environment'          => (string) __('tiss_credentials.form.environment'),
            'endpoint_submission'  => (string) __('tiss_credentials.form.endpoint_submission'),
            'endpoint_status'      => (string) __('tiss_credentials.form.endpoint_status'),
            'username'             => (string) __('tiss_credentials.form.username'),
            'password'             => (string) __('tiss_credentials.form.password'),
            'token'                => (string) __('tiss_credentials.form.token'),
            'certificate'          => (string) __('tiss_credentials.form.certificate'),
            'private_key'          => (string) __('tiss_credentials.form.private_key'),
            'certificate_password' => (string) __('tiss_credentials.form.certificate_password'),
        ];
    }
}
