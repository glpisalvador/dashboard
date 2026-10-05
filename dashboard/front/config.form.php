<?php

/**
 * Plugin Dashboard - configuração (marketplace e menu). Cada aba é um formulário próprio que faz
 * POST para esta mesma página.
 */

Session::checkLoginUser();

global $DB;
$C = PluginDashboardConfig::class;
$P = PluginDashboardPainel::class;
$e = [$C, 'e'];

if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$ABAS = [
    'geral'   => ['ti ti-adjustments', 'Geral'],
    'email'   => ['ti ti-mail', 'E-mail'],
    'paineis' => ['ti ti-layout-dashboard', 'Todos os painéis'],
    'acesso'  => ['ti ti-shield-lock', 'Acesso'],
];
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'geral');
if (!isset($ABAS[$aba])) {
    $aba = 'geral';
}
$linha = fn(string $campo, int $max) => mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) ($_POST[$campo] ?? ''))), 0, $max);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    switch ((string) $_POST['save_action']) {
        case 'salvar_geral':
            $C::setArrayConfig('grupos_tecnicos', $C::idsPost('grupos_tecnicos'));
            $C::setArrayConfig('usuarios_ocultos', $C::idsPost('usuarios_ocultos'));
            $C::setConfig('atualizacao_min', (string) (in_array((int) ($_POST['atualizacao_min'] ?? 30), [30, 60, 300, 900], true) ? (int) $_POST['atualizacao_min'] : 30));
            $C::setConfig('limite_lista', (string) max(100, min(5000, (int) ($_POST['limite_lista'] ?? 1000))));
            Session::addMessageAfterRedirect('Configuração geral salva.', false, INFO);
            break;

        case 'salvar_email':
            $remetente = $linha('email_remetente', 255);
            if ($remetente !== '' && !filter_var($remetente, FILTER_VALIDATE_EMAIL)) {
                Session::addMessageAfterRedirect('O e-mail do remetente não é válido.', false, ERROR);
                break;
            }
            $C::setConfig('email_remetente', $remetente);
            $C::setConfig('email_nome', $linha('email_nome', 120));
            $C::setConfig('rodape_email', $linha('rodape_email', 300));
            Session::addMessageAfterRedirect('Configuração de e-mail salva.', false, INFO);
            break;

        case 'excluir_painel':
            $erro = $P::excluir((int) ($_POST['painel'] ?? 0));
            Session::addMessageAfterRedirect($erro ?? 'Painel excluído.', false, $erro ? ERROR : INFO);
            break;
    }
}

Html::header('Dashboard', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginDashboardMenu');
echo '<link rel="stylesheet" href="' . $e($C::urlAsset('css/dashboard.css')) . '">';

$form = fn(string $acao, string $abaForm) => '<form method="post" action="' . $e($C::url('config.form.php')) . '" class="dashboard-form">'
    . '<input type="hidden" name="save_action" value="' . $e($acao) . '"><input type="hidden" name="aba" value="' . $e($abaForm) . '">';
$salvar = '<div class="dashboard-rodape-form"><button type="submit" class="btn btn-sm dashboard-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar</span></button></div>';
$card = fn(string $icone, string $titulo, string $corpo) => '<div class="card dashboard-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body">' . $corpo . '</div></div>';
$explicacao = fn(string $texto) => '<p class="dashboard-explicacao"><i class="ti ti-info-circle"></i><span>' . $texto . '</span></p>';
$campo = fn(string $rotulo, string $controle, string $dica = '') => '<div class="dashboard-campo"><label>' . $e($rotulo) . '</label>' . $controle . ($dica !== '' ? '<small>' . $dica . '</small>' : '') . '</div>';
$select = function (string $name, array $opcoes, string $valor) use ($e): string {
    $h = '<select class="form-select form-select-sm" name="' . $e($name) . '">';
    foreach ($opcoes as $k => $r) {
        $h .= '<option value="' . $e($k) . '"' . ((string) $k === $valor ? ' selected' : '') . '>' . $e($r) . '</option>';
    }
    return $h . '</select>';
};

echo '<div class="dashboard-config" data-dashboard-config>';
echo '<ul class="nav nav-tabs dashboard-abas">';
foreach ($ABAS as $chave => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a class="nav-link' . ($aba === $chave ? ' active' : '') . '" href="#" data-aba="' . $chave . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ---------------------------------------------------------------- Geral
echo '<div data-aba-painel="geral"' . ($aba !== 'geral' ? ' hidden' : '') . '>' . $form('salvar_geral', 'geral');
$corpo = '<div class="dashboard-grade-2">'
    . $campo('Grupos de técnicos', $C::multiselect('grupos_tecnicos', $C::listarGrupos(), $C::ids('grupos_tecnicos'), 'Todos que já tiveram itens atribuídos'), 'O filtro "Técnicos" dos painéis lista só os membros destes grupos. Vazio: quem já foi técnico atribuído em algum item.')
    . $campo('Usuários ocultos', $C::multiselect('usuarios_ocultos', $C::listarUsuarios(), $C::ids('usuarios_ocultos'), 'Nenhum usuário'), 'Contas técnicas ou de sistema que não aparecem como técnico nem requerente nos gráficos.')
    . $campo('Atualização automática mínima', $select('atualizacao_min', [30 => '30 segundos', 60 => '1 minuto', 300 => '5 minutos', 900 => '15 minutos'], (string) $C::getConfig('atualizacao_min')), 'Menor intervalo que um painel pode usar (protege o servidor em bases grandes).')
    . $campo('Itens por módulo nas listas', '<input type="number" class="form-control form-control-sm" name="limite_lista" min="100" max="5000" step="100" value="' . (int) $C::getConfig('limite_lista') . '">', 'Máximo de itens lidos por módulo no widget de lista e no detalhamento (100 a 5.000).')
    . '</div>';
echo $card('ti ti-adjustments', 'Geral', $corpo) . $salvar . Html::closeForm(false) . '</div>';

// ---------------------------------------------------------------- E-mail
echo '<div data-aba-painel="email"' . ($aba !== 'email' ? ' hidden' : '') . '>' . $form('salvar_email', 'email');
$rem = PluginDashboardRelatorio::remetente();
$corpo = ($rem === null
        ? '<div class="dashboard-alerta dashboard-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>O GLPI não tem remetente de e-mail configurado. Informe um abaixo ou em Configurar &gt; Notificações; sem isso os relatórios não são enviados.</span></div>'
        : $explicacao('Remetente atual: <strong>' . $e($rem[0]) . '</strong>' . ($rem[1] !== '' ? ' (' . $e($rem[1]) . ')' : '') . '.'))
    . '<div class="dashboard-grade-2">'
    . $campo('Nome do remetente', '<input type="text" class="form-control form-control-sm" name="email_nome" maxlength="120" value="' . $e($C::getConfig('email_nome')) . '">', 'Vazio: o nome configurado no GLPI.')
    . $campo('E-mail do remetente', '<input type="email" class="form-control form-control-sm" name="email_remetente" maxlength="255" value="' . $e($C::getConfig('email_remetente')) . '">', 'Vazio: o remetente das notificações do GLPI.')
    . $campo('Rodapé dos relatórios', '<input type="text" class="form-control form-control-sm" name="rodape_email" maxlength="300" value="' . $e($C::getConfig('rodape_email')) . '">', 'Opcional. Ex.: nome da equipe ou contato.')
    . '</div>';
echo $card('ti ti-mail', 'E-mail dos relatórios', $corpo) . $salvar . Html::closeForm(false) . '</div>';

// ---------------------------------------------------------------- Todos os painéis
echo '<div data-aba-painel="paineis"' . ($aba !== 'paineis' ? ' hidden' : '') . '>';
$paineis = iterator_to_array($DB->request(['FROM' => $P::TABELA, 'ORDER' => 'name ASC']), false);
$agend = [];
foreach ($DB->request(['SELECT' => ['plugin_dashboard_paineis_id AS p', new \Glpi\DBAL\QueryExpression('COUNT(*) AS n')], 'FROM' => PluginDashboardAgendamento::TABELA, 'GROUPBY' => 'plugin_dashboard_paineis_id']) as $r) {
    $agend[(int) $r['p']] = (int) $r['n'];
}
$donos = $C::nomesUsuarios(array_column($paineis, 'users_id'));
$corpo = $explicacao('Todos os painéis criados, de qualquer usuário. Administradores podem abrir, alterar e excluir qualquer um.');
if (!$paineis) {
    $corpo .= '<div class="dashboard-vazio">Nenhum painel criado.</div>';
} else {
    $corpo .= '<div class="table-responsive"><table class="table table-sm table-hover dashboard-tabela"><thead><tr><th>Painel</th><th>Dono</th><th>Visibilidade</th><th class="text-end">Widgets</th><th class="text-end">Agendamentos</th><th>Atualizado</th><th class="text-end">Ações</th></tr></thead><tbody>';
    foreach ($paineis as $p) {
        $corpo .= '<tr><td><a href="' . $e($C::url('painel.php', ['id' => $p['id']])) . '">' . $e($p['name']) . '</a></td><td>' . $e($donos[(int) $p['users_id']] ?? '—') . '</td>'
            . '<td>' . $e($P::VISIBILIDADES[$p['visibilidade']] ?? '') . '</td><td class="text-end">' . count(json_decode((string) $p['widgets'], true) ?: []) . '</td>'
            . '<td class="text-end">' . ($agend[(int) $p['id']] ?? 0) . '</td><td>' . $e(Html::convDateTime((string) $p['date_mod'])) . '</td>'
            . '<td class="text-end">' . $form('excluir_painel', 'paineis') . '<input type="hidden" name="painel" value="' . (int) $p['id'] . '">'
            . '<button type="submit" class="btn btn-sm btn-ghost-danger" data-dashboard-confirmar="Excluir o painel ' . $e($p['name']) . '?" title="Excluir"><i class="ti ti-trash"></i></button>' . Html::closeForm(false) . '</td></tr>';
    }
    $corpo .= '</tbody></table></div>';
}
echo $card('ti ti-layout-dashboard', 'Painéis', $corpo) . '</div>';

// ---------------------------------------------------------------- Acesso
echo '<div data-aba-painel="acesso"' . ($aba !== 'acesso' ? ' hidden' : '') . '>';
$nomesDireitos = [READ => 'Ver painéis', CREATE => 'Criar e editar', PURGE => 'Administrar todos'];
$corpo = $explicacao('O acesso usa os direitos nativos do GLPI: Administração &gt; Perfis, aba <strong>Dashboard</strong>. Os dados de cada painel respeitam as entidades ativas de quem está vendo.')
    . '<div class="table-responsive"><table class="table table-sm table-hover dashboard-tabela"><thead><tr><th>Perfil</th>';
foreach ($nomesDireitos as $r) {
    $corpo .= '<th class="text-center">' . $e($r) . '</th>';
}
$corpo .= '<th></th></tr></thead><tbody>';
foreach ($DB->request(['SELECT' => ['pr.profiles_id', 'pr.rights', 'p.name'], 'FROM' => 'glpi_profilerights AS pr', 'INNER JOIN' => ['glpi_profiles AS p' => ['ON' => ['pr' => 'profiles_id', 'p' => 'id']]], 'WHERE' => ['pr.name' => $C::DIREITO], 'ORDER' => 'p.name ASC']) as $r) {
    $corpo .= '<tr><td>' . $e($r['name']) . '</td>';
    foreach (array_keys($nomesDireitos) as $bit) {
        $corpo .= '<td class="text-center">' . (((int) $r['rights'] & $bit) === $bit ? '<i class="ti ti-check dashboard-sim"></i>' : '<span class="dashboard-nao">—</span>') . '</td>';
    }
    $corpo .= '<td class="text-end"><a class="btn btn-sm btn-ghost-secondary" href="' . $e(Profile::getFormURLWithID((int) $r['profiles_id'])) . '&forcetab=PluginDashboardProfile$1"><i class="ti ti-edit"></i> Alterar</a></td></tr>';
}
$corpo .= '</tbody></table></div>';
echo $card('ti ti-shield-lock', 'Quem pode usar', $corpo) . '</div>';

echo '</div>';
echo '<script src="' . $e($C::urlAsset('js/config.js')) . '"></script>';
Html::footer();
