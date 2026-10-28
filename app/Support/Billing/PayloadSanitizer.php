<?php

namespace App\Support\Billing;

/**
 * Limpa as respostas dos gateways antes de gravar (invoices.raw_gateway_payload,
 * payments.raw_gateway_payload, payment_attempts.response_payload e
 * subscriptions.gateway_payload) — LGPD/PCI: nada do portador do cartão
 * (nome, CPF/CNPJ, BIN, validade) nem segredo de integração.
 *
 * Recursão manual (não array_walk_recursive, que só visita as folhas): uma
 * chave sensível cujo valor é um objeto inteiro (cardholder, holder,
 * payer.identification, billing_details, tax_id…) tem a subárvore toda
 * trocada por "***REDACTED***". O resto (ids, status, valores, Pix/boleto,
 * últimos 4 dígitos, bandeira) fica — o checkout e a conciliação usam.
 */
final class PayloadSanitizer
{
    public const REDACTED = '***REDACTED***';

    /** Chaves (minúsculas, sem "_"/"-") cujo valor — folha ou subárvore — é removido. */
    private const SENSITIVE = [
        // Cartão: número, CVV, BIN, validade e o token de uso único.
        'cardnumber', 'numbercard', 'cvv', 'cardcvv', 'cvc', 'securitycode', 'cardtoken', 'encrypted', 'encryptedcard',
        'expirationmonth', 'expirationyear', 'expmonth', 'expyear', 'expirationdate', 'firstsixdigits', 'firstdigits', 'bin',
        // Portador do cartão e documento do pagador.
        'cardholder', 'cardholdername', 'holder', 'holdername', 'holderdocument', 'cardholderinfo', 'creditcardholderinfo',
        'identification', 'billingdetails', 'taxid', 'taxids', 'document', 'documents', 'documentnumber', 'documenttype',
        'cpf', 'cnpj', 'cpfcnpj', 'nationalregistration', 'birthdate', 'birthday',
        // Segredos de integração.
        'clientsecret', 'password', 'secret', 'accesstoken', 'apikey', 'authorization',
    ];

    /**
     * Dados pessoais do pagador que o webhook bruto traz e o reprocessamento
     * não usa (webhook_events.payload — cleanPersonal): nome, e-mail,
     * telefone, endereço, IP e o token do cartão salvo. Ids, status,
     * valores, datas, referências e links ficam (os parsers de cada gateway
     * só leem esses — ver WebhookPayloadReprocessTest).
     */
    private const PERSONAL = [
        'name', 'firstname', 'lastname', 'fullname', 'givenname', 'familyname', 'socialname', 'legalname', 'tradename', 'companyname',
        'customername', 'payername', 'buyername', 'billingname',
        'email', 'emails', 'emailaddress', 'customeremail', 'payeremail', 'receiptemail',
        'phone', 'phones', 'phonenumber', 'mobilephone', 'homephone', 'cellphone', 'telephone', 'mobile', 'customerphone', 'areacode',
        'address', 'addresses', 'billingaddress', 'shippingaddress', 'customeraddress', 'shipping', 'customershipping',
        'street', 'streetname', 'streetnumber', 'addressnumber', 'line1', 'line2', 'complement', 'neighborhood', 'district', 'province',
        'zipcode', 'zip', 'postalcode', 'postcode', 'cep',
        'customertaxids', 'customerdetails', 'payerdetails',
        'ip', 'ipaddress', 'clientip', 'remoteip',
        'creditcardtoken',
    ];

    /** Chaves que, quando vêm como objeto (não como id), são o cadastro do pagador. */
    private const PERSON_OBJECTS = ['customer', 'payer', 'buyer', 'holder', 'owner'];

    /**
     * Headers do webhook que carregam segredo (credencial do webhook) e nunca
     * são gravados em webhook_events.headers: Basic Auth do Pagar.me
     * (usuário:senha), token do Asaas, token do PagBank, cookie e afins.
     * Assinaturas HMAC (x-signature, stripe-signature…) ficam — não revelam
     * o segredo. Ponto único: ingestão (WebhookIngestionService) e limpeza
     * dos já gravados (billing:sanitize-webhook-events).
     */
    private const SECRET_HEADERS = [
        'authorization',
        'proxy-authorization',
        'asaas-access-token',
        'access_token',
        'cookie',
    ];

    /**
     * Headers sem os que carregam segredo (SECRET_HEADERS), sem diferenciar
     * maiúsculas.
     *
     * @param array<array-key, mixed> $headers
     *
     * @return array<array-key, mixed>
     */
    public static function storableHeaders(array $headers): array
    {
        return array_filter(
            $headers,
            fn ($value, $key) => ! in_array(strtolower((string) $key), self::SECRET_HEADERS, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public static function clean(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return $clean;
    }

    /**
     * clean() + dados pessoais (PERSONAL e o objeto do pagador): para guardar
     * o corpo bruto de um webhook (webhook_events.payload) só com o que o
     * reprocessamento usa. A assinatura (HMAC/token) é conferida antes, no
     * corpo bruto em memória — nunca neste.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public static function cleanPersonal(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized = self::normalize($key);

                if (self::isSensitive($key) || in_array($normalized, self::PERSONAL, true)
                    || (is_array($value) && in_array($normalized, self::PERSON_OBJECTS, true))) {
                    $clean[$key] = self::REDACTED;

                    continue;
                }
            }

            $clean[$key] = is_array($value) ? self::cleanPersonal($value) : $value;
        }

        return $clean;
    }

    /** Aceita null (coluna vazia) e devolve null. */
    public static function cleanNullable(mixed $payload): mixed
    {
        return is_array($payload) ? self::clean($payload) : $payload;
    }

    private static function isSensitive(string $key): bool
    {
        return in_array(self::normalize($key), self::SENSITIVE, true);
    }

    private static function normalize(string $key): string
    {
        return str_replace(['_', '-', ' '], '', strtolower($key));
    }
}
