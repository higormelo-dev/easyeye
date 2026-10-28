<?php

use App\Domains\AI\Models\AiModelPrice;
use App\Enums\DataAccessPurpose;
use App\Models\{Entity, PatientAccount, People};
use App\Services\DataAccessLogService;
use App\Support\AuditContext;
use Database\Seeders\AiModelPriceSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Exceptions, Schema};

/**
 * Sentry EASY-EYE-TESTING-12: num `migrate` que aplica as duas no mesmo lote,
 * a migration de dados 2026_08_31 (refresh do catálogo de preços de IA →
 * AiModelPriceSeeder → model auditado) roda ANTES da 2026_09_06, que cria
 * audit_logs.patient_account_id. O INSERT mandava a coluna (null) e falhava:
 * 16 preços sem trilha, 32 erros reportados. O ator paciente só entra no
 * INSERT quando existe.
 *
 * O PostgreSQL desfaz DDL na transação do teste — a coluna volta sozinha.
 */
function dropPatientActorColumn(string $table): void
{
    Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('patient_account_id'));
}

afterEach(fn () => AuditContext::setPatientAccountId(null));

it('migration antiga que audita roda sem a coluna nova: trilha gravada e nada reportado', function () {
    Exceptions::fake();
    DB::table('ai_model_prices')->delete();
    dropPatientActorColumn('audit_logs');

    (new AiModelPriceSeeder())->run();

    $prices = DB::table('ai_model_prices')->count();

    expect($prices)->toBeGreaterThan(0)
        ->and(DB::table('audit_logs')->where('auditable_type', AiModelPrice::class)->where('event', 'created')->count())->toBe($prices);

    Exceptions::assertNothingReported();
});

it('log de leitura sensível também grava sem a coluna nova (não derruba a requisição)', function () {
    $entity = Entity::factory()->create();
    session(['selected_entity_id' => $entity->id]);
    dropPatientActorColumn('data_access_logs');

    app(DataAccessLogService::class)->log($entity, DataAccessPurpose::Administrative);

    expect(DB::table('data_access_logs')->where('resource_id', $entity->id)->count())->toBe(1);
});

it('[REGRESSÃO] paciente logado no Portal continua registrado nos dois logs', function () {
    $account = PatientAccount::factory()->create(['person_id' => People::factory()->create()->id]);
    AuditContext::setPatientAccountId($account->id);

    $price = AiModelPrice::factory()->create();
    app(DataAccessLogService::class)->log($price, DataAccessPurpose::PatientCare);

    expect(DB::table('audit_logs')->where('auditable_id', $price->id)->value('patient_account_id'))->toBe($account->id)
        ->and(DB::table('data_access_logs')->where('resource_id', $price->id)->value('patient_account_id'))->toBe($account->id);
});

it('sem paciente logado a coluna fica nula (staff/sistema)', function () {
    $price = AiModelPrice::factory()->create();

    expect(DB::table('audit_logs')->where('auditable_id', $price->id)->value('patient_account_id'))->toBeNull();
});
