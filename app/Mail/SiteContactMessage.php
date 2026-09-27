<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Address, Content, Envelope};

class SiteContactMessage extends Mailable
{
    public function __construct(public array $submission)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->submission['email'])],
            subject: __('site.contact.form.mail_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.site-contact');
    }
}
