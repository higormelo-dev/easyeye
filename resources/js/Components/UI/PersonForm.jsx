import FormInput, { FormSelect, FormSwitch } from './FormInput';

export default function PersonForm({ form, errors, onChange, genders, maritalStatuses, statesOfBrazil, showEmail = true }) {
    return (
        <>
            {/* Dados Pessoais */}
            <h6 className="fw-semibold mb-3 text-primary">
                <i className="ti ti-user me-1"></i> Dados Pessoais
            </h6>
            <div className="row">
                <div className="col-md-8">
                    <FormInput label="Nome Completo" name="name" value={form.name} onChange={onChange} error={errors?.name} required />
                </div>
                <div className="col-md-4">
                    <FormInput label="Apelido" name="nickname" value={form.nickname} onChange={onChange} error={errors?.nickname} />
                </div>
            </div>
            <div className="row">
                <div className="col-md-4">
                    <FormInput label="CPF" name="national_registry" value={form.national_registry} onChange={onChange} error={errors?.national_registry} />
                </div>
                <div className="col-md-4">
                    <FormInput label="Data de Nascimento" name="birth_date" type="date" value={form.birth_date} onChange={onChange} error={errors?.birth_date} />
                </div>
                <div className="col-md-4">
                    <FormSelect label="Sexo" name="gender" value={form.gender} onChange={onChange} options={genders} error={errors?.gender} />
                </div>
            </div>
            <div className="row">
                <div className="col-md-4">
                    <FormSelect label="Estado Civil" name="marital_status" value={form.marital_status} onChange={onChange} options={maritalStatuses} error={errors?.marital_status} />
                </div>
                {showEmail && (
                    <div className="col-md-4">
                        <FormInput label="E-mail" name="email" type="email" value={form.email} onChange={onChange} error={errors?.email} required />
                    </div>
                )}
                <div className="col-md-4">
                    <FormInput label="Nome da Mãe" name="mother_name" value={form.mother_name} onChange={onChange} error={errors?.mother_name} />
                </div>
            </div>
            <div className="row">
                <div className="col-md-4">
                    <FormInput label="Nome do Pai" name="father_name" value={form.father_name} onChange={onChange} error={errors?.father_name} />
                </div>
            </div>

            {/* Documentos */}
            <h6 className="fw-semibold mb-3 mt-4 text-primary">
                <i className="ti ti-id me-1"></i> Documentos
            </h6>
            <div className="row">
                <div className="col-md-3">
                    <FormInput label="RG" name="state_registry" value={form.state_registry} onChange={onChange} error={errors?.state_registry} />
                </div>
                <div className="col-md-3">
                    <FormInput label="Órgão Emissor" name="state_registry_agency" value={form.state_registry_agency} onChange={onChange} />
                </div>
                <div className="col-md-3">
                    <FormSelect label="UF Emissor" name="state_registry_initial" value={form.state_registry_initial} onChange={onChange} options={statesOfBrazil} />
                </div>
                <div className="col-md-3">
                    <FormInput label="Data de Emissão" name="state_registry_date" type="date" value={form.state_registry_date} onChange={onChange} />
                </div>
            </div>

            {/* Contato */}
            <h6 className="fw-semibold mb-3 mt-4 text-primary">
                <i className="ti ti-phone me-1"></i> Contato
            </h6>
            <div className="row">
                <div className="col-md-4">
                    <FormInput label="Telefone Fixo" name="telephone" value={form.telephone} onChange={onChange} error={errors?.telephone} />
                </div>
                <div className="col-md-4">
                    <FormInput label="Celular" name="cellphone" value={form.cellphone} onChange={onChange} error={errors?.cellphone} />
                </div>
                <div className="col-md-4 d-flex align-items-end pb-3">
                    <FormSwitch label="WhatsApp" name="whatsapp" checked={form.whatsapp} onChange={onChange} />
                </div>
            </div>

            {/* Endereço */}
            <h6 className="fw-semibold mb-3 mt-4 text-primary">
                <i className="ti ti-map-pin me-1"></i> Endereço
            </h6>
            <div className="row">
                <div className="col-md-3">
                    <FormInput label="CEP" name="zipcode" value={form.zipcode} onChange={onChange} error={errors?.zipcode} />
                </div>
                <div className="col-md-6">
                    <FormInput label="Logradouro" name="address" value={form.address} onChange={onChange} error={errors?.address} />
                </div>
                <div className="col-md-3">
                    <FormInput label="Número" name="number" value={form.number} onChange={onChange} error={errors?.number} />
                </div>
            </div>
            <div className="row">
                <div className="col-md-3">
                    <FormInput label="Complemento" name="complement" value={form.complement} onChange={onChange} />
                </div>
                <div className="col-md-3">
                    <FormInput label="Bairro" name="district" value={form.district} onChange={onChange} />
                </div>
                <div className="col-md-3">
                    <FormInput label="Cidade" name="city" value={form.city} onChange={onChange} error={errors?.city} />
                </div>
                <div className="col-md-3">
                    <FormSelect label="Estado" name="state" value={form.state} onChange={onChange} options={statesOfBrazil} error={errors?.state} />
                </div>
            </div>
        </>
    );
}
