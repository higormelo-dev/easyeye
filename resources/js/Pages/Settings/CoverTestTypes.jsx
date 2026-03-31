import SettingsCrud from './SettingsCrud';

export default function CoverTestTypes({ records }) {
    return <SettingsCrud title="Tipos de Cover Test" records={records} baseUrl="/panel/setting/covertesttypes" />;
}
