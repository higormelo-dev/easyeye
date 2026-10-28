<?php

declare(strict_types=1);

/**
 * Prova de DURABILIDADE da trilha do ditado (B3): quando o áudio chega ao provedor, a linha
 * `eye_images.report.dictation` (outcome=sending) já está COMITADA — visível a OUTRA conexão do banco.
 *
 * Isolado de propósito, sem transação envolvente (nada de RefreshDatabase/DatabaseTransactions): nos testes
 * Feature a transação de teste faz o DB::transaction() do controller virar SAVEPOINT, o HTTP acontece sem
 * commit real e a leitura pela mesma conexão enxerga a linha de qualquer jeito — passariam mesmo se o commit
 * antes do envio fosse removido. Aqui o nível de transação é 0 (a produção), os dados são comitados e a
 * limpeza é na mão. Roda por caminho explícito (tests/Integration), como os demais testes de banco real:
 *   ./vendor/bin/pest tests/Integration/ExamManagerReportDictationDurabilityTest.php
 * O esquema vem de DatabaseMigrations (migrate:fresh sem transação, como os vizinhos): os outros testes de
 * tests/Integration fazem migrate:rollback ao terminar, então "pressupor o esquema migrado" quebrava na CI
 * sempre que este arquivo rodava depois de um deles na mesma fatia (relation "system_settings" does not exist).
 */

use App\Domains\AI\Services\AiProviderSettings;
use App\Enums\ClientRule;
use App\Models\{Entity, User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** WAV PCM 16 kHz mono 16 bits de silêncio, 2 s. */
function ddWav(): string
{
    $data = str_repeat("\0", 16000 * 2 * 2);

    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
}

beforeEach(function () {
    $this->rolesBefore = app(AiProviderSettings::class)->roleAssignments();

    $this->entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->entity->skipAutoTrial = true;
    $this->entity->save();

    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Secretary->value);

    app(AiProviderSettings::class)->setRoleAssignments(['primary' => 'mistral', 'reviewer' => null, 'adjudicator' => null]);

    config([
        'audit.durable_transaction_baseline' => 0, // produção: a requisição começa SEM transação
        'ai.transcription.enabled'           => true,
        'ai.transcription.provider'          => 'mistral',
        'ai.transcription.model'             => 'voxtral-mini-latest',
        'ai.transcription.per_minute'        => 100,
        'services.mistral.api_key'           => 'test-mistral-key',
        'ai.providers.mistral.base_url'      => 'https://api.mistral.ai/v1',
        // Segunda conexão REAL ao mesmo banco: só enxerga o que foi comitado.
        'database.connections.pgsql_other' => config('database.connections.' . config('database.default')),
    ]);
});

afterEach(function () {
    // Limpeza na mão (nada disto foi desfeito por uma transação de teste).
    DB::table('audit_logs')->where('entity_id', $this->entity->id)->orWhere('user_id', $this->user->id)->delete();
    DB::table('entity_users')->where('entity_id', $this->entity->id)->delete();
    DB::table('entities')->where('id', $this->entity->id)->delete();
    DB::table('users')->where('id', $this->user->id)->delete();
    app(AiProviderSettings::class)->setRoleAssignments($this->rolesBefore);
    DB::purge('pgsql_other');
});

it('[LGPD] quando o áudio chega ao provedor, a trilha "sending" já está COMITADA — visível a outra conexão, sem transação aberta', function () {
    expect(DB::transactionLevel())->toBe(0); // sem transação envolvente

    $atSend = null;
    Http::fake(function (HttpRequest $request) use (&$atSend) {
        $atSend = [
            'level' => DB::transactionLevel(),
            'rows'  => DB::connection('pgsql_other')->table('audit_logs')
                ->where('event', 'eye_images.report.dictation')->where('entity_id', $this->entity->id)->get()->all(),
        ];

        return Http::response(['text' => 'Texto ditado.']);
    });

    $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
        ->post(route('panel.exam-manager.reports.dictate'), ['audio' => UploadedFile::fake()->createWithContent('ditado.wav', ddWav())], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('text', 'Texto ditado.');

    expect($atSend)->not->toBeNull()
        ->and($atSend['level'])->toBe(0)             // nenhuma transação aberta no instante do envio
        ->and($atSend['rows'])->toHaveCount(1);      // e a OUTRA conexão já vê a linha

    $seen = json_decode((string) $atSend['rows'][0]->new_values, true);
    expect($seen)->toMatchArray(['provider' => 'mistral', 'outcome' => 'sending'])
        ->and($seen)->toHaveKeys(['audio_sha256', 'audio_bytes', 'audio_seconds']);

    // Depois da resposta, a MESMA linha ganha o resultado (e continua sendo uma só).
    $final = DB::connection('pgsql_other')->table('audit_logs')->where('event', 'eye_images.report.dictation')->where('entity_id', $this->entity->id)->get();
    expect($final)->toHaveCount(1)
        ->and(json_decode((string) $final[0]->new_values, true))->toMatchArray(['outcome' => 'transcribed']);
});

it('[LGPD] com uma transação EXTERNA aberta o envio é recusado (503) — a trilha não chega comitada ao provedor', function () {
    Http::fake(['api.mistral.ai/*' => Http::response(['text' => 'Ok.'])]);

    DB::beginTransaction(); // quem chamou o controller já estava numa transação

    try {
        $response = $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
            ->post(route('panel.exam-manager.reports.dictate'), ['audio' => UploadedFile::fake()->createWithContent('ditado.wav', ddWav())], ['Accept' => 'application/json'])
            ->assertStatus(503);
    } finally {
        DB::rollBack();
    }

    expect($response->json('message'))->toBe(__('eye_images.dictation_audit_failed'));
    Http::assertNothingSent();
    expect(DB::connection('pgsql_other')->table('audit_logs')->where('event', 'eye_images.report.dictation')->where('entity_id', $this->entity->id)->count())->toBe(0);
});
