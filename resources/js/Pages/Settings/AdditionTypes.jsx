import SettingsCrud from './SettingsCrud';

export default function AdditionTypes({ records }) {
    return <SettingsCrud title="Tipos de Adição" records={records} baseUrl="/panel/setting/additiontypes" />;
}
