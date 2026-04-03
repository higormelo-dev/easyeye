<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\DataTables\EntityUsersDataTable;
use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EntityUsersController extends Controller
{
    /**
     * Lista todos os usuários vinculados a uma empresa cliente,
     * com opção de iniciar sessão como cada um deles (impersonação).
     *
     * Rota: GET /panel/manager/entities/{entity}/users
     */
    public function index(Entity $entity, EntityUsersDataTable $dataTable): View|JsonResponse
    {
        $saasEntity = Entity::findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::SaasImpersonate->value, $saasEntity);

        $meta = [
            'title'            => $entity->name,
            'action'           => 'Usuários',
            'total'            => $entity->entityUsers()->count(),
            'breadcrumb_title' => false,
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Empresas', 'url' => route('panel.manager.entities.index'), 'active' => false],
                ['label' => $entity->name, 'url' => 'javascript:void(0);', 'active' => false],
                ['label' => 'Usuários', 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];

        return $dataTable
            ->forEntity($entity->id)
            ->render('system.manager.entities.users', compact('entity', 'meta'));
    }
}
