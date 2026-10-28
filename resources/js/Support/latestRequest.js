/**
 * Só a resposta da ÚLTIMA requisição vale (2ª revisão, P1-B).
 *
 * Telas que carregam dados de UM paciente por vez (gaveta do paciente,
 * agendamento, prontuário, segurança clínica, prévia de modelo…): a resposta
 * atrasada do paciente A, chegando depois de abrir o B, não pode mais
 * sobrescrever a tela do B. Cada carga chama `begin()` — que invalida (e
 * aborta, quando dá) a anterior — e só grava o resultado se `current()`;
 * fechar a tela chama `cancel()`.
 *
 *   const detail = latestRequest();
 *   const req = detail.begin();
 *   const res = await fetch(url, { signal: req.signal });
 *   if (!req.current()) return;      // chegou tarde: descarta
 */
export function latestRequest() {
    let seq = 0;
    let controller = null;

    function cancel() {
        seq += 1;
        controller?.abort();
        controller = null;
    }

    function begin() {
        cancel();
        controller = typeof AbortController === 'undefined' ? null : new AbortController();
        const mine = seq;
        return { signal: controller?.signal, current: () => mine === seq };
    }

    return { begin, cancel };
}

/** Erro de requisição abortada (fetch ou axios) — não é falha para o usuário. */
export function isAbortError(error) {
    return error?.name === 'AbortError' || error?.name === 'CanceledError' || error?.code === 'ERR_CANCELED';
}
