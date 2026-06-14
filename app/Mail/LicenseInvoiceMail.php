<?php

namespace App\Mail;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LicenseInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $document  Pre-formatted invoice document data.
     */
    public function __construct(
        public array $document,
        public ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        $invoice = $this->document['invoice'];

        return new Envelope(
            subject: 'Invoice '.$invoice->invoice_number.' — '.$this->document['platform_name'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.license-invoice',
            with: [
                'document' => $this->document,
                'note' => $this->note,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = Pdf::loadView('pdf.license-invoice', $this->document)->setPaper('a4');
        $filename = $this->document['invoice']->invoice_number.'.pdf';

        return [
            Attachment::fromData(fn (): string => $pdf->output(), $filename)
                ->withMime('application/pdf'),
        ];
    }
}
