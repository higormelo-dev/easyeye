/**
 * Fuso dos testes. As telas formatam datas com o fuso do navegador (Intl) e os testes comparam textos como
 * "28/10/2026" derivados de instantes ISO — escritos em Brasília (UTC-3). Num runner em UTC (GitHub Actions) o mesmo
 * instante cai um dia adiante ("29/10/2026, 02:59") e 4 testes de Billing/Revisor reprovavam só na CI. Fixar o fuso aqui
 * (processo principal, antes de subir os workers, que o herdam) deixa o resultado igual em qualquer máquina.
 */
export default function setup() {
    process.env.TZ = 'America/Sao_Paulo';
}
