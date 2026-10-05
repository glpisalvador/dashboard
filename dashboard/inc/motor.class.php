<?php

/**
 * Plugin Dashboard - motor de cálculo.
 *
 * Toda métrica vira, por módulo, uma consulta agregada que devolve numerador e denominador por
 * chave da dimensão; os módulos são somados e o valor final sai conforme a unidade (contagem,
 * percentual, tempo médio, nota). Assim qualquer métrica combina com qualquer dimensão, o
 * detalhamento reaproveita a mesma consulta (ids em vez de agregados) e a comparação de períodos
 * é só a mesma conta com outro intervalo.
 */
class PluginDashboardMotor
{
    private PluginDashboardFiltro $f;
    private array $cacheNomes = [];

    private const DIAS = [2 => 'Seg', 3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb', 1 => 'Dom'];

    public function __construct(PluginDashboardFiltro $filtro)
    {
        $this->f = $filtro;
    }

    // =====================================================================
    // SQL
    // =====================================================================

    private static function q(string $v): string
    {
        global $DB;
        return $DB->quote($v);
    }

    /** Coluna de data da métrica no módulo */
    private static function colunaData(array $m, string $modulo): string
    {
        return match ($m['data']) {
            'solucao'    => $modulo === 'Project' ? 't.real_end_date' : 't.solvedate',
            'fechamento' => 't.closedate',
            'tarefa'     => 'k.`' . PluginDashboardCatalogo::MODULOS[$modulo]['tarefa_data'] . '`',
            'avaliacao'  => 's.date_answered',
            default      => 't.date',
        };
    }

    /** Expressão da chave e junção da dimensão no módulo, ou null se não se aplica */
    private function dimensao(string $dim, string $modulo, string $dc, array $intervalo): ?array
    {
        if ($dim === '') {
            return ['chave' => "''", 'join' => ''];
        }
        if ($dim !== 'calor' && !in_array($modulo, PluginDashboardCatalogo::DIMENSOES[$dim][1], true)) {
            return null;
        }
        $M = PluginDashboardCatalogo::MODULOS[$modulo];
        $L = [PluginDashboardFiltro::class, 'lista'];
        $proj = $modulo === 'Project';
        switch ($dim) {
            case 'tempo':
                $g = $this->f->granularidade($intervalo);
                $chave = match ($g) {
                    'hora'   => "DATE_FORMAT($dc, '%Y-%m-%d %H:00')",
                    'semana' => "DATE(DATE_SUB($dc, INTERVAL WEEKDAY($dc) DAY))",
                    'mes'    => "DATE_FORMAT($dc, '%Y-%m-01')",
                    default  => "DATE($dc)",
                };
                return ['chave' => $chave, 'join' => ''];
            case 'status':
                return ['chave' => $proj ? 't.projectstates_id' : 't.status', 'join' => ''];
            case 'modulo':
                return ['chave' => self::q($modulo), 'join' => ''];
            case 'tipo':
                return ['chave' => 't.type', 'join' => ''];
            case 'prioridade':
                return ['chave' => 't.priority', 'join' => ''];
            case 'urgencia':
                return ['chave' => 't.urgency', 'join' => ''];
            case 'categoria':
                return ['chave' => 't.itilcategories_id', 'join' => ''];
            case 'entidade':
                return ['chave' => 't.entities_id', 'join' => ''];
            case 'localizacao':
                return ['chave' => 't.locations_id', 'join' => ''];
            case 'origem':
                return ['chave' => 't.requesttypes_id', 'join' => ''];
            case 'grupo':
                if ($proj) {
                    return ['chave' => 't.groups_id', 'join' => ''];
                }
                return ['chave' => 'COALESCE(dg.groups_id, 0)', 'join' => "LEFT JOIN `{$M['grupos']}` dg ON dg.`{$M['fk']}` = t.id AND dg.type = 2"
                    . ($this->f->grupos ? ' AND dg.groups_id IN (' . $L($this->f->grupos) . ')' : '')];
            case 'tecnico':
                if ($proj) {
                    return ['chave' => 't.users_id', 'join' => ''];
                }
                return ['chave' => 'COALESCE(du.users_id, 0)', 'join' => "LEFT JOIN `{$M['usuarios']}` du ON du.`{$M['fk']}` = t.id AND du.type = 2"
                    . ($this->f->tecnicos ? ' AND du.users_id IN (' . $L($this->f->tecnicos) . ')' : '')];
            case 'requerente':
                return ['chave' => 'COALESCE(dr.users_id, 0)', 'join' => "LEFT JOIN `{$M['usuarios']}` dr ON dr.`{$M['fk']}` = t.id AND dr.type = 1"];
            case 'motivo':
                return ['chave' => 'COALESCE(dp.pendingreasons_id, 0)', 'join' => 'LEFT JOIN glpi_pendingreasons_items dp ON dp.itemtype = ' . self::q($modulo) . ' AND dp.items_id = t.id'];
            case 'hora':
                return ['chave' => "HOUR($dc)", 'join' => ''];
            case 'dia_semana':
                return ['chave' => "DAYOFWEEK($dc)", 'join' => ''];
            case 'calor':
                return ['chave' => "CONCAT(DAYOFWEEK($dc), '-', HOUR($dc))", 'join' => ''];
        }
        return null;
    }

    /**
     * Partes da consulta da métrica no módulo: from, where, num, den. Null se não se aplica.
     */
    private function partes(string $metrica, string $modulo, array $intervalo): ?array
    {
        $m = PluginDashboardCatalogo::metrica($metrica);
        if ($m === null || !in_array($modulo, $m['modulos'], true) || !in_array($modulo, $this->f->modulos, true)) {
            return null;
        }
        $M = PluginDashboardCatalogo::MODULOS[$modulo];
        [$ini, $fim] = $intervalo;
        $aberto = PluginDashboardFiltro::condAberto($modulo);
        $from = "FROM `{$M['tabela']}` t";
        $where = [$this->f->condicoes($modulo)];
        $dc = self::colunaData($m, $modulo);
        if ($m['data'] !== '') {
            $where[] = "$dc BETWEEN " . self::q($ini) . ' AND ' . self::q($fim);
        }
        $num = 'COUNT(DISTINCT t.id)';
        $den = '1';
        switch ($metrica) {
            case 'solucionados':
            case 'fechados':
                $where[] = "$dc IS NOT NULL";
                break;
            case 'em_aberto':
                $where[] = $aberto;
                $where[] = 't.date <= ' . self::q($fim);
                break;
            case 'backlog':
                $where[] = $aberto;
                $where[] = 't.date < ' . self::q($ini);
                break;
            case 'pendentes':
                $where[] = 't.status = ' . CommonITILObject::WAITING;
                $where[] = 't.date <= ' . self::q($fim);
                break;
            case 'parados':
                $where[] = $aberto;
                $where[] = 't.date_mod < (NOW() - INTERVAL 24 HOUR)';
                $where[] = 't.date <= ' . self::q($fim);
                break;
            case 'estourados':
                $where[] = $aberto;
                $where[] = 't.time_to_resolve IS NOT NULL AND t.time_to_resolve < NOW()';
                break;
            case 'reabertos':
                $where[] = 't.solvedate IS NOT NULL';
                $where[] = $aberto;
                break;
            case 'sla_tto':
                $where[] = 't.time_to_own IS NOT NULL';
                $num = 'SUM(CASE WHEN (t.takeintoaccountdate IS NOT NULL AND t.takeintoaccountdate <= t.time_to_own) OR (t.takeintoaccountdate IS NULL AND NOW() <= t.time_to_own) THEN 1 ELSE 0 END)';
                $den = 'COUNT(*)';
                break;
            case 'sla_ttr':
                $where[] = 't.time_to_resolve IS NOT NULL';
                $num = 'SUM(CASE WHEN (t.solvedate IS NOT NULL AND t.solvedate <= t.time_to_resolve) OR (t.solvedate IS NULL AND NOW() <= t.time_to_resolve) THEN 1 ELSE 0 END)';
                $den = 'COUNT(*)';
                break;
            case 'tma':
                $where[] = 't.takeintoaccount_delay_stat > 0';
                $num = 'SUM(t.takeintoaccount_delay_stat)';
                $den = 'COUNT(*)';
                break;
            case 'tmr':
                $where[] = 't.solve_delay_stat > 0';
                $num = 'SUM(t.solve_delay_stat)';
                $den = 'COUNT(*)';
                break;
            case 'espera':
                $where[] = 't.solvedate IS NOT NULL';
                $num = 'SUM(t.waiting_duration)';
                $den = 'COUNT(*)';
                break;
            case 'tempo_acao':
                $num = 'SUM(t.actiontime)';
                break;
            case 'tarefas':
                $from = "FROM `{$M['tarefas']}` k INNER JOIN `{$M['tabela']}` t ON t.id = k.`{$M['fk']}`";
                if ($modulo === 'Project') {
                    $where[] = 'k.is_deleted = 0';
                }
                $num = 'COUNT(DISTINCT k.id)';
                break;
            case 'satisfacao':
                $from = 'FROM glpi_ticketsatisfactions s INNER JOIN glpi_tickets t ON t.id = s.tickets_id';
                $where[] = 's.satisfaction IS NOT NULL';
                $num = 'SUM(s.satisfaction)';
                $den = 'COUNT(*)';
                break;
        }
        return ['from' => $from, 'where' => implode(' AND ', $where), 'num' => $num, 'den' => $den, 'dc' => $dc, 'm' => $m];
    }

    private static function linhas(string $sql): array
    {
        global $DB;
        $r = $DB->doQuery($sql);
        $saida = [];
        while ($r && ($l = $DB->fetchAssoc($r))) {
            $saida[] = $l;
        }
        return $saida;
    }

    /**
     * Valores agregados por chave final da dimensão, somando os módulos:
     * [chave => ['rotulo', 'ordem', 'num', 'den']]
     */
    public function agrupar(string $metrica, string $dim, ?array $intervalo = null): array
    {
        $intervalo ??= $this->f->intervalo();
        $brutos = [];
        foreach ($this->f->modulos as $modulo) {
            $p = $this->partes($metrica, $modulo, $intervalo);
            if ($p === null) {
                continue;
            }
            $d = $this->dimensao($dim, $modulo, $p['dc'], $intervalo);
            if ($d === null) {
                continue;
            }
            $sql = "SELECT {$d['chave']} AS chave, {$p['num']} AS num, {$p['den']} AS den {$p['from']} {$d['join']} WHERE {$p['where']}" . ($dim !== '' ? ' GROUP BY chave' : '');
            foreach (self::linhas($sql) as $l) {
                $brutos[$modulo][(string) $l['chave']] = [(float) $l['num'], (float) $l['den']];
            }
        }
        $final = [];
        foreach ($brutos as $modulo => $lista) {
            $mapa = $this->rotulos($dim, $modulo, array_keys($lista));
            foreach ($lista as $bruta => [$num, $den]) {
                [$chave, $rotulo, $ordem] = $mapa[$bruta] ?? [$bruta, $bruta, $bruta];
                if ($chave === null) {
                    continue;
                }
                $final[$chave] ??= ['rotulo' => $rotulo, 'ordem' => $ordem, 'num' => 0.0, 'den' => 0.0];
                $final[$chave]['num'] += $num;
                $final[$chave]['den'] += $den;
            }
        }
        return $final;
    }

    /** Ids dos itens por trás de um valor (detalhamento): [modulo => [ids]] */
    public function ids(string $metrica, string $dim, ?string $chaveFinal, ?array $intervalo = null, int $limite = 2000): array
    {
        $intervalo ??= $this->f->intervalo();
        $saida = [];
        foreach ($this->f->modulos as $modulo) {
            $p = $this->partes($metrica, $modulo, $intervalo);
            $d = $p ? $this->dimensao($dim, $modulo, $p['dc'], $intervalo) : null;
            if ($p === null || $d === null) {
                continue;
            }
            $linhas = self::linhas("SELECT DISTINCT t.id AS id, {$d['chave']} AS chave {$p['from']} {$d['join']} WHERE {$p['where']} LIMIT " . (int) $limite);
            $mapa = $dim !== '' ? $this->rotulos($dim, $modulo, array_unique(array_column($linhas, 'chave'))) : [];
            foreach ($linhas as $l) {
                $final = $dim === '' ? null : ($mapa[(string) $l['chave']][0] ?? (string) $l['chave']);
                if ($chaveFinal === null || (string) $final === $chaveFinal) {
                    $saida[$modulo][(int) $l['id']] = true;
                }
            }
        }
        return array_map('array_keys', $saida);
    }

    // =====================================================================
    // Rótulos das chaves
    // =====================================================================

    private function nomes(string $tabela, array $ids, string $campo = 'completename'): array
    {
        global $DB;
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        $faltam = array_diff($ids, array_keys($this->cacheNomes[$tabela] ?? []));
        if ($faltam) {
            foreach ($DB->request(['SELECT' => ['id', $campo], 'FROM' => $tabela, 'WHERE' => ['id' => array_values($faltam)]]) as $r) {
                $this->cacheNomes[$tabela][(int) $r['id']] = (string) $r[$campo];
            }
        }
        return $this->cacheNomes[$tabela] ?? [];
    }

    /** [chave bruta => [chave final (null = descartar), rótulo, ordem]] */
    private function rotulos(string $dim, string $modulo, array $chaves): array
    {
        $mapa = [];
        $nomeUsuarios = fn() => PluginDashboardConfig::nomesUsuarios($chaves);
        $ocultos = $dim === 'tecnico' || $dim === 'requerente' ? PluginDashboardConfig::ids('usuarios_ocultos') : [];
        $tabelas = ['categoria' => ['glpi_itilcategories', 'Sem categoria'], 'grupo' => ['glpi_groups', 'Sem grupo'], 'localizacao' => ['glpi_locations', 'Sem localização'],
            'origem' => ['glpi_requesttypes', 'Sem origem', 'name'], 'motivo' => ['glpi_pendingreasons', 'Sem motivo', 'name']];
        $nomes = match (true) {
            isset($tabelas[$dim]) => $this->nomes($tabelas[$dim][0], $chaves, $tabelas[$dim][2] ?? 'completename'),
            $dim === 'entidade' => $this->nomes('glpi_entities', $chaves),
            $dim === 'tecnico', $dim === 'requerente' => $nomeUsuarios(),
            $dim === 'status' && $modulo === 'Project' => $this->nomes('glpi_projectstates', $chaves, 'name'),
            default => [],
        };
        foreach ($chaves as $k) {
            $k = (string) $k;
            $i = (int) $k;
            $mapa[$k] = match ($dim) {
                ''          => ['total', 'Total', 0],
                'tempo'     => [$k, $k, $k],
                'status'    => $modulo === 'Project'
                    ? [$nomes[$i] ?? 'Sem status', $nomes[$i] ?? 'Sem status', 100 + $i]
                    : [$modulo::getStatus($i), $modulo::getStatus($i), $i],
                'modulo'    => [$k, PluginDashboardCatalogo::MODULOS[$k]['rotulo'] ?? $k, array_search($k, array_keys(PluginDashboardCatalogo::MODULOS), true)],
                'tipo'      => [$k, $i === 1 ? 'Incidente' : ($i === 2 ? 'Requisição' : 'Sem tipo'), $i],
                'prioridade' => [$k, $i > 0 ? CommonITILObject::getPriorityName($i) : 'Sem prioridade', -$i],
                'urgencia'  => [$k, $i > 0 ? CommonITILObject::getUrgencyName($i) : 'Sem urgência', -$i],
                'entidade'  => [$k, $i === 0 ? ($nomes[0] ?? 'Entidade raiz') : ($nomes[$i] ?? '#' . $i), $k],
                'tecnico', 'requerente' => in_array($i, $ocultos, true) ? [null, '', 0] : [$k, $i === 0 ? ($dim === 'tecnico' ? 'Sem técnico' : 'Sem requerente') : ($nomes[$i] ?? '#' . $i), $k],
                'hora'      => [$k, str_pad($k, 2, '0', STR_PAD_LEFT) . 'h', $i],
                'dia_semana' => [$k, self::DIAS[$i] ?? $k, $i === 1 ? 8 : $i],
                'calor'     => [$k, $k, $k],
                default     => [$k, $i === 0 ? ($tabelas[$dim][1] ?? 'Sem valor') : ($nomes[$i] ?? '#' . $i), $k],
            };
        }
        if ($dim === 'entidade' && in_array('0', array_map('strval', $chaves), true)) {
            $mapa['0'][1] = 'Entidade raiz';
        }
        return $mapa;
    }

    // =====================================================================
    // Valores
    // =====================================================================

    /** Valor final conforme a unidade (null = sem base para calcular) */
    public static function valor(string $metrica, float $num, float $den): ?float
    {
        $m = PluginDashboardCatalogo::metrica($metrica);
        return match ($m['unidade']) {
            'pct'   => $den > 0 ? round($num / $den * 100, 1) : null,
            'tempo' => $metrica === 'tempo_acao' ? $num : ($den > 0 ? $num / $den : null),
            'nota'  => $den > 0 ? round($num / $den, 2) : null,
            default => $num,
        };
    }

    public static function formatar(string $metrica, ?float $v): string
    {
        if ($v === null) {
            return '—';
        }
        return match (PluginDashboardCatalogo::METRICAS[$metrica][1]) {
            'pct'   => number_format($v, 1, ',', '.') . '%',
            'tempo' => PluginDashboardConfig::formatarTempo($v),
            'nota'  => number_format($v, 2, ',', '.'),
            default => number_format($v, 0, ',', '.'),
        };
    }

    /** Valor para o eixo dos gráficos (tempo em horas) */
    private static function paraGrafico(string $metrica, ?float $v): ?float
    {
        if ($v === null) {
            return null;
        }
        return PluginDashboardCatalogo::METRICAS[$metrica][1] === 'tempo' ? round($v / 3600, 1) : round($v, 2);
    }

    public function total(string $metrica, ?array $intervalo = null): ?float
    {
        $g = $this->agrupar($metrica, '', $intervalo);
        $t = $g['total'] ?? ['num' => 0.0, 'den' => 0.0];
        return self::valor($metrica, $t['num'], $t['den']);
    }

    /** Chaves do eixo de tempo do intervalo (preenche os dias sem dados) */
    private function baldes(array $intervalo): array
    {
        $g = $this->f->granularidade($intervalo);
        $ini = strtotime($intervalo[0]);
        $fim = strtotime($intervalo[1]);
        $lista = [];
        switch ($g) {
            case 'hora':
                for ($t = strtotime(date('Y-m-d H:00:00', $ini)); $t <= $fim; $t += 3600) {
                    $lista[date('Y-m-d H:00', $t)] = date('H\h', $t) . (date('Y-m-d', $ini) !== date('Y-m-d', $fim) ? ' ' . date('d/m', $t) : '');
                }
                break;
            case 'semana':
                for ($t = strtotime('monday this week', $ini); $t <= $fim; $t = strtotime('+1 week', $t)) {
                    $lista[date('Y-m-d', $t)] = 'Sem. ' . date('d/m', $t);
                }
                break;
            case 'mes':
                for ($t = strtotime(date('Y-m-01', $ini)); $t <= $fim; $t = strtotime('+1 month', $t)) {
                    $lista[date('Y-m-01', $t)] = date('m/Y', $t);
                }
                break;
            default:
                for ($t = strtotime(date('Y-m-d', $ini)); $t <= $fim; $t = strtotime('+1 day', $t)) {
                    $lista[date('Y-m-d', $t)] = date('d/m', $t);
                }
        }
        return $lista;
    }

    /** Série ordenada: [[chave, rótulo, valor]] (tempo com todos os baldes; demais ordenados) */
    public function serie(string $metrica, string $dim, ?array $intervalo = null, int $limite = 0): array
    {
        $intervalo ??= $this->f->intervalo();
        $g = $this->agrupar($metrica, $dim, $intervalo);
        $itens = [];
        if ($dim === 'tempo') {
            foreach ($this->baldes($intervalo) as $k => $rotulo) {
                $itens[] = [(string) $k, $rotulo, isset($g[$k]) ? self::valor($metrica, $g[$k]['num'], $g[$k]['den']) : (PluginDashboardCatalogo::METRICAS[$metrica][1] === 'n' ? 0.0 : null)];
            }
            return $itens;
        }
        foreach ($g as $k => $v) {
            $itens[] = [(string) $k, $v['rotulo'], self::valor($metrica, $v['num'], $v['den']), $v['ordem'], $v['num']];
        }
        $natural = in_array($dim, ['hora', 'dia_semana', 'prioridade', 'urgencia', 'status', 'modulo', 'tipo'], true);
        usort($itens, $natural
            ? fn($a, $b) => $a[3] <=> $b[3]
            : fn($a, $b) => [$b[2] ?? -1, $a[1]] <=> [$a[2] ?? -1, $b[1]]);
        if ($limite > 0 && count($itens) > $limite && !$natural) {
            $resto = array_slice($itens, $limite);
            $itens = array_slice($itens, 0, $limite);
            if (PluginDashboardCatalogo::METRICAS[$metrica][1] === 'n') {
                $itens[] = ['__outros__', 'Outros (' . count($resto) . ')', (float) array_sum(array_column($resto, 2)), 0, 0];
            }
        }
        return array_map(fn($i) => [$i[0], $i[1], $i[2]], $itens);
    }

    // =====================================================================
    // Widgets
    // =====================================================================

    public function widget(array $w, array $opcoes = []): array
    {
        try {
            return match ($w['tipo']) {
                'indicador' => $this->indicador($w),
                'grafico'   => $this->grafico($w),
                'ranking'   => $this->ranking($w),
                'calor'     => $this->calor($w),
                'tecnicos'  => $this->tecnicos($w),
                'lista'     => $this->lista($w, $opcoes),
                default     => ['erro' => 'Tipo de widget desconhecido.'],
            };
        } catch (\Throwable $e) {
            Toolbox::logInFile('dashboard', 'Widget ' . ($w['id'] ?? '?') . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
            return ['erro' => 'Não foi possível calcular este widget.'];
        }
    }

    private function semModulo(array $metricas): ?string
    {
        foreach ($metricas as $m) {
            if (array_intersect(PluginDashboardCatalogo::METRICAS[$m][3], $this->f->modulos)) {
                return null;
            }
        }
        $nomes = implode(', ', array_map(fn($mod) => PluginDashboardCatalogo::MODULOS[$mod]['rotulo'], PluginDashboardCatalogo::METRICAS[$metricas[0]][3]));
        return 'Esta métrica vale só para: ' . $nomes . '.';
    }

    private function indicador(array $w): array
    {
        $m = $w['metricas'][0];
        if ($aviso = $this->semModulo([$m])) {
            return ['aviso' => $aviso];
        }
        $v = $this->total($m);
        $saida = ['valor' => $v, 'formatado' => self::formatar($m, $v), 'unidade' => PluginDashboardCatalogo::METRICAS[$m][1], 'melhor' => PluginDashboardCatalogo::METRICAS[$m][5],
            'descricao' => PluginDashboardCatalogo::METRICAS[$m][4]];
        // Retratos do momento (em aberto, backlog...) não têm valor "antes" comparável
        if (($c = $this->f->intervaloComparacao()) && PluginDashboardCatalogo::METRICAS[$m][2] !== '') {
            $a = $this->total($m, $c);
            $saida['anterior'] = $a;
            $saida['anterior_formatado'] = self::formatar($m, $a);
            $saida['variacao'] = ($a !== null && $v !== null && $a != 0.0) ? round(($v - $a) / abs($a) * 100, 1) : null;
        }
        if (PluginDashboardCatalogo::METRICAS[$m][2] !== '') {
            $saida['serie'] = array_map(fn($i) => self::paraGrafico($m, $i[2]), $this->serie($m, 'tempo'));
        }
        return $saida;
    }

    private function grafico(array $w): array
    {
        $dim = $w['dimensao'];
        if ($aviso = $this->semModulo($w['metricas'])) {
            return ['aviso' => $aviso];
        }
        $series = [];
        $listas = [];
        foreach ($w['metricas'] as $m) {
            if ($dim === 'tempo' && PluginDashboardCatalogo::METRICAS[$m][2] === '') {
                continue;
            }
            $listas[$m] = $this->serie($m, $dim, null, count($w['metricas']) === 1 ? $w['limite'] : 0);
        }
        // Várias métricas: as categorias são a união de todas (o maior valor de cada uma decide a ordem)
        $rotuloDe = [];
        $peso = [];
        foreach ($listas as $s) {
            foreach ($s as $i) {
                $rotuloDe[$i[0]] ??= $i[1];
                $peso[$i[0]] = max($peso[$i[0]] ?? -1.0, (float) ($i[2] ?? -1));
            }
        }
        $chaves = array_map('strval', array_keys($rotuloDe));
        $natural = in_array($dim, ['tempo', 'hora', 'dia_semana', 'prioridade', 'urgencia', 'status', 'modulo', 'tipo'], true);
        if (count($listas) > 1 && !$natural) {
            usort($chaves, fn($a, $b) => [$peso[$b], $rotuloDe[$a]] <=> [$peso[$a], $rotuloDe[$b]]);
            $chaves = array_slice($chaves, 0, $w['limite']);
        }
        $rotulos = array_map(fn($k) => $rotuloDe[$k], $chaves);
        foreach ($listas as $m => $s) {
            $porChave = [];
            foreach ($s as $i) {
                $porChave[$i[0]] = $i[2];
            }
            // Categoria que só existe em outra métrica: contagem vale 0; média/percentual fica sem valor
            $vazio = PluginDashboardCatalogo::METRICAS[$m][1] === 'n' ? 0.0 : null;
            $series[] = ['nome' => PluginDashboardCatalogo::METRICAS[$m][0], 'metrica' => $m, 'unidade' => PluginDashboardCatalogo::METRICAS[$m][1],
                'valores' => array_map(fn($k) => self::paraGrafico($m, $porChave[$k] ?? $vazio), $chaves),
                'formatados' => array_map(fn($k) => self::formatar($m, $porChave[$k] ?? $vazio), $chaves)];
        }
        if (!$series) {
            return ['aviso' => 'Métricas de situação atual (em aberto, backlog...) não têm evolução no tempo: escolha outra dimensão.'];
        }
        $saida = ['categorias' => $rotulos, 'chaves' => $chaves, 'series' => $series];
        $c = $this->f->intervaloComparacao();
        if ($c && count($series) === 1 && PluginDashboardCatalogo::METRICAS[$w['metricas'][0]][2] !== '') {
            $m = $series[0]['metrica'];
            if ($dim === 'tempo') {
                $ant = array_map(fn($i) => self::paraGrafico($m, $i[2]), $this->serie($m, 'tempo', $c));
                $ant = array_slice(array_pad($ant, count($chaves), null), 0, count($chaves));
            } else {
                $g = $this->agrupar($m, $dim, $c);
                $ant = array_map(fn($k) => isset($g[$k]) ? self::paraGrafico($m, self::valor($m, $g[$k]['num'], $g[$k]['den'])) : null, $chaves);
            }
            $saida['comparacao'] = ['nome' => 'Período de comparação (' . PluginDashboardFiltro::rotuloIntervalo($c) . ')', 'valores' => $ant];
        }
        return $saida;
    }

    private function ranking(array $w): array
    {
        $m = $w['metricas'][0];
        if ($aviso = $this->semModulo([$m])) {
            return ['aviso' => $aviso];
        }
        $s = $this->serie($m, $w['dimensao'], null, $w['limite']);
        $max = max(array_map(fn($i) => (float) ($i[2] ?? 0), $s) ?: [0]);
        return ['unidade' => PluginDashboardCatalogo::METRICAS[$m][1], 'itens' => array_map(fn($i) => [
            'chave' => $i[0], 'rotulo' => $i[1], 'valor' => $i[2], 'formatado' => self::formatar($m, $i[2]), 'pct' => $max > 0 ? round((float) $i[2] / $max * 100) : 0,
        ], $s)];
    }

    private function calor(array $w): array
    {
        $m = $w['metricas'][0];
        if ($aviso = $this->semModulo([$m])) {
            return ['aviso' => $aviso];
        }
        $g = $this->agrupar($m, 'calor');
        $valores = [];
        $max = 0;
        $ordem = array_keys(self::DIAS);
        foreach ($g as $k => $v) {
            [$dia, $hora] = array_map('intval', explode('-', (string) $k));
            $val = self::paraGrafico($m, self::valor($m, $v['num'], $v['den']));
            $valores[] = [(int) $hora, (int) array_search($dia, $ordem, true), $val];
            $max = max($max, (float) $val);
        }
        return ['dias' => array_values(self::DIAS), 'horas' => array_map(fn($h) => str_pad((string) $h, 2, '0', STR_PAD_LEFT) . 'h', range(0, 23)), 'valores' => $valores, 'max' => $max];
    }

    private function tecnicos(array $w): array
    {
        $colunas = [];
        $linhas = [];
        foreach ($w['metricas'] as $m) {
            if (!array_intersect(PluginDashboardCatalogo::METRICAS[$m][3], $this->f->modulos)) {
                continue;
            }
            $colunas[] = ['chave' => $m, 'rotulo' => PluginDashboardCatalogo::METRICAS[$m][0], 'unidade' => PluginDashboardCatalogo::METRICAS[$m][1], 'melhor' => PluginDashboardCatalogo::METRICAS[$m][5]];
            foreach ($this->agrupar($m, 'tecnico') as $k => $v) {
                if ((int) $k <= 0) {
                    continue;
                }
                $linhas[$k] ??= ['id' => (int) $k, 'nome' => $v['rotulo'], 'valores' => []];
                $val = self::valor($m, $v['num'], $v['den']);
                $linhas[$k]['valores'][$m] = ['v' => $val, 'f' => self::formatar($m, $val)];
            }
        }
        if ($this->f->tecnicos) {
            $linhas = array_intersect_key($linhas, array_flip(array_map('strval', $this->f->tecnicos)) + array_flip($this->f->tecnicos));
        }
        // Ranking por coluna (1º = melhor; métricas neutras ordenam pelo maior)
        foreach ($colunas as $c) {
            $vals = [];
            foreach ($linhas as $k => $l) {
                if (isset($l['valores'][$c['chave']]['v'])) {
                    $vals[$k] = $l['valores'][$c['chave']]['v'];
                }
            }
            $c['melhor'] === 'baixo' ? asort($vals) : arsort($vals);
            $pos = 1;
            foreach (array_keys($vals) as $k) {
                $linhas[$k]['valores'][$c['chave']]['rank'] = $pos++;
            }
        }
        $linhas = array_values($linhas);
        usort($linhas, fn($a, $b) => [($b['valores']['abertos']['v'] ?? 0), $a['nome']] <=> [($a['valores']['abertos']['v'] ?? 0), $b['nome']]);
        return ['colunas' => $colunas, 'linhas' => $linhas];
    }

    // =====================================================================
    // Lista de itens (widget lista e detalhamento)
    // =====================================================================

    /**
     * $opcoes: pagina, por_pagina, ordem (coluna), direcao (asc/desc), busca, ids ([modulo => ids] do detalhamento)
     */
    public function lista(array $w, array $opcoes = []): array
    {
        global $DB;
        [$ini, $fim] = $this->f->intervalo();
        $limite = max(100, min(5000, (int) PluginDashboardConfig::getConfig('limite_lista')));
        $busca = trim((string) ($opcoes['busca'] ?? ''));
        $ids = $opcoes['ids'] ?? null;
        $itens = [];
        $truncado = false;
        foreach ($this->f->modulos as $modulo) {
            if (is_array($ids) && empty($ids[$modulo])) {
                continue;
            }
            $M = PluginDashboardCatalogo::MODULOS[$modulo];
            $proj = $modulo === 'Project';
            $where = [$this->f->condicoes($modulo)];
            if (is_array($ids)) {
                $where[] = 't.id IN (' . PluginDashboardFiltro::lista($ids[$modulo]) . ')';
            } else {
                $where[] = 't.date BETWEEN ' . self::q($ini) . ' AND ' . self::q($fim);
            }
            if ($busca !== '') {
                $like = $DB->quote('%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca) . '%');
                $where[] = '(t.name LIKE ' . $like . (ctype_digit($busca) ? ' OR t.id = ' . (int) $busca : '') . ')';
            }
            $sub = fn(string $tabela, int $tipo, string $campo) => "(SELECT GROUP_CONCAT(DISTINCT x.$campo) FROM `$tabela` x WHERE x.`{$M['fk']}` = t.id AND x.type = $tipo)";
            $campos = $proj
                ? 't.id, t.name, t.entities_id, t.projectstates_id AS status, t.priority, t.date, t.real_end_date AS solvedate, 0 AS itilcategories_id, NULL AS time_to_resolve, t.users_id AS tecnicos, t.groups_id AS grupos, \'\' AS requerentes'
                : 't.id, t.name, t.entities_id, t.status, t.priority, t.date, t.solvedate, t.itilcategories_id, t.time_to_resolve, '
                    . $sub($M['usuarios'], 2, 'users_id') . ' AS tecnicos, ' . $sub($M['grupos'], 2, 'groups_id') . ' AS grupos, ' . $sub($M['usuarios'], 1, 'users_id') . ' AS requerentes';
            $linhas = self::linhas("SELECT $campos FROM `{$M['tabela']}` t WHERE " . implode(' AND ', $where) . ' ORDER BY t.date DESC LIMIT ' . ($limite + 1));
            if (count($linhas) > $limite) {
                $truncado = true;
                array_pop($linhas);
            }
            foreach ($linhas as $l) {
                $l['modulo'] = $modulo;
                $itens[] = $l;
            }
        }

        $colunas = $w['colunas'] ?? array_keys(PluginDashboardCatalogo::COLUNAS_LISTA);
        $ordem = isset(PluginDashboardCatalogo::COLUNAS_LISTA[$opcoes['ordem'] ?? '']) ? (string) $opcoes['ordem'] : 'abertura';
        $desc = ($opcoes['direcao'] ?? 'desc') !== 'asc';
        $agora = time();
        $chaveOrdem = fn($l) => match ($ordem) {
            'id' => (int) $l['id'],
            'abertura' => (string) $l['date'],
            'solucao' => (string) $l['solvedate'],
            'idade' => (($l['solvedate'] ? strtotime((string) $l['solvedate']) : $agora) - strtotime((string) $l['date'])),
            'prioridade' => (int) $l['priority'],
            'status' => (int) $l['status'],
            'prazo' => (string) $l['time_to_resolve'],
            'modulo' => $l['modulo'],
            default => mb_strtolower((string) $l['name']),
        };
        usort($itens, fn($a, $b) => $desc ? $chaveOrdem($b) <=> $chaveOrdem($a) : $chaveOrdem($a) <=> $chaveOrdem($b));

        $porPagina = max(10, min(100, (int) ($opcoes['por_pagina'] ?? 15)));
        $total = count($itens);
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($paginas, (int) ($opcoes['pagina'] ?? 1)));
        $pag = array_slice($itens, ($pagina - 1) * $porPagina, $porPagina);

        // Nomes da página
        $usuarios = [];
        $grupos = [];
        foreach ($pag as $l) {
            $usuarios = array_merge($usuarios, explode(',', (string) $l['tecnicos']), explode(',', (string) $l['requerentes']));
            $grupos = array_merge($grupos, explode(',', (string) $l['grupos']));
        }
        $nomesU = PluginDashboardConfig::nomesUsuarios($usuarios);
        $nomesG = $this->nomes('glpi_groups', $grupos);
        $nomesE = $this->nomes('glpi_entities', array_column($pag, 'entities_id'));
        $nomesC = $this->nomes('glpi_itilcategories', array_column($pag, 'itilcategories_id'));
        $estados = $this->nomes('glpi_projectstates', array_column(array_filter($pag, fn($l) => $l['modulo'] === 'Project'), 'status'), 'name');
        $junta = fn(string $csv, array $nomes) => implode(', ', array_filter(array_map(fn($i) => $nomes[(int) $i] ?? '', explode(',', $csv))));

        $linhas = [];
        foreach ($pag as $l) {
            $mod = $l['modulo'];
            $fimRef = $l['solvedate'] ? strtotime((string) $l['solvedate']) : $agora;
            $prazo = (string) ($l['time_to_resolve'] ?? '');
            $estourado = $prazo !== '' && (($l['solvedate'] ? strtotime((string) $l['solvedate']) : $agora) > strtotime($prazo));
            $linhas[] = [
                'url'        => $mod::getFormURLWithID((int) $l['id']),
                'modulo'     => PluginDashboardCatalogo::MODULOS[$mod]['singular'],
                'icone'      => PluginDashboardCatalogo::MODULOS[$mod]['icone'],
                'id'         => (int) $l['id'],
                'titulo'     => (string) $l['name'],
                'entidade'   => (int) $l['entities_id'] === 0 ? 'Entidade raiz' : ($nomesE[(int) $l['entities_id']] ?? ''),
                'status'     => $mod === 'Project' ? ($estados[(int) $l['status']] ?? '') : $mod::getStatus((int) $l['status']),
                'status_html' => $mod === 'Project' ? '' : $mod::getStatusIcon((int) $l['status']),
                'prioridade' => (int) $l['priority'] > 0 ? CommonITILObject::getPriorityName((int) $l['priority']) : '',
                'prioridade_n' => (int) $l['priority'],
                'categoria'  => $nomesC[(int) $l['itilcategories_id']] ?? '',
                'requerente' => $junta((string) $l['requerentes'], $nomesU),
                'tecnico'    => $junta((string) $l['tecnicos'], $nomesU),
                'grupo'      => $junta((string) $l['grupos'], $nomesG),
                'abertura'   => Html::convDateTime((string) $l['date']),
                'solucao'    => $l['solvedate'] ? Html::convDateTime((string) $l['solvedate']) : '',
                'idade'      => PluginDashboardConfig::formatarTempo((float) max(0, $fimRef - strtotime((string) $l['date']))),
                'prazo'      => $prazo !== '' ? Html::convDateTime($prazo) : '',
                'estourado'  => $estourado,
            ];
        }
        return [
            'colunas'  => array_map(fn($c) => ['chave' => $c, 'rotulo' => PluginDashboardCatalogo::COLUNAS_LISTA[$c]], $colunas),
            'linhas'   => $linhas,
            'total'    => $total,
            'truncado' => $truncado,
            'pagina'   => $pagina,
            'paginas'  => $paginas,
            'ordem'    => $ordem,
            'direcao'  => $desc ? 'desc' : 'asc',
        ];
    }

    /** Detalhamento de um widget: itens por trás do valor clicado */
    public function detalhar(array $w, ?string $chave, bool $comparacao, array $opcoes): array
    {
        $intervalo = $comparacao ? ($this->f->intervaloComparacao() ?? $this->f->intervalo()) : $this->f->intervalo();
        $m = $opcoes['metrica'] ?? ($w['metricas'][0] ?? 'abertos');
        if (!isset(PluginDashboardCatalogo::METRICAS[$m])) {
            $m = 'abertos';
        }
        $dim = $w['tipo'] === 'indicador' ? '' : ($w['tipo'] === 'calor' ? 'calor' : ($w['tipo'] === 'tecnicos' ? 'tecnico' : (string) $w['dimensao']));
        if ($chave === '__outros__') {
            $chave = null;
        }
        $ids = $this->ids($m, $dim, $dim === '' ? null : $chave, $intervalo);
        $lista = $this->lista(['colunas' => ['modulo', 'id', 'titulo', 'entidade', 'status', 'tecnico', 'grupo', 'abertura', 'solucao', 'idade']], $opcoes + ['ids' => $ids]);
        $lista['metrica'] = PluginDashboardCatalogo::METRICAS[$m][0];
        return $lista;
    }
}
