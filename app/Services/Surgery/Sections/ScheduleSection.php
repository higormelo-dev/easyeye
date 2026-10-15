<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\Surgery\{SurgeryFacility, SurgicalCase};
use App\Services\Surgery\SurgicalCaseView;

/** Aba "Agenda": janela atual da cirurgia e as ações de agendar, reagendar e liberar a vaga. */
final class ScheduleSection implements SurgeryShowSection
{
    public function key(): string
    {
        return 'schedule';
    }

    public function order(): int
    {
        return 30;
    }

    public function label(): string
    {
        return (string) __('surgery_schedule.tab');
    }

    public function visible(SurgicalCase $case, array $can): bool
    {
        return ! in_array($case->status, [SurgicalCaseStatus::Draft, SurgicalCaseStatus::Cancelled], true);
    }

    public function props(SurgicalCase $case, array $can): array
    {
        $case->loadMissing(['facility', 'room']);

        $status      = $case->status;
        $authorized  = in_array($status, [SurgicalCaseStatus::Authorized, SurgicalCaseStatus::PartiallyAuthorized], true);
        $schedulable = $authorized && $case->authorization_status->allowsScheduling();
        $auth        = SurgicalCaseView::authorization($case);

        // Autorização que vence antes (ou no dia) da cirurgia já agendada: alerta na aba.
        $expiresBeforeWindow = $case->scheduled_start !== null
            && $case->authorization_valid_until !== null
            && $case->authorization_valid_until->toDateString() < $case->scheduled_start->toDateString();

        return [
            'window' => $case->scheduled_start === null ? null : [
                'start'    => $case->scheduled_start->toIso8601String(),
                'end'      => $case->scheduled_end?->toIso8601String(),
                'facility' => $case->facility?->name,
                'room'     => $case->room?->name,
            ],
            'authorization'        => $auth,
            'expires_before'       => $expiresBeforeWindow,
            'authorization_blocks' => $authorized && ! $schedulable,
            'can_schedule'         => $can['manage'] && $schedulable,
            'can_reschedule'       => $can['manage'] && $status === SurgicalCaseStatus::Scheduled,
            'can_release'          => $can['manage'] && $status === SurgicalCaseStatus::Scheduled,
            'duration'             => (int) ($case->estimated_duration_minutes ?: config('surgery.default_duration', 60)),
            'facility_id'          => $case->surgery_facility_id,
            'room_id'              => $case->surgery_room_id,
            'facilities'           => SurgeryFacility::query()
                ->where('entity_id', $case->entity_id)
                ->where('active', true)
                ->with(['rooms' => fn ($q) => $q->where('active', true)->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->map(fn (SurgeryFacility $f) => [
                    'id'    => (string) $f->getKey(),
                    'name'  => $f->name,
                    'rooms' => $f->rooms->map(fn ($r) => ['id' => (string) $r->getKey(), 'name' => $r->name])->all(),
                ])->all(),
            'urls' => [
                'store'      => route('panel.surgery.schedule.store', $case),
                'update'     => route('panel.surgery.schedule.update', $case),
                'destroy'    => route('panel.surgery.schedule.destroy', $case),
                'check'      => route('panel.surgery.schedule.check', $case),
                'agenda'     => route('panel.surgery.agenda'),
                'facilities' => route('panel.setting.surgery-facilities.index'),
            ],
        ];
    }
}
