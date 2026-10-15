<?php

declare(strict_types=1);

namespace App\Services\Surgery\Documents;

use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase};
use App\Support\Surgery\CanonicalHash;

/**
 * O que o termo de consentimento (TCLE) AFIRMA sobre a cirurgia — o retrato que vai junto do documento
 * emitido e contra o qual a cópia assinada é conferida depois.
 *
 * Um TCLE assinado vale para a cirurgia que ele descreve. Se o pedido muda depois (o rascunho passa de OD para OE,
 * troca-se o procedimento, o cirurgião ou a anestesia), o papel assinado continua "vigente" mas já não cobre o
 * que vai ser feito: é preciso reemitir e colher nova assinatura. Fora disto de propósito: data, sala e local
 * (reagendar não pode invalidar o termo), título (rótulo) e a LIO planejada (potência e modelo se ajustam com a biometria).
 *
 * A anestesia só conta quando o termo a afirmava: em branco na emissão, ela é preenchida à mão no papel
 * (`{{anestesia}}` vira uma linha para preencher) e defini-la depois não desfaz a assinatura.
 */
final class ConsentBasis
{
    public const FIELDS = ['eye', 'procedures', 'surgeon', 'anesthesia'];

    /**
     * O retrato do caso AGORA (consulta fresca: o caso pode ter vindo de uma leitura antiga).
     *
     * @return array{eye: string, procedures: list<string>, surgeon_doctor_id: string, anesthesia: string|null}
     */
    public static function of(SurgicalCase $case): array
    {
        $procedures = $case->procedures()->get()
            ->map(fn ($procedure) => self::normalize(trim((string) $procedure->tuss_code) . '|' . $procedure->description))
            ->sort()
            ->values()
            ->all();

        return [
            'eye'               => $case->eye->value,
            'procedures'        => $procedures,
            'surgeon_doctor_id' => (string) $case->surgeon_doctor_id,
            'anesthesia'        => $case->anesthesia?->value,
        ];
    }

    /** @param array<string, mixed> $basis */
    public static function hash(array $basis): string
    {
        return CanonicalHash::of($basis);
    }

    /**
     * O que o termo emitido afirma e o caso de hoje contradiz (vazio = o termo cobre o pedido).
     *
     * @return list<string> subconjunto de FIELDS
     */
    public static function divergence(SurgeryDocumentIssue $consent, SurgicalCase $case): array
    {
        $stored  = (array) (($consent->payload['data'] ?? [])['consent_basis'] ?? []);
        $current = self::of($case);

        // Termo sem o retrato: o olho do cabeçalho congelado é o mínimo que sempre se confere.
        $eye = $stored['eye'] ?? ($consent->payload['header']['case']['eye'] ?? null);

        $diff = [];

        if (filled($eye) && (string) $eye !== $current['eye']) {
            $diff[] = 'eye';
        }

        if (is_array($stored['procedures'] ?? null) && array_values($stored['procedures']) !== $current['procedures']) {
            $diff[] = 'procedures';
        }

        if (filled($stored['surgeon_doctor_id'] ?? null) && (string) $stored['surgeon_doctor_id'] !== $current['surgeon_doctor_id']) {
            $diff[] = 'surgeon';
        }

        if (filled($stored['anesthesia'] ?? null) && (string) $stored['anesthesia'] !== (string) $current['anesthesia']) {
            $diff[] = 'anesthesia';
        }

        return $diff;
    }

    /** Rótulos (idioma atual) dos campos divergentes, para a mensagem. @param list<string> $fields */
    public static function labels(array $fields): string
    {
        return implode(', ', array_map(fn (string $field) => (string) __('surgery_documents.consent_fields.' . $field), $fields));
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
