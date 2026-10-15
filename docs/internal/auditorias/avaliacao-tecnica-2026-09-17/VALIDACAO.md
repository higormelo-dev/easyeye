# Evidências de validação — 17/09/2026

## Resultados consolidados

| Verificação | Resultado | Condição |
|---|---|---|
| PHP/Pest: comando completo original | Interrompido antes de executar | Funções globais duplicadas `actingAsDoctor` e `actingAsSecretary` |
| PHP/Pest: Unit | **255 passaram; 738 assertions** | Acesso ao PostgreSQL local `easyeye_test`; runtime de IA fake |
| PHP/Pest: Feature, excluindo um arquivo com colisão | **1.412 passaram; 17 falharam; 4.866 assertions** | Configuração temporária, banco `easyeye_test`, memória PHP 512 MB; 267,52 s |
| PHP/Pest: arquivo separado `Stock/MedicalRecordProceduresTest.php` | **11 passaram; 26 assertions** | Configuração original, processo separado; 5,59 s |
| Total funcional PHP, sem contar Unit | **1.423 passaram; 17 falharam** | Todos os 169 arquivos Feature abrangidos entre as duas execuções |
| Vitest | **117 passaram, 11 arquivos** | `npm test -- --reporter=dot`; 4,67 s |
| Vite client | **Passou** | 1.033 módulos transformados; saída em diretório temporário; 18,55 s |
| Vite SSR | **Passou** | 300 módulos transformados; saída temporária; 6,12 s |
| Rust `cargo test --locked --all` | **179 passaram; 2 ignorados; zero falhas** | Build local macOS; 85 testes internos + 94 nos arquivos de integração |
| Laravel `route:list --json` | **Passou; 578 registros** | Evidência de carregamento/registro, não de sucesso HTTP em cada rota |
| Autoload da exportação Excel | **Dependências ausentes** | Maatwebsite Excel e PhpSpreadsheet não disponíveis |

Os dois testes Rust ignorados estão em `src/dicom/mod.rs` e são auxiliares manuais associados a arquivos externos; não foram ativados. Não confundir 179 testes macOS com homologação de instaladores e APIs Windows.

## Ajustes de ambiente necessários para executar

1. O sandbox inicialmente bloqueou o PostgreSQL local. Com acesso autorizado, os mesmos 255 testes Unit passaram. Os erros de conexão iniciais não foram contados como defeitos da aplicação.
2. O cache Rust offline não continha a versão travada de `smallvec`. Foi autorizado baixar as dependências. A compilação usou `CARGO_TARGET_DIR=/private/tmp/easyeye-valuation-cargo-target`; a árvore do integrador permaneceu sem mudanças.
3. O comando original Pest encontra duas funções globais repetidas. Uma configuração em `/private/tmp/easyeye-valuation-phpunit.xml` excluiu somente `Stock/MedicalRecordProceduresTest.php`, executado separadamente depois. Nenhum teste de origem foi editado e nenhuma falha foi ocultada como skip.
4. A primeira execução Feature parou por limite PHP de 128 MB. A execução completa usou `php -d memory_limit=512M`. Não se inferiu consumo de uma requisição de produção a partir da memória acumulada da suíte.
5. Testes utilizaram `APP_ENV=testing`, banco `easyeye_test`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, `CACHE_STORE=array` e `AI_PROVIDER_RUNTIME=fake`. A configuração cacheada do aplicativo foi desabilitada apenas nesses comandos por caminho temporário inexistente.

## Classificação das 17 falhas funcionais

| Grupo | Casos | O que a execução mostrou | Interpretação |
|---|---:|---|---|
| ACL de relatórios | 5 | `/panel/reports` retorna 404 em vez de 200/403 | A rota-hub foi removida; `routes/web.php` registra migração para relatórios dentro da agenda. Testes ficaram para trás. Não prova falha de autorização nas rotas novas |
| Estoque e feature de plano, incluindo dashboard | 9 | Esperava bloqueio/null, recebeu acesso/card | Gate existe no código. Os testes criam uma entidade que pode ganhar trial automático e depois criam outra assinatura. `FeatureGateService` usa primeira assinatura acessível sem ordenação. Há ambiguidade de setup/seleção a investigar; não afirmar que todo cliente sem módulo acessa estoque |
| Importação de exame/lateralidade | 1 | Esperava null, leu 2 | Teste cria três registros seguidos e escolhe `latest(created_at)` sem desempate. O service grava `data['laterality'] ?? null`. Ordenação empatada é hipótese forte de teste instável, não causa confirmada por reprodução isolada |
| Publicação de instalador | 1 | Nenhum registro novo encontrado | Fixture usa assinatura sintética de 64 bytes repetidos. Publisher passou a exigir verificação criptográfica real e chave pública configurada. Teste de sucesso não representa o contrato atual; não é evidência de que assinaturas válidas sejam recusadas |
| Busca de indicações | 1 | Violação de unicidade de subdomínio | Colisão de dado sintético de factory no banco de teste. Não foi encontrada evidência de corrupção de dado real |

O CSV `falhas-funcionais.csv` contém classe, nome e resumo sanitizado de cada falha. Os XMLs JUnit e logs completos ficaram em `/private/tmp`, fora dos anexos, para não transportar dados de fixtures e rastros desnecessários.

Não foram feitas correções: o pedido foi análise e precificação. Corrigir testes ou comportamento durante a medição mudaria o objeto avaliado.

## Achados de integração/entrega confirmados por leitura

- `app/Exports/PartnersReportExport.php` depende de `Maatwebsite\Excel\Concerns\WithMultipleSheets` e outras classes. O controller chama `Excel::download`. As classes não estão no autoload, e os pacotes não constam da declaração/lock Composer inspecionados.
- `integrator/src/auth/mod.rs` grava `access_token` em `PublicAppConfig` no SQLite antes de chamar o keyring. Mesmo quando o keyring funciona, a cópia local permanece. Este é um achado de desenho, sem acesso a um token real.
- `integrator/src/updater/mod.rs` contém chave pública all-zero. A verificação rejeita chave não configurada; o workflow de release tem bloqueio correspondente. Consulta/download/verificação existem, portanto chamar todo updater de stub seria incorreto.
- `integrator/src/scheduler/mod.rs` possui fallback `match_first_pending`. Quando não há referência identificável, ordem/data da agenda podem decidir o vínculo. Precisa de homologação ou confirmação de identidade apropriada ao uso.
- `config/tiss.php` usa mock por padrão e `require_schema_validation=false`. `storage/app/tiss/schemas` não tinha arquivos XSD na árvore local. Não foi lida configuração privada de produção; o ambiente remoto pode ser diferente.
- O worker Supervisor versionado escuta `default,high`; os serviços TISS despacham filas `tiss-xml`, `tiss-send` e `tiss-returns`. Precisa de configuração coerente ou de evidência de workers externos.

## Avisos dos builds

- Base Browserslist com aviso de desatualização.
- Build client reportou URLs de imagens/fontes que não resolveu no build e manteve para resolução em runtime. Não se afirmou que todos os assets quebram no navegador.
- Chunk vendor client de aproximadamente 875 kB antes de gzip (264 kB gzip). Não houve medição de tempo real de carregamento ou Core Web Vitals.

## Verificações não realizadas

- Cypress/E2E contra aplicação em execução: não rodado; existem 10 specs e suporte, inclusive geração dos manuais.
- Execução visual integral de todas as páginas e fluxos em vários dispositivos.
- Testes com equipamentos físicos e arquivos reais de todos os presets.
- Build/instalação/upgrade Windows e Linux nesta máquina macOS; status remoto do CI não consultado.
- Ensaios de carga, recuperação de backup, perda de disco e restauração total da plataforma.
- Testes de pagamento, envio de mensagens ou modelos de IA com serviços pagos reais.
- Auditoria jurídica de titularidade/licenças, certificação clínica, pentest completo ou auditoria de todas as dependências.

## Preservação do estado

Nenhum arquivo de implementação foi alterado. Os únicos documentos novos ficam em `docs/avaliacao-tecnica-2026-09-17/`. Alterações preexistentes foram preservadas. Os testes escreveram somente artefatos normais de teste/cache e dados sintéticos no banco dedicado; não foram feitas migrações na base operacional, deploy, commit ou publicação.
