<?php

namespace App\Jobs;

use App\Models\Schedule;
use App\Notifications\ScheduleNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

/**
 * `extraInfo` é texto livre digitado pela clínica (motivo de cancelamento, até 2000 caracteres) e pode citar o
 * paciente: o payload viaja CIFRADO (ShouldBeEncrypted) no Redis e em failed_jobs, como o código de verificação.
 */
class NotifyScheduleChangeJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string $scheduleId,
        public readonly string $event,
        public readonly ?string $extraInfo = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $schedule = Schedule::with(['patient.person', 'doctor', 'entity'])
            ->find($this->scheduleId);

        if (! $schedule) {
            return;
        }

        $email = $schedule->patient?->person?->email;

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $notification = new ScheduleNotification($schedule, $this->event, $this->extraInfo);

        (new AnonymousNotifiable())
            ->route('mail', [$email => $schedule->full_name])
            ->notify($notification);
    }
}
