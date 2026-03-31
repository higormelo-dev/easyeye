import SettingsCrud from './SettingsCrud';

export default function VisualAcuityTypes({ records }) {
    return <SettingsCrud title="Acuidade Visual" records={records} baseUrl="/panel/setting/visualacuitytypes" />;
}
