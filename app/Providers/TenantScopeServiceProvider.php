<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\AI\Models\{AiCircuitBreaker, AiCreditLedgerEntry, AiCreditPurchase, AiCreditWallet, AiDoctorPrompt, AiRunFeedback};
use App\Models\{AdditionType, AuditLog, BillingBatch, BillingClaim, CashClose, ClinicResource, ColorVisionType, Covenant, CoverTestType, DataAccessLog, EntityActivation, ExamType, FeatureUsage, FinancialCashEntry, FinancialCategory, Indication, IrisType, Lense, LgpdRequest, Medicine, MedicinePresentation, NearPointConvergence, Notice, PartnerCommission, PartnerLead, PatientConsent, PatientImport, Procedure, ProcedurePrice, RecordVersion, ReferralCode, ReportSetting, ScheduleEvent, SkinType, Subscription, SurgeryType, VisitType, VisualAcuityType, WaitingList};
use App\Models\Billing\{BillingLog, BillingRetrySchedule, Cancellation, EntityGatewayAccess, FinancialEvent, GatewayCircuitBreaker, GatewayCredential, GatewayFallbackRule, Invoice, Payment, PaymentAttempt, SubscriptionChange, TenantGatewaySetting, WebhookEvent};
use App\Models\Scopes\EntityScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/**
 * Registra o isolamento multi-tenant (EntityScope global + auto-set de entity_id)
 * em massa, sem precisar editar cada model.
 *
 * Modelos que já usam o trait App\Models\Concerns\BelongsToEntity (clínicos,
 * AI\AiRun e o domínio Tiss) NÃO entram aqui — o trait já os cobre.
 *
 * Excluídos de propósito (entity_id é membership/cross-entidade, não posse):
 *   - EntityUser            (troca de entidade lista todas as vinculações)
 *   - EntityUserIntegrator  (integração por usuário, área manager/API)
 *
 * O scope é INERTE quando não há tenant vinculado (manager/webhook/job/CLI),
 * preservando os fluxos cross-entidade. Ver EntityScope/TenantContext.
 */
class TenantScopeServiceProvider extends ServiceProvider
{
    /**
     * Models de posse com coluna entity_id ainda SEM o trait BelongsToEntity.
     *
     * @var list<class-string<Model>>
     */
    private const MODELS = [
        // IA (exceto AiRun, que já usa o trait)
        AiCircuitBreaker::class,
        AiCreditLedgerEntry::class,
        AiCreditPurchase::class,
        AiCreditWallet::class,
        AiDoctorPrompt::class,
        AiRunFeedback::class,

        // Financeiro / Billing
        BillingBatch::class,
        BillingClaim::class,
        BillingLog::class,
        BillingRetrySchedule::class,
        Cancellation::class,
        EntityGatewayAccess::class,
        FinancialEvent::class,
        GatewayCircuitBreaker::class,
        GatewayCredential::class,
        GatewayFallbackRule::class,
        Invoice::class,
        Payment::class,
        PaymentAttempt::class,
        SubscriptionChange::class,
        TenantGatewaySetting::class,
        WebhookEvent::class,
        CashClose::class,
        FinancialCashEntry::class,
        FinancialCategory::class,

        // Assinatura
        Subscription::class,

        // Compliance / auditoria
        AuditLog::class,
        DataAccessLog::class,
        LgpdRequest::class,
        PatientConsent::class,
        RecordVersion::class,

        // Clínico auxiliar / catálogos
        ClinicResource::class,
        Covenant::class,
        ExamType::class,
        ScheduleEvent::class,
        WaitingList::class,
        PatientImport::class,
        AdditionType::class,
        ColorVisionType::class,
        CoverTestType::class,
        IrisType::class,
        Lense::class,
        Medicine::class,
        MedicinePresentation::class,
        NearPointConvergence::class,
        Procedure::class,
        ProcedurePrice::class,
        SkinType::class,
        SurgeryType::class,
        VisitType::class,
        VisualAcuityType::class,

        // Configuração / growth / avisos
        ReportSetting::class,
        Notice::class,
        Indication::class,
        ReferralCode::class,
        PartnerLead::class,
        PartnerCommission::class,
        EntityActivation::class,
        FeatureUsage::class,
    ];

    public function boot(): void
    {
        $scope = new EntityScope();

        foreach (self::MODELS as $model) {
            $model::addGlobalScope($scope);

            $model::creating(function (Model $record): void {
                if (blank($record->getAttribute('entity_id'))) {
                    $entityId = app(TenantContext::class)->id();

                    if ($entityId !== null) {
                        $record->setAttribute('entity_id', $entityId);
                    }
                }
            });
        }
    }
}
