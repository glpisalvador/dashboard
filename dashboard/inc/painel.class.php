<?php

/**
 * Plugin Dashboard - painéis: criação (a partir de modelos), compartilhamento, edição e permissões.
 * Visibilidade: privado (só o dono), perfis escolhidos ou todos com direito de ver painéis.
 */
class PluginDashboardPainel extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_dashboard_paineis';
    public const VISIBILIDADES = ['privado' => 'Só eu', 'perfis' => 'Perfis escolhidos', 'todos' => 'Todos com acesso ao Dashboard'];
    public const ATUALIZACOES = [0 => 'Desligada', 30 => '30 segundos', 60 => '1 minuto', 300 => '5 minutos', 900 => '15 minutos'];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Painéis' : 'Painel';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getIcon(): string
    {
        return 'ti ti-layout-dashboard';
    }

    public static function canView(): bool
    {
        return PluginDashboardConfig::pode(READ);
    }

    public static function canCreate(): bool
    {
        return PluginDashboardConfig::pode(CREATE);
    }

    public static function canUpdate(): bool
    {
        return PluginDashboardConfig::pode(CREATE);
    }

    public static function canDelete(): bool
    {
        return PluginDashboardConfig::pode(CREATE);
    }

    public static function canPurge(): bool
    {
        return PluginDashboardConfig::pode(PURGE);
    }

    public function getRights($interface = 'central')
    {
        return [READ => 'Ver painéis', CREATE => 'Criar e editar os próprios', PURGE => 'Administrar todos'];
    }

    // =====================================================================
    // Leitura e permissões
    // =====================================================================

    public static function obter(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $r) {
            $r['widgets'] = json_decode((string) $r['widgets'], true) ?: [];
            $r['filtros'] = json_decode((string) $r['filtros'], true) ?: [];
            $r['perfis'] = array_map('intval', json_decode((string) $r['perfis'], true) ?: []);
            return $r;
        }
        return null;
    }

    public static function podeVer(array $p): bool
    {
        if (!PluginDashboardConfig::pode(READ)) {
            return false;
        }
        $uid = (int) Session::getLoginUserID();
        return (int) $p['users_id'] === $uid || PluginDashboardConfig::pode(PURGE) || $p['visibilidade'] === 'todos'
            || ($p['visibilidade'] === 'perfis' && in_array((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0), $p['perfis'], true));
    }

    public static function podeEditar(array $p): bool
    {
        return PluginDashboardConfig::pode(PURGE) || ((int) $p['users_id'] === (int) Session::getLoginUserID() && PluginDashboardConfig::pode(CREATE));
    }

    /** Painéis que o usuário pode ver: [id => linha] (os próprios primeiro) */
    public static function visiveis(): array
    {
        global $DB;
        $uid = (int) Session::getLoginUserID();
        $lista = [];
        foreach ($DB->request(['FROM' => self::TABELA, 'ORDER' => ['name ASC']]) as $r) {
            $r['widgets'] = json_decode((string) $r['widgets'], true) ?: [];
            $r['filtros'] = json_decode((string) $r['filtros'], true) ?: [];
            $r['perfis'] = array_map('intval', json_decode((string) $r['perfis'], true) ?: []);
            if (self::podeVer($r)) {
                $lista[(int) $r['id']] = $r;
            }
        }
        uasort($lista, fn($a, $b) => [(int) $b['users_id'] === $uid, mb_strtolower($a['name'])] <=> [(int) $a['users_id'] === $uid, mb_strtolower($b['name'])]);
        return $lista;
    }

    // =====================================================================
    // Gravação
    // =====================================================================

    private static function normalizar(array $dados, ?array $atual = null): array|string
    {
        $C = PluginDashboardCatalogo::class;
        $saida = [];
        if (array_key_exists('name', $dados) || $atual === null) {
            $nome = mb_substr(trim(strip_tags((string) ($dados['name'] ?? ''))), 0, 255);
            if ($nome === '') {
                return 'Informe o nome do painel.';
            }
            $saida['name'] = $nome;
        }
        if (array_key_exists('visibilidade', $dados)) {
            $saida['visibilidade'] = isset(self::VISIBILIDADES[$dados['visibilidade']]) ? (string) $dados['visibilidade'] : 'privado';
            $saida['perfis'] = json_encode(array_values(array_unique(array_filter(array_map('intval', (array) ($dados['perfis'] ?? [])), fn($v) => $v > 0))));
        }
        if (array_key_exists('atualizacao', $dados)) {
            $min = (int) PluginDashboardConfig::getConfig('atualizacao_min');
            $v = (int) $dados['atualizacao'];
            $saida['atualizacao'] = isset(self::ATUALIZACOES[$v]) ? ($v > 0 ? max($v, $min) : 0) : 0;
        }
        if (array_key_exists('widgets', $dados)) {
            $widgets = array_values(array_filter(array_map(fn($w) => is_array($w) ? $C::widget($w) : null, (array) $dados['widgets'])));
            $saida['widgets'] = json_encode(array_slice($widgets, 0, 60), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('filtros', $dados)) {
            $saida['filtros'] = json_encode((new PluginDashboardFiltro((array) $dados['filtros']))->toArray(), JSON_UNESCAPED_UNICODE);
        }
        return $saida;
    }

    /** Cria o painel (widgets do modelo) e devolve o id, ou a mensagem de erro */
    public static function criar(array $dados): int|string
    {
        global $DB;
        $modelo = isset(PluginDashboardCatalogo::MODELOS[$dados['modelo'] ?? '']) ? (string) $dados['modelo'] : 'visao_geral';
        $dados += ['visibilidade' => 'privado', 'atualizacao' => 0];
        $dados['widgets'] = $dados['widgets'] ?? PluginDashboardCatalogo::widgetsDoModelo($modelo);
        $dados['filtros'] = $dados['filtros'] ?? ['periodo' => $modelo === 'tendencias' ? '30d' : '7d', 'modulos' => ['Ticket'], 'comparar' => $modelo === 'visao_geral' ? 'anterior' : 'nenhuma'];
        $valores = self::normalizar($dados);
        if (is_string($valores)) {
            return $valores;
        }
        $DB->insert(self::TABELA, $valores + ['users_id' => (int) Session::getLoginUserID(), 'modelo' => $modelo, 'date_creation' => date('Y-m-d H:i:s'), 'date_mod' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }

    public static function salvar(int $id, array $dados): ?string
    {
        global $DB;
        $p = self::obter($id);
        if ($p === null || !self::podeEditar($p)) {
            return 'Painel não encontrado ou sem permissão para alterar.';
        }
        $valores = self::normalizar($dados, $p);
        if (is_string($valores)) {
            return $valores;
        }
        if ($valores) {
            $DB->update(self::TABELA, $valores + ['date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
        }
        return null;
    }

    public static function duplicar(int $id): int|string
    {
        $p = self::obter($id);
        if ($p === null || !self::podeVer($p) || !PluginDashboardConfig::pode(CREATE)) {
            return 'Painel não encontrado ou sem permissão.';
        }
        return self::criar(['name' => mb_substr('Cópia de ' . $p['name'], 0, 255), 'modelo' => $p['modelo'] ?: 'branco', 'widgets' => $p['widgets'], 'filtros' => $p['filtros'], 'atualizacao' => $p['atualizacao']]);
    }

    public static function excluir(int $id): ?string
    {
        global $DB;
        $p = self::obter($id);
        if ($p === null || !self::podeEditar($p)) {
            return 'Painel não encontrado ou sem permissão para excluir.';
        }
        $DB->delete('glpi_plugin_dashboard_agendamentos', ['plugin_dashboard_paineis_id' => $id]);
        $DB->delete(self::TABELA, ['id' => $id]);
        return null;
    }
}
