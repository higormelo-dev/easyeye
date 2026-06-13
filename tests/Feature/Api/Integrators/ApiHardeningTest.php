<?php

use App\Enums\{DataAccessPurpose, FeatureKey};
use App\Models\{DataAccessLog, ExamType, Patient, PatientExam};
use App\Services\FeatureGateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Emite um header Authorization para o integrador do contexto com abilities
 * específicas além do `integrator_id:<uuid>` padrão.
 */
function scopedHeaders(array $ctx, array $extraAbilities): array
{
    $token = $ctx['integratorUser']->createToken(
        'integrator-token',
        array_merge(['integrator_id:' . $ctx['integrator']->id], $extraAbilities),
        now()->addDays(7),
    );

    return ['Authorization' => 'Bearer ' . $token->plainTextToken];
}

/**
 * Payload válido de criação de exame.
 */
function examPayload(array $ctx, string $name): array
{
    return [
        'exam_identifier'     => $ctx['examType']->code,
        'schedule_identifier' => $ctx['schedule']->code,
        'archive'             => UploadedFile::fake()->image("{$name}.jpg"),
        'name'                => $name,
    ];
}

function writeCtx(): array
{
    Storage::fake('s3');

    $ctx = setupIntegrator([
        FeatureKey::HasApiIntegrator->value    => '1',
        FeatureKey::ApiMonthlyExamSends->value => '50',
    ]);

    $ctx['patient']  = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
    $ctx['examType'] = ExamType::factory()->create(['entity_id' => null]);
    $ctx['schedule'] = createScheduleForEntity($ctx['entity'])['schedule'];

    return $ctx;
}

// ---------------------------------------------------------------------------
// Rate limiting — buckets read/write por integrador
// ---------------------------------------------------------------------------
describe('rate limiting integrators-api', function () {
    it('expõe o teto de leitura (120/min) nas respostas GET', function () {
        $ctx = setupIntegrator();

        $this->getJson('/api/integrators/v1/examtypes', $ctx['headers'])
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 120);
    });

    it('usa um bucket de escrita separado (40/min) nas respostas de escrita', function () {
        $ctx = writeCtx();

        $this->postJson(
            "/api/integrators/v1/patients/{$ctx['patient']->id}/exams",
            examPayload($ctx, 'Bucket Write'),
            $ctx['headers'],
        )->assertCreated()->assertHeader('X-RateLimit-Limit', 40);
    });

    it('retorna 429 com Retry-After ao estourar o teto de leitura', function () {
        $ctx = setupIntegrator();

        // Consome os 120 permitidos.
        foreach (range(1, 120) as $i) {
            $this->getJson('/api/integrators/v1/examtypes', $ctx['headers'])->assertOk();
        }

        $this->getJson('/api/integrators/v1/examtypes', $ctx['headers'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    });
});

// ---------------------------------------------------------------------------
// Escopo de token por verbo (read/write)
// ---------------------------------------------------------------------------
describe('escopo de token', function () {
    it('token read-only consegue ler', function () {
        $ctx     = writeCtx();
        $headers = scopedHeaders($ctx, ['api:read']);

        $this->getJson("/api/integrators/v1/patients/{$ctx['patient']->id}/exams", $headers)
            ->assertOk();
    });

    it('token read-only é barrado (403) ao escrever', function () {
        $ctx     = writeCtx();
        $headers = scopedHeaders($ctx, ['api:read']);

        $this->postJson(
            "/api/integrators/v1/patients/{$ctx['patient']->id}/exams",
            examPayload($ctx, 'Negado'),
            $headers,
        )->assertForbidden();

        expect(PatientExam::where('name', 'Negado')->exists())->toBeFalse();
    });

    it('token com api:write consegue escrever', function () {
        $ctx     = writeCtx();
        $headers = scopedHeaders($ctx, ['api:read', 'api:write']);

        $this->postJson(
            "/api/integrators/v1/patients/{$ctx['patient']->id}/exams",
            examPayload($ctx, 'Permitido'),
            $headers,
        )->assertCreated();
    });

    it('token legado (sem api:*) mantém acesso total de escrita', function () {
        $ctx = writeCtx(); // setupIntegrator emite token legado, sem api:*

        $this->postJson(
            "/api/integrators/v1/patients/{$ctx['patient']->id}/exams",
            examPayload($ctx, 'Legado'),
            $ctx['headers'],
        )->assertCreated();
    });

    it('signin com scope=read emite token somente leitura', function () {
        $ctx = writeCtx();
        $ctx['integratorUser']->update(['password' => 'secret123']);

        $response = $this->postJson('/api/integrators/signin', [
            'email'    => $ctx['integratorUser']->email,
            'password' => 'secret123',
            'code'     => $ctx['integrator']->code,
            'scope'    => 'read',
        ])->assertOk();

        $plainToken  = $response->json('access_token');
        $accessToken = PersonalAccessToken::findToken($plainToken);

        expect($accessToken->abilities)->toContain('api:read')
            ->and($accessToken->abilities)->not->toContain('api:write');
    });

    it('signin sem scope emite token read+write', function () {
        $ctx = writeCtx();
        $ctx['integratorUser']->update(['password' => 'secret123']);

        $response = $this->postJson('/api/integrators/signin', [
            'email'    => $ctx['integratorUser']->email,
            'password' => 'secret123',
            'code'     => $ctx['integrator']->code,
        ])->assertOk();

        $accessToken = PersonalAccessToken::findToken($response->json('access_token'));

        expect($accessToken->abilities)->toContain('api:read')
            ->and($accessToken->abilities)->toContain('api:write');
    });
});

// ---------------------------------------------------------------------------
// Idempotência no upload
// ---------------------------------------------------------------------------
describe('idempotência', function () {
    it('re-tentativa com mesmo Idempotency-Key não duplica nem consome cota 2x', function () {
        $ctx = writeCtx();
        $url = "/api/integrators/v1/patients/{$ctx['patient']->id}/exams";
        $key = ['Idempotency-Key' => 'abc12345-retry-key'];

        $first = $this->postJson($url, examPayload($ctx, 'Idem'), $ctx['headers'] + $key)
            ->assertCreated();

        $second = $this->postJson($url, examPayload($ctx, 'Idem'), $ctx['headers'] + $key)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        // Mesma resposta memorizada e um único registro criado.
        expect($second->json('data.id'))->toBe($first->json('data.id'));
        expect(PatientExam::where('name', 'Idem')->count())->toBe(1);

        $used = app(FeatureGateService::class)
            ->status($ctx['entity']->id, FeatureKey::ApiMonthlyExamSends)->used;

        expect($used)->toBe(1);
    });

    it('rejeita Idempotency-Key com formato inválido (400)', function () {
        $ctx = writeCtx();

        $this->postJson(
            "/api/integrators/v1/patients/{$ctx['patient']->id}/exams",
            examPayload($ctx, 'BadKey'),
            $ctx['headers'] + ['Idempotency-Key' => 'short'],
        )->assertStatus(400);
    });

    it('sem header não aplica idempotência (não há replay)', function () {
        $ctx = writeCtx();

        $this->postJson(
            "/api/integrators/v1/patients/{$ctx['patient']->id}/exams",
            examPayload($ctx, 'NoIdem'),
            $ctx['headers'],
        )->assertCreated()->assertHeaderMissing('Idempotency-Replayed');
    });
});

// ---------------------------------------------------------------------------
// Log de acesso LGPD
// ---------------------------------------------------------------------------
describe('log de acesso LGPD', function () {
    it('registra acesso ao listar exames do paciente', function () {
        $ctx     = setupIntegrator();
        $patient = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
        PatientExam::factory(2)->create(['patient_id' => $patient->id]);

        $this->getJson("/api/integrators/v1/patients/{$patient->id}/exams", $ctx['headers'])
            ->assertOk();

        $log = DataAccessLog::query()->latest('accessed_at')->first();

        expect($log)->not->toBeNull()
            ->and($log->purpose)->toBe(DataAccessPurpose::ApiAccess)
            ->and($log->patient_id)->toBe($patient->id)
            ->and($log->entity_id)->toBe($ctx['entity']->id);
    });

    it('registra acesso ao consultar um paciente específico', function () {
        $ctx     = setupIntegrator();
        $patient = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);

        $this->getJson("/api/integrators/v1/patients/{$patient->id}", $ctx['headers'])
            ->assertOk();

        expect(DataAccessLog::where('patient_id', $patient->id)
            ->where('purpose', DataAccessPurpose::ApiAccess->value)
            ->exists())->toBeTrue();
    });
});
