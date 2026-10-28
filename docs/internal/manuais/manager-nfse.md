# Manager — Nota fiscal (NFS-e)

Para quem opera o painel do Manager (admin e financeiro do SaaS). A nota fiscal de serviço é emitida **pela dona do EasyEye** para cada fatura paga de uma clínica
(assinatura, troca de plano ou pacote de créditos de IA). Cortesia e teste grátis não geram nota. A clínica só baixa o PDF/XML em **Minha assinatura**.
Detalhes técnicos e de implantação: `docs/internal/runbooks/nfse-focusnfe.md`.

**Quem pode o quê.** Financeiro e admin: ver a página, emitir, reenviar e-mail, consultar na Focus, baixar XML. Só admin: configurar, trocar credenciais, registrar o gatilho,
gravar IBGE das clínicas, cancelar e marcar cancelamento na prefeitura.

## 1. Primeira configuração (admin)

1. **Credenciais** — escolha o ambiente (comece em **Homologação**), cole o *token da empresa* e, se for usar a busca de município, o *token master*, informe o motivo (mín. 20 caracteres) e salve.
   Os tokens nunca mais aparecem; para trocar, digite o novo. Use **Testar credenciais**: a tela diz se a Focus aceitou.
2. **Prestador** — CNPJ, inscrição municipal, código IBGE do município, CNAE, item da lista de serviços (ex.: `01.07`), código tributário municipal (se a prefeitura exigir), código de tributação nacional
   (6 dígitos, para o padrão nacional), alíquota do ISS (em %), ISS retido, natureza da operação, optante do Simples e regime especial.
3. **Padrão e ambiente** — *municipal* ou *nacional* (o que a empresa tem habilitado na Focus) e o ambiente. No nacional, informe série e próximo número da DPS.
4. **Textos da nota** — modelos de discriminação para assinatura e para pacote de IA, com `{plano}`, `{ciclo}`, `{periodo}`, `{fatura}`, `{creditos}`, `{cliente}`.
5. **Gatilho (webhook)** — **Registrar gatilho** (com motivo). A tela mostra a URL; ela precisa ser `https` pública. Depois disso a Focus avisa cada mudança de status. Sem gatilho a nota ainda fecha (consulta automática a cada 10 min), só demora mais.
6. **Validar município** — mostra o que a prefeitura exige (endereço do tomador, CNAE, cancelamento pelo sistema). Use **Atualizar** para consultar de novo.
7. **E-mail de cópia** (opcional) — recebe uma cópia de cada nota autorizada, além do e-mail da clínica.
8. **Emissão automática** — só depois de emitir e conferir uma nota manual em produção. Para ligar, o prestador precisa estar completo e o ambiente com token.

## 2. Clínicas sem código IBGE

O card lista as clínicas ativas sem código IBGE do município. Digite os 7 dígitos ou busque por nome/UF (precisa do token master); a Focus confirma o código antes de gravar e a tela avisa se a UF não bate com a da clínica.
Municípios que exigem endereço do tomador barram a nota até o cadastro da clínica (endereço, CEP, UF) e o IBGE estarem completos.

## 3. Emitir

- **Automática**: a nota nasce quando a fatura é paga (se a emissão automática estiver ligada).
- **Manual**: **Faturas pagas sem nota** (página) ou o bloco **NFS-e** no drawer da assinatura/pacote → **Emitir nota fiscal** (ou **Emitir**, na linha da nota). A guarda pode recusar com o motivo (fatura não paga/estornada, valor zero, documento da clínica inválido, prestador incompleto,
  token ausente, endereço/IBGE exigidos pelo município). Fatura que já tem nota a caminho ou valendo não emite outra.
- Depois de **cancelada**, a nota pode ser emitida de novo à mão (decisão humana); o automático e o lote nunca refazem uma nota cancelada.

A tabela **Notas** mostra o estado da nota, em ordem: enviando → processando → autorizada. Pode parar em *erro de autorização* (os motivos da prefeitura aparecem na linha, com a correção sugerida) ou em *falha no envio* (token, rede ou configuração); a nota cancelada fica marcada como tal.
Para tentar de novo depois de corrigir, use **Emitir** na linha — cria uma nota nova, com novo número de referência.

## 4. Ações sobre a nota

| Ação | Quem | Quando |
|---|---|---|
| Consultar agora | financeiro/admin | nota processando ou autorizada: pergunta à Focus e atualiza |
| Reenviar e-mail | financeiro/admin | só autorizada; até 10 endereços |
| Baixar XML / ver PDF / ver espelho | financeiro/admin | autorizada ou cancelada (XML do cancelamento quando houver) |
| Cancelar | admin | só autorizada; justificativa de 15 a 255 caracteres; **definitivo** |
| Marcar como cancelada na prefeitura | admin | quando o município não cancela pelo sistema; motivo de 20 a 255 caracteres |

Se o cancelamento pelo sistema der erro, a tela mostra o motivo da Focus e a nota continua autorizada. Município que não cancela pelo sistema: a tela orienta a cancelar no portal da prefeitura e depois **Marcar como cancelada na prefeitura**.

## 5. Estorno e chargeback

- **Estorno total ou chargeback** da fatura cancela a nota automaticamente. Se não for possível (município sem cancelamento, prazo, erro), a nota fica com a marca **Cancelamento pendente** — cancele na prefeitura e marque como cancelada, ou cancele pela tela.
- **Estorno parcial** não cancela a nota: o drawer da fatura mostra um aviso para você decidir (a nota segue válida pelo valor pago).
- Pagamento em duplicidade estornado não mexe na nota (a fatura continua paga pela outra cobrança).

## 6. O que a clínica vê

Em **Minha assinatura → faturas**: "NFS-e nº X" com **PDF** e **XML** (e o XML do cancelamento, se a nota foi cancelada pelo sistema). Notas pendentes, com erro ou em processamento não aparecem para ela.
Só admin, financeiro e dono da clínica acessam.

## 7. Problemas comuns

- *"A Focus recusou as credenciais"*: token errado ou de outro ambiente — reponha e use **Testar credenciais**.
- *"Complete os dados do prestador"*: a mensagem lista os campos.
- Nota presa em *Processando*: use **Consultar agora**; se continuar, veja se o gatilho está registrado e se a prefeitura está no ar.
- *Cancelamento pendente* depois de um estorno: ver §5.
