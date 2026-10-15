<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Olho a que um ato clínico se refere (condição crônica, exame, aplicação).
 *
 * O produto guarda o olho de três jeitos: `patient_exams.laterality` (inteiro:
 * 1 = OD, 2 = OE, 0/nulo = AO), `medical_record_procedures.eye` (texto:
 * `right|left|both`, nulo = procedimento sem olho) e `eye_side` nos itens de guia
 * TISS. Este enum é o ponto único de tradução entre eles — o recall, o curso
 * anti-VEGF e as demais telas novas leem o olho por aqui, nunca comparando
 * inteiros ou strings soltos.
 *
 * AO (Both) é a convenção que o produto já usa para "ambos os olhos / não
 * informado" (EyeImagesController, ReportExamSummary): o exame sem lateralidade
 * aparece como AO e, para o recall, cobre qualquer olho (`covers()`). É a
 * leitura branda — um exame que ninguém marcou como OD/OE pode suprimir o
 * lembrete do olho que de fato não foi examinado — e está registrada no
 * glossário e no ADR 0002.
 */
enum Eye: string
{
    case Right = 'right';
    case Left  = 'left';
    case Both  = 'both';

    /**
     * `patient_exams.laterality`: 1 = OD, 2 = OE; 0, nulo e qualquer outro valor
     * são AO (mesma regra de ReportExamSummary::eyeCode).
     */
    public static function fromLaterality(?int $laterality): self
    {
        return match ($laterality) {
            1       => self::Right,
            2       => self::Left,
            default => self::Both,
        };
    }

    /**
     * `medical_record_procedures.eye`: nulo/vazio é procedimento SEM olho (não
     * vira AO — não há olho a cobrir). Valor fora de right|left|both também dá
     * nulo; a validação do formulário já recusa isso antes de gravar.
     */
    public static function fromProcedureEye(?string $eye): ?self
    {
        return self::tryFrom(strtolower(trim((string) $eye)));
    }

    public function isLateral(): bool
    {
        return $this !== self::Both;
    }

    /**
     * Um ato registrado NESTE olho cobre a exigência de `$required`? AO cobre
     * qualquer olho; OD só cobre OD; OE só OE; exigência de AO (condição
     * bilateral) só é coberta por AO — para somar OD + OE separados, quem
     * avalia decompõe a exigência com `components()`.
     */
    public function covers(self $required): bool
    {
        return $this === self::Both || $this === $required;
    }

    /**
     * Os olhos concretos da exigência: AO decompõe em [OD, OE]; OD e OE são
     * eles mesmos.
     *
     * @return array<int, self>
     */
    public function components(): array
    {
        return match ($this) {
            self::Both => [self::Right, self::Left],
            default    => [$this],
        };
    }

    /** OD/OE/AO (OD/OS/OU em inglês) — as siglas já traduzidas em `eye_images`. */
    public function label(): string
    {
        return match ($this) {
            self::Right => __('eye_images.eye_od'),
            self::Left  => __('eye_images.eye_oe'),
            self::Both  => __('eye_images.eye_ao'),
        };
    }

    /** Código inteiro de `patient_exams.laterality` (AO = 0). */
    public function laterality(): int
    {
        return match ($this) {
            self::Right => 1,
            self::Left  => 2,
            self::Both  => 0,
        };
    }
}
