<?php

declare(strict_types=1);

use App\Enums\Surgery\AuthorizationStatus;
use App\Events\Surgery\AuthorizationEventRecorded;
use App\Jobs\Surgery\SendSurgeryAuthorizationNoticeJob;
use App\Listeners\Surgery\QueueAuthorizationNotice;
use App\Models\Surgery\{SurgeryAuthorizationNotice, SurgeryClinicSetting, SurgicalAuthorizationEvent, SurgicalCase};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Services\Surgery\{SurgeryAuthorizationNotifier, SurgeryAuthorizationService, SurgicalCaseService};
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\TemplateMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\{Log, Queue};

/*
 * Módulo cirúrgico (Fase 4) / M3a — aviso NEUTRO ao paciente quando a
 * operadora responde o pedido: kill-switch por clínica (desligado de fábrica),
 * opt-in do canal, SAIR, revalidação no envio, um aviso por evento e falha de
 * envio que nunca afeta a autorização. Transporte: driver mock do WhatsApp.
 */

beforeEach(function () {
    config()->set('whatsapp.driver', 'mock');

    $this->w = surgeryWorld();
    $this->w->patient->person->update(['cellphone' => '61999998888', 'whatsapp' => true]);

    $this->setting = WhatsAppSetting::create([
        'entity_id'     => $this->w->entity->id, 'app_id' => 'clinic-app-1',
        'webhook_token' => 'tok-' . str_repeat('a', 40), 'webhook_secret' => 'segredo', 'active' => true,
    ]);

    SurgeryClinicSetting::query()->create(['entity_id' => $this->w->entity->id, 'authorization_notice_enabled' => true]);
});

function sanRequested(object $w, array $over = []): SurgicalCase
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, array_merge([
        'patient_id' => (string) $w->patient->id, 'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'      => 'Facoemulsificação com LIO OLHO DIREITO', 'eye' => 'od',
        'procedures' => [['description' => 'Facoemulsificação com implante de LIO', 'tuss_code' => '30306019']],
    ], $over));

    return app(SurgeryAuthorizationService::class)->request($case);
}

function sanAuthorize(SurgicalCase $case, string $type = 'authorized', array $over = []): SurgicalCase
{
    return app(SurgeryAuthorizationService::class)->record($case, array_merge(
        $type === 'denied'
            ? ['type' => 'denied', 'denial_reason' => 'Fora da cobertura contratual']
            : ['type' => $type, 'authorization_number' => 'AUT-998877'],
        $over,
    ));
}

function sanReason(SurgicalCase $case): ?string
{
    return SurgeryAuthorizationNotice::query()->where('surgical_case_id', $case->id)->value('skip_reason');
}

function sanNotices(): int
{
    return SurgeryAuthorizationNotice::query()->withoutGlobalScopes()->count();
}

function sanMessages()
{
    return WhatsAppMessage::query()->where('kind', SurgeryAuthorizationNotifier::KIND);
}

it('resposta da operadora: um aviso neutro enviado ao celular do paciente', function () {
    $case = sanRequested($this->w);

    sanAuthorize($case);

    $message = sanMessages()->firstOrFail();
    $notice  = SurgeryAuthorizationNotice::query()->firstOrFail();

    expect($message->status)->toBe(WhatsAppMessage::STATUS_SENT)
        ->and($message->phone)->toBe('5561999998888')
        ->and($message->schedule_id)->toBeNull()
        ->and($message->template)->toBe('easyeye_cirurgia_aviso')
        ->and($notice->status)->toBe(SurgeryAuthorizationNotice::STATUS_QUEUED)
        ->and((string) $notice->whatsapp_message_id)->toBe((string) $message->id);
});

it('o texto é neutro: nunca cita cirurgia, olho, número, motivo nem se foi autorizado ou negado', function () {
    $authorized = sanRequested($this->w);
    sanAuthorize($authorized);

    $denied = sanRequested($this->w, ['title' => 'Vitrectomia OLHO ESQUERDO', 'eye' => 'oe']);
    sanAuthorize($denied, 'denied');

    expect(sanMessages()->count())->toBe(2);

    foreach (sanMessages()->get() as $message) {
        $text = mb_strtolower($message->body . ' ' . json_encode($message->payload));

        expect($text)->toContain('paciente')->toContain(mb_strtolower($this->w->entity->name))
            ->not->toContain('facoemuls')->not->toContain('vitrectomia')->not->toContain('olho')
            ->not->toContain('aut-998877')->not->toContain('cobertura')->not->toContain('autoriz')->not->toContain('negad')
            ->not->toContain('30306019')->not->toContain('lio');
    }

    // Os dois avisos têm o MESMO texto: o resultado não vaza pela mensagem.
    expect(sanMessages()->pluck('body')->unique())->toHaveCount(1);
});

it('desligado de fábrica: sem o kill-switch da clínica nada é enfileirado nem registrado', function () {
    Queue::fake();
    SurgeryClinicSetting::query()->delete();

    sanAuthorize(sanRequested($this->w));

    Queue::assertNothingPushed();
    expect(sanNotices())->toBe(0)->and(sanMessages()->count())->toBe(0);
});

it('só os eventos de resposta avisam: parcial e negada sim; pedido, envio, dispensa e nota não', function () {
    $partial = sanRequested($this->w, ['needs_opme' => true, 'materials' => [['description' => 'Anel', 'quantity' => 2, 'technical_justification' => 'Subluxação']]]);
    sanAuthorize($partial, 'partially_authorized', ['authorized_items' => [
        ['kind' => 'procedure', 'id' => (string) $partial->procedures->first()->id, 'quantity' => 1],
        ['kind' => 'material', 'id' => (string) $partial->materials->first()->id, 'quantity' => 1],
    ]]);

    $denied = sanRequested($this->w);
    sanAuthorize($denied, 'denied');

    $other = sanRequested($this->w);
    app(SurgeryAuthorizationService::class)->record($other, ['type' => 'submitted', 'protocol_number' => 'P1']);
    app(SurgeryAuthorizationService::class)->record($other, ['type' => 'note', 'note' => 'Liguei para a operadora']);
    app(SurgeryAuthorizationService::class)->record($other, ['type' => 'waived', 'note' => 'Virou particular']);

    expect(sanMessages()->count())->toBe(2)->and(sanNotices())->toBe(2);
});

it('é idempotente por evento: reentrega do job ou do ouvinte nunca manda duas mensagens', function () {
    $case = sanRequested($this->w);
    sanAuthorize($case);
    $event = SurgicalAuthorizationEvent::query()->where('type', 'authorized')->firstOrFail();

    expect(app(SurgeryAuthorizationNotifier::class)->notify((string) $event->id))->toBeNull();
    SendSurgeryAuthorizationNoticeJob::dispatchSync((string) $event->id);
    event(new AuthorizationEventRecorded($case->fresh(), $event));

    expect(sanMessages()->count())->toBe(1)->and(sanNotices())->toBe(1);
});

it('paciente sem WhatsApp marcado, com número inválido ou inativo não recebe', function () {
    $this->w->patient->person->update(['whatsapp' => false]);
    $a = sanAuthorize(sanRequested($this->w));
    expect(sanReason($a))->toBe('no_whatsapp');

    $this->w->patient->person->update(['whatsapp' => true, 'cellphone' => '123']);
    $b = sanAuthorize(sanRequested($this->w));
    expect(sanReason($b))->toBe('no_whatsapp');

    $this->w->patient->person->update(['cellphone' => '61999998888']);
    $this->w->patient->update(['active' => false]);
    $c = sanAuthorize(sanRequested($this->w));
    expect(sanReason($c))->toBe('patient_inactive');

    expect(sanMessages()->count())->toBe(0);
});

it('quem respondeu SAIR não recebe; WhatsApp inativo ou ausente também não', function () {
    WhatsAppOptOut::query()->create(['whatsapp_setting_id' => $this->setting->id, 'phone' => '5561999998888', 'source' => 'keyword', 'opted_out_at' => now()]);
    $a = sanAuthorize(sanRequested($this->w));
    expect(sanReason($a))->toBe('opted_out');

    WhatsAppOptOut::query()->delete();
    $this->setting->update(['active' => false]);
    $b = sanAuthorize(sanRequested($this->w));
    expect(sanReason($b))->toBe('whatsapp_unavailable');

    expect(sanMessages()->count())->toBe(0);
});

it('clínica sem o recurso has_surgery no plano não manda aviso', function () {
    $w = surgeryWorld(withModule: false);
    $w->patient->person->update(['cellphone' => '61999998888', 'whatsapp' => true]);
    WhatsAppSetting::create(['entity_id' => $w->entity->id, 'app_id' => 'app-2', 'webhook_token' => 'tok-' . str_repeat('b', 40), 'webhook_secret' => 's', 'active' => true]);
    SurgeryClinicSetting::query()->create(['entity_id' => $w->entity->id, 'authorization_notice_enabled' => true]);

    sanAuthorize(sanRequested($w));

    expect(SurgeryAuthorizationNotice::query()->withoutGlobalScopes()->where('entity_id', $w->entity->id)->value('skip_reason'))->toBe('feature_off')
        ->and(WhatsAppMessage::query()->where('entity_id', $w->entity->id)->count())->toBe(0);
});

it('revalida no envio: desligar o aviso, superar o evento ou cancelar o caso antes de a fila rodar corta o aviso', function () {
    Queue::fake();

    $case = sanRequested($this->w);
    sanAuthorize($case);
    $event = SurgicalAuthorizationEvent::query()->where('type', 'authorized')->firstOrFail();
    Queue::assertPushed(SendSurgeryAuthorizationNoticeJob::class);

    // 1) kill-switch desligado depois de enfileirar.
    SurgeryClinicSetting::query()->update(['authorization_notice_enabled' => false]);
    (new SendSurgeryAuthorizationNoticeJob((string) $event->id))->handle(app(SurgeryAuthorizationNotifier::class));
    expect(SurgeryAuthorizationNotice::query()->first()->skip_reason)->toBe('disabled');

    // 2) evento superado: a autorização venceu antes de o job rodar.
    SurgeryAuthorizationNotice::query()->delete();
    SurgeryClinicSetting::query()->update(['authorization_notice_enabled' => true]);
    $case->forceFill(['authorization_status' => AuthorizationStatus::Expired])->save();
    (new SendSurgeryAuthorizationNoticeJob((string) $event->id))->handle(app(SurgeryAuthorizationNotifier::class));
    expect(SurgeryAuthorizationNotice::query()->first()->skip_reason)->toBe('event_superseded');

    // 3) caso cancelado.
    SurgeryAuthorizationNotice::query()->delete();
    $case->forceFill(['authorization_status' => AuthorizationStatus::Authorized])->save();
    app(SurgicalCaseService::class)->cancel($case->fresh(), 'Paciente desistiu');
    (new SendSurgeryAuthorizationNoticeJob((string) $event->id))->handle(app(SurgeryAuthorizationNotifier::class));
    expect(SurgeryAuthorizationNotice::query()->first()->skip_reason)->toBe('case_cancelled');

    expect(sanMessages()->count())->toBe(0);
});

it('falha do transporte nunca desfaz nem trava a autorização', function () {
    $failing = new class() implements WhatsAppProvider {
        public function name(): string
        {
            return 'fail';
        }

        public function authorize(WhatsAppSetting $setting): array
        {
            return ['ok' => true];
        }

        public function sendTemplate(WhatsAppSetting $setting, string $phone, TemplateMessage $template): array
        {
            throw new RuntimeException('falha no envio para 5561999998888 de MARIA');
        }

        public function sendSessionText(WhatsAppSetting $setting, string $phone, string $text): array
        {
            return ['ok' => false];
        }

        public function health(WhatsAppSetting $setting): array
        {
            return ['ok' => true];
        }

        public function subscribeWebhook(WhatsAppSetting $setting, string $url, string $secret): array
        {
            return ['ok' => true];
        }
    };
    app()->instance(WhatsAppProvider::class, $failing);
    Log::spy();

    $case = sanAuthorize(sanRequested($this->w));

    expect($case->status->value)->toBe('authorized')
        ->and($case->authorization_number)->toBe('AUT-998877')
        ->and(SurgeryAuthorizationNotice::query()->count())->toBe(1);

    // Nada de telefone/nome nos logs do módulo.
    Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
        return str_starts_with((string) $message, 'surgery.authorization_notice') && ! str_contains(json_encode($context), '5561999998888') && ! str_contains(json_encode($context), 'MARIA');
    })->atLeast()->once();
});

it('o aviso de outra clínica não vaza: cada evento só olha o paciente do próprio caso', function () {
    $w2 = surgeryWorld();
    $w2->patient->person->update(['cellphone' => '61911112222', 'whatsapp' => true]);
    WhatsAppSetting::create(['entity_id' => $w2->entity->id, 'app_id' => 'app-3', 'webhook_token' => 'tok-' . str_repeat('c', 40), 'webhook_secret' => 's', 'active' => true]);
    SurgeryClinicSetting::query()->create(['entity_id' => $w2->entity->id, 'authorization_notice_enabled' => true]);

    sanAuthorize(sanRequested($w2));

    $message = sanMessages()->firstOrFail();

    expect((string) $message->entity_id)->toBe((string) $w2->entity->id)->and($message->phone)->toBe('5561911112222')
        ->and(sanMessages()->where('entity_id', $this->w->entity->id)->count())->toBe(0);
});

it('a fila leva só o id do evento: nenhum model (nem dado do paciente) é serializado', function () {
    Queue::fake();

    sanAuthorize(sanRequested($this->w));

    Queue::assertPushed(SendSurgeryAuthorizationNoticeJob::class, function (SendSurgeryAuthorizationNoticeJob $job) {
        $payload = serialize($job);

        return is_string($job->eventId)
            && ! str_contains($payload, 'PACIENTE')
            && ! str_contains($payload, 'AUT-998877')
            && ! str_contains($payload, '5561999998888')
            && ! str_contains($payload, 'App\\Models\\Surgery');
    });

    // O ouvinte é síncrono e leve: não implementa ShouldQueue (não serializa o evento).
    expect(in_array(ShouldQueue::class, class_implements(QueueAuthorizationNotice::class), true))->toBeFalse();
});
