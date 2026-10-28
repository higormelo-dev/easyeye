# Evidências da auditoria

Este arquivo registra resultados observados, não os critérios futuros de aprovação. Os itens do checklist permanecem abertos.

| Verificação | Resultado observado |
|---|---|
| `composer validate --no-check-publish` | Passou |
| `composer check-platform-reqs --no-dev` | Passou no PHP 8.4.26; não comprova suporte a 8.2 |
| `npm run build` | Passou: cliente e SSR |
| `npm run test` | 2.503 passaram; 1 falhou; 243 arquivos (242 passaram, 1 falhou) |
| Arquivo `RecordDocuments.test.js` com relógio controlado | 13 testes passaram; reprodução da causa de EE-11 |
| `npm run test`, após alteração detectada no teste | 2.506 passaram em 243 arquivos; 33,95s; ambiente local |
| Novo arquivo de documentos com `TZ=America/Los_Angeles` | 14 passaram e 1 falhou; EE-11 ainda depende do fuso |
| `npm run lint` | 219 erros e 202 avisos; saída em `eslint.txt` |
| `vendor/bin/pint --test` | 37 arquivos reprovados; lista/fixers em `pint.json`; sem aplicar formatação |
| `composer audit --format=json` | 44 registros de avisos em 14 pacotes, com duplicatas; `composer-audit.json` |
| `npm audit --json` | 52 entradas afetadas: 3 críticas, 33 altas, 16 moderadas; `npm-audit.json` |
| PHP Feature/Unit/Integration, execução controlada em banco exclusivo | 5.789 passaram, 1 ignorado, zero falhas/erros; 44.880 asserções; 944,23s; saída 0 |

O build apresentou avisos de referências a imagens legadas (`auth-bg.jpg`/`card-bg-02.png`). A existência e uso dessas imagens em runtime não foram confirmados, portanto não se abriu defeito funcional só com o aviso.

## Testes JavaScript

O teste que falhou é `tests/JavaScript/Panel/MedicalRecords/RecordDocuments.test.js:247`: esperava `08:15` em `cert-time-from`, recebeu vazio. A implementação preenche a chegada somente quando anterior à hora atual. O runner estava próximo de meia-noite.

A execução inicial de diagnóstico usou `vi.useFakeTimers({ toFake: ['Date'] })` e `vi.setSystemTime(new Date('2026-10-11T15:00:00Z'))`, equivalentes às 12h em São Paulo. Todos os 13 testes do arquivo passaram. Esse ensaio usou setup/config temporários em `/tmp` e não alterou testes do projeto.

Depois, foi detectada uma alteração no arquivo fora das ações desta auditoria: relógio congelado em 15h UTC, restauração ao final e dois testes novos (chegada futura/ausente). A nova suíte local passou com 2.506 testes. O arquivo isolado no fuso de Los Angeles falhou no mesmo preenchimento (`RecordDocuments.test.js:259`): 14 passaram, 1 falhou. O helper `currentTime` usa `getHours` local, e 15h UTC corresponde a 08h nesse fuso nessa data. Portanto, EE-11 permanece aberto para concluir o controle de fuso; não é um novo item.

Comando do cenário de fuso:

```sh
rtk proxy env TZ=America/Los_Angeles node node_modules/vitest/vitest.mjs run tests/JavaScript/Panel/MedicalRecords/RecordDocuments.test.js
```

## Testes PHP e isolamento

Primeira execução isolada:

```sh
rtk proxy env APP_ENV=testing DB_DATABASE=easyeye_audit_20261011 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 SENTRY_LARAVEL_DSN= SENTRY_DSN= php vendor/bin/pest tests/Feature tests/Unit tests/Integration --compact --log-junit /tmp/easyeye-audit-php-junit.xml
```

O banco foi criado para a auditoria. Não usar esse nome indiscriminadamente em uma execução futura compartilhada; reservar um banco exclusivo e confirmar `APP_ENV=testing` antes de migrações. Credenciais não foram gravadas no relatório.

Essa primeira execução isolada foi **interrompida**, com código 143, por espera ilimitada no helper com fork (EE-16). Havia um marcador de falha antes do travamento, mas não houve resumo/JUnit concluído para atribuí-lo com segurança a um teste. Não é uma execução aprovada nem base para inventar uma causa.

Repetição controlada, sem mudar o código do projeto:

```sh
rtk proxy env APP_ENV=testing DB_DATABASE=easyeye_audit_20261011 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 SENTRY_LARAVEL_DSN= SENTRY_DSN= MAGICK_THREAD_LIMIT=1 OMP_NUM_THREADS=1 php vendor/bin/pest tests/Feature tests/Unit tests/Integration --compact --log-junit /tmp/easyeye-audit-php-final-junit.xml
```

As variáveis limitam threads de imagem desde a inicialização do processo. A repetição permite avaliar a interação no contexto da suíte; uma sonda menor de Imagick/fork não reproduziu o bloqueio sozinha. Não se presume causa nativa exata apenas a partir de uma espera em futex.

**Resultado final da repetição:** 5.790 casos, sendo 5.789 aprovados e 1 ignorado, com 44.880 asserções, zero falhas/erros, saída 0 e duração reportada de 944,23s. Os 10 casos em `tests/Integration` completaram. O caso ignorado verifica a ausência de Imagick, extensão disponível neste ambiente. Uma verificação de processos confirmou o encerramento do processo de auditoria e de seus filhos.

Esse resultado é da execução com as duas variáveis de threads. Não significa que o helper da execução padrão tenha sido corrigido. Tampouco comprova os cenários de assinatura/auditoria/TISS das sondas, ausentes dessa cobertura específica e reproduzidos separadamente como defeitos. O marcador anterior à interrupção da primeira execução não reapareceu; não foi atribuída a ele uma causa sem evidência.

Tentativas descartadas: conexão PostgreSQL bloqueada no sandbox; concorrência de migrações/deadlock em `easyeye_test` compartilhado. Essas falhas ambientais não justificam modificar lógica clínica. Não rodar outra suíte migratória simultaneamente no banco reservado.

## Reproduções dirigidas

- **EE-01:** model de template não persistido com marcador `<script>`; renderização da view de prévia preservou o marcador. Confirmou HTML emitido sem sanitização, sem acessar prontuário real ou executar script no navegador.
- **EE-07:** chamada ao serviço de validação com `<not-valid-xml`, pasta XSD do repositório e `require_schema_validation=false`; retorno `valid=true`, `schema=null`, aviso de validação XSD ignorada. Não houve conexão com operadora.
- **EE-03/04/05/08:** sondas sintéticas em `easyeye_audit_probes_20261011`, separado da suíte, transação com rollback, relógio fixo, notificações falsas e bloqueio de requisições HTTP externas. Resultados confirmados em `sondas.json`: integridade falhou após reload; alteração de instância antiga persistiu mesmo com registro assinado; clínica do log divergiu da clínica do recurso; chave concluída foi sobrescrita como reservada.

Os scripts locais foram de diagnóstico. Para fechar os itens, portar os cenários para testes mantidos pelo projeto e, nos casos de concorrência/filas/navegador, executar a modalidade apropriada. Reflexão do método privado de reserva demonstra a perda de estado, mas não substitui o teste público de envio repetido.

## Arquivos de evidência

- `referencia.json`: HEAD, hash do manifesto e data de referência.
- `inventario-sha256.json`: 3.223 hashes dos arquivos de código/configuração pública selecionados, sem `.env` ou conteúdo de credenciais.
- `git-status.txt`: estado observado, incluindo mudanças anteriores à auditoria.
- `composer-audit.json` e `npm-audit.json`: resultados brutos da consulta de segurança; contagens não equivalem a ataques demonstrados.
- `pint.json` e `eslint.txt`: dívidas de ferramentas, sem correção automática.
- `resultados.json`: números finais das verificações e condições da execução.
- `variacoes-durante-auditoria.json`: hash anterior/observado do teste JS alterado fora das ações desta auditoria e resultados de local/fuso. A referência inicial foi preservada. A verificação final não detectou alteração nos arquivos PHP inventariados.

Os dois bancos exclusivos foram mantidos como artefatos locais de auditoria. Os prontuários/pacientes das sondas foram revertidos; não houve leitura ou modificação de dados clínicos reais. Sua eventual remoção não faz parte das correções funcionais do checklist.

Logs temporários originais ficam em `/tmp/easyeye-audit-*` nesta máquina. O relatório persistente guarda resultados e metadados necessários; não depende desses logs para os critérios de aceite.
