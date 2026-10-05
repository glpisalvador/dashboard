<?php

/**
 * Plugin Dashboard - endpoint AJAX (sempre JSON).
 * Leituras (GET): dados, widget, lista, detalhar, envios, agendamentos.
 * Gravações (POST): painel_criar, painel_salvar, painel_duplicar, painel_excluir, enviar,
 * agendamento_salvar, agendamento_excluir, agendamento_enviar.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno: ' . $erro['message']]);
    }
});

$C = PluginDashboardConfig::class;
$P = PluginDashboardPainel::class;
$A = PluginDashboardAgendamento::class;
$post = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$responder = function (array $dados) use ($C, $post): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $dados['new_token'] = $post ? $C::tokenCsrf() : '';
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$falhar = fn(string $mensagem) => $responder(['success' => false, 'mensagem' => $mensagem]);

if (!Session::getLoginUserID()) {
    $falhar('Sessão expirada. Recarregue a página.');
}
if (!$C::pode(READ)) {
    $falhar('Sem permissão para o Dashboard.');
}

$R = $_REQUEST;
$acao = (string) ($R['action'] ?? '');
$json = fn(string $k) => is_array($v = json_decode((string) ($R[$k] ?? ''), true)) ? $v : [];
/** Painel da requisição, conferindo se o usuário pode ver (e editar, se pedido) */
$painel = function (bool $editar = false) use ($R, $P, $falhar): array {
    $p = $P::obter((int) ($R['painel'] ?? $R['id'] ?? 0));
    if ($p === null || !$P::podeVer($p)) {
        $falhar('Painel não encontrado.');
    }
    if ($editar && !$P::podeEditar($p)) {
        $falhar('Você não pode alterar este painel.');
    }
    return $p;
};
$widgetDoPainel = function (array $p) use ($R, $falhar): array {
    foreach ($p['widgets'] as $w) {
        if ($w['id'] === (string) ($R['widget'] ?? '')) {
            return $w;
        }
    }
    $falhar('Widget não encontrado (salve o painel e tente de novo).');
};
$exigirPost = function () use ($post, $falhar): void {
    if (!$post) {
        $falhar('Requisição inválida.');
    }
};
$opcoesLista = fn() => [
    'pagina' => (int) ($R['pagina'] ?? 1), 'por_pagina' => (int) ($R['por_pagina'] ?? 15), 'ordem' => (string) ($R['ordem'] ?? ''),
    'direcao' => (string) ($R['direcao'] ?? 'desc'), 'busca' => (string) ($R['busca'] ?? ''), 'metrica' => (string) ($R['metrica'] ?? ''),
];

try {
    switch ($acao) {
        // ------------------------------------------------------------ leituras
        case 'dados':
            $p = $painel();
            @set_time_limit(120);
            $filtro = new PluginDashboardFiltro($json('f') ?: $p['filtros']);
            $motor = new PluginDashboardMotor($filtro);
            $saida = [];
            $so = (string) ($R['widget'] ?? '');
            foreach ($p['widgets'] as $w) {
                if ($so === '' || $so === $w['id']) {
                    $saida[$w['id']] = $motor->widget($w);
                }
            }
            $c = $filtro->intervaloComparacao();
            $responder(['success' => true, 'widgets' => $saida, 'periodo' => $filtro->rotuloPeriodo(), 'comparacao' => $c ? PluginDashboardFiltro::rotuloIntervalo($c) : '',
                'filtros' => $filtro->toArray(), 'hora' => date('H:i:s')]);

        case 'widget':
            $p = $painel(true);
            $w = PluginDashboardCatalogo::widget($json('w'));
            if ($w === null) {
                $falhar('Widget inválido.');
            }
            $motor = new PluginDashboardMotor(new PluginDashboardFiltro($json('f') ?: $p['filtros']));
            $responder(['success' => true, 'widget' => $w, 'dados' => $motor->widget($w)]);

        case 'lista':
            $p = $painel();
            $w = $widgetDoPainel($p);
            $motor = new PluginDashboardMotor(new PluginDashboardFiltro($json('f') ?: $p['filtros']));
            $responder(['success' => true, 'dados' => $motor->lista($w, $opcoesLista())]);

        case 'detalhar':
            $p = $painel();
            $w = $widgetDoPainel($p);
            $motor = new PluginDashboardMotor(new PluginDashboardFiltro($json('f') ?: $p['filtros']));
            $chave = isset($R['chave']) && $R['chave'] !== '' ? (string) $R['chave'] : null;
            $responder(['success' => true, 'dados' => $motor->detalhar($w, $chave, !empty($R['comparacao']), $opcoesLista())]);

        case 'envios':
            $p = $painel();
            $responder(['success' => true, 'envios' => PluginDashboardRelatorio::envios((int) $p['id'])]);

        case 'agendamentos':
            $p = $painel(true);
            $responder(['success' => true, 'agendamentos' => $A::listar((int) $p['id']), 'frequencias' => $A::FREQUENCIAS, 'dias' => $A::DIAS_SEMANA]);

        // ------------------------------------------------------------ painéis
        case 'painel_criar':
            $exigirPost();
            if (!$C::pode(CREATE)) {
                $falhar('Você não pode criar painéis.');
            }
            $r = $P::criar(['name' => $R['name'] ?? '', 'modelo' => $R['modelo'] ?? 'visao_geral', 'visibilidade' => $R['visibilidade'] ?? 'privado', 'perfis' => (array) ($R['perfis'] ?? [])]);
            is_string($r) ? $falhar($r) : $responder(['success' => true, 'id' => $r, 'url' => $C::url('painel.php', ['id' => $r])]);

        case 'painel_salvar':
            $exigirPost();
            $p = $painel(true);
            $dados = [];
            foreach (['name', 'visibilidade', 'atualizacao'] as $k) {
                if (isset($R[$k])) {
                    $dados[$k] = $R[$k];
                }
            }
            if (isset($R['visibilidade'])) {
                $dados['perfis'] = (array) ($R['perfis'] ?? []);
            }
            if (isset($R['widgets'])) {
                $dados['widgets'] = $json('widgets');
            }
            if (isset($R['filtros'])) {
                $dados['filtros'] = $json('filtros');
            }
            $erro = $P::salvar((int) $p['id'], $dados);
            $erro !== null ? $falhar($erro) : $responder(['success' => true, 'painel' => $P::obter((int) $p['id'])]);

        case 'painel_duplicar':
            $exigirPost();
            $p = $painel();
            $r = $P::duplicar((int) $p['id']);
            is_string($r) ? $falhar($r) : $responder(['success' => true, 'id' => $r, 'url' => $C::url('painel.php', ['id' => $r])]);

        case 'painel_excluir':
            $exigirPost();
            $p = $painel(true);
            $erro = $P::excluir((int) $p['id']);
            if ($erro === null) {
                Session::addMessageAfterRedirect('Painel excluído.', false, INFO);
            }
            $erro !== null ? $falhar($erro) : $responder(['success' => true, 'url' => $C::url('painel.php')]);

        // ------------------------------------------------------------ e-mail
        case 'enviar':
            $exigirPost();
            $p = $painel();
            $filtro = new PluginDashboardFiltro($json('f') ?: $p['filtros']);
            $destinos = preg_split('/[\s,;]+/', (string) ($R['emails'] ?? '')) ?: [];
            $invalidos = array_values(array_filter($destinos, fn($e) => trim($e) !== '' && !filter_var(trim($e), FILTER_VALIDATE_EMAIL)));
            if ($invalidos) {
                $falhar('E-mail inválido: ' . implode(', ', $invalidos));
            }
            $destinos = array_merge(array_filter(array_map('trim', $destinos)), array_values($C::emailsUsuarios((array) ($R['usuarios'] ?? []))));
            $anexo = null;
            $f = $_FILES['pdf'] ?? null;
            if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name'])) {
                if ((int) $f['size'] > 20 * 1024 * 1024 || mime_content_type((string) $f['tmp_name']) !== 'application/pdf') {
                    $falhar('O PDF gerado é inválido ou passa de 20 MB.');
                }
                $anexo = ['caminho' => (string) $f['tmp_name'], 'nome' => preg_replace('/[^\w\-. ]+/u', '_', (string) ($R['pdf_nome'] ?? 'dashboard.pdf'))];
            } elseif (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $falhar('O PDF não pôde ser recebido (limite do servidor: ' . ini_get('upload_max_filesize') . '). Envie sem o PDF ou com menos widgets.');
            }
            @set_time_limit(180);
            $r = PluginDashboardRelatorio::enviar($p, $filtro, $destinos, (string) ($R['assunto'] ?? ''), (string) ($R['mensagem'] ?? ''), $anexo);
            $responder(['success' => $r['ok']] + $r);

        // ------------------------------------------------------------ agendamentos
        case 'agendamento_salvar':
            $exigirPost();
            $p = $painel(true);
            $erro = $A::salvar((int) $p['id'], (int) ($R['agendamento'] ?? 0), $R);
            $erro !== null ? $falhar($erro) : $responder(['success' => true, 'agendamentos' => $A::listar((int) $p['id'])]);

        case 'agendamento_excluir':
            $exigirPost();
            $p = $painel(true);
            $erro = $A::excluir((int) $p['id'], (int) ($R['agendamento'] ?? 0));
            $erro !== null ? $falhar($erro) : $responder(['success' => true, 'agendamentos' => $A::listar((int) $p['id'])]);

        case 'agendamento_enviar':
            $exigirPost();
            $p = $painel(true);
            $a = $A::obter((int) ($R['agendamento'] ?? 0));
            if ($a === null || (int) $a['plugin_dashboard_paineis_id'] !== (int) $p['id']) {
                $falhar('Agendamento não encontrado.');
            }
            @set_time_limit(180);
            $r = $A::executar($a);
            $responder(['success' => $r['ok'], 'mensagem' => $r['mensagem'], 'agendamentos' => $A::listar((int) $p['id'])]);

        default:
            $falhar('Ação desconhecida.');
    }
} catch (\Glpi\Exception\RedirectException $e) {
    throw $e;
} catch (\Throwable $e) {
    Toolbox::logInFile('dashboard', 'Erro no ajax (' . $acao . '): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    $falhar('Erro: ' . $e->getMessage());
}
