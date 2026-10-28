<?php

namespace App\DTOs\Billing;

use App\Support\Billing\PaymentUrl;

/**
 * Como pagar uma cobrança Pix ou boleto na tela do EasyEye.
 *
 * pix:    copy_paste (copia-e-cola / EMV), qr_code_base64 (PNG em base64, sem
 *         o prefixo data:), qr_image_url, expires_at (ISO 8601);
 * boleto: digitable_line (linha digitável), barcode (código de barras),
 *         pdf_url, due_date (Y-m-d).
 */
readonly class PaymentInstructionsDTO
{
    public function __construct(
        public string $method,
        public ?array $pix = null,
        public ?array $boleto = null,
        public ?string $paymentUrl = null,
    ) {
    }

    public static function pix(?string $copyPaste, ?string $qrBase64 = null, ?string $qrImageUrl = null, ?string $expiresAt = null, ?string $paymentUrl = null): ?self
    {
        $copyPaste = is_string($copyPaste) ? trim($copyPaste) : '';
        $qrBase64  = is_string($qrBase64) ? (string) preg_replace('#^data:image/[a-z+]+;base64,#i', '', trim($qrBase64)) : '';

        if ($copyPaste === '' && $qrBase64 === '' && blank($qrImageUrl)) {
            return null;
        }

        return new self(
            method: 'pix',
            pix: [
                'copy_paste'     => $copyPaste !== '' ? $copyPaste : null,
                'qr_code_base64' => $qrBase64 !== '' ? $qrBase64 : null,
                'qr_image_url'   => PaymentUrl::safe($qrImageUrl),
                'expires_at'     => $expiresAt,
            ],
            paymentUrl: PaymentUrl::safe($paymentUrl),
        );
    }

    public static function boleto(?string $digitableLine, ?string $barcode = null, ?string $pdfUrl = null, ?string $dueDate = null, ?string $paymentUrl = null): ?self
    {
        $digitableLine = is_string($digitableLine) ? trim($digitableLine) : '';
        $barcode       = is_string($barcode) ? trim($barcode) : '';
        $pdfUrl        = PaymentUrl::safe($pdfUrl);

        if ($digitableLine === '' && $barcode === '' && $pdfUrl === null) {
            return null;
        }

        return new self(
            method: 'boleto',
            boleto: [
                'digitable_line' => $digitableLine !== '' ? $digitableLine : null,
                'barcode'        => $barcode !== '' ? $barcode : null,
                'pdf_url'        => $pdfUrl,
                'due_date'       => $dueDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $dueDate) ? substr($dueDate, 0, 10) : null,
            ],
            paymentUrl: PaymentUrl::safe($paymentUrl) ?? $pdfUrl,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'method'      => $this->method,
            'pix'         => $this->pix,
            'boleto'      => $this->boleto,
            'payment_url' => $this->paymentUrl,
        ], fn ($value) => $value !== null);
    }
}
