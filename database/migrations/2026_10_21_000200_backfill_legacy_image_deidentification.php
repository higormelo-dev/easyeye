<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de dado histórico (3ª e 6ª rodadas de revisão adversarial Codex):
 * 2026_10_03_200000_add_image_deidentification_to_ai_run_patient_exam_table
 * documenta null com DOIS significados — "execução anterior a esta regra"
 * (analisada de verdade, só nunca registrada) OU "ainda não executada"
 * (vídeo, arquivo ausente etc.). PatientExam::reportedConstraint() e
 * AiRunDocumentationService::writeRunDocumentation() (Fase 5 / revisão
 * adversarial Codex, rodada 2) passaram a exigir NOT NULL pra contar como
 * "analisado" — correto pro 2º significado, mas tratava todo run ANTERIOR
 * à coluna como nunca analisado, tirando laudo de IA aprovado antigo da
 * lista de laudados (voltava pra fila de pendentes) e, se esse run legado
 * só fosse aprovado DEPOIS desta correção, nunca vinculava o exame ao
 * documento (acenda pra reportar de novo, cobrança de IA em dobro).
 *
 * O corte NUNCA é a data do nome do arquivo desta migration (achado na 6ª
 * rodada de revisão adversarial Codex: o arquivo foi ESCRITO em 03/10, mas
 * nada garante que o DEPLOY em produção aconteceu nesse exato instante —
 * um run criado no intervalo entre escrever e de fato implantar ficaria
 * injustamente marcado como legado, OU um run anterior ao nome do arquivo
 * mas já processado por um deploy antecipado ficaria de fora). TAMBÉM não é
 * "o primeiro outcome real já gravado nesta base" (achado no /codex:review
 * seguinte: uma base com runs históricos de verdade, mas cujo deploy da
 * coluna/backfill aconteceu ANTES de qualquer execução do código NOVO de
 * análise, não tem NENHUMA linha não-nula pra servir de marco — ficava sem
 * marcar nada, mesmo havendo legado de verdade pra proteger).
 *
 * O corte NÃO é mais só o instante em que a migration roda (achado na 8ª
 * rodada de revisão adversarial Codex: um run criado pelo código NOVO pode
 * legitimamente ter uma linha com outcome real E outra ainda null — ex.
 * foto + vídeo no mesmo run — e as duas linhas nascem no MESMO created_at,
 * na criação do run; um corte por instante sozinho marca a linha null desse
 * run MODERNO como legado, fazendo um exame nunca analisado contar como
 * laudado). A granularidade certa é por RUN, não por linha: um run
 * genuinamente legado nunca teve NENHUMA linha escrita pelo código novo (a
 * coluna não existia), então TODAS as suas linhas são null; um run moderno
 * que tenha QUALQUER linha não-null prova que resolveImages() já rodou para
 * ele, e qualquer OUTRA linha null nesse mesmo run é genuinamente "nunca
 * tentada" — nunca legado. Por isso: exclui do backfill TODO run que tenha
 * ao menos uma linha não-null em qualquer lugar, antes de aplicar o
 * sentinela às linhas null restantes.
 *
 * Uma linha que por acaso ainda estiver genuinamente pendente (run novo,
 * ainda não processado) e só vier a ser analisada de verdade DEPOIS, pelo
 * código novo, tem seu outcome sobrescrito corretamente nessa análise
 * (resolveImages() sempre zera e reescreve a seleção inteira a cada
 * execução, Fase 5 / revisão adversarial Codex rodada 6) — o sentinela
 * nunca sobrevive a uma análise real subsequente, e reportedConstraint() só
 * considera "reportado" um run com status Approved, que nenhum run novo
 * ainda não processado alcança.
 *
 * "Toda linha null" não é mais garantia de legado nem assim (achado na 9ª
 * rodada de revisão adversarial Codex): resolveImages() já teve, em
 * versões mais antigas desta MESMA coluna (depois dela existir, antes do
 * guard de $sent === [] cobrir QUALQUER motivo — rodada 6), uma janela em
 * que um run com todo arquivo selecionado ilegível (não reconhecido como
 * "layout não suportado", só ilegível mesmo) chegava a waiting_approval/
 * approved sem travar E sem escrever nenhum outcome real OU skip — todas
 * as linhas desse run ficam null, mas o run é MODERNO (pós-coluna), nunca
 * legado. ai_runs.dispatch_audit (gravado por AiDispatchAudit::summarize()
 * sempre que resolveImages() segue adiante, mesma leva de migrations desta
 * coluna) é o sinal confiável: um run genuinamente legado nunca chegou a
 * passar por aquele código, então dispatch_audit fica null (nunca
 * "{images:0}"); um run que passou e não conseguiu enviar nenhuma imagem
 * grava dispatch_audit->images = 0 de verdade. Exclui do backfill todo run
 * nessa 2ª situação — ambíguo, fica null (ainda pendente) em vez de
 * arriscar certificar como analisado algo que não foi.
 *
 * Preenche só as linhas DE FATO legadas (nunca linha de um run que já tem
 * algum outcome real, nem linha de um run com dispatch_audit.images = 0)
 * com um sentinela distinto de SKIPPED_UNRECOGNIZED_LAYOUT
 * ('unrecognized_layout') e de qualquer chave de layout real (sempre um
 * identificador de produto, nunca a palavra genérica abaixo) — nenhum
 * código novo passa a depender do VALOR, só do "não é null e não é skip",
 * que já era a checagem usada.
 *
 * As duas exclusões acima NUNCA podem ser 2 SELECTs separados, lidos pra
 * um array do PHP e só DEPOIS usados num UPDATE posterior (achado na 11ª
 * rodada de revisão adversarial Codex): se um worker de fila ainda ativo
 * durante o deploy grava o outcome real de um exame (ex.: a foto de um run
 * foto+vídeo) no intervalo ENTRE o SELECT que lista "runs com outcome
 * real" e o UPDATE final, essa lista fica obsoleta — o run não é mais
 * "zero outcome real" (já tem um), mas o UPDATE, usando a lista velha,
 * não o exclui, e a linha do vídeo (null de propósito, nunca tentado) é
 * marcada legacy_unknown. resolveImages() não roda de novo pra corrigir
 * (só reexecuta em retry de falha, e este run teve sucesso de 1ª vez) — o
 * sentinela errado fica pra sempre, até alguém aprovar o run e o vídeo
 * contar como laudado sem nunca ter sido analisado.
 *
 * Por isso: as 2 exclusões viram subqueries correlacionadas (NOT EXISTS)
 * dentro do PRÓPRIO UPDATE — mas isso SOZINHO ainda não fecha a corrida
 * (achado na verificação adversarial da rodada seguinte): sob READ
 * COMMITTED, o snapshot de 1 instrução é tirado no INÍCIO dela, mas o
 * reexame de EvalPlanQual do Postgres (que atualizaria a visão pra uma
 * escrita concorrente) só vale pra LINHA sendo de fato escrita pelo UPDATE
 * — nunca pras linhas lidas só via subquery (o "sibling" com o outcome
 * real). Se o worker comitar o outcome real da foto DEPOIS do snapshot da
 * instrução mas ANTES dela terminar de varrer a linha do vídeo, a corrida
 * ainda existe — só mais estreita (1 instrução em vez de 2 round-trips).
 *
 * Fechar de verdade exige impedir qualquer escrita concorrente nas linhas
 * candidatas ENQUANTO a classificação acontece — não só torná-la rápida.
 * Um SELECT ... FOR UPDATE travando TODAS as linhas de TODO run candidato
 * (o que seria atualizado E os siblings que decidem a exclusão) ANTES de
 * classificar obriga qualquer escrita concorrente do worker numa dessas
 * linhas a ESPERAR esta transação terminar — e se o worker já estava
 * escrevendo, é esta migration que espera o commit dele primeiro.
 * Migration::$withinTransaction (default true no Laravel) já envolve este
 * up() inteiro numa única transação (Migrator::runMigration()) — as
 * travas seguem valendo até o fim dela. Só depois de travar é que o
 * UPDATE classifica e escreve, sem mais nenhuma escrita concorrente
 * possível nessas linhas específicas.
 *
 * Pré-requisito desta trava funcionar: resolveImages() (AiRunExecutionService)
 * precisa escrever zerar+outcomes como UMA transação, não 2 autocommits
 * separados — senão esta migration ainda podia travar exatamente ENTRE as
 * duas e ver "tudo null, sem dispatch_audit ainda" (achado na 12ª rodada
 * de revisão adversarial Codex; corrigido lá, não aqui).
 *
 * O conjunto de runs candidatos é calculado UMA vez e reutilizado por todas
 * as instruções (achado em nova rodada de revisão adversarial Codex): as 2
 * travas e o UPDATE antes recalculavam "runs com linha null" cada um por
 * conta própria, cada qual com o seu snapshot READ COMMITTED. Um run criado e
 * comitado DEPOIS da trava mas ANTES do UPDATE (o run e os vínculos com os
 * exames nascem atomicamente, ambos com outcome null) entrava no UPDATE sem
 * uma única linha travada: o worker dele podia gravar o outcome real da foto
 * depois do snapshot do UPDATE, e a linha do vídeo (nunca tentada, null de
 * propósito) virava 'legacy_unknown' — contando como analisada, saindo dos
 * pendentes e se vinculando a um laudo aprovado sem nunca ter sido enviada à
 * IA. Agora os IDs são capturados primeiro; as duas travas e o UPDATE só
 * enxergam esse conjunto fixo. Um run que apareça depois não pertence a ele:
 * fica intocado (null = pendente), que é o lado seguro — e as linhas do
 * conjunto, travadas antes da classificação, não mudam sob os pés dela.
 *
 * Risco residual aceito (não corrigido — gracioso dos 2 lados, não exige
 * mais código): pra um run com 2+ linhas, se o SELECT...FOR UPDATE desta
 * migration e o UPDATE de reset do worker visitarem as mesmas linhas em
 * ORDEM diferente (planos de execução distintos), é teoricamente possível
 * um deadlock real (AB-BA) — o Postgres detecta e aborta um dos dois
 * lados em ~1s (deadlock_timeout), nunca trava pra sempre. Lado abortado:
 * worker tenta de novo (RunAiWorkflowJob::$tries, default 3) OU esta
 * migration falha com erro claro e pode ser reexecutada com segurança
 * (UPDATE idempotente via NOT EXISTS).
 */
return new class() extends Migration {
    private const LEGACY_SENTINEL = 'legacy_unknown';

    /** Runs por instrução (limite de parâmetros do PostgreSQL: 65.535). */
    private const CHUNK_SIZE = 1000;

    public function up(): void
    {
        // Rodar de preferência em janela de baixo tráfego de IA: a trava
        // abaixo pode cobrir uma fração grande/ativa da tabela (todo run
        // com alguma linha ainda null é o estado NORMAL de um run em
        // processamento, não só legado raro) — sem lock_timeout, uma
        // trava "normal" (worker escrevendo 1 linha, libera rápido) nunca
        // é um problema, mas travar pra sempre contra algo genuinamente
        // preso em outra transação bloquearia workers de IA reais durante
        // o deploy. Falha rápido com erro claro em vez de pendurar
        // (achado na verificação adversarial empírica da rodada seguinte).
        DB::statement("set local lock_timeout = '5s'");

        // Conjunto FIXO de runs candidatos (qualquer run com ao menos 1 linha
        // null — o estado NORMAL de um run em processamento, não só legado),
        // capturado UMA vez: as duas travas e o UPDATE abaixo só enxergam
        // este conjunto. Recalcular "runs com linha null" em cada instrução
        // deixava um run criado entre uma e outra entrar no UPDATE sem
        // nenhuma linha travada.
        $runIds = DB::table('ai_run_patient_exam')
            ->whereNull('image_deidentification')
            ->distinct()
            ->pluck('ai_run_id')
            ->all();

        if ($runIds === []) {
            return;
        }

        $chunks = array_chunk($runIds, self::CHUNK_SIZE);

        // Trava TODAS as linhas de TODO run do conjunto antes de classificar —
        // é isto, não a subquery sozinha, que impede um worker concorrente de
        // comitar um outcome real ENTRE o que esta migration lê e o que ela
        // escreve.
        foreach ($chunks as $chunk) {
            DB::table('ai_run_patient_exam')->selectRaw('1')->whereIn('ai_run_id', $chunk)->lockForUpdate()->get();
        }

        // Mesma trava pro lado de ai_runs.dispatch_audit (2ª exclusão).
        foreach ($chunks as $chunk) {
            DB::table('ai_runs')->selectRaw('1')->whereIn('id', $chunk)->lockForUpdate()->get();
        }

        // Só depois de TODAS as travas é que a classificação roda — e só sobre
        // o conjunto travado.
        foreach ($chunks as $chunk) {
            DB::update(
                sprintf(
                    <<<'SQL'
                        update ai_run_patient_exam
                        set image_deidentification = ?
                        where ai_run_id in (%s)
                          and image_deidentification is null
                          and not exists (
                              select 1 from ai_run_patient_exam as sibling
                              where sibling.ai_run_id = ai_run_patient_exam.ai_run_id
                                and sibling.image_deidentification is not null
                          )
                          and not exists (
                              select 1 from ai_runs
                              where ai_runs.id = ai_run_patient_exam.ai_run_id
                                and ai_runs.dispatch_audit->>'images' = '0'
                          )
                        SQL,
                    implode(', ', array_fill(0, count($chunk), '?')),
                ),
                [self::LEGACY_SENTINEL, ...$chunk],
            );
        }
    }

    public function down(): void
    {
        DB::table('ai_run_patient_exam')
            ->where('image_deidentification', self::LEGACY_SENTINEL)
            ->update(['image_deidentification' => null]);
    }
};
