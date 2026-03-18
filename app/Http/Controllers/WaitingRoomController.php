<?php

namespace App\Http\Controllers;

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\Schedule;
use Illuminate\Http\Request;

class WaitingRoomController extends Controller
{
    public function index()
    {
        $meta = [
            'title'       => 'Sala de espera',
            'action'      => 'Sala de espera',
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'),          'active' => false],
                ['label' => 'Sala de espera',                 'url' => route('panel.waiting-room.index'), 'active' => true],
            ],
        ];

        return view('system.waiting-room.index', compact('meta'));
    }

    public function ajaxList(Request $request)
    {
        $entityId     = session('selected_entity_id');
        $entityUserId = session('selected_entity_user_id');
        $isDoctor     = session('user_rule') === ClientRule::Doctor->value;

        $query = Schedule::query()
            ->with(['doctor.person', 'patient.person', 'visitType', 'covenant'])
            ->whereDate('date_time', today())
            ->where('entity_id', $entityId)
            ->whereIn('situation', [
                ScheduleSituation::Waiting->value,
                ScheduleSituation::InProgress->value,
            ])
            ->orderBy('arrived_at');

        if ($isDoctor) {
            $query->whereHas('doctor', fn ($q) => $q->where('entity_user_id', $entityUserId));
        }

        $schedules = $query->get();

        return view('system.waiting-room._list', compact('schedules'));
    }
}
