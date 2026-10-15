<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Support;

use App\Http\Requests\Manager\CovenantRequest;
use App\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Identificação profissional de um Revisor (CRM/UF, RQE, e-mail), normalizada UMA vez:
 * é a mesma regra no cadastro, na unicidade e na comparação com o que o Revisor declara no
 * parecer — "12.345" e "012345" são o mesmo CRM, "Fulano@X.com " o mesmo e-mail.
 */
final class ReviewerIdentity
{
    /** @var list<string> */
    public const UFS = CovenantRequest::UFS;

    /** CRM e RQE: números, sem zeros à esquerda (o conselho não numera com zero à frente). */
    public static function number(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return ltrim($digits, '0');
    }

    public static function email(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    public static function uf(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    /** Texto livre: NFC, sem espaços nas pontas (UTF-8 inválido vira vazio — a validação recusa). */
    public static function text(?string $value): string
    {
        try {
            return trim(CanonicalJson::normalizeText((string) $value));
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    public static function phone(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{name: string, email: string, crm_number: string, crm_uf: string, rqe: string, specialty: string, phone: ?string, notes: ?string}
     */
    public static function normalize(array $data): array
    {
        $phone = self::phone($data['phone'] ?? null);
        $notes = self::text($data['notes'] ?? null);

        return [
            'name'       => self::text($data['name'] ?? null),
            'email'      => self::email($data['email'] ?? null),
            'crm_number' => self::number($data['crm_number'] ?? null),
            'crm_uf'     => self::uf($data['crm_uf'] ?? null),
            'rqe'        => self::number($data['rqe'] ?? null),
            'specialty'  => self::text($data['specialty'] ?? null) ?: 'Oftalmologia',
            'phone'      => $phone === '' ? null : $phone,
            'notes'      => $notes === '' ? null : $notes,
        ];
    }
}
