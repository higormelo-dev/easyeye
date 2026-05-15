export default function registerWizard(plansJson, trialDays) {
    const plans = typeof plansJson === 'string' ? JSON.parse(plansJson) : (plansJson || []);

    return {
        step: 1,
        loading: false,
        errors: {},
        emailChecking: false,
        emailAvailable: null,
        selectedPlan: plans.length ? plans[0].id : null,
        carouselIndex: 0,
        trialDays: trialDays || 7,

        form: {
            name: '',
            email: '',
            password: '',
            password_confirmation: '',
            company_name: '',
            company_phone: '',
            company_cnpj: '',
            plan_id: plans.length ? plans[0].id : '',
        },

        /* ─── Plano selecionado ─── */
        get currentPlan() {
            return plans.find(p => p.id === this.selectedPlan) || null;
        },

        /* ─── Força da senha ─── */
        get passwordStrength() {
            const p = this.form.password;
            if (!p) return 0;
            let score = 0;
            if (p.length >= 8)           score++;
            if (p.length >= 12)          score++;
            if (/[A-Z]/.test(p))         score++;
            if (/[0-9]/.test(p))         score++;
            if (/[^A-Za-z0-9]/.test(p))  score++;
            return score; // 0–5
        },

        get passwordStrengthLabel() {
            const l = window._trans?.strength_labels
                ?? ['', 'Muito fraca', 'Fraca', 'Razoável', 'Forte', 'Muito forte'];
            return l[this.passwordStrength] ?? '';
        },

        get passwordStrengthColor() {
            return ['', '#ef4444', '#f97316', '#eab308', '#22c55e', '#06d6a0'][this.passwordStrength] ?? '';
        },

        /* ─── Planos ─── */
        plans() { return plans; },

        selectPlan(planId) {
            this.selectedPlan = planId;
            this.form.plan_id = planId;
            const idx = plans.findIndex(p => p.id === planId);
            if (idx >= 0) this.carouselIndex = idx;
        },

        prevSlide() {
            if (this.carouselIndex > 0) {
                this.carouselIndex--;
                this.selectPlan(plans[this.carouselIndex].id);
            }
        },

        nextSlide() {
            if (this.carouselIndex < plans.length - 1) {
                this.carouselIndex++;
                this.selectPlan(plans[this.carouselIndex].id);
            }
        },

        goToSlide(index) {
            this.carouselIndex = index;
            this.selectPlan(plans[index].id);
        },

        quickStart() {
            this.goToSlide(0);
            this.submit();
        },

        /* ─── Verificação de e-mail ─── */
        async checkEmailAvailability() {
            if (!this.form.email || !this.form.email.includes('@')) return;
            this.emailChecking = true;
            this.emailAvailable = null;
            try {
                const res  = await fetch(
                    `/register/check-email?email=${encodeURIComponent(this.form.email)}`,
                    { headers: { Accept: 'application/json' } }
                );
                const data = await res.json();
                this.emailAvailable = data.available;
                if (!data.available) {
                    this.errors.email = [window._trans?.email_taken ?? 'E-mail já cadastrado.'];
                } else {
                    delete this.errors.email;
                }
            } catch {
                this.emailAvailable = null;
            } finally {
                this.emailChecking = false;
            }
        },

        /* ─── Validação etapa 1 ─── */
        validateStep1() {
            const t   = window._trans ?? {};
            const req = t.field_required ?? 'Campo obrigatório.';
            const errs = {};
            if (!this.form.name.trim())  errs.name = [req];
            if (!this.form.email.trim()) errs.email = [req];
            else if (this.emailAvailable === false) errs.email = [t.email_taken ?? 'E-mail já cadastrado.'];
            if (!this.form.password)     errs.password = [req];
            if (this.form.password !== this.form.password_confirmation) {
                errs.password_confirmation = [t.passwords_mismatch ?? 'As senhas não conferem.'];
            }
            this.errors = errs;
            return Object.keys(errs).length === 0;
        },

        nextStep() {
            if (!this.validateStep1()) return;
            this.step = 2;
            this.$nextTick(() => document.getElementById('reg-company-name')?.focus());
        },

        prevStep() {
            this.step = 1;
            this.errors = {};
        },

        firstError(field) {
            return this.errors[field]?.[0] ?? null;
        },

        /* ─── Submissão ─── */
        async submit() {
            this.errors = {};
            this.loading = true;
            try {
                const res  = await fetch('/register', {
                    method: 'POST',
                    headers: {
                        'Content-Type':  'application/json',
                        Accept:          'application/json',
                        'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(this.form),
                });
                const data = await res.json();

                if (!res.ok) {
                    this.errors = data.errors ?? {};
                    const step1Fields = ['name', 'email', 'password', 'password_confirmation'];
                    if (step1Fields.some(f => this.errors[f])) this.step = 1;
                    return;
                }
                window.location.href = data.redirect;
            } catch {
                // erro de rede — não altera loading para não bloquear UI
            } finally {
                this.loading = false;
            }
        },
    };
}
