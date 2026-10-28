<?php

declare(strict_types=1);

namespace App\Support\Documents;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Data alternativa dos documentos do prontuário (Fase 2 / B3).
 *
 * O médico pode imprimir no documento uma data diferente de hoje, dentro da
 * faixa de config/clinical_documents.php (padrão: 30 dias antes e 30 depois).
 * A data de emissão de fato nunca muda — fica em created_at e na trilha de
 * auditoria. Regra única para receita, relatório, pedido de exames e
 * atestados (quick actions e editor de Documentações).
 */
final class DocumentDate
{
    /** Hoje no fuso da aplicação (a faixa é em dias de calendário). */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    public static function min(): CarbonImmutable
    {
        return self::today()->subDays(max(0, (int) config('clinical_documents.alternative_date.days_before', 30)));
    }

    public static function max(): CarbonImmutable
    {
        return self::today()->addDays(max(0, (int) config('clinical_documents.alternative_date.days_after', 30)));
    }

    /**
     * Regras de validação do campo (Y-m-d, opcional, dentro da faixa).
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'nullable',
            'date_format:Y-m-d',
            'after_or_equal:' . self::min()->toDateString(),
            'before_or_equal:' . self::max()->toDateString(),
        ];
    }

    /**
     * Mensagens das regras acima para o atributo informado (mostra a faixa).
     *
     * @return array<string, string>
     */
    public static function messages(string $attribute = 'document_date'): array
    {
        $range = __('clinical_documents.date.range_error', [
            'min' => self::label(self::min()),
            'max' => self::label(self::max()),
        ]);

        return [
            "{$attribute}.after_or_equal"  => $range,
            "{$attribute}.before_or_equal" => $range,
            "{$attribute}.date_format"     => __('clinical_documents.date.format_error'),
        ];
    }

    /**
     * Data a gravar como "data alternativa": null quando vazia ou igual a
     * hoje (não é alternativa — o documento sai com a data da emissão).
     */
    public static function alternative(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value), config('app.timezone'));

        if ($date === false || $date->isSameDay(self::today())) {
            return null;
        }

        return $date;
    }

    /**
     * Data efetiva do documento (a alternativa ou hoje) — usada nas variáveis
     * de data dos modelos ({{DATA_ATUAL}}, {{LOCAL_DATA}}, ...).
     */
    public static function effective(mixed $value): CarbonImmutable
    {
        return self::alternative($value) ?? self::today();
    }

    /** Faixa para a tela (o servidor revalida). */
    public static function clientConfig(): array
    {
        return [
            'today' => self::today()->toDateString(),
            'min'   => self::min()->toDateString(),
            'max'   => self::max()->toDateString(),
        ];
    }

    private static function label(CarbonImmutable $date): string
    {
        return Carbon::instance($date)->locale(app()->getLocale())->isoFormat('L');
    }
}
