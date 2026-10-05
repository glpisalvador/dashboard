<?php

/**
 * Plugin Dashboard - envios agendados do relatório de um painel (diário, semanal ou mensal, numa hora
 * escolhida, com período próprio). A tarefa automática roda de hora em hora; os dados respeitam as
 * entidades do usuário que criou o agendamento.
 */
class PluginDashboardAgendamento extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_dashboard_agendamentos';
    public const FREQUENCIAS = ['diario' => 'Diário', 'semanal' => 'Semanal', 'mensal' => 'Mensal'];
    public const DIAS_SEMANA = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Envios agendados' : 'Envio agendado';
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $r) {
            return self::preparar($r);
        }
        return null;
    }

    private static function preparar(array $r): array
    {
        $r['usuarios'] = array_map('intval', json_decode((string) $r['usuarios'], true) ?: []);
        $r['emails'] = json_decode((string) $r['emails'], true) ?: [];
        return $r;
    }

    public static function listar(int $painel): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['plugin_dashboard_paineis_id' => $painel], 'ORDER' => 'id ASC']) as $r) {
            $r = self::preparar($r);
            $r['resumo'] = self::resumo($r);
            $r['ultimo'] = $r['ultimo_envio'] ? Html::convDateTime((string) $r['ultimo_envio']) : 'Nunca';
            $lista[] = $r;
        }
        return $lista;
    }

    public static function resumo(array $a): string
    {
        $hora = str_pad((string) $a['hora'], 2, '0', STR_PAD_LEFT) . 'h';
        $quando = match ($a['frequencia']) {
            'diario'  => 'Todo dia às ' . $hora,
            'semanal' => 'Toda ' . mb_strtolower(self::DIAS_SEMANA[(int) $a['dia']] ?? 'segunda') . ' às ' . $hora,
            default   => 'Todo dia ' . (int) $a['dia'] . ' às ' . $hora,
        };
        return $quando . ' · período: ' . (PluginDashboardCatalogo::PERIODOS[$a['periodo']] ?? $a['periodo']);
    }

    /** Cria ou altera; devolve null ou a mensagem de erro */
    public static function salvar(int $painel, int $id, array $d): ?string
    {
        global $DB;
        $p = PluginDashboardPainel::obter($painel);
        if ($p === null || !PluginDashboardPainel::podeEditar($p)) {
            return 'Sem permissão para agendar envios deste painel.';
        }
        $emails = [];
        foreach (preg_split('/[\s,;]+/', (string) ($d['emails'] ?? '')) ?: [] as $e) {
            $e = trim($e);
            if ($e === '') {
                continue;
            }
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return 'E-mail inválido: ' . $e;
            }
            $emails[] = $e;
        }
        $usuarios = array_values(array_unique(array_filter(array_map('intval', (array) ($d['usuarios'] ?? [])), fn($v) => $v > 0)));
        if (!$emails && !$usuarios) {
            return 'Informe ao menos um destinatário.';
        }
        $periodo = isset(PluginDashboardCatalogo::PERIODOS[$d['periodo'] ?? '']) && ($d['periodo'] ?? '') !== 'personalizado' ? (string) $d['periodo'] : '7d';
        $frequencia = isset(self::FREQUENCIAS[$d['frequencia'] ?? '']) ? (string) $d['frequencia'] : 'semanal';
        $dia = (int) ($d['dia'] ?? 1);
        $dia = $frequencia === 'semanal' ? max(1, min(7, $dia)) : ($frequencia === 'mensal' ? max(1, min(31, $dia)) : 1);
        $valores = [
            'name'       => mb_substr(trim(strip_tags((string) ($d['name'] ?? ''))), 0, 255) ?: 'Relatório ' . mb_strtolower(self::FREQUENCIAS[$frequencia]),
            'frequencia' => $frequencia,
            'dia'        => $dia,
            'hora'       => max(0, min(23, (int) ($d['hora'] ?? 8))),
            'periodo'    => $periodo,
            'usuarios'   => json_encode($usuarios),
            'emails'     => json_encode(array_values(array_unique($emails))),
            'mensagem'   => (string) ($d['mensagem'] ?? ''),
            'is_active'  => !empty($d['is_active']) ? 1 : 0,
            'date_mod'   => date('Y-m-d H:i:s'),
        ];
        if ($id > 0) {
            $a = self::obter($id);
            if ($a === null || (int) $a['plugin_dashboard_paineis_id'] !== $painel) {
                return 'Agendamento não encontrado.';
            }
            $DB->update(self::TABELA, $valores, ['id' => $id]);
        } else {
            $DB->insert(self::TABELA, $valores + ['plugin_dashboard_paineis_id' => $painel, 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => date('Y-m-d H:i:s')]);
        }
        return null;
    }

    public static function excluir(int $painel, int $id): ?string
    {
        global $DB;
        $p = PluginDashboardPainel::obter($painel);
        if ($p === null || !PluginDashboardPainel::podeEditar($p)) {
            return 'Sem permissão.';
        }
        $DB->delete(self::TABELA, ['id' => $id, 'plugin_dashboard_paineis_id' => $painel]);
        return null;
    }

    /** Está na hora de enviar? (no máximo uma vez por dia) */
    public static function devido(array $a, ?int $agora = null): bool
    {
        $agora ??= time();
        if (!(int) $a['is_active'] || (int) date('G', $agora) < (int) $a['hora']) {
            return false;
        }
        if ($a['ultimo_envio'] && date('Y-m-d', strtotime((string) $a['ultimo_envio'])) === date('Y-m-d', $agora)) {
            return false;
        }
        return match ($a['frequencia']) {
            'diario'  => true,
            'semanal' => (int) date('N', $agora) === (int) $a['dia'],
            default   => (int) date('j', $agora) === min((int) $a['dia'], (int) date('t', $agora)),
        };
    }

    /** Entidades que o usuário enxerga pelos perfis dele (recursivos incluem as filhas) */
    public static function entidadesDoUsuario(int $users_id): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['entities_id', 'is_recursive'], 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $users_id]]) as $r) {
            $lista = (int) $r['is_recursive'] ? getSonsOf('glpi_entities', (int) $r['entities_id']) : [(int) $r['entities_id']];
            foreach ($lista as $e) {
                $ids[(int) $e] = true;
            }
        }
        return array_keys($ids);
    }

    /** Envia um agendamento agora (tarefa automática ou botão "Enviar agora") */
    public static function executar(array $a): array
    {
        global $DB;
        $p = PluginDashboardPainel::obter((int) $a['plugin_dashboard_paineis_id']);
        if ($p === null) {
            return ['ok' => false, 'mensagem' => 'Painel não existe mais.'];
        }
        $entidades = self::entidadesDoUsuario((int) $a['users_id']);
        $filtro = new PluginDashboardFiltro(['periodo' => $a['periodo']] + $p['filtros'], $entidades ?: [-1]);
        $destinos = array_merge($a['emails'], array_values(PluginDashboardConfig::emailsUsuarios($a['usuarios'])));
        $r = PluginDashboardRelatorio::enviar($p, $filtro, $destinos, 'Dashboard: ' . $p['name'] . ' - ' . $filtro->rotuloPeriodo(), (string) $a['mensagem'], null, 'agendado', (int) $a['id']);
        $DB->update(self::TABELA, ['ultimo_envio' => date('Y-m-d H:i:s')], ['id' => (int) $a['id']]);
        return $r;
    }

    public static function cronInfo($name): array
    {
        return ['description' => 'Dashboard: envia os relatórios agendados dos painéis'];
    }

    public static function cronDashboardEnviarRelatorios(CronTask $task): int
    {
        global $DB;
        $feitos = 0;
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['is_active' => 1]]) as $r) {
            $a = self::preparar($r);
            if (!self::devido($a)) {
                continue;
            }
            $res = self::executar($a);
            $task->log('Agendamento #' . $a['id'] . ': ' . $res['mensagem']);
            $feitos++;
        }
        $task->addVolume($feitos);
        return $feitos > 0 ? 1 : 0;
    }
}
