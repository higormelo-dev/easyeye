import SettingsCrud from './SettingsCrud';

export default function VisitTypes({ records }) {
    return <SettingsCrud title="Tipos de Visita" records={records} baseUrl="/panel/setting/visittypes" />;
}
