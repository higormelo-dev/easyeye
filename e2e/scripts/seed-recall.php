<?php

use App\Enums\FeatureKey;
use App\Models\{Doctor, Entity, PlanFeature, Subscription};
use App\Services\FeatureGateService;

// Seed do fluxo de RETORNOS (Fase 6, spec clinic.recall):
//  - liga a feature `has_patient_recall` no plano da Clínica Teste Integrador;
//  - libera o protocolo de glaucoma (validado e liberado pelos MESMOS serviços do produto, como nos testes Pest);
//  - cria o paciente CY-RCL PACIENTE (celular com WhatsApp + autorização de lembretes) com um retorno em atraso;
//  - liga o canal de convite (driver mock: nada sai de verdade).
// NÃO adota o protocolo: quem ativa é o médico, pela tela, no spec. Idempotente e auto-limpante.
//
// Uso: php artisan tinker --execute="require 'e2e/scripts/seed-recall.php';"
require_once base_path('tests/ClinicalReviewFixtures.php');
require_once base_path('tests/RecallFixtures.php');

require base_path('e2e/scripts/clean-recall.php');

$ent = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$ana = Doctor::whereHas('person', fn ($q) => $q->where('email', 'dra.ana@clinicateste.com'))->firstOrFail();

$subscription = Subscription::query()->where('entity_id', $ent->id)->latest('starts_at')->firstOrFail();
PlanFeature::query()->updateOrCreate(
    ['plan_id' => $subscription->plan_id, 'feature' => FeatureKey::HasPatientRecall->value],
    ['value' => '1'],
);
app(FeatureGateService::class)->forgetCache((string) $ent->id);

rcfReleased('glaucoma');

$pat = rcfInvitePatient($ent, 'CY-RCL PACIENTE', '11955553333');
rcfRecord($ent, $pat, $ana, now()->subDays(60)->toDateString(), 30);
rcfEvaluate($ent, $pat, now()->toDateString());
rcfInviteChannel($ent);

echo "seed-recall ok: paciente {$pat->id}\n";
