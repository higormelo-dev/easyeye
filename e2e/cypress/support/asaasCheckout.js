// Página do Asaas Checkout (sandbox.asaas.com/<conta>/checkoutSession/show/<id>) — usada só pelo spec opt-in de sandbox,
// SÓ PARA LER: o spec confere que a sessão abre com o valor e entrega a URL a um humano. Ele NÃO preenche nem paga.
//
// Por que o cartão é pago fora do Cypress (verificado em Chrome real, 2026-10-16):
//  - O "Pagar" é protegido por reCAPTCHA Enterprise, que PONTUA o navegador. A janela do Cypress carrega
//    `navigator.webdriver` e flags de automação (e, no modo túnel, o User-Agent 'EasyEye-E2E-Sandbox' que contorna o aviso do
//    ngrok): o POST /checkoutSession/pay volta `{"success":false,"message":{"text":"Tente novamente e verifique o
//    recaptcha."},"recaptchaV2Enabled":true}` e a página recarrega vazia — também com o clique de uma pessoa DENTRO do Cypress.
//  - Por isso o pagamento é feito no navegador comum de quem roda o spec (a URL sai no terminal) e o spec confirma o
//    resultado pelo webhook (polling do resumo), nunca pela página do Asaas.
//
// Pontos da página que não se veem no código do app:
//  - Web Components (Atlas) com SHADOW DOM: sem `includeShadowDom: true` o Cypress não encontra um único elemento
//    (cy.get devolve 0).
//  - A página nasce SEM os dados do pagador: o Checkout só aceita `customer`/`customerData` com endereço completo
//    (address, addressNumber, postalCode, province) e o cadastro da clínica não tem endereço, então o app abre o checkout
//    sem cliente. Quem paga digita e-mail, nome, CPF/CNPJ, celular e endereço (o CEP preenche rua/bairro/cidade) — acordeão
//    "Identificação" → "Dados de endereço" → "Dados de pagamento".
//  - O resumo mostra o valor ("Total R$ 99,90"), que é o que o spec afirma.

const FIELD_TIMEOUT = 30000;

/**
 * A sessão do Asaas Checkout abriu no navegador do Cypress: formulário de identificação visível e, se `amount` vier, o
 * valor da fatura no resumo do pedido. Só leitura — nenhum campo é tocado. Roda DENTRO do cy.origin: com a página no
 * Asaas, qualquer cy.get/cy.location fora dele reprova por origem.
 *
 * @param {string} checkoutUrl URL do checkout (https, domínio do Asaas)
 * @param {{ amount?: number }} [options] valor da fatura em reais (ex.: 99.9 → procura "R$ 99,90")
 */
export function assertAsaasCheckoutOpen(checkoutUrl, { amount } = {}) {
    // O callback do cy.origin é serializado para a outra origem: nada do escopo de fora entra nele, só `args`.
    cy.origin(
        new URL(checkoutUrl).origin,
        { args: { amount: amount ?? null, fieldTimeout: FIELD_TIMEOUT } },
        ({ amount, fieldTimeout }) => {
            const shadow = { includeShadowDom: true, timeout: fieldTimeout };

            cy.get('input[name="email"]', shadow).should('be.visible');

            if (amount !== null) {
                // "R$ 99,90" (o espaço pode ser NBSP): \s cobre os dois.
                cy.contains(new RegExp(`R\\$\\s*${Number(amount).toFixed(2).replace('.', ',')}`), shadow).should(
                    'exist',
                );
            }
        },
    );
}
