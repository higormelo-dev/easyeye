import '../css/app.css';
import './bootstrap';

import Alpine from 'alpinejs';

// Registra componentes Alpine globalmente.
// Adicione novos componentes aqui conforme o projeto crescer.
import patientViewToggle from './components/patientViewToggle';
Alpine.data('patientViewToggle', patientViewToggle);

import doctorViewToggle from './components/doctorViewToggle';
Alpine.data('doctorViewToggle', doctorViewToggle);

import userViewToggle from './components/userViewToggle';
Alpine.data('userViewToggle', userViewToggle);

window.Alpine = Alpine;

Alpine.start();
