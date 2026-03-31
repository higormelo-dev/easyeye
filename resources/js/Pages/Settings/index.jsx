/**
 * Páginas individuais de Settings que reutilizam o componente genérico SettingsCrud.
 * Cada export é uma página Inertia que recebe 'records' do Laravel e passa para o CRUD.
 */
import SettingsCrud from './SettingsCrud';

// Ordem alfabética dos tipos de configuração
export function SkinTypes({ records }) {
    return <SettingsCrud title="Tipos de Pele" records={records} baseUrl="/panel/setting/skintypes" />;
}

export function IrisTypes({ records }) {
    return <SettingsCrud title="Tipos de Íris" records={records} baseUrl="/panel/setting/iristypes" />;
}

export function VisitTypes({ records }) {
    return <SettingsCrud title="Tipos de Visita" records={records} baseUrl="/panel/setting/visittypes" />;
}

export function AdditionTypes({ records }) {
    return <SettingsCrud title="Tipos de Adição" records={records} baseUrl="/panel/setting/additiontypes" />;
}

export function ColorVisionTypes({ records }) {
    return <SettingsCrud title="Visão de Cores" records={records} baseUrl="/panel/setting/colorvisiontypes" />;
}

export function CoverTestTypes({ records }) {
    return <SettingsCrud title="Tipos Cover Test" records={records} baseUrl="/panel/setting/covertesttypes" />;
}

export function VisualAcuityTypes({ records }) {
    return <SettingsCrud title="Acuidade Visual" records={records} baseUrl="/panel/setting/visualacuitytypes" />;
}

export function SurgeryTypes({ records }) {
    return <SettingsCrud title="Tipos de Cirurgia" records={records} baseUrl="/panel/setting/surgerytypes" />;
}

export function Lenses({ records }) {
    return <SettingsCrud title="Lentes" records={records} baseUrl="/panel/setting/lenses" />;
}

export function NearPointConvergences({ records }) {
    return <SettingsCrud title="Ponto Próximo de Convergência" records={records} baseUrl="/panel/setting/nearpointconvergences" />;
}

export function Covenants({ records }) {
    return <SettingsCrud title="Convênios" records={records} baseUrl="/panel/setting/covenants" />;
}

export function Resources({ records }) {
    return <SettingsCrud title="Recursos da Clínica" records={records} baseUrl="/panel/setting/resources" />;
}
