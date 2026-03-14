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
            company_cnpj: '',
            plan_id: plans.length ? plans[0].id : '',
        },

        get currentPlan() {
            return plans.find(p => p.id === this.selectedPlan) || null;
        },

        plans() {
            return plans;
        },

        selectPlan(planId) {
            this.selectedPlan = planId;
            this.form.plan_id = planId;
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

        async checkEmailAvailability() {
            if (!this.form.email || !this.form.email.includes('@')) return;
            this.emailChecking = true;
            this.emailAvailable = null;
            try {
                const res = await fetch(
                    `/register/check-email?email=${encodeURIComponent(this.form.email)}`,
                    { headers: { Accept: 'application/json' } }
                );
                const data = await res.json();
                this.emailAvailable = data.available;
                if (!data.available) {
                    this.errors.email = [window._trans?.email_taken || 'E-mail já cadastrado.'];
                } else {
                    delete this.errors.email;
                }
            } catch {
                this.emailAvailable = null;
            } finally {
                this.emailChecking = false;
            }
        },

        validateStep1() {
            const t    = window._trans || {};
            const req  = t.field_required     || 'Campo obrigatório.';
            const errs = {};
            if (!this.form.name.trim())  errs.name = [req];
            if (!this.form.email.trim()) errs.email = [req];
            else if (this.emailAvailable === false) errs.email = [t.email_taken || 'E-mail já cadastrado.'];
            if (!this.form.password)     errs.password = [req];
            if (this.form.password !== this.form.password_confirmation) {
                errs.password_confirmation = [t.passwords_mismatch || 'As senhas não conferem.'];
            }
            this.errors = errs;
            return Object.keys(errs).length === 0;
        },

        nextStep() {
            if (this.validateStep1()) {
                this.step = 2;
            }
        },

        prevStep() {
            this.step = 1;
            this.errors = {};
        },

        firstError(field) {
            return this.errors[field] ? this.errors[field][0] : null;
        },

        async submit() {
            this.errors = {};
            this.loading = true;
            try {
                const res = await fetch('/register', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(this.form),
                });

                const data = await res.json();

                if (!res.ok) {
                    this.errors = data.errors || {};
                    const step1Fields = ['name', 'email', 'password', 'password_confirmation'];
                    if (step1Fields.some(f => this.errors[f])) {
                        this.step = 1;
                    }
                    return;
                }

                window.location.href = data.redirect;
            } catch {
                // network error – keep loading = false
            } finally {
                this.loading = false;
            }
        },
    };
}
