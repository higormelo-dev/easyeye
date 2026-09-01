<?php
// Seed para as CAPTURAS do manual do financeiro: lançamentos de caixa e uma
// glosa TISS com descrições apresentáveis. Limpeza: clean-docs-financial.php.

$ent = App\Models\Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();

require __DIR__ . '/clean-docs-financial.php';

App\Models\FinancialCashEntry::create([
    'entity_id' => $ent->id, 'entry_date' => now()->toDateString(),
    'description' => 'Consulta particular — Dra. Ana', 'type' => 'income',
    'amount' => 350.00, 'status' => 'paid', 'notes' => '[demo-manual]',
]);
App\Models\FinancialCashEntry::create([
    'entity_id' => $ent->id, 'entry_date' => now()->toDateString(),
    'description' => 'Material de escritório', 'type' => 'expense',
    'amount' => 128.90, 'status' => 'paid', 'notes' => '[demo-manual]',
]);
App\Models\FinancialCashEntry::create([
    'entity_id' => $ent->id, 'entry_date' => now()->subDay()->toDateString(),
    'description' => 'Repasse convênio — consultas de agosto', 'type' => 'income',
    'amount' => 2140.00, 'status' => 'pending', 'notes' => '[demo-manual]',
]);

$op = App\Domains\Tiss\Models\TissOperator::firstOrFail();
App\Domains\Tiss\Models\TissGlosa::create([
    'entity_id' => $ent->id, 'operator_id' => $op->id, 'status' => 'open',
    'glosa_code' => '1802', 'glosa_description' => 'Cobrança em duplicidade — consulta 12/08',
    'amount' => 180.00, 'identified_at' => now()->subDays(2),
    'metadata' => ['demo_manual' => true],
]);
echo 'docsfin:ok';
