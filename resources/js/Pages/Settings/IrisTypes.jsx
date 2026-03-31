import SettingsCrud from './SettingsCrud';

export default function IrisTypes({ records }) {
    return <SettingsCrud title="Tipos de Íris" records={records} baseUrl="/panel/setting/iristypes" />;
}
