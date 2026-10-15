<?php

declare(strict_types=1);

namespace App\Services\Surgery\Documents;

use App\Enums\Surgery\SurgeryDocumentType;
use App\Models\SurgeryType;

/**
 * Catálogo dos modelos padrão de TCLE e de orientações pré-operatórias, por
 * categoria de cirurgia (SurgeryType::$categories), em pt_BR e en.
 *
 * O conteúdo vive em database/data/surgery_templates/<locale>.php (texto de
 * documento, não de interface — não é carregado nas telas). Aqui só a
 * composição em HTML. As variáveis {{...}} ficam no texto e são resolvidas na
 * emissão por SurgeryTemplateVariables. Chave estável de cada modelo
 * (`consent.cat1`, `pre_op.cat1`) é o que torna a adoção idempotente.
 */
final class SurgeryDefaultTemplates
{
    public const LOCALES = ['pt_BR', 'en'];

    /** pt_BR para qualquer idioma que não seja inglês. */
    public static function resolveLocale(?string $locale): string
    {
        return str_starts_with(strtolower((string) $locale), 'en') ? 'en' : 'pt_BR';
    }

    /**
     * @return list<array{key: string, type: string, category: int, name: string, body: string}>
     */
    public static function catalog(?string $locale = null): array
    {
        $data = self::data(self::resolveLocale($locale));
        $out  = [];

        foreach ($data['categories'] as $category => $texts) {
            $category = (int) $category;

            $out[] = [
                'key'      => self::key(SurgeryDocumentType::Consent, $category),
                'type'     => SurgeryDocumentType::Consent->value,
                'category' => $category,
                'name'     => strtr($data['consent_name'], [':category' => $texts['name']]),
                'body'     => self::consentBody($data, $texts),
            ];

            $out[] = [
                'key'      => self::key(SurgeryDocumentType::PreOpGuidance, $category),
                'type'     => SurgeryDocumentType::PreOpGuidance->value,
                'category' => $category,
                'name'     => strtr($data['preop_name'], [':category' => $texts['name']]),
                'body'     => self::preOpBody($data, $texts),
            ];
        }

        return $out;
    }

    /** @return list<string> chaves de todos os modelos padrão. */
    public static function keys(): array
    {
        return array_column(self::catalog('pt_BR'), 'key');
    }

    public static function key(SurgeryDocumentType $type, int $category): string
    {
        return ($type === SurgeryDocumentType::Consent ? 'consent' : 'pre_op') . '.cat' . $category;
    }

    /** Categorias cobertas pelo catálogo. @return list<int> */
    public static function categories(): array
    {
        return array_map('intval', array_keys(self::data('pt_BR')['categories']));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $texts
     */
    private static function consentBody(array $data, array $texts): string
    {
        $h = $data['headings'];
        $c = $data['consent'];

        $html = '<p>' . $c['identification'] . '</p>'
            . '<h3>' . $h['procedure'] . '</h3>'
            . '<p>' . $c['procedure_intro'] . ' ' . $texts['description'] . '</p>'
            . '{{procedimentos}}'
            . '<h3>' . $h['anesthesia'] . '</h3><p>' . $c['anesthesia'] . '</p>'
            . '<h3>' . $h['risks'] . '</h3>' . self::list([...$texts['risks'], ...$c['risks_general']])
            . '<h3>' . $h['alternatives'] . '</h3>' . self::list($texts['alternatives'])
            . '<h3>' . $h['expectations'] . '</h3><p>' . ($texts['expectations'] ?? '') . ' ' . $c['expectations'] . '</p>'
            . '<h3>' . $h['responsibilities'] . '</h3>' . self::list($c['responsibilities'])
            . '<h3>' . $h['declaration'] . '</h3><p>' . $c['declaration'] . '</p>'
            . '<h3>' . $h['data'] . '</h3><p>' . $c['data_protection'] . '</p>';

        return $html;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $texts
     */
    private static function preOpBody(array $data, array $texts): string
    {
        $h = $data['headings'];
        $p = $data['preop'];

        return '<p>' . $p['intro'] . '</p>'
            . '<h3>' . $h['fasting'] . '</h3>' . self::list($p['fasting'])
            . '<h3>' . $h['medications'] . '</h3>' . self::list($p['medications'])
            . '<h3>' . $h['documents'] . '</h3>' . self::list($p['documents'])
            . '<h3>' . $h['companion'] . '</h3>' . self::list($p['companion'])
            . '<h3>' . $h['hygiene'] . '</h3>' . self::list($p['hygiene'])
            . '<h3>' . $h['specific'] . '</h3>' . self::list($texts['preop'])
            . '<h3>' . $h['warn'] . '</h3>' . self::list($p['warn']);
    }

    /** @param list<string> $items */
    private static function list(array $items): string
    {
        return '<ul>' . implode('', array_map(fn (string $item) => '<li>' . $item . '</li>', $items)) . '</ul>';
    }

    /** @return array<string, mixed> */
    private static function data(string $locale): array
    {
        /** @var array<string, mixed> $data */
        $data = require database_path("data/surgery_templates/{$locale}.php");

        return $data;
    }

    /** Rótulo da categoria no idioma do catálogo (nome curto, ex.: "Catarata"). */
    public static function categoryName(int $category, ?string $locale = null): string
    {
        $data = self::data(self::resolveLocale($locale));

        return (string) ($data['categories'][$category]['name'] ?? SurgeryType::$categories[$category] ?? (string) $category);
    }
}
