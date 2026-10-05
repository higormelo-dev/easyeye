<?php

// Seed do spec de cobrança (e2e/cypress/e2e/profiles/billing.cy.js) e do
// gerador de capturas do manual (e2e/cypress/e2e/docs/billing-manual.cy.js).
//
// Cria clínicas de CENÁRIO próprias — isoladas da CLÍNICA TESTE INTEGRADOR,
// que segue em cortesia para os outros specs não serem bloqueados:
//
//   CY-BILL EM DIA       plano Pro mensal pago e vigente (Asaas, renovação
//                        local), 1 fatura paga no histórico, a renovação em
//                        aberto (vence em 5 dias) e 1 pedido de créditos de IA
//                        aguardando pagamento. Admin (dono), financeiro,
//                        secretária e médico.
//   CY-BILL ATRASO       em atraso há 1 dia (régua D+1: aviso, acesso total).
//                        Admin e financeiro.
//   CY-BILL LIMITADO     em atraso há 4 dias (régua D+3: acesso limitado —
//                        IA e financeiro bloqueados). Admin, médico,
//                        secretária e financeiro.
//   CY-BILL BLOQUEADO    em atraso há 8 dias (régua D+7: bloqueio total).
//                        Admin, secretária, TV de chamada ligada e um
//                        paciente com conta no portal.
//   CY-BILL RECORRENCIA  pago e vigente, mas o gateway desativou a
//                        recorrência (alerta "Recorrência desativada" no
//                        manager). Sem usuários.
//   CY-BILL TRIAL FIM    teste grátis acaba em 2 dias (aviso "Contratar agora";
//                        IA sem franquia → paywall). Admin e médico.
//   CY-BILL TRIAL VENCIDO teste grátis acabou ontem (bloqueado, contratar
//                        já pagando). Admin.
//
// Nenhum gateway real é chamado: sem recorrência no gateway
// (gateway_subscription_id nulo) e as respostas de cobrança (Pix/boleto) são
// simuladas pelo spec com cy.intercept nas rotas JSON do próprio sistema.
// Usuários sem telefone (sem WhatsApp a verificar) e com os termos aceitos.
//
// Idempotente: limpa a execução anterior (clean-billing.php) antes de criar.
// Saída: JSON com ids/tokens que os specs usam.

require __DIR__ . '/clean-billing.php';

use App\Domains\AI\Services\AiCreditPurchaseService;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\{SubscriptionBillingMode, SubscriptionStatus};
use App\Models\Billing\{Gateway, Invoice};
use App\Models\{Covenant, Doctor, Entity, EntityUser, Patient, PatientAccount, People, Plan, Subscription, TermVersion, User};
use App\Services\TermsService;
use Illuminate\Support\Str;

const CY_BILL_PASSWORD = 'CyBilling@2026';

$pro    = Plan::query()->where('slug', 'pro')->firstOrFail();
$terms  = app(TermsService::class);
$asaas  = Gateway::query()->where('code', 'asaas')->value('id');
$output = [];

$clinic = function (string $name, string $cnpj, array $extra = []): Entity {
    // forceFill: TV de chamada (call_panel_*) fica fora do $fillable.
    $entity = (new Entity())->forceFill(array_merge([
        'name'                  => $name,
        'subdomain'             => Str::slug($name) . '-' . Str::lower(Str::random(6)),
        'national_registration' => $cnpj,
        'city'                  => 'São Paulo',
        'state'                 => 'SP',
        'email'                 => Str::slug($name) . '@cy-billing.test',
        'is_client'             => true,
        'active'                => true,
    ], $extra));
    // O trial automático do observer não entra: cada cenário cria a sua assinatura.
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
};

$user = function (Entity $entity, string $email, string $name, string $rule, bool $owner = false) use ($terms): User {
    $user = User::query()->create([
        'name'     => $name,
        'email'    => $email,
        'password' => CY_BILL_PASSWORD,
        'locale'   => 'pt_BR',
    ]);
    $user->forceFill(['email_verified_at' => now(), 'phone' => null])->save();

    EntityUser::query()->create([
        'entity_id' => $entity->id,
        'user_id'   => $user->id,
        'rule'      => $rule,
        'is_owner'  => $owner,
        'active'    => true,
        'joined_at' => now(),
    ]);

    foreach (TermsService::REQUIRED_TYPES as $type) {
        if ($version = TermVersion::currentFor($type)) {
            $terms->accept($user, $version);
        }
    }

    return $user;
};

$doctor = function (Entity $entity, User $user, string $fullName): Doctor {
    $person     = People::query()->create(['full_name' => $fullName, 'email' => $user->email, 'cellphone' => '1195555' . random_int(1000, 9999)]);
    $entityUser = EntityUser::query()->where('entity_id', $entity->id)->where('user_id', $user->id)->firstOrFail();

    return Doctor::query()->create([
        'person_id'        => $person->id,
        'entity_user_id'   => $entityUser->id,
        'record'           => (string) random_int(100000, 999999),
        'record_specialty' => (string) random_int(10000, 99999),
        'color'            => '#0d6efd',
        'partner'          => false,
        'active'           => true,
    ]);
};

$invoice = function (Entity $entity, Subscription $sub, array $attrs) use ($pro, $asaas): Invoice {
    return Invoice::query()->create(array_merge([
        'entity_id'       => $entity->id,
        'subscription_id' => $sub->id,
        'plan_id'         => $pro->id,
        'gateway_id'      => $asaas,
        'gateway_code'    => 'asaas',
        'reference'       => 'CY-' . Str::upper(Str::random(8)),
        'amount'          => 899.90,
        'currency'        => 'BRL',
        'billing_reason'  => 'subscription_cycle',
        'idempotency_key' => 'cy-billing-' . Str::uuid(),
        'correlation_id'  => (string) Str::uuid(),
    ], $attrs));
};

// Assinatura paga no gateway (Asaas), mensal, sem recorrência no gateway.
$paid = function (Entity $entity, array $attrs) use ($pro): Subscription {
    return Subscription::query()->create(array_merge([
        'entity_id'               => $entity->id,
        'plan_id'                 => $pro->id,
        'billing_mode'            => SubscriptionBillingMode::Gateway,
        'billing_cycle'           => 'monthly',
        'amount'                  => 899.90,
        'gateway'                 => 'asaas',
        'gateway_customer_id'     => 'cus_cy_' . Str::lower(Str::random(10)),
        'gateway_subscription_id' => null,
        'payment_method'          => 'pix',
        'idempotency_key'         => 'cy-billing-sub-' . Str::uuid(),
        'correlation_id'          => (string) Str::uuid(),
    ], $attrs));
};

// Assinatura em atraso há N dias, com a fatura vencida em aberto.
$overdue = function (Entity $entity, int $days) use ($paid, $invoice): array {
    $due = now()->subDays($days)->startOfDay()->setTime(12, 0);
    $sub = $paid($entity, [
        'status'          => SubscriptionStatus::PastDue,
        'billing_state'   => 'past_due',
        'starts_at'       => now()->subMonths(2),
        'ends_at'         => $due,
        'next_billing_at' => $due,
        'past_due_at'     => $due,
        'last_payment_at' => $due->copy()->subMonth(),
    ]);
    $invoice($entity, $sub, [
        'status'       => InvoiceStatus::Paid,
        'paid_at'      => $due->copy()->subMonth(),
        'due_at'       => $due->copy()->subMonth(),
        'period_start' => $due->copy()->subMonth()->toDateString(),
        'period_end'   => $due->toDateString(),
    ]);
    $open = $invoice($entity, $sub, [
        'status'       => InvoiceStatus::Pending,
        'due_at'       => $due,
        'period_start' => $due->toDateString(),
        'period_end'   => $due->copy()->addMonth()->toDateString(),
        'payment_url'  => 'https://sandbox.asaas.com/i/cy_overdue_' . $days,
    ]);
    $sub->update(['current_invoice_id' => $open->id]);

    return [$sub, $open];
};

// ── CY-BILL EM DIA ────────────────────────────────────────────────────────────
$ok   = $clinic('CY-BILL EM DIA', '11222333000181');
$okAd = $user($ok, 'admin.emdia@cy-billing.test', 'Ana Admin Em Dia', 'admin', owner: true);
$user($ok, 'financeiro.emdia@cy-billing.test', 'Fábio Financeiro Em Dia', 'financial');
$user($ok, 'secretaria.emdia@cy-billing.test', 'Sônia Secretária Em Dia', 'secretary');
$okDr = $user($ok, 'medico.emdia@cy-billing.test', 'Dr. Mário Em Dia', 'doctor');
$doctor($ok, $okDr, 'MÁRIO EM DIA');

$renewal = now()->addDays(5)->startOfDay()->setTime(12, 0);
$okSub   = $paid($ok, [
    'status'          => SubscriptionStatus::Active,
    'billing_state'   => 'active',
    'starts_at'       => now()->subMonth()->addDays(5),
    'ends_at'         => $renewal,
    'next_billing_at' => $renewal,
    'last_payment_at' => now()->subMonth()->addDays(5),
]);
$invoice($ok, $okSub, [
    'status'       => InvoiceStatus::Paid,
    'paid_at'      => now()->subMonth()->addDays(5),
    'due_at'       => now()->subMonth()->addDays(5),
    'period_start' => now()->subMonth()->addDays(5)->toDateString(),
    'period_end'   => $renewal->toDateString(),
]);
$okOpen = $invoice($ok, $okSub, [
    'status'       => InvoiceStatus::Pending,
    'due_at'       => $renewal,
    'period_start' => $renewal->toDateString(),
    'period_end'   => $renewal->copy()->addMonth()->toDateString(),
    'payment_url'  => 'https://sandbox.asaas.com/i/cy_emdia',
]);
$okSub->update(['current_invoice_id' => $okOpen->id]);

// Pedido de créditos de IA aguardando pagamento (o mesmo que o checkout abre:
// AiCreditPackCheckoutService::openOrder) — "Continuar pagamento"/"Descartar".
$purchase = app(AiCreditPurchaseService::class)->createPendingPurchase(
    entityId: (string) $ok->id,
    packageCode: 'starter',
    subscriptionId: (string) $okSub->id,
    requestedBy: (string) $okAd->id,
    idempotencyKey: 'ai-credit-purchase:checkout:' . Str::uuid(),
    metadata: ['gateway' => 'asaas'],
    source: 'checkout',
);
$pack = Invoice::query()->create([
    'entity_id'       => $ok->id,
    'subscription_id' => null,
    'plan_id'         => null,
    'gateway_id'      => $asaas,
    'gateway_code'    => 'asaas',
    'reference'       => 'IA-' . now()->format('Ymd') . '-CY' . Str::upper(Str::random(6)),
    'due_at'          => now()->addDays(3)->endOfDay(),
    'amount'          => round($purchase->amount_cents / 100, 2),
    'currency'        => $purchase->currency,
    'status'          => InvoiceStatus::Pending->value,
    'billing_reason'  => Invoice::BILLING_REASON_AI_CREDIT_PACK,
    'metadata'        => [
        'ai_credit_purchase_id' => (string) $purchase->id,
        'package_code'          => $purchase->package_code,
        'credits'               => (int) $purchase->credits,
    ],
    'correlation_id'  => (string) Str::uuid(),
    'idempotency_key' => 'ai-credit-pack:' . $purchase->id,
]);
$purchase->update(['invoice_id' => $pack->id]);

$output['ok'] = [
    'entity'       => (string) $ok->id,
    'subscription' => (string) $okSub->id,
    'open_invoice' => (string) $okOpen->id,
    'reference'    => $okOpen->reference,
    'ai_pack'      => (string) $pack->id,
];

// ── CY-BILL ATRASO (D+1: aviso, acesso total) ────────────────────────────────
$late = $clinic('CY-BILL ATRASO', '77888999000127');
$user($late, 'admin.atraso@cy-billing.test', 'Alice Admin Atraso', 'admin', owner: true);
$user($late, 'financeiro.atraso@cy-billing.test', 'Fernanda Financeiro Atraso', 'financial');
[$lateSub, $lateOpen] = $overdue($late, 1);
$output['overdue']    = ['entity' => (string) $late->id, 'open_invoice' => (string) $lateOpen->id];

// ── CY-BILL LIMITADO (D+4) ───────────────────────────────────────────────────
$lim = $clinic('CY-BILL LIMITADO', '22333444000172');
$user($lim, 'admin.limitado@cy-billing.test', 'Lia Admin Limitado', 'admin', owner: true);
$limDr = $user($lim, 'medico.limitado@cy-billing.test', 'Dra. Lúcia Limitado', 'doctor');
$user($lim, 'secretaria.limitado@cy-billing.test', 'Lara Secretária Limitado', 'secretary');
$user($lim, 'financeiro.limitado@cy-billing.test', 'Lauro Financeiro Limitado', 'financial');
$doctor($lim, $limDr, 'LÚCIA LIMITADO');
[$limSub, $limOpen] = $overdue($lim, 4);
$output['limited']  = ['entity' => (string) $lim->id, 'open_invoice' => (string) $limOpen->id];

// ── CY-BILL BLOQUEADO (D+8) ──────────────────────────────────────────────────
$callToken = Str::random(40);
$blk       = $clinic('CY-BILL BLOQUEADO', '33444555000163', [
    'call_panel_enabled' => true,
    'call_panel_token'   => $callToken,
]);
$user($blk, 'admin.bloqueado@cy-billing.test', 'Bruno Admin Bloqueado', 'admin', owner: true);
$user($blk, 'secretaria.bloqueado@cy-billing.test', 'Bia Secretária Bloqueado', 'secretary');
[$blkSub, $blkOpen] = $overdue($blk, 8);

$patientPerson = People::query()->create([
    'full_name' => 'CY-BILL PACIENTE PORTAL',
    'email'     => 'paciente.bloqueado@cy-billing.test',
    'cellphone' => '11955550000',
]);
$covenant = Covenant::query()->create(['entity_id' => $blk->id, 'name' => 'PARTICULAR', 'active' => true]);
$patient  = Patient::query()->create([
    'entity_id'   => $blk->id,
    'person_id'   => $patientPerson->id,
    'covenant_id' => $covenant->id,
    'active'      => true,
]);
$account = PatientAccount::query()->create([
    'person_id' => $patientPerson->id,
    'email'     => $patientPerson->email,
    'password'  => CY_BILL_PASSWORD,
]);
$account->forceFill(['email_verified_at' => now(), 'active' => true])->save();

$output['blocked'] = [
    'entity'       => (string) $blk->id,
    'open_invoice' => (string) $blkOpen->id,
    'call_token'   => $callToken,
    'patient'      => (string) $patient->id,
];

// ── CY-BILL RECORRENCIA (alerta no manager) ──────────────────────────────────
$rec    = $clinic('CY-BILL RECORRENCIA', '44555666000154');
$recSub = $paid($rec, [
    'status'              => SubscriptionStatus::Active,
    'billing_state'       => 'active',
    'starts_at'           => now()->subDays(10),
    'ends_at'             => now()->addDays(20),
    'next_billing_at'     => now()->addDays(20),
    'last_payment_at'     => now()->subDays(10),
    'recurrence_alert_at' => now()->subHour(),
]);
$invoice($rec, $recSub, [
    'status'       => InvoiceStatus::Paid,
    'paid_at'      => now()->subDays(10),
    'due_at'       => now()->subDays(10),
    'period_start' => now()->subDays(10)->toDateString(),
    'period_end'   => now()->addDays(20)->toDateString(),
]);
$output['recurrence'] = ['entity' => (string) $rec->id, 'subscription' => (string) $recSub->id];

// ── CY-BILL TRIAL FIM (teste grátis acaba em 2 dias) ─────────────────────────
$trialEnd = $clinic('CY-BILL TRIAL FIM', '55666777000145');
$user($trialEnd, 'admin.trialfim@cy-billing.test', 'Tina Admin Trial', 'admin', owner: true);
$trialDr = $user($trialEnd, 'medico.trialfim@cy-billing.test', 'Dr. Tiago Trial', 'doctor');
$doctor($trialEnd, $trialDr, 'TIAGO TRIAL');
Subscription::query()->create([
    'entity_id'       => $trialEnd->id,
    'plan_id'         => $pro->id,
    'status'          => SubscriptionStatus::Trial,
    'billing_mode'    => null,
    'starts_at'       => now()->subDays(5),
    'trial_ends_at'   => now()->addDays(2)->endOfDay(),
    'idempotency_key' => 'cy-billing-sub-' . Str::uuid(),
    'correlation_id'  => (string) Str::uuid(),
]);
$output['trial_ending'] = ['entity' => (string) $trialEnd->id];

// ── CY-BILL TRIAL VENCIDO (teste grátis acabou ontem — sem dias de graça) ────
$trialOver = $clinic('CY-BILL TRIAL VENCIDO', '66777888000136');
$user($trialOver, 'admin.trialvencido@cy-billing.test', 'Otávio Admin Trial', 'admin', owner: true);
Subscription::query()->create([
    'entity_id'       => $trialOver->id,
    'plan_id'         => $pro->id,
    'status'          => SubscriptionStatus::Expired,
    'billing_mode'    => null,
    'starts_at'       => now()->subDays(8),
    'trial_ends_at'   => now()->subDay(),
    'idempotency_key' => 'cy-billing-sub-' . Str::uuid(),
    'correlation_id'  => (string) Str::uuid(),
]);
$output['trial_over'] = ['entity' => (string) $trialOver->id];

$output['plans'] = Plan::query()->whereIn('slug', ['basico', 'pro', 'premium'])->pluck('id', 'slug')->map(fn ($id) => (string) $id)->all();

echo 'billing-seed:' . json_encode($output) . ';';
