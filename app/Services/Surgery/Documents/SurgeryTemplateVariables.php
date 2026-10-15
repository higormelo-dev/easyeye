<?php

declare(strict_types=1);

namespace App\Services\Surgery\Documents;

use App\Models\Entity;
use App\Models\Surgery\SurgicalCase;
use Illuminate\Support\{Carbon, Number};

/**
 * Variáveis {{...}} dos modelos de TCLE / orientações e a resolução delas.
 *
 * Todo valor entra com ESCAPE de HTML (nome de paciente com "<script>" vira
 * texto). Só as variáveis de lista (`procedimentos`, `lio`) devolvem HTML — que
 * este próprio serviço monta, escapando cada item. Valor ausente (cirurgia
 * ainda sem data, sem anestesia definida...) vira uma linha em branco para
 * preencher à mão e a variável sai em `pending`, para a tela avisar.
 * Variável que o sistema não conhece NÃO é trocada e sai em `unknown`.
 */
final class SurgeryTemplateVariables
{
    public const KEYS = [
        'paciente', 'data_nascimento', 'olho', 'cirurgia', 'procedimentos', 'cirurgiao', 'crm',
        'clinica', 'local', 'data_cirurgia', 'anestesia', 'lio',
    ];

    /** Variáveis que carregam HTML montado aqui (lista). */
    private const HTML_KEYS = ['procedimentos', 'lio'];

    /** Espaço em branco para preencher à mão. */
    public const BLANK = '______________';

    /**
     * Valores do caso (já com os rótulos no idioma atual).
     *
     * @return array<string, string> chave => texto (ou HTML nas variáveis de lista); '' = sem valor
     */
    public function forCase(SurgicalCase $case): array
    {
        $case->loadMissing(['patient.person', 'surgeon.person', 'facility', 'room', 'procedures', 'iolPlans']);

        $locale = app()->getLocale();
        $person = $case->patient?->person;

        $procedures = $case->procedures->map(fn ($p) => trim(($p->tuss_code ? $p->tuss_code . ' — ' : '') . $p->description))->filter()->values()->all();

        $iol = $case->iolPlans->map(function ($plan) use ($locale) {
            $power = (float) $plan->power;
            $text  = $plan->eye->label() . ': ' . $plan->model_label . ' ' . ($power >= 0 ? '+' : '') . Number::format($power, 2, locale: $locale) . ' D';

            return $text;
        })->values()->all();

        $local = $case->facility?->name;

        if ($local !== null && $case->room?->name) {
            $local .= ' — ' . $case->room->name;
        }

        return [
            'paciente'        => (string) ($person?->full_name ?? ''),
            'data_nascimento' => $person?->birth_date ? $person->birth_date->locale($locale)->isoFormat('L') : '',
            'olho'            => $case->eye ? $case->eye->label() : '',
            'cirurgia'        => (string) $case->title,
            'procedimentos'   => $this->htmlList($procedures),
            'cirurgiao'       => (string) ($case->surgeon?->person?->full_name ?? ''),
            'crm'             => (string) ($case->surgeon?->record ?? ''),
            'clinica'         => (string) ($case->entity_id ? Entity::query()->whereKey($case->entity_id)->value('name') : ''),
            'local'           => (string) ($local ?? ''),
            'data_cirurgia'   => $case->scheduled_start ? $case->scheduled_start->copy()->locale($locale)->isoFormat('L LT') : '',
            'anestesia'       => $case->anesthesia?->label() ?? '',
            'lio'             => $this->htmlList($iol),
        ];
    }

    /** Dados de exemplo para a pré-visualização do modelo (Configurações). @return array<string, string> */
    public function sample(): array
    {
        $locale = app()->getLocale();
        $en     = str_starts_with(strtolower($locale), 'en');

        return [
            'paciente'        => $en ? 'Mary Example' : 'Maria Exemplo',
            'data_nascimento' => Carbon::parse('1958-04-12')->locale($locale)->isoFormat('L'),
            'olho'            => (string) __('surgery_enums.surgery_eye.od'),
            'cirurgia'        => $en ? 'Phacoemulsification with IOL' : 'Facoemulsificação com LIO',
            'procedimentos'   => $this->htmlList([$en ? '30306019 — Phacoemulsification with IOL implant' : '30306019 — Facoemulsificação com implante de LIO']),
            'cirurgiao'       => $en ? 'John Surgeon' : 'João Cirurgião',
            'crm'             => '12345',
            'clinica'         => $en ? 'Vision Clinic' : 'Clínica Visão',
            'local'           => $en ? 'Vision Clinic — Room 1' : 'Clínica Visão — Sala 1',
            'data_cirurgia'   => Carbon::parse('2026-11-10 08:30')->locale($locale)->isoFormat('L LT'),
            'anestesia'       => (string) __('surgery_enums.anesthesia_type.topical_sedation'),
            'lio'             => $this->htmlList([(string) __('surgery_enums.surgery_eye.od') . ': LIO X +21,50 D']),
        ];
    }

    /**
     * Troca as variáveis do texto.
     *
     * @param array<string, string> $values
     *
     * @return array{html: string, pending: list<string>, unknown: list<string>}
     */
    public function resolve(string $body, array $values): array
    {
        $pending = [];
        $unknown = [];

        $html = preg_replace_callback('/\{\{\s*([A-Za-z_]+)\s*\}\}/', function (array $m) use ($values, &$pending, &$unknown): string {
            $key = strtolower($m[1]);

            if (! in_array($key, self::KEYS, true)) {
                $unknown[$key] = true;

                return $m[0];
            }

            $value = trim((string) ($values[$key] ?? ''));

            if ($value === '') {
                $pending[$key] = true;

                return self::BLANK;
            }

            return in_array($key, self::HTML_KEYS, true) ? $value : e($value);
        }, $body) ?? $body;

        return ['html' => $html, 'pending' => array_keys($pending), 'unknown' => array_keys($unknown)];
    }

    /** Variáveis que o texto usa e o sistema não conhece. @return list<string> */
    public function unknownIn(string $body): array
    {
        return $this->resolve($body, [])['unknown'];
    }

    /** @param list<string> $items */
    private function htmlList(array $items): string
    {
        if ($items === []) {
            return '';
        }

        return '<ul>' . implode('', array_map(fn (string $item) => '<li>' . e($item) . '</li>', $items)) . '</ul>';
    }
}
