<?php

// Remove do banco de DEV as clínicas de cenário do spec de cobrança
// (e2e/cypress/e2e/profiles/billing.cy.js e o gerador docs/billing-manual.cy.js):
// tudo que nasce em seed-billing.php — clínicas "CY-BILL …", usuários
// @cy-billing.test, o paciente/conta do portal e as pessoas dos médicos.
// Nada da CLÍNICA TESTE INTEGRADOR é tocado. Idempotente.
//
// Clínica de cenário = nome "CY-BILL …" (ou "CY-BILL" puro/outro sufixo COM o CNPJ da fixture). O CNPJ de cada clínica é
// gerado de uma base fixa (cyBillCnpj: 'CY-BILL|<nome>' → raiz de 8 dígitos + filial 0001 + dígitos verificadores
// corretos), nunca um CNPJ "famoso" de teste: o 11222333000181 colidia com clínicas reais de teste no dev. O CNPJ
// sozinho NUNCA apaga nada — só entidade cujo NOME começa com CY-BILL.
//
// As FKs para `entities` são quase todas ON DELETE CASCADE (assinaturas,
// faturas, pagamentos, vínculos, pacientes, pedidos de créditos de IA...);
// o resto (audit_logs, billing_logs, webhook_events...) é SET NULL.

use Illuminate\Support\Facades\DB;

if (! function_exists('cyBillCnpj')) {
    /** As clínicas de cenário do seed-billing.php (o nome é a base do CNPJ da fixture). */
    function cyBillClinicNames(): array
    {
        return ['CY-BILL EM DIA', 'CY-BILL ATRASO', 'CY-BILL LIMITADO', 'CY-BILL BLOQUEADO', 'CY-BILL RECORRENCIA', 'CY-BILL TRIAL FIM', 'CY-BILL TRIAL VENCIDO'];
    }

    /**
     * CNPJ numérico VÁLIDO (dígitos verificadores corretos) e estável por clínica, gerado da base fixa 'CY-BILL': raiz =
     * crc32('CY-BILL|<nome>') em 8 dígitos, filial 0001. Raiz de dígitos todos iguais (inválida na Receita) é deslocada.
     */
    function cyBillCnpj(string $name): string
    {
        $seed = crc32('CY-BILL|' . $name);

        do {
            $root = str_pad((string) ($seed % 100_000_000), 8, '0', STR_PAD_LEFT);
            $seed++;
        } while (count(array_unique(str_split($root))) === 1);

        $digits = $root . '0001';

        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
            $sum = 0;

            foreach ($weights as $i => $weight) {
                $sum += (int) $digits[$i] * $weight;
            }

            $rest = $sum % 11;
            $digits .= $rest < 2 ? '0' : (string) (11 - $rest);
        }

        return $digits;
    }
}

$fixtureCnpjs = array_map('cyBillCnpj', cyBillClinicNames());

// Por nome (as clínicas do seed) e, também, por CNPJ da fixture — mas SEMPRE só com nome CY-BILL: clínica alheia que por
// acaso tenha o mesmo CNPJ nunca é tocada.
$entityIds = DB::table('entities')
    ->where('name', 'like', 'CY-BILL%')
    ->where(fn ($q) => $q->where('name', 'like', 'CY-BILL %')->orWhereIn('national_registration', $fixtureCnpjs))
    ->pluck('id');
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
