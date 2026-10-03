# Contratos do integrador P2

O catálogo executável está em `integrators-openapi.yaml`. Todos os endpoints clínicos continuam sujeitos à licença e à finalidade do token. As rotas `updates`, `queue-health` e `commands` permitem recuperação operacional de um ator ativo sem licença clínica; isso não permite pesquisar pacientes, criar equipamentos, obter snapshots ou enviar exames.

## Escopo e evidências

| Requisito | Implementação | Prova permanente |
|---|---|---|
| C08 / UP08 / A9 | `IntegratorExamArchive`, `EmrReport`, `BoundedExamArchive`, `GenerateExamDerivatives`, `BoundedPdfProcess` | `P2ContractsTest`, `P2BoundariesTest`, testes existentes EyeImages/PDF |
| C09 / SEC01–03 | Resources próprios, ator autenticado em audit/access logs, leitura agregada, health minimizado | `P2ContractsTest`, `QueueHealthTest`, `EntityIntegratorQueueHealthControllerTest` |
| C09 finalidade de token | Perfis capture/worklist/support autorizados no Manager; `IntegratorTokenPolicy` e `EnsureTokenScope` | `AuthTest`, `TokenScopeTest`, `P2ContractsTest` |
| C10 / SEC04–05 / F16 / A8 | Publicação V2 obrigatória, storage verificado, artefato imutável entre coortes, seleção autorizada | testes publisher/CLI/Manager/updates e `P2BoundariesTest` |
| C11 / SEC06–07 | Política única de token; ACK terminal atômico, prazo, resultado fechado e limitado | `AuthTest`, `CommandsTest`, `P2ContractsTest`, `IntegratorP2ConcurrencyTest` |
| C12 / A3 / A5 | Catálogo integral, snapshot real com pacientes referenciados e data local explícita | inventário `validate-integrator-contracts.php`, fixtures HTTP e `P2ContractsTest` |
| C12 / A7 | Manager solicita diagnóstico/resync/reload; backlog20, poll20, prazo e resultados/capabilities | testes ManagerCommands, `IntegratorCommands.test.js`, health Vue |
| C12 / A10 | Cadastro, autenticação, captura observada e recibo confirmado são marcos distintos | jornada HTTP em `P2ContractsTest`, `ActivationServiceTest` |
| F11 | Recibo durável equipamento/operação, replay após24h e CAS obrigatório em novo PUT | `P2ContractsTest`, concorrência HTTP em `IntegratorP2ConcurrencyTest` |
| UP07 | Quota e recibo na mesma transação P1; geração EXM compartilhada sob lock e unique por paciente | `IntegratorOutboxCommitTest`, concorrência/quota/month-boundary P2 |
| A1/A2/A4/A6 e C01–07 P1 | Captura original, recibo, outbox, identidade canônica e revisão preservados | suíte API P1, integração de commit e deeplink JavaScript |

O estado clínico `fulfillment` permanece `unknown`. Captura observada significa telemetria declarada pelo dispositivo; recibo confirmado significa transação de recebimento, sem concluir revisão médica.

## Formatos e recursos

Ambas as rotas de upload, flat e aninhada, aceitam JPEG/PNG/BMP/PDF até10MiB e `.emr` **OCULUS-PDM-text-v1** UTF8 até1MiB. EMR usa as labels exatas Last Name, First Name, Patient ID, Exam Eye, Exam Date, Exam Time, Display, Exam Infotext, Date of Birth, Patient Comment 1 e Patient Comment 2. Patient ID é obrigatório; duplicatas, labels desconhecidas, HTML, controles e encoding incompatível são rejeitados. BOM UTF8 e CRLF são aceitos. Máximo512linhas,512caracteres por valor. Identidade clínica continua no payload canônico P1; nomes/observações do relatório não fazem resolução de paciente.

Original e hash permanecem intactos. O viewer EMR usa derivada SVG literal escapada. Derivadas só ficam `ready` após writes e hashes verificados; falhas ficam `failed`, ausência de capacidade fica `unsupported`. Atualizações de estado/referência usam CAS pelo original observado, inclusive jobs antigos sem expectedArchive explícito.

Raster: dimensões até16k/40MP e orçamento antes de GD para fonte/cópia/escala/buffers, limitado à memória disponível e teto256MiB. PDFs usam subprocesso PHP com memory_limit128MiB, RLIMIT_AS512MiB/CPU12s/FSIZE16MiB herdados por delegates e grupo `setsid` interrompido no timeout. Máximo64páginas,256KiB texto,10MiB resultado; timeout10s texto/15s render. Render depende de Imagick/Ghostscript e isolamento Linux disponível. PDF escaneado não produz texto por OCR; original continua disponível. Limites POSIX seguem o [manual oficial PHP](https://www.php.net/manual/en/posix.constants.setrlimit.php).

## Autorização, telemetria e suporte

Perfil é estado autorizado do SaaS, nunca escolhido pelo signin. Capture permite pacientes/agenda/exames/equipamento e operação; worklist permite agenda/equipamento e operação; support só telemetria/comandos/updates. Tokens legados mantêm compatibilidade de verbo dentro do perfil autorizado até renovação, que converte abilities de finalidade conhecidas. Token read não ganha write. Owner, validade e inatividade de3dias úteis usam a mesma política no check e na API.

Logs incluem entidade, ator integrador, integrator/device e ID do token autenticado, sem bearer. Leituras de listas registram contagem e filtros técnicos por página, sem texto de busca. Health aceita DTO legado limitado, mas elimina referências paciente/agenda e erro livre antes de persistir/retornar; filename vira referência HMAC secreta. Snapshot atual obsoleto minimizado após30dias; histórico retido7dias contém **somente contadores e timestamps**, sem coluna problems. Commands terminais retidos30dias; expiry usa a mesma deadline no recurso, ACK e prune.

Diagnostics tem teto16KiB,32devices e total_devices/devices_truncated explícitos. Health tem teto64KiB,100devices/worklist e enum técnico fechado, incluindo configuration_sync e instalação. `awaiting_restart`/`reboot_required` não significam versão instalada; confirmação vem da versão efetiva após reinício. Reload informa leitura de configuração no uso, sem alegar recarga que não ocorreu.

## Equipamento e snapshot

Novo POST/PUT envia operation_id+installation_id UUID e Idempotency-Key igual operation_id. POST nasce generation1; PUT exige expected_config_generation>=1. Recibo criptografado e equipamento são atômicos; retry imutável retorna o mesmo equipamento além24h, inclusive após renovação do token. Divergência409 `equipment_operation_payload_conflict`; CAS409 `equipment_config_generation_conflict`; lock ocupado409 `equipment_operation_in_progress` com Retry-After5. GET equipment-operations/{operation} reconcilia o recibo scoped. Novo fluxo não adota equipamento pelo nome/IP.

Snapshot exige recurso e janela<=7dias, até500registros combinados e1MiB JSON, TTL24h. Excesso422 pede janela menor; nunca retorna complete para conjunto truncado. clinic_timezone é configuração de calendário do SaaS, clinic_date/local_date_time preservam calendário local e date_time representa instante UTC. Teste São Paulo prova9h=12Z e23h30 local continua no dia clínico anterior. Snapshot não autoriza upload nem substitui revalidação atual.

## Publicação e piloto

CLI/Manager/service exigem metadata V2 e manifest-signature; nova publicação artifact-only é recusada. Row V1 histórica fica visível como unsigned/installable=false. Metadados são assinados offline; SaaS nunca recebe chave privada. CLI: `integrator:publish-update FILE --release-version=VERSION --arch=x86 --signature=BASE64 --metadata=JSON --manifest-signature=BASE64`.

Bytes canônicos: prefixo `easyeye-integrator-update-v2\n`, seguido por release_id,sequence,version,platform,arch,sha256,size_bytes,issued_at,expires_at,channel,cohort,min_client_version,min_contract_version,min_os_version,max_os_version,asset_signature,key_id,rollback_from; um valor ASCII por linha e newline terminal. Null representa linha vazia. URL efêmera fica fora da assinatura. key_id permitido release-v1. Win7 SP1 x86 exige6.1.7601; x64 Windows10 exige10.0 no release apropriado. Teto artefato256MiB.

Seleção usa canal/coorte do integrador autenticado. Pilot exige coorte específica, stable exige all. Mesmo platform/arch/version nunca muda SHA, inclusive entre coortes; promoção do mesmo binário usa novos metadados/sequence. Storage privado usa target/arch/version/hash/basename e readback de tamanho+SHA antes de ativação. Falha mantém release anterior. Candidatos do catálogo são limitados100 e V2 válido tem preferência sobre legado unsigned. Cliente verifica minclient/mincontract/OS/expiry/sequence e só permite rollback com confirmação explícita e rollback_from correspondente.

URL de artefato HTTPS sem credenciais/fragmento e porta443: host exato da API HTTPS configurada ou namespaces oficiais S3 AWS; cliente impede redirect e nunca envia bearer. Sem asset_host novo na assinatura. Nenhum endpoint/bucket real é fornecido por fixtures.

## Verificação e implantação

Orçamento raster limita novas superfícies do decode/flatten/scale a256MiB por operação e também ao memory_limit PHP restante com reserva16MiB. Heap pré-existente de worker longo não é descontado do teto incremental; memory_limit=-1 mantém o teto por operação. PNG com dimensions infladas continua rejeitado antes do decode. Falha de renderer no importador externo após commit preserva original e estado failed/unsupported, sem devolver500 como se o upload confirmado tivesse falhado. Publisher integrador continua retendo intent durável em falha. O harness Pest restaura o estado estático de hosts Symfony entre testes; a política trustHosts de produção permanece intacta.

Edição Manager devolve token_profile/update_channel/update_cohort persistidos; renomear preserva finalidade e piloto. Resposta de edição incompleta bloqueia salvamento no modal. POST legado com hardware ativo duplicado continua recusado pela validação; restauração por hardware de registro excluído mantém compatibilidade, mas adquire lock da linha e avança config_generation. PUT operacional com geração observada antes dessa restauração retorna409 sem mutação ou recibo da operação. Concorrência HTTP com conexões distintas cobre restauração versus CAS.

CI `.github/workflows/integrator-contracts.yml` usa PG17/PHP8.4 e extensões pcntl/posix, fixtures sem PHI, permissões contents:read e ações fixadas em SHAs de tags oficiais. Npm build inclui navegador e SSR antes dos testes PHP; style de fontes alteradas, PHP/JS completos e inventário OpenAPI são gates. Fixtures geradas ficam storage/framework/testing/p2.

Migração recusa EXM scoped duplicado antes de qualquer mudança. Rollback recusa versões promovidas duplicadas entre coortes antes de mutar schema/metadata/ledger; reconciliação exige operação explícita, sem exclusão automática. Nenhuma migração real, apply, commit, push ou deploy foi executado durante a validação isolada.

Homologação física Win7/OCULUS/Pentacam/Zeiss permanece com os equipamentos do piloto. Fixtures são sintéticas e demonstram contratos/formato/limites, sem afirmar homologação de fabricante ou novas modalidades P3.
