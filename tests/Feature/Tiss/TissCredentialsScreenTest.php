<?php

declare(strict_types=1);

/**
 * Financeiro > Credenciais TISS: só o administrador da clínica, isolado por clínica, homologação e produção
 * separadas, segredos cifrados que nunca voltam (nem para a tela nem para a auditoria), certificado lido com a
 * senha antes de gravar e guardado no disco privado, teste de conexão auditado e sem segredo, trava de destino
 * (rede interna) e o comando de manutenção.
 */

use App\Domains\Tiss\Actions\ResolveTissOperatorForCovenantAction;
use App\Domains\Tiss\Exceptions\TissEndpointNotAllowedException;
use App\Domains\Tiss\Models\{TissEntityOperatorCredential, TissOperator};
use App\Domains\Tiss\Services\Credentials\TissCredentialConnectionTester;
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, TissEndpointGuard, TissHttpClientFactory};
use App\Enums\{ClientRule, EntityGate};
use App\Models\{AuditLog, Covenant, Entity, User};
use App\Models\EntityUser;
use Illuminate\Http\Client\{ConnectionException, Request as HttpRequest};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\{Arr, Carbon};
use Illuminate\Support\Facades\{Crypt, Gate, Http, Storage};
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: Entity, 1: EntityUser} */
function tcsClinic(string $rule = 'admin'): array
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    return [$entity, createEntityUser($entity, User::factory()->create(), $rule)];
}

function tcsCovenant(Entity $entity, string $ans = '326305', string $name = 'UNIMED TESTE'): Covenant
{
    return Covenant::factory()->create(['entity_id' => $entity->id, 'active' => true, 'name' => $name, 'ans_registry' => $ans]);
}

/** Certificado autoassinado de teste: PKCS#12 e PEM (chave cifrada com a mesma senha). */
function tcsCertificate(string $password = 'senha-do-certificado', string $cn = 'CLINICA TESTE LTDA'): array
{
    $key  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $x509 = openssl_csr_sign(openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']), null, $key, 365, ['digest_alg' => 'sha256']);

    openssl_x509_export($x509, $certPem);
    openssl_pkey_export($key, $keyPem, $password);
    openssl_pkcs12_export($x509, $p12, $key, $password);

    return ['p12' => $p12, 'cert' => $certPem, 'key' => $keyPem];
}

beforeEach(function (): void {
    Storage::fake(TissHttpClientFactory::DISK);
    Http::preventStrayRequests();

    [$this->clinic, $this->admin] = tcsClinic();
    $this->covenant               = tcsCovenant($this->clinic);
    $this->as                     = fn ($eu = null) => $this->actingAs(($eu ?? $this->admin)->user)->withSession(panelSession($eu ?? $this->admin));
    $this->store                  = fn (array $data = []) => ($this->as)()->post(route('panel.setting.tiss-credentials.store'), [
        'covenant_id'         => $this->covenant->id,
        'environment'         => 'sandbox',
        'endpoint_submission' => 'https://homolog.operadora.test/tiss',
        'ssl_enabled'         => true,
        'active'              => true,
        ...$data,
    ]);
});

describe('acesso', function (): void {
    it('administrador da clínica abre a tela; financeiro, médico e secretária não', function (string $rule, int $status): void {
        [, $eu] = $rule === 'admin' ? [null, $this->admin] : [null, createEntityUser($this->clinic, User::factory()->create(), $rule)];

        ($this->as)($eu)->get(route('panel.setting.tiss-credentials.index'))->assertStatus($status);
        ($this->as)($eu)->post(route('panel.setting.tiss-credentials.store'), ['covenant_id' => $this->covenant->id, 'environment' => 'sandbox'])
            ->assertStatus($status === 200 ? 302 : $status);
    })->with([
        'admin'      => [ClientRule::Admin->value, 200],
        'financeiro' => [ClientRule::Financial->value, 403],
        'médico'     => [ClientRule::Doctor->value, 403],
        'secretária' => [ClientRule::Secretary->value, 403],
    ]);

    it('o gate não vale para a empresa do SaaS, só para clínica', function (): void {
        $saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $user = User::factory()->create();
        createEntityUser($saas, $user, ClientRule::Admin->value);

        expect(Gate::forUser($user)->allows(EntityGate::ManageTissCredentials->value, $saas))->toBeFalse()
            ->and(Gate::forUser($this->admin->user)->allows(EntityGate::ManageTissCredentials->value, $this->clinic))->toBeTrue();
    });
});

describe('cadastro', function (): void {
    it('cifra senha e token, cria a operadora pelo ANS e nunca devolve segredo para a tela nem para a auditoria', function (): void {
        ($this->store)(['username' => 'prestador01', 'password' => 'S3nha-Secreta!', 'token' => 'tok-ABC-123'])
            ->assertRedirect()
            ->assertSessionHas('success', __('tiss_credentials.flash.created'));

        $credential = TissEntityOperatorCredential::query()->forEntity($this->clinic->id)->sole();

        expect($credential->operator->ans_code)->toBe('326305')
            ->and($credential->environment)->toBe('sandbox')
            ->and($credential->password_encrypted)->not->toContain('S3nha')
            ->and(Crypt::decryptString($credential->password_encrypted))->toBe('S3nha-Secreta!')
            ->and(Crypt::decryptString($credential->token_encrypted))->toBe('tok-ABC-123');

        $page = ($this->as)()->get(route('panel.setting.tiss-credentials.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Panel/Settings/TissCredentials/Index')
                ->where('credentials.0.has_password', true)
                ->where('credentials.0.has_token', true)
                ->where('credentials.0.missing', [])
                ->where('transport.simulated', true));

        $props = json_encode($page->viewData('page')['props']);
        $audit = AuditLog::query()->where('auditable_id', $credential->id)->get()->toJson();

        // Não vazio: o usuário (login, não segredo) e o evento estão lá.
        expect($props)->toContain('prestador01')
            ->and($audit)->toContain('tiss.credential.created');

        foreach (['S3nha-Secreta!', 'tok-ABC-123', $credential->password_encrypted, $credential->token_encrypted] as $secret) {
            expect($props)->not->toContain($secret)
                ->and($audit)->not->toContain($secret);
        }

        expect(AuditLog::query()->where('event', 'tiss.credential.created')->where('auditable_id', $credential->id)->exists())->toBeTrue();
    });

    it('homologação e produção são credenciais separadas; repetir a mesma operadora no mesmo ambiente é recusado', function (): void {
        ($this->store)()->assertSessionHasNoErrors();
        ($this->store)(['environment' => 'production', 'endpoint_submission' => 'https://ws.operadora.test/tiss'])->assertSessionHasNoErrors();
        ($this->store)()->assertSessionHasErrors(['covenant_id' => __('tiss_credentials.errors.duplicate')]);

        expect(TissEntityOperatorCredential::query()->forEntity($this->clinic->id)->pluck('environment')->sort()->values()->all())
            ->toBe(['production', 'sandbox']);
    });

    it('produção exige https; endereço com usuário e senha na URL é recusado', function (): void {
        ($this->store)(['environment' => 'production', 'endpoint_submission' => 'http://ws.operadora.test/tiss'])
            ->assertSessionHasErrors(['endpoint_submission' => __('tiss_credentials.errors.https_required')]);
        ($this->store)(['endpoint_submission' => 'https://login:senha@homolog.operadora.test/tiss'])
            ->assertSessionHasErrors(['endpoint_submission' => __('tiss_credentials.errors.endpoint_invalid')]);

        expect(TissEntityOperatorCredential::query()->count())->toBe(0);
    });

    it('aproveita a credencial provisória do 1º faturamento em vez de duplicar, e ela deixa de ser provisória', function (): void {
        app(ResolveTissOperatorForCovenantAction::class)($this->covenant, (string) $this->clinic->id);
        $placeholder = TissEntityOperatorCredential::query()->forEntity($this->clinic->id)->sole();

        expect($placeholder->isPlaceholder())->toBeTrue()
            ->and($placeholder->missingForSubmission())->toContain('review');

        ($this->store)(['environment' => $placeholder->environment, 'token' => 'tok'])->assertSessionHasNoErrors();

        $placeholder->refresh();

        expect(TissEntityOperatorCredential::query()->forEntity($this->clinic->id)->count())->toBe(1)
            ->and($placeholder->isPlaceholder())->toBeFalse()
            ->and($placeholder->metadata)->not->toHaveKey('provisioned_by')
            ->and($placeholder->missingForSubmission())->toBe([]);
    });

    it('convênio sem registro ANS ou de outra clínica não vira credencial', function (): void {
        $particular      = tcsCovenant($this->clinic, '', 'PARTICULAR DA CLINICA');
        [$other]         = tcsClinic();
        $otherClinicPlan = tcsCovenant($other, '999999', 'DE OUTRA CLINICA');

        ($this->store)(['covenant_id' => $particular->id])->assertSessionHasErrors(['covenant_id' => __('tiss_credentials.errors.covenant_without_ans')]);
        ($this->store)(['covenant_id' => $otherClinicPlan->id])->assertSessionHasErrors(['covenant_id' => __('tiss_credentials.errors.covenant_not_found')]);

        expect(TissEntityOperatorCredential::query()->count())->toBe(0);
    });

    it('a busca de operadoras lista só convênios com ANS da clínica e os globais', function (): void {
        tcsCovenant($this->clinic, '', 'SEM ANS');
        [$other] = tcsClinic();
        tcsCovenant($other, '111111', 'UNIMED DE OUTRA');
        Covenant::factory()->create(['entity_id' => null, 'active' => true, 'name' => 'UNIMED GLOBAL', 'ans_registry' => '222222']);

        $labels = ($this->as)()->getJson(route('panel.setting.tiss-credentials.operators', ['q' => 'unimed']))
            ->assertOk()
            ->json('data.*.label');

        expect($labels)->toBe(['UNIMED TESTE', 'UNIMED GLOBAL']);
    });
});

describe('edição e remoção', function (): void {
    beforeEach(function (): void {
        ($this->store)(['username' => 'prestador01', 'password' => 'senha-1', 'token' => 'tok-1']);
        $this->credential = TissEntityOperatorCredential::query()->forEntity($this->clinic->id)->sole();
        $this->update     = fn (array $data, ?TissEntityOperatorCredential $c = null) => ($this->as)()->post(
            route('panel.setting.tiss-credentials.update', $c ?? $this->credential),
            ['endpoint_submission' => 'https://homolog.operadora.test/tiss', 'username' => 'prestador01', 'ssl_enabled' => true, 'active' => true, ...$data],
        );
    });

    it('campo de segredo vazio mantém o salvo; "apagar" remove; ambiente não muda na edição', function (): void {
        ($this->update)(['environment' => 'production'])->assertSessionHasNoErrors();
        $this->credential->refresh();

        expect(Crypt::decryptString($this->credential->password_encrypted))->toBe('senha-1')
            ->and(Crypt::decryptString($this->credential->token_encrypted))->toBe('tok-1')
            ->and($this->credential->environment)->toBe('sandbox');

        ($this->update)(['password' => 'senha-2', 'remove_token' => true])->assertSessionHasNoErrors();
        $this->credential->refresh();

        expect(Crypt::decryptString($this->credential->password_encrypted))->toBe('senha-2')
            ->and($this->credential->token_encrypted)->toBeNull()
            ->and(AuditLog::query()->where('event', 'tiss.credential.updated')->where('auditable_id', $this->credential->id)->count())->toBe(2);
    });

    it('"apagar" vence o que foi digitado no mesmo envio (o campo fica desabilitado na tela)', function (): void {
        ($this->update)(['password' => 'digitada-depois', 'remove_password' => true, 'token' => 'outro', 'remove_token' => true])
            ->assertSessionHasNoErrors();

        expect($this->credential->fresh()->password_encrypted)->toBeNull()
            ->and($this->credential->fresh()->token_encrypted)->toBeNull();

        // Arquivo inválido com "apagar o certificado": nem é validado, e nada é gravado.
        ($this->update)([
            'remove_certificate' => true,
            'certificate'        => UploadedFile::fake()->createWithContent('lixo.exe', 'x'),
        ])->assertSessionHasNoErrors();

        expect($this->credential->fresh()->certificate_path)->toBeNull()
            ->and(Storage::disk(TissHttpClientFactory::DISK)->allFiles())->toBe([]);
    });

    it('trocar o endereço exige digitar de novo a senha e o token salvos (senão o teste os mandaria ao endereço novo)', function (): void {
        $other = ['endpoint_submission' => 'https://servidor-de-alguem.test/tiss'];

        ($this->update)($other)->assertSessionHasErrors([
            'password' => __('tiss_credentials.errors.secret_reentry'),
            'token'    => __('tiss_credentials.errors.secret_reentry'),
        ]);
        // O endereço de teste também recebe a autenticação.
        ($this->update)(['endpoint_status' => 'https://outro-teste.test/'])->assertSessionHasErrors(['password', 'token']);

        expect($this->credential->fresh()->endpoint_submission)->toBe('https://homolog.operadora.test/tiss');

        ($this->update)([...$other, 'password' => 'senha-confirmada', 'remove_token' => true])->assertSessionHasNoErrors();

        expect($this->credential->fresh()->endpoint_submission)->toBe('https://servidor-de-alguem.test/tiss')
            ->and(Crypt::decryptString($this->credential->fresh()->password_encrypted))->toBe('senha-confirmada')
            ->and($this->credential->fresh()->token_encrypted)->toBeNull();
    });

    it('outra clínica não vê, não altera, não testa nem remove a credencial', function (): void {
        [, $otherAdmin] = tcsClinic();

        ($this->as)($otherAdmin)->get(route('panel.setting.tiss-credentials.index'))
            ->assertInertia(fn (Assert $p) => $p->has('credentials', 0));
        ($this->as)($otherAdmin)->post(route('panel.setting.tiss-credentials.update', $this->credential), ['password' => 'invasor'])->assertNotFound();
        ($this->as)($otherAdmin)->post(route('panel.setting.tiss-credentials.test', $this->credential))->assertNotFound();
        ($this->as)($otherAdmin)->delete(route('panel.setting.tiss-credentials.destroy', $this->credential))->assertNotFound();

        expect(Crypt::decryptString($this->credential->fresh()->password_encrypted))->toBe('senha-1');
    });

    it('remover apaga a linha (libera o ambiente para um novo cadastro) e audita', function (): void {
        ($this->as)()->delete(route('panel.setting.tiss-credentials.destroy', $this->credential))
            ->assertSessionHas('success', __('tiss_credentials.flash.deleted'));

        expect(TissEntityOperatorCredential::withTrashed()->whereKey($this->credential->id)->exists())->toBeFalse()
            ->and(AuditLog::query()->where('event', 'tiss.credential.deleted')->where('auditable_id', $this->credential->id)->exists())->toBeTrue();

        ($this->store)()->assertSessionHasNoErrors();
    });
});

describe('certificado', function (): void {
    it('PKCS#12 com a senha certa vai para o disco privado; titular e validade ficam registrados', function (): void {
        $cert = tcsCertificate();

        ($this->store)([
            'certificate'          => UploadedFile::fake()->createWithContent('clinica.pfx', $cert['p12']),
            'certificate_password' => 'senha-do-certificado',
        ])->assertSessionHasNoErrors();

        $credential = TissEntityOperatorCredential::query()->forEntity($this->clinic->id)->sole();
        $disk       = Storage::disk(TissHttpClientFactory::DISK);

        expect($credential->certificate_path)->toStartWith($this->clinic->id . '/' . $credential->id . '/')
            ->and($disk->get($credential->certificate_path))->toBe($cert['p12'])
            ->and(Crypt::decryptString($credential->certificate_password_encrypted))->toBe('senha-do-certificado')
            ->and($credential->metadata['certificate']['subject'])->toBe('CLINICA TESTE LTDA')
            // Validade de 365 dias; ±1 por fuso (o certificado conta em UTC).
            ->and(abs(Carbon::parse($credential->metadata['certificate']['valid_to'])->diffInDays(now()->addDays(365)->startOfDay())))->toBeLessThanOrEqual(1.0)
            ->and($credential->hasAuthentication())->toBeTrue();

        // O envio usa o arquivo do disco privado com a senha (mTLS), no formato PKCS#12.
        $options = TissHttpClientFactory::tlsOptions($credential);

        // Gravado como .p12: o Guzzle usa o tipo P12 pela extensão.
        expect($options['cert'])->toBe([$disk->path($credential->certificate_path), 'senha-do-certificado'])
            ->and($credential->certificate_path)->toEndWith('.p12')
            ->and($options)->not->toHaveKey('curl');
    });

    it('senha do certificado com espaço nas pontas vale como foi digitada', function (): void {
        $cert = tcsCertificate(' com espaço ');

        ($this->store)([
            'certificate'          => UploadedFile::fake()->createWithContent('clinica.pfx', $cert['p12']),
            'certificate_password' => ' com espaço ',
        ])->assertSessionHasNoErrors();

        expect(Crypt::decryptString(TissEntityOperatorCredential::query()->sole()->certificate_password_encrypted))->toBe(' com espaço ');
    });

    it('senha errada ou arquivo que não é certificado: erro no campo e nada gravado', function (): void {
        $cert = tcsCertificate();

        ($this->store)([
            'certificate'          => UploadedFile::fake()->createWithContent('clinica.p12', $cert['p12']),
            'certificate_password' => 'senha-errada',
        ])->assertSessionHasErrors(['certificate' => __('tiss_credentials.errors.certificate_unreadable')]);
        ($this->store)(['certificate' => UploadedFile::fake()->createWithContent('clinica.pem', 'nao sou certificado')])
            ->assertSessionHasErrors('certificate');

        expect(TissEntityOperatorCredential::query()->count())->toBe(0)
            ->and(Storage::disk(TissHttpClientFactory::DISK)->allFiles())->toBe([]);
    });

    it('.pfx exportado sem a chave ou em formato antigo (RC2): mensagem própria, nada gravado', function (string $fixture, string $error): void {
        ($this->store)([
            'certificate'          => UploadedFile::fake()->createWithContent('clinica.p12', file_get_contents(base_path('tests/Fixtures/tiss-certificates/' . $fixture))),
            'certificate_password' => 'teste123',
        ])->assertSessionHasErrors(['certificate' => __('tiss_credentials.errors.' . $error)]);

        expect(TissEntityOperatorCredential::query()->count())->toBe(0);
    })->with([
        'sem a chave privada'     => ['sem-chave.p12', 'certificate_without_key'],
        'criptografia RC2 antiga' => ['legado-rc2.p12', 'certificate_legacy'],
    ]);

    it('erro de validação não guarda a senha do certificado na sessão', function (): void {
        ($this->store)([
            'endpoint_submission'  => 'ftp://errado.test',
            'certificate_password' => ' senha com espaço ',
        ])->assertSessionHasErrors('endpoint_submission');

        expect(session()->getOldInput('endpoint_submission'))->toBe('ftp://errado.test')
            ->and(session()->getOldInput('certificate_password'))->toBeNull();
    });

    it('PEM com a chave à parte: a chave precisa abrir com a senha e combinar com o certificado', function (): void {
        $cert  = tcsCertificate();
        $other = tcsCertificate();

        ($this->store)([
            'certificate'          => UploadedFile::fake()->createWithContent('clinica.pem', $cert['cert']),
            'private_key'          => UploadedFile::fake()->createWithContent('chave.key', $other['key']),
            'certificate_password' => 'senha-do-certificado',
        ])->assertSessionHasErrors(['private_key' => __('tiss_credentials.errors.private_key_mismatch')]);

        ($this->store)([
            'certificate'          => UploadedFile::fake()->createWithContent('clinica.pem', $cert['cert']),
            'private_key'          => UploadedFile::fake()->createWithContent('chave.key', $cert['key']),
            'certificate_password' => 'senha-do-certificado',
        ])->assertSessionHasNoErrors();

        $credential = TissEntityOperatorCredential::query()->forEntity($this->clinic->id)->sole();

        expect(Storage::disk(TissHttpClientFactory::DISK)->allFiles())->toHaveCount(2)
            ->and(TissHttpClientFactory::tlsOptions($credential))->toHaveKey('ssl_key');
    });

    it('apagar o certificado (ou a credencial) remove os arquivos do disco', function (): void {
        $cert = tcsCertificate();
        ($this->store)([
            'certificate'          => UploadedFile::fake()->createWithContent('clinica.pfx', $cert['p12']),
            'certificate_password' => 'senha-do-certificado',
        ]);
        $credential = TissEntityOperatorCredential::query()->forEntity($this->clinic->id)->sole();

        ($this->as)()->post(route('panel.setting.tiss-credentials.update', $credential), [
            'endpoint_submission' => 'https://homolog.operadora.test/tiss',
            'remove_certificate'  => true,
        ])->assertSessionHasNoErrors();

        expect($credential->fresh()->certificate_path)->toBeNull()
            ->and($credential->fresh()->certificate_password_encrypted)->toBeNull()
            ->and(Storage::disk(TissHttpClientFactory::DISK)->allFiles())->toBe([]);
    });
});

describe('teste de conexão', function (): void {
    beforeEach(function (): void {
        ($this->store)(['username' => 'prestador01', 'password' => 'S3nha-Secreta!']);
        $this->credential = TissEntityOperatorCredential::query()->forEntity($this->clinic->id)->sole();
    });

    it('usa a mesma autenticação do envio, guarda só códigos e audita sem segredo', function (int $status, string $result, string $flash): void {
        Http::fake(['homolog.operadora.test/*' => Http::response('<html>corpo que não deve ser guardado</html>', $status)]);

        ($this->as)()->post(route('panel.setting.tiss-credentials.test', $this->credential))->assertSessionHas($flash);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET'
            && $r->url() === 'https://homolog.operadora.test/tiss'
            && $r->hasHeader('Authorization', 'Basic ' . base64_encode('prestador01:S3nha-Secreta!')));

        $test  = $this->credential->fresh()->metadata['connection_test'];
        $audit = AuditLog::query()->where('event', 'tiss.credential.connection_tested')->sole();

        expect($test['result'])->toBe($result)
            ->and($test['status'])->toBe($status)
            ->and(json_encode($test))->not->toContain('corpo')
            ->and($audit->toJson())->not->toContain('S3nha')
            ->and($audit->toJson())->not->toContain('corpo');
    })->with([
        'ok'                    => [200, 'reachable', 'success'],
        'SOAP sem GET'          => [405, 'responded', 'success'],
        'autenticação recusada' => [401, 'auth_rejected', 'error'],
        'erro do servidor'      => [503, 'server_error', 'error'],
    ]);

    it('sem conexão: motivo em código fixo, sem a mensagem bruta do cURL', function (): void {
        Http::fake(['homolog.operadora.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 ms for https://homolog.operadora.test/tiss')]);

        ($this->as)()->post(route('panel.setting.tiss-credentials.test', $this->credential))
            ->assertSessionHas('error', __('tiss_credentials.test.results.unreachable', ['reason' => __('tiss_credentials.test.reasons.timeout')]));

        expect($this->credential->fresh()->metadata['connection_test'])->toMatchArray(['result' => 'unreachable', 'reason' => 'timeout']);
    });

    it('não segue redirecionamento (levaria a autenticação a outro endereço) e avisa que o envio também não segue', function (): void {
        Http::fake(['homolog.operadora.test/*' => Http::response('', 302, ['Location' => 'https://outro.test/'])]);

        ($this->as)()->post(route('panel.setting.tiss-credentials.test', $this->credential))
            ->assertSessionHas('error', __('tiss_credentials.test.results.redirect', ['status' => 302]));

        Http::assertSentCount(1);
        expect($this->credential->fresh()->metadata['connection_test'])->toMatchArray(['result' => 'redirect', 'status' => 302]);
    });

    it('certificado salvo que sumiu do disco: teste falha sem conexão e sem expor o caminho', function (): void {
        $this->credential->forceFill(['certificate_path' => $this->clinic->id . '/' . $this->credential->id . '/sumiu.p12'])->save();

        ($this->as)()->post(route('panel.setting.tiss-credentials.test', $this->credential))
            ->assertSessionHas('error', __('tiss_credentials.test.results.failed'));

        Http::assertNothingSent();
    });
});

describe('trava de destino (SSRF)', function (): void {
    it('em produção recusa rede interna e aceita IP público; fora de produção libera a rede local', function (string $url, ?string $reason): void {
        app()->detectEnvironment(fn () => 'production');

        if ($reason === null) {
            expect(TissEndpointGuard::check($url))->not->toBe([]);

            return;
        }

        try {
            TissEndpointGuard::check($url);
            $this->fail('esperava TissEndpointNotAllowedException');
        } catch (TissEndpointNotAllowedException $e) {
            expect($e->reason)->toBe($reason);
        }
    })->with([
        'loopback'        => ['https://127.0.0.1/tiss', 'private'],
        'localhost'       => ['https://localhost/tiss', 'private'],
        'rede privada'    => ['https://10.0.0.5/tiss', 'private'],
        'metadados nuvem' => ['http://169.254.169.254/latest', 'private'],
        'CGNAT'           => ['https://100.64.0.1/tiss', 'private'],
        'IPv6 loopback'   => ['https://[::1]/tiss', 'private'],
        'sem http'        => ['ftp://8.8.8.8/tiss', 'invalid'],
        'usuário na URL'  => ['https://user:pw@8.8.8.8/tiss', 'invalid'],
        'IP público'      => ['https://8.8.8.8/tiss', null],
    ]);

    it('vale também fora de produção (o servidor de teste é público); a rede interna só abre com a liberação explícita', function (): void {
        config(['tiss.transport.http.allow_private_endpoints' => false]);

        expect(fn () => TissEndpointGuard::check('http://127.0.0.1:8099/tiss'))->toThrow(TissEndpointNotAllowedException::class);

        config(['tiss.transport.http.allow_private_endpoints' => true]);

        expect(TissEndpointGuard::check('http://127.0.0.1:8099/tiss'))->toBe([]);

        // Em produção a liberação é ignorada.
        app()->detectEnvironment(fn () => 'production');

        expect(fn () => TissEndpointGuard::check('http://127.0.0.1:8099/tiss'))->toThrow(TissEndpointNotAllowedException::class);
    });

    it('o envio real recusado pela trava é "não enviado" (erro definitivo), nunca entrega em dúvida', function (): void {
        config(['tiss.transport.http.allow_private_endpoints' => false]);
        $operator   = TissOperator::create(['ans_code' => '555556', 'name' => 'Operadora Interna']);
        $credential = TissEntityOperatorCredential::create([
            'entity_id'           => $this->clinic->id,
            'operator_id'         => $operator->id,
            'environment'         => 'sandbox',
            'endpoint_submission' => 'https://10.0.0.5/tiss',
            'active'              => true,
        ]);

        $result = app(HttpTissTransportClient::class)->sendBatch($credential, '<ansTISS/>', 'chave-1');

        expect($result)->toMatchArray(['ok' => false, 'status_code' => null, 'error_code' => HttpTissTransportClient::NOT_SENT])
            ->and($result['error_message'])->toBe(__('tiss_credentials.errors.endpoint_private'));
        Http::assertNothingSent();
    });

    it('teste de conexão com destino recusado não faz requisição nenhuma', function (): void {
        app()->detectEnvironment(fn () => 'production');
        $operator   = TissOperator::create(['ans_code' => '555555', 'name' => 'Operadora Interna']);
        $credential = TissEntityOperatorCredential::create([
            'entity_id'           => $this->clinic->id,
            'operator_id'         => $operator->id,
            'environment'         => 'sandbox',
            'endpoint_submission' => 'https://10.0.0.5/tiss',
            'active'              => true,
        ]);

        $test = app(TissCredentialConnectionTester::class)->test($credential, request());

        expect($test)->toMatchArray(['result' => 'blocked', 'reason' => 'private']);
        Http::assertNothingSent();
    });
});

describe('comando de manutenção', function (): void {
    it('lista sem segredos e testa a conexão de uma credencial', function (): void {
        ($this->store)(['username' => 'prestador01', 'password' => 'S3nha-Secreta!']);
        $credential = TissEntityOperatorCredential::query()->forEntity($this->clinic->id)->sole();

        $this->artisan('tiss:credentials')
            ->expectsOutputToContain('UNIMED TESTE (326305)')
            ->doesntExpectOutputToContain('S3nha-Secreta!')
            ->doesntExpectOutputToContain('prestador01')
            ->assertSuccessful();

        Http::fake(['homolog.operadora.test/*' => Http::response('', 200)]);

        $this->artisan('tiss:credentials', ['--test' => $credential->id])->assertSuccessful();

        expect($credential->fresh()->metadata['connection_test']['result'])->toBe('reachable')
            ->and(AuditLog::query()->where('event', 'tiss.credential.connection_tested')->where('auditable_id', $credential->id)->exists())->toBeTrue();
    });
});

it('lang/{pt_BR,en}/tiss_credentials.php têm as mesmas chaves e nenhum valor vazio', function (): void {
    $pt = Arr::dot(require lang_path('pt_BR/tiss_credentials.php'));
    $en = Arr::dot(require lang_path('en/tiss_credentials.php'));

    expect(array_keys($pt))->toEqualCanonicalizing(array_keys($en))
        ->and(array_filter($pt, fn ($v) => trim((string) $v) === ''))->toBe([])
        ->and(array_filter($en, fn ($v) => trim((string) $v) === ''))->toBe([]);
});
