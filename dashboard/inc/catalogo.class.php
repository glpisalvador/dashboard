<?php

/**
 * Plugin Dashboard - catálogo: módulos, métricas, dimensões, tipos de widget, gráficos,
 * períodos e modelos de painel.
 */
class PluginDashboardCatalogo
{
    public const MODULOS = [
        'Ticket' => [
            'rotulo' => 'Chamados', 'singular' => 'Chamado', 'icone' => 'ti ti-alert-circle', 'tabela' => 'glpi_tickets', 'fk' => 'tickets_id',
            'usuarios' => 'glpi_tickets_users', 'grupos' => 'glpi_groups_tickets', 'tarefas' => 'glpi_tickettasks', 'tarefa_data' => 'date',
        ],
        'Problem' => [
            'rotulo' => 'Problemas', 'singular' => 'Problema', 'icone' => 'ti ti-alert-triangle', 'tabela' => 'glpi_problems', 'fk' => 'problems_id',
            'usuarios' => 'glpi_problems_users', 'grupos' => 'glpi_groups_problems', 'tarefas' => 'glpi_problemtasks', 'tarefa_data' => 'date',
        ],
        'Change' => [
            'rotulo' => 'Mudanças', 'singular' => 'Mudança', 'icone' => 'ti ti-exchange', 'tabela' => 'glpi_changes', 'fk' => 'changes_id',
            'usuarios' => 'glpi_changes_users', 'grupos' => 'glpi_changes_groups', 'tarefas' => 'glpi_changetasks', 'tarefa_data' => 'date',
        ],
        'Project' => [
            'rotulo' => 'Projetos', 'singular' => 'Projeto', 'icone' => 'ti ti-briefcase', 'tabela' => 'glpi_projects', 'fk' => 'projects_id',
            'usuarios' => '', 'grupos' => '', 'tarefas' => 'glpi_projecttasks', 'tarefa_data' => 'date_creation',
        ],
    ];

    public const ITIL = ['Ticket', 'Problem', 'Change'];

    /**
     * Métricas: rótulo, unidade (n, pct, tempo, nota), data de referência (abertura, solucao,
     * fechamento, tarefa, avaliacao ou '' quando é retrato do momento), módulos, descrição e
     * qual sentido é melhor (alto/baixo).
     */
    public const METRICAS = [
        'abertos'      => ['Abertos', 'n', 'abertura', ['Ticket', 'Problem', 'Change', 'Project'], 'Itens abertos no período.', 'neutro'],
        'solucionados' => ['Solucionados', 'n', 'solucao', ['Ticket', 'Problem', 'Change', 'Project'], 'Itens solucionados no período (projetos: término real).', 'alto'],
        'fechados'     => ['Fechados', 'n', 'fechamento', ['Ticket', 'Problem', 'Change'], 'Itens fechados no período.', 'alto'],
        'em_aberto'    => ['Em aberto', 'n', '', ['Ticket', 'Problem', 'Change', 'Project'], 'Itens ainda não solucionados agora (abertos até o fim do período).', 'baixo'],
        'backlog'      => ['Backlog', 'n', '', ['Ticket', 'Problem', 'Change', 'Project'], 'Em aberto agora e abertos antes do início do período.', 'baixo'],
        'pendentes'    => ['Pendentes', 'n', '', ['Ticket', 'Problem', 'Change'], 'Itens com status Pendente agora.', 'baixo'],
        'parados'      => ['Parados há +24 h', 'n', '', ['Ticket', 'Problem', 'Change'], 'Em aberto e sem nenhuma atualização há mais de 24 horas.', 'baixo'],
        'estourados'   => ['SLA estourado', 'n', '', ['Ticket', 'Problem', 'Change'], 'Em aberto com o prazo de solução já vencido.', 'baixo'],
        'reabertos'    => ['Reabertos', 'n', 'abertura', ['Ticket', 'Problem', 'Change'], 'Abertos no período, já solucionados uma vez e de novo em aberto.', 'baixo'],
        'sla_tto'      => ['SLA de atendimento', 'pct', 'abertura', ['Ticket'], '% atendidos dentro do prazo (ou ainda no prazo).', 'alto'],
        'sla_ttr'      => ['SLA de solução', 'pct', 'abertura', ['Ticket', 'Problem', 'Change'], '% solucionados dentro do prazo (ou ainda no prazo).', 'alto'],
        'tma'          => ['TMA (atendimento)', 'tempo', 'abertura', ['Ticket'], 'Tempo médio até o atendimento, em horário útil (GLPI).', 'baixo'],
        'tmr'          => ['TMR (solução)', 'tempo', 'solucao', ['Ticket', 'Problem', 'Change'], 'Tempo médio até a solução, em horário útil (GLPI).', 'baixo'],
        'espera'       => ['Tempo médio pendente', 'tempo', 'solucao', ['Ticket', 'Problem', 'Change'], 'Tempo médio em espera dos itens solucionados no período.', 'baixo'],
        'tempo_acao'   => ['Tempo de ação', 'tempo', 'abertura', ['Ticket', 'Problem', 'Change'], 'Soma do tempo lançado nos itens abertos no período.', 'neutro'],
        'tarefas'      => ['Tarefas', 'n', 'tarefa', ['Ticket', 'Problem', 'Change', 'Project'], 'Tarefas registradas no período.', 'neutro'],
        'satisfacao'   => ['Satisfação', 'nota', 'avaliacao', ['Ticket'], 'Média das pesquisas de satisfação respondidas no período.', 'alto'],
    ];

    /** Dimensões: rótulo e módulos onde existem */
    public const DIMENSOES = [
        'tempo'       => ['Tempo (dia/semana/mês)', ['Ticket', 'Problem', 'Change', 'Project']],
        'status'      => ['Status', ['Ticket', 'Problem', 'Change', 'Project']],
        'modulo'      => ['Módulo', ['Ticket', 'Problem', 'Change', 'Project']],
        'tipo'        => ['Tipo (incidente/requisição)', ['Ticket']],
        'prioridade'  => ['Prioridade', ['Ticket', 'Problem', 'Change', 'Project']],
        'urgencia'    => ['Urgência', ['Ticket', 'Problem', 'Change']],
        'categoria'   => ['Categoria', ['Ticket', 'Problem', 'Change']],
        'entidade'    => ['Entidade', ['Ticket', 'Problem', 'Change', 'Project']],
        'grupo'       => ['Grupo atribuído', ['Ticket', 'Problem', 'Change', 'Project']],
        'tecnico'     => ['Técnico atribuído', ['Ticket', 'Problem', 'Change', 'Project']],
        'requerente'  => ['Requerente', ['Ticket', 'Problem', 'Change']],
        'origem'      => ['Origem da requisição', ['Ticket']],
        'localizacao' => ['Localização', ['Ticket', 'Problem', 'Change']],
        'motivo'      => ['Motivo de pendência', ['Ticket', 'Problem', 'Change']],
        'hora'        => ['Hora do dia', ['Ticket', 'Problem', 'Change', 'Project']],
        'dia_semana'  => ['Dia da semana', ['Ticket', 'Problem', 'Change', 'Project']],
    ];

    public const TIPOS_WIDGET = [
        'indicador' => ['Indicador', 'ti ti-number'],
        'grafico'   => ['Gráfico', 'ti ti-chart-bar'],
        'ranking'   => ['Ranking', 'ti ti-list-numbers'],
        'calor'     => ['Mapa de calor (hora × dia)', 'ti ti-grid-dots'],
        'tecnicos'  => ['Comparação de técnicos', 'ti ti-users'],
        'lista'     => ['Lista de itens', 'ti ti-list-details'],
    ];

    public const GRAFICOS = [
        'barras'    => 'Barras',
        'barras_h'  => 'Barras horizontais',
        'empilhado' => 'Barras empilhadas',
        'linha'     => 'Linha',
        'area'      => 'Área',
        'pizza'     => 'Pizza',
        'rosca'     => 'Rosca',
    ];

    public const PERIODOS = [
        'hoje'         => 'Hoje',
        'ontem'        => 'Ontem',
        '3d'           => '3 dias',
        '7d'           => '7 dias',
        '15d'          => '15 dias',
        '30d'          => '30 dias',
        'mes'          => 'Mês atual',
        'mes_anterior' => 'Mês anterior',
        '90d'          => 'Trimestre',
        '180d'         => '6 meses',
        'ano'          => 'Ano atual',
        '365d'         => '12 meses',
        'personalizado' => 'Personalizado',
    ];

    public const COMPARACOES = ['nenhuma' => 'Sem comparação', 'anterior' => 'Período anterior', 'ano_anterior' => 'Mesmo período do ano anterior'];

    /** Colunas da lista de itens */
    public const COLUNAS_LISTA = [
        'modulo' => 'Módulo', 'id' => 'ID', 'titulo' => 'Título', 'entidade' => 'Entidade', 'status' => 'Status', 'prioridade' => 'Prioridade',
        'categoria' => 'Categoria', 'requerente' => 'Requerente', 'tecnico' => 'Técnico', 'grupo' => 'Grupo', 'abertura' => 'Abertura',
        'solucao' => 'Solução', 'idade' => 'Tempo em aberto', 'prazo' => 'Prazo (SLA)',
    ];

    public const METRICAS_TECNICOS = ['abertos', 'solucionados', 'em_aberto', 'sla_ttr', 'sla_tto', 'tma', 'tmr', 'reabertos', 'parados', 'tarefas', 'tempo_acao'];

    public static function metrica(string $chave): ?array
    {
        $m = self::METRICAS[$chave] ?? null;
        return $m === null ? null : ['chave' => $chave, 'rotulo' => $m[0], 'unidade' => $m[1], 'data' => $m[2], 'modulos' => $m[3], 'descricao' => $m[4], 'melhor' => $m[5]];
    }

    /** Catálogo para o editor (JS) */
    public static function paraJs(): array
    {
        $metricas = [];
        foreach (self::METRICAS as $k => $m) {
            $metricas[$k] = ['rotulo' => $m[0], 'unidade' => $m[1], 'temporal' => $m[2] !== '', 'modulos' => $m[3], 'descricao' => $m[4], 'melhor' => $m[5]];
        }
        $dimensoes = [];
        foreach (self::DIMENSOES as $k => $d) {
            $dimensoes[$k] = ['rotulo' => $d[0], 'modulos' => $d[1]];
        }
        return [
            'metricas'   => $metricas,
            'dimensoes'  => $dimensoes,
            'tipos'      => array_map(fn($t) => ['rotulo' => $t[0], 'icone' => $t[1]], self::TIPOS_WIDGET),
            'graficos'   => self::GRAFICOS,
            'colunas'    => self::COLUNAS_LISTA,
            'tecnicos'   => self::METRICAS_TECNICOS,
            'modulos'    => array_map(fn($m) => ['rotulo' => $m['rotulo'], 'icone' => $m['icone']], self::MODULOS),
            'periodos'   => array_diff_key(self::PERIODOS, ['personalizado' => true]),
        ];
    }

    /** Normaliza a definição de um widget vinda do editor */
    public static function widget(array $w): ?array
    {
        $tipo = (string) ($w['tipo'] ?? '');
        if (!isset(self::TIPOS_WIDGET[$tipo])) {
            return null;
        }
        $metricas = array_values(array_filter(array_map('strval', (array) ($w['metricas'] ?? [])), fn($m) => isset(self::METRICAS[$m])));
        $dimensao = isset(self::DIMENSOES[$w['dimensao'] ?? '']) ? (string) $w['dimensao'] : '';
        $saida = [
            'id'       => preg_match('/^[a-z0-9]{4,20}$/', (string) ($w['id'] ?? '')) ? (string) $w['id'] : substr(md5(uniqid('', true)), 0, 10),
            'tipo'     => $tipo,
            'titulo'   => mb_substr(trim(strip_tags((string) ($w['titulo'] ?? ''))), 0, 120),
            'metricas' => $metricas,
            'dimensao' => $dimensao,
            'grafico'  => isset(self::GRAFICOS[$w['grafico'] ?? '']) ? (string) $w['grafico'] : 'barras',
            'limite'   => max(3, min(50, (int) ($w['limite'] ?? 10))),
            'largura'  => in_array((int) ($w['largura'] ?? 0), [2, 3, 4, 6, 8, 9, 12], true) ? (int) $w['largura'] : 4,
            'altura'   => in_array($w['altura'] ?? '', ['p', 'm', 'g'], true) ? (string) $w['altura'] : 'm',
            'colunas'  => array_values(array_filter(array_map('strval', (array) ($w['colunas'] ?? [])), fn($c) => isset(self::COLUNAS_LISTA[$c]))),
        ];
        switch ($tipo) {
            case 'indicador':
                $saida['metricas'] = array_slice($saida['metricas'] ?: ['abertos'], 0, 1);
                $saida['largura'] = in_array($saida['largura'], [2, 3, 4], true) ? $saida['largura'] : 3;
                break;
            case 'grafico':
                $saida['metricas'] = array_slice($saida['metricas'] ?: ['abertos'], 0, 4);
                $saida['dimensao'] = $saida['dimensao'] ?: 'tempo';
                break;
            case 'ranking':
                $saida['metricas'] = array_slice($saida['metricas'] ?: ['abertos'], 0, 1);
                $saida['dimensao'] = $saida['dimensao'] && $saida['dimensao'] !== 'tempo' ? $saida['dimensao'] : 'tecnico';
                break;
            case 'calor':
                $saida['metricas'] = array_slice($saida['metricas'] ?: ['abertos'], 0, 1);
                break;
            case 'tecnicos':
                $saida['metricas'] = $saida['metricas'] ?: self::METRICAS_TECNICOS;
                $saida['largura'] = 12;
                break;
            case 'lista':
                $saida['colunas'] = $saida['colunas'] ?: ['modulo', 'id', 'titulo', 'entidade', 'status', 'tecnico', 'abertura', 'idade'];
                break;
        }
        if ($saida['titulo'] === '') {
            $saida['titulo'] = self::tituloPadrao($saida);
        }
        return $saida;
    }

    public static function tituloPadrao(array $w): string
    {
        $m = array_map(fn($k) => self::METRICAS[$k][0], $w['metricas']);
        $d = $w['dimensao'] !== '' ? mb_strtolower(self::DIMENSOES[$w['dimensao']][0]) : '';
        return match ($w['tipo']) {
            'tecnicos' => 'Comparação de técnicos',
            'lista'    => 'Itens do período',
            'calor'    => ($m[0] ?? 'Abertos') . ' por hora e dia da semana',
            'indicador' => $m[0] ?? 'Indicador',
            default    => implode(' × ', $m) . ($d !== '' ? ' por ' . preg_replace('/\s*\(.*\)$/', '', $d) : ''),
        };
    }

    // =====================================================================
    // Modelos de painel
    // =====================================================================

    public const MODELOS = [
        'visao_geral' => ['Visão geral', 'Indicadores principais, fluxo do período, status, categorias e técnicos.'],
        'sla'         => ['SLA e tempos', 'Cumprimento de SLA, TMA/TMR, estourados e motivos de pendência.'],
        'tecnicos'    => ['Comparação de técnicos', 'Tabela comparativa com ranking por métrica e carga de trabalho.'],
        'tendencias'  => ['Fluxo e tendências', 'Abertos × solucionados, mapa de calor e distribuição por dia e hora.'],
        'lista'       => ['Lista de itens', 'Indicadores e a lista unificada de chamados, problemas, mudanças e projetos.'],
        'branco'      => ['Em branco', 'Comece do zero e adicione os widgets que quiser.'],
    ];

    public static function widgetsDoModelo(string $modelo): array
    {
        $k = fn(string $m, int $l = 3) => ['tipo' => 'indicador', 'metricas' => [$m], 'largura' => $l];
        $g = fn(array $m, string $d, string $gr, int $l = 6, array $extra = []) => ['tipo' => 'grafico', 'metricas' => $m, 'dimensao' => $d, 'grafico' => $gr, 'largura' => $l] + $extra;
        $lista = match ($modelo) {
            'visao_geral' => [
                $k('abertos'), $k('solucionados'), $k('em_aberto'), $k('backlog'),
                $k('sla_ttr'), $k('sla_tto'), $k('tma'), $k('tmr'),
                $g(['abertos', 'solucionados'], 'tempo', 'area', 12, ['altura' => 'm']),
                $g(['abertos'], 'status', 'rosca', 4), $g(['abertos'], 'modulo', 'pizza', 4), $g(['abertos'], 'tipo', 'rosca', 4),
                $g(['abertos'], 'categoria', 'barras_h', 6, ['limite' => 10]),
                ['tipo' => 'ranking', 'metricas' => ['abertos'], 'dimensao' => 'tecnico', 'largura' => 6, 'limite' => 10],
                $g(['abertos'], 'prioridade', 'barras', 6), $g(['abertos'], 'entidade', 'barras_h', 6, ['limite' => 10]),
            ],
            'sla' => [
                $k('sla_ttr'), $k('sla_tto'), $k('estourados'), $k('tmr'),
                $g(['sla_ttr', 'sla_tto'], 'tempo', 'linha', 12),
                $g(['sla_ttr'], 'grupo', 'barras_h', 6, ['limite' => 12]), $g(['sla_ttr'], 'tecnico', 'barras_h', 6, ['limite' => 12]),
                $g(['tmr'], 'categoria', 'barras_h', 6, ['limite' => 10]), $g(['tma'], 'grupo', 'barras_h', 6, ['limite' => 10]),
                $g(['pendentes'], 'motivo', 'rosca', 6), $g(['espera'], 'tempo', 'barras', 6),
            ],
            'tecnicos' => [
                $k('abertos'), $k('solucionados'), $k('em_aberto'), $k('parados'),
                ['tipo' => 'tecnicos', 'largura' => 12],
                $g(['solucionados', 'abertos'], 'tecnico', 'barras', 12, ['limite' => 15]),
                $g(['em_aberto'], 'tecnico', 'barras_h', 6, ['limite' => 15]), $g(['tmr'], 'tecnico', 'barras_h', 6, ['limite' => 15]),
            ],
            'tendencias' => [
                $g(['abertos', 'solucionados', 'fechados'], 'tempo', 'linha', 12, ['altura' => 'g']),
                ['tipo' => 'calor', 'metricas' => ['abertos'], 'largura' => 12],
                $g(['abertos'], 'dia_semana', 'barras', 6), $g(['abertos'], 'hora', 'barras', 6),
                ['tipo' => 'ranking', 'metricas' => ['abertos'], 'dimensao' => 'requerente', 'largura' => 6, 'limite' => 10],
                ['tipo' => 'ranking', 'metricas' => ['abertos'], 'dimensao' => 'origem', 'largura' => 6, 'limite' => 10],
            ],
            'lista' => [
                $k('abertos'), $k('em_aberto'), $k('estourados'), $k('parados'),
                ['tipo' => 'lista', 'largura' => 12, 'altura' => 'g'],
            ],
            default => [],
        };
        return array_values(array_filter(array_map([self::class, 'widget'], $lista)));
    }
}
