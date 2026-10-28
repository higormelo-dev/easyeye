<!--
Formato deste arquivo (contrato validado por tests/Unit/ChangelogTest.php):
- Cada versão abre com um título de nível 2: "## 1.3.0 — 18/10/2026" (o "v" antes do número é opcional).
- Versões mais novas ficam no TOPO: a PRIMEIRA ocorrência é a versão atual. Ao publicar uma versão nova
  o badge "novo" do menu volta a aparecer para todos os usuários.
- Este arquivo (pt_BR) é a fonte da verdade da versão; en.md é a tradução e precisa ter a mesma versão no topo.
- Só Markdown simples: títulos, parágrafos, listas, negrito/itálico e links http(s)/mailto. HTML cru é descartado.
-->

## 1.3.0 — 18/10/2026

Gestão comercial: contratos de convênio, bandeira do cartão, suporte à mão e esta página.

- **Contrato com vigência nos convênios.** Em Configurações → Convênios, cada convênio da clínica ganha início e fim do contrato (opcionais). O painel inicial de administradores e do financeiro avisa os contratos vencidos e os que vencem nos próximos 30 dias. É só um aviso: não bloqueia atendimento nem faturamento TISS.
- **Bandeira do cartão no caixa.** Ao lançar um pagamento em crédito, crédito + dinheiro ou débito + dinheiro no Fluxo de caixa, você pode informar a bandeira (Visa, Mastercard, Elo, American Express, Hipercard, Diners Club ou Outros). Ela aparece na tabela do caixa e no relatório. As exportações CSV e Excel do fluxo de caixa agora também trazem as colunas **Forma de pagamento** e **Bandeira**, no fim.
- **Suporte em um clique.** Um ícone no topo de todas as telas do painel abre o WhatsApp oficial do suporte com a mensagem já preenchida (ou o e-mail, quando o WhatsApp não estiver configurado).
- **Novidades.** Esta página reúne o que mudou em cada versão. O item **Novidades** do menu mostra a marca "novo" até você abri-la e volta a mostrá-la quando sai uma versão nova.

## 1.2.0 — 18/10/2026

Nota fiscal da assinatura.

- **NFS-e da sua assinatura.** A EasyEye passa a emitir a nota fiscal de serviço eletrônica (NFS-e) da assinatura da clínica. Em **Minha assinatura**, administradores, financeiro e responsáveis baixam o XML da nota e, se ela for cancelada, o XML do cancelamento. Cada clínica só enxerga as próprias notas.

## 1.1.0 — 16/10/2026

Laudos de exames e pagamento no cadastro.

- **Ditado por voz nos laudos.** O texto do laudo pode ser ditado e transcrito pelo servidor. O recurso vem desligado e é habilitado pela EasyEye; cada ditado fica registrado na trilha de auditoria.
- **Impressão de vários laudos por folha.** Escolha quantos laudos de exame cabem em cada folha impressa.
- **Devolução do laudo ao técnico.** O médico pode devolver um laudo ao técnico, informando o motivo, e o técnico é avisado.
- **Cadastro com CPF ou CNPJ do responsável.** O "Contratar agora" pede o documento e o aceite dos termos e da política de privacidade, e o pagamento pode ser feito no ato por Pix, boleto ou cartão.
