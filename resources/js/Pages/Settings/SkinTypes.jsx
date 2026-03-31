import SettingsCrud from './SettingsCrud';

export default function SkinTypes({ records }) {
    return <SettingsCrud title="Tipos de Pele" records={records} baseUrl="/panel/setting/skintypes" />;
}
