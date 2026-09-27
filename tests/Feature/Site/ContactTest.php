<?php

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\{Event, Log, Mail};
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;

beforeEach(function () {
    // Exercise the real rendering pipeline, capturing mail in memory only.
    config(['mail.mailers.smtp' => ['transport' => 'array'], 'mail.contact_address' => 'contato@easyeye.app']);
    $this->contact = [
        'name'      => 'José 山田 👁',
        'email'     => 'visitor@example.test',
        'phone'     => '(11) 99999-9999',
        'is_client' => 'Não',
        'role'      => 'Outro',
        'segment'   => 'Consultório individual',
        'message'   => "Quero conhecer o EasyEye.\nPodemos conversar?",
        'terms'     => true,
    ];
    Log::spy();
});

it('envia dados validados ao destinatário configurado sem gravar dados pessoais no log', function () {
    // Even when the app default is log, contact uses the explicit SMTP mailer.
    config(['mail.default' => 'log']);
    $this->postJson(route('contact.store'), $this->contact + ['entity_id' => 'untrusted', 'to' => 'other@example.test'])
        ->assertOk()->assertExactJson(['ok' => true]);

    $messages = Mail::mailer('smtp')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $message = $messages->first()->getOriginalMessage();
    expect($message->getTo()[0]->getAddress())->toBe('contato@easyeye.app')
        ->and($message->getReplyTo()[0]->getAddress())->toBe($this->contact['email'])
        ->and($message->getHtmlBody())->toContain($this->contact['name'], $this->contact['message'])
        ->not->toContain('untrusted', 'other@example.test');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('error');
});

it('rejeita campos inválidos sem enviar e-mail', function (string $field, mixed $value) {
    $this->postJson(route('contact.store'), array_replace($this->contact, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Mail::mailer('smtp')->getSymfonyTransport()->messages())->toHaveCount(0);
})->with([
    ['name', ''], ['name', str_repeat('a', 121)], ['email', 'invalid'], ['email', ['unexpected']],
    ['phone', ''], ['phone', str_repeat('1', 31)], ['message', ''], ['message', '   '],
    ['message', str_repeat('a', 5001)], ['message', ['unexpected']], ['terms', false], ['terms', null],
    ['is_client', str_repeat('a', 61)], ['role', str_repeat('a', 81)], ['segment', str_repeat('a', 81)],
]);

it('aceita os limites e escapa conteúdo HTML fornecido pelo visitante', function () {
    $data = array_replace($this->contact, ['name' => str_repeat('á', 120), 'message' => '<script>alert(1)</script>' . str_repeat('a', 4975)]);
    $this->postJson(route('contact.store'), $data)->assertOk();
    $html = Mail::mailer('smtp')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->not->toContain('<script>');
});

it('retorna 503 quando o transporte falha sem expor detalhes nem registrar o conteúdo', function () {
    $transport = Mockery::mock(TransportInterface::class);
    $transport->shouldReceive('send')->once()->andThrow(new TransportException('Private SMTP diagnostics: ' . $this->contact['email']));
    Mail::mailer('smtp')->setSymfonyTransport($transport);

    $this->postJson(route('contact.store'), $this->contact)
        ->assertStatus(503)
        ->assertExactJson(['ok' => false, 'message' => __('site.contact.form.errors.server')]);

    Log::shouldHaveReceived('error')->once()->with('contact_form_delivery_failed', ['exception_type' => TransportException::class]);
    Log::shouldNotHaveReceived('info');
});

it('não confirma um envio cancelado por um listener', function () {
    Event::listen(MessageSending::class, fn () => false);
    $this->postJson(route('contact.store'), $this->contact)->assertStatus(503)->assertJsonPath('ok', false);
    expect(Mail::mailer('smtp')->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('localiza os erros de validação e o e-mail no idioma da sessão', function (string $locale) {
    $this->withSession(['locale' => $locale])
        ->postJson(route('contact.store'), array_replace($this->contact, ['message' => '']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.message.0', __('validation.required', ['attribute' => __('site.contact.form.message')]));

    $this->postJson(route('contact.store'), $this->contact)->assertOk();
    $message = Mail::mailer('smtp')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    expect($message->getSubject())->toBe(__('site.contact.form.mail_subject'));
})->with(['pt_BR', 'en']);

it('limita tentativas públicas e não envia a tentativa bloqueada', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('contact.store'), $this->contact)->assertOk();
    }
    $this->postJson(route('contact.store'), $this->contact)->assertStatus(429)->assertHeader('Retry-After');
    expect(Mail::mailer('smtp')->getSymfonyTransport()->messages())->toHaveCount(5);
});

it('mantém as mesmas traduções de contato nos dois idiomas', function () {
    $pt = trans('site.contact.form', [], 'pt_BR');
    $en = trans('site.contact.form', [], 'en');
    expect(array_keys($pt))->toBe(array_keys($en))
        ->and(array_keys($pt['errors']))->toBe(array_keys($en['errors']));
});
