import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormInput, { FormSelect, FormSwitch } from '@/Components/UI/FormInput';
import PageHeader from '@/Components/UI/PageHeader';

export default function MedicalRecordsCreate({
    patient, doctors, visualAcuityTypes, colorVisionTypes, coverTestTypes,
    nearPointTypes, additionTypes, lenses
}) {
    const defaultForm = {
        doctor_id: '',
        main_complaint: '',
        visual_acuity_type_id: '',
        near_point_convergence_id: '',
        cover_test_type_id: '',
        visual_acuity_without_correction_right_id: '',
        visual_acuity_without_correction_left_id: '',
        visual_acuity_with_correction_right_id: '',
        visual_acuity_with_correction_left_id: '',
        addition_type_id: '',
        lens_away_id: '',
        lens_near_id: '',
        diabetic: false, diabetic_family: false,
        hypertensive: false, hypertensive_family: false,
        glaucomatous: false, glaucomatous_family: false,
        tonometer_right: '', tonometer_left: '', tonometer_time: '',
        dynamic_spherical_right: '0.00', dynamic_spherical_left: '0.00',
        dynamic_cylindrical_right: '0.00', dynamic_cylindrical_left: '0.00',
        dynamic_axis_right: '', dynamic_axis_left: '',
        static_spherical_right: '0.00', static_spherical_left: '0.00',
        static_cylindrical_right: '0.00', static_cylindrical_left: '0.00',
        static_axis_right: '', static_axis_left: '',
        biomicroscopy_right: '', biomicroscopy_left: '',
        fundoscopy_right: '', fundoscopy_left: '',
        observation_general: '', observation_of_lenses: '',
    };

    const { data, setData, post, processing, errors } = useForm(defaultForm);

    const onChange = (name, value) => setData(name, value);
    const onCheckboxChange = (e) => setData(e.target.name, e.target.checked);

    const handleSubmit = (e) => {
        e.preventDefault();
        post(`/panel/patients/${patient.id}/medicalrecords`);
    };

    const asOptions = (arr) => arr.map(a => ({ value: a.id, label: a.name }));
    const asDoctors = (arr) => arr.map(a => ({ value: a.id, label: a.person?.full_name || a.code }));

    const InputSet = ({ label, namePrefix, isOdOe = false }) => (
        <div className="mb-2">
            <span className="pmr-label">{label}</span>
            {isOdOe ? (
                <div className="d-flex align-items-center gap-1">
                    <span className="pmr-eye">OD</span>
                    <input type="text" className={`form-control form-control-sm ${errors[`${namePrefix}_right`] ? 'is-invalid' : ''}`} value={data[`${namePrefix}_right`]} onChange={(e) => onChange(`${namePrefix}_right`, e.target.value)} placeholder="—" />
                    <span className="pmr-eye ms-2">OE</span>
                    <input type="text" className={`form-control form-control-sm ${errors[`${namePrefix}_left`] ? 'is-invalid' : ''}`} value={data[`${namePrefix}_left`]} onChange={(e) => onChange(`${namePrefix}_left`, e.target.value)} placeholder="—" />
                </div>
            ) : null}
        </div>
    );

    return (
        <AuthenticatedLayout title="Novo Prontuário">
            <style>{`
            .pmr-label { color: #26a69a; font-size: .78rem; font-style: italic; font-weight: 500; display: block; margin-bottom: 3px; }
            .pmr-eye { font-size: .78rem; font-weight: 600; color: #6c757d; min-width: 22px; flex-shrink: 0; }
            .pmr-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
            .pmr-table th { background: #f8f9fa; border: 1px solid #dee2e6; padding: 4px 8px; text-align: center; font-weight: 500; font-size: .78rem; }
            .pmr-table td { border: 1px solid #dee2e6; padding: 0; }
            .pmr-table td.pmr-od { text-align: center; font-weight: 600; color: #6c757d; font-size: .78rem; padding: 4px; width: 36px; }
            .pmr-table td input { width: 100%; border: none; outline: none; text-align: center; padding: 5px 4px; font-size: .85rem; background: transparent; }
            .pmr-table td input:focus { background: #f0f9f8; }
            `}</style>
            
            <PageHeader title="Novo Prontuário" subtitle={`Paciente: ${patient.person?.full_name}`} breadcrumb={[{ label: 'Prontuários', url: `/panel/patients/${patient.id}/medicalrecords` }]}>
                <Link href={`/panel/patients/${patient.id}/medicalrecords`} className="btn btn-secondary btn-sm">
                    <i className="ti ti-arrow-left me-1"></i>Voltar
                </Link>
            </PageHeader>

            <div className="row g-3">
                {/* Coluna Lateral - Paciente */}
                <div className="col-12 col-md-3">
                    <div className="card mb-0 sticky-top" style={{ top: '6rem' }}>
                        <img src={patient.photo_url || '/system/images/team.png'} alt={patient.person?.full_name} className="card-img-top" style={{ objectFit: 'cover', maxHeight: '180px' }} />
                        <div className="card-body p-3">
                            <p className="text-muted small mb-1">{patient.code}</p>
                            <h6 className="mb-3 fw-semibold">{patient.person?.full_name}</h6>
                            <hr className="my-2" />
                            <div className="row g-2 mb-2">
                                <div className="col-6">
                                    <div className="text-muted" style={{ fontSize: '.7rem', textTransform: 'uppercase', letterSpacing: '.04em' }}>Sexo</div>
                                    <div className="small">{patient.person?.gender || '—'}</div>
                                </div>
                                <div className="col-6">
                                    <div className="text-muted" style={{ fontSize: '.7rem', textTransform: 'uppercase', letterSpacing: '.04em' }}>Idade</div>
                                    <div className="small">{patient.age_string || '—'}</div>
                                </div>
                            </div>
                            {patient.covenant && (
                                <div className="mb-2">
                                    <div className="text-muted" style={{ fontSize: '.7rem', textTransform: 'uppercase', letterSpacing: '.04em' }}>Convênio</div>
                                    <div className="small">{patient.covenant.name}</div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Coluna Formulário */}
                <div className="col-12 col-md-9">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center">
                            <h5 className="mb-0"><i className="ti ti-file-medical text-info me-2"></i> Preencher Ficha Clínica</h5>
                        </div>
                        <div className="card-body p-3">
                            <form onSubmit={handleSubmit}>
                                <div className="row g-2 mb-3">
                                    <div className="col-12 col-md-4">
                                        <label className="pmr-label">Médico Responsável</label>
                                        <select className={`form-select form-select-sm ${errors.doctor_id ? 'is-invalid' : ''}`} value={data.doctor_id} onChange={(e) => onChange('doctor_id', e.target.value)}>
                                            <option value="">Selecione...</option>
                                            {asDoctors(doctors).map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                                        </select>
                                    </div>
                                    <div className="col-12 col-md-8">
                                        <label className="pmr-label">Queixa Principal / Motivo da Consulta</label>
                                        <input type="text" className={`form-control form-control-sm ${errors.main_complaint ? 'is-invalid' : ''}`} value={data.main_complaint} onChange={(e) => onChange('main_complaint', e.target.value)} />
                                    </div>
                                </div>

                                <div className="row g-3 border-top pt-3 mt-1">
                                    {/* Lado Esquerdo */}
                                    <div className="col-12 col-lg-7">
                                        <div className="row g-2 mb-3">
                                            <div className="col-4">
                                                <FormSelect label="Visão Cromática" name="visual_acuity_type_id" value={data.visual_acuity_type_id} onChange={onChange} options={asOptions(colorVisionTypes)} labelClass="pmr-label" emptyOption="—" />
                                            </div>
                                            <div className="col-4">
                                                <FormSelect label="PPC" name="near_point_convergence_id" value={data.near_point_convergence_id} onChange={onChange} options={asOptions(nearPointTypes)} labelClass="pmr-label" emptyOption="—" />
                                            </div>
                                            <div className="col-4">
                                                <FormSelect label="Cover Test" name="cover_test_type_id" value={data.cover_test_type_id} onChange={onChange} options={asOptions(coverTestTypes)} labelClass="pmr-label" emptyOption="—" />
                                            </div>
                                        </div>

                                        <div className="row g-2 mb-3">
                                            <div className="col-7">
                                                <label className="pmr-label">Campimetria / AV S/C</label>
                                                <div className="d-flex align-items-center gap-1">
                                                    <span className="pmr-eye">OD</span>
                                                    <select className="form-select form-select-sm" value={data.visual_acuity_without_correction_right_id} onChange={e => onChange('visual_acuity_without_correction_right_id', e.target.value)}>
                                                        <option value="">—</option>
                                                        {asOptions(visualAcuityTypes).map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                                                    </select>
                                                    <span className="pmr-eye mx-1">OE</span>
                                                    <select className="form-select form-select-sm" value={data.visual_acuity_without_correction_left_id} onChange={e => onChange('visual_acuity_without_correction_left_id', e.target.value)}>
                                                        <option value="">—</option>
                                                        {asOptions(visualAcuityTypes).map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                                                    </select>
                                                </div>
                                            </div>
                                            <div className="col-5">
                                                <label className="pmr-label">Tonometria</label>
                                                <div className="d-flex align-items-center gap-1">
                                                    <span className="pmr-eye">OD</span>
                                                    <input type="number" step="0.5" className="form-control form-control-sm text-center" style={{ width: 52 }} value={data.tonometer_right} onChange={e => onChange('tonometer_right', e.target.value)} placeholder="00" />
                                                    <span className="pmr-eye mx-1">OE</span>
                                                    <input type="number" step="0.5" className="form-control form-control-sm text-center" style={{ width: 52 }} value={data.tonometer_left} onChange={e => onChange('tonometer_left', e.target.value)} placeholder="00" />
                                                    <input type="time" className="form-control form-control-sm ms-1" style={{ width: 82 }} value={data.tonometer_time} onChange={e => onChange('tonometer_time', e.target.value)} />
                                                </div>
                                            </div>
                                        </div>

                                        <div className="mb-3">
                                            <label className="pmr-label">Refração Dinâmica (Retinoscopia)</label>
                                            <table className="pmr-table">
                                                <thead><tr><th style={{width: 36}}></th><th>Esférico</th><th>Cilíndrico</th><th>Eixo</th></tr></thead>
                                                <tbody>
                                                    <tr>
                                                        <td className="pmr-od">OD</td>
                                                        <td><input type="number" step="0.25" value={data.dynamic_spherical_right} onChange={e => onChange('dynamic_spherical_right', e.target.value)} /></td>
                                                        <td><input type="number" step="0.25" value={data.dynamic_cylindrical_right} onChange={e => onChange('dynamic_cylindrical_right', e.target.value)} /></td>
                                                        <td><input type="number" min="0" max="180" value={data.dynamic_axis_right} onChange={e => onChange('dynamic_axis_right', e.target.value)} placeholder="0°" /></td>
                                                    </tr>
                                                    <tr>
                                                        <td className="pmr-od">OE</td>
                                                        <td><input type="number" step="0.25" value={data.dynamic_spherical_left} onChange={e => onChange('dynamic_spherical_left', e.target.value)} /></td>
                                                        <td><input type="number" step="0.25" value={data.dynamic_cylindrical_left} onChange={e => onChange('dynamic_cylindrical_left', e.target.value)} /></td>
                                                        <td><input type="number" min="0" max="180" value={data.dynamic_axis_left} onChange={e => onChange('dynamic_axis_left', e.target.value)} placeholder="0°" /></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div className="mb-0">
                                            <label className="pmr-label">Refração Estática (Subjetivo)</label>
                                            <table className="pmr-table">
                                                <thead><tr><th style={{width: 36}}></th><th>Esférico</th><th>Cilíndrico</th><th>Eixo</th></tr></thead>
                                                <tbody>
                                                    <tr>
                                                        <td className="pmr-od">OD</td>
                                                        <td><input type="number" step="0.25" value={data.static_spherical_right} onChange={e => onChange('static_spherical_right', e.target.value)} /></td>
                                                        <td><input type="number" step="0.25" value={data.static_cylindrical_right} onChange={e => onChange('static_cylindrical_right', e.target.value)} /></td>
                                                        <td><input type="number" min="0" max="180" value={data.static_axis_right} onChange={e => onChange('static_axis_right', e.target.value)} placeholder="0°" /></td>
                                                    </tr>
                                                    <tr>
                                                        <td className="pmr-od">OE</td>
                                                        <td><input type="number" step="0.25" value={data.static_spherical_left} onChange={e => onChange('static_spherical_left', e.target.value)} /></td>
                                                        <td><input type="number" step="0.25" value={data.static_cylindrical_left} onChange={e => onChange('static_cylindrical_left', e.target.value)} /></td>
                                                        <td><input type="number" min="0" max="180" value={data.static_axis_left} onChange={e => onChange('static_axis_left', e.target.value)} placeholder="0°" /></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    {/* Lado Direito */}
                                    <div className="col-12 col-lg-5 border-start">
                                        <div className="d-flex gap-4 mb-3">
                                            <div>
                                                <label className="pmr-label" style={{ fontStyle: 'normal' }}>Diabético</label>
                                                <div className="d-flex gap-2">
                                                    <div className="form-check form-switch" title="Paciente"><input className="form-check-input" type="checkbox" role="switch" name="diabetic" checked={data.diabetic} onChange={onCheckboxChange} /></div>
                                                    <div className="form-check form-switch" title="Família"><input className="form-check-input bg-warning border-warning" type="checkbox" role="switch" name="diabetic_family" checked={data.diabetic_family} onChange={onCheckboxChange} /></div>
                                                </div>
                                            </div>
                                            <div>
                                                <label className="pmr-label" style={{ fontStyle: 'normal' }}>Hipertenso</label>
                                                <div className="d-flex gap-2">
                                                    <div className="form-check form-switch" title="Paciente"><input className="form-check-input" type="checkbox" role="switch" name="hypertensive" checked={data.hypertensive} onChange={onCheckboxChange} /></div>
                                                    <div className="form-check form-switch" title="Família"><input className="form-check-input bg-danger border-danger" type="checkbox" role="switch" name="hypertensive_family" checked={data.hypertensive_family} onChange={onCheckboxChange} /></div>
                                                </div>
                                            </div>
                                            <div>
                                                <label className="pmr-label" style={{ fontStyle: 'normal' }}>Glaucoma</label>
                                                <div className="d-flex gap-2">
                                                    <div className="form-check form-switch" title="Paciente"><input className="form-check-input" type="checkbox" role="switch" name="glaucomatous" checked={data.glaucomatous} onChange={onCheckboxChange} /></div>
                                                    <div className="form-check form-switch" title="Família"><input className="form-check-input bg-info border-info" type="checkbox" role="switch" name="glaucomatous_family" checked={data.glaucomatous_family} onChange={onCheckboxChange} /></div>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="row g-2 mb-3">
                                            <div className="col-4"><FormSelect label="Adição" name="addition_type_id" value={data.addition_type_id} onChange={onChange} options={asOptions(additionTypes)} labelClass="pmr-label" emptyOption="—" /></div>
                                            <div className="col-4"><FormSelect label="Tipo Longe" name="lens_away_id" value={data.lens_away_id} onChange={onChange} options={asOptions(lenses)} labelClass="pmr-label" emptyOption="—" /></div>
                                            <div className="col-4"><FormSelect label="Tipo Perto" name="lens_near_id" value={data.lens_near_id} onChange={onChange} options={asOptions(lenses)} labelClass="pmr-label" emptyOption="—" /></div>
                                        </div>

                                        <div className="mb-3">
                                            <label className="pmr-label">A/V C/C</label>
                                            <div className="d-flex align-items-center gap-1">
                                                <span className="pmr-eye">OD</span>
                                                <select className="form-select form-select-sm" value={data.visual_acuity_with_correction_right_id} onChange={e => onChange('visual_acuity_with_correction_right_id', e.target.value)}><option value="">—</option>{asOptions(visualAcuityTypes).map(o => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
                                                <span className="pmr-eye mx-1">OE</span>
                                                <select className="form-select form-select-sm" value={data.visual_acuity_with_correction_left_id} onChange={e => onChange('visual_acuity_with_correction_left_id', e.target.value)}><option value="">—</option>{asOptions(visualAcuityTypes).map(o => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
                                            </div>
                                        </div>

                                        <InputSet label="Biomicroscopia Frontal" namePrefix="biomicroscopy" isOdOe />
                                        <InputSet label="Fundoscopia" namePrefix="fundoscopy" isOdOe />

                                        <div className="mb-2">
                                            <label className="pmr-label">Observações Gerais / Evolução</label>
                                            <textarea className="form-control form-control-sm" rows={2} value={data.observation_general} onChange={e => onChange('observation_general', e.target.value)}></textarea>
                                        </div>
                                        <div className="mb-0">
                                            <label className="pmr-label">Observações sobre as Lentes</label>
                                            <textarea className="form-control form-control-sm" rows={2} value={data.observation_of_lenses} onChange={e => onChange('observation_of_lenses', e.target.value)}></textarea>
                                        </div>

                                    </div>
                                </div>

                                <div className="text-end mt-4 pt-3 border-top">
                                    <button type="submit" className="btn btn-primary" disabled={processing}>
                                        <i className="ti ti-device-floppy me-1"></i> {processing ? 'Salvando...' : 'Salvar Prontuário'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
