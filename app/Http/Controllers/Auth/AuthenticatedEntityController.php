<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SelectEntityRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedEntityController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        $entities    = [];
        $entityUsers = Auth::user()->entityUsers->where('active', true);
        $entityUsers->load('entity');

        if (count($entityUsers) > 1) {
            foreach ($entityUsers as $entityUser) {
                $entityUserRule = '';

                if (app()->environment(['local', 'testing'])) {
                    $entityUserRule .= $entityUser->rule === 'admin' ? '*' : '';
                }

                $entities[$entityUser->id] = trim(sprintf('%s %s', $entityUser->entity->name, $entityUserRule));
            }
        }

        return view('auth.select-entity', compact('entities'));
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
