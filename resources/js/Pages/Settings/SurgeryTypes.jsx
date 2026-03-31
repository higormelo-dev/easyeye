import SettingsCrud from './SettingsCrud';

export default function SurgeryTypes({ records }) {
    return <SettingsCrud title="Tipos de Cirurgia" records={records} baseUrl="/panel/setting/surgerytypes" />;
}
