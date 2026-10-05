<?php

/**
 * Plugin Dashboard - configurações, direitos e utilitários comuns
 */
class PluginDashboardConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_dashboard_configs';
    public const DIREITO = 'plugin_dashboard_painel';

    public static function getTypeName($nb = 0): string
    {
        return 'Dashboard';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    /** READ ver painéis; CREATE criar e editar os próprios; PURGE administrar todos */
    public static function pode(int $direito): bool
    {
        return (bool) Session::getLoginUserID() && Session::haveRight(self::DIREITO, $direito);
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'grupos_tecnicos'   => [],
            'usuarios_ocultos'  => [],
            'atualizacao_min'   => '30',
            'limite_lista'      => '1000',
            'email_remetente'   => '',
            'email_nome'        => '',
            'rodape_email'      => '',
        ];
    }

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['value'], 'FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1]) as $row) {
            return $row['value'];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            return (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        }
        return (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value), JSON_UNESCAPED_UNICODE));
    }

    public static function ids(string $name): array
    {
        return array_values(array_unique(array_filter(array_map('intval', self::getArrayConfig($name)), fn($v) => $v > 0)));
    }

    // =====================================================================
    // Listas
    // =====================================================================

    public static function nomesUsuarios(array $ids): array
    {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        $nomes = [];
        foreach (array_chunk($ids, 500) as $parte) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $parte]]) as $u) {
                $nome = trim(trim((string) $u['firstname']) . ' ' . trim((string) $u['realname']));
                $nomes[(int) $u['id']] = $nome !== '' ? $nome : (string) $u['name'];
            }
        }
        return $nomes;
    }

    /** E-mail principal de cada usuário: o padrão ou o primeiro válido */
    public static function emailsUsuarios(array $ids): array
    {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        $emails = [];
        if ($ids) {
            foreach ($DB->request(['SELECT' => ['users_id', 'email'], 'FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => $ids], 'ORDER' => ['is_default DESC', 'id ASC']]) as $r) {
                $uid = (int) $r['users_id'];
                $email = trim((string) $r['email']);
                if (!isset($emails[$uid]) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[$uid] = $email;
                }
            }
        }
        return $emails;
    }

    public static function listarUsuarios(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => ['firstname ASC', 'realname ASC', 'name ASC'],
        ]) as $u) {
            $nome = trim(trim((string) $u['firstname']) . ' ' . trim((string) $u['realname']));
            $lista[(int) $u['id']] = $nome !== '' ? $nome . ' (' . $u['name'] . ')' : (string) $u['name'];
        }
        return $lista;
    }

    /** glpi_groups não tem is_deleted */
    public static function listarGrupos(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'completename'], 'FROM' => 'glpi_groups', 'ORDER' => 'completename ASC']) as $r) {
            $lista[(int) $r['id']] = (string) ($r['completename'] ?: $r['name']);
        }
        return $lista;
    }

    public static function listarPerfis(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $lista[(int) $r['id']] = (string) $r['name'];
        }
        return $lista;
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/dashboard/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** URL de arquivo de public/ (servido em /plugins/<nome>/ no GLPI 11/12), com versão e data do arquivo */
    public static function urlAsset(string $caminho): string
    {
        global $CFG_GLPI;
        $arquivo = dirname(__DIR__) . '/public/' . $caminho;
        return $CFG_GLPI['root_doc'] . '/plugins/dashboard/' . $caminho . '?v=' . PLUGIN_DASHBOARD_VERSION . '-' . (is_file($arquivo) ? filemtime($arquivo) : 0);
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    /** Configuração do editor rico do GLPI em tinymce_editor_configs['dashboard_modelo'] (para editores em modais) */
    public static function editorModelo(): string
    {
        static $feito = false;
        if ($feito) {
            return '';
        }
        $feito = true;
        Html::requireJs('tinymce');
        return (string) Html::initEditorSystem('dashboard_modelo', '', false, false, false, 160, [], 'top', false);
    }

    public static function formatarTempo(float $segundos): string
    {
        $s = (int) round($segundos);
        if ($s <= 0) {
            return '0 min';
        }
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        $partes = [];
        if ($d > 0) {
            $partes[] = $d . 'd';
        }
        if ($h > 0) {
            $partes[] = $h . 'h';
        }
        if ($m > 0 && $d === 0) {
            $partes[] = $m . 'min';
        }
        return $partes ? implode(' ', $partes) : '< 1 min';
    }

    /** Multiselect com pesquisa, marcar todos e selecionados primeiro */
    public static function multiselect(string $name, array $opcoes, array $selecionados, string $placeholder = 'Selecione...', array $attrs = []): string
    {
        $selecionados = array_map('strval', $selecionados);
        $itens = [];
        foreach ($opcoes as $valor => $rotulo) {
            $itens[] = ['valor' => (string) $valor, 'rotulo' => (string) $rotulo, 'marcado' => in_array((string) $valor, $selecionados, true)];
        }
        usort($itens, fn($a, $b) => [$b['marcado'], mb_strtolower($a['rotulo'])] <=> [$a['marcado'], mb_strtolower($b['rotulo'])]);
        $extra = '';
        foreach ($attrs as $k => $v) {
            $extra .= ' ' . self::e($k) . '="' . self::e($v) . '"';
        }
        $h = '<div class="dashboard-ms" data-dashboard-ms data-name="' . self::e($name) . '" data-placeholder="' . self::e($placeholder) . '"' . $extra . '>';
        $h .= '<button type="button" class="dashboard-ms-cabecalho form-select form-select-sm" data-dashboard-ms-abrir><span class="dashboard-ms-texto"></span></button>';
        $h .= '<div class="dashboard-ms-dropdown" hidden>';
        $h .= '<div class="dashboard-ms-topo"><input type="text" class="form-control form-control-sm dashboard-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>';
        $h .= '<label class="dashboard-ms-todos"><input type="checkbox" class="dashboard-check" data-dashboard-ms-todos> Marcar/desmarcar todos</label>';
        $h .= '<div class="dashboard-ms-opcoes">';
        foreach ($itens as $i) {
            $h .= '<label class="dashboard-ms-opcao' . ($i['marcado'] ? ' selected' : '') . '" data-label="' . self::e(mb_strtolower($i['rotulo'])) . '">'
                . '<input type="checkbox" class="dashboard-check" name="' . self::e($name) . '[]" value="' . self::e($i['valor']) . '"' . ($i['marcado'] ? ' checked' : '') . '>'
                . '<span>' . self::e($i['rotulo']) . '</span></label>';
        }
        $h .= '</div></div><div class="dashboard-ms-contador"></div></div>';
        return $h;
    }

    public static function idsPost(string $campo): array
    {
        $ids = array_map('intval', (array) ($_POST[$campo] ?? []));
        return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    }
}
