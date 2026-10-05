<?php

/**
 * Plugin Dashboard - filtros do painel: período (com comparação), módulos, entidades e os demais
 * recortes, convertidos em condições SQL por módulo. Os dados ficam sempre restritos às entidades
 * ativas do usuário (ou do criador, no envio agendado).
 */
class PluginDashboardFiltro
{
    public string $periodo = '7d';
    public string $de = '';
    public string $ate = '';
    public string $comparar = 'nenhuma';
    public array $modulos = ['Ticket'];
    public array $entidades = [];
    public array $grupos = [];
    public array $tecnicos = [];
    public array $categorias = [];
    public array $tipos = [];
    public array $prioridades = [];
    public array $status = [];
    public array $origens = [];
    public string $situacao = 'todos';
    /** Entidades que o usuário pode ver (vazio = as ativas da sessão) */
    private array $permitidas = [];

    private const LISTAS = ['entidades', 'grupos', 'tecnicos', 'categorias', 'tipos', 'prioridades', 'origens'];

    public function __construct(array $f = [], ?array $entidadesPermitidas = null)
    {
        $C = PluginDashboardCatalogo::class;
        $this->periodo = isset($C::PERIODOS[$f['periodo'] ?? '']) ? (string) $f['periodo'] : '7d';
        foreach (['de', 'ate'] as $c) {
            $this->$c = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f[$c] ?? '')) ? (string) $f[$c] : '';
        }
        if ($this->periodo === 'personalizado' && ($this->de === '' || $this->ate === '')) {
            $this->periodo = '30d';
        }
        if ($this->de !== '' && $this->ate !== '' && $this->de > $this->ate) {
            [$this->de, $this->ate] = [$this->ate, $this->de];
        }
        $this->comparar = isset($C::COMPARACOES[$f['comparar'] ?? '']) ? (string) $f['comparar'] : 'nenhuma';
        $modulos = array_values(array_intersect(array_keys($C::MODULOS), array_map('strval', (array) ($f['modulos'] ?? ['Ticket']))));
        $this->modulos = $modulos ?: ['Ticket'];
        foreach (self::LISTAS as $l) {
            $this->$l = array_values(array_unique(array_filter(array_map('intval', (array) ($f[$l] ?? [])), fn($v) => $v > 0 || ($l === 'entidades' && $v === 0 && in_array('0', array_map('strval', (array) $f[$l]), true)))));
        }
        $this->status = array_values(array_unique(array_filter(array_map('intval', (array) ($f['status'] ?? [])), fn($v) => $v > 0)));
        $this->situacao = in_array($f['situacao'] ?? '', ['abertos', 'encerrados'], true) ? (string) $f['situacao'] : 'todos';
        $this->permitidas = $entidadesPermitidas ?? array_map('intval', $_SESSION['glpiactiveentities'] ?? [0]);
    }

    public function toArray(): array
    {
        $a = ['periodo' => $this->periodo, 'comparar' => $this->comparar, 'modulos' => $this->modulos, 'situacao' => $this->situacao, 'status' => $this->status];
        if ($this->periodo === 'personalizado') {
            $a['de'] = $this->de;
            $a['ate'] = $this->ate;
        }
        foreach (self::LISTAS as $l) {
            $a[$l] = $this->$l;
        }
        return $a;
    }

    // =====================================================================
    // Período
    // =====================================================================

    /** [início, fim] do período escolhido, em 'Y-m-d H:i:s' */
    public function intervalo(): array
    {
        $hoje = date('Y-m-d');
        [$ini, $fim] = match ($this->periodo) {
            'hoje'          => [$hoje, $hoje],
            'ontem'         => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            '3d'            => [date('Y-m-d', strtotime('-2 days')), $hoje],
            '7d'            => [date('Y-m-d', strtotime('-6 days')), $hoje],
            '15d'           => [date('Y-m-d', strtotime('-14 days')), $hoje],
            '30d'           => [date('Y-m-d', strtotime('-29 days')), $hoje],
            'mes'           => [date('Y-m-01'), $hoje],
            'mes_anterior'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
            '90d'           => [date('Y-m-d', strtotime('-89 days')), $hoje],
            '180d'          => [date('Y-m-d', strtotime('-179 days')), $hoje],
            'ano'           => [date('Y-01-01'), $hoje],
            '365d'          => [date('Y-m-d', strtotime('-364 days')), $hoje],
            'personalizado' => [$this->de, $this->ate],
            default         => [date('Y-m-d', strtotime('-6 days')), $hoje],
        };
        return [$ini . ' 00:00:00', $fim . ' 23:59:59'];
    }

    /** Período de comparação com a mesma duração, ou null */
    public function intervaloComparacao(): ?array
    {
        if ($this->comparar === 'nenhuma') {
            return null;
        }
        [$ini, $fim] = $this->intervalo();
        if ($this->comparar === 'ano_anterior') {
            return [date('Y-m-d H:i:s', strtotime($ini . ' -1 year')), date('Y-m-d H:i:s', strtotime($fim . ' -1 year'))];
        }
        $dias = (int) round((strtotime($fim) - strtotime($ini)) / 86400);
        $novoFim = date('Y-m-d', strtotime(substr($ini, 0, 10) . ' -1 day'));
        return [date('Y-m-d', strtotime($novoFim . ' -' . max(0, $dias - 1) . ' days')) . ' 00:00:00', $novoFim . ' 23:59:59'];
    }

    public static function rotuloIntervalo(array $i): string
    {
        $a = date('d/m/Y', strtotime($i[0]));
        $b = date('d/m/Y', strtotime($i[1]));
        return $a === $b ? $a : $a . ' a ' . $b;
    }

    public function rotuloPeriodo(): string
    {
        $p = PluginDashboardCatalogo::PERIODOS[$this->periodo];
        return ($this->periodo === 'personalizado' ? '' : $p . ' · ') . self::rotuloIntervalo($this->intervalo());
    }

    /** Granularidade do eixo de tempo conforme a duração */
    public function granularidade(?array $intervalo = null): string
    {
        [$ini, $fim] = $intervalo ?? $this->intervalo();
        $dias = (strtotime($fim) - strtotime($ini)) / 86400;
        return $dias <= 1.5 ? 'hora' : ($dias <= 62 ? 'dia' : ($dias <= 200 ? 'semana' : 'mes'));
    }

    // =====================================================================
    // Condições SQL
    // =====================================================================

    /** Entidades efetivas: as escolhidas (com sub-entidades) dentro das permitidas */
    public function entidadesEfetivas(): array
    {
        $permitidas = $this->permitidas ?: [0];
        if (!$this->entidades) {
            return $permitidas;
        }
        $todas = [];
        foreach ($this->entidades as $e) {
            foreach (getSonsOf('glpi_entities', $e) as $f) {
                $todas[] = (int) $f;
            }
        }
        $ids = array_values(array_intersect(array_unique($todas), $permitidas));
        return $ids ?: [-1];
    }

    public static function lista(array $ids): string
    {
        return implode(',', array_map('intval', $ids ?: [-1]));
    }

    /** Status que contam como encerrados no módulo */
    public static function statusEncerrados(string $modulo): array
    {
        if ($modulo === 'Project') {
            return [];
        }
        $lista = array_merge($modulo::getSolvedStatusArray(), $modulo::getClosedStatusArray());
        if ($modulo === 'Change') {
            foreach (['CANCELED', 'REFUSED'] as $c) {
                if (defined('Change::' . $c)) {
                    $lista[] = constant('Change::' . $c);
                }
            }
        }
        return array_values(array_unique(array_map('intval', $lista)));
    }

    /** Condição "em aberto" do módulo */
    public static function condAberto(string $modulo, string $a = 't'): string
    {
        if ($modulo === 'Project') {
            return "($a.projectstates_id = 0 OR $a.projectstates_id IN (SELECT id FROM glpi_projectstates WHERE is_finished = 0))";
        }
        return "$a.status NOT IN (" . self::lista(self::statusEncerrados($modulo)) . ')';
    }

    /** Condições (sem data) aplicadas aos itens do módulo, com alias da tabela principal */
    public function condicoes(string $modulo, string $a = 't'): string
    {
        $m = PluginDashboardCatalogo::MODULOS[$modulo];
        $c = ["$a.is_deleted = 0", "$a.entities_id IN (" . self::lista($this->entidadesEfetivas()) . ')'];
        if ($modulo === 'Project') {
            $c[] = "$a.is_template = 0";
            if ($this->grupos) {
                $c[] = "$a.groups_id IN (" . self::lista($this->grupos) . ')';
            }
            if ($this->tecnicos) {
                $c[] = "$a.users_id IN (" . self::lista($this->tecnicos) . ')';
            }
        } else {
            if ($this->grupos) {
                $c[] = "EXISTS (SELECT 1 FROM `{$m['grupos']}` fg WHERE fg.`{$m['fk']}` = $a.id AND fg.type = 2 AND fg.groups_id IN (" . self::lista($this->grupos) . '))';
            }
            if ($this->tecnicos) {
                $c[] = "EXISTS (SELECT 1 FROM `{$m['usuarios']}` fu WHERE fu.`{$m['fk']}` = $a.id AND fu.type = 2 AND fu.users_id IN (" . self::lista($this->tecnicos) . '))';
            }
            if ($this->categorias) {
                $c[] = "$a.itilcategories_id IN (" . self::lista($this->categorias) . ')';
            }
            if ($this->status) {
                $c[] = "$a.status IN (" . self::lista($this->status) . ')';
            }
        }
        if ($modulo === 'Ticket') {
            if ($this->tipos) {
                $c[] = "$a.type IN (" . self::lista($this->tipos) . ')';
            }
            if ($this->origens) {
                $c[] = "$a.requesttypes_id IN (" . self::lista($this->origens) . ')';
            }
        }
        if ($this->prioridades) {
            $c[] = "$a.priority IN (" . self::lista($this->prioridades) . ')';
        }
        if ($this->situacao === 'abertos') {
            $c[] = self::condAberto($modulo, $a);
        } elseif ($this->situacao === 'encerrados') {
            $c[] = 'NOT ' . self::condAberto($modulo, $a);
        }
        return implode(' AND ', $c);
    }

    /** Descrição curta dos filtros aplicados (relatórios e PDF) */
    public function descricao(): array
    {
        global $DB;
        $nomes = function (string $tabela, array $ids, string $campo = 'completename') use ($DB): string {
            if (!$ids) {
                return '';
            }
            $lista = [];
            foreach ($DB->request(['SELECT' => [$campo], 'FROM' => $tabela, 'WHERE' => ['id' => $ids]]) as $r) {
                $lista[] = (string) $r[$campo];
            }
            return implode(', ', $lista);
        };
        $d = [
            'Período' => $this->rotuloPeriodo(),
            'Módulos' => implode(', ', array_map(fn($m) => PluginDashboardCatalogo::MODULOS[$m]['rotulo'], $this->modulos)),
        ];
        if ($c = $this->intervaloComparacao()) {
            $d['Comparado com'] = self::rotuloIntervalo($c);
        }
        $extras = [
            'Entidades'  => $nomes('glpi_entities', $this->entidades),
            'Grupos'     => $nomes('glpi_groups', $this->grupos),
            'Técnicos'   => implode(', ', PluginDashboardConfig::nomesUsuarios($this->tecnicos)),
            'Categorias' => $nomes('glpi_itilcategories', $this->categorias),
            'Origens'    => $nomes('glpi_requesttypes', $this->origens, 'name'),
        ];
        foreach ($extras as $k => $v) {
            if ($v !== '') {
                $d[$k] = $v;
            }
        }
        if ($this->tipos) {
            $d['Tipos'] = implode(', ', array_map(fn($t) => $t === 1 ? 'Incidente' : 'Requisição', $this->tipos));
        }
        if ($this->prioridades) {
            $d['Prioridades'] = implode(', ', array_map(fn($p) => CommonITILObject::getPriorityName($p), $this->prioridades));
        }
        if ($this->situacao !== 'todos') {
            $d['Situação'] = $this->situacao === 'abertos' ? 'Em aberto' : 'Encerrados';
        }
        return $d;
    }
}
