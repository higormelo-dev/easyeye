<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Requests\SurgeryTypeRequest;
use App\Http\Resources\SurgeryTypeResource;
use App\Models\SurgeryType;
use App\Services\SurgeryTypeService;
use Illuminate\Database\Eloquent\Model;

class SurgeryTypesController extends BaseSettingController
{
    public function __construct(SurgeryTypeService $service)
    {
        $this->titleController = __('actions.sidemenu.surgerytypes');
        $this->service         = $service;
        $this->resourceClass   = SurgeryTypeResource::class;
        $this->routePrefix     = 'panel.setting.surgerytypes';
        $this->viewSlot        = 'surgerytypes';
        $this->crudFields      = ['name' => '', 'category' => '', 'active' => true];

        // Grupo de abas "Parâmetros oftalmológicos" — MOVIDO de Atendimento
        // (era item solto no menu) — pedido do usuário. Mesma lista
        // replicada nos 9 controllers do grupo (cada aba navega para as
        // demais); posição no fim, depois de Lentes — ver docblock de
        // BaseSettingController::$tabsGroup.
        $this->tabsGroup = [
            ['route' => 'panel.setting.skintypes.index', 'label' => __('actions.sidemenu.skintypes')],
            ['route' => 'panel.setting.iristypes.index', 'label' => __('actions.sidemenu.iristypes')],
            ['route' => 'panel.setting.additiontypes.index', 'label' => __('actions.sidemenu.additiontypes')],
            ['route' => 'panel.setting.visualacuitytypes.index', 'label' => __('actions.sidemenu.visualacuitytypes')],
            ['route' => 'panel.setting.colorvisiontypes.index', 'label' => __('actions.sidemenu.colorvisiontypes')],
            ['route' => 'panel.setting.nearpointconvergences.index', 'label' => __('actions.sidemenu.nearpointconvergences')],
            ['route' => 'panel.setting.covertesttypes.index', 'label' => __('actions.sidemenu.covertesttypes')],
            ['route' => 'panel.setting.lenses.index', 'label' => __('actions.sidemenu.lenses')],
            ['route' => 'panel.setting.surgerytypes.index', 'label' => __('actions.sidemenu.surgerytypes')],
        ];
    }

    protected function getColumns(): array
    {
        return [
            ['key' => 'code', 'label' => __('actions.code'), 'type' => 'code'],
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'sortable' => true],
            ['key' => 'category_label', 'label' => __('actions.category'), 'type' => 'text'],
        ];
    }

    protected function getFormFields(): array
    {
        return [
            ['key' => 'name', 'label' => __('actions.name'), 'type' => 'text', 'required' => true],
            ['key' => 'category', 'label' => __('actions.category'), 'type' => 'select', 'required' => true, 'options' => $this->categoryOptions()],
        ];
    }

    /**
     * Adiciona o label da categoria à serialização (não exibimos só o índice 0-N).
     */
    protected function serializeRecord(Model $record): array
    {
        $data                   = parent::serializeRecord($record);
        $cat                    = $record->category;
        $data['category_label'] = $cat !== null ? (SurgeryType::$categories[$cat] ?? '—') : '—';

        return $data;
    }

    /**
     * @return list<array{value: int|string, label: string}>
     */
    private function categoryOptions(): array
    {
        $out = [];

        foreach (SurgeryType::$categories as $value => $label) {
            $out[] = ['value' => (int) $value, 'label' => $label];
        }

        return $out;
    }

    public function store(SurgeryTypeRequest $request)
    {
        return $this->genericStore($request);
    }

    public function update(SurgeryTypeRequest $request, string $id)
    {
        return $this->genericUpdate($request, $id);
    }
}
