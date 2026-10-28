<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

/**
 * PROPOSTA INICIAL de protocolos de retorno (v1) — ponto de partida para o Revisor
 * Clínico, NÃO regra validada. Quem valida é o Revisor, versão por versão, e a
 * regra só age depois de liberada (ADR 0002). Por isso o seeder grava SEMPRE em
 * rascunho e nenhum valor daqui chega a uma clínica sem passar pela validação.
 *
 * Os números vêm das diretrizes citadas em `references`. Onde a literatura dá
 * faixa ou depende de gravidade/idade/risco, o protocolo traz o ponto médio e a
 * FAIXA (min..max) em que o médico da clínica pode ajustar — o esquema v1 não
 * estratifica por gravidade; isso fica a critério do médico dentro da faixa.
 * `evidence` diz o quanto a fonte sustenta o NÚMERO (não a qualidade da fonte).
 */
final class ProposedProtocolDrafts
{
    private const INITIAL_NOTE = 'Versão inicial, proposta pela EasyEye para a primeira validação clínica.';

    /**
     * @return array<string, array{
     *     sort_order: int,
     *     translations: array<string, array{title: string, summary: string}>,
     *     content: array<string, mixed>
     * }>
     */
    public static function all(): array
    {
        return [
            'glaucoma'              => self::glaucoma(),
            'dmri'                  => self::dmri(),
            'retinopatia_diabetica' => self::diabeticRetinopathy(),
            'ceratocone'            => self::keratoconus(),
            'hidroxicloroquina'     => self::hydroxychloroquine(),
        ];
    }

    private static function glaucoma(): array
    {
        return [
            'sort_order'   => 10,
            'translations' => [
                'pt_BR' => ['title' => 'Glaucoma', 'summary' => 'Consulta e campimetria periódicas para quem tem glaucoma.'],
                'en'    => ['title' => 'Glaucoma', 'summary' => 'Periodic visit and visual field testing for patients with glaucoma.'],
            ],
            'content' => [
                'entry' => [
                    // H40.0 (suspeita de glaucoma) fica de fora de propósito: suspeita não é glaucoma.
                    'cid' => [
                        'strong'  => ['H401', 'H402', 'H403', 'H404', 'H405', 'H406', 'H408', 'H409'],
                        'weak'    => ['H40', 'H42'],
                        'exclude' => ['H400'],
                    ],
                    'medications' => [],
                ],
                'tasks' => [
                    [
                        'key'      => 'consulta',
                        'kind'     => 'consultation',
                        'interval' => ['unit' => 'months', 'default' => 4, 'min' => 3, 'max' => 6],
                    ],
                    [
                        'key'      => 'campimetria',
                        'kind'     => 'exam',
                        'exam_key' => 'visual_field',
                        'interval' => ['unit' => 'months', 'default' => 12, 'min' => 6, 'max' => 12],
                    ],
                    [
                        'key'      => 'oct_nervo_optico',
                        'kind'     => 'exam',
                        'exam_key' => 'oct_optic_nerve',
                        'interval' => ['unit' => 'months', 'default' => 12, 'min' => 6, 'max' => 24],
                        'required' => false,
                    ],
                ],
                'doctor_order' => ['cap_to_max' => true],
                'references'   => [
                    [
                        'title'    => 'American Academy of Ophthalmology. Primary Open-Angle Glaucoma Preferred Practice Pattern. Ophthalmology 2021',
                        'year'     => 2021,
                        'evidence' => 'medium',
                        'note'     => 'Frequência de consulta e de campo visual depende da gravidade, do risco de progressão e da PIO-alvo; a faixa aqui é o intervalo geral.',
                    ],
                    [
                        'title'    => 'European Glaucoma Society. Terminology and Guidelines for Glaucoma, 5th Edition. Br J Ophthalmol 2021',
                        'year'     => 2021,
                        'evidence' => 'medium',
                        'note'     => 'Intervalos de acompanhamento por estágio e risco; o revisor indica a edição nacional (SBG/CBO) preferida.',
                    ],
                    [
                        'title'    => 'Chauhan BC et al. Practical recommendations for measuring rates of visual field change in glaucoma. Br J Ophthalmol 2008',
                        'year'     => 2008,
                        'evidence' => 'medium',
                        'note'     => 'Base para a frequência do campo visual; campo mais frequente no início do acompanhamento fica a critério do médico, fora do protocolo.',
                    ],
                ],
                'rationale'   => 'Consulta a cada 4 meses (faixa de 3 a 6) e campimetria anual (faixa de 6 a 12) são o ponto de partida das diretrizes da AAO e da EGS para glaucoma estável; ambas ajustam a frequência à gravidade e ao risco de progressão, o que este protocolo não estratifica: o médico ajusta dentro da faixa. OCT de nervo óptico é complementar e opcional. Glaucoma congênito (Q15.0) e suspeita de glaucoma (H40.0) não entram.',
                'change_note' => self::INITIAL_NOTE,
            ],
        ];
    }

    private static function dmri(): array
    {
        return [
            'sort_order'   => 20,
            'translations' => [
                'pt_BR' => ['title' => 'Degeneração macular relacionada à idade (DMRI)', 'summary' => 'Consulta e OCT de mácula periódicos para quem tem DMRI sem tratamento intravítreo em curso.'],
                'en'    => ['title' => 'Age-related macular degeneration (AMD)', 'summary' => 'Periodic visit and macular OCT for patients with AMD not on active intravitreal therapy.'],
            ],
            'content' => [
                'entry' => [
                    'cid'         => ['strong' => ['H353'], 'weak' => [], 'exclude' => []],
                    'medications' => [],
                ],
                'tasks' => [
                    [
                        'key'      => 'consulta',
                        'kind'     => 'consultation',
                        'interval' => ['unit' => 'months', 'default' => 6, 'min' => 3, 'max' => 12],
                    ],
                    [
                        'key'      => 'oct_macula',
                        'kind'     => 'exam',
                        'exam_key' => 'oct_macula',
                        'interval' => ['unit' => 'months', 'default' => 6, 'min' => 3, 'max' => 12],
                    ],
                ],
                'doctor_order' => ['cap_to_max' => true],
                'references'   => [
                    [
                        'title'    => 'American Academy of Ophthalmology. Age-Related Macular Degeneration Preferred Practice Pattern. Ophthalmology 2020',
                        'year'     => 2020,
                        'evidence' => 'medium',
                        'note'     => 'O intervalo depende do estágio (inicial, intermediária, avançada) e da forma (seca ou neovascular).',
                    ],
                ],
                'rationale'   => 'Consulta e OCT de mácula a cada 6 meses (faixa de 3 a 12) para DMRI sem tratamento intravítreo ativo: o intervalo certo depende do estágio, que este protocolo não estratifica — o médico ajusta dentro da faixa. Paciente em tratamento anti-VEGF terá protocolo próprio (curso de aplicações) em versão posterior; o paciente com DMRI neovascular ativa deve ter o retorno pedido pelo médico.',
                'change_note' => self::INITIAL_NOTE,
            ],
        ];
    }

    private static function diabeticRetinopathy(): array
    {
        return [
            'sort_order'   => 30,
            'translations' => [
                'pt_BR' => ['title' => 'Retinopatia diabética', 'summary' => 'Consulta com mapeamento de retina e retinografia periódicos para quem tem retinopatia diabética.'],
                'en'    => ['title' => 'Diabetic retinopathy', 'summary' => 'Periodic dilated visit and fundus photography for patients with diabetic retinopathy.'],
            ],
            'content' => [
                'entry' => [
                    // E10–E14 com 4º caractere 3 = diabetes com complicação oftálmica; H36.0 = retinopatia diabética.
                    'cid' => [
                        'strong'  => ['E103', 'E113', 'E123', 'E133', 'E143', 'H360'],
                        'weak'    => [],
                        'exclude' => [],
                    ],
                    'medications' => [],
                ],
                'tasks' => [
                    [
                        'key'      => 'consulta_mapeamento',
                        'kind'     => 'consultation',
                        'interval' => ['unit' => 'months', 'default' => 6, 'min' => 3, 'max' => 12],
                    ],
                    [
                        'key'      => 'retinografia',
                        'kind'     => 'exam',
                        'exam_key' => 'retinography',
                        'interval' => ['unit' => 'months', 'default' => 12, 'min' => 6, 'max' => 12],
                    ],
                    [
                        'key'      => 'oct_macula',
                        'kind'     => 'exam',
                        'exam_key' => 'oct_macula',
                        'interval' => ['unit' => 'months', 'default' => 12, 'min' => 6, 'max' => 24],
                        'required' => false,
                    ],
                ],
                'doctor_order' => ['cap_to_max' => true],
                'references'   => [
                    [
                        'title'    => 'American Academy of Ophthalmology. Diabetic Retinopathy Preferred Practice Pattern. Ophthalmology 2020',
                        'year'     => 2020,
                        'evidence' => 'medium',
                        'note'     => 'Intervalo por gravidade da retinopatia (de 12 meses, na leve, a 1-3 meses, na proliferativa/edema).',
                    ],
                    [
                        'title'    => 'International Council of Ophthalmology. Guidelines for Diabetic Eye Care. 2017',
                        'year'     => 2017,
                        'evidence' => 'medium',
                        'note'     => 'Frequência de exame por nível de gravidade e por nível de recurso do serviço.',
                    ],
                    [
                        'title'    => 'Sociedade Brasileira de Diabetes. Diretrizes (edição vigente)',
                        'year'     => null,
                        'evidence' => 'medium',
                        'note'     => 'Edição e capítulo a confirmar com o Revisor.',
                    ],
                ],
                'rationale'   => 'Para quem JÁ tem retinopatia diabética (a entrada exige complicação oftálmica codificada), consulta com mapeamento de retina a cada 6 meses (faixa de 3 a 12) e retinografia anual (faixa de 6 a 12). O intervalo ideal depende da gravidade, que este protocolo não estratifica: formas moderadas a graves ou com edema macular pedem retorno mais curto, a pedido do médico. OCT de mácula é complementar e opcional. Diabético sem retinopatia não entra nesta versão.',
                'change_note' => self::INITIAL_NOTE,
            ],
        ];
    }

    private static function keratoconus(): array
    {
        return [
            'sort_order'   => 40,
            'translations' => [
                'pt_BR' => ['title' => 'Ceratocone', 'summary' => 'Consulta e topografia de córnea periódicas para quem tem ceratocone.'],
                'en'    => ['title' => 'Keratoconus', 'summary' => 'Periodic visit and corneal topography for patients with keratoconus.'],
            ],
            'content' => [
                'entry' => [
                    'cid'         => ['strong' => ['H186'], 'weak' => [], 'exclude' => []],
                    'medications' => [],
                ],
                'tasks' => [
                    [
                        'key'      => 'consulta',
                        'kind'     => 'consultation',
                        'interval' => ['unit' => 'months', 'default' => 6, 'min' => 3, 'max' => 12],
                    ],
                    [
                        'key'      => 'topografia',
                        'kind'     => 'exam',
                        'exam_key' => 'corneal_topography',
                        'interval' => ['unit' => 'months', 'default' => 6, 'min' => 3, 'max' => 12],
                    ],
                ],
                'doctor_order' => ['cap_to_max' => true],
                'references'   => [
                    [
                        'title'    => 'Gomes JAP et al. Global Consensus on Keratoconus and Ectatic Diseases. Cornea 2015',
                        'year'     => 2015,
                        'evidence' => 'low',
                        'note'     => 'O consenso não fixa um intervalo: a periodicidade depende de idade, progressão documentada e tratamento (cross-linking).',
                    ],
                ],
                'rationale'   => 'Não há intervalo fechado na literatura: consulta e topografia de córnea a cada 6 meses (faixa de 3 a 12) é a prática comum para ceratocone, mais curta em jovens e em progressão documentada. Este protocolo não estratifica por idade nem por progressão — o médico ajusta dentro da faixa. É o protocolo com a base de evidência mais fraca para o número e o que mais precisa da opinião do Revisor.',
                'change_note' => self::INITIAL_NOTE,
            ],
        ];
    }

    private static function hydroxychloroquine(): array
    {
        return [
            'sort_order'   => 50,
            'translations' => [
                'pt_BR' => ['title' => 'Uso de hidroxicloroquina', 'summary' => 'Rastreio de maculopatia tóxica: exame basal e triagem anual a partir de 5 anos de uso.'],
                'en'    => ['title' => 'Hydroxychloroquine use', 'summary' => 'Toxic maculopathy screening: baseline exam and yearly screening after 5 years of use.'],
            ],
            'content' => [
                'entry' => [
                    'cid'         => ['strong' => [], 'weak' => [], 'exclude' => []],
                    'medications' => ['hydroxychloroquine'],
                ],
                'tasks' => [
                    [
                        'key'      => 'basal_campimetria',
                        'kind'     => 'exam',
                        'exam_key' => 'visual_field',
                        'interval' => ['unit' => 'months', 'default' => 12, 'min' => 6, 'max' => 12],
                        'recurs'   => false,
                    ],
                    [
                        'key'      => 'basal_oct_macula',
                        'kind'     => 'exam',
                        'exam_key' => 'oct_macula',
                        'interval' => ['unit' => 'months', 'default' => 12, 'min' => 6, 'max' => 12],
                        'recurs'   => false,
                    ],
                    [
                        'key'                 => 'triagem_campimetria',
                        'kind'                => 'exam',
                        'exam_key'            => 'visual_field',
                        'interval'            => ['unit' => 'months', 'default' => 12, 'min' => 6, 'max' => 12],
                        'starts_after_months' => 60,
                    ],
                    [
                        'key'                 => 'triagem_oct_macula',
                        'kind'                => 'exam',
                        'exam_key'            => 'oct_macula',
                        'interval'            => ['unit' => 'months', 'default' => 12, 'min' => 6, 'max' => 12],
                        'starts_after_months' => 60,
                    ],
                ],
                'doctor_order' => ['cap_to_max' => true],
                'references'   => [
                    [
                        'title'    => 'Marmor MF et al. Recommendations on Screening for Chloroquine and Hydroxychloroquine Retinopathy (2016 Revision). Ophthalmology 2016',
                        'year'     => 2016,
                        'evidence' => 'high',
                        'note'     => 'Exame basal no 1º ano; triagem anual após 5 anos de uso, antes se houver fator de risco (dose acima de 5 mg/kg/dia de peso real, doença renal, tamoxifeno, doença macular). Campo visual automatizado 10-2 e SD-OCT.',
                    ],
                    [
                        'title'    => 'Melles RB, Marmor MF. The risk of toxic retinopathy in patients on long-term hydroxychloroquine therapy. JAMA Ophthalmol 2014',
                        'year'     => 2014,
                        'evidence' => 'high',
                        'note'     => 'Risco cumulativo: baixo nos primeiros 5 anos e crescente depois.',
                    ],
                ],
                'rationale'   => 'Exame basal (campo visual e OCT de mácula) em até 12 meses do início e triagem anual a partir de 5 anos de uso, como na recomendação da AAO. Fatores de risco (dose elevada, doença renal, tamoxifeno, doença macular) antecipam a triagem: o médico pede o retorno. Sem a data de início do uso, a clínica precisa informá-la para o retorno ser calculado. Cloroquina não está nesta versão.',
                'change_note' => self::INITIAL_NOTE,
            ],
        ];
    }
}
