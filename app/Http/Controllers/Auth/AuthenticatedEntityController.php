<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SelectEntityRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedEntityController extends Controller
{
    /**
     * Display the entity selection view.
     */
    public function create(): Response
    {
        $entityUsers = Auth::user()->entityUsers->where('active', true);
        $entityUsers->load('entity');

        $entities = $entityUsers->map(function ($entityUser) {
            return [
                'id'         => $entityUser->id,
                'name'       => $entityUser->entity->name,
                'code'       => $entityUser->entity->code,
                'rule_label' => $entityUser->rule,
            ];
        })->values()->toArray();

        return Inertia::render('Auth/SelectEntity', compact('entities'));
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(SelectEntityRequest $request)
    {
        $entityUser = Auth::user()->entityUsers()->with('entity')->find($request->entity_user_id);

        if ($entityUser) {
            session([
                'selected_entity_user_id'   => $entityUser->id,
                'selected_entity_user_rule' => $entityUser->rule,
                'selected_entity_id'        => $entityUser->entity->id,
                'selected_entity_is_client' => $entityUser->entity->is_client,
                'user_rule'                 => $entityUser->rule,
            ]);
        }

        if ($entityUser->rule === 'doctor') {
            session(['selected_entity_doctor_id' => $entityUser->doctor->id]);
        }

        return redirect()->intended(route('panel.dashboard', absolute: false));

    }
}
