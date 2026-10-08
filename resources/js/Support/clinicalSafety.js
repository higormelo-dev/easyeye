/**
 * Segurança clínica (Fase 2 / A1-A4) — utilitários da TELA.
 *
 * A checagem de alergia do receituário espelha a do servidor
 * (App\Support\ClinicalSafety\AllergyConflictChecker): mesma normalização
 * (minúsculo, sem acento, sem as palavras ignoradas que o servidor manda em
 * `check.stopwords`) e comparação por sequência de palavras inteiras. Os
 * termos de cada alergia (substância + membros da classe) já chegam prontos
 * do servidor — aqui só se compara. O servidor refaz tudo ao emitir (422).
 */
import { usePage } from '@inertiajs/vue3';

/** Rótulos `t_clinical` (lang/<locale>/clinical_safety.php) — prop explícita vence. */
export function clinicalTexts(explicit = null) {
    if (explicit && Object.keys(explicit).length) return explicit;
    try {
        return usePage()?.props?.t_clinical ?? {};
    } catch {
        return {};
    }
}

/** Lê `a.b.c` de um objeto de textos, com fallback. */
export function tc(texts, path, fallback = '') {
    const value = String(path)
        .split('.')
        .reduce((acc, key) => (acc && typeof acc === 'object' ? acc[key] : undefined), texts);
    return typeof value === 'string' ? value : fallback;
}

/**
 * Cadastro rápido de paciente (lista de espera e pronto atendimento — G2):
 * manda só o que foi preenchido — alergias (texto) OU "nega alergias"; nada
 * = "sem registro de alergias". O servidor recusa os dois juntos.
 */
export function quickAllergyPayload(allergies, denied) {
    const text = String(allergies ?? '').trim();
    if (text) return { allergies: text };
    return denied ? { allergies_denied: true } : {};
}

/** Substitui `:name` pelos valores. */
export function fill(text, replacements = {}) {
    return Object.entries(replacements).reduce((acc, [key, value]) => acc.replaceAll(`:${key}`, value ?? ''), text);
}

export function plain(text) {
    return String(text ?? '')
        .replace(/<[^>]*>/g, ' ')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

export function core(text, stopwords = []) {
    const stop = new Set(stopwords);
    return plain(text)
        .split(' ')
        .filter((token) => token && !stop.has(token))
        .join(' ');
}

export function containsPhrase(haystackCore, needleCore) {
    if (!haystackCore || !needleCore) return false;
    return ` ${haystackCore} `.includes(` ${needleCore} `);
}

/** Termos de um texto de substância ("a + b; c") — como TermNormalizer::terms. */
export function termsOf(text, stopwords = []) {
    return String(text ?? '')
        .split(/[+;,/\n]|\s-\s/)
        .map((part) => core(part, stopwords))
        .filter((term) => term.length >= 3);
}

/**
 * Mantém só os itens (selecionados no catálogo ou digitados soltos) cujo
 * NOME/marca ou princípio ativo ainda aparece no texto final da receita —
 * espelha reconcileTemplateItems() do servidor
 * (MedicalRecordDocumentationsController). Médico pode ter editado o texto
 * depois de aplicar o modelo/escolher o item (tirou ou trocou um remédio);
 * sem isto, o item removido continuava pedindo motivo de alergia na tela E
 * sendo enviado pro servidor, que recusava de novo (14ª revisão Codex, P2).
 */
export function reconcileItems(items, content, stopwords = []) {
    if (!items?.length) return [];
    const textCore = core(content, stopwords);

    return items.filter((item) => {
        const name = String(item?.name ?? '').replace(/\s*\d.*$/, '');
        const terms = [...new Set([...termsOf(name, stopwords), ...termsOf(item?.active_ingredient, stopwords)])];

        if (!terms.length) return true;

        return terms.some((term) => containsPhrase(textCore, term));
    });
}

function conflict(allergy, match, matched, klass = null) {
    return {
        allergy_id: allergy.id,
        substance: allergy.substance,
        reaction: allergy.reaction,
        severity: allergy.severity,
        severity_label: allergy.severity_label,
        status_label: allergy.status_label,
        match,
        matched,
        class: klass,
    };
}

const EYE_DROP = 'colirio';

/**
 * Conflitos de UM item do receituário (catálogo ou nome livre) com as
 * alergias ativas — princípio ativo/nome, classe e colírio (conservante).
 */
export function itemConflicts(item, check) {
    if (!item || !check?.allergies?.length) return [];
    const stop = check.stopwords ?? [];
    const label = [item.name, item.concentration].filter(Boolean).join(' ') || item.active_ingredient || '';
    const parts = [...new Set([...termsOf(item.active_ingredient, stop), ...termsOf(item.name, stop)])];
    const isEyeDrop =
        Boolean(item.is_eye_drop) ||
        containsPhrase(core(`${item.name ?? ''} ${item.presentation ?? ''}`, stop), EYE_DROP);
    const out = [];

    for (const allergy of check.allergies) {
        const byTerm = parts.some((part) =>
            (allergy.terms ?? []).some(
                (term) => containsPhrase(part, term) || (part.length >= 4 && containsPhrase(term, part)),
            ),
        );
        if (byTerm) {
            out.push(conflict(allergy, 'ingredient', label));
            continue;
        }
        const byClass = parts.some((part) =>
            (allergy.class_members ?? []).some((member) => containsPhrase(part, member)),
        );
        if (byClass) {
            out.push(conflict(allergy, 'class', label, (allergy.class_labels ?? []).join('; ')));
            continue;
        }
        if (allergy.eye_drops && isEyeDrop)
            out.push(conflict(allergy, 'eye_drops', label, (allergy.class_labels ?? []).join('; ')));
    }

    return out;
}

/** Conflitos no TEXTO livre da receita (substância, membro da classe, colírio). */
export function textConflicts(text, check) {
    if (!check?.allergies?.length) return [];
    const stop = check.stopwords ?? [];
    const textCore = core(text, stop);
    if (!textCore) return [];
    const out = [];

    for (const allergy of check.allergies) {
        for (const term of allergy.terms ?? []) {
            if (containsPhrase(textCore, term)) out.push(conflict(allergy, 'text', term));
        }
        for (const member of allergy.class_members ?? []) {
            if (containsPhrase(textCore, member))
                out.push(conflict(allergy, 'class', member, (allergy.class_labels ?? []).join('; ')));
        }
        if (allergy.eye_drops && containsPhrase(textCore, EYE_DROP))
            out.push(conflict(allergy, 'eye_drops', 'colírio', (allergy.class_labels ?? []).join('; ')));
    }

    return out;
}

const MATCH_FALLBACK = {
    ingredient: 'mesmo princípio ativo',
    class: 'mesma classe',
    text: 'citado no texto da receita',
    brand: 'nome comercial no texto',
    eye_drops: 'colírio (pode conter o conservante)',
};

/** Rótulo de "por que bateu" no idioma da tela. */
export function matchLabel(match, texts) {
    return tc(texts, `prescription.match_${match}`, MATCH_FALLBACK[match] ?? match);
}

/** CSRF do layout. */
export function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/** fetch JSON que devolve { ok, status, data }. `signal` (AbortController) opcional. */
export async function requestJson(url, { method = 'GET', body = null, signal = undefined } = {}) {
    const res = await fetch(url, {
        method,
        ...(signal ? { signal } : {}),
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: body === null ? undefined : JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data };
}

/** Primeira mensagem de erro de uma resposta 422/403. */
export function firstError(data, fallback = '') {
    const errors = data?.errors ?? {};
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
    return data?.message || fallback;
}

/**
 * Linhas do histórico da consulta (modal/drawer): retrato das alergias e
 * medicações no momento da consulta + uso de lente de contato (rótulos do
 * servidor). Prontuário anterior ao retrato: nada.
 *
 * @returns {Array<[string, string]>}
 */
export function recordSafetyRows(detail, texts) {
    const rows = [];
    const allergies = detail?.allergies_snapshot;

    if (allergies) {
        let value;
        if (allergies.state === 'has') {
            value = (allergies.items ?? [])
                .map((a) => {
                    const extra = [a.reaction, a.severity_label].filter(Boolean).join('; ');
                    return extra ? `${a.substance} (${extra})` : a.substance;
                })
                .join(' · ');
        } else if (allergies.state === 'denied') {
            value = tc(texts, 'banner.denied', 'Nega alergias');
        } else {
            value = tc(texts, 'banner.no_record', 'Sem registro de alergias');
        }
        rows.push([tc(texts, 'snapshot.allergies', 'Alergias (no momento da consulta)'), value]);
    }

    if (Array.isArray(detail?.medications_snapshot)) {
        rows.push([
            tc(texts, 'snapshot.medications', 'Medicações em uso (no momento da consulta)'),
            detail.medications_snapshot.length
                ? detail.medications_snapshot
                      .map((m) => (m.posology ? `${m.name} — ${m.posology}` : m.name))
                      .join(' · ')
                : tc(texts, 'snapshot.none_medications', 'Nenhuma na lista estruturada.'),
        ]);
    }

    for (const row of detail?.contact_lens_use_rows ?? []) rows.push([row.label, row.value]);

    return rows;
}
