<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\TvDisplaysDataTable;
use App\Http\Controllers\Controller;
use App\Models\TvDisplay;
use Illuminate\Http\{JsonResponse, Request};

class TvDisplaysController extends Controller
{
    public function index(Request $request, TvDisplaysDataTable $dataTable)
    {
        $entityId = session('selected_entity_id');

        $meta = [
            'title'       => 'Displays de TV',
            'action'      => 'Displays de TV',
            'breadcrumbs' => [
                [
                    'label'  => __('actions.sidemenu.dashboard'),
                    'url'    => route('panel.dashboard'),
                    'active' => false,
                ],
                [
                    'label'  => 'Displays de TV',
                    'url'    => route('panel.setting.tv-displays.index'),
                    'active' => true,
                ],
            ],
        ];

        return $dataTable
            ->forEntity($entityId)
            ->render('system.setting.tv-displays.index', compact('meta', 'entityId'));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $entityId = session('selected_entity_id');

        $tv = TvDisplay::create([
            'entity_id' => $entityId,
            'name'      => $request->input('name'),
            'status'    => TvDisplay::STATUS_ACTIVE,
            'token'     => TvDisplay::generateToken(),
            'active'    => true,
        ]);

        return response()->json([
            'message' => 'Display de TV criado com sucesso.',
            'data'    => array_merge($tv->toArray(), [
                'url' => route('tv.show', $tv->token),
            ]),
        ], 201);
    }

    public function approve(Request $request, TvDisplay $tvDisplay): JsonResponse
    {
        if ($tvDisplay->entity_id !== session('selected_entity_id')) {
            abort(403);
        }

        if (! $tvDisplay->isPending()) {
            return response()->json(['message' => 'Solicitação não está pendente.'], 422);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $tvDisplay->update([
            'name'   => $request->input('name'),
            'status' => TvDisplay::STATUS_ACTIVE,
            'token'  => TvDisplay::generateToken(),
            'pin'    => null,
            'active' => true,
        ]);

        return response()->json(['message' => 'Display aprovado com sucesso.']);
    }

    public function destroy(TvDisplay $tvDisplay): JsonResponse
    {
        if ($tvDisplay->entity_id !== session('selected_entity_id')) {
            abort(403);
        }

        $tvDisplay->update(['status' => TvDisplay::STATUS_REVOKED, 'active' => false]);
        $tvDisplay->delete();

        return response()->json(['message' => 'Display de TV removido com sucesso.']);
    }
}
