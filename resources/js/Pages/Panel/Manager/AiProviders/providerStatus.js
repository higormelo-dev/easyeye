const warning = 'badge-soft-warning text-warning border border-warning';

/**
 * Situação de um provedor de IA na tela (tabela e drawer): a mais
 * importante primeiro — sem chave, sem modelo, sem preço, modelo
 * descontinuado pelo provedor, pronto.
 *
 * @param {Object} p card de providerCards() (servidor)
 * @param {Object} t traduções (manager_ai)
 */
export function providerStatus(p, t = {}) {
    if (!p.has_key) {
        return {
            code: 'no_key',
            label: t.status_no_key ?? 'Sem chave',
            hint: t.no_credential_hint ?? '',
            icon: 'ti-key-off',
            cls: 'badge-soft-secondary',
        };
    }

    if (!p.configured) {
        return {
            code: 'no_model',
            label: t.status_no_model ?? 'Sem modelo',
            hint: '',
            icon: 'ti-alert-triangle',
            cls: warning,
        };
    }

    if (!p.price_ok) {
        return {
            code: 'no_price',
            label: t.status_no_price ?? 'Sem preço',
            hint: t.price_missing_hint ?? '',
            icon: 'ti-coin-off',
            cls: warning,
        };
    }

    if (p.model_unlisted_at) {
        return {
            code: 'unlisted',
            label: t.status_unlisted ?? 'Modelo descontinuado',
            hint: (t.model_unlisted ?? '').replace(':date', p.model_unlisted_at),
            icon: 'ti-alert-triangle',
            cls: warning,
        };
    }

    return {
        code: 'ready',
        label: t.status_ready ?? 'Pronto',
        hint: t.configured ?? '',
        icon: 'ti-circle-check',
        cls: 'badge-soft-success text-success border border-success',
    };
}

const ROLES = {
    primary: { key: 'role_primary_short', fallback: 'Principal', icon: 'ti-bolt' },
    reviewer: { key: 'role_reviewer_short', fallback: 'Revisor', icon: 'ti-eye-check' },
    adjudicator: { key: 'role_adjudicator_short', fallback: 'Árbitro', icon: 'ti-scale' },
};

/** Papel do provedor no assistente (null quando não tem papel). */
export function providerRole(p, t = {}) {
    const role = ROLES[p.role];

    return role ? { label: t[role.key] ?? role.fallback, icon: role.icon } : null;
}

const success = 'badge-soft-success text-success border border-success';

/**
 * Proteção de dados (LGPD) do provedor em um selo: bloqueado para pacientes,
 * em uso sem o mecanismo de transferência registrado, registrado, exige
 * registro, processa no Brasil ou na UE (adequação). Null sem dados.
 *
 * @param {Object} p card de providerCards() (servidor) — usa p.lgpd
 * @param {Object} t traduções (manager_ai)
 */
export function lgpdBadge(p, t = {}) {
    const l = p?.lgpd;
    if (!l) return null;

    if (!l.patients) {
        return {
            code: 'blocked',
            label: t.lgpd_badge_blocked ?? 'Bloqueado p/ pacientes',
            hint: t[`blocked_reason_${l.blocked_reason}`] ?? '',
            icon: 'ti-shield-x',
            cls: 'badge-soft-danger text-danger border border-danger',
        };
    }

    if (l.pending) {
        return {
            code: 'pending',
            label: t.lgpd_badge_pending ?? 'Registro pendente',
            hint: t.transfer_contract ?? '',
            icon: 'ti-shield-exclamation',
            cls: warning,
        };
    }

    if (l.needs_record) {
        return l.record
            ? {
                  code: 'recorded',
                  label: t.lgpd_badge_recorded ?? 'Mecanismo registrado',
                  hint: t[`mechanism_${l.record.mechanism}`] ?? '',
                  icon: 'ti-shield-check',
                  cls: success,
              }
            : {
                  code: 'needs',
                  label: t.lgpd_badge_needs ?? 'Exige registro',
                  hint: t.transfer_contract ?? '',
                  icon: 'ti-file-certificate',
                  cls: 'badge-soft-secondary',
              };
    }

    return l.transfer === 'none'
        ? {
              code: 'br',
              label: t.lgpd_badge_br ?? 'No Brasil',
              hint: t.transfer_none ?? '',
              icon: 'ti-map-pin',
              cls: success,
          }
        : {
              code: 'eu',
              label: t.lgpd_badge_eu ?? 'UE (adequação)',
              hint: t.transfer_adequacy ?? '',
              icon: 'ti-shield-check',
              cls: success,
          };
}
