<?php

declare(strict_types=1);

use App\Services\Surgery\Guards\{ChecklistTimeOutGuard, ConsentSignedGuard};
use App\Services\Surgery\Sections\{AuthorizationSection, ChecklistSection, DischargeSection, DocumentsSection, HistorySection, OperationSection, PostOpSection, RequestSection, ScheduleSection, TissSection};

/*
|--------------------------------------------------------------------------
| Módulo cirúrgico (Fase 4)
|--------------------------------------------------------------------------
| Ver docs/adr/0003-modulo-cirurgico.md.
*/
return [
    /*
    | Abas da tela do caso (Panel/Surgery/Show). Cada seção é uma classe que
    | implementa App\Services\Surgery\Sections\SurgeryShowSection: diz se a aba
    | aparece para aquele caso/usuário e quais props entrega. O componente Vue
    | correspondente fica em resources/js/Components/Surgery/Tabs/<Key>Tab.vue
    | (carregado por import.meta.glob — não precisa registrar no Show.vue).
    | A ORDEM de exibição vem de order() de cada seção.
    */
    'show_sections' => [
        RequestSection::class,
        AuthorizationSection::class,
        DocumentsSection::class,
        ScheduleSection::class,
        ChecklistSection::class,
        PostOpSection::class,
        DischargeSection::class,
        OperationSection::class,
        TissSection::class,
        HistorySection::class,
    ],

    /*
    | Condições para registrar a cirurgia como REALIZADA (App\Services\Surgery\
    | Guards\SurgeryPerformGuard). Cada etapa acrescenta a sua aqui.
    */
    'perform_guards' => [
        ChecklistTimeOutGuard::class,
        ConsentSignedGuard::class,
    ],

    /*
    | Mensagens e prazos que o módulo usa de verdade. Valores aqui são limites
    | operacionais, não regra clínica.
    */
    'dedupe_minutes'   => 10,   // mesmo documento emitido de novo dentro da janela devolve a emissão existente
    'default_duration' => 60,   // minutos, quando o caso não tem duração estimada
    'max_cids'         => 4,    // limite da guia TISS
];
