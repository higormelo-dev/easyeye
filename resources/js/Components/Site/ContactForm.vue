<template>
    <div class="contact-form-box">
        <div v-if="sent" class="cf-success" role="status">
            <div class="cf-success-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false">
                    <circle class="cf-success-ring" cx="12" cy="12" r="9" pathLength="1" />
                    <path class="cf-success-check" d="m8 12 3 3 5-6" pathLength="1" />
                </svg>
            </div>
            <h3 ref="successHeading" tabindex="-1">{{ t.success_title }}</h3>
            <p>{{ t.success_body }}</p>
        </div>
        <template v-else>
            <h3 id="cf-title">{{ t.title }}</h3>
            <p>{{ t.subtitle }}</p>

            <form ref="formElement" aria-labelledby="cf-title" :aria-busy="sending"
                  @submit.prevent="submit" @invalid.capture="showNativeError" @input="clearFieldError" @change="clearFieldError">
                <p v-if="errorMessage" ref="errorSummary" class="cf-error-summary" role="alert" tabindex="-1">
                    {{ errorMessage }}
                </p>
                <fieldset class="cf-fields" :disabled="sending">
                    <div class="cf-row">
                        <div class="cf-group">
                            <label for="cf-name">{{ t.name }} *</label>
                            <input id="cf-name" v-model="form.name" name="name" type="text" class="cf-control"
                                   autocomplete="name" :placeholder="t.name_ph" maxlength="120" required v-bind="errorAttributes('name')">
                            <p v-if="errors.name" id="cf-name-error" class="cf-field-error">{{ errors.name }}</p>
                        </div>
                        <div class="cf-group">
                            <label for="cf-email">{{ t.email }} *</label>
                            <input id="cf-email" v-model="form.email" name="email" type="email" class="cf-control"
                                   autocomplete="email" :placeholder="t.email_ph" maxlength="191" required v-bind="errorAttributes('email')">
                            <p v-if="errors.email" id="cf-email-error" class="cf-field-error">{{ errors.email }}</p>
                        </div>
                    </div>

                    <div class="cf-group">
                        <label for="cf-phone">{{ t.phone }} *</label>
                        <input id="cf-phone" v-model="form.phone" v-mask="'phone'" name="phone" type="tel"
                               inputmode="tel" autocomplete="tel" class="cf-control" :placeholder="t.phone_ph"
                               maxlength="30" required v-bind="errorAttributes('phone')">
                        <p v-if="errors.phone" id="cf-phone-error" class="cf-field-error">{{ errors.phone }}</p>
                    </div>

                    <div class="cf-group">
                        <label for="cf-message">{{ t.message }} *</label>
                        <textarea id="cf-message" v-model="form.message" name="message" class="cf-control cf-message"
                                  :placeholder="t.message_ph" rows="5" maxlength="5000" required v-bind="errorAttributes('message', 'cf-message-hint')"></textarea>
                        <p id="cf-message-hint" class="cf-hint">{{ t.message_hint }}</p>
                        <p v-if="errors.message" id="cf-message-error" class="cf-field-error">{{ errors.message }}</p>
                    </div>

                    <details ref="optionalDetails" class="cf-optional">
                        <summary class="cf-optional-title" aria-describedby="cf-optional-hint">{{ t.details_title }}</summary>
                        <p id="cf-optional-hint" class="cf-optional-hint">{{ t.details_hint }}</p>
                        <div class="cf-optional-fields">
                            <div class="cf-row">
                                <div v-for="field in ['is_client', 'role']" :key="field" class="cf-group">
                                    <label :for="`cf-${field}`">{{ t[field] }} <span class="cf-optional-label">({{ t.optional }})</span></label>
                                    <select :id="`cf-${field}`" v-model="form[field]" :name="field" class="cf-control" v-bind="errorAttributes(field)">
                                        <option value="">{{ t.select }}</option>
                                        <option v-for="option in t[`${field}_opts`]" :key="option" :value="option">{{ option }}</option>
                                    </select>
                                    <p v-if="errors[field]" :id="`cf-${field}-error`" class="cf-field-error">{{ errors[field] }}</p>
                                </div>
                            </div>
                            <div class="cf-group">
                                <label for="cf-segment">{{ t.segment }} <span class="cf-optional-label">({{ t.optional }})</span></label>
                                <select id="cf-segment" v-model="form.segment" name="segment" class="cf-control" v-bind="errorAttributes('segment')">
                                    <option value="">{{ t.select }}</option>
                                    <option v-for="option in t.segment_opts" :key="option" :value="option">{{ option }}</option>
                                </select>
                                <p v-if="errors.segment" id="cf-segment-error" class="cf-field-error">{{ errors.segment }}</p>
                            </div>
                        </div>
                    </details>

                    <div class="cf-check">
                        <input id="cf-terms" v-model="form.terms" name="terms" type="checkbox" required v-bind="errorAttributes('terms')">
                        <div>
                            <label for="cf-terms" v-html="t.terms"></label>
                            <p v-if="errors.terms" id="cf-terms-error" class="cf-field-error">{{ errors.terms }}</p>
                        </div>
                    </div>

                    <button type="submit" class="cf-submit" :disabled="sending">
                        <i :class="'ti ' + (sending ? 'ti-loader-2 cf-spin' : 'ti-send')" aria-hidden="true"></i>
                        <span>{{ sending ? t.sending : t.submit }}</span>
                    </button>
                </fieldset>
            </form>
        </template>
    </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    t: { type: Object, required: true },
    action: { type: String, required: true },
});

const form = ref({ name: '', email: '', phone: '', is_client: '', role: '', segment: '', message: '', terms: false });
const errors = ref({});
const errorMessage = ref('');
const sending = ref(false);
const sent = ref(false);
const formElement = ref(null);
const errorSummary = ref(null);
const successHeading = ref(null);
const optionalDetails = ref(null);
let requestController;

function errorAttributes(field, hint) {
    return {
        'aria-invalid': errors.value[field] ? 'true' : undefined,
        'aria-describedby': [hint, errors.value[field] ? `cf-${field}-error` : null].filter(Boolean).join(' ') || undefined,
    };
}

function clearFieldError(event) {
    delete errors.value[event.target.name];
}

function showNativeError(event) {
    const { name, validity } = event.target;
    const key = validity.valueMissing ? (name === 'terms' ? 'terms' : 'required') : validity.typeMismatch ? 'email' : 'invalid';
    errors.value[name] = props.t.errors[key];
}

async function submit() {
    if (sending.value || sent.value || !formElement.value.reportValidity()) return;

    errors.value = {};
    errorMessage.value = '';
    sending.value = true;
    requestController = new AbortController();

    try {
        const response = await axios.post(props.action, { ...form.value }, {
            headers: { Accept: 'application/json' },
            timeout: 30000,
            signal: requestController.signal,
        });
        // A redirect or an HTML error page may also return HTTP 200.
        if (response.data?.ok !== true) throw new Error('Unexpected contact response');
        sent.value = true;
        await nextTick();
        successHeading.value?.focus();
    } catch (error) {
        if (error.code === 'ERR_CANCELED') return;

        const status = error.response?.status;
        let key = 'server';
        if (status === 422) {
            key = 'validation';
            const fieldErrors = error.response?.data?.errors;
            for (const field of Object.keys(form.value)) {
                const messages = fieldErrors?.[field];
                if (Array.isArray(messages) && typeof messages[0] === 'string') errors.value[field] = messages[0];
            }
            if (['is_client', 'role', 'segment'].some(field => errors.value[field])) {
                optionalDetails.value.open = true;
            }
        } else if (status === 419) {
            key = 'session';
        } else if (status === 429) {
            key = 'rate_limit';
        } else if (['ECONNABORTED', 'ETIMEDOUT'].includes(error.code)) {
            key = 'timeout';
        } else if (!error.response && error.request) {
            key = 'network';
        }
        errorMessage.value = props.t.errors[key];
    } finally {
        sending.value = false;
        requestController = undefined;
    }

    if (errorMessage.value) {
        await nextTick();
        const firstInvalid = formElement.value?.querySelector('[aria-invalid="true"]');
        (firstInvalid || errorSummary.value)?.focus();
    }
}

onBeforeUnmount(() => requestController?.abort());
</script>
