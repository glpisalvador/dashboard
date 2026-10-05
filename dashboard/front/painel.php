<?php

/**
 * Plugin Dashboard - lista de painéis (sem id) e visualização de um painel (com id).
 * Os filtros ficam na URL (compartilhar/voltar); os widgets são calculados por AJAX.
 */

Session::checkLoginUser();

global $DB, $CFG_GLPI;
$C = PluginDashboardConfig::class;
$K = PluginDashboardCatalogo::class;
$P = PluginDashboardPainel::class;
$e = [$C, 'e'];

if (!$C::pode(READ)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$id = (int) ($_GET['id'] ?? 0);
$painel = null;
if ($id > 0) {
    $painel = $P::obter($id);
    if ($painel === null || !$P::podeVer($painel)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
}

Html::header('Dashboard', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginDashboardMenu');
echo '<link rel="stylesheet" href="' . $e($C::urlAsset('css/dashboard.css')) . '">';

$visiveis = $P::visiveis();
$uid = (int) Session::getLoginUserID();
$nomesDonos = $C::nomesUsuarios(array_column($visiveis, 'users_id'));
$base = [
    'ajax'     => $C::url('ajax.php'),
    'pagina'   => $C::url('painel.php'),
    'token'    => $C::tokenCsrf(),
    'criar'    => $C::pode(CREATE),
    'catalogo' => $K::paraJs(),
    'modelos'  => array_map(fn($m) => ['rotulo' => $m[0], 'descricao' => $m[1]], $K::MODELOS),
    'visibilidades' => $P::VISIBILIDADES,
    'atualizacoes'  => $P::ATUALIZACOES,
    'perfis'   => $C::listarPerfis(),
];

// ---------------------------------------------------------------- lista de painéis
if ($painel === null) {
    echo '<div class="dashboard-inicio" data-dashboard-inicio>';
    echo '<div class="dashboard-inicio-topo"><div><h2 class="dashboard-h2"><i class="ti ti-layout-dashboard"></i> Painéis</h2>'
        . '<p class="dashboard-explicacao"><i class="ti ti-info-circle"></i><span>Abra um painel ou crie o seu a partir de um modelo. Cada painel tem seus widgets, filtros e envios por e-mail.</span></p></div>'
        . ($C::pode(CREATE) ? '<button type="button" class="btn btn-sm dashboard-btn-principal" data-dashboard-novo><i class="ti ti-plus"></i><span>Novo painel</span></button>' : '') . '</div>';
    if (!$visiveis) {
        echo '<div class="dashboard-vazio-grande"><i class="ti ti-layout-dashboard"></i><div><strong>Nenhum painel ainda.</strong><p class="mb-0">'
            . ($C::pode(CREATE) ? 'Crie o primeiro com o botão "Novo painel": os modelos já trazem os widgets prontos.' : 'Peça a quem administra o Dashboard para compartilhar um painel com o seu perfil.') . '</p></div></div>';
    }
    echo '<div class="dashboard-cartoes">';
    foreach ($visiveis as $p) {
        $proprio = (int) $p['users_id'] === $uid;
        $tipos = array_count_values(array_column($p['widgets'], 'tipo'));
        echo '<a class="card dashboard-cartao" href="' . $e($C::url('painel.php', ['id' => $p['id']])) . '">'
            . '<div class="dashboard-cartao-topo"><i class="ti ti-layout-dashboard"></i><strong>' . $e($p['name']) . '</strong></div>'
            . '<div class="dashboard-cartao-info"><span class="dashboard-selo dashboard-selo-' . ($p['visibilidade'] === 'privado' ? 'neutro' : 'info') . '">' . $e($P::VISIBILIDADES[$p['visibilidade']] ?? '') . '</span>'
            . '<span>' . count($p['widgets']) . ' widget(s)' . (isset($tipos['indicador']) ? ' · ' . $tipos['indicador'] . ' indicador(es)' : '') . '</span></div>'
            . '<div class="dashboard-cartao-rodape">' . ($proprio ? 'Seu painel' : 'De ' . $e($nomesDonos[(int) $p['users_id']] ?? '—')) . ' · atualizado ' . $e(Html::convDateTime((string) $p['date_mod'])) . '</div></a>';
    }
    echo '</div></div>';
    echo '<script type="application/json" data-dashboard-base>' . json_encode($base, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>';
    echo '<script src="' . $e($CFG_GLPI['root_doc'] . '/lib/echarts.min.js') . '"></script>';
    echo '<script src="' . $e($C::urlAsset('js/dashboard.js')) . '"></script>';
    Html::footer();
    return;
}

// ---------------------------------------------------------------- opções dos filtros
$ativas = array_map('intval', $_SESSION['glpiactiveentities'] ?? [0]);
$filhas = [];
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['entities_id' => ['>', 0]]]) as $r) {
    $filhas[(int) $r['id']] = true;
}
$entidades = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $ativas], 'ORDER' => 'completename ASC']) as $r) {
    if (!isset($filhas[(int) $r['id']])) {
        $entidades[(int) $r['id']] = (string) ($r['completename'] ?: 'Entidade raiz');
    }
}
$grupos = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'WHERE' => ['is_assign' => 1], 'ORDER' => 'completename ASC']) as $r) {
    $grupos[(int) $r['id']] = (string) $r['completename'];
}
$gruposTec = $C::ids('grupos_tecnicos');
$ocultos = $C::ids('usuarios_ocultos');
$tecIds = [];
if ($gruposTec) {
    foreach ($DB->request(['SELECT' => ['users_id'], 'DISTINCT' => true, 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $gruposTec]]) as $r) {
        $tecIds[] = (int) $r['users_id'];
    }
} else {
    foreach (['glpi_tickets_users', 'glpi_problems_users', 'glpi_changes_users'] as $t) {
        foreach ($DB->request(['SELECT' => ['users_id'], 'DISTINCT' => true, 'FROM' => $t, 'WHERE' => ['type' => 2], 'LIMIT' => 3000]) as $r) {
            $tecIds[] = (int) $r['users_id'];
        }
    }
}
$tecnicos = array_diff_key($C::nomesUsuarios($tecIds), array_flip($ocultos));
asort($tecnicos, SORT_NATURAL | SORT_FLAG_CASE);
$categorias = [];
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_itilcategories', 'ORDER' => 'completename ASC']) as $r) {
    $categorias[(int) $r['id']] = (string) $r['completename'];
}
$origens = [];
foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_requesttypes', 'WHERE' => ['is_active' => 1], 'ORDER' => 'name ASC']) as $r) {
    $origens[(int) $r['id']] = (string) $r['name'];
}
$status = [];
foreach ($K::ITIL as $classe) {
    foreach ($classe::getAllStatusArray() as $codigo => $nome) {
        $status[(int) $codigo] ??= (string) $nome;
    }
}
ksort($status);
$prioridades = [];
foreach ([6, 5, 4, 3, 2, 1] as $pr) {
    $prioridades[$pr] = CommonITILObject::getPriorityName($pr);
}

// Filtros: os da URL (?f=JSON) sobre os padrões do painel
$daUrl = json_decode((string) ($_GET['f'] ?? ''), true);
$filtro = new PluginDashboardFiltro(is_array($daUrl) ? $daUrl : $painel['filtros']);
$fa = $filtro->toArray();
$podeEditar = $P::podeEditar($painel);

// Destinatários possíveis do e-mail: usuários ativos com e-mail
$todosUsuarios = $C::listarUsuarios();
$emails = $C::emailsUsuarios(array_keys($todosUsuarios));
$destinatarios = [];
foreach ($emails as $u => $em) {
    $destinatarios[] = ['id' => $u, 'nome' => $todosUsuarios[$u], 'email' => $em];
}

$dados = $base + [
    'painel'  => [
        'id' => (int) $painel['id'], 'name' => $painel['name'], 'widgets' => $painel['widgets'], 'filtros' => $painel['filtros'], 'atualizacao' => (int) $painel['atualizacao'],
        'visibilidade' => $painel['visibilidade'], 'perfis' => $painel['perfis'], 'editar' => $podeEditar, 'dono' => $nomesDonos[(int) $painel['users_id']] ?? '',
    ],
    'filtros' => $fa,
    'paineis' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name']], array_values($visiveis)),
    'remetente' => PluginDashboardRelatorio::remetente() !== null,
    'usuarios' => $destinatarios,
    'agora'   => date('d/m/Y H:i'),
];

$ms = fn(string $nome, array $opcoes, array $sel, string $vazio, string $icone, string $rotulo) => '<div class="dashboard-filtro"><span class="dashboard-filtro-rotulo"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</span>'
    . $C::multiselect($nome, $opcoes, $sel, $vazio, ['data-dashboard-filtro' => $nome]) . '</div>';

echo '<div class="dashboard-pagina" data-dashboard-pagina>';

// Cabeçalho
echo '<div class="dashboard-cabecalho">';
echo '<div class="dashboard-titulo"><div class="dropdown"><button type="button" class="btn btn-ghost-secondary dashboard-trocar" data-bs-toggle="dropdown" aria-expanded="false"><i class="ti ti-layout-dashboard"></i><span data-dashboard-nome>' . $e($painel['name']) . '</span><i class="ti ti-chevron-down"></i></button>'
    . '<div class="dropdown-menu dashboard-menu-paineis">';
foreach ($visiveis as $p) {
    echo '<a class="dropdown-item' . ((int) $p['id'] === $id ? ' active' : '') . '" href="' . $e($C::url('painel.php', ['id' => $p['id']])) . '">' . $e($p['name']) . '</a>';
}
echo '<div class="dropdown-divider"></div><a class="dropdown-item" href="' . $e($C::url('painel.php')) . '"><i class="ti ti-layout-grid"></i> Todos os painéis</a>'
    . ($C::pode(CREATE) ? '<a class="dropdown-item" href="#" data-dashboard-novo><i class="ti ti-plus"></i> Novo painel</a>' : '') . '</div></div>'
    . '<span class="dashboard-atualizado" data-dashboard-status><span class="dashboard-giro"></span> Carregando...</span></div>';
echo '<div class="dashboard-acoes">'
    . ($podeEditar ? '<button type="button" class="btn btn-sm btn-ghost-secondary" data-dashboard-editar><i class="ti ti-layout-grid-add"></i><span>Editar layout</span></button>' : '')
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-dashboard-atualizar title="Atualizar agora"><i class="ti ti-refresh"></i></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-dashboard-pdf><i class="ti ti-file-type-pdf"></i><span>PDF</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-dashboard-email><i class="ti ti-mail"></i><span>Enviar</span></button>'
    . '<div class="dropdown"><button type="button" class="btn btn-sm btn-ghost-secondary" data-bs-toggle="dropdown" aria-expanded="false" title="Mais opções"><i class="ti ti-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">'
    . ($podeEditar ? '<a class="dropdown-item" href="#" data-dashboard-config-painel><i class="ti ti-settings"></i> Configurações do painel</a>'
        . '<a class="dropdown-item" href="#" data-dashboard-agendamentos><i class="ti ti-calendar-time"></i> Envios agendados</a>'
        . '<a class="dropdown-item" href="#" data-dashboard-salvar-filtros><i class="ti ti-filter-check"></i> Salvar filtros como padrão</a>' : '')
    . '<a class="dropdown-item" href="#" data-dashboard-historico><i class="ti ti-history"></i> Histórico de envios</a>'
    . ($C::pode(CREATE) ? '<a class="dropdown-item" href="#" data-dashboard-duplicar><i class="ti ti-copy"></i> Duplicar painel</a>' : '')
    . ($podeEditar ? '<div class="dropdown-divider"></div><a class="dropdown-item text-danger" href="#" data-dashboard-excluir><i class="ti ti-trash"></i> Excluir painel</a>' : '')
    . '</div></div></div></div>';

// Filtros
echo '<div class="card dashboard-filtros" data-dashboard-filtros>';
echo '<div class="dashboard-periodos" role="group" aria-label="Período">';
foreach ($K::PERIODOS as $k => $rotulo) {
    echo '<button type="button" class="dashboard-chip' . ($fa['periodo'] === $k ? ' ativo' : '') . '" data-dashboard-periodo="' . $e($k) . '">' . $e($rotulo) . '</button>';
}
echo '<span class="dashboard-datas" data-dashboard-datas' . ($fa['periodo'] !== 'personalizado' ? ' hidden' : '') . '>'
    . '<input type="date" class="form-control form-control-sm" data-dashboard-de value="' . $e($fa['de'] ?? '') . '"> <span>até</span> <input type="date" class="form-control form-control-sm" data-dashboard-ate value="' . $e($fa['ate'] ?? '') . '"></span>';
echo '<span class="dashboard-periodo-texto" data-dashboard-periodo-texto>' . $e($filtro->rotuloPeriodo()) . '</span>';
echo '</div>';
echo '<div class="dashboard-filtros-linha">';
echo '<div class="dashboard-filtro"><span class="dashboard-filtro-rotulo"><i class="ti ti-apps"></i> Módulos</span><div class="dashboard-modulos">';
foreach ($K::MODULOS as $k => $m) {
    echo '<label class="dashboard-opcao"><input type="checkbox" class="dashboard-check" data-dashboard-modulo value="' . $e($k) . '"' . (in_array($k, $fa['modulos'], true) ? ' checked' : '') . '><i class="' . $e($m['icone']) . '"></i> ' . $e($m['rotulo']) . '</label>';
}
echo '</div></div>';
echo '<div class="dashboard-filtro"><span class="dashboard-filtro-rotulo"><i class="ti ti-arrows-diff"></i> Comparar</span><select class="form-select form-select-sm" data-dashboard-comparar>';
foreach ($K::COMPARACOES as $k => $rotulo) {
    echo '<option value="' . $e($k) . '"' . ($fa['comparar'] === $k ? ' selected' : '') . '>' . $e($rotulo) . '</option>';
}
echo '</select></div>';
echo '<div class="dashboard-filtro"><span class="dashboard-filtro-rotulo"><i class="ti ti-progress"></i> Situação</span><select class="form-select form-select-sm" data-dashboard-situacao>';
foreach (['todos' => 'Todos', 'abertos' => 'Em aberto', 'encerrados' => 'Encerrados'] as $k => $rotulo) {
    echo '<option value="' . $k . '"' . ($fa['situacao'] === $k ? ' selected' : '') . '>' . $rotulo . '</option>';
}
echo '</select></div>';
echo '<button type="button" class="btn btn-sm btn-ghost-secondary" data-dashboard-mais-filtros><i class="ti ti-adjustments-horizontal"></i><span>Mais filtros</span><span class="dashboard-contagem" data-dashboard-contagem hidden></span></button>';
echo '<button type="button" class="btn btn-sm btn-ghost-secondary" data-dashboard-limpar><i class="ti ti-eraser"></i><span>Limpar</span></button>';
echo '</div>';
echo '<div class="dashboard-filtros-extras" data-dashboard-extras hidden>'
    . $ms('entidades', $entidades, $fa['entidades'], 'Todas as entidades ativas', 'ti ti-building', 'Entidades')
    . $ms('grupos', $grupos, $fa['grupos'], 'Todos os grupos', 'ti ti-users-group', 'Grupos atribuídos')
    . $ms('tecnicos', $tecnicos, $fa['tecnicos'], 'Todos os técnicos', 'ti ti-user-check', 'Técnicos')
    . $ms('categorias', $categorias, $fa['categorias'], 'Todas as categorias', 'ti ti-category', 'Categorias')
    . $ms('status', $status, $fa['status'], 'Todos os status', 'ti ti-circle-dot', 'Status')
    . $ms('prioridades', $prioridades, $fa['prioridades'], 'Todas as prioridades', 'ti ti-flag', 'Prioridades')
    . $ms('tipos', [1 => 'Incidente', 2 => 'Requisição'], $fa['tipos'], 'Incidentes e requisições', 'ti ti-tags', 'Tipos (chamados)')
    . $ms('origens', $origens, $fa['origens'], 'Todas as origens', 'ti ti-inbox', 'Origens (chamados)')
    . '</div>';
echo '</div>';

echo '<div class="dashboard-aviso-edicao" data-dashboard-aviso-edicao hidden><i class="ti ti-layout-grid-add"></i><span>Modo de edição: arraste os widgets pela barra de título para reordenar, use os botões para mudar o tamanho, editar ou remover.</span>'
    . '<button type="button" class="btn btn-sm dashboard-btn-principal" data-dashboard-adicionar><i class="ti ti-plus"></i><span>Adicionar widget</span></button>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-dashboard-concluir><i class="ti ti-check"></i><span>Concluir</span></button></div>';

echo '<div class="dashboard-grade" data-dashboard-grade></div>';
echo '<div class="dashboard-vazio-grande" data-dashboard-sem-widgets hidden><i class="ti ti-layout-grid-add"></i><div><strong>O painel está vazio.</strong><p class="mb-0">'
    . ($podeEditar ? 'Use "Editar layout" e depois "Adicionar widget".' : 'Quem criou o painel ainda não adicionou widgets.') . '</p></div></div>';
echo '</div>';

echo '<script type="application/json" data-dashboard-base>' . json_encode($dados, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>';
echo $C::editorModelo();
echo '<script src="' . $e($CFG_GLPI['root_doc'] . '/lib/echarts.min.js') . '"></script>';
echo '<script src="' . $e($C::urlAsset('js/dashboard.js')) . '"></script>';
if ($podeEditar) {
    echo '<script src="' . $e($C::urlAsset('js/editor.js')) . '"></script>';
}
Html::footer();
