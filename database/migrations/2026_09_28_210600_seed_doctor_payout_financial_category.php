<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Categoria global de sistema "REPASSE MÉDICO" (despesa): a despesa gerada ao
 * registrar o pagamento de um repasse cai nela, e o Fluxo de Caixa/BI
 * separam o repasse das demais despesas. Idempotente (o nome é gravado em
 * maiúsculas por HasUppercaseName — mesma comparação aqui).
 */
return new class() extends Migration {
    private const NAME = 'REPASSE MÉDICO';

    public function up(): void
    {
        $exists = DB::table('financial_categories')
            ->whereNull('entity_id')
            ->whereNull('deleted_at')
            ->where('type', 'expense')
            ->whereRaw('upper(name) = ?', [self::NAME])
            ->exists();

        if ($exists) {
            return;
        }

        $lastCode = DB::table('financial_categories')
            ->whereNull('entity_id')
            ->where('code', '~', '^FCP-[0-9]{10}$')
            ->orderByDesc('code')
            ->value('code');

        $next = $lastCode ? ((int) substr((string) $lastCode, 4)) + 1 : 1;

        DB::table('financial_categories')->insert([
            'id'         => (string) Str::uuid7(),
            'entity_id'  => null,
            'code'       => sprintf('FCP-%010d', $next),
            'name'       => self::NAME,
            'type'       => 'expense',
            'active'     => true,
            'is_system'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Não remove: lançamentos de repasse já pagos apontam para a categoria.
     */
    public function down(): void
    {
    }
};
