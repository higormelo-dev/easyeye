<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{CommissionStatus, PartnerLeadStatus, PartnerType};
use App\Models\{Partner, PartnerCommission, PartnerLead, Subscription};
use Illuminate\Support\Str;

class PartnerService
{
    /**
     * Cadastra um novo parceiro/revendedor.
     * Gera automaticamente o token único de atribuição.
     */
    public function create(
        string $name,
        string $email,
        PartnerType $type,
        ?string $document = null,
        ?float $commissionRate = null,
        ?string $notes = null,
        ?string $createdBy = null,
    ): Partner {
        return Partner::create([
            'name'            => $name,
            'email'           => $email,
            'document'        => $document,
            'type'            => $type,
            'commission_rate' => $commissionRate ?? $type->defaultCommissionRate(),
            'token'           => $this->generateUniqueToken(),
            'status'          => 'active',
            'notes'           => $notes,
            'created_by'      => $createdBy,
        ]);
    }

    /**
     * Registra um lead trazido por um parceiro.
     * Chamado quando o parceiro submete um novo contato pelo portal.
     */
    public function registerLead(
        Partner $partner,
        string $name,
        string $email,
        ?string $phone = null,
        ?string $city = null,
        ?string $state = null,
        ?string $notes = null,
    ): PartnerLead {
        return PartnerLead::create([
            'partner_id' => $partner->id,
            'entity_id'  => null,
            'name'       => $name,
            'email'      => $email,
            'phone'      => $phone,
            'city'       => $city,
            'state'      => $state,
            'status'     => PartnerLeadStatus::New,
            'notes'      => $notes,
        ]);
    }

    /**
     * Avança o status de um lead no funil.
     */
    public function advanceLead(PartnerLead $lead, PartnerLeadStatus $status, ?string $notes = null): void
    {
        $lead->update(array_filter([
            'status' => $status,
            'notes'  => $notes ?? $lead->notes,
        ]));
    }

    /**
     * Vincula um lead a uma clínica criada e avança para status Trial.
     * Chamado quando a clínica é registrada a partir de um lead.
     */
    public function linkLeadToEntity(PartnerLead $lead, string $entityId): void
    {
        $lead->update([
            'entity_id' => $entityId,
            'status'    => PartnerLeadStatus::Trial,
        ]);
    }

    /**
     * Gera uma comissão para o parceiro quando sua clínica ativa um plano pago.
     * Chamado pelo SubscriptionObserver.
     *
     * @param Subscription $subscription Assinatura que acabou de ser ativada
     */
    public function generateCommission(Subscription $subscription): ?PartnerCommission
    {
        $entity = $subscription->entity;

        if (! $entity->partner_id) {
            return null;
        }

        $partner = $entity->partner;

        if (! $partner || ! $partner->isActive()) {
            return null;
        }

        $plan   = $subscription->plan;
        $amount = round(($plan->price * $partner->commission_rate) / 100, 2);

        // Comissão gerada com vencimento em 30 dias
        $commission = PartnerCommission::create([
            'partner_id'      => $partner->id,
            'entity_id'       => $entity->id,
            'subscription_id' => $subscription->id,
            'amount'          => $amount,
            'rate'            => $partner->commission_rate,
            'period'          => now()->format('Y-m'),
            'status'          => CommissionStatus::Pending,
            'due_at'          => now()->addDays(30),
        ]);

        // Marca o lead do parceiro como convertido
        $lead = PartnerLead::query()
            ->where('partner_id', $partner->id)
            ->where('entity_id', $entity->id)
            ->first();

        $lead?->update([
            'status'       => PartnerLeadStatus::Converted,
            'converted_at' => now(),
        ]);

        return $commission;
    }

    /**
     * Marca uma comissão como paga.
     */
    public function payCommission(PartnerCommission $commission): void
    {
        $commission->update([
            'status'  => CommissionStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    /**
     * Localiza um parceiro pelo token de atribuição (parâmetro UTM).
     */
    public function findByToken(string $token): ?Partner
    {
        return Partner::where('token', $token)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Métricas resumidas de um parceiro para o dashboard do gestor.
     */
    public function metrics(Partner $partner): array
    {
        $leads = $partner->leads();

        return [
            'total_leads'     => $leads->count(),
            'active_leads'    => $leads->whereIn('status', ['new', 'contacted', 'trial'])->count(),
            'converted_leads' => $leads->where('status', PartnerLeadStatus::Converted->value)->count(),
            'conversion_rate' => $leads->count() > 0
                ? round($leads->where('status', PartnerLeadStatus::Converted->value)->count() / $leads->count() * 100, 1)
                : 0,
            'pending_commissions' => $partner->pendingCommissionsAmount(),
            'total_commissions'   => (float) $partner->commissions()->sum('amount'),
        ];
    }

    private function generateUniqueToken(): string
    {
        do {
            $token = strtoupper(Str::random(16));
        } while (Partner::where('token', $token)->exists());

        return $token;
    }
}
