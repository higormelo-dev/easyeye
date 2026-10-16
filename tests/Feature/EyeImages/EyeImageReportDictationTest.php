<?php

declare(strict_types=1);

use App\Domains\AI\Services\AiProviderSettings;
use App\Domains\AI\Support\WavInspector;
use App\Enums\ClientRule;
use App\Models\{Entity, User};
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\{Request, UploadedFile};
use Illuminate\Support\Facades\{DB, Http, Log, Schema};

/**
 * Ditado por voz do laudo (Fase 5): o áudio vai ao backend e é transcrito por
 * provedor da política LGPD — nunca pelo reconhecimento de voz do navegador.
 * SpeechTranscriptionService + EyeImageReportDictationController.
 */

/** WAV PCM 16 kHz mono 16 bits só de silêncio, com a duração pedida. */
function dictationWav(float $seconds, array $extraChunks = []): string
{
    $rate      = 16000;
    $dataBytes = (int) round($seconds * $rate * 2);
    $extra     = implode('', $extraChunks);

    return 'RIFF' . pack('V', 4 + 24 + strlen($extra) + 8 + $dataBytes) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
        . $extra
        . 'data' . pack('V', $dataBytes) . str_repeat("\0", $dataBytes);
}

/** O que o dono do SaaS deixa habilitado no Manager (fonte de verdade); a chave no .env não habilita nada. */
function enableDictationProvider(string $code): void
{
    app(AiProviderSettings::class)->setRoleAssignments(['primary' => $code, 'reviewer' => null, 'adjudicator' => null]);
}

/**
 * WAV com o `fmt ` escolhido a dedo (campos adulteráveis) e os frames reais pedidos.
 *
 * @param array{format?:int, channels?:int, rate?:int, byteRate?:int, blockAlign?:int, bits?:int} $fmt
 */
function forgedDictationWav(int $frames, array $fmt = [], ?int $declaredDataBytes = null, string $trailing = '', ?int $riffSize = null): string
{
    $fmt       = $fmt + ['format' => 1, 'channels' => 1, 'rate' => 16000, 'byteRate' => 32000, 'blockAlign' => 2, 'bits' => 16];
    $dataBytes = $frames * 2; // o conteúdo real é PCM 16 bits mono
    $declared  = $declaredDataBytes ?? $dataBytes;

    return 'RIFF' . pack('V', $riffSize ?? (4 + 24 + 8 + $dataBytes + strlen($trailing))) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, $fmt['format'], $fmt['channels'], $fmt['rate'], $fmt['byteRate'], $fmt['blockAlign'], $fmt['bits'])
        . 'data' . pack('V', $declared) . str_repeat("\0", $dataBytes) . $trailing;
}

function dictationUpload(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('ditado.wav', $content);
}

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->secretaryUser       = User::factory()->create();
    $this->secretaryEntityUser = createEntityUser($this->entity, $this->secretaryUser, ClientRule::Secretary->value);

    $this->financialUser       = User::factory()->create();
    $this->financialEntityUser = createEntityUser($this->entity, $this->financialUser, ClientRule::Financial->value);

    config([
        'ai.transcription.enabled'      => true,
        'ai.transcription.provider'     => 'mistral',
        'ai.transcription.model'        => 'voxtral-mini-latest',
        'ai.transcription.language'     => 'pt',
        'ai.transcription.per_minute'   => 100,
        'services.mistral.api_key'      => 'test-mistral-key',
        'ai.providers.mistral.base_url' => 'https://api.mistral.ai/v1',
    ]);

    enableDictationProvider('mistral');

    // A trilha durável exige que a requisição comece SEM transação (produção: nível 0).
    // O teste roda dentro da transação do RefreshDatabase; este é o nível "de base" dele.
    config(['audit.durable_transaction_baseline' => DB::transactionLevel()]);
});

// Rede de segurança do banco compartilhado (easyeye_test): nada aqui mexe no esquema
// (a falha da trilha é provocada com dado, não com DDL). Se um teste futuro renomear
// ou apagar audit_logs e falhar antes de desfazer, este afterEach acusa na hora em
// vez de deixar o schema quebrado para os outros agentes — roda também quando o teste falha.
afterEach(function () {
    expect(Schema::hasTable('audit_logs'))->toBeTrue()
        ->and(Schema::hasTable('audit_logs_indisponivel'))->toBeFalse();
});

function dictate($test, ?string $content = null, ?User $user = null)
{
    $user ??= $test->secretaryUser;
    $eu = $user->is($test->financialUser) ? $test->financialEntityUser : $test->secretaryEntityUser;

    return $test->actingAs($user)->withSession(panelSession($eu))
        ->post(route('panel.eye-images.reports.dictate'), ['audio' => dictationUpload($content ?? dictationWav(2.0))], ['Accept' => 'application/json']);
}

it('transcreve o ditado no provedor e devolve só o texto, sem guardar o áudio', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => "  Cristalino transparente.\n\n\n\nPIO normal.  "])]);

    dictate($this)->assertOk()->assertExactJson(['text' => "Cristalino transparente.\n\nPIO normal."]);

    Http::assertSent(function (HttpRequest $request) {
        $fields = collect($request->data())->mapWithKeys(fn ($f) => [$f['name'] => $f['contents']]);

        return $request->url() === 'https://api.mistral.ai/v1/audio/transcriptions'
            && $request->hasHeader('Authorization', 'Bearer test-mistral-key')
            && $fields['model'] === 'voxtral-mini-latest'
            && $fields['language'] === 'pt'
            && $fields->has('file');
    });
});

it('[LGPD] a auditoria registra que o áudio saiu (provedor, duração, tamanho, SHA-256) e nunca o áudio nem o texto', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => 'Texto sigiloso do achado.'])]);
    $wav = dictationWav(3.0);

    dictate($this, $wav)->assertOk();

    $row = DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->first();
    expect($row)->not->toBeNull();

    $values = json_decode((string) $row->new_values, true);
    expect($values)->toMatchArray([
        'provider'      => 'mistral',
        'model'         => 'voxtral-mini-latest',
        'audio_seconds' => 3.0,
        'audio_bytes'   => strlen($wav),
        'audio_sha256'  => hash('sha256', $wav),
    ])->and(json_encode($values))->not->toContain('sigiloso');
});

it('desligado de fábrica: 503 e nenhuma chamada ao provedor', function () {
    config(['ai.transcription.enabled' => false]);
    Http::fake();

    dictate($this)->assertStatus(503)->assertJsonPath('message', __('eye_images.dictation_unavailable'));

    Http::assertNothingSent();
});

it('[LGPD] só provedor que aceita dado de paciente e tem endpoint de transcrição entra', function (string $provider) {
    config(['ai.transcription.provider' => $provider]);
    Http::fake();

    dictate($this)->assertStatus(503);

    Http::assertNothingSent();
})->with([
    'Gemini (termos proíbem uso clínico)' => ['gemini'],
    'Anthropic (sem transcrição)'         => ['anthropic'],
    'Maritaca (só texto)'                 => ['maritaca'],
    'provedor inexistente'                => ['qualquer-coisa'],
]);

it('[LGPD] provedor que processa fora do Brasil sem adequação só entra com o mecanismo de transferência registrado', function () {
    config([
        'ai.transcription.provider'    => 'openai',
        'ai.transcription.model'       => 'whisper-1',
        'services.openai.api_key'      => 'test-openai-key',
        'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
    ]);
    Http::fake(['api.openai.com/*' => Http::response(['text' => 'Laudo ditado.'])]);
    enableDictationProvider('openai');

    dictate($this)->assertStatus(503); // EUA, sem registro: bloqueado
    Http::assertNothingSent();

    app(AiProviderSettings::class)->setTransferRecord('openai', [
        'mechanism'     => 'standard_clauses',
        'reference'     => 'DPA-2026-01',
        'signed_at'     => '2026-10-01',
        'registered_by' => null,
        'registered_at' => null,
    ]);

    dictate($this)->assertOk()->assertJsonPath('text', 'Laudo ditado.');
});

it('sem chave de API ou sem endereço do provedor o ditado fica indisponível', function () {
    config(['services.mistral.api_key' => '']);
    Http::fake();
    dictate($this)->assertStatus(503);

    config(['services.mistral.api_key' => 'k', 'ai.providers.mistral.base_url' => '']);
    dictate($this)->assertStatus(503);

    Http::assertNothingSent();
});

it('Azure: usa o cabeçalho api-key e a UE (adequação) dispensa o registro', function () {
    config([
        'ai.transcription.provider'             => 'azure_openai',
        'ai.transcription.model'                => 'whisper',
        'services.azure_openai.api_key'         => 'azure-key',
        'ai.providers.azure_openai.base_url'    => 'https://clinica.openai.azure.com/openai/v1',
        'ai.providers.azure_openai.auth'        => 'api-key',
        'ai.providers.azure_openai.data_region' => 'eu',
    ]);
    Http::fake(['clinica.openai.azure.com/*' => Http::response(['text' => 'Ok.'])]);
    enableDictationProvider('azure_openai');

    dictate($this)->assertOk();

    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('api-key', 'azure-key') && ! $r->hasHeader('Authorization'));
});

it('[SEGURANÇA] áudio que não é WAV PCM é recusado antes de gastar a chamada', function () {
    Http::fake();

    dictate($this, 'isto não é um áudio, é só texto qualquer com mais de quarenta e quatro bytes')->assertStatus(422);
    dictate($this, "RIFF\0\0\0\0WAVE" . str_repeat('x', 100))->assertStatus(422);

    Http::assertNothingSent();
});

it('[SEGURANÇA] a duração é medida no cabeçalho: acima do teto é 422, abaixo do mínimo também', function () {
    config(['ai.transcription.max_seconds' => 5]);
    Http::fake();

    dictate($this, dictationWav(6.0))->assertStatus(422)
        ->assertJsonPath('message', __('eye_images.dictation_too_long', ['seconds' => 5]));
    dictate($this, dictationWav(0.1))->assertStatus(422);

    Http::assertNothingSent();
});

it('o teto de tamanho do arquivo vale mesmo antes de medir', function () {
    config(['ai.transcription.max_kb' => 10]);
    Http::fake();

    dictate($this, dictationWav(2.0))->assertStatus(422)->assertJsonValidationErrors('audio');

    Http::assertNothingSent();
});

it('falha do provedor vira 502 genérico: nem o corpo da resposta nem o áudio vazam, e o log traz só provedor e status', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['error' => 'eco do áudio: paciente Maria da Silva'], 500)]);
    Log::spy();

    $response = dictate($this)->assertStatus(502);

    expect($response->getContent())->not->toContain('Maria da Silva');
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'speech.transcription.failed'
            && $context === ['provider' => 'mistral', 'status' => 500],
    );
});

it('transcrição vazia é 422 "nenhuma fala reconhecida"', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => '   '])]);

    dictate($this)->assertStatus(422)->assertJsonPath('message', __('eye_images.dictation_no_speech'));
});

it('o texto devolvido é puro: caracteres de controle saem e o tamanho tem teto', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => "Olho\x00 direito\x07 normal." . str_repeat('a', 25000)])]);

    $text = dictate($this)->assertOk()->json('text');

    expect($text)->toStartWith('Olho direito normal.')
        ->and(mb_strlen($text))->toBe(20000);
});

it('[SEGURANÇA] perfil sem permissão de preparar laudo (financeiro) recebe 403', function () {
    Http::fake();

    dictate($this, null, $this->financialUser)->assertForbidden();

    Http::assertNothingSent();
});

it('o ditado tem limite por minuto (usuário + clínica)', function () {
    config(['ai.transcription.per_minute' => 2]);
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => 'Ok.'])]);

    dictate($this)->assertOk();
    dictate($this)->assertOk();
    dictate($this)->assertStatus(429);
});

it('a tela de imagens só oferece o ditado quando o recurso está disponível', function () {
    $this->actingAs($this->secretaryUser)->withSession(panelSession($this->secretaryEntityUser))
        ->get(route('panel.eye-images.index'))
        ->assertInertia(fn ($page) => $page->where('dictation.available', true)->where('dictation.max_seconds', 120)
            ->where('urls.report_dictate', route('panel.eye-images.reports.dictate')));

    config(['ai.transcription.enabled' => false]);

    $this->actingAs($this->secretaryUser)->withSession(panelSession($this->secretaryEntityUser))
        ->get(route('panel.eye-images.index'))
        ->assertInertia(fn ($page) => $page->where('dictation.available', false));
});

// ── B1: o Manager é a fonte de verdade dos provedores habilitados ───────────

it('[LGPD] provedor retirado no Manager deixa de receber áudio mesmo com a chave ainda no .env', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => 'Ok.'])]);

    dictate($this)->assertOk();

    // O dono do SaaS retira o Mistral e deixa só outro provedor; a credencial do
    // Mistral continua no .env (e a flag do ditado, ligada).
    enableDictationProvider('anthropic');
    expect(config('services.mistral.api_key'))->toBe('test-mistral-key');

    dictate($this)->assertStatus(503)->assertJsonPath('message', __('eye_images.dictation_unavailable'));

    Http::assertSentCount(1); // só o ditado anterior à retirada
});

it('o provedor de transcrição precisa estar habilitado no Manager; religar o habilita de novo', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => 'Ok.'])]);
    enableDictationProvider('openai');

    dictate($this)->assertStatus(503);
    Http::assertNothingSent();

    enableDictationProvider('mistral');
    dictate($this)->assertOk();
});

it('a tela não oferece o ditado quando o provedor foi retirado no Manager', function () {
    enableDictationProvider('anthropic');

    $this->actingAs($this->secretaryUser)->withSession(panelSession($this->secretaryEntityUser))
        ->get(route('panel.eye-images.index'))
        ->assertInertia(fn ($page) => $page->where('dictation.available', false));
});

// ── B2: a duração vem dos frames PCM reais, não do que o cabeçalho diz ──────

it('[SEGURANÇA] cabeçalho com byteRate inflado não esconde um áudio acima do teto (180 s passavam como 90 s)', function () {
    config(['ai.transcription.max_seconds' => 120]);
    Http::fake();
    $forged = forgedDictationWav(frames: 16000 * 180, fmt: ['byteRate' => 64000]); // 180 s reais

    dictate($this, $forged)->assertStatus(422);

    Http::assertNothingSent();
});

it('[SEGURANÇA] áudio acima do teto com cabeçalho coerente continua barrado (controle do teste anterior)', function () {
    config(['ai.transcription.max_seconds' => 120]);
    Http::fake();

    dictate($this, forgedDictationWav(frames: 16000 * 180))->assertStatus(422)
        ->assertJsonPath('message', __('eye_images.dictation_too_long', ['seconds' => 120]));

    Http::assertNothingSent();
});

// ── B3: a trilha existe ANTES do áudio sair e a falha em gravá-la fecha a porta ──

it('[LGPD] a trilha de auditoria já está gravada quando o áudio chega ao provedor', function () {
    $seenAtSend = null;
    Http::fake(function () use (&$seenAtSend) {
        $seenAtSend = DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->get()->all();

        return Http::response(['text' => 'Texto sigiloso.']);
    });

    dictate($this)->assertOk();

    expect($seenAtSend)->toHaveCount(1);
    $atSend = json_decode((string) $seenAtSend[0]->new_values, true);
    expect($atSend)->toMatchArray(['provider' => 'mistral', 'outcome' => 'sending'])
        ->and($atSend)->toHaveKeys(['audio_sha256', 'audio_bytes', 'audio_seconds']);

    // Depois da resposta, a MESMA linha ganha o resultado (sem texto).
    $rows = DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->get();
    expect($rows)->toHaveCount(1);
    $final = json_decode((string) $rows[0]->new_values, true);
    expect($final)->toMatchArray(['outcome' => 'transcribed', 'text_characters' => mb_strlen('Texto sigiloso.')])
        ->and(json_encode($final))->not->toContain('sigiloso');
});

it('[LGPD] falhou a gravação da trilha: o áudio NÃO é enviado e a resposta é 503', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => 'Ok.'])]);
    // Falha REAL do banco, sem tocar no esquema: audit_logs.ip_address é varchar(45).
    $this->withServerVariables(['REMOTE_ADDR' => str_repeat('9', 60)]);

    $response = dictate($this)->assertStatus(503);

    expect($response->json('message'))->toBe(__('eye_images.dictation_audit_failed'));
    Http::assertNothingSent();
    expect(DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->count())->toBe(0);
});

it('a tentativa que o provedor derrubou também fica na trilha, com o resultado', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['error' => 'x'], 500)]);

    dictate($this)->assertStatus(502);

    $row = DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->first();
    expect($row)->not->toBeNull()
        ->and(json_decode((string) $row->new_values, true))->toMatchArray(['outcome' => 'provider_failed']);
});

it('transcrição vazia fica na trilha como "sem fala", e áudio recusado antes do envio não deixa registro (nada saiu)', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => ' '])]);
    dictate($this)->assertStatus(422);

    $row = DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->first();
    expect(json_decode((string) $row->new_values, true))->toMatchArray(['outcome' => 'no_speech']);

    DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->delete();
    dictate($this, 'não é áudio nenhum, só texto comprido o bastante para passar do tamanho mínimo')->assertStatus(422);
    expect(DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->count())->toBe(0);
});

// ── B3 (reaberto): a trilha falha FECHADA também na serialização e só vale comitada ──

it('[LGPD] valor que não serializa (UTF-8 inválido no modelo): a trilha não nasce vazia — 503, nada enviado e nenhuma linha gravada', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => 'Ok.'])]);
    config(['ai.transcription.model' => "voxtral-\xB1\x31"]); // UTF-8 inválido: json_encode falha

    $response = dictate($this)->assertStatus(503);

    expect($response->json('message'))->toBe(__('eye_images.dictation_audit_failed'));
    Http::assertNothingSent();
    expect(DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->count())->toBe(0);
});

it('[LGPD] transação externa aberta: o INSERT da trilha não seria comitado antes do envio — a gravação é recusada, nada sai', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => 'Ok.'])]);

    DB::beginTransaction(); // transação externa (a do chamador): a trilha viraria savepoint, sem commit real

    try {
        $response = dictate($this)->assertStatus(503);
    } finally {
        DB::rollBack();
    }

    expect($response->json('message'))->toBe(__('eye_images.dictation_audit_failed'));
    Http::assertNothingSent();
    expect(DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->count())->toBe(0);
});

it('AuditLogger::recordAdminActionOrFail: sem new_values, valor não serializável ou transação externa → exceção (e nenhuma linha)', function (array $newValues, ?int $extraLevels) {
    $audit   = app(AuditLogger::class);
    $request = Request::create('/x', 'POST');
    $call    = fn () => DB::transaction(fn () => $audit->recordAdminActionOrFail(
        event: 'teste.trilha',
        targetEntityId: null,
        targetUserId: null,
        auditableType: 'entity',
        auditableId: (string) $this->entity->id,
        reason: 'x',
        newValues: $newValues,
        request: $request,
    ));

    if ($extraLevels !== null) {
        DB::beginTransaction();
    }

    $thrown = null;

    try {
        $call();
    } catch (Throwable $e) {
        $thrown = $e;
    } finally {
        if ($extraLevels !== null) {
            DB::rollBack();
        }
    }

    expect($thrown)->not->toBeNull();

    expect(DB::table('audit_logs')->where('event', 'teste.trilha')->count())->toBe(0);
})->with([
    'new_values vazio'      => [[], null],
    'UTF-8 inválido'        => [['model' => "x\xB1"], null],
    'com transação externa' => [['ok' => 'valor'], 1],
]);

it('AuditLogger::recordAdminActionOrFail: caso válido grava a linha com new_values e devolve o id', function () {
    $audit = app(AuditLogger::class);
    $id    = DB::transaction(fn () => $audit->recordAdminActionOrFail(
        event: 'teste.trilha.ok',
        targetEntityId: null,
        targetUserId: null,
        auditableType: 'entity',
        auditableId: (string) $this->entity->id,
        reason: 'x',
        newValues: ['k' => 'v'],
        request: Request::create('/x', 'POST'),
    ));

    expect(json_decode((string) DB::table('audit_logs')->where('id', $id)->value('new_values'), true))->toBe(['k' => 'v']);
});

it('WAV válido com chunk LIST de ~8 KB antes do data: aceito e transcrito (o scan percorre os chunks)', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => 'Ok.'])]);
    $list = 'LIST' . pack('V', 8192) . str_repeat('a', 8192);

    dictate($this, dictationWav(2.0, [$list]))->assertOk()->assertJsonPath('text', 'Ok.');

    Http::assertSentCount(1);
});

it('[LGPD] falha de SQL ao COMPLETAR a trilha não derruba a requisição nem deixa a transação abortada; o registro "sending" permanece', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => 'Ok.'])]);
    // A conclusão falha de verdade no banco (id que não é uuid → erro de SQL do PostgreSQL).
    $this->app->instance(AuditLogger::class, new class() extends AuditLogger {
        public function completeAdminAction(string $id, array $newValues): void
        {
            parent::completeAdminAction('nao-e-um-uuid', $newValues);
        }
    });

    dictate($this)->assertOk()->assertJsonPath('text', 'Ok.');

    // Sem o savepoint isto lançaria "current transaction is aborted" (a consulta seguinte da requisição).
    $row = DB::table('audit_logs')->where('event', 'eye_images.report.dictation')->first();
    expect($row)->not->toBeNull()
        ->and(json_decode((string) $row->new_values, true))->toMatchArray(['outcome' => 'sending']);
});

it('AuditLogger::completeAdminAction: erro de SQL é engolido sem abortar a transação corrente', function () {
    app(AuditLogger::class)->completeAdminAction('nao-e-um-uuid', ['outcome' => 'x']);

    expect(DB::selectOne('select 1 as ok')->ok)->toBe(1);
});

describe('WavInspector', function () {
    function inspectorFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wav');
        file_put_contents($path, $content);

        return $path;
    }

    it('mede a duração pelo cabeçalho, inclusive com chunk extra (LIST) antes do data', function () {
        $list = 'LIST' . pack('V', 5) . 'abcde' . "\0"; // 5 bytes + 1 de alinhamento

        $path = inspectorFile(dictationWav(2.5, [$list]));
        expect(WavInspector::durationSeconds($path))->toBe(2.5);
        @unlink($path);
    });

    it('chunk LIST de ~8 KB antes do data: WAV válido aceito, com a duração certa', function () {
        $list = 'LIST' . pack('V', 8192) . str_repeat('a', 8192);
        $path = inspectorFile(dictationWav(2.5, [$list]));

        expect(WavInspector::durationSeconds($path))->toBe(2.5);
        @unlink($path);
    });

    it('vários chunks antes do data são percorridos (até ~1 MiB); o "data" além do teto de varredura é recusado', function () {
        $near = 'LIST' . pack('V', 900_000) . str_repeat('x', 900_000);
        $far  = 'LIST' . pack('V', 1_200_000) . str_repeat('x', 1_200_000);

        $path = inspectorFile(dictationWav(1.0, [$near]));
        expect(WavInspector::durationSeconds($path))->toBe(1.0);
        @unlink($path);

        $path = inspectorFile(dictationWav(1.0, [$far]));
        expect(WavInspector::durationSeconds($path))->toBeNull();
        @unlink($path);
    });

    it('chunk de tamanho absurdo (maior que o arquivo) antes do data: recusado, sem ler além do arquivo', function () {
        $path = inspectorFile(dictationWav(1.0, ['junk' . pack('V', 0x7FFFFFFF) . 'x']));

        expect(WavInspector::durationSeconds($path))->toBeNull();
        @unlink($path);
    });

    it('gravação em fluxo com o tamanho do data = 0 vale o resto do arquivo', function () {
        $path = inspectorFile(substr_replace(dictationWav(2.0), pack('V', 0), 40, 4));

        expect(WavInspector::durationSeconds($path))->toBe(2.0);
        @unlink($path);
    });

    it('gravação em fluxo (tamanho do data zerado) vale o resto do arquivo', function () {
        $wav  = dictationWav(2.0);
        $wav  = substr_replace($wav, pack('V', 0xFFFFFFFF), 40, 4);
        $path = inspectorFile($wav);

        expect(WavInspector::durationSeconds($path))->toBe(2.0);
        @unlink($path);
    });

    it('[SEGURANÇA] byteRate inflado: 180 s reais não passam como 90 s (o cabeçalho tem de ser coerente)', function () {
        $path = inspectorFile(forgedDictationWav(frames: 16000 * 180, fmt: ['byteRate' => 64000]));

        expect(WavInspector::durationSeconds($path))->toBeNull();
        @unlink($path);
    });

    it('[SEGURANÇA] fmt incoerente é recusado: taxa × canais × bits × alinhamento × byteRate', function (array $fmt) {
        $path = inspectorFile(forgedDictationWav(frames: 32000, fmt: $fmt));

        expect(WavInspector::durationSeconds($path))->toBeNull();
        @unlink($path);
    })->with([
        'alinhamento errado'   => [['blockAlign' => 4]],
        'byteRate errado'      => [['byteRate' => 16000]],
        'bits fora do padrão'  => [['bits' => 12, 'blockAlign' => 2]],
        'zero canais'          => [['channels' => 0, 'blockAlign' => 0, 'byteRate' => 0]],
        'canais demais'        => [['channels' => 9, 'blockAlign' => 18, 'byteRate' => 288000]],
        'taxa zero'            => [['rate' => 0, 'byteRate' => 0]],
        'taxa abaixo de 8 kHz' => [['rate' => 4000, 'byteRate' => 8000]],
        'taxa acima de 48 kHz' => [['rate' => 96000, 'byteRate' => 192000]],
    ]);

    it('fmt coerente e fora do padrão do navegador (estéreo, 44,1 kHz) é aceito e medido pelos frames', function () {
        // 1 s de estéreo 16 bits a 44,1 kHz = 44100 frames de 4 bytes.
        $frames = 44100;
        $wav    = 'RIFF' . pack('V', 36 + $frames * 4) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 2, 44100, 44100 * 4, 4, 16)
            . 'data' . pack('V', $frames * 4) . str_repeat("\0", $frames * 4);
        $path = inspectorFile($wav);

        expect(WavInspector::durationSeconds($path))->toBe(1.0);
        @unlink($path);
    });

    it('[SEGURANÇA] data declarado maior que o arquivo (e não é gravação em fluxo) é inconsistente', function () {
        $path = inspectorFile(forgedDictationWav(frames: 16000, declaredDataBytes: 16000 * 2 * 100));

        expect(WavInspector::durationSeconds($path))->toBeNull();
        @unlink($path);
    });

    it('[SEGURANÇA] áudio escondido depois do chunk data conta: vale o que há até o fim do arquivo', function () {
        // data declara 10 s, mas depois dele vêm mais 60 s num chunk qualquer.
        $junk = 'junk' . pack('V', 16000 * 2 * 60) . str_repeat("\0", 16000 * 2 * 60);
        $path = inspectorFile(forgedDictationWav(frames: 16000 * 10, trailing: $junk));

        expect(WavInspector::durationSeconds($path))->toBeGreaterThan(69.9);
        @unlink($path);
    });

    it('[SEGURANÇA] tamanho do RIFF maior que o arquivo (truncado/adulterado) é recusado', function () {
        $path = inspectorFile(forgedDictationWav(frames: 32000, riffSize: 10_000_000));

        expect(WavInspector::durationSeconds($path))->toBeNull();
        @unlink($path);
    });

    it('recusa o que não é WAV PCM (outro formato, "data" antes de "fmt ", arquivo curto, inexistente)', function () {
        $notPcm    = substr_replace(dictationWav(2.0), pack('v', 3), 20, 2); // formato 3 = float
        $dataFirst = 'RIFF' . pack('V', 100) . 'WAVEdata' . pack('V', 64) . str_repeat("\0", 64);

        foreach ([$notPcm, $dataFirst, 'RIFF', 'x'] as $content) {
            $path = inspectorFile($content);
            expect(WavInspector::durationSeconds($path))->toBeNull();
            @unlink($path);
        }

        expect(WavInspector::durationSeconds('/caminho/que/nao/existe.wav'))->toBeNull();
    });
});
