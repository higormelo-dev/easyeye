import SettingsCrud from './SettingsCrud';

export default function NearPointConvergences({ records }) {
    return <SettingsCrud title="Ponto Próximo de Convergência" records={records} baseUrl="/panel/setting/nearpointconvergences" />;
}
