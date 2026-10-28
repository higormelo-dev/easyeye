# Módulo cirúrgico (Fase 4) — entrada em produção

Decisões e contratos: `docs/internal/auditorias/adr/0003-modulo-cirurgico.md`. Este roteiro é a ordem de entrada e o que conferir em cada passo. O módulo é recurso de plano (`has_surgery`): enquanto nenhuma clínica tiver o recurso, nada muda para ninguém.

## 1. Antes do deploy
1. Backup do banco (as migrations só criam tabelas/colunas, mas mexem em `stock_movements`, `entity_products`, `schedules` e `tiss_*`).
2. Migrations novas, em ordem (`php artisan migrate`): `2026_10_25_*` (núcleo + backfill do plano), `2026_10_26_*` (checklist), `2026_10_27_*` (modelos e avisos), `2026_10_28_*` (TISS), `2026_10_29_*` (série/estorno de estoque + cirurgia), `2026_10_30_*` (pós-operatório).
3. Backfills que rodam sozinhos: `has_surgery` em todo plano existente (Premium = ligado, demais = desligado) e `requires_serial`/`requires_lot` das lentes IOL já cadastradas (só marca lote quando o saldo já é o dos lotes; nunca inventa lote).
4. **Mudança de comportamento no estoque (todas as clínicas):** consumo de lote vencido passa a ser recusado (inclusive na baixa de procedimento do prontuário). Avisar quem usa o módulo de estoque.

## 2. Configuração
1. Agendador: `surgery:expire-authorizations` (diário, 00:25) já está em `routes/console.php`; conferir que o `schedule:run` do servidor está ativo.
2. Fila: o aviso por WhatsApp e os retornos pós-operatórios usam a fila padrão; nenhum worker novo.
3. WhatsApp: o aviso de autorização usa o template `easyeye_cirurgia_aviso` (chave `surgery_authorization_notice` em `config/whatsapp.php`, texto em `docs/internal/integracoes/whatsapp-gupshup.md`). **Precisa estar aprovado na Meta antes do envio real.** O aviso vem desligado de fábrica por clínica (Configurações > Modelos cirúrgicos).
4. Armazenamento: a cópia assinada do termo grava no disco `local` (`surgery/{entity}/{caso}/…`); conferir o backup desse diretório.
5. Plano: ligar `has_surgery` nos planos que devem ter o módulo (Manager > Planos). Assinatura cortesia herda do plano.

## 3. Primeiro uso numa clínica
1. Configurações > Clínica > Locais e salas cirúrgicas: cadastrar a clínica própria e/ou hospitais e salas (ligar a sala própria à sala da Agenda quando houver escala/bloqueios).
2. Configurações > Documentos > Modelos cirúrgicos: "Adotar modelos padrão" (28 modelos pt/en). **O responsável médico deve revisar os textos antes de usar** (TCLE e orientações são modelos, não aconselhamento jurídico).
3. Cirurgiões precisam de cadastro de médico ativo na clínica (as telas clínicas avisam quando falta).
4. Produtos de lente IOL: lote e série passam a ser obrigatórios no registro do implante; unidade consignada (hospital/fornecedor) registra-se como "externa", sem baixa de saldo.

## 4. Conferência após o deploy (checklist)
- [ ] Clínica sem `has_surgery`: menu "Cirurgias" não aparece e as rotas devolvem 403.
- [ ] Clínica com `has_surgery`: criar caso → enviar para autorização → registrar resposta → agendar (sala/horário) → fechar a Pausa Cirúrgica → emitir TCLE e anexar cópia → registrar implante (lote/série/validade) → registrar cirurgia realizada → retornos D1/D7/D30 aparecem na Agenda do cirurgião → avaliação D1 finalizada + descrição finalizada → alta.
- [ ] Conflito de sala/cirurgião devolve 409 com a lista; consulta no horário da cirurgia é recusada.
- [ ] Estoque: consumo de lote vencido recusado; estorno único; saldo bate com o razão.
- [ ] Exportação LGPD do paciente traz a seção `surgery` (versão do formato 5).
- [ ] Indicadores abrem para admin/financeiro/médico (o médico só vê os próprios).
- [ ] `php artisan surgery:expire-authorizations` roda sem erro.

## 5. Reversão
1. Desligar `has_surgery` nos planos esconde o módulo sem perder dado.
2. As tabelas novas não são lidas por nenhum fluxo antigo; as colunas novas em `stock_movements` (`reversal_of_id`), `entity_products` (`requires_serial`), `schedules` (`surgical_case_id`) e `tiss_*` são nulas/opcionais.
3. `down()` das migrations existe, mas **apaga dado clínico** (casos, documentos, implantes): só em ambiente de teste.

## 6. Limites assumidos (não são gaps escondidos)
- Assinatura ICP-Brasil (Fase 3) não existe: documentos usam hash interno de conteúdo; o termo assinado pelo paciente entra como cópia digitalizada.
- O sistema não calcula a potência da LIO: registra modelo, potência, fórmula e refração prevista informados pelo cirurgião.
- Guias de cobrança do hospital (resumo de internação, honorários) ficam com o hospital; o EasyEye emite a solicitação de internação e o anexo de OPME.
- Repasse do cirurgião por cirurgia não faz parte desta fase.
- Importação CSV de produtos não conhece `requires_serial`; consumo de implante não entra no relatório "consumo por procedimento".
