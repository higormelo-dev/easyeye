# EasyEye — auditoria e plano finito de encerramento

Data de referência: 11/10/2026. Escopo: aplicação Laravel deste repositório, incluindo alterações locais presentes durante a análise. Este documento registra uma auditoria técnica; não certifica ausência de vulnerabilidades nem conformidade jurídica.

## Decisão recomendada

Encerrar a rodada atual por uma lista de defeitos e critérios verificáveis, em vez de pedir repetidamente a uma IA que encontre “qualquer melhoria”. A lista está em [CHECKLIST.md](CHECKLIST.md). Cada item tem causa, impacto, correção mínima e evidência de conclusão abaixo. Melhorias opcionais não bloqueiam a entrega.

“Nenhum achado em qualquer revisão futura” não é um resultado garantível: requisitos mudam, correções podem criar regressões e surgem novos avisos de segurança. O resultado possível é **nenhum bloqueador aberto no escopo e na referência definidos**, com verificações reproduzíveis. O protocolo ao final deste documento estabelece esse limite sem esconder defeitos.

Não tenho o histórico completo das revisões dos últimos dois dias para atribuir todas as mudanças a uma causa. Nesta referência, há fatores concretos que explicam um ciclo desse tipo: dívida global de lint misturada a defeitos, árvore com alterações locais, teste dependente da hora e cenários clínicos que os testes existentes não percorrem pela interface. Além disso, um pedido aberto por “todas as melhorias” permite que cada revisão escolha outros arquivos e outras preferências. A solução de processo é fixar defeitos, referência, requisitos e gate; a solução técnica é cobrir cada causa com um teste adequado.

## Referência e abrangência

- HEAD observado: `2bc6a86e`; a árvore de trabalho contém numerosas alterações e arquivos novos anteriores a esta auditoria. O commit sozinho não identifica o código analisado. O inventário de hashes e o estado Git estão em `evidencias/`.
- Inventário aproximado: 1.177 arquivos em `app`, 503 em `resources/js`, 772 em `tests`, 10 em `routes`. Quantidades são inventário, não prova de leitura integral de cada arquivo.
- Ambiente utilizado: PHP 8.4.26, PostgreSQL, Node 24.18.0, Composer 2.8.8. Laravel instalado 12.56.0. Foram examinados fluxos clínicos, assinatura, documentos/PDF, portal do paciente, auditoria, relatórios externos, TISS, filas, autenticação/isolamento, dependências, testes e automação.
- Foram consideradas as avaliações de setembro em `docs/`. Achados antigos corrigidos não foram reabertos automaticamente. Há CI real em `.github/workflows/integrator-contracts.yml`, com testes PHP/JS e builds; seu nome não significa que teste somente contratos.
- A auditoria não alterou código funcional nem aplicou correções automáticas de dependências/formatação. O build gerou os artefatos usuais. A suíte usou `easyeye_audit_20261011`; as reproduções dirigidas usaram `easyeye_audit_probes_20261011`, separadamente, com fixtures sintéticas e rollback. Não se usou banco de produção.
- Infraestrutura remota, binário desktop Rust, equipamentos reais, homologação com operadoras, restauração de backups, carga de produção e teste visual completo em navegador não foram certificados. Quando necessários à implantação, são validações de ambiente separadas, não defeitos de código inventados.

## Verificações executadas

O resumo final dos resultados está em [evidencias/VALIDACAO.md](evidencias/VALIDACAO.md). Os dados de auditoria de dependências, sondas e inventário também estão nessa pasta.

Resultado final: PHP com threads de imagem limitadas concluiu com **5.789 aprovados, 1 ignorado e zero falhas**, incluindo 10 casos de integração. Build cliente/SSR passou. Após uma alteração detectada no teste JS, a suíte local passou com **2.506 testes**, mas o arquivo de documentos ainda falhou em outro fuso (EE-11). A execução PHP padrão havia travado no helper de concorrência (EE-16). Ferramentas de lint/formatação e auditorias de dependências possuem pendências registradas; o estado do projeto não foi declarado integralmente aprovado.

O primeiro ensaio PHP no sandbox falhou por conexão com PostgreSQL; uma tentativa no banco compartilhado encontrou deadlocks de migração. Esses resultados foram descartados como veredito da aplicação. A execução válida foi feita em um banco criado exclusivamente para esta auditoria. Não executar duas suítes com migrações no mesmo banco.

Não foi medida cobertura percentual. Muitos testes passando não demonstram que um fluxo ausente ou um cenário de concorrência esteja coberto.

## Achados e correções delimitadas

### EE-01 — HTML executável na prévia de modelos de laudo — P1

**Causa e evidência.** `app/Services/ReportSettingService.php`, `syncContents`, persiste o HTML recebido; a validação do controller verifica string. `resources/views/pdf/report_setting_preview_html.blade.php:246` emite `{!! $content->content !!}`. O iframe em `resources/js/Pages/Panel/Manager/ReportSettings/ReportSettingPreviewModal.vue:50` não tem sandbox. Uma sonda renderizou um modelo sintético contendo `<script>` e confirmou que o script foi emitido sem remoção. Não foi executado um ataque em navegador.

**Impacto.** Quem pode editar modelos pode inserir conteúdo executável na prévia apresentada a outro usuário. Há risco de XSS armazenado dentro da origem da aplicação. A permissão para administrar modelos não equivale a autorização para executar JavaScript na sessão do visualizador.

**Correção mínima.** Aplicar sanitização com lista permitida para modelos, preservando a formatação clínica e os placeholders necessários; proteger também registros legados no ponto de renderização. Isolar a prévia com sandbox restritivo e avaliar CSP compatível. Não substituir todas as saídas ricas por texto puro indiscriminadamente.

**Conclusão.** Scripts, atributos de evento e URLs `javascript:` são removidos/bloqueados; tabelas, estilos permitidos e placeholders continuam funcionando. Testes cobrem salvar, atualizar, prévia e conteúdo legado. Uma prévia real confirma o isolamento.

### EE-02 — Dependências com avisos de segurança — P1

**Causa e evidência.** `composer audit --format=json` retornou 44 registros de aviso em 14 pacotes; há duplicação de aviso para a mesma falha Laravel, portanto não são 44 explorações distintas. `npm audit --json` retornou 52 entradas de dependências afetadas: 3 críticas, 33 altas e 16 moderadas. As críticas incluem ferramentas de desenvolvimento/build; não são três endpoints críticos demonstrados.

Há avisos nos componentes usados em execução, como Laravel, Guzzle, Snappy, Vue, Axios e TinyMCE. Exemplo diretamente relacionado ao envio de laudos: o Laravel instalado 12.56.0 está na faixa afetada pelo aviso de CRLF na validação de e-mail. O [aviso oficial do Laravel](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq) descreve as versões corrigidas. Há também aviso para URLs assinadas, relevante ao fluxo de relatórios externos.

**Impacto.** A exposição depende de configuração, opções utilizadas e caminhos alcançáveis. O inventário de pacotes afetados justifica atualização e análise de alcance; não comprova que cada aviso seja explorável neste produto.

**Correção mínima.** Atualizar versões compatíveis e respectivos locks, começando pelas dependências de execução e pela cadeia de build. Analisar pacotes legados sem correção, removendo-os somente se comprovadamente sem uso. Não usar `npm audit fix --force` como solução geral nem exigir migração de major sem necessidade. Avisos não aplicáveis exigem justificativa por ID, caminho de uso e versão, com responsável e revisão futura definida.

Classificar também o código entregue no bundle: estar em `devDependencies` não garante ausência no navegador. Inversamente, um aviso em ferramenta de build não comprova vulnerabilidade alcançável por uma requisição web.

**Conclusão.** Nenhum aviso alto/crítico aplicável permanece sem correção ou mitigação comprovada; todos os demais avisos deste inventário estão corrigidos ou triados explicitamente. Instalação limpa, suítes e builds passam. Avisos novos publicados depois desta referência pertencem ao acompanhamento de segurança, não à reabertura arbitrária de estilo.

### EE-03 — Hash da assinatura inclui estado volátil — P1

**Causa.** `app/Traits/Signable.php:68` calcula o hash antes de `saveQuietly`; `clinicalContent` usa praticamente todos os atributos brutos, excluindo apenas campos de assinatura. Inclui `updated_at`, que o próprio salvamento modifica. Serializar atributos brutos também depende de representação e ordem.

**Impacto.** Uma assinatura pode deixar de verificar após salvamento/reload sem adulteração clínica. Isso compromete a confiança no indicador de integridade.

**Reprodução.** Criar às 12h, carregar novamente, assinar às 12h01 e recarregar resultou em `signed=true` e `integrity_after_reload=false`.

**Correção mínima.** Definir conteúdo clínico canônico, ordenado e versionado, com representação estável de datas e tipos e exclusão de metadados voláteis. Tratar assinaturas existentes sem reescrever silenciosamente sua evidência. Considerar todos os elementos que o contrato de assinatura promete proteger, inclusive conteúdo relacionado quando aplicável.

Os testes em `tests/Feature/MedicalRecord/ContactLensCalculationTest.php` e `tests/Feature/ClinicalSafety/MedicationsAndRiskAlertsTest.php` montam hashes legados diretamente e verificam compatibilidade com colunas novas. Preservar essa cobertura e acrescentar o roundtrip do ato de assinatura pelo serviço real, com relógio avançado e reload. `RecordDraftAndTimerTest.php` já cobre a limpeza de rascunhos ao assinar; ampliá-lo para a atomicidade, sem duplicar toda a suíte.

**Conclusão.** Criar às 12h, assinar às 12h01 e recarregar continua verificando; alteração clínica invalida a verificação; alteração de metadado excluído não a invalida; testes cobrem assinaturas legadas e fusos. O resultado da reprodução deste caso está nas sondas de validação.

### EE-04 — Instância antiga contorna bloqueio do prontuário — P1

**Causa.** Os hooks `updating` e `deleting` de `Signable` consultam `is_locked` da instância carregada, sem garantir que reflita o estado atual persistido. Uma instância carregada antes da assinatura conserva `false`. A assinatura e a alteração não compartilham uma proteção transacional contra concorrência.

**Impacto.** Uma requisição antiga/autosave pode alterar um prontuário que outra requisição acabou de assinar. O indicador de bloqueio pode continuar ativo apesar da alteração.

**Reprodução.** A instância antiga salvou a alteração; a leitura fresca confirmou conteúdo alterado e `is_locked=true`. Não foram usados dados clínicos reais.

**Correção mínima.** Serializar assinatura, edição e exclusão com transação e proteção no banco adequada ao fluxo, verificando o estado atual sob lock. Inspecionar também rascunhos e escritas por query builder que não disparam hooks. Manter assinatura, auditoria obrigatória e limpeza de rascunhos consistentes diante de falha.

**Conclusão.** Duas conexões reais ao PostgreSQL reproduzem editar/assinar e assinar/assinar; somente uma operação válida vence e nenhuma edição posterior persiste. Instância Eloquent antiga também é rejeitada. Falha de auditoria não deixa assinatura parcialmente concluída. Sondas desta auditoria usam a instância antiga como reprodução inicial; não substituem o teste futuro com duas conexões.

### EE-05 — Auditoria do portal pode registrar clínica errada — P1

**Causa e evidência.** `app/Services/DataAccessLogService.php:48` prioriza `selected_entity_id` da sessão sobre a clínica do recurso quando não recebe `entityId`. `PatientPortal/DocumentsController.php:81` e `PatientPortal/LgpdExportController.php:70` não passam essa clínica. O guard do paciente pode coexistir com uma sessão administrativa. A correção recente do controller de relatório externo, que passa a clínica explicitamente, não cobre automaticamente esses outros chamadores. `logMany` também usa a preferência da sessão.

**Impacto.** A trilha de acesso pode faltar na clínica dona do recurso e aparecer em outra clínica. Isso afeta rastreabilidade e segregação de metadados; não foi demonstrado acesso indevido ao documento clínico em si.

**Reprodução.** Paciente da clínica A e sessão selecionando B: `resource_clinic_matches_log=false`, `log_uses_staff_session=true`.

**Correção mínima.** Resolver a clínica a partir do recurso/relacionamento validado ou passá-la explicitamente nos fluxos de portal. Tornar a resolução central coerente para `log` e `logMany`. Distinguir o ator do guard em uso e não depender de sessão administrativa em acessos de paciente, integração ou link público.

**Conclusão.** Paciente da clínica A com sessão administrativa selecionada na B gera log somente na A; logs não aparecem no relatório da B. Cobrir exportação, leitura de documento, rota assinada anônima, ator paciente e lote de recursos.

### EE-06 — Assinatura sem caminho disponível na aplicação — P2

**Causa e evidência.** Há `MedicalRecordSignatureService` e testes que chamam a assinatura. A busca em código da aplicação encontrou o serviço chamando `$record->sign`, mas nenhum controller/rota/ação de interface chamando esse serviço. `PatientDocumentShareService.php:30` exige prontuário assinado para compartilhar documentação. A interface reconhece o estado de assinatura, mas não oferece o ato de assinar.

**Impacto.** O fluxo normal de criar prontuário e disponibilizar documentação ao paciente fica incompleto para registros novos. Isso pode passar nos testes que assinam diretamente o model.

**Correção mínima.** Disponibilizar uma ação explícita de assinatura para o médico autorizado, vinculada à clínica e ao registro corretos, com confirmação das consequências, bloqueio e auditoria. Concluir EE-03/04 antes de disponibilizá-la. Se o produto ainda não entrega assinatura, desabilitar formalmente a promessa desse fluxo; não remover a exigência de assinatura para liberar o compartilhamento.

**Conclusão.** Teste percorre criar → editar → assinar pela rota real → compartilhar → visualizar no portal; recepcionista e médico de outra clínica não assinam; requisição repetida é segura; UI e erros têm tradução.

### EE-07 — Resolver XSD e rejeitar XML inválido no TISS — P2, bloqueia envio real

**Causa e evidência.** `ValidateTissXmlService.php:51` procura `ansTISS.xsd`. Os schemas entregues têm nomes como `storage/app/tiss/schemas/202603/tissV4_03_00.xsd`. Com `require_schema_validation=false`, o serviço retorna válido antes mesmo de analisar XML se não acha schema. Sonda com `<not-valid-xml` e a pasta real retornou `valid=true`, `schema=null` e aviso de validação ignorada. O teste que carrega o XSD diretamente não exercita esse resolver.

**Impacto.** O indicador “validado” pode não significar XML bem formado nem conformidade com o schema. A configuração padrão usa transporte mock; não há comprovação aqui de envio inválido a uma operadora real.

**Correção mínima.** Mapear versão para os schemas efetivamente distribuídos e imports; verificar XML bem formado sempre; exigir validação efetiva no transporte real. Diferenciar simulação, geração, validação e aceite da operadora. Não criar cópia improvisada de XSD apenas para calar o aviso.

**Conclusão.** Testes chamam o serviço de produção com versão entregue, XML válido/inválido, versão desconhecida e schema ausente. Transporte real não envia nem marca como validado quando falta schema. Mock continua explícito e não finge homologação.

### EE-08 — Reserva sobrescreve idempotência TISS concluída — P2, bloqueia repetição segura

**Causa e evidência.** `SendTissBatchService.php:49` reserva a chave antes de verificar `completed`. `reserveIdempotency`, linha 368, faz `updateOrCreate` e escreve `reserved` em toda chamada normal. Assim, o ramo que deveria devolver a transmissão já concluída fica inacessível para essa chave. A tabela tem índice único em `(entity_id, idempotency_key)`.

**Impacto.** Repetir envio concluído pode resultar em violação de unicidade em vez de reaproveitar a resposta. Não foi demonstrada cobrança duplicada; o índice pode impedir a segunda inserção. Também é necessário definir concorrência, timeout e o significado de envio forçado.

**Reprodução.** A chamada isolada ao método de reserva transformou uma chave persistida de `completed` para `reserved`. O transporte não foi chamado.

**Correção mínima.** Preservar estado concluído e reivindicar uma reserva atomicamente. Definir comportamento para reserva em andamento, expirada, falha, timeout e força, em acordo com os índices existentes. Não remover a restrição única para contornar o erro.

**Conclusão.** Duas chamadas com mesmo lote/XML retornam a transmissão apropriada e somente um envio normal chega ao transporte; concorrência, timeout incerto e força têm testes próprios. A sonda local da reserva está registrada; o teste de conclusão deve usar o serviço público inteiro.

### EE-09 — Alinhar filas, tempos e parada dos workers — P2, bloqueia configuração Docker atual

**Causa e evidência.** `.docker/supervisor/easyeye-worker.conf:3` consome `default,high`, com dois processos e timeout 90s. `config/tiss.php:43` publica em `tiss-xml,tiss-send,tiss-returns`, que não estão nessa lista. `config/queue.php` usa `retry_after=90`; existem jobs de AI/importação com timeouts de 150 a 900s. Supervisor aguarda somente 100s para parar.

**Impacto.** Filas podem não ser consumidas; um job longo pode ser disponibilizado novamente antes de terminar, ou ser interrompido em deploy. Isso cria falhas intermitentes e possível repetição de efeitos. A configuração remota descrita em `docs/infra/reverb-ambiente-teste.md` usa um worker e já comenta essa limitação; não se afirma que o remoto execute exatamente o Supervisor Docker.

**Correção mínima.** Declarar consumidores para todas as filas e separar trabalhos longos quando necessário. Garantir `timeout < retry_after` na conexão realmente usada e `stopwaitsecs` acima da maior duração permitida. Documentar variáveis e estratégia de reinício. Redis/cache compartilhado não deve carregar dados de clínica sem chave/escopo apropriado.

**Conclusão.** Um smoke assíncrono com workers reais processa as filas TISS e um job longo sem duplicação; reinício/deploy respeita o trabalho em andamento. Testes com `QUEUE_CONNECTION=sync` não bastam. A [documentação oficial de filas Laravel](https://laravel.com/docs/12.x/queues#job-expirations-and-timeouts) descreve a relação entre timeout e nova disponibilização.

### EE-10 — Limitar geração PDF e limpar temporários em falhas — P2

**Causa e evidência.** `config/snappy.php:47` desativa timeout. `MedicalRecordPdfService.php:147,320` concatena `.html` ao resultado de `tempnam`: o arquivo inicial criado fica sem limpeza. A limpeza do HTML acontece depois da geração bem-sucedida, sem `finally` em todos esses caminhos.

**Impacto.** Renderização travada ocupa recursos indefinidamente; erros deixam temporários, inclusive rodapés com dados da clínica. Isso também afeta laudos externos que geram PDF de forma síncrona.

**Correção mínima.** Timeout finito e compatível com o processo chamador; arquivo temporário único, permissões restritas e limpeza em `finally`. Revisar acesso a arquivos e rede do renderizador, preservando assets clínicos necessários. Não pressupor que a proteção já usada para extração/derivados também limite o Snappy.

**Conclusão.** Geração normal conserva rodapé/imagens/paginação; processo lento termina controladamente; exceção não deixa nenhum dos arquivos de rodapé. O [projeto wkhtmltopdf](https://wkhtmltopdf.org/status.html) recomenda sanitização e isolamento para HTML não confiável; não foi demonstrada execução de código neste ambiente.

### EE-11 — Eliminar dependência do relógio nos testes de documentos — P2

**Causa e evidência.** `RecordDocuments.test.js:247` espera chegada às 08h15 sem fixar hora atual. `CertificateModal.vue:84` preenche esse horário somente se anterior ao relógio atual. Rodando por volta de meia-noite, a suíte JS falhou em um teste. Com `Date` fixado em 11/10/2026 às 12h de São Paulo, os 13 testes desse arquivo passaram.

**Impacto.** A mesma revisão alterna entre verde e vermelho conforme o horário, provocando correções desnecessárias de comportamento clínico válido.

**Correção mínima.** Fixar relógio e fuso no teste e restaurá-los ao final; incluir chegada anterior, futura, ausente e virada de dia. Verificar a regra de atendimento que atravessa meia-noite se esse cenário fizer parte do produto; não inferir essa regra da expectativa de um teste.

**Alteração durante a auditoria.** O arquivo foi modificado fora das ações desta auditoria: passou a congelar `Date` em 15h UTC e ganhou dois testes. A suíte completa passou com 2.506 testes no ambiente atual. Porém, o arquivo com `TZ=America/Los_Angeles` ainda teve 1 falha em 15 testes, agora na linha 259: 15h UTC vira 08h locais, antes de 08h15. O item continua parcialmente resolvido, pois o critério de fuso já fazia parte de EE-11. Usar uma hora civil controlada ou estabelecer explicitamente o fuso do runner e testar a regra temporal evita essa dependência. A referência inicial foi preservada; a variação observada é registrada nas evidências.

**Conclusão.** Suíte passa independentemente de hora/fuso do runner, mantendo testes explícitos de intervalos inválidos. Não mudar a implementação para aceitar horário futuro só para satisfazer a fixture.

### EE-12 — Unificar versão PHP prometida e realmente suportada — P2

**Causa e evidência.** `composer.json` anuncia PHP `^8.2`, mas o lock inclui oito pacotes Symfony 8 de execução que exigem PHP >=8.4. Pest 4 e PHPUnit 12 também impõem requisitos superiores a 8.2. Docker e CI já usam 8.4. O `check-platform-reqs` passou no PHP 8.4 usado aqui; isso não valida 8.2.

**Impacto.** Instalação em ambiente escolhido com base no contrato do projeto pode falhar antes de iniciar a aplicação.

**Correção mínima.** A opção incremental é declarar e documentar PHP 8.4 como mínimo, alinhando instalações e plataforma Composer. Se 8.2 for requisito real de implantação, resolver e testar um conjunto compatível, inclusive ferramentas, em vez de ignorar a verificação de plataforma.

**Conclusão.** README, Docker, CI, Composer e ambiente alvo concordam; instalação limpa e testes na versão mínima declarada passam.

### EE-13 — Resolver baseline de lint/formatação e fixar o contrato de revisão — P2

**Causa e evidência.** ESLint global retornou 421 problemas, sendo 219 erros e 202 avisos. Pint em modo verificação reprovou 37 arquivos. Existem diferenças entre convenções de páginas Inertia e regras genéricas, por exemplo nomes `Index`/`Edit`, e arquivos legados com globals/CommonJS. Avisos de `v-html` exigem análise de origem/sanitização, não são automaticamente 202 vulnerabilidades.

**Impacto.** Revisões podem descobrir dívidas antigas em lotes e misturá-las a regressões. Um gate global recém-exigido falha mesmo quando uma alteração é correta.

**Correção mínima.** Configurar regras por ambiente/padrão real, corrigir erros de código e formatar o conjunto escolhido em mudança separada. Manter baseline versionado para legado quando justificado e gate para arquivos alterados; o CI já faz parte disso. Não desativar validações de segurança nem mudar expectativas funcionais para obter verde. Tipos/análise estática podem ser introduzidos progressivamente; não são uma reescrita obrigatória desta rodada.

**Conclusão.** Comando e alcance do gate estão documentados; zero erro novo nos arquivos alterados; resíduos legados possuem contagem/lista estável e não entram como “novo defeito”. Uma decisão explícita por limpeza global exige zero erro global; não fingir que baseline significa limpeza completa.

### EE-14 — Fechar localização dos fluxos identificados — P2, escopo funcional delimitado

**Causa e evidência.** Há mensagens fixas no login/middleware do portal e em `PatientPortalInvitation`; `TinyMceEditor.vue` fixa `pt_BR`; rodapés PDF têm “Pág.”/“Emitido em”; telas de imagens/dashboard possuem formatação `pt-BR` e valores com `R$` fixos. O projeto já tem infraestrutura de traduções PT/EN.

**Impacto.** Parte dos fluxos ignora o idioma escolhido; datas/horários podem ficar inconsistentes. Moeda do dado e idioma do formato são decisões diferentes: mudar locale não converte BRL em outra moeda.

**Correção mínima.** Nesta rodada, limitar a portal (login, sessão, convite), documentos/PDF, editor e telas de imagens/indicadores identificadas. Usar catálogo existente, locale e timezone da aplicação/usuário; preservar datas civis sem conversão indevida de fuso e manter a moeda efetiva do registro. Não transformar isso em tradução ilimitada de toda a aplicação.

**Conclusão.** Cenários PT/EN nesses componentes e mensagens de erro/estado vazio passam; datas civis, instantes e moeda são testados com locale/fuso explícitos; editor usa idioma suportado com fallback definido.

### EE-15 — Completar comportamento de teclado do modal compartilhado — P2

**Causa e evidência.** `resources/js/Components/Panel/CenteredModal.vue` declara `aria-modal` e restaura parcialmente o foco ao fechar, mas não posiciona foco ao abrir, não contém Tab, não trata Escape e não torna o fundo inerte. Alguns diálogos administrativos possuem implementação própria.

**Impacto.** Usuários de teclado podem continuar operando o conteúdo atrás do diálogo ou não alcançar prontamente a ação clínica. Atributos ARIA isolados não implementam a interação.

**Correção mínima.** Implementar o contrato no componente compartilhado, incluindo nome acessível, entrada/retorno de foco, contenção, Escape e gestão de modais aninhados/editor. Migrar somente os diálogos críticos identificados na verificação, sem redesenhar o produto inteiro.

**Conclusão.** Testes de teclado e verificação real nos tamanhos 320/768/1280 px mostram foco correto, ausência de interação com fundo e conteúdo/ações acessíveis. O [padrão oficial WAI-ARIA para diálogos modais](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/) fornece os comportamentos de referência.

### EE-16 — Impedir espera ilimitada no helper de concorrência — P2

**Causa e evidência.** `tests/Integration/IntegratorP2ConcurrencyTest.php:88` chama `pcntl_waitpid` bloqueante sem prazo para o encerramento do filho. O prazo de 10s cobre a barreira inicial, não o trabalho/encerramento. A primeira execução desta auditoria ficou presa nessa espera: os dois JSONs de resultado já continham HTTP 200/404, o pai esperava os filhos, e ambos os filhos estavam em espera `futex`. A consulta ao banco exclusivo não mostrou requisições ativas nesse momento. A suíte foi encerrada de forma controlada, sem considerá-la aprovada.

**Impacto.** O próprio teste pode manter CI/revisão rodando indefinidamente. Resultado da requisição escrito em disco não demonstra que o processo terminou nem que o teste completo passou.

**Correção mínima.** Espera com `WNOHANG`, prazo total explícito, verificação do status de saída e limpeza garantida de todos os filhos. Executar testes com fork em processo separado quando misturados a extensões com threads, ou configurar o ambiente do runner de forma comprovadamente compatível. A repetição de diagnóstico usa `MAGICK_THREAD_LIMIT=1 OMP_NUM_THREADS=1` desde a inicialização. A [documentação oficial ImageMagick](https://imagemagick.org/resources/) descreve o limite de threads; isso, sozinho, não prova qual extensão causou a espera observada. Uma sonda pequena de Imagick/fork terminou normalmente com e sem limite, portanto a interação depende do contexto da suíte e a causa nativa exata permanece por confirmar.

**Conclusão.** Filho lento, travado ou com erro é detectado dentro do prazo e produz falha legível; nenhum processo órfão permanece. A combinação de testes de imagem e concorrência termina no runner real; não trocar falha por skip silencioso nem aceitar resultados de filhos mortos pelo watchdog como teste aprovado.

**Validação da condição de diagnóstico.** A repetição inteira com as duas variáveis terminou com saída 0 e os 10 casos de integração aprovados. Os processos encerraram normalmente, sem intervenção para fazer os testes passarem. Isso sustenta o uso dessa condição no runner, mas não implementa o prazo ausente do helper; EE-16 continua aberto.

## O que não bloqueia esta entrega

São backlog, salvo defeito reproduzido: migrar Laravel para outra major; converter todo JavaScript em TypeScript; dividir todos os componentes grandes; introduzir microserviços/DDD/repositórios/DTOs por preferência; substituir toda biblioteca de interface; adicionar testes que apenas repetem implementação; renomear páginas Inertia para satisfazer uma configuração genérica.

Links externos assinados são credenciais por posse do link. Ausência de login administrativo nessa rota não é, por si só, falha; há assinatura, expiração e limitação de requisições. Revogação individual, política de cache/referrer e confirmação de destinatário podem ser evoluções de produto conforme o contrato. Não foi provado vazamento de foto legada nem IDOR universal; referências públicas merecem inventário se arquivos reais estiverem nessa superfície, sem declarar exposição antes da evidência.

Responsividade completa, análise de carga, restauração de backups e homologação externa ficam como verificações específicas de implantação. Não exigir sua repetição a cada revisão de um arquivo sem relação.

## Protocolo de encerramento

1. Preservar esta referência. Registrar os commits de correção e relacioná-los aos IDs do checklist; não expandir o escopo para refatorações estéticas durante uma correção.
2. Corrigir P1 primeiro; concluir P2 aplicáveis aos fluxos entregues. Se TISS real/assinatura ainda não forem entregues, desabilitá-los/documentá-los explicitamente e registrar a condição de habilitação. Isso não autoriza manter um caminho inseguro ativo.
3. Cada defeito confirmado recebe teste que falha antes e passa depois. Concorrência usa PostgreSQL e múltiplas conexões; filas usam worker real; segurança verifica rejeição e fluxo permitido.
4. Após o conjunto de correções, fazer **uma verificação final**: instalação conforme plataforma, testes PHP em banco exclusivo, testes JS com relógio controlado, build cliente/SSR, lint no alcance contratado, auditoria triada e smoke dos fluxos afetados. Não exigir sucessivas revisões genéricas até a IA perder criatividade.
5. A revisão final verifica somente os IDs, suas regressões e os requisitos existentes. Um novo bloqueador precisa de arquivo/linha, cenário reproduzível, resultado esperado versus observado e impacto concreto. Duplicatas são anexadas ao ID existente. Preferências e hipóteses sem evidência vão para backlog.
6. Concluir quando não houver P1 nem P2 aplicável aberto e todos os critérios tiverem evidência. Congelar o resultado em um commit/release. Um defeito real descoberto depois continua merecendo correção, com novo ticket; não muda retroativamente o escopo concluído.

Sugestão de instrução para a revisão final:

> Revise o diff de correções contra `docs/auditoria-encerramento-2026-10-11/CHECKLIST.md`, os testes de aceitação e os requisitos já documentados. Relate somente defeitos reproduzíveis ou regressões, com evidência e impacto. Vincule duplicatas ao ID existente. Não introduza refatorações, novas convenções ou requisitos. Separe sugestões opcionais do veredito. Se não houver bloqueador nesse escopo, declare o gate concluído.

Esse protocolo reduz o ciclo de revisão sem instruir a ferramenta a esconder problemas reais.
