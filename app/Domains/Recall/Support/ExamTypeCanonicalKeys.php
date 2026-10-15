<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

/**
 * Quais dos exames GLOBAIS da plataforma (catálogo `exam_types` sem clínica) o recall SUGERE como cada exame
 * canônico de um protocolo (RecallExamKey). É só sugestão: a clínica confirma o mapeamento dela na tela de
 * configuração — o que conta como "campimetria" é decisão da clínica, nunca do sistema.
 *
 * Nomes em maiúsculas, como o catálogo os grava. Só o que é inequívoco: "OCT de retina" (genérico) e a campimetria
 * manual ficam de fora de propósito.
 */
final class ExamTypeCanonicalKeys
{
    /** @var array<string, string> nome do exame global → chave canônica */
    public const BY_NAME = [
        'CAMPIMETRIA COMPUTADORIZADA'             => 'visual_field',
        'OCT DE NERVO ÓPTICO'                     => 'oct_optic_nerve',
        'OCT DE CAMADA DE FIBRAS NERVOSAS (RNFL)' => 'oct_optic_nerve',
        'OCT MÁCULA'                              => 'oct_macula',
        'RETINOGRAFIA COLORIDA'                   => 'retinography',
        'RETINOGRAFIA'                            => 'retinography',
        'TOPOGRAFIA CORNEANA'                     => 'corneal_topography',
    ];
}
