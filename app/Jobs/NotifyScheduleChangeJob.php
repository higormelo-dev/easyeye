<?php

namespace App\Jobs;

use App\Models\Schedule;
use App\Notifications\ScheduleNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class NotifyScheduleChangeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string  $scheduleId,
        public readonly string  $event,
        public readonly ?string $extraInfo = null,
    ) {
    }

    public function handle(): void
    {
        $schedule = Schedule::with(['patient.person', 'doctor', 'entity'])
            ->find($this->scheduleId);

        if (!$schedule) {
            return;
        }

        $email = $schedule->patient?->person?->email;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $notification = new ScheduleNotification($schedule, $this->event, $this->extraInfo);

        (new AnonymousNotifiable())
            ->route('mail', [$email => $schedule->full_name])
            ->notify($notification);
    }
}
