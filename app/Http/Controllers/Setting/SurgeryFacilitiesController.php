<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Enums\Surgery\SurgeryFacilityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Surgery\{SurgeryFacilityRequest, SurgeryRoomRequest};
use App\Models\ClinicResource;
use App\Models\Surgery\{SurgeryFacility, SurgeryRoom};
use App\Services\Surgery\SurgeryFacilityService;
use App\Support\Surgery\SurgeryLang;
use Illuminate\Http\RedirectResponse;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Configurações > Cirurgia: locais (clínica própria, hospital, centro
 * cirúrgico) e suas salas. Rota atrás de `permission:settings.manage` +
 * `feature:has_surgery`; binding de local/sala sempre na clínica da sessão.
 */
class SurgeryFacilitiesController extends Controller
{
    public function __construct(private readonly SurgeryFacilityService $service)
    {
    }

    public function index(): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');

        return Inertia::render('Panel/Settings/SurgeryFacilities/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('surgery.facilities.title'), 'url' => '#', 'active' => true],
            ],
            'facilities' => SurgeryFacility::query()
                ->where('entity_id', $entityId)
                ->with(['rooms' => fn ($q) => $q->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->map(fn (SurgeryFacility $f) => [
                    'id'             => (string) $f->getKey(),
                    'name'           => $f->name,
                    'type'           => $f->type->value,
                    'type_label'     => $f->type->label(),
                    'cnes'           => $f->cnes,
                    'operator_code'  => $f->operator_code,
                    'phone'          => $f->phone,
                    'zip_code'       => $f->zip_code,
                    'address'        => $f->address,
                    'address_number' => $f->address_number,
                    'district'       => $f->district,
                    'city'           => $f->city,
                    'state'          => $f->state,
                    'notes'          => $f->notes,
                    'active'         => (bool) $f->active,
                    'rooms'          => $f->rooms->map(fn (SurgeryRoom $r) => [
                        'id'                 => (string) $r->getKey(),
                        'name'               => $r->name,
                        'active'             => (bool) $r->active,
                        'clinic_resource_id' => $r->clinic_resource_id,
                    ])->all(),
                ])->all(),
            'types'     => collect(SurgeryFacilityType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()])->all(),
            'resources' => ClinicResource::query()
                ->where('entity_id', $entityId)
                ->where('type', 'room')
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($r) => ['id' => (string) $r->getKey(), 'name' => $r->name])
                ->all(),
            'urls' => [
                'store'        => route('panel.setting.surgery-facilities.store'),
                'update'       => route('panel.setting.surgery-facilities.update', ['facility' => '__ID__']),
                'destroy'      => route('panel.setting.surgery-facilities.destroy', ['facility' => '__ID__']),
                'room_store'   => route('panel.setting.surgery-facilities.rooms.store', ['facility' => '__ID__']),
                'room_update'  => route('panel.setting.surgery-facilities.rooms.update', ['room' => '__ID__']),
                'room_destroy' => route('panel.setting.surgery-facilities.rooms.destroy', ['room' => '__ID__']),
            ],
            't' => SurgeryLang::all(),
        ]);
    }

    public function store(SurgeryFacilityRequest $request): RedirectResponse
    {
        $this->service->createFacility((string) session('selected_entity_id'), $request->validated());

        return back()->with('success', __('surgery.facilities.created'));
    }

    public function update(SurgeryFacilityRequest $request, SurgeryFacility $facility): RedirectResponse
    {
        $this->service->updateFacility($facility, $request->validated());

        return back()->with('success', __('surgery.facilities.updated'));
    }

    public function destroy(SurgeryFacility $facility): RedirectResponse
    {
        $this->service->deleteFacility($facility);

        return back()->with('success', __('surgery.facilities.deleted'));
    }

    public function storeRoom(SurgeryRoomRequest $request, SurgeryFacility $facility): RedirectResponse
    {
        $this->service->createRoom($facility, $request->validated());

        return back()->with('success', __('surgery.rooms.created'));
    }

    public function updateRoom(SurgeryRoomRequest $request, SurgeryRoom $room): RedirectResponse
    {
        $this->service->updateRoom($room, $request->validated());

        return back()->with('success', __('surgery.rooms.updated'));
    }

    public function destroyRoom(SurgeryRoom $room): RedirectResponse
    {
        $this->service->deleteRoom($room);

        return back()->with('success', __('surgery.rooms.deleted'));
    }
}
