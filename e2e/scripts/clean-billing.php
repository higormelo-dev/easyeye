<?php

// Remove do banco de DEV as clínicas de cenário do spec de cobrança
// (e2e/cypress/e2e/profiles/billing.cy.js e o gerador docs/billing-manual.cy.js):
// tudo que nasce em seed-billing.php — clínicas "CY-BILL …", usuários
// @cy-billing.test, o paciente/conta do portal e as pessoas dos médicos.
// Nada da CLÍNICA TESTE INTEGRADOR é tocado. Idempotente.
//
// As FKs para `entities` são quase todas ON DELETE CASCADE (assinaturas,
// faturas, pagamentos, vínculos, pacientes, pedidos de créditos de IA...);
// o resto (audit_logs, billing_logs, webhook_events...) é SET NULL.

use Illuminate\Support\Facades\DB;

$entityIds = DB::table('entities')->where('name', 'like', 'CY-BILL %')->pluck('id');
$userIds   = DB::table('users')->where('email', 'like', '%@cy-billing.test')->pluck('id');
$personIds = DB::table('people')->where('email', 'like', '%@cy-billing.test')->pluck('id');

DB::transaction(function () use ($entityIds, $userIds, $personIds): void {
    if ($personIds->isNotEmpty()) {
        DB::table('patient_accounts')->whereIn('person_id', $personIds)->delete();
    }

    if ($entityIds->isNotEmpty()) {
        $entityUserIds = DB::table('entity_users')->whereIn('entity_id', $entityIds)->pluck('id');
        DB::table('doctors')->whereIn('entity_user_id', $entityUserIds)->delete();
        // Linhas sem FK em cascata que o fluxo pode gerar para estas clínicas.
        DB::table('notices')->whereIn('entity_id', $entityIds)->delete();
        DB::table('schedule_events')->whereIn('entity_id', $entityIds)->delete();
        DB::table('waiting_list')->whereIn('entity_id', $entityIds)->delete();
        DB::table('entities')->whereIn('id', $entityIds)->delete();
    }

    if ($userIds->isNotEmpty()) {
        DB::table('ai_runs')->whereIn('requested_by', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    }

    if ($personIds->isNotEmpty()) {
        DB::table('people')->whereIn('id', $personIds)->delete();
    }
});

// Sessões no Redis ficam órfãs e expiram sozinhas; o cache de acesso por
// clínica é chaveado pelo id (não reaproveitado).
echo 'billing-clean:ok:', $entityIds->count(), ':', $userIds->count(), ';';
