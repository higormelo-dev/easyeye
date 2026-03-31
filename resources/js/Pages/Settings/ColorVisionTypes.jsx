import SettingsCrud from './SettingsCrud';

export default function ColorVisionTypes({ records }) {
    return <SettingsCrud title="Visão de Cores" records={records} baseUrl="/panel/setting/colorvisiontypes" />;
}
