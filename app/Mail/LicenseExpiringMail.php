<?php

namespace App\Mail;

use App\Domain\Licensing\Entities\License;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LicenseExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public License $license
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Mfuko Pro: Your License is Expiring Soon',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: "
                <h1>License Expiring</h1>
                <p>Hello {$this->license->tenant->name},</p>
                <p>Your <b>{$this->license->plan}</b> license is set to expire on <b>{$this->license->expires_at->format('Y-m-d')}</b>.</p>
                <p>Please renew your subscription to avoid any service interruption.</p>
            ",
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
