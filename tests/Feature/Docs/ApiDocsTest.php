<?php

use App\Http\Middleware\EnsureApiDocsAccess;

const DOCS_TEST_PASSWORD = 'senha-de-teste-docs';

beforeEach(function () {
    config(['docs.api.password' => DOCS_TEST_PASSWORD]);
});

describe('GET /docs/api (Swagger UI protegido)', function () {
    it('redirects to the password form when not authorized', function () {
        $this->get('/docs/api')
            ->assertRedirect(route('docs.api.auth'));
    });

    it('returns 404 everywhere when no password is configured', function () {
        config(['docs.api.password' => null]);

        $this->get('/docs/api')->assertNotFound();
        $this->get('/docs/api/auth')->assertNotFound();
        $this->post('/docs/api/auth', ['password' => 'x'])->assertNotFound();
        $this->get('/docs/api/spec')->assertNotFound();
    });

    it('shows the swagger page when the session is authorized', function () {
        $this->withSession([EnsureApiDocsAccess::SESSION_KEY => true])
            ->get('/docs/api')
            ->assertOk()
            ->assertSee('swagger-ui', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    });
});

describe('POST /docs/api/auth (senha)', function () {
    it('rejects a wrong password', function () {
        $this->from(route('docs.api.auth'))
            ->post('/docs/api/auth', ['password' => 'errada'])
            ->assertRedirect(route('docs.api.auth'))
            ->assertSessionHasErrors('password');

        $this->get('/docs/api')->assertRedirect(route('docs.api.auth'));
    });

    it('grants access with the correct password', function () {
        $this->post('/docs/api/auth', ['password' => DOCS_TEST_PASSWORD])
            ->assertRedirect(route('docs.api.index'));

        $this->get('/docs/api')->assertOk();
        $this->get('/docs/api/spec')
            ->assertOk()
            ->assertJsonPath('openapi', '3.0.3');
    });

    it('requires the password field', function () {
        $this->post('/docs/api/auth', [])
            ->assertSessionHasErrors('password');
    });
});

describe('GET /docs/api/spec — servers por ambiente', function () {
    beforeEach(function () {
        $this->withSession([EnsureApiDocsAccess::SESSION_KEY => true]);
    });

    it('does not list the dev server outside the local environment', function () {
        // phpunit roda com APP_ENV=testing — mesmo env usado em homologação
        $servers = $this->get('/docs/api/spec')->assertOk()->json('servers');

        expect(collect($servers)->pluck('description')->all())
            ->toBe(['Homologação', 'Produção']);

        expect(collect($servers)->pluck('url')->all())
            ->each->not->toContain('localhost');
    });

    it('lists Homologação then Produção in production, never the dev server', function () {
        $this->app['env'] = 'production';

        $servers = $this->get('/docs/api/spec')->assertOk()->json('servers');

        expect(collect($servers)->pluck('description')->all())
            ->toBe(['Produção', 'Homologação']);
    });

    it('lists the local dev server first in the local environment', function () {
        $this->app['env'] = 'local';

        $servers = $this->get('/docs/api/spec')->assertOk()->json('servers');

        expect($servers[0]['description'])->toBe('Desenvolvimento (local)')
            ->and($servers[0]['url'])->toBe(rtrim(config('app.url'), '/'))
            ->and(collect($servers)->pluck('description')->all())
            ->toBe(['Desenvolvimento (local)', 'Homologação', 'Produção']);
    });
});

describe('POST /docs/api/logout', function () {
    it('revokes the session authorization', function () {
        $this->withSession([EnsureApiDocsAccess::SESSION_KEY => true])
            ->post('/docs/api/logout')
            ->assertRedirect(route('docs.api.auth'));

        $this->get('/docs/api')->assertRedirect(route('docs.api.auth'));
    });
});
