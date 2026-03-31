import SettingsCrud from './SettingsCrud';

export default function Resources({ records }) {
    return <SettingsCrud title="Recursos da Clínica" records={records} baseUrl="/panel/setting/resources" />;
}
