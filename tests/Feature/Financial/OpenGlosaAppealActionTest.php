<?php

declare(strict_types=1);

use App\Domains\Tiss\Actions\OpenGlosaAppealAction;
use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\{TissGlosa, TissGlosaAppeal, TissOperator, TissStatusHistory};
use App\Exceptions\Financial\{AppealNumberUnavailableException, GlosaNotAppealableException};
use App\Models\Entity;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Numeração REC-AAAAMM-NNNNN dos recursos de glosa (sequencial por clínica e
 * mês) e guarda contra dois recursos para a mesma glosa. Antes: número lido
 * sem lock + ordem lexicográfica + soft delete fora da conta => violação do
 * índice único tiss_glosa_appeals_entity_number_unique => HTTP 500.
 *
 * A prova com processos PARALELOS reais fica fora da suíte (script de
 * corrida); aqui ficam os cenários determinísticos.
 */
function ogaaOperator(): TissOperator
{
    return TissOperator::query()->create([
        'ans_code' => (string) random_int(100000, 999999),
        'name'     => 'Operadora ' . Str::random(4),
        'active'   => true,
    ]);
}

/** @param array<string, mixed> $overrides */
function ogaaGlosa(Entity $entity, array $overrides = []): TissGlosa
{
    return TissGlosa::query()->create(array_merge([
        'entity_id'         => $entity->id,
        'operator_id'       => ogaaOperator()->id,
        'status'            => TissGlosaStatus::Open->value,
        'glosa_code'        => '3099',
        'glosa_description' => 'Procedimento não autorizado',
        'amount'            => 150,
        'identified_at'     => now()->toDateString(),
    ], $overrides));
}

/** Recurso já existente com número fixo (histórico da clínica). */
function ogaaExistingAppeal(Entity $entity, string $number): TissGlosaAppeal
{
    $glosa = ogaaGlosa($entity, ['status' => TissGlosaStatus::Appealed->value]);

    return TissGlosaAppeal::query()->create([
        'entity_id'        => $entity->id,
        'glosa_id'         => $glosa->id,
        'appeal_number'    => $number,
        'status'           => TissAppealStatus::Opened->value,
        'requested_amount' => 150,
        'accepted_amount'  => 0,
    ]);
}

function ogaaPrefix(): string
{
    return 'REC-' . now()->format('Ym') . '-';
}

function ogaaOpen(TissGlosa $glosa, array $payload = []): TissGlosaAppeal
{
    return app(OpenGlosaAppealAction::class)($glosa, $payload + ['reason' => 'Cobertura contratual válida.']);
}

/**
 * Simula OUTRO escritor (sem o lock — ex.: instância antiga durante o deploy)
 * gravando o MESMO número entre a leitura do último número e o INSERT.
 * Colide nas $times primeiras tentativas; devolve o contador de tentativas.
 */
function ogaaCollideOnInsert(TissGlosa $otherGlosa, int $times = PHP_INT_MAX): stdClass
{
    $state = (object) ['attempts' => 0];

    TissGlosaAppeal::creating(function (TissGlosaAppeal $appeal) use ($otherGlosa, $state, $times): void {
        $state->attempts++;

        if ($state->attempts > $times) {
            return;
        }

        DB::table('tiss_glosa_appeals')->insert([
            'id'               => (string) Str::uuid(),
            'entity_id'        => $appeal->entity_id,
            'glosa_id'         => $otherGlosa->id,
            'appeal_number'    => $appeal->appeal_number,
            'status'           => TissAppealStatus::Opened->value,
            'requested_amount' => 1,
            'accepted_amount'  => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    });

    return $state;
}

beforeEach(function (): void {
    $this->freezeTime();
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
});

describe('numeração sequencial por clínica e mês', function (): void {
    it('continua numérica depois de 99999 (antes a ordem lexicográfica repetia 100000 e dava 500)', function (): void {
        ogaaExistingAppeal($this->entity, ogaaPrefix() . '99999');

        $first  = ogaaOpen(ogaaGlosa($this->entity));
        $second = ogaaOpen(ogaaGlosa($this->entity));

        expect($first->appeal_number)->toBe(ogaaPrefix() . '100000')
            ->and($second->appeal_number)->toBe(ogaaPrefix() . '100001');
    });

    it('usa o maior número + 1 mesmo com lacunas (não reaproveita buracos)', function (): void {
        ogaaExistingAppeal($this->entity, ogaaPrefix() . '00001');
        ogaaExistingAppeal($this->entity, ogaaPrefix() . '00002');
        ogaaExistingAppeal($this->entity, ogaaPrefix() . '00007');

        expect(ogaaOpen(ogaaGlosa($this->entity))->appeal_number)->toBe(ogaaPrefix() . '00008');
    });

    it('conta recursos excluídos (soft delete): o índice único ainda enxerga o número', function (): void {
        ogaaExistingAppeal($this->entity, ogaaPrefix() . '00001');
        ogaaExistingAppeal($this->entity, ogaaPrefix() . '00002')->delete();

        expect(ogaaOpen(ogaaGlosa($this->entity))->appeal_number)->toBe(ogaaPrefix() . '00003');
    });

    it('ignora números fora do padrão no mesmo mês (legado/importado) sem sair da sequência', function (): void {
        ogaaExistingAppeal($this->entity, ogaaPrefix() . '00003');
        ogaaExistingAppeal($this->entity, ogaaPrefix() . 'LEGADO');
        ogaaExistingAppeal($this->entity, ogaaPrefix() . '00009-A');

        expect(ogaaOpen(ogaaGlosa($this->entity))->appeal_number)->toBe(ogaaPrefix() . '00004');
    });

    it('reinicia a sequência a cada mês', function (): void {
        ogaaExistingAppeal($this->entity, 'REC-' . now()->subMonthNoOverflow()->format('Ym') . '-00042');

        expect(ogaaOpen(ogaaGlosa($this->entity))->appeal_number)->toBe(ogaaPrefix() . '00001');
    });

    it('é isolada por clínica: números de uma clínica não afetam a outra', function (): void {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        ogaaExistingAppeal($other, ogaaPrefix() . '00010');
        ogaaExistingAppeal($other, ogaaPrefix() . '00011')->delete();

        $mine   = ogaaOpen(ogaaGlosa($this->entity));
        $theirs = ogaaOpen(ogaaGlosa($other));

        expect($mine->appeal_number)->toBe(ogaaPrefix() . '00001')
            ->and($mine->entity_id)->toBe($this->entity->id)
            ->and($theirs->appeal_number)->toBe(ogaaPrefix() . '00012')
            ->and($theirs->entity_id)->toBe($other->id);
    });

    it('ignora appeal_number vindo do payload: o número é sempre gerado pelo sistema', function (): void {
        ogaaExistingAppeal($this->entity, ogaaPrefix() . '00001');

        $duplicate = ogaaOpen(ogaaGlosa($this->entity), ['appeal_number' => ogaaPrefix() . '00001']);
        $freeForm  = ogaaOpen(ogaaGlosa($this->entity), ['appeal_number' => 'QUALQUER-COISA']);

        expect($duplicate->appeal_number)->toBe(ogaaPrefix() . '00002')
            ->and($freeForm->appeal_number)->toBe(ogaaPrefix() . '00003');
    });

    it('trava a glosa e serializa a numeração da clínica ANTES de ler o último número', function (): void {
        $glosa = ogaaGlosa($this->entity);

        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        ogaaOpen($glosa);

        $glosaLock  = collect($sql)->search(fn (string $q): bool => str_contains($q, 'from "tiss_glosas"') && str_contains($q, 'for update'));
        $numberLock = collect($sql)->search(fn (string $q): bool => str_contains($q, 'pg_advisory_xact_lock'));
        $lastNumber = collect($sql)->search(fn (string $q): bool => str_contains($q, 'from "tiss_glosa_appeals"') && str_contains($q, 'appeal_number'));

        expect($glosaLock)->toBeInt()
            ->and($numberLock)->toBeInt()
            ->and($lastNumber)->toBeInt()
            ->and($glosaLock)->toBeLessThan($numberLock)
            ->and($numberLock)->toBeLessThan($lastNumber);
    });
});

describe('colisão no índice único (última linha de defesa)', function (): void {
    it('tenta de novo quando outro escritor grava o mesmo número entre a leitura e o INSERT', function (): void {
        $glosa = ogaaGlosa($this->entity);
        $state = ogaaCollideOnInsert(ogaaGlosa($this->entity), times: 1);

        $appeal = ogaaOpen($glosa);

        expect($state->attempts)->toBe(2)
            ->and($appeal->appeal_number)->toBe(ogaaPrefix() . '00001')
            ->and(TissGlosaAppeal::query()->where('glosa_id', $glosa->id)->count())->toBe(1)
            ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Appealed)
            // A tentativa que falhou não deixou histórico duplicado.
            ->and(TissStatusHistory::query()->where('context_id', $glosa->id)->count())->toBe(1);
    });

    it('esgotadas as tentativas, lança AppealNumberUnavailableException e não grava nada', function (): void {
        $glosa = ogaaGlosa($this->entity);
        $state = ogaaCollideOnInsert(ogaaGlosa($this->entity));

        expect(fn () => ogaaOpen($glosa))->toThrow(AppealNumberUnavailableException::class);

        expect($state->attempts)->toBe(3)
            ->and(TissGlosaAppeal::query()->where('glosa_id', $glosa->id)->count())->toBe(0)
            ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Open)
            ->and(TissStatusHistory::query()->where('context_id', $glosa->id)->count())->toBe(0);
    });

    it('violação de OUTRA constraint no INSERT do recurso (PK) sobe na hora — não vira "número indisponível"', function (): void {
        // Antes a colisão era decidida pelo texto do SQL ("insert into
        // tiss_glosa_appeals"): qualquer violação nesse INSERT era tentada 3x e
        // mascarada como número indisponível (409), escondendo o erro real.
        $existing = ogaaExistingAppeal($this->entity, 'REC-LEGADO-1');
        $glosa    = ogaaGlosa($this->entity);
        $state    = (object) ['attempts' => 0];

        TissGlosaAppeal::creating(function (TissGlosaAppeal $appeal) use ($existing, $state): void {
            $state->attempts++;
            $appeal->id = $existing->id;
        });

        expect(fn () => ogaaOpen($glosa))->toThrow(UniqueConstraintViolationException::class);

        expect($state->attempts)->toBe(1)
            ->and(TissGlosaAppeal::query()->where('glosa_id', $glosa->id)->count())->toBe(0)
            ->and($glosa->fresh()->status)->toBe(TissGlosaStatus::Open);
    });

    it('HTTP: colisão persistente responde 409 com mensagem traduzida (nunca 500)', function (): void {
        actingAsFinancialEntityUser($this->entity);
        $glosa = ogaaGlosa($this->entity);
        ogaaCollideOnInsert(ogaaGlosa($this->entity));

        $this->postJson(route('panel.financial.tiss.glosas.appeal', $glosa->id), ['reason' => 'Procedimento coberto conforme contrato.'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('financial_glosas.appeal_number_unavailable'));

        expect($glosa->fresh()->status)->toBe(TissGlosaStatus::Open)
            ->and(trans('financial_glosas.appeal_number_unavailable', [], 'en'))->not->toBe('financial_glosas.appeal_number_unavailable')
            ->and(trans('financial_glosas.appeal_number_unavailable', [], 'pt_BR'))->not->toBe('financial_glosas.appeal_number_unavailable');
    });
});

describe('mesma glosa recorrida duas vezes', function (): void {
    it('a action recusa a segunda abertura com instância desatualizada e não cria outro recurso', function (): void {
        $glosa = ogaaGlosa($this->entity);
        $tabA  = TissGlosa::query()->findOrFail($glosa->id);
        $tabB  = TissGlosa::query()->findOrFail($glosa->id); // ainda "Aberta" em memória

        ogaaOpen($tabA);

        expect(fn () => ogaaOpen($tabB))->toThrow(GlosaNotAppealableException::class);
        expect(TissGlosaAppeal::query()->where('glosa_id', $glosa->id)->count())->toBe(1);
    });

    it('HTTP: segundo POST na mesma glosa responde 409 e mantém um único recurso', function (): void {
        actingAsFinancialEntityUser($this->entity);
        $glosa = ogaaGlosa($this->entity);
        $url   = route('panel.financial.tiss.glosas.appeal', $glosa->id);

        $this->post($url, ['reason' => 'Procedimento coberto conforme contrato.'])->assertSessionHas('success');
        $this->postJson($url, ['reason' => 'Procedimento coberto conforme contrato.'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('financial_glosas.cannot_appeal'));

        expect(TissGlosaAppeal::query()->where('glosa_id', $glosa->id)->count())->toBe(1);
    });

    it('usa o valor glosado relido sob lock quando o payload não informa requested_amount', function (): void {
        $glosa = ogaaGlosa($this->entity, ['amount' => 150]);
        $stale = TissGlosa::query()->findOrFail($glosa->id);
        TissGlosa::query()->whereKey($glosa->id)->update(['amount' => 175.5]);

        expect((string) ogaaOpen($stale)->requested_amount)->toBe('175.50');
    });
});
