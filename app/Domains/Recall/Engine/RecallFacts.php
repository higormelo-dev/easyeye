<?php

declare(strict_types=1);

namespace App\Domains\Recall\Engine;

use App\Enums\Eye;
use Carbon\CarbonImmutable;

/**
 * O que o prontuário diz sobre UM paciente, na forma que o motor lê: só datas e olhos, sem
 * identificação e sem banco. Quem monta isto (RecallFactsLoader) já filtrou a clínica e o que foi
 * apagado.
 */
final class RecallFacts
{
    /**
     * @param list<CarbonImmutable>                                     $consultations datas dos atendimentos (a consulta cobre os dois olhos)
     * @param list<array{key: string, eye: Eye, date: CarbonImmutable}> $exams         exames já traduzidos para a chave canônica do protocolo
     */
    public function __construct(
        public readonly array $consultations = [],
        public readonly array $exams = [],
    ) {
    }

    /**
     * O último atendimento dentro da janela `[notBefore, notAfter]` (ou nulo). Ato com data no FUTURO (erro de
     * digitação, relógio do equipamento) nunca conta como feito.
     */
    public function lastConsultation(?CarbonImmutable $notBefore = null, ?CarbonImmutable $notAfter = null): ?CarbonImmutable
    {
        $dates = array_filter(
            $this->consultations,
            static fn (CarbonImmutable $date): bool => self::within($date, $notBefore, $notAfter),
        );

        return $dates === [] ? null : max($dates);
    }

    /**
     * O último exame `$key` que cobre `$eye`, dentro da janela. AO decompõe em OD+OE: o exame só "vale" quando os
     * DOIS olhos foram cobertos, e a data é a do olho mais antigo (um exame só no OD deixa o OE vencendo).
     */
    public function lastExam(string $key, Eye $eye, ?CarbonImmutable $notBefore = null, ?CarbonImmutable $notAfter = null): ?CarbonImmutable
    {
        $oldest = null;

        foreach ($eye->components() as $component) {
            $latest = null;

            foreach ($this->exams as $exam) {
                if ($exam['key'] !== $key || ! $exam['eye']->covers($component) || ! self::within($exam['date'], $notBefore, $notAfter)) {
                    continue;
                }

                if ($latest === null || $exam['date']->greaterThan($latest)) {
                    $latest = $exam['date'];
                }
            }

            if ($latest === null) {
                return null;
            }

            if ($oldest === null || $latest->lessThan($oldest)) {
                $oldest = $latest;
            }
        }

        return $oldest;
    }

    private static function within(CarbonImmutable $date, ?CarbonImmutable $notBefore, ?CarbonImmutable $notAfter): bool
    {
        return ($notBefore === null || $date->startOfDay()->greaterThanOrEqualTo($notBefore->startOfDay()))
            && ($notAfter === null || $date->startOfDay()->lessThanOrEqualTo($notAfter->startOfDay()));
    }
}
