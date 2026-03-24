import '../css/app.css';
import './bootstrap';

import Alpine from 'alpinejs';
import maskBrDirective from './directives/maskBr';
maskBrDirective(Alpine);

// Registra componentes Alpine globalmente.
// Adicione novos componentes aqui conforme o projeto crescer.
import patientViewToggle from './components/patientViewToggle';
Alpine.data('patientViewToggle', patientViewToggle);

import doctorViewToggle from './components/doctorViewToggle';
Alpine.data('doctorViewToggle', doctorViewToggle);

import userViewToggle from './components/userViewToggle';
Alpine.data('userViewToggle', userViewToggle);

import scheduleView from './components/scheduleView';
Alpine.data('scheduleView', scheduleView);

import crudForm from './components/crudForm';
Alpine.data('crudForm', crudForm);

import patientSearch from './components/patientSearch';
Alpine.data('patientSearch', patientSearch);

import datatableManager from './components/datatableManager';
Alpine.data('datatableManager', datatableManager);

import registerWizard from './components/registerWizard';
Alpine.data('registerWizard', registerWizard);

import slotPicker from './components/slotPicker';
Alpine.data('slotPicker', slotPicker);

window.Alpine = Alpine;

Alpine.start();
