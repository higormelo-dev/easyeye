<?php

declare(strict_types=1);

use App\Support\Database\UniqueViolation;
use Illuminate\Database\{QueryException, UniqueConstraintViolationException};

/**
 * Detecção de QUAL índice único foi violado, independente do idioma do
 * servidor (lc_messages). Mensagens copiadas do formato real do PostgreSQL
 * (catálogos pt_BR/fr/de) e do MySQL 8.
 */
function uvPdo(string $sqlState, string $driverMessage): PDOException
{
    // Como o pdo_pgsql faz: código = SQLSTATE (string), errorInfo = [SQLSTATE, código do driver, mensagem].
    return new class($sqlState, $driverMessage) extends PDOException {
        public function __construct(string $sqlState, string $driverMessage)
        {
            parent::__construct("SQLSTATE[{$sqlState}]: Integrity constraint violation: 7 {$driverMessage}");

            $this->code      = $sqlState;
            $this->errorInfo = [$sqlState, 7, $driverMessage];
        }
    };
}

/** @param list<mixed> $bindings */
function uvUnique(string $driverMessage, string $sql = 'insert into "billing_claims" ("code") values (?)', array $bindings = ['GUI-0000000001']): UniqueConstraintViolationException
{
    return new UniqueConstraintViolationException('pgsql', $sql, $bindings, uvPdo('23505', $driverMessage));
}

const UV_INDEX = 'billing_claims_entity_code_unique';

it('reconhece o índice em qualquer idioma do servidor', function (string $driverMessage): void {
    expect(UniqueViolation::violates(uvUnique($driverMessage), UV_INDEX))->toBeTrue();
})->with([
    'pt_BR' => "ERRO:  duplicar valor da chave viola a restrição de unicidade \"billing_claims_entity_code_unique\"\nDETALHE:  Chave (entity_id, code)=(01a0, GUI-0000000001) já existe.",
    'en'    => "ERROR:  duplicate key value violates unique constraint \"billing_claims_entity_code_unique\"\nDETAIL:  Key (entity_id, code)=(01a0, GUI-0000000001) already exists.",
    'fr'    => "ERREUR:  la valeur d'une clé dupliquée rompt la contrainte unique « billing_claims_entity_code_unique »\nDÉTAIL : La clé « (entity_id, code)=(01a0, GUI-0000000001) » existe déjà.",
    'de'    => "FEHLER:  doppelter Schlüsselwert verletzt Unique-Constraint »billing_claims_entity_code_unique«\nDETAIL:  Schlüssel »(entity_id, code)=(01a0, GUI-0000000001)« existiert bereits.",
    'mysql' => "Duplicate entry '01a0-GUI-0000000001' for key 'billing_claims.billing_claims_entity_code_unique'",
]);

it('não confunde com outro índice da mesma tabela', function (): void {
    $e = uvUnique("ERRO:  duplicar valor da chave viola a restrição de unicidade \"billing_claims_active_schedule_unique\"\nDETALHE:  Chave (schedule_id)=(01a0) já existe.");

    expect(UniqueViolation::violates($e, UV_INDEX))->toBeFalse()
        ->and(UniqueViolation::violates($e, 'billing_claims_active_schedule_unique'))->toBeTrue()
        ->and(UniqueViolation::violates($e, UV_INDEX, 'billing_claims_active_schedule_unique'))->toBeTrue();
});

it('exige o nome inteiro: prefixo ou sufixo de outro índice não conta', function (): void {
    $e = uvUnique('ERRO:  duplicar valor da chave viola a restrição de unicidade "billing_claims_entity_code_unique_v2"');

    expect(UniqueViolation::violates($e, UV_INDEX))->toBeFalse()
        ->and(UniqueViolation::violates($e, 'claims_entity_code_unique_v2'))->toBeFalse()
        ->and(UniqueViolation::violates($e, 'billing_claims_entity_code_unique_v2'))->toBeTrue();
});

it('ignora o nome quando ele só aparece no DETAIL (valor da chave) ou no SQL/bindings', function (): void {
    $inDetail = uvUnique("ERRO:  duplicar valor da chave viola a restrição de unicidade \"schedules_code_unique\"\nDETALHE:  Chave (code)=(billing_claims_entity_code_unique) já existe.");
    $inSql    = uvUnique(
        'ERRO:  duplicar valor da chave viola a restrição de unicidade "billing_claims_active_schedule_unique"',
        'insert into "billing_claims" ("notes") values (?)',
        ['observação: billing_claims_entity_code_unique'],
    );

    expect($inSql->getMessage())->toContain(UV_INDEX)
        ->and(UniqueViolation::violates($inDetail, UV_INDEX))->toBeFalse()
        ->and(UniqueViolation::violates($inSql, UV_INDEX))->toBeFalse();
});

it('só trata violação de unicidade (SQLSTATE 23505), não outra violação que cite o nome', function (): void {
    $foreignKey = new QueryException('pgsql', 'insert into "x"', [], uvPdo(
        '23503',
        'ERRO:  inserção ou atualização em tabela "x" viola restrição de chave estrangeira "billing_claims_entity_code_unique"',
    ));

    expect(UniqueViolation::isUniqueViolation($foreignKey))->toBeFalse()
        ->and(UniqueViolation::violates($foreignKey, UV_INDEX))->toBeFalse();
});

it('aceita QueryException genérica com SQLSTATE 23505 (sem a subclasse do Laravel)', function (): void {
    $e = new QueryException('pgsql', 'insert into "x"', [], uvPdo(
        '23505',
        'ERRO:  duplicar valor da chave viola a restrição de unicidade "billing_claims_entity_code_unique"',
    ));

    expect(UniqueViolation::isUniqueViolation($e))->toBeTrue()
        ->and(UniqueViolation::violates($e, UV_INDEX))->toBeTrue();
});

it('sem errorInfo, lê a mensagem do PDO sem o sufixo de SQL anexado pelo Laravel', function (): void {
    $pdo = new PDOException('SQLSTATE[23505]: Unique violation: 7 ERRO:  duplicar valor da chave viola a restrição de unicidade "billing_claims_entity_code_unique"');
    $e   = new UniqueConstraintViolationException('pgsql', 'insert into "x" values (?)', ['billing_claims_active_schedule_unique'], $pdo);

    expect(UniqueViolation::violates($e, UV_INDEX))->toBeTrue()
        ->and(UniqueViolation::violates($e, 'billing_claims_active_schedule_unique'))->toBeFalse();
});

it('sem constraints informadas nunca afirma a violação', function (): void {
    expect(UniqueViolation::violates(uvUnique('ERRO:  ... "billing_claims_entity_code_unique"'), ''))->toBeFalse()
        ->and(UniqueViolation::violates(uvUnique('ERRO:  ... "billing_claims_entity_code_unique"')))->toBeFalse();
});

/** Como o pdo_sqlite entrega: SQLSTATE 23000 + código 19; a mensagem cita só as colunas. */
function uvSqliteUnique(string $driverMessage, string $sql = 'insert into "patients" ("code") values (?)', array $bindings = []): UniqueConstraintViolationException
{
    $pdo            = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 19 {$driverMessage}");
    $pdo->errorInfo = ['23000', 19, $driverMessage];

    return new UniqueConstraintViolationException('sqlite', $sql, $bindings, $pdo);
}

it('SQLite: reconhece a lista EXATA de colunas do índice (sem prefixo, sufixo ou coluna a mais)', function (): void {
    $twoColumns   = uvSqliteUnique('UNIQUE constraint failed: patients.entity_id, patients.code');
    $threeColumns = uvSqliteUnique('UNIQUE constraint failed: patients.entity_id, patients.code, patients.deleted_at');
    $otherTable   = uvSqliteUnique('UNIQUE constraint failed: old_patients.code');

    expect(UniqueViolation::violatesColumns($twoColumns, 'patients', 'entity_id', 'code'))->toBeTrue()
        ->and(UniqueViolation::violatesColumns($twoColumns, 'patients', 'code'))->toBeFalse()
        ->and(UniqueViolation::violatesColumns($twoColumns, 'patients', 'entity_id'))->toBeFalse()
        ->and(UniqueViolation::violatesColumns($twoColumns, 'patients', 'code', 'entity_id'))->toBeFalse()
        ->and(UniqueViolation::violatesColumns($threeColumns, 'patients', 'entity_id', 'code'))->toBeFalse()
        ->and(UniqueViolation::violatesColumns($threeColumns, 'patients', 'code', 'deleted_at'))->toBeFalse()
        ->and(UniqueViolation::violatesColumns($threeColumns, 'patients', 'entity_id', 'code', 'deleted_at'))->toBeTrue()
        ->and(UniqueViolation::violatesColumns($otherTable, 'patients', 'code'))->toBeFalse();
});

it('SQLite: colunas citadas só no SQL/bindings ou em violação que não é de unicidade não contam', function (): void {
    $inBindings = uvUnique(
        'ERRO:  duplicar valor da chave viola a restrição de unicidade "patients_entity_id_import_code_unique"',
        'insert into "patients" ("notes") values (?)',
        ['UNIQUE constraint failed: patients.entity_id, patients.code'],
    );
    $foreignKey = new QueryException('sqlite', 'insert into "patients"', [], uvPdo('23503', 'FOREIGN KEY failed: patients.entity_id, patients.code'));

    expect($inBindings->getMessage())->toContain('patients.entity_id, patients.code')
        ->and(UniqueViolation::violatesColumns($inBindings, 'patients', 'entity_id', 'code'))->toBeFalse()
        ->and(UniqueViolation::violatesColumns($foreignKey, 'patients', 'entity_id', 'code'))->toBeFalse()
        ->and(UniqueViolation::violatesColumns(uvSqliteUnique('UNIQUE constraint failed: patients.code'), '', 'code'))->toBeFalse()
        ->and(UniqueViolation::violatesColumns(uvSqliteUnique('UNIQUE constraint failed: patients.code'), 'patients'))->toBeFalse();
});
