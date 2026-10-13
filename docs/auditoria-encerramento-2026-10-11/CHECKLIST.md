# Checklist finito da rodada de auditoria

Referência e causas: [RELATORIO.md](RELATORIO.md). Evidências: [VALIDACAO.md](evidencias/VALIDACAO.md). Os itens estão abertos: a auditoria identifica e valida problemas; não implementa as correções.

P1: segurança e integridade, corrigir antes de liberar os caminhos afetados. P2: funcionamento, operação e contrato de qualidade, concluir nos fluxos entregues. TISS real não pode ser habilitado antes de EE-07/08/09. Assinatura deve passar por EE-03/04 antes de EE-06.

| Estado | ID | Prioridade | Entrega delimitada | Evidência necessária para fechar |
|---|---|---|---|---|
| [ ] | EE-01 | P1 | Sanitizar e isolar prévia de modelos | HTML malicioso bloqueado; formatação/placeholders preservados; legado coberto |
| [ ] | EE-02 | P1 | Atualizar/mitigar e triar avisos de dependências | Inventário triado por aviso; nenhuma alta/crítica aplicável pendente; suítes/build verdes |
| [ ] | EE-03 | P1 | Tornar hash clínico estável e versionado | Assinar após intervalo e recarregar verifica; adulteração falha; legado coberto |
| [ ] | EE-04 | P1 | Proteger assinatura/edição contra concorrência | Instância antiga e duas conexões não alteram registro assinado; operação atômica |
| [ ] | EE-05 | P1 | Vincular auditoria à clínica do recurso | Sessão de outra clínica não contamina leitura/exportação/lotes do portal |
| [ ] | EE-06 | P2 | Completar ação de assinatura no fluxo web | Criar → assinar via rota → compartilhar → portal; autorização negativa coberta |
| [ ] | EE-07 | P2 | Resolver XSD e rejeitar XML malformado | Serviço real usa XSD distribuído; envio real falha fechado sem validação |
| [ ] | EE-08 | P2 | Preservar idempotência concluída no TISS | Repetição/concorrência não gera novo envio normal nem erro de unicidade |
| [ ] | EE-09 | P2 | Alinhar consumidores/timeout/retry/parada | Filas TISS consumidas; job longo sem duplicação; reinício seguro em worker real |
| [ ] | EE-10 | P2 | Limitar PDF e limpar temporários | Renderização normal preservada; timeout/exceção deixam zero temporário criado |
| [ ] | EE-11 | P2 | Fixar relógio/fuso dos testes clínicos (parcial) | Suíte local passou após alteração detectada; arquivo ainda falha em Los Angeles; fechar o contrato de fuso |
| [ ] | EE-12 | P2 | Alinhar PHP declarado/lock/ambientes | Instalação limpa e testes na versão mínima publicada |
| [ ] | EE-13 | P2 | Fixar e cumprir baseline de lint/formatação | Zero erro novo no alcance contratado; dívida residual explicitamente registrada |
| [ ] | EE-14 | P2 | Localizar portal/editor/documentos/telas indicadas | PT/EN e datas/instantes/moeda no escopo delimitado |
| [ ] | EE-15 | P2 | Completar acessibilidade do modal compartilhado | Foco/Tab/Escape/fundo/retorno e uso real em 320/768/1280 px |
| [ ] | EE-16 | P2 | Limitar espera e isolar testes de concorrência | Timeout/exit status/limpeza cobertos; suíte termina sem filhos órfãos |

Ao fechar cada linha, registrar commit, teste/comando e resultado. Marcar não aplicável exige motivo verificável e funcionalidade efetivamente não entregue/desabilitada; não basta ignorar a revisão.

O gate final também exige que falhas funcionais reproduzíveis da execução PHP válida sejam resolvidas, conforme VALIDACAO.md. Erros de conexão/deadlock das tentativas inválidas não entram como defeitos do produto.

Ordem sugerida: EE-16/11 para estabilizar diagnóstico; EE-01/02/05; EE-03/04/06; EE-07/08/09; EE-10/12/13; EE-14/15. Atualização de dependências pode produzir ajustes nesses mesmos itens; não abre automaticamente uma rodada de refatoração.
