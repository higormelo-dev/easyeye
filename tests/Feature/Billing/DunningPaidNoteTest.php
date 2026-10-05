<?php

declare(strict_types=1);

use App\Enums\Billing\DunningStep;
use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Models\{Entity, Plan, Subscription, User};
use App\Notifications\SubscriptionDunningNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * E-mail da régua para quem já pagou: depois do vencimento o bloqueio (IA e
 * financeiro no D+3) não espera a confirmação, que pode levar dias úteis —
 * o texto não manda "desconsiderar" e diz que o acesso volta sozinho com a
 * confirmação. No lembrete (antes do vencimento) não há bloqueio a citar.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 09:00:00'));

    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Nota']);
    $entity->skipAutoTrial = true;
    $entity->save();

    $this->subscription = Subscription::factory()->gateway()->for($entity)->for(Plan::factory()->create())->create([
        'status'          => SubscriptionStatus::PastDue,
        'billing_cycle'   => BillingCycle::Monthly,
        'amount'          => 299.90,
        'last_payment_at' => '2026-09-01 10:00:00',
        'past_due_at'     => '2026-10-01 23:59:59',
        'ends_at'         => '2026-10-01 23:59:59',
    ]);

    $this->user = User::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

/** Todas as linhas do e-mail (antes e depois do botão), no idioma pedido. */
function paidNoteMailText(DunningStep $step, string $locale): string
{
    app()->setLocale($locale);

    $mail = (new SubscriptionDunningNotification(test()->subscription, $step, '2026-10-01', [
        'entity'      => 'Clínica Nota',
        'amount'      => 299.90,
        'payment_url' => null,
        'limited_on'  => '2026-10-04',
        'blocked_on'  => '2026-10-08',
    ]))->toMail(test()->user);

    return implode(' ', [...$mail->introLines, ...$mail->outroLines]);
}

it('atraso, acesso limitado e 1ª cobrança vencida: sem "desconsidere", com o acesso voltando sozinho', function (DunningStep $step) {
    $pt = paidNoteMailText($step, 'pt_BR');
    $en = paidNoteMailText($step, 'en');

    expect($pt)->toContain(__('billing_dunning.paid_note_overdue', [], 'pt_BR'))
        ->and($pt)->not->toContain('desconsidere')
        ->and($pt)->toContain('o acesso volta automaticamente')
        ->and($en)->toContain(__('billing_dunning.paid_note_overdue', [], 'en'))
        ->and($en)->not->toContain('disregard')
        ->and($en)->toContain('access is restored automatically');
})->with([
    'D+1 atraso'          => [DunningStep::Overdue],
    'D+3 acesso limitado' => [DunningStep::Limited],
    '1ª cobrança vencida' => [DunningStep::FirstChargeOverdue],
]);

it('lembrete antes do vencimento: nota de quem já pagou sem "desconsidere"', function () {
    $pt = paidNoteMailText(DunningStep::Reminder, 'pt_BR');
    $en = paidNoteMailText(DunningStep::Reminder, 'en');

    expect($pt)->toContain(__('billing_dunning.paid_note', [], 'pt_BR'))
        ->and($pt)->not->toContain('desconsidere')
        ->and($pt)->not->toContain(__('billing_dunning.paid_note_overdue', [], 'pt_BR'))
        ->and($en)->toContain(__('billing_dunning.paid_note', [], 'en'))
        ->and($en)->not->toContain('disregard');
});

it('encerramento não traz a nota de quem já pagou', function () {
    $pt = paidNoteMailText(DunningStep::Terminated, 'pt_BR');

    expect($pt)->not->toContain(__('billing_dunning.paid_note', [], 'pt_BR'))
        ->and($pt)->not->toContain(__('billing_dunning.paid_note_overdue', [], 'pt_BR'));
});
