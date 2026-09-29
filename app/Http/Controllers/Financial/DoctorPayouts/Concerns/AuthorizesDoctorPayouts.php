<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts\Concerns;

use App\Enums\{ClientRule, EntityGate};
use App\Models\Entity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Autorização comum das telas administrativas de repasse — a mesma do resto
 * do módulo financeiro (Gate ViewFinancial: admin e financeiro), mais as
 * operações só de admin (reabrir, estornar, visibilidade para o médico).
 */
trait AuthorizesDoctorPayouts
{
    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));

        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }

    private function isEntityAdmin(Entity $entity): bool
    {
        return (bool) auth()->user()?->hasRoleInEntity($entity, ClientRule::Admin);
    }

    /** Registro de outra clínica nunca é revelado: 404 (a rota já filtra; isto é defesa em profundidade). */
    private function assertBelongsToEntity(Model $model, Entity $entity): void
    {
        abort_unless((string) $model->getAttribute('entity_id') === (string) $entity->id, 404);
    }

    /** @return list<array{label: string, url: string, active: bool}> */
    private function payoutBreadcrumbs(string $current): array
    {
        return [
            ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
            ['label' => __('financial_doctor_payouts.breadcrumb_financial'), 'url' => route('panel.financial.bi.index'), 'active' => false],
            ['label' => __('financial_doctor_payouts.title'), 'url' => route('panel.financial.doctor-payouts.index'), 'active' => false],
            ['label' => $current, 'url' => '#', 'active' => true],
        ];
    }

    /** @return array{apuracao: string, closings: string, rules: string} */
    private function payoutTabs(): array
    {
        return [
            'apuracao' => route('panel.financial.doctor-payouts.index'),
            'closings' => route('panel.financial.doctor-payouts.closings.index'),
            'rules'    => route('panel.financial.doctor-payouts.rules.index'),
        ];
    }
}
