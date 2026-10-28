<?php

declare(strict_types=1);

namespace App\Services\Medicines;

/**
 * Interpreta a coluna APRESENTAÇÃO da lista de preços CMED/Anvisa, ex.:
 * "10 MG/ML SUS OFT CT FR GOT PLAS OPC X 5 ML" → concentração "10 MG/ML",
 * forma "susp_oft" (suspensão oftálmica), oftálmico = true.
 *
 * A CMED abrevia a forma farmacêutica logo depois da concentração; o que vem
 * depois é embalagem (CT, FR, BL, X 5 ML...), que fica só no texto original.
 * Forma desconhecida → null (o texto original continua disponível).
 */
class CmedPresentationParser
{
    /**
     * Abreviação da CMED (palavras separadas por espaço) → código da forma
     * (chave em lang/{locale}/medicine_forms.php). Ordem não importa: o
     * regex tenta as mais longas primeiro.
     *
     * @var array<string, string>
     */
    private const FORMS = [
        'SUS OFT'          => 'susp_oft',
        'SUSP OFT'         => 'susp_oft',
        'SOL OFT'          => 'sol_oft',
        'POM OFT'          => 'pom_oft',
        'GEL OFT'          => 'gel_oft',
        'EMU OFT'          => 'emu_oft',
        'COM REV LIB PROL' => 'com_lib_prol',
        'COM LIB PROL'     => 'com_lib_prol',
        'COM LIB RETARD'   => 'com_lib_prol',
        'COM REV'          => 'com_rev',
        'COM SUBL'         => 'com_subl',
        'COM MAST'         => 'com_mast',
        'COM EFEV'         => 'com_efev',
        'COM ORODISP'      => 'com_orodisp',
        'COM DISP'         => 'com_orodisp',
        'COM'              => 'com',
        'CAP DURA'         => 'cap',
        'CAP MOLE'         => 'cap',
        'CAP'              => 'cap',
        'SOL OR'           => 'sol_or',
        'SUS OR'           => 'sus_or',
        'SUSP OR'          => 'sus_or',
        'XPE'              => 'xpe',
        'SOL INJ'          => 'sol_inj',
        'SUS INJ'          => 'sus_inj',
        'EMU INJ'          => 'emu_inj',
        'PO LIOF'          => 'po_inj',
        'PO INJ'           => 'po_inj',
        'CREM DERM'        => 'crem',
        'CREM'             => 'crem',
        'POM DERM'         => 'pom',
        'POM'              => 'pom',
        'GEL DERM'         => 'gel',
        'GEL'              => 'gel',
        'SOL NAS'          => 'sol_nas',
        'SUS NAS'          => 'sol_nas',
        'SOL TOP'          => 'sol_top',
        'GRAN'             => 'gran',
        'PO'               => 'po',
    ];

    /** Formas que o médico chama de "colírio" no dia a dia (entra na busca). */
    public const EYE_DROP_FORMS = ['susp_oft', 'sol_oft', 'emu_oft'];

    private ?string $pattern = null;

    /**
     * @return array{concentration: ?string, form: ?string, is_ophthalmic: bool}
     */
    public function parse(string $presentation): array
    {
        $text = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $presentation)), 'UTF-8');

        $isOphthalmic = (bool) preg_match('/\bOFT\b/u', $text);

        if ($text === '' || ! preg_match($this->pattern(), $text, $match, PREG_OFFSET_CAPTURE)) {
            return ['concentration' => null, 'form' => null, 'is_ophthalmic' => $isOphthalmic];
        }

        $abbreviation  = (string) preg_replace('/\s+/', ' ', $match[1][0]);
        $concentration = trim(substr($text, 0, $match[0][1]));

        return [
            'concentration' => $concentration !== '' ? $concentration : null,
            'form'          => self::FORMS[$abbreviation] ?? null,
            'is_ophthalmic' => $isOphthalmic,
        ];
    }

    private function pattern(): string
    {
        if ($this->pattern !== null) {
            return $this->pattern;
        }

        $codes = array_keys(self::FORMS);
        usort($codes, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $alternatives = array_map(
            fn (string $code) => str_replace(' ', '\s+', preg_quote($code, '/')),
            $codes,
        );

        // Forma = abreviação como palavra(s) inteira(s), em qualquer posição
        // (alguns registros começam direto pela forma, sem concentração).
        return $this->pattern = '/(?:^|\s)(' . implode('|', $alternatives) . ')(?=\s|$)/u';
    }
}
