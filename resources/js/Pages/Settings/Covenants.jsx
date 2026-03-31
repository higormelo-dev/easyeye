import SettingsCrud from './SettingsCrud';

export default function Covenants({ records }) {
    return <SettingsCrud title="Convênios" records={records} baseUrl="/panel/setting/covenants" />;
}
