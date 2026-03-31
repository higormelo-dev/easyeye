import SettingsCrud from './SettingsCrud';

export default function Lenses({ records }) {
    return <SettingsCrud title="Lentes" records={records} baseUrl="/panel/setting/lenses" />;
}
