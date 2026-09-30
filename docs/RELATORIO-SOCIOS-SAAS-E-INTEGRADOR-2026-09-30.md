# EasyEye — Relatório consolidado para os sócios

## SaaS e Integrador de Equipamentos

**Data:** 30 de setembro de 2026

**Período principal:** 17 a 30 de setembro de 2026

**Projetos:** EasyEye SaaS e EasyEye Integrator

**Referências:** SaaS `8dac5624df679039ee30496db3532f75c4c798c4`; integrador `f7e8f958850441512d69141751bc850e0339a8da`.

**Situação da entrega:** publicada no ambiente de teste e disponível para homologação dos sócios, conforme confirmação do responsável.

**Atualização após a revisão:** o responsável confirmou TISS 4.03.00 como a única versão implementada. As traduções públicas da landing e do login foram corrigidas para essa versão.

> Este documento consolida as entregas recentes dos dois repositórios, já publicadas no ambiente de teste para homologação dos sócios. “Implementado” significa identificado no código analisado; a publicação em teste foi confirmada pelo responsável. A conclusão da homologação interna e a liberação em produção são etapas distintas. O produto continua em pré-lançamento, sem clínicas contratadas informadas pelo responsável; os benefícios descritos são esperados, não resultados comerciais medidos.

## 1. Resumo executivo

O ciclo recente aproximou o EasyEye de um fluxo integrado entre recepção, consultório, equipamentos e financeiro. O SaaS recebeu evoluções no faturamento, repasse médico, imagens, laudos, cadastros, importação, painel e comunicação comercial. O integrador recebeu melhorias na identificação dos exames, configuração dos aparelhos, operação da fila e proteção dos dados locais.

A entrega de maior porte no SaaS foi o **repasse médico**, desde regras e apuração até pagamentos parciais e demonstrativos. No integrador, os destaques são a **conferência visual antes de vincular o exame ao paciente**, a identificação por OCR configurável e a revisão da fila para evitar reenvios indevidos após limpeza ou exclusão.

A conexão entre os projetos foi ampliada para transportar data/hora real da captura e observações, apoiar a identificação do paciente e executar um conjunto restrito de comandos operacionais solicitados pelo SaaS. A API continua fechada para os integradores autorizados da clínica.

| Frente | Entrega consolidada | Benefício esperado | Situação identificada |
| --- | --- | --- | --- |
| Financeiro do SaaS | Evolução de faturamento, glosas, caixa, relatórios e tabelas de preço | Menos controles paralelos e conferência mais clara | Implementado; integração real com operadoras depende de configuração e validação |
| Repasse médico | Regras, recebimentos, divisão, fechamento, pagamentos e área do médico | Rastreabilidade da remuneração médica | Implementado; validação operacional pendente |
| Consultório e exames | Agrupamento, fluxo de laudos, montagem de imagens e importações | Menos etapas para organizar e consultar informações | Implementado; algumas funções dependem da infraestrutura |
| Cadastros e painel | Listagens consistentes, máscaras, permissões e tour por perfil | Uso mais previsível e orientação inicial | Implementado |
| Landing e cadastro | Revisão responsiva, contato, planos e conteúdo comercial | Oferta mais compreensível e coerente com o pré-lançamento | Implementado; inspeção visual final pendente |
| Equipamentos | Configuração por função Padrão/OCR/DICOM | Configuração orientada ao modo de captura do aparelho | Implementado; compatibilidade deve ser validada por aparelho |
| Fila local | Diagnóstico, conferência, vínculo manual, exclusão e restauração | Facilitar suporte e reduzir envios incorretos ou repetidos | Implementado |
| OCR e metadados | Leitura por regiões, origem do identificador, olho e data/hora | Reduzir digitação manual e preservar contexto do exame | Implementado; exige configuração e conferência |
| DICOM | Recepção e lista de trabalho, com correções de isolamento e limites | Integrar aparelhos pela rede quando compatíveis | Base iniciada em 16/09 e refinada no período |
| Segurança local | Criptografia de arquivos e campos selecionados, correção do Keychain | Reduzir exposição dos dados mantidos pelo integrador | Implementado com limites de cobertura explicitados |
| Comandos remotos | Solicitações de sincronização e diagnóstico com confirmação | Diminuir a necessidade de acesso remoto para tarefas simples | Implementado; sem tela de disparo no Manager |
| RPA | Automação inicial do software de equipamento no Windows | Alternativa quando o software não exporta a imagem | Implementação inicial; validação operacional com aparelho pendente |

### Pontos de atenção para decisão dos sócios

- **TISS:** o motor e a comunicação pública agora indicam 4.03.00, única versão implementada. A divergência de texto foi corrigida no workspace. A homologação TISS não foi obtida.
- **Publicação:** as entregas foram publicadas no ambiente de teste e estão disponíveis para homologação dos sócios. A aprovação dessa etapa e a posterior liberação em produção permanecem como próximos marcos.
- **Homologação:** o ambiente de teste está disponível para os sócios avaliarem os fluxos, a usabilidade e as regras de negócio. Desenvolvimento, infraestrutura e validações técnicas permanecem centralizados no responsável técnico pelo projeto.
- **Prova comercial:** métricas e depoimentos sem comprovação foram removidos; a estrutura permanece oculta para futura publicação de dados reais.

## 2. Escopo e método

O recorte foi ampliado em relação ao relatório anterior, que tratava somente de 29–30/09 no SaaS. Foram examinados o histórico de commits, os diffs, implementações atuais, testes existentes e documentação dos dois projetos entre **17 e 30/09/2026**. A implementação inicial do servidor DICOM em 16/09 aparece apenas como contexto necessário para entender as melhorias seguintes.

As seções descrevem o estado final consolidado, evitando contar revisões sucessivas como entregas independentes. Recursos anteriores são identificados quando relevantes. Planejamentos comerciais, protótipos e documentação não são apresentados como funcionalidades prontas.

O código atual prevalece sobre comentários ou documentos desatualizados. Quando há conflito material entre implementação e comunicação, ele é registrado como pendência. Não houve alteração funcional dos projetos durante a elaboração deste relatório.

A situação de publicação foi atualizada com base na confirmação do responsável: o ambiente de teste já está disponível para homologação dos sócios. Essa informação complementa a análise do repositório; os resultados da homologação deverão ser registrados à medida que os fluxos forem conferidos.

### Guia de leitura

- [Financeiro e TISS](#3-financeiro-faturamento-e-tiss-no-saas), [repasse médico](#4-módulo-de-repasse-médico) e [operação clínica/cadastros](#5-operação-clínica-imagens-importação-e-cadastros).
- [Painel e tour](#6-painel-orientação-e-produtividade), [conexão entre os projetos](#7-conexão-entre-saas-e-integrador), [landing](#8-landing-page-e-experiência-de-navegação), [conteúdo comercial](#9-conteúdo-comercial-adequado-ao-pré-lançamento) e [hero](#10-hero-e-mockup-calibração-de-precisão).
- [Integrador de equipamentos](#11-integrador-de-equipamentos).
- [Homologação e responsabilidades](#12-homologação-e-responsabilidades), [pendências e próximos passos](#13-pendências-e-próximos-passos) e [referências/cronologia](#14-evidências-e-cronologia).

## 3. Financeiro, faturamento e TISS no SaaS

O financeiro já existia. Neste ciclo foram consolidados o faturamento, o tratamento de glosas, o caixa, os relatórios, o BI e as operações em lote.

### 3.1. Faturamento individual e em lote

A geração de guias e lotes foi conectada ao domínio TISS, incluindo vínculos com operadora, contrato, guia, lote e catálogo TUSS. Convênios com registro ANS seguem esse fluxo; os demais seguem cobrança particular/tabela própria.

O caminho “A faturar → Guias → Lotes” passou a ter apresentação mais consistente, com busca, filtros, ordenação, paginação no servidor e ações conforme a situação do registro.

As operações entregues ou ampliadas incluem:

- Faturar atendimentos individualmente e montar lotes.
- Consultar elegíveis e pendências de beneficiário, carteirinha, autorização, médico, códigos e valores.
- Corrigir dados e executar nova pré-validação.
- Acrescentar guias a lotes em rascunho e reprocessar pendências.
- Cancelar guias ou lotes em rascunho com justificativa, preservando histórico e recalculando totais.
- Importar retorno XML manualmente.
- Registrar recebimentos individuais, de uma seleção de guias ou de um lote.

A inclusão/reprocessamento de guias em lotes e o registro de recebimentos em massa possuem teto de 200 guias por requisição. Na inclusão em lote, guias válidas podem seguir enquanto as inválidas permanecem pendentes e são informadas. No recebimento em massa, determinados bloqueios recusam a operação inteira, evitando gravação parcial. Registros já enviados não são tratados como rascunhos livremente editáveis.

**Benefício esperado:** resolver pendências no próprio fluxo e reduzir repetição de operações.

### 3.2. XML, cadastro e limites da integração TISS

O gerador foi revisto para o layout **4.03.00**, com envelope, namespace, cabeçalho, epílogo e estruturas de consulta/SP-SADT. Entraram dados cadastrais necessários à geração, como CNES da clínica e CBO do médico, além de identificação profissional e UF.

Foi adicionado um aviso de conferência quando a descrição de um procedimento monocular/binocular não informa o olho. A lateralidade segue em texto compatível com o layout, sem representar um campo estruturado próprio nem um bloqueio universal de schema.

Há três limites materiais:

1. **Versão divulgada:** o código usa versão interna `202603` e layout `4.03.00`. A landing e o login foram corrigidos para divulgar somente TISS 4.03.00, conforme a versão implementada confirmada pelo responsável.
2. **Validação estrutural:** o serviço procura `ansTISS.xsd`, enquanto os arquivos versionados encontrados usam `tissV4_03_00.xsd`. A configuração padrão permite continuar com aviso quando o schema está ausente. O teste que usa diretamente o XSD não comprova validação obrigatória no fluxo operacional.
3. **Transporte externo:** o padrão de configuração é simulado (`mock`); endpoint, credenciais e contrato com a operadora precisam estar definidos para usar o cliente HTTP real. Aceitação pela operadora e homologação não foram comprovadas.

Embora o gerador possua estruturas de consulta e SP-SADT, o fluxo financeiro auditado cria guias de consulta. Não se deve anunciar todos os tipos de guia como operações disponíveis na interface.

### 3.3. Glosas e recursos

Foi ampliado o ciclo de glosas: motivo, valor, prazo, abertura de recurso, registro de envio, decisão aceita/rejeitada e recuperação total ou parcial, com histórico.

A tela funciona como uma fila de trabalho, com pendentes/resolvidas, busca, filtros, vencimentos, próxima ação, indicadores e resumo por operadora. A identificação de glosas de retorno sem guia reconhecida foi reforçada para evitar sobreposição silenciosa de ocorrências; elas continuam exigindo conferência manual.

“Recuperado” significa valor aceito no recurso, não dinheiro recebido. A ação de enviar recurso registra situação, data, prazo e histórico; não foi identificada transmissão externa nessa ação. Os prazos são configuráveis e não devem ser apresentados como regras regulatórias universais.

### 3.4. Caixa e fechamento

Lançamentos, origem dos valores, indicadores realizados/projetados e mensagens de bloqueio foram refinados. O recebimento de guia gera a receita correspondente no Fluxo de Caixa.

O fechamento oferece prévia, contagem de lançamentos, pendências, totais por forma de pagamento e verificação de sobreposição com períodos fechados. A reabertura registra motivo e histórico.

Há proteção contra segundo lançamento de recebimento de uma mesma guia. Um valor recebido abaixo do esperado não deve ser confundido com parcelamento ilimitado da mesma guia. Recebimento da clínica e pagamento de repasse ao médico continuam sendo operações distintas.

### 3.5. Tabelas de preços

A grade por convênio recebeu filtros, distinção de preço próprio/herdado, indicação de alterações, proteção contra saída com edições não salvas, reajuste percentual e cópia de outro convênio com prévia.

Reajustar ou copiar altera primeiro a grade; a gravação só ocorre após “Salvar”. O backend atualiza as linhas explicitamente alteradas. Omitir uma linha não a exclui; limpar o valor remove aquela configuração própria. A opção de faturar por guia TISS considera a elegibilidade do convênio.

### 3.6. BI e relatórios

O BI e o relatório de convênios passaram a compartilhar a fonte dos cálculos de faturado, recebido e glosado. Convênios com nomes iguais são separados pelo identificador, e os excluídos continuam representados no histórico.

As telas receberam gráficos, indicadores, períodos, detalhamento e exportações. O BI informa quando os dados foram gerados e permite atualização manual; os caches são de 10 minutos para o resumo e 30 minutos para tendência.

O relatório de caixa ganhou lista paginada, busca, filtros, ordenação, resumo por categoria/dia e PDF. Seu recorte é limitado a 366 dias. Filtros da lista não alteram automaticamente os agregados nem o conjunto exportado.

As exportações incluem CSV/XLSX e alternativa XLS. PDF depende de `wkhtmltopdf`; XLSX depende de `ZipArchive`. O relatório de convênios não possui modelo PDF específico. Planilhas de convênios reduzem a identificação de pacientes a código/iniciais e registram a exportação em auditoria.

### 3.7. Concorrência, isolamento e proteção das operações

Foram acrescentados ou reforçados índices, travas e validações para reduzir duplicidade de guias, receitas, numeração e ações simultâneas de recebimento, fechamento e recurso.

O estado é conferido novamente antes de ações sensíveis; lançamentos são coordenados com períodos de caixa fechados; referências e operações são limitadas à clínica e aos perfis autorizados. Também foram reforçadas a neutralização de fórmulas em planilhas e a redução de dados sensíveis em erros de importação XML.

Essas são proteções específicas identificadas no código, sem equivaler a uma auditoria independente de segurança ou à aprovação operacional de toda a área financeira.

## 4. Módulo de repasse médico

### 4.1. Regras de remuneração

Foi implementada a configuração de regras de repasse por médico, serviço, tipo de atendimento e pagador, contemplando consultas, exames e procedimentos.

As regras permitem:

- Definir remuneração percentual ou por valor fixo.
- Estabelecer vigência e iniciar uma nova vigência sem apagar o histórico anterior.
- Duplicar regras para facilitar configurações semelhantes.
- Definir participantes e a divisão da parcela do grupo nas regras percentuais.
- Validar referências, períodos e composição dos beneficiários.

**Impacto esperado:** tornar explícitas as condições de remuneração e reduzir a dependência de cálculos avulsos e interpretações diferentes entre administração e médicos.

### 4.2. Apuração da produção

A apuração reúne atendimentos realizados, procedimentos executados e exames provenientes dos equipamentos. O tratamento das fontes procura evitar contagem duplicada de um mesmo serviço entre agenda, prontuário e equipamento.

A interface disponibiliza indicadores, resumo por serviço, itens detalhados, filtros e alertas. É possível filtrar situações de repasse, tipos de serviço, recebimentos e alertas dos itens. Exames sem médico vinculado geram um aviso separado para conferência.

### 4.3. Repasse por recebimento efetivo

A implementação inicial foi ampliada para separar **valor previsto, valor liberado e valor pago**. O cálculo passou a considerar o que a clínica efetivamente recebeu, em vez de tratar todo valor cobrado como dinheiro disponível.

O fluxo distingue valores cobrados, recebidos, a receber, glosados e sem confirmação de caixa. Uma guia marcada como paga, isoladamente, não equivale a uma receita paga no Fluxo de Caixa.

Quando há recebimentos parciais, o sistema libera a diferença entre o repasse devido sobre o recebido acumulado e o que já foi liberado. Complementos e estornos podem ser considerados nos fechamentos seguintes.

**Exemplo ilustrativo, sem representar dados reais:** para um serviço de R$ 1.000 com repasse de 20%, sem deduções nem divisão, um recebimento inicial de R$ 400 corresponde a R$ 80 de repasse liberável. Ao receber os R$ 600 restantes, o complemento é de R$ 120, descontando os R$ 80 já liberados.

Os fechamentos anteriores do regime de produção permanecem identificados nesse regime. A evolução não os converte automaticamente em novas parcelas por recebimento.

### 4.4. Alocação manual de recebimentos

Foi adicionada a possibilidade de vincular parte de uma receita avulsa já paga a atendimentos, exames ou procedimentos. A operação respeita o saldo disponível da receita e possui fluxo próprio de estorno.

**Impacto esperado:** permitir rastrear recebimentos que não chegaram ao caixa com o vínculo necessário para a apuração automática, mantendo a relação entre o dinheiro recebido e o serviço correspondente.

### 4.5. Deduções e divisão entre médicos

O módulo passou a aceitar taxas de cartão, imposto e administração com vigência. Nas regras percentuais, as deduções formam a base líquida antes da divisão da parcela do grupo.

As deduções incidem sobre o bruto, sem cálculo em cascata. A taxa de cartão considera a parcela paga com cartão no balcão. Trata-se de configuração operacional: **não foi implementado um mecanismo automático de determinação de tributos ou de conformidade fiscal**.

A divisão pode incluir o executor e médicos fixos participantes da regra, inclusive quando o mesmo médico acumula papéis. Os percentuais devem totalizar 100% da parcela do grupo.

As regras de valor fixo atendem ao executor, com liberação proporcional ao recebimento, sem a mesma divisão e deduções das regras percentuais. A regra e a divisão utilizadas no primeiro fechamento válido ficam preservadas para as parcelas seguintes do atendimento.

### 4.6. Fechamento, ajustes e histórico

O fechamento grava um retrato do cálculo, das regras, dos recebimentos e da divisão utilizados. Se a apuração mudar entre a prévia conferida e a confirmação, o fechamento é recusado para exigir nova conferência.

Foram implementados ajustes manuais antes de pagamentos válidos e reabertura por cancelamento justificado. A reabertura exige administrador e ausência de pagamento válido. O histórico é preservado, incluindo fechamentos cancelados.

### 4.7. Pagamentos parciais e integração com o caixa

É possível registrar um ou vários pagamentos de um fechamento, acompanhando saldo e situações como “Fechado”, “Pago em parte”, “Pago” e “Cancelado”.

Cada pagamento de valor positivo gera a despesa correspondente no Fluxo de Caixa. Um fechamento de total zero pode ser concluído sem gerar despesa. O fluxo respeita períodos de caixa fechados e exige que correções de lançamentos vinculados sejam feitas pelo estorno apropriado.

**Distinção operacional:** o sistema registra o pagamento e sua repercussão no caixa. Não foi identificada execução de transferência bancária nesse fluxo. “Parcela liberada para fechamento” e “pagamento ao médico” são etapas diferentes.

### 4.8. Demonstrativos e “Meus repasses”

Foram implementados demonstrativos por tipo de serviço, geração de PDF e exportação em CSV/XLSX, com alternativa XLS quando necessária.

A área “Meus repasses” permite ao médico consultar seus próprios fechamentos válidos e baixar demonstrativos, desde que o administrador habilite essa visão para a clínica. Produção ainda pendente e fechamentos cancelados não são expostos nessa área.

A apresentação individual omite informações internas de pagamento e nomes da equipe. Quando o médico é somente participante da divisão, o paciente é apresentado por iniciais; as planilhas também utilizam iniciais e código.

### 4.9. Controles e dependências

Foram incorporados controles de isolamento por clínica, papéis autorizados, validação de saldos e referências, bloqueios transacionais e índices para reduzir o risco de alocação excessiva, parcelas repetidas e pagamentos concorrentes inconsistentes.

Consultas de demonstrativos e exportações são registradas em auditoria sem reproduzir dados de pacientes no registro de acesso. Receitas com alocação ativa e despesas de repasse ficam protegidas contra alteração direta no caixa.

O funcionamento depende da qualidade dos cadastros, regras, vínculos entre serviço e cobrança e baixas no caixa. O PDF depende do gerador `wkhtmltopdf`. Esses controles e dependências foram identificados no código; não equivalem a uma auditoria financeira ou de segurança independente.

Os limites operacionais identificados são: fechamento de até 366 dias, sem data final futura; busca de atos para liberação nos últimos 24 meses; e seleção de receitas avulsas dos últimos 365 dias, limitada a 200 opções.

## 5. Operação clínica, imagens, importação e cadastros

### 5.1. Organização das imagens e produção de laudos

O gerenciador de exames passou a organizar os grupos por equipamento ou tipo de exame, preservando data e relação entre imagens do mesmo exame. A seleção de grupos diferentes permite iniciar uma sequência de laudos separados, com confirmação, progresso e acesso aos PDFs produzidos.

O comparador, que já existia, foi corrigido para abrir de um a quatro painéis conforme a seleção, sem painéis excedentes nem ocultação indevida de miniaturas. O filtro por olho permanece no modo específico OD/OE.

Também foram entregues:

- Biblioteca pessoal de até 30 frases rápidas por médico, com edição e inserção no editor.
- Inserção de imagens selecionadas no laudo.
- Extração do texto existente em PDFs dos equipamentos para aproveitamento no editor.
- Montagem de duas a cinquenta imagens em um PNG, com uma a quatro colunas e ordem definida pela seleção.
- Prioridade operacional manual de uma a cinco estrelas, com opção de limpar.
- Autoria do laudo atribuída ao médico que efetivamente salva/assina, inclusive quando outro médico abriu o prontuário.

**Limites:** extrair texto de PDF não é OCR nem interpretação clínica; documentos digitalizados ou protegidos podem não fornecer texto. A prioridade é definida pela equipe, sem algoritmo de triagem. A montagem depende de Imagick, cuja instalação não está declarada no Dockerfile examinado. Interromper uma sequência preserva os laudos já salvos e não conclui os restantes automaticamente.

### 5.2. Importação de médicos, pacientes e agendamentos

Foram criados fluxos de importação de médicos e agendamentos por CSV, com modelo de arquivo, leitura dos cabeçalhos, prévia das primeiras cinco linhas, aviso de colunas desconhecidas/obrigatórias e confirmação antes do processamento em fila. As telas mostram progresso, importados, ignorados e erros, com exportação dos erros em CSV.

Na importação de médicos, o sistema cria os registros e vínculos necessários. Novos usuários recebem senha aleatória e link para defini-la; a planilha não transporta senhas. Contas já existentes têm proteções para preservar nome, senha e titularidade.

Agendamentos podem localizar médicos por código antigo ou CRM e pacientes por código antigo ou CPF. A planilha contempla data, situação, tipo de atendimento, especialidade, convênio, contatos e observações.

Pacientes, médicos e agendamentos passaram a manter um **código de importação separado do código interno**. Esses identificadores também são utilizados nos fluxos correspondentes da API e do integrador.

A importação de pacientes recebeu melhorias de acompanhamento e cancelamento. Foi introduzida a referência global “Particular”; convênio vazio utiliza Particular, preferindo o cadastro da clínica. A comparação de nomes ignora diferenças de acentos/maiúsculas; convênio desconhecido gera erro na linha.

**Limites operacionais:** o formato implementado é CSV, com dependência do worker. Cancelar não desfaz linhas já importadas. Importar agendamentos cria registros e não estabelece sincronização contínua com outro sistema. Histórico pode ficar fora da grade atual do médico; conflitos de horários ativos continuam sujeitos à restrição do banco. O médico precisa ser encontrado; o agendamento pode manter apenas o nome do paciente quando não há vínculo cadastral. Não há comprovação de migração de uma clínica real neste relatório.

### 5.3. Miniaturas e localização dos exames

A geração de miniaturas foi corrigida para ocorrer após a confirmação da transação, evitando que o worker procure um exame ainda não disponível. O visualizador utiliza miniaturas quando existentes, com alternativa para a imagem original.

Foi disponibilizado o comando `eye-images:backfill-derivatives` para enfileirar a geração do histórico pendente, com recortes e reprocessamento. A presença do comando não comprova execução sobre o histórico.

A tela ganhou período personalizado, integrado aos demais filtros. Esse filtro considera a data de criação do registro (`created_at`), sem representar uma data clínica independente. O recebimento pela API também passou a recusar identificadores ambíguos e a resolver equipamentos dentro do integrador autenticado.

### 5.4. Fluxo da consulta e qualidade dos cadastros

Entrou a situação **“Retornando à consulta”**, entre exame e consulta. Ela mantém o paciente como presente na clínica e preserva o horário de chegada. Agenda, calendário e prontuário reconhecem o novo estado.

As transições foram centralizadas para gravar estado e histórico de forma consistente, com tratamento de concorrência e duplo clique. Quando a clínica exige lançamento no caixa para concluir o atendimento, a regra também se aplica a formulário, prontuário e ações em lote. Importação de histórico fica fora dessa exigência; operações em lote informam as linhas bloqueadas e podem ter sucesso parcial.

Foi criada uma diretiva compartilhada de máscaras para CPF, CNPJ, telefone e CEP, acompanhada de normalização no servidor. CNPJ alfanumérico é preservado, e números internacionais não são convertidos indevidamente em telefones brasileiros. Máscara não equivale a validação cadastral oficial.

Outros ajustes incluem mostrar/ocultar senha no cadastro de médico, desabilitar carteirinha para Particular, melhorar alinhamentos e modo escuro do prontuário e corrigir convênios com nome formado apenas por asteriscos quando existe razão social utilizável.

Foram reforçadas proteções sobre identidade compartilhada entre clínicas, exclusão de pacientes/médicos ainda referenciados, importação por CPF, restauração de prontuário do paciente correto e seleção do médico na prévia de modelos.

### 5.5. Áreas administrativas com comportamento consistente

Listagens de catálogos, médicos, estoque, perfis, usuários e modelos documentais foram aproximadas do padrão de pacientes: busca, ordenação controlada, tabela/cartões, colunas, paginação e mensagens de retorno. Ações preservam filtros, ordenação e página quando partem da listagem.

O modo tabela/cartões é salvo no navegador. Essa preferência não é sincronizada entre dispositivos como a conclusão do tour.

No estoque, foram revisadas telas de produtos, lentes, movimentações, pedidos, fornecedores, contagens e relatórios, com português/inglês e formatação conforme o idioma. Relatórios receberam tratamento de datas inválidas, ordenação por aba e proteção de texto no CSV. As agregações dos relatórios continuam em memória; não houve paginação no servidor de todo relatório existente.

Perfis fixos continuam somente para consulta. A gestão de usuários diferencia proprietário, própria conta e perfis adicionais, explica ações indisponíveis e usa painel lateral com retorno de foco. Restaurar usuário passou de GET para PATCH, passando pelo fluxo de proteção de alterações de estado.

Modelos documentais receberam filtros de categoria/situação, busca por título/descrição, carregamento da origem sem consulta extra por linha e mensagens para falhas de adoção/reimportação. O editor não foi refeito nesse commit.

**Benefício esperado:** reduzir diferenças desnecessárias entre telas e preservar o contexto do trabalho. Estoque, comparador, perfis e modelos são módulos anteriores que receberam evolução.

### 5.6. Relatório de parceiros na administração do SaaS

Foi corrigida a exportação Excel do relatório de parceiros, incluindo a dependência necessária e o uso do estado real do cadastro para contar e indicar parceiros ativos. Entraram testes específicos desse relatório.

A gestão de parceiros, leads e comissões já estava presente; a correção não comprova existência de parceiros comerciais contratados nem execução do plano comercial posteriormente documentado.

## 6. Painel, orientação e produtividade

### 6.1. Tour guiado por perfil

Foi criado um tour para apresentar o Dashboard e a navegação principal. Ele explica as áreas disponíveis, troca de clínica, idioma, tema, conta, assistente e ajuda.

O tour:

- Inicia automaticamente no Dashboard para quem ainda não viu a versão atual.
- Pode ser reaberto pelo botão de ajuda.
- Considera o perfil, os itens visíveis e os acessos disponíveis ao usuário.
- Segue a ordem personalizada das seções do Dashboard.
- Salva conclusão ou dispensa por usuário e perfil, permitindo persistência entre dispositivos.
- Considera teclado, foco, telas móveis, modo escuro e preferência por movimento reduzido.
- Carrega a biblioteca e seus estilos apenas quando necessário.

Durante acesso de suporte por impersonação, não inicia automaticamente nem grava a conclusão na conta representada; ainda pode ser aberto manualmente.

**Limite de escopo:** a orientação específica de conteúdo cobre inicialmente o Dashboard. Nas demais páginas existe orientação da navegação geral, sem representar um tutorial detalhado de todos os módulos.

### 6.2. Acessos coerentes com as permissões

Alguns links e atalhos podiam levar usuários a telas sem autorização. O Dashboard e o menu passaram a refletir melhor as permissões efetivas das rotas.

Indicadores, atalhos, boas-vindas, agenda e pacientes recentes consideram um mapa de acessos. Quando o usuário não tem permissão, o indicador pode continuar informativo, mas o acesso correspondente é omitido.

Essa mudança melhora a coerência da interface; ela não amplia privilégios dos perfis.

### 6.3. Personalização mais estável

A ordem escolhida para as seções podia ser perdida quando alertas de estoque apareciam ou desapareciam. A normalização passou a preservar a posição das seções conhecidas, inclusive das temporariamente ocultas, descartar entradas inválidas ou repetidas e acrescentar novas seções ao final.

A ordenação e os atalhos favoritos já existiam. A entrega recente tornou esse comportamento mais robusto e traduziu os textos relacionados.

### 6.4. Atualização, agenda e navegação direta

- O horário da última atualização só avança quando a atualização realmente termina com sucesso, sem simular informação recente após falhas.
- A agenda de hoje informa quando está mostrando apenas parte das consultas; permanece o limite de 25 registros no resumo.
- A indicação de chegada recebeu tradução e rótulo acessível.
- “Ver estoque” abre a lista completa, enquanto cada alerta mantém seu filtro correspondente.
- Um paciente recente abre diretamente o cadastro selecionado; “Novo paciente” abre o formulário vazio.
- A limpeza dos parâmetros de abertura direta preserva o estado da página e os demais filtros.

Atualização automática, alertas de estoque e pacientes recentes são recursos anteriores que receberam correções nesta etapa.

### 6.5. Português e inglês

Foram incluídas traduções do tour e de textos antes fixos em português, como personalização do Dashboard, atalhos, ordenação, chegada de pacientes, alertas de estoque e etapas de configuração inicial.

Os alertas consideram singular e plural, e as etapas de ativação consultam o idioma atual. As mudanças funcionais de idioma estão concentradas no commit `59acf15`.

## 7. Conexão entre SaaS e integrador

### 7.1. Dados clínicos transportados

O contrato passou a transportar a data/hora efetiva do exame e a observação lida dos arquivos do equipamento. O SaaS armazena e apresenta esses dados. Quando o upload informa somente o paciente, a resolução do agendamento considera o dia da captura, em vez de pressupor o dia do envio. Reenvios que omitem os campos preservam os valores já registrados.

A API de pacientes passou a fornecer o nome completo para apoiar a identificação e a conferência manual. O integrador consulta o cadastro detalhado somente do paciente escolhido, valida seu identificador e rejeita uma resposta referente a outro paciente. A origem do código — cadastro do sistema ou código de importação — é tratada explicitamente.

**Exemplo de uso:** um exame capturado enquanto a clínica está sem internet pode ser enviado depois, preservando o contexto da captura. Isso depende de o equipamento ou a configuração do OCR fornecerem data/hora válidas; não é uma garantia de identificação automática em qualquer aparelho.

### 7.2. Canal de comandos operacionais

Foi implementado um canal pelo qual o SaaS registra uma solicitação e o integrador a busca periodicamente, executa e confirma o resultado. A consulta está ligada tanto à interface desktop quanto aos modos de serviço, com intervalo nominal de 120 segundos.

| Comando | Comportamento atual |
| --- | --- |
| Sincronizar agora | Solicita um ciclo de varredura/envio dos exames. |
| Executar diagnóstico | Obtém informações operacionais de diagnóstico. |
| Recarregar configuração | Tipo reconhecido que responde à solicitação, mas ainda não baixa nem aplica uma configuração remota. |

No SaaS, o disparo é um endpoint JSON restrito à administração da plataforma, com escopo de clínica/integrador, autenticação e controles administrativos. **Ainda não há uma tela própria de disparo no Manager.** O canal não oferece terminal remoto, execução arbitrária de comandos ou vínculo remoto de paciente.

O registro permanece pendente se a confirmação não chegar, permitindo nova tentativa. A entrega pode se repetir, exigindo ações idempotentes. Comandos concluídos ou falhos são elegíveis à limpeza após 30 dias; pendentes são preservados.

### 7.3. Correção no estado ativo dos exames recebidos

Foi identificada uma causa concreta para exames recém-recebidos nascerem inativos: o serviço de criação não definia explicitamente o estado ativo, enquanto o banco mantinha `false` como padrão. Isso podia excluí-los de fluxos que exigem exame habilitado, como laudos, IA e repasses.

A correção inclui:

1. Gravar explicitamente novos exames recebidos pelo serviço como ativos.
2. Alterar o padrão da coluna no banco para `true`.
3. Recuperar seletivamente registros elegíveis afetados desde 02/02/2026.
4. Registrar em auditoria cada recuperação.
5. Adicionar índice por agendamento e processar a recuperação em lotes.

A recuperação exclui importações externas, registros anteriores ao recorte e exames com alteração de estado identificada na auditoria. No PostgreSQL, o índice pode ser criado de forma concorrente quando fora de transação.

**Situação:** correção implementada na entrega disponibilizada para homologação. A avaliação deve conferir a disponibilidade dos novos exames e dos registros elegíveis à recuperação. A quantidade recuperada será registrada após essa conferência.

## 8. Landing page e experiência de navegação

### 8.1. Identidade e responsividade

A revisão preservou a marca EasyEye, a tipografia Inter, a combinação de azul-marinho e teal e a estrutura principal da página. Os ajustes se concentraram em distribuição, leitura, alinhamento e comportamento dos componentes.

A abertura passou a apresentar a captura real do prontuário oftalmológico, acompanhada de explicações sobre avaliação por olho e refração. As funcionalidades foram organizadas pela rotina de recepção, consultório e gestão/faturamento, com detalhes complementares em áreas expansíveis.

Foram refinados espaçamentos e composições para desktop, tablet e celular, incluindo cartões, planos, formulário, mockup e navegação móvel. Campos e componentes passam a responder também à largura disponível dentro de seus próprios contêineres.

O menu móvel utiliza a altura disponível da tela, considerando a navegação, para evitar que seu conteúdo fique prejudicado em telas baixas. Estados de foco, alvos de toque e preferência por movimento reduzido foram considerados nas alterações.

**Na homologação:** conferir leitura, alinhamento e navegação em computador, tablet e celular, registrando eventuais ajustes de apresentação.

### 8.2. Cartões de problemas alinhados

Os cartões da seção “Sua clínica ainda perde tempo (e dinheiro) com isso?” tinham alturas diferentes conforme a quebra dos títulos. Isso produzia o desalinhamento percebido na imagem enviada.

A composição passou a esticar os cartões dentro da mesma linha do grid e a alinhar melhor o início do conteúdo e os indicadores de expansão. O comportamento expansível foi preservado sem impor uma altura fixa aos textos.

### 8.3. Demonstração sob controle do visitante

A troca automática da demonstração e o controle “Pausar demonstração” foram retirados. O visitante escolhe a aba que deseja visualizar e pode ler no próprio ritmo.

A demonstração reúne capturas de prontuário, imagens, agenda e laudos, com possibilidade de ampliação no celular. Foram preservados os controles de teclado, incluindo setas, Home e End, e o gerenciamento de foco. Painéis inativos deixam de participar da navegação por teclado. A agenda demonstrativa permanece identificada como fictícia.

**Impacto esperado:** reduzir distração e tornar a demonstração previsível, sem exigir um controle de reprodução para consultar o produto.

### 8.4. Navegação e detalhes de interação

A navegação principal passou a indicar a seção atual durante a rolagem, inclusive com identificação acessível. Foram refinados estados de foco e passagem do mouse, mantendo o contraste nos diferentes fundos do cabeçalho.

O menu prioriza funcionalidades, demonstração, preços e contato. O rodapé se adapta à disponibilidade de páginas institucionais e de depoimentos, evitando caminhos sem conteúdo publicado.

O formulário recebeu confirmação visual breve de sucesso, estática quando o usuário prefere movimento reduzido. Campos inválidos mantêm a indicação de erro mesmo quando recebem foco, ajudando a localizar o que precisa de correção.

### 8.5. Distribuição da área de contato

No início deste ciclo, o endpoint de contato passou de um registro em log com resposta de sucesso para **envio efetivo por SMTP**, com validação, destinatário configurável e resposta adequada em caso de falha. Foram incluídos limite de cinco requisições por minuto, prevenção de duplicidade e feedback acessível. O envio depende da configuração do serviço de e-mail; aceitação pelo SMTP não comprova chegada à caixa postal.

O espaço desproporcional à esquerda era consequência da diferença de altura entre os blocos de contato e o formulário, combinada à posição do título acima das duas colunas.

O título e a introdução foram incorporados à coluna de informações no desktop, com o formulário ao lado. A distribuição usa o conteúdo existente para equilibrar a seção, sem adicionar textos artificiais apenas para preencher espaço.

Em telas menores, a sequência passa por introdução, contato de vendas, formulário e conteúdo complementar. Os campos lado a lado se reorganizam conforme a largura interna do formulário.

Os dados essenciais permanecem visíveis, e detalhes adicionais de atendimento ficam em uma área opcional expansível. Foram preservados consentimento, mensagens de erro, orientações de preenchimento e comportamento de sucesso.

A área opcional abre automaticamente se um de seus campos apresentar erro. A confirmação visual só aparece após resposta de sucesso do servidor, acompanhada de mensagem textual e foco acessível.

### 8.6. Planos, integrador e comunicação da IA

A comparação dos planos foi organizada para permitir leitura equivalente de médicos, armazenamento, créditos de IA e estoque. Informações complementares foram agrupadas por capacidade, IA e recursos.

A apresentação considera os planos ativos do banco e suas características, inclusive a disponibilidade do estoque. O catálogo inicial habilita estoque em Pro e Premium, mantendo a regra de acesso do produto.

A comparação distingue informação não cadastrada de recurso não incluído. Zero créditos de IA não é tratado como uso ilimitado. Planos com preço zero são apresentados como “Sob consulta”, com encaminhamento ao comercial.

O integrador é descrito como o caminho de envio dos exames dos aparelhos para o EasyEye. A comunicação não o apresenta como uma API pública genérica nem destaca limites internos de envio como benefício comercial.

O programa de optotipos aparece somente como **“Em breve” no Premium**. Essa apresentação não representa implementação do programa.

As explicações de IA foram reorganizadas para distinguir análise, chat, consumo variável, saldo compartilhado, renovação de franquia, recarga e revisão pelo médico. Também esclarecem a ausência de franquia durante o teste. A IA permanece apresentada como apoio ao profissional. Essa entrega revisa a comunicação das regras existentes; não cria um novo mecanismo de créditos.

### 8.7. Continuidade entre plano e cadastro

A escolha do plano na landing é transportada para o cadastro pela URL e preservada nas etapas seguintes, inclusive após erros de validação. O servidor verifica a escolha contra os planos ativos. O prazo de teste segue a configuração global do sistema.

Quando o teste está desabilitado, com duração zero, os botões direcionam ao contato, o backend redireciona o acesso à página de cadastro e rejeita a tentativa de envio. A regra não depende apenas de esconder um botão na interface.

### 8.8. Páginas legais e apresentação pública

Foram criadas páginas públicas de privacidade e termos utilizando o sistema de versionamento jurídico que já existia. Entraram conteúdo inicial 1.0, templates, configuração dos dados da empresa e seeders que preservam documentos existentes, além de índice, títulos e listas para leitura.

As traduções de cortesia em inglês ficam vinculadas à versão oficial em português, com aviso, acesso ao original e alternativa quando não há tradução. Isso não cria um aceite jurídico paralelo.

A homologação no ambiente de teste deve conferir as versões dos documentos ativadas pelas migrações/seeders; a aprovação jurídica não foi verificada neste levantamento. A estrutura de SEO — metadados, canonical, idiomas alternativos, dados estruturados e sitemap — é anterior e foi preservada, sem resultado medido de posicionamento.

### 8.9. Acessibilidade, carregamento e acabamento

O ciclo acrescentou link para pular ao conteúdo, navegação móvel com Escape e retorno de foco, controles identificados, áreas de toque e revisões de contraste. Foram incorporadas capturas WebP reais, verificação de arquivos disponíveis e alternativas para imagens ausentes.

A escala tipográfica, os números tabulares, o carregamento inicial da Inter e as métricas da fonte substituta foram refinados para favorecer consistência e reduzir mudanças de quebra de linha. São medidas implementadas, sem certificação integral de acessibilidade ou ganho de velocidade medido nesta revisão.
## 9. Conteúdo comercial adequado ao pré-lançamento

### 9.1. Indicadores e depoimentos ocultos

Foram removidos números e relatos sem comprovação, incluindo alegações de clínicas ativas, volume de consultas, satisfação e depoimentos fictícios. A mesma revisão alcançou landing, cadastro e login, evitando mensagens contraditórias entre essas telas.

A estrutura dos indicadores e depoimentos foi preservada para publicação futura, mas permanece desativada por `site.social_proof_enabled = false`.

O bloqueio também é aplicado no servidor: esses campos não seguem nos dados enviados ao frontend quando desabilitados. Se não existem depoimentos publicáveis, o link correspondente não aparece na navegação, e as seções não deixam contêineres vazios.

Para publicar futuramente, será necessário:

- Reunir dados reais, com fonte e período de medição.
- Obter autorização para os depoimentos.
- Preencher o conteúdo em português e inglês.
- Revisar o material e habilitar a configuração.

Não basta ativar a chave para transformar conteúdo de demonstração em prova comercial válida.

### 9.2. TISS sem alegação de homologação

A comunicação foi alterada de “integrado e homologado” para **“Novo: integração com TISS 4.03.00”**. Diferenciais, perguntas frequentes e login foram alinhados ao mesmo posicionamento em português e inglês.

A página descreve a integração existente, e a FAQ informa que ainda não há homologação TISS. Não foi adotada uma afirmação de “conformidade” como substituição, pois ela também poderia sugerir uma validação ainda não obtida.

Essa foi uma correção de comunicação. **Homologação TISS não foi entregue nesta etapa.** A versão divulgada foi alinhada ao layout 4.03.00 do motor atual; não é anunciado suporte a outras versões.

### 9.3. Outras alegações revisadas

- A promessa numérica de redução de faltas sem comprovação foi retirada.
- “Mais popular” foi substituído por “Em destaque”, evitando inferir adoção ainda inexistente.
- Textos de diferenciais passaram a descrever a proposta do produto, sem sugerir uma base de clientes comprovada.
- A taxa de implantação de R$ 0 é tratada como condição comercial, não como indicador de adoção.

Referências a CFM, LGPD ou segurança nos textos do produto não constituem certificação ou parecer jurídico produzido por esta entrega.

## 10. Hero e mockup: “Calibração de precisão”

A direção visual escolhida foi implementada somente no hero e no mockup principal. O conceito de painel de instrumentos foi traduzido em quatro marcas finas nos cantos da moldura, que se ajustam em uma única animação breve.

O texto, os botões, a captura e os dados existentes permanecem estáveis. As entradas anteriores do hero foram substituídas pelo movimento localizado das marcas. Não foram acrescentados efeitos de brilho genérico nem movimento contínuo.

| Aspecto | Implementação |
| --- | --- |
| Desktop | Deslocamento de 8 px e duração aproximada de 0,8 s. |
| Telas até 640 px | Deslocamento de 3 px e duração aproximada de 0,55 s. |
| Início | Uma vez, após pelo menos 35% do mockup estar visível e com o documento ativo. |
| Fora da tela ou aba oculta | Movimento pausado; retomada sem reiniciar a apresentação. |
| Movimento reduzido | Moldura estática; uma mudança de preferência durante a animação interrompe o efeito. |
| Recursos de animação indisponíveis | Conteúdo e moldura continuam utilizáveis em estado estático. |
| Dependências | Reutilização do GSAP já presente; nenhuma nova biblioteca para esse efeito. |

O efeito anima transformações e opacidade de poucos elementos, com desenho orientado a baixo custo de renderização. **A meta de 60 fps não foi medida**, e o relatório não apresenta um resultado de desempenho ou benchmark inexistente.

## 11. Integrador de equipamentos

O integrador é o aplicativo local que aproxima os equipamentos do SaaS. Ele identifica arquivos ou recebe exames pela rede, mantém uma fila local, associa os dados ao paciente e realiza o envio autenticado. A arquitetura de fila offline já existia; neste período recebeu melhorias de identificação, operação, segurança e continuidade.

### 11.1. Cadastro orientado à função do equipamento

O cadastro foi separado em três funções, com campos próprios:

| Função | Forma de entrada | Configuração principal |
| --- | --- | --- |
| Padrão | Arquivos exportados para uma pasta, com metadados ou identificador no nome | Pasta, tipo de exame, origem do código e modelo de nome de arquivo, quando necessário. |
| OCR | Imagens em pasta sem identificação estruturada suficiente | Regiões de leitura, tamanho da imagem de referência e formato de data. |
| DICOM | Recepção direta pela rede | IP, AE Title e vínculo opcional ao recurso de agenda; sem pasta monitorada. |

O tipo de exame ganhou busca. Listagem, filtros e detalhes mostram a função e a origem dos exames de maneira mais clara. A configuração separa código interno do EasyEye de código de importação, evitando trocar silenciosamente uma referência pela outra.

Um modelo configurável de nome de arquivo permite adaptar a extração de identificadores às convenções de exportação do aparelho. Validação de campos, conflito de pastas e navegação por Tab foram refinados ao longo do ciclo.

**Benefício esperado:** reduzir configuração irrelevante e facilitar a preparação de aparelhos com diferentes formas de exportação. Isso não representa compatibilidade universal com fabricantes.

### 11.2. Configuração e teste do OCR

Foi implementada leitura local de regiões da imagem para identificador, nome, olho e data/hora. Os modelos estão incorporados ao aplicativo; o OCR não depende de enviar a imagem a um serviço externo de leitura.

A configuração permite marcar regiões sobre uma imagem de referência, ampliar a imagem, consultar o estado de cada região e abrir coordenadas para ajuste fino. Sugestões de regiões auxiliam a configuração, e o botão de teste mostra o que foi lido antes de salvar.

Foram acrescentadas verificações para regiões fora dos limites, configuração incompleta, mudança no tamanho da referência e resultados de teste que chegaram depois de a configuração mudar. Uma leitura anterior não permanece apresentada como válida para uma configuração diferente.

O vínculo automático exige código encontrado no EasyEye e nome compatível com o cadastro. Sem confirmação, o item fica bloqueado para conferência, sem escolher o próximo agendamento como palpite. Essa regra acompanha a origem do item mesmo após alteração ou exclusão do equipamento.

**Limites:** o cadastro pode permitir região de nome opcional, mas a ausência de confirmação suficiente impede o vínculo automático. Mudanças na resolução ou no layout exportado pelo aparelho exigem revisão da configuração. OCR não é garantia de leitura correta de qualquer imagem.

### 11.3. Data/hora, olho e observações do exame

O integrador passou a transportar data/hora e observações obtidas do arquivo `.EMR`, além de data/hora lidas por OCR quando configuradas. Um arquivo auxiliar recebido depois pode complementar os metadados da imagem já enfileirada.

É possível escolher formato brasileiro ou americano de data. Leituras inválidas são descartadas sem inventar uma data e sem impedir o envio somente por esse motivo. O lado SaaS recebeu os campos correspondentes, conforme a seção 7.

Essa mudança procura preservar o contexto clínico da captura, especialmente quando envio e realização acontecem em dias diferentes.

### 11.4. Fila com diagnóstico mais claro

A fila recebeu apresentação revisada, filtros por situação/equipamento, contadores e detalhes por item. A janela de detalhes reúne:

- Situação, tentativas, próxima repetição e necessidade de intervenção.
- Código HTTP e campos específicos recusados pela API.
- Equipamento, tipo de exame, tamanho, olho, data/hora e observação.
- Origem da identificação: arquivo auxiliar, OCR, DICOM ou captura de tela.
- Histórico de tentativas.
- Imagem local e informações de identificação extraídas dos arquivos do equipamento.

Exames com falha antiga podem guardar apenas a mensagem genérica anterior; uma nova tentativa permite registrar os detalhes atualmente retornados pela API.

**Benefício esperado:** dar ao suporte elementos concretos para diferenciar erro de identificação, dado recusado, sessão inválida e falha temporária de envio.

### 11.5. Conferência visual e vínculo manual do paciente

O operador pode visualizar a cópia do exame mantida pelo integrador, ampliar e comparar os dados lidos com o cadastro escolhido. Nos itens identificados por OCR, a interface também pode mostrar recortes das regiões configuradas.

A busca parte preferencialmente do identificador lido, com alternativa pelo nome. Ao selecionar um paciente, a interface consulta seu cadastro e compara código, nome, nascimento e sexo quando houver dados suficientes. Indicadores distinguem concordância, semelhança que exige conferência e divergência.

Datas ambíguas não são comparadas automaticamente; nomes semelhantes ou homônimos não são prova de identidade. A confirmação continua sendo uma decisão do operador. Os recortes usam a configuração atual do equipamento, que pode diferir daquela usada na leitura original.

Foram implementadas proteções adicionais:

- A imagem exibida é decifrada e conferida pelo checksum antes da apresentação.
- O vínculo aguarda o carregamento do cadastro selecionado.
- Respostas antigas de busca ou seleção não substituem o paciente atual.
- Se o aparelho regravar o arquivo enquanto o modal está aberto, a correção é recusada e exige nova conferência.
- Fechar ou bloquear a interface descarta a visualização e resultados que chegam atrasados.

A correção é gravada antes da tentativa de envio. Se a rede não permitir concluir naquele momento, a fila preserva o trabalho para uma tentativa posterior.

**Limites:** PDF/texto e arquivos cuja cópia já foi removida podem não ter prévia de imagem. Instalações somente em modo de serviço não possuem esse modal; a correção exige abrir a GUI na mesma máquina. Não há comando remoto de vínculo de paciente.

### 11.6. Exclusão, restauração e limpeza sem reenvio indevido

Antes, apagar o histórico de um arquivo ainda presente na pasta podia permitir que a próxima varredura o tratasse como novo. A limpeza passou a preservar registros mínimos de controle para evitar esse reenvio.

Foi acrescentada exclusão manual com confirmação, filtro de excluídos e restauração. A exclusão não apaga o original do aparelho nem remove o exame do SaaS. Itens em processamento não podem ser excluídos por essa ação.

Restaurar recupera a situação anterior: um enviado continua enviado; um pendente volta ao fluxo. A restauração é recusada quando outro item ativo já usa o arquivo ou conteúdo. Um exame novo sobrescrevendo o mesmo nome pode ser reconhecido como conteúdo novo.

Após 30 dias, exclusões são convertidas em histórico não restaurável, com remoção dos dados do paciente e limpeza da cópia local quando não utilizada por outro item. “Limpar enviados” e retenção preservam o controle necessário para os arquivos ainda observados.

**Limite importante:** a proteção não é um bloqueio eterno de qualquer reencontro do conteúdo. Os registros mínimos também têm coleta quando deixam de ser necessários; caminhos virtuais DICOM/RPA podem perder essa proteção após a janela de retenção. O original do equipamento permanece fora da gestão de exclusão do integrador.

### 11.7. Continuidade do monitoramento e envio

Foi acrescentada varredura imediata ao entrar no aplicativo. O modo `--watch-loop` recebeu varredura periódica adicional, além dos eventos do sistema operacional, para reduzir o risco de perder arquivos quando pastas compartilhadas deixam de emitir notificações.

Essas melhorias complementam fila persistente, deduplicação, retentativas e armazenamento local já existentes. O funcionamento offline significa guardar e retomar trabalho; o cadastro e a confirmação de identidade ainda podem depender da API disponível.

O bloqueio da interface protege ações de suporte sem interromper o processamento em segundo plano. A base atual contempla senha local e bloqueio por inatividade; instalações antigas sem senha precisam da configuração correspondente para ter essa proteção.

O retrato da saúde da fila para o SaaS é uma capacidade anterior preservada, com contadores e amostra de problemas, útil para diagnóstico antes de acesso à máquina. Não é apresentado como novo módulo criado neste recorte.

### 11.8. Criptografia local e persistência da chave

Foi adicionada criptografia aos arquivos do armazenamento interno e a campos selecionados da fila: identificadores de paciente/agendamento, referências, nomes e caminhos. A chave fica no cofre de credenciais do sistema operacional, separada do SQLite.

A implementação utiliza AES-256-GCM e AES-SIV conforme a necessidade do dado, preservando a comparação usada na deduplicação. Há formatos versionados e tratamento de informações legadas. Isso não comprova uma rotação operacional de chave já realizada.

No macOS, foi corrigido o uso do Keychain persistente, evitando uma configuração que perderia a chave ao encerrar. Registros que não podem ser decifrados são identificados e possuem tratamento específico, sem serem confundidos com uma fila normalmente legível.

O checksum do conteúdo é verificado antes do envio e da apresentação da cópia local. O cabeçalho de DICOM arquivado pode ser lido parcialmente, evitando carregar todo o volume para uma conferência de identificação.

**Cobertura delimitada:** o banco inteiro não está cifrado por esse mecanismo. Configurações, logs, data do exame e observação não recebem a mesma cifragem por campo. Os arquivos originais na pasta do fabricante também não são cifrados pelo integrador. Portanto, não cabe afirmar que “todos os dados locais estão criptografados”.

### 11.9. DICOM e lista de trabalho

A base iniciada em 16/09 oferece verificação de conexão (`C-ECHO`), recebimento de exame (`C-STORE`) e consulta à lista de trabalho a partir da agenda (`C-FIND`). No recorte principal foram adicionados vínculo opcional com recurso de agenda e correções de robustez do protocolo.

O equipamento é identificado pela combinação de IP e AE Title do mesmo cadastro. Foram reforçados limites de conexões, duração, buffers e resultados e a rejeição de mensagens inconsistentes. O nome do paciente do cabeçalho passou a ser preservado para conferência manual, sem substituir o identificador no vínculo automático.

Limites atuais relevantes:

- Transferências DICOM sem compressão; conversão somente do primeiro frame para PNG.
- Até oito associações simultâneas, dez minutos por associação, dataset recebido por C-STORE até 40 MiB e imagem convertida até 10 MiB.
- Listener inicialmente desabilitado, exigindo configuração explícita.
- Identificação por IP/AE Title, sem autenticação criptográfica nativa nesse protocolo.
- Sem MPPS e sem pretensão de substituir um PACS universal ou visualizador completo de volumes.

Há testes de protocolo com dados simulados e estrutura de testes adversariais/fuzzing. Compatibilidade com cada aparelho físico ainda precisa ser confirmada.

### 11.10. RPA para softwares que não exportam a imagem

Foi implementada uma alternativa que executa uma sequência configurável de teclado/mouse e captura uma região da tela. A imagem entra na fila normal. Existe controle persistente das tentativas, limpeza antes da digitação e bloqueio após esgotar as tentativas.

O backend é exclusivo de Windows. Na GUI o acionamento é manual; a retomada automática de tentativas pendentes acontece nos modos de serviço, evitando tomar teclado/mouse periodicamente durante o uso da interface.

**Estado correto para comunicação:** implementação inicial disponível no código, com correções de compatibilidade para Windows, ainda dependente de validação com software e aparelho reais. A verificação da janela em primeiro plano não comprova foco no campo correto; não existe confirmação por OCR de que o texto foi aceito. A captura considera o monitor principal.

Não há evidência para afirmar cobertura de todos os equipamentos, operação autônoma validada ou prontidão clínica desse caminho.

### 11.11. Builds, testes e distribuição

Foram corrigidos problemas específicos do build Windows, da verificação de dependências e de um teste que presumira rótulos em português mesmo no runner em inglês.

O perfil de testes foi otimizado para a carga de OCR, e as dependências do perfil de desenvolvimento receberam otimização para reduzir a demora local de leitura. Isso não representa uma nova otimização do instalador de produção, que já usa o perfil de release. Medições mencionadas em comentários são registros históricos, não benchmarks reproduzidos nesta auditoria.

Os workflows atuais incluem formatação, lint, análise de dependências, builds/testes Windows e um alvo para Windows 7 de 32 bits. A existência do alvo não comprova operação validada nessa plataforma ou com seus equipamentos.

A infraestrutura anterior de atualização já verifica versão, baixa arquivo e confere checksum/assinatura. Instalação e rollback automáticos continuam pendentes. A conferência do instalador e da assinatura cabe ao responsável técnico durante a preparação da distribuição.

### 11.12. Documentação operacional

Foram atualizados o manual e os guias de OCR, DICOM, criptografia, fila e correção manual. Eles ajudam a preparar instalação, suporte e conferência de falhas, mas parte das capturas e descrições ainda representa estados anteriores.

O documento de resumo de RPA contém afirmações de prontidão mais fortes do que as evidências verificadas. O relatório adota os limites do código atual e recomenda sincronizar esses materiais antes de utilizá-los comercialmente.

## 12. Homologação e responsabilidades

### 12.1. Objetivo da homologação dos sócios

As entregas estão publicadas no ambiente de teste. Nesta etapa, os sócios podem avaliar a aderência das funcionalidades à rotina da clínica, a clareza da experiência e as prioridades de ajuste antes da liberação em produção.

A avaliação funcional complementa o trabalho técnico de desenvolvimento e infraestrutura. O aceite dos fluxos será registrado após a conferência dos cenários relevantes.

### 12.2. Roteiro de avaliação funcional

| Área | O que avaliar no ambiente de teste |
| --- | --- |
| Landing e cadastro | Clareza da oferta, diferenças entre planos, comunicação TISS 4.03.00, contato e continuidade do cadastro. |
| Recepção e agenda | Cadastro de pacientes, importação, situações de atendimento e retorno do paciente à consulta. |
| Consultório e exames | Localização e comparação de imagens, identificação do paciente, produção de laudos e consulta aos documentos. |
| Financeiro | Conferência de cobranças, recebimentos, saldos, glosas, fechamento e demonstrativos. |
| Repasse médico | Regras de remuneração, valores liberados, divisão entre médicos, fechamento e registro dos pagamentos. |
| Integrador | Configuração do aparelho, chegada do exame ao paciente correto, entendimento das falhas e correção manual quando necessária. |
| Experiência de uso | Clareza das telas, utilidade do tour, facilidade de navegação e apresentação em computador, tablet e celular. |

Para cada ajuste identificado, registrar a tela, os passos realizados, o resultado esperado e o resultado observado. Isso permite reproduzir o cenário e priorizar a correção.

### 12.3. Responsabilidades

- **Sócios:** avaliar os fluxos de negócio, apontar dificuldades, alinhar prioridades e registrar o aceite funcional.
- **Responsável técnico pelo projeto:** conduzir desenvolvimento, testes automatizados, infraestrutura, configuração dos ambientes, correções e preparação da publicação em produção.
- **Alinhamento conjunto:** decidir quais ajustes impedem a liberação e quais podem seguir como melhorias posteriores.

As atividades técnicas permanecem centralizadas no responsável por desenvolvimento e infraestrutura. O foco da participação dos sócios é a avaliação do produto e das regras de negócio.

### 12.4. Resultado esperado desta etapa

Consolidar os fluxos aprovados, os ajustes necessários e as prioridades para preparar a liberação em produção. A publicação no ambiente de teste já está concluída; a aprovação funcional será registrada ao término da homologação.

A homologação interna pelos sócios é distinta da homologação TISS e da validação de compatibilidade com cada equipamento ou operadora. Esses limites permanecem descritos nas respectivas funcionalidades.

## 13. Pendências e próximos passos

### 13.1. Itens que não devem ser anunciados como concluídos

| Item | Estado real |
| --- | --- |
| Homologação TISS | Não obtida; a correção da versão anunciada para 4.03.00 não representa homologação. |
| Envio TISS validado com operadoras | Cliente HTTP existe; configuração padrão simulada e configuração real não verificada. |
| Envio eletrônico do recurso de glosa | A ação auditada registra envio/histórico, sem transmissão externa comprovada. |
| Transferência bancária de repasse | O sistema registra pagamento e despesa, sem executar transferência. |
| Tributação automática | As deduções do repasse são parâmetros operacionais, não um motor fiscal legal. |
| Optotipos | Comunicado como “Em breve”, sem implementação do programa. |
| Tours detalhados de todos os módulos | Tour específico de conteúdo inicialmente no Dashboard; navegação geral nas demais telas. |
| CRM e programa comercial | Plano documentado, sem CRM, integração comercial ou parceiros implantados por essa entrega. |
| RPA validada para qualquer aparelho | Implementação Windows inicial, dependente de configuração e validação operacional. |
| Reconfiguração remota completa | O comando de recarga reconhecido ainda não aplica configuração remota. |
| Atualização totalmente automática do integrador | Consulta/download/verificação existentes; instalação e rollback automáticos pendentes. |
| Compatibilidade universal DICOM | Protocolos e formatos delimitados, sem homologação de todos os aparelhos. |
| Criptografia de todos os dados locais | Arquivos internos e campos selecionados cifrados; cobertura não abrange todo o banco ou originais do fabricante. |
| Métricas de adoção e depoimentos | Sem evidência publicável; seções ocultas. |
| 60 fps e conformidade integral de acessibilidade | Objetivos de projeto, sem medição/certificação final nesta etapa. |

### 13.2. Homologação no ambiente de teste e preparação para produção

A publicação no ambiente de teste já foi realizada. As ações abaixo orientam a homologação dos sócios e a preparação da liberação em produção.

| Prioridade | Ação | Evidência esperada | Responsabilidade |
| --- | --- | --- | --- |
| Durante a homologação dos sócios | Conferir a comunicação de TISS 4.03.00 na landing e no login | Versão anunciada alinhada à implementação | Sócios, com ajustes pelo responsável técnico |
| Antes da liberação de TISS | Validar a geração dos arquivos e a comunicação real com a operadora | Integração conferida no cenário de uso previsto | Responsável técnico, com conferência das regras de faturamento pelos sócios |
| Durante a homologação financeira | Conferir cenários de caixa, glosas, recebimentos e repasses | Valores, saldos e estornos conferidos | Sócios, com apoio do responsável técnico |
| Durante a homologação dos sócios | Conferir a configuração e os serviços do ambiente de teste | Ambiente preparado para os fluxos avaliados | Responsável técnico |
| Antes de habilitar funções dependentes | Conferir geração de imagens, PDFs, planilhas e envio de e-mail | Recursos funcionando no ambiente de destino | Responsável técnico |
| Antes da distribuição do integrador | Conferir instalação, assinatura, atualização manual e remoção no Windows alvo | Pacote preparado para distribuição | Responsável técnico |
| Antes de conectar cada modelo de aparelho | Validar exportação, identificação do paciente e formato dos exames | Compatibilidade confirmada para a configuração utilizada | Responsável técnico |
| Antes de utilizar RPA operacionalmente | Validar a sequência e a captura com o software real | Associação correta e comportamento de falha conferidos | Responsável técnico |
| Antes da liberação conjunta | Conferir retomada após falha de rede, correção manual e prevenção de duplicidade | Fluxo equipamento → fila → API → prontuário conferido | Responsável técnico |
| Antes da divulgação pública | Avaliar landing, cadastro e tour nas diferentes telas | Leitura e navegação adequadas | Sócios, com ajustes pelo responsável técnico |
| Ao concluir a homologação | Registrar os fluxos aprovados e as pendências para produção | Aceite interno e plano de liberação documentados | Sócios e responsável técnico |
| Após o início da operação | Acompanhar utilização, dúvidas e resultados comerciais | Indicadores reais para priorização e futura prova social | Sócios; coleta técnica pelo responsável técnico |

Essas ações orientam a homologação e a preparação da liberação; sua conclusão será registrada conforme o andamento.

### 13.3. Documentação e manutenção do ciclo

Além das funções de produto, o período trouxe:

- Plano comercial/CRM com qualificação, demonstração, prova de conceito, onboarding e possíveis parcerias, como material de decisão.
- Ajustes em factories, fixtures e testes para reduzir colisões e alinhar cenários ao comportamento atual.
- Remoção da geração de exames fictícios no seeder de dados de demonstração; isso não apaga dados já existentes.
- Rollback mais seguro de uma migração de campos clínicos opcionais.
- Ajuste do listener de fila no comando local `composer dev`, sem mudança comprovada dos workers de produção.
- Alinhamento da geração de URLs HTTPS e da configuração padrão de cookie seguro ao protocolo de `APP_URL`, evitando forçar TLS em ambientes locais sem certificado apenas pelo nome do ambiente. O funcionamento seguro depende da configuração correta do destino.
- Remoção de arquivos `.DS_Store` versionados e ajustes de formatação.

Há documentação a sincronizar: `DESIGN.md` ainda descreve autoplay e a animação anterior do hero; `PRODUCT.md` menciona prazo de teste por plano, enquanto o código usa configuração global. No integrador, partes dos materiais de RPA, criptografia, status e capturas estão anteriores ao código analisado. O plano comercial também precisa ser usado junto das limitações atuais de TISS e pré-lançamento.

A revisão ampliada corrige a atribuição temporal do relatório anterior: envio SMTP do contato e páginas legais públicas foram entregues em 27/09; eram anteriores apenas ao recorte mais estreito de 29–30/09.

## 14. Evidências e cronologia

### 14.1. Fontes do SaaS

Os links apontam para os arquivos dos projetos e servem para conferência técnica. O texto do relatório é autossuficiente para leitura pelos sócios.

| Área | Fontes principais |
| --- | --- |
| Faturamento e caixa | [Serviços financeiros](../app/Services/Financial/), [controllers](../app/Http/Controllers/Financial/), [interface](../resources/js/Pages/Panel/Financial/) |
| TISS e glosas | [Domínio TISS](../app/Domains/Tiss/), [configuração](../config/tiss.php), [gerador XML](../app/Domains/Tiss/Xml/Builders/V202603TissXmlBuilder.php) |
| Repasse médico | [Serviços de repasse](../app/Services/Financial/DoctorPayouts/), [controllers](../app/Http/Controllers/Financial/DoctorPayouts/), [Meus repasses](../app/Http/Controllers/MyPayoutsController.php), [PDF](../resources/views/pdf/doctor_payout_statement.blade.php) |
| Imagens e laudos | [Tela de imagens](../resources/js/Pages/Panel/EyeImages/Index.vue), [serviços](../app/Services/EyeImages/), [frases rápidas](../app/Services/DoctorReportPhraseService.php), [miniaturas históricas](../app/Console/Commands/BackfillExamDerivatives.php) |
| Importação | [Médicos](../app/Services/DoctorImportService.php), [pacientes](../app/Services/PatientImportService.php), [agendamentos](../app/Services/ScheduleImportService.php) |
| Agenda e cadastros | [ScheduleService](../app/Services/ScheduleService.php), [situações](../app/Enums/ScheduleSituation.php), [PatientService](../app/Services/PatientService.php), [máscaras](../resources/js/utils/masks.js) |
| Listagens administrativas | [Controllers de estoque](../app/Http/Controllers/Stock/), [catálogos](../app/Http/Controllers/Setting/BaseSettingController.php), [retorno às listagens](../app/Http/Controllers/Concerns/RedirectsToListing.php) |
| Relatório de parceiros | [Exportação Excel](../app/Exports/PartnersReportExport.php), [teste específico](../tests/Feature/Manager/PartnersExportTest.php) |
| Painel e tour | [Dashboard](../app/Http/Controllers/PanelDashboardController.php), [PanelTour](../app/Support/PanelTour.php), [usePanelTour](../resources/js/composables/usePanelTour.js) |
| API do integrador | [Serviço de exames](../app/Services/Api/PatientExamService.php), [validação do upload](../app/Http/Requests/Api/ExamRequest.php), [comandos da API](../app/Http/Controllers/Api/IntegratorCommandsController.php), [comandos no Manager](../app/Http/Controllers/Manager/EntityIntegratorCommandsController.php) |
| Landing | [Home.vue](../resources/js/Pages/Site/Home.vue), [SiteLayout.vue](../resources/js/Layouts/SiteLayout.vue), [contato](../resources/js/Components/Site/ContactForm.vue), [planos](../resources/js/Components/Site/PricingPlans.vue), [animação](../resources/js/site-animations.js) |
| Conteúdo público e jurídico | [Filtro de conteúdo](../app/Support/Site/SiteContent.php), [configuração](../config/site.php), [tradução PT](../lang/pt_BR/site.php), [páginas legais](../app/Http/Controllers/SiteLegalController.php), [textos legais](../resources/legal/) |
| Cadastro | [Controller](../app/Http/Controllers/Auth/RegisteredUserController.php), [formulário](../resources/js/Pages/Auth/Register.vue) |
| Planejamento comercial | [Plano comercial e CRM](plano_comercial_crm.md) |
| Cobertura automatizada | [Testes PHP de funcionalidades](../tests/Feature/), [testes PHP unitários](../tests/Unit/), [testes JavaScript](../tests/JavaScript/) |

### 14.2. Fontes do integrador

Os caminhos relativos desta tabela consideram `easyeye` e `integrator` como pastas irmãs, como no workspace analisado.

| Área | Fontes principais |
| --- | --- |
| Pipeline e fila | [Serviço](../../integrator/src/service/mod.rs), [watcher](../../integrator/src/watcher/mod.rs), [fila](../../integrator/src/queue/mod.rs), [spool](../../integrator/src/spool/mod.rs) |
| Identificação e OCR | [Resolvedor](../../integrator/src/scheduler/mod.rs), [OCR](../../integrator/src/ocr/mod.rs), [metadados](../../integrator/src/equipment/mod.rs), [seletor de regiões](../../integrator/src/ui/ocr_picker.rs) |
| Interface e conferência | [Estado da interface](../../integrator/src/ui/mod.rs), [telas](../../integrator/src/ui/views.rs), [tarefas](../../integrator/src/ui/tasks.rs), [detalhes da fila](../../integrator/src/ui/queue_details.rs) |
| API e comandos | [Cliente da API](../../integrator/src/api/mod.rs), [comandos](../../integrator/src/commands/mod.rs), [contrato documentado](../../integrator/docs/COMMANDS.md) |
| Criptografia | [Módulo criptográfico](../../integrator/src/crypto/mod.rs), [segurança](../../integrator/src/security/mod.rs) |
| DICOM | [Serviços de rede](../../integrator/src/dicom_scp/), [conversão](../../integrator/src/dicom/mod.rs), [limites documentados](../../integrator/docs/DICOM_SCP.md) |
| RPA | [Módulo](../../integrator/src/rpa/mod.rs), [ações](../../integrator/src/rpa/actions.rs), [captura](../../integrator/src/rpa/capture.rs), [limites operacionais](../../integrator/docs/RPA_FALLBACK.md) |
| Build e distribuição | [Cargo.toml](../../integrator/Cargo.toml), [CI](../../integrator/.github/workflows/ci.yml), [release Windows](../../integrator/.github/workflows/release-windows.yml), [atualizador](../../integrator/src/updater/mod.rs) |
| Operação e testes | [Manual](../../integrator/docs/MANUAL_USUARIO.md), [correção manual](../../integrator/docs/MANUAL_CORRECTION.md), [testes](../../integrator/tests/) |

### 14.3. Commits do período

A lista a seguir permite localizar o histórico que fundamentou a revisão. Títulos são os registros originais do Git; o corpo do relatório descreve o conteúdo efetivo, inclusive quando o título é mais amplo que a alteração. Commits de formatação ou documentação não são contados como novas funcionalidades.

**SaaS — 42 commits no recorte:**

| Data | Referência | Registro original |
| --- | --- | --- |
| 2026-09-17 | `39f75b0` | Corrige colisão de função global entre testes Pest (actingAsDoctor/actingAsSecretary) |
| 2026-09-17 | `8983f2e` | API: vínculo opcional equipamento↔recurso de agenda pra escopar Modality Worklist |
| 2026-09-17 | `9ed36d5` | Correção do relatório em excel |
| 2026-09-18 | `1c1682e` | Merge branch 'desenv' of github.com:higormelo-dev/easyeye into desenv |
| 2026-09-18 | `1a991bc` | Correções da falhas apresentadas no relatório |
| 2026-09-20 | `445570f` | TISS: conformidade estrutural do XML 4.03.00 + captura de CNES/CBO/UF |
| 2026-09-20 | `02089f4` | Faturamento: liga individual/lote ao motor TISS real + catálogo TUSS |
| 2026-09-20 | `dc02397` | TISS: fecha ciclo de glosa e recurso |
| 2026-09-20 | `4b15029` | TISS: aviso de pré-validação pra procedimento monocular/binocular sem olho |
| 2026-09-20 | `ecafeae` | Financeiro: corrige condições de corrida que duplicavam dinheiro |
| 2026-09-20 | `6d6e691` | Financeiro: fecha gaps de autorização, injeção e isolamento |
| 2026-09-20 | `9e874b5` | Financeiro: paginação, i18n e cobertura de teste HTTP |
| 2026-09-20 | `9d1216d` | Correção do modal de entrada de caixa |
| 2026-09-24 | `fd85f26` | Eye-images: agrupamento por equipamento/exame, comparador multi-painel, montage, frases rápidas e prioridade de triagem |
| 2026-09-24 | `67cada3` | Integrator commands: fila de comandos remotos pro desktop (enfileirar/poll/ack/prune) |
| 2026-09-24 | `231b05a` | API integradores: expõe full_name do paciente pra correspondência por OCR |
| 2026-09-24 | `7c9ec8f` | Merge remote-tracking branch 'origin/desenv' into desenv |
| 2026-09-25 | `e45027c` | API integradores: data/hora real do exame e observação vindas do equipamento |
| 2026-09-27 | `220eefb` | chore: seeder sem exames fake e rollback seguro da migration de pacientes |
| 2026-09-27 | `732edde` | feat: código de importação e importação de médicos e agendamentos |
| 2026-09-27 | `9b93821` | feat: miniaturas das imagens de exame e período personalizado |
| 2026-09-27 | `db55a7b` | feat: máscaras de CPF, CNPJ, telefone e CEP e ajustes de formulários |
| 2026-09-27 | `fc711f3` | feat: situação "Retornando à consulta" na agenda |
| 2026-09-27 | `2826ee8` | feat: listagens de catálogos, médicos e estoque no padrão de pacientes |
| 2026-09-27 | `9c509e9` | feat: financeiro — telas, números e fluxos (fases 1 e 2) |
| 2026-09-27 | `5b4ea84` | fix: concorrência, numeração e isolamento por clínica |
| 2026-09-27 | `33228da` | feat: financeiro — telas redesenhadas no padrão do painel (fase 3) |
| 2026-09-27 | `2524911` | feat: financeiro — ações em lote, paginação e revisão final (fase 4) |
| 2026-09-27 | `3ec4837` | feat: landing responsiva, acessível e revisada a partir da crítica |
| 2026-09-27 | `77a51a5` | docs: plano comercial, estrutura de CRM e go-to-market |
| 2026-09-27 | `6a1d939` | chore: parar de versionar .DS_Store |
| 2026-09-27 | `6acba3f` | chore: formatar traduções de importação com o Pint |
| 2026-09-27 | `19889f8` | feat: landing — tipografia, microinterações e painel no hero |
| 2026-09-27 | `090cdfb` | feat: Política de Privacidade e Termos de Uso 1.0 publicados |
| 2026-09-27 | `248c220` | feat: páginas de privacidade e termos em inglês (tradução de cortesia) |
| 2026-09-27 | `7f8ecdb` | feat: perfis de acesso no padrão da listagem de pacientes |
| 2026-09-27 | `d51c0e4` | feat: usuários no padrão da listagem de pacientes; restaurar via PATCH |
| 2026-09-27 | `7f3774a` | feat: modelos de documentação no padrão da listagem de pacientes |
| 2026-09-28 | `a062efb` | Correção no composer |
| 2026-09-29 | `6a4dd3a` | feat: repasse médico — regras, apuração, fechamento, pagamento e "Meus repasses" |
| 2026-09-30 | `59acf15` | Adicionando novo módulo, adicionado tour guiado no dashboard primeiramente e melhoria na landing page do projeto |
| 2026-09-30 | `8dac562` | Correção no multi idioma |

**Integrador — 20 commits no recorte:**

| Data | Referência | Registro original |
| --- | --- | --- |
| 2026-09-17 | `a09fa8c` | DICOM SCP — pendências da Fase 4: tela de config, indicador de status, fuzzing (e uma vulnerabilidade real encontrada) |
| 2026-09-17 | `e572d09` | GUI: Tab avança pro próximo campo do formulário |
| 2026-09-17 | `27c8b13` | Worklist DICOM: vínculo opcional equipamento↔recurso de agenda (EasyEye) |
| 2026-09-17 | `b8f796b` | Modo headless --watch-loop ganha re-scan periódico (paridade com service-loop/GUI) |
| 2026-09-17 | `ec0d0e5` | DICOM SCP: auditoria de gaps encontra 5 problemas reais, todos corrigidos |
| 2026-09-17 | `f850b32` | Equipamento: template de nome de arquivo configurável por fabricante |
| 2026-09-20 | `2cbf9b2` | Cripto em repouso, canal de comando remoto e captura RPA |
| 2026-09-24 | `89ba443` | Cadastro de equipamento por função (Padrão/OCR/DICOM) e identificação por OCR |
| 2026-09-24 | `22440d8` | Corrige build Windows e cargo-deny no CI |
| 2026-09-24 | `a8a5dc8` | Correção do build via github actions |
| 2026-09-25 | `fa58796` | Equipamento: origem do identificador (código do sistema ou de importação) |
| 2026-09-25 | `e0c0d7a` | Fila: varredura imediata ao entrar no app |
| 2026-09-25 | `53b07a6` | Exames: data/hora da captura e observação do .EMR enviadas à API |
| 2026-09-25 | `f33cb6d` | OCR: data/hora do exame por regiões marcadas na imagem modelo |
| 2026-09-25 | `2da1d92` | Fila: modal de detalhes, campos recusados pela API e tabela refeita |
| 2026-09-25 | `77544fa` | Chave de criptografia no Keychain do macOS e registros ilegíveis da fila |
| 2026-09-25 | `95005d7` | Equipamento: tipo de exame com busca |
| 2026-09-25 | `f494775` | Fila: excluir item manualmente; "Limpar enviados" e retenção sem reenvio |
| 2026-09-28 | `516b159` | Correção no deploy para gerar build |
| 2026-09-28 | `f7e8f95` | Melhoria no cadastro de equipamento e fila do processo |

**Situação final da entrega:** implementada e publicada no ambiente de teste, disponível para homologação dos sócios. A aprovação interna, a validação com equipamentos/operadoras e a liberação em produção devem ter seus resultados registrados separadamente.
