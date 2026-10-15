# Auditoria de segurança — referência Cloudflare

- **Projeto:** EasyEye, SaaS para clínicas oftalmológicas.
- **Data da análise:** 14 de outubro de 2026.
- **Referência organizacional:** Cloudflare.
- **Método:** inspeção estática orientada pela skill global `security-audit`, com revisão independente dos candidatos.
- **Skill principal:** `/home/higor/.agents/skills/security-audit/SKILL.md`.
- **Referência Git:** `0485d67fb794de20f31c94bffe6cd57ba584b1e1`, incluindo as alterações locais e arquivos novos presentes durante a análise.
- **Stack observada:** Laravel 12, PHP `^8.4`, PostgreSQL, Redis, Vue/Inertia e APIs REST.

> “Cloudflare” identifica a organização deste diretório. Este documento não foi emitido pela Cloudflare e não representa auditoria da conta, das políticas ou da infraestrutura Cloudflare implantada.

## 1. Resumo executivo

A análise identificou **seis candidatos concretos no código/configuração e duas dependências com versões afetadas**. O candidato prioritário é um possível IDOR entre clínicas no fluxo de IA: IDs de exames podem escapar da verificação de posse em workflows textuais e alcançar a resposta de uma execução pertencente à clínica solicitante.

Também foram identificados caminhos de acesso após revogação de vínculo, concessão de permissões customizadas a vínculo inativo, possível evasão do limite de login por cabeçalhos de proxy e interpretação de dados não confiáveis como fórmulas em exportações CSV/XLSX.

**Nenhuma exploração da aplicação foi reproduzida.** Os seis candidatos permanecem em `needs_validation`. Os caminhos foram examinados na fonte e revisados independentemente; isso não equivale a observar o resultado em execução.

A auditoria original não alterou arquivos nem executou código do projeto, testes, builds, migrations ou comandos destrutivos. A criação posterior deste relatório foi autorizada separadamente e não altera essas conclusões.

## 2. Método, classificação e limites

Foi utilizada somente a skill global `security-audit` e seus complementos de categorias, reconhecimento, investigação, validação, web/autenticação, navegador, isolamento de dados, IA, mensageria, implantação e dependências. Não foram acrescentadas regras próprias do EasyEye como critérios de auditoria.

Para cada candidato foram procurados principal de menor confiança, entrada controlável, controles existentes, recurso afetado, caminho até o efeito e condições que poderiam refutar a hipótese.

A skill reserva a classificação de vulnerabilidade confirmada à evidência completa e à validação limitada do resultado. Para atender ao formato solicitado pelo usuário, este relatório apresenta **severidade preliminar condicionada à validação**, sem promover os candidatos a `confirmed`.

O protocolo completo de `full audit` não foi concluído: não foram produzidos os artefatos estruturados, executados seus validadores ou reproduzidos os cenários. A execução original proibia criação de arquivos; a autorização posterior abrange o registro Markdown deste relatório, sem nova rodada de testes.

As referências de linhas descrevem o estado examinado. Como havia alterações locais, o commit isoladamente não reconstrói todo o conteúdo auditado. Este documento é um registro da análise anterior, não uma revalidação do estado posterior do projeto.

### Contagem por severidade

| Severidade | Aplicação: exploração confirmada | Candidatos: severidade preliminar | Dependências: severidade do advisory |
|---|---:|---:|---:|
| Critical | 0 | 0 | 0 |
| High | 0 | 3 | 1 |
| Medium | 0 | 3 | 1 |
| Low | 0 | 0 | 0 |
| Informational | 0 | 0 | 0 |

As colunas distinguem vereditos e não devem ser interpretadas como uma soma de vulnerabilidades exploráveis na aplicação. A presença das versões afetadas das dependências foi verificada, mas seu alcance por entrada não confiável da aplicação não foi demonstrado.

## 3. Candidatos no código e na configuração

### SEC-01 — Exames de outra clínica vinculados a execução de IA

- **Severidade preliminar:** High.
- **Categoria:** autorização, IDOR e isolamento entre tenants.
- **Estado:** `needs_validation`.
- **Exploração:** provavelmente explorável, sem reprodução.

#### Evidência e fluxo

1. As rotas de IA do painel permitem os perfis `admin`, `doctor` e `secretary`, sujeitos aos controles do painel e às features aplicáveis.
2. [`StoreAiRunRequest::rules()`](../../../../../app/Http/Requests/AI/StoreAiRunRequest.php), linhas 42–57, aceita `exam_ids` em workflows textuais e valida os elementos apenas como UUID.
3. [`AiPayloadEnricher::applyWorkflowDefaults()`](../../../../../app/Domains/AI/Services/AiPayloadEnricher.php), linhas 119–127, chama `authorizeExamIds()` somente para `eye_image_analysis`. A verificação de contexto, linhas 308–323, examina paciente e prontuário opcionais, sem validar os exames dos demais workflows.
4. [`AiRunsController::store()`](../../../../../app/Http/Controllers/AiRunsController.php), linhas 539–547, insere o vínculo entre a execução da clínica A e o exame informado, atribuindo ao vínculo o `entity_id` de A.
5. A [migration de `ai_run_patient_exam`](../../../../../database/migrations/2026_06_08_000000_create_ai_run_patient_exam_table.php), linhas 15–22, contém FKs independentes, sem garantir que o exame e a execução pertencem à mesma clínica.
6. [`PatientExam`](../../../../../app/Models/PatientExam.php) não possui o scope global de tenant examinado. A relação `AiRun::exams()` também não verifica a posse real do exame.
7. `AiRunsController::show()`, linhas 255–273 e 291–314, autoriza a execução de A e devolve diagnóstico e data dos exames vinculados em `exam_context`.

#### Condições e impacto

O cenário exige sessão autorizada na clínica A, assinatura/feature e modo de IA disponíveis, créditos para reserva e conhecimento prévio de um UUID válido de exame da clínica B.

O caminho estático permite que diagnóstico e data de B alcancem a resposta da execução de A. Essa hipótese não depende de worker, arquivo de imagem ou chamada ao provedor. Nome do médico e tipo privado de exame não são resultados garantidos, pois relações adicionais podem aplicar seus próprios scopes.

Existem extensões condicionais do mesmo problema: [`AiRunExecutionService::resolveImages()`](../../../../../app/Domains/AI/Services/AiRunExecutionService.php), linhas 278–294, consulta IDs sem filtro de tenant; `AiRunsController::approve()`, linhas 676–680, seleciona exames pelo tenant atribuído ao vínculo para atualizar diagnósticos. Processamento de imagens e alteração de diagnóstico exigem outras condições e não foram demonstrados.

#### Correção sugerida

Rejeitar `exam_ids` em workflows incompatíveis; verificar a posse de todos os exames antes de persistir o vínculo; conferir a posse real novamente na leitura, execução e aprovação. O `entity_id` do vínculo não deve funcionar como prova da propriedade do exame. Investigar vínculos inconsistentes já existentes antes de tratá-los como confiáveis.

#### Validação segura pendente

Usar duas clínicas fictícias e fila simulada. Criar em B um exame com diagnóstico sentinela e data conhecida; criar em A uma execução textual referenciando esse UUID e consultar a execução. Verificar ausência do marcador na resposta. O workflow `eye_image_analysis`, que verifica posse, serve como controle negativo. Não acessar S3 ou provedor externo.

### SEC-02 — Sessão conserva acesso a imagens após revogação do vínculo

- **Severidade preliminar:** High.
- **Categoria:** autorização e revogação de acesso.
- **Estado:** `needs_validation`.
- **Exploração:** provavelmente explorável, sem reprodução.

#### Evidência e fluxo

- [`EnsureEntitySelected::handle()`](../../../../../app/Http/Middleware/EnsureEntitySelected.php), linhas 23–50, consulta vínculos ativos somente quando não existe `selected_entity_user_id` na sessão.
- As [rotas de leitura de imagens](../../../../../routes/web.php), linhas 234–239, não acrescentam verificação de membership.
- [`EyeImagesController::search()`](../../../../../app/Http/Controllers/EyeImagesController.php), linha 278 em diante, consulta usando a clínica da sessão. `patientExamUrls()`, linhas 291–325, compara o paciente com essa clínica e emite novas URLs S3 por duas horas. `imageUrl()`, linhas 387–399, emite URL por uma hora.
- [`EntityUserService::update()`](../../../../../app/Services/EntityUserService.php), linhas 40–63, desativa o vínculo sem invalidar esse contexto. `UsersController::destroy()` aplica exclusão lógica ao vínculo, sem excluir a identidade global ou invalidar suas sessões.

#### Impacto

Ex-funcionário com sessão anteriormente válida pode potencialmente continuar consultando metadados e solicitando novas URLs de exames depois da desativação ou exclusão lógica do vínculo. Os controles de identidade, 2FA e assinatura comercial examinados não revalidam a associação atual com a clínica.

#### Correção sugerida

Revalidar em cada requisição que o vínculo selecionado pertence ao usuário e à clínica, está ativo e não foi removido. Negar e limpar o contexto inválido antes das consultas, preservando também os controles locais de posse dos recursos.

#### Validação segura pendente

Reutilizar uma sessão fictícia após desativação e após exclusão lógica do vínculo, verificando negação nos endpoints de busca e emissão de URLs, com armazenamento simulado.

### SEC-03 — Permissões customizadas consideram vínculo inativo

- **Severidade preliminar:** High.
- **Categoria:** controle de acesso e RBAC.
- **Estado:** `needs_validation`.
- **Exploração:** provavelmente explorável; exige vínculo inativo com permissão customizada preservada.

#### Evidência e fluxo

- [`HasEntityRoles::entityUserFor()`](../../../../../app/Traits/HasEntityRoles.php), linhas 86–92, inclui vínculos inativos. `hasPermissionInEntity()`, linhas 225–252, consulta roles/permissões desse vínculo sem exigir `active=true`.
- [`EnsureEntityPermission::handle()`](../../../../../app/Http/Middleware/EnsureEntityPermission.php), linhas 90–97, aceita esse resultado.
- Um destino concreto é [`PatientsController::updatePriority()`](../../../../../app/Http/Controllers/PatientsController.php), linhas 497–511: compara a clínica da sessão e grava `priority_rating`, sem outra autorização.

#### Condições e impacto

Usuário desativado, com sessão anterior e role customizada contendo `patients.manage`, pode conservar acesso ao cadastro e alcançar alteração de prioridade de pacientes. O ramo de roles fixas exige vínculo ativo; a inconsistência está no ramo customizado.

Este candidato não inclui vínculo com exclusão lógica, que é filtrado pela consulta. SEC-02 e SEC-03 compartilham o contexto de sessão, mas possuem causas e pontos de correção distintos.

#### Correção sugerida

Exigir membership ativo e não removido dentro da própria decisão de permissão, antes de consultar roles. Preservar a consulta de vínculos inativos apenas para apresentação.

#### Validação segura pendente

Comparar usuário fictício desativado com role customizada, sem role customizada e com vínculo excluído. Verificar resposta HTTP e persistência da prioridade, usando apenas paciente fictício.

### SEC-04 — Cabeçalho de proxy pode alterar o limite de login

- **Severidade preliminar:** Medium.
- **Categoria:** configuração insegura e evasão de rate limit.
- **Estado:** `needs_validation`.
- **Exploração:** depende de condição adicional de implantação.

#### Evidência e fluxo

- [`bootstrap/app.php`](../../../../../bootstrap/app.php), linhas 74–80, configura `trustProxies(at: '*')`, incluindo `X-Forwarded-For`.
- [`LoginRequest::throttleKey()`](../../../../../app/Http/Requests/Auth/LoginRequest.php), linhas 81–83, usa e-mail e `$this->ip()`.
- A [configuração Nginx/FastCGI](../../../../../.docker/nginx/laravel.conf), linhas 42–52, não redefine esse cabeçalho no caminho PHP.
- O [Compose](../../../../../docker-compose.yml), linhas 108–112, publica a porta HTTP sem restringir o endereço de bind.

#### Condições e impacto

Se o cliente alcançar essa entrada sem normalização adequada dos cabeçalhos, poderá alterar o IP resolvido e trocar o bucket de tentativas. Também pode prejudicar a confiabilidade do IP registrado em auditoria.

Isso não demonstra quebra de senha, de 2FA ou tomada de conta. Um ingress que acrescente corretamente o IP real pode neutralizar o cenário. Firewall, configuração FastCGI efetiva e tratamento dos cabeçalhos na implantação não foram observados.

#### Correção sugerida e validação pendente

Definir os proxies efetivamente confiáveis e normalizar cabeçalhos na entrada. Avaliar proteção de tentativas por identidade independente da troca de IP. Validar em fixture isolada a resolução de IP e o limite com dois valores XFF, sem contas reais.

### SEC-05 — User-Agent chega sem neutralização ao CSV de auditoria

- **Severidade preliminar:** Medium.
- **Categoria:** injeção de fórmulas em CSV.
- **Estado:** `needs_validation`.
- **Exploração:** depende de aplicativo que interprete fórmulas ao abrir/importar o CSV.

#### Evidência e fluxo

[`AuditService::log()`](../../../../../app/Services/AuditService.php), linha 55, grava `request()->userAgent()`. Um usuário operacional pode produzir esse registro ao executar uma ação auditável, como cadastrar paciente.

[`ComplianceController::exportAuditLogs()`](../../../../../app/Http/Controllers/ComplianceController.php), linhas 74–82, passa o valor diretamente a `fputcsv`. O consumidor é um administrador autorizado a exportar os logs da mesma clínica.

#### Impacto

Conteúdo escolhido por usuário menos privilegiado pode ser interpretado como fórmula no arquivo aberto pelo administrador. `fputcsv` preserva a estrutura CSV, mas não força interpretação textual da célula. Não foram demonstrados RCE ou exfiltração.

#### Correção sugerida e validação pendente

Neutralizar células textuais no momento da exportação, preservando o conteúdo original no log. O projeto já possui [`SpreadsheetWriter::sanitizeCell()`](../../../../../app/Support/Export/SpreadsheetWriter.php), linhas 35–41. Revisar também nomes e justificativas no CSV de acessos.

Validar exportação fictícia com `User-Agent: =1+1`, inspecionando os bytes e a abertura offline no consumidor utilizado. Não usar fórmulas com rede ou comandos.

### SEC-06 — Nome de clínica pode virar fórmula no XLSX de parceiros

- **Severidade preliminar:** Medium.
- **Categoria:** injeção de fórmulas em planilha.
- **Estado:** `needs_validation`.
- **Exploração:** depende de comissão associada à clínica e consumo do relatório.

#### Evidência e fluxo

- [`RegisterRequest::rules()`](../../../../../app/Http/Requests/Auth/RegisterRequest.php), linha 39, aceita `company_name` como texto.
- [`CreateEntityAction::execute()`](../../../../../app/Actions/Register/CreateEntityAction.php), linha 17, grava esse texto no nome da clínica.
- [`PartnersCommissionsSheet::collection()`](../../../../../app/Exports/PartnersReportExport.php), linhas 287–294, entrega o nome diretamente à célula.
- O binder padrão instalado do PhpSpreadsheet classifica strings iniciadas por `=` como fórmula. Não foi encontrado binder customizado nesse export.

#### Condições e impacto

Dado controlado por uma clínica pode tornar-se expressão no relatório consumido pelo financeiro ou administrador SaaS. Cadastro público, sozinho, não basta: a clínica precisa aparecer em uma comissão incluída na exportação. Efeitos externos ou execução de comandos não foram demonstrados.

#### Correção sugerida e validação pendente

Usar tipagem textual explícita para campos textuais não confiáveis, preservando os tipos das colunas numéricas. Validar com clínica e comissão fictícias e nome `=1+1`; inspecionar se o XLSX contém fórmula ou texto literal.

## 4. Vulnerabilidades confirmadas e investigações adicionais

Não houve exploração da aplicação confirmada pelo critério da skill. Os seis candidatos acima possuem evidência de fonte e revisão independente, mas ainda precisam de validação limitada do resultado.

A presença das versões afetadas das duas dependências da seção seguinte foi verificada. Isso não confirma exploração da aplicação por essas dependências.

### Política de 2FA pessoal

[`EnsureTwoFactor`](../../../../../app/Http/Middleware/EnsureTwoFactor.php), linhas 84–99, libera empresas sem exigência mesmo quando o usuário cadastrou TOTP. A fonte declara essa política, enquanto a interface apresenta a proteção pessoal como “Ativo”. É uma garantia a esclarecer, não um bypass comprovado da exigência empresarial.

Se inscrição pessoal deve proteger o login independentemente da obrigatoriedade empresarial, será necessário separar obrigação de inscrição e exigência de verificação da sessão. Caso a política atual seja deliberada, a comunicação da interface deve refletir esse comportamento. Não foi atribuído um achado adicional a essa ambiguidade.

## 5. Dependências com versões afetadas

As versões foram verificadas no lockfile e os dois casos abaixo foram confrontados com avisos públicos durante a auditoria. São dependências de desenvolvimento/build; não foi demonstrado caminho de entrada HTTP da aplicação até esses parsers.

| Componente | Evidência local | Advisory | Situação |
|---|---|---|---|
| `braces 3.0.3` | [`package-lock.json`](../../../../../package-lock.json), linhas 2612–2616 | **High**, [CVE-2026-93687 / GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) | Recursão excessiva com padrões aninhados; aviso consultado não indica versão corrigida. |
| `postcss-selector-parser 6.1.4` | Cópias sob `postcss-nested` e `tailwindcss` no mesmo lockfile, linhas 5558–5562 e 6277–6281 | **Medium**, [CVE-2026-104844 / GHSA-rj75-hqrm-r3gf](https://github.com/advisories/GHSA-rj75-hqrm-r3gf) | Complexidade quadrática; versão corrigida indicada: `7.1.6`. A cópia raiz atualizada não substitui as transitivas. |

Não foram executados novos `composer audit` ou `npm audit`. Os relatórios salvos de 11/10 foram usados como pistas e confrontados com as versões atuais na ocasião; seus totais antigos não foram reapresentados como vulnerabilidades atuais. A verificação não substitui uma consulta completa e atualizada de todas as dependências, incluindo o ambiente E2E.

## 6. Riscos arquiteturais e cobertura

### Riscos observados

- **Isolamento por relações:** autorizar uma execução de IA não autoriza automaticamente seus exames. SEC-01 apresenta um caminho concreto dessa falha.
- **Revogação distribuída:** contexto de sessão, roles fixas e permissões customizadas aplicam verificações diferentes de atividade.
- **Contexto de tenant:** [`EntityScope`](../../../../../app/Models/Scopes/EntityScope.php), linhas 24–25, fica inerte sem tenant vinculado. APIs, jobs e outros caminhos precisam manter seus predicados explícitos.
- **Arquivos assinados:** URLs já emitidas possuem validade própria. Bloquear novas emissões após revogação não invalida automaticamente URLs anteriores.
- **Infraestrutura efetiva:** confiança em proxy, permissões Redis/S3, acesso a logs e configurações carregadas em produção não foram estabelecidos pela inspeção de fonte.

### Cobertura realizada

| Área | Resultado da inspeção |
|---|---|
| Autenticação, autorização, ACL, IDOR e multi-tenancy | Candidatos SEC-01, SEC-02 e SEC-03; guards, middleware, relações e controles de posse examinados. |
| SQL injection, command injection e serialização insegura | Não foi identificado outro caminho concreto nos sinks examinados; queries parametrizadas, identificadores restritos e processos com argumentos separados foram observados. |
| XSS, CSRF, mass assignment, uploads e filesystem | Nenhum outro candidato suficiente nos caminhos examinados; Purifier, validações, armazenamento privado e verificações locais de posse observados. Não houve teste de navegador. |
| SSRF | Não foi estabelecido ator capaz de controlar os destinos investigados. Endpoint TISS armazenado em banco, isoladamente, não foi reportado como exploração. |
| APIs, webhooks e WebSocket | Autenticação de integradores, validação de callbacks e autorização de canais privados examinadas. Sem novo bypass suficiente nesses caminhos. |
| Secrets, credenciais e logs | Nenhuma exposição adicional demonstrada a leitor indevido. A busca não equivale a auditoria completa do histórico Git ou de todos os formatos de segredo. |
| Redis/cache e filas/jobs | Tokens cifrados, chaves com contexto e controles de execução examinados. ACL/TLS efetivos de Redis não observados. |
| CORS, cookies, sessões e configuração Laravel/PHP/Vite | Fonte inspecionada; comportamento efetivo de navegador e implantação não validado. SEC-04 depende dessas condições externas. |
| Revisão clínica externa | Assinatura, token, PIN, rotação, revogação, vínculo dos itens, draft e submissão examinados. Nenhum novo candidato suficiente. |
| Ciclo assíncrono de IA | Passada adicional identificou SEC-01; controles de cancelamento, aprovação, reserva e liberação de créditos também examinados. |

### Lacunas e exclusões

- Não houve execução de fixtures, testes HTTP/DB, testes de concorrência ou processamento real de documentos e imagens.
- Não foram acessados produção, contas reais, dados clínicos reais, S3, Redis ou provedores externos para exploração.
- Não foram verificados todos os controllers, todas as combinações de autorização ou o histórico completo de credenciais.
- Uma requisição de revisão clínica já autorizada antes de reenvio concorrente do convite pode exigir estudo de semântica de rotação; sem efeito reproduzido, isso não foi promovido a achado.
- A cobertura é estática e parcial. Ausência de candidato em uma categoria não significa ausência de vulnerabilidades.

## 7. Top 10 correções propostas por prioridade

As mudanças abaixo são propostas para uma segunda etapa, com validação das condições aplicáveis. Nenhuma foi aplicada nesta auditoria.

1. **SEC-01:** bloquear `exam_ids` incompatíveis e verificar a posse antes de criar vínculos de IA.
2. **SEC-01:** revalidar a posse dos exames no worker antes de acessar arquivos privados.
3. **SEC-01:** aplicar a mesma verificação em `show` e `approve`; investigar vínculos inconsistentes existentes.
4. **SEC-02:** revalidar o membership selecionado em toda requisição ao painel.
5. **SEC-03:** rejeitar vínculos inativos na decisão de permissão customizada.
6. **SEC-04:** restringir confiança em proxies e normalizar cabeçalhos na entrada.
7. **SEC-05:** neutralizar textos nos dois CSVs de Compliance.
8. **SEC-06:** forçar tipo textual nos campos livres do XLSX de parceiros.
9. **Dependências:** resolver as cópias transitivas vulneráveis de `postcss-selector-parser`, verificando compatibilidade.
10. **Dependências:** impedir padrões não confiáveis nos consumidores de `braces` e avaliar substituição ou atualização quando houver correção adequada.

## 8. Arquivos prováveis para a segunda etapa

| Frente | Arquivos e pontos de revisão |
|---|---|
| IA e isolamento | `app/Http/Requests/AI/StoreAiRunRequest.php`; `app/Domains/AI/Services/AiPayloadEnricher.php`; `app/Http/Controllers/AiRunsController.php`; `app/Domains/AI/Services/AiRunExecutionService.php`; relação `app/Domains/AI/Models/AiRun.php::exams()` e integridade dos vínculos persistidos. |
| Revogação e RBAC | `app/Http/Middleware/EnsureEntitySelected.php`; `app/Traits/HasEntityRoles.php`; composição de middleware em `routes/web.php`. |
| Proxy e limites | `bootstrap/app.php`; `.docker/nginx/laravel.conf`; eventualmente `app/Http/Requests/Auth/LoginRequest.php`, conforme topologia efetiva. |
| CSV | `app/Http/Controllers/ComplianceController.php`, reutilizando `app/Support/Export/SpreadsheetWriter.php`. Preservar o conteúdo original dos logs. |
| XLSX | `app/Exports/PartnersReportExport.php` e binder específico, caso necessário. |
| Dependências | `package.json` e `package-lock.json`, conforme resolução transitiva compatível. |
| Regressões | Testes de isolamento da IA, revogação, permissões, exportações e resolução de IP com dados exclusivamente fictícios. |

## 9. Estado de encerramento

- **Auditoria estática solicitada:** encerrada, com os limites registrados.
- **Candidatos da aplicação:** seis, todos `needs_validation`.
- **Explorações reproduzidas:** nenhuma.
- **Dependências com versões afetadas identificadas:** duas, sem alcance remoto da aplicação demonstrado.
- **Correções aplicadas:** nenhuma.
- **Protocolo completo da skill:** incompleto quanto a artefatos estruturados, validadores e reprodução dinâmica.
- **Este documento:** registro posterior autorizado do relatório apresentado na conversa; não constitui nova execução da auditoria.
