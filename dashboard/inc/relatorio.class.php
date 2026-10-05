<?php

/**
 * Plugin Dashboard - relatório do painel por e-mail: resumo em HTML (indicadores e os dados de cada
 * widget em tabelas), PDF do navegador anexado no envio manual, registro de cada envio.
 */
class PluginDashboardRelatorio
{
    public const TABELA_ENVIOS = 'glpi_plugin_dashboard_envios';

    /** [email, nome] do remetente: configuração do plugin, depois as notificações do GLPI */
    public static function remetente(): ?array
    {
        global $CFG_GLPI;
        $C = PluginDashboardConfig::class;
        foreach ([$C::getConfig('email_remetente'), $CFG_GLPI['from_email'] ?? '', $CFG_GLPI['admin_email'] ?? ''] as $email) {
            $email = trim((string) $email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [$email, trim((string) $C::getConfig('email_nome')) ?: trim((string) ($CFG_GLPI['from_email_name'] ?? $CFG_GLPI['admin_email_name'] ?? ''))];
            }
        }
        return null;
    }

    /** Resumo HTML do painel com estilos em linha (clientes de e-mail) */
    public static function html(array $painel, PluginDashboardFiltro $filtro, string $mensagem = ''): string
    {
        $e = [PluginDashboardConfig::class, 'e'];
        $motor = new PluginDashboardMotor($filtro);
        $th = 'style="text-align:left;padding:6px 8px;font-size:11px;color:#6c757d;font-weight:600;border-bottom:1px solid #dee2e6;background:#f8f9fa;"';
        $td = 'style="padding:6px 8px;font-size:12px;color:#333;border-bottom:1px solid #f0f0f0;"';
        $tdn = 'style="padding:6px 8px;font-size:12px;color:#333;border-bottom:1px solid #f0f0f0;text-align:right;white-space:nowrap;"';
        $titulo = fn(string $t) => '<div style="font-size:14px;font-weight:600;color:#495057;margin:18px 0 6px;border-bottom:1px solid #dee2e6;padding-bottom:4px;">' . $e($t) . '</div>';

        $h = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:900px;margin:0 auto;background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:18px 22px;">';
        $h .= '<div style="font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:.5px;">Dashboard</div><div style="font-size:18px;font-weight:600;color:#2f3f64;margin-bottom:8px;">' . $e($painel['name']) . '</div>';
        if (trim(strip_tags($mensagem, '<img>')) !== '') {
            $h .= '<div style="font-size:13px;color:#333;margin:8px 0 14px;">' . \Glpi\RichText\RichText::getSafeHtml($mensagem) . '</div>';
        }
        $h .= '<table style="border-collapse:collapse;font-size:12px;margin-bottom:6px;">';
        foreach ($filtro->descricao() as $k => $v) {
            $h .= '<tr><td style="padding:2px 12px 2px 0;color:#6c757d;font-weight:600;white-space:nowrap;">' . $e($k) . '</td><td style="padding:2px 0;color:#333;">' . $e($v) . '</td></tr>';
        }
        $h .= '</table>';

        // Indicadores em grade
        $kpis = array_values(array_filter($painel['widgets'], fn($w) => $w['tipo'] === 'indicador'));
        if ($kpis) {
            $h .= $titulo('Indicadores') . '<table style="border-collapse:separate;border-spacing:6px;width:100%;"><tr>';
            foreach ($kpis as $i => $w) {
                $d = $motor->widget($w);
                if ($i > 0 && $i % 4 === 0) {
                    $h .= '</tr><tr>';
                }
                $var = isset($d['variacao']) && $d['variacao'] !== null ? '<div style="font-size:11px;color:#6c757d;">' . ($d['variacao'] > 0 ? '+' : '') . number_format((float) $d['variacao'], 1, ',', '.') . '% vs. ' . $e($d['anterior_formatado'] ?? '') . '</div>' : '';
                $h .= '<td style="width:25%;vertical-align:top;border:1px solid #dee2e6;border-radius:4px;padding:8px 10px;background:#fff;">'
                    . '<div style="font-size:11px;color:#6c757d;">' . $e($w['titulo']) . '</div><div style="font-size:20px;font-weight:600;color:#2f3f64;">' . $e($d['formatado'] ?? ($d['aviso'] ?? '—')) . '</div>' . $var . '</td>';
            }
            $h .= '</tr></table>';
        }

        foreach ($painel['widgets'] as $w) {
            if ($w['tipo'] === 'indicador') {
                continue;
            }
            $d = $motor->widget($w, ['por_pagina' => 20]);
            $h .= $titulo($w['titulo']);
            if (isset($d['erro']) || isset($d['aviso'])) {
                $h .= '<div style="font-size:12px;color:#999;">' . $e($d['erro'] ?? $d['aviso']) . '</div>';
                continue;
            }
            switch ($w['tipo']) {
                case 'grafico':
                    $h .= '<table style="border-collapse:collapse;width:100%;"><tr><th ' . $th . '>' . $e(PluginDashboardCatalogo::DIMENSOES[$w['dimensao']][0]) . '</th>';
                    foreach ($d['series'] as $s) {
                        $h .= '<th ' . str_replace('text-align:left', 'text-align:right', $th) . '>' . $e($s['nome']) . '</th>';
                    }
                    $h .= '</tr>';
                    foreach ($d['categorias'] as $i => $cat) {
                        $h .= '<tr><td ' . $td . '>' . $e($cat) . '</td>';
                        foreach ($d['series'] as $s) {
                            $h .= '<td ' . $tdn . '>' . $e($s['formatados'][$i]) . '</td>';
                        }
                        $h .= '</tr>';
                    }
                    $h .= '</table>';
                    break;
                case 'ranking':
                    $h .= '<table style="border-collapse:collapse;width:100%;">';
                    foreach ($d['itens'] as $i => $it) {
                        $h .= '<tr><td style="width:30px;padding:6px 8px;font-size:12px;color:#999;border-bottom:1px solid #f0f0f0;">' . ($i + 1) . 'º</td><td ' . $td . '>' . $e($it['rotulo']) . '</td><td ' . $tdn . '>' . $e($it['formatado']) . '</td></tr>';
                    }
                    $h .= '</table>';
                    break;
                case 'tecnicos':
                    $h .= '<table style="border-collapse:collapse;width:100%;"><tr><th ' . $th . '>Técnico</th>';
                    foreach ($d['colunas'] as $c) {
                        $h .= '<th ' . $th . '>' . $e($c['rotulo']) . '</th>';
                    }
                    $h .= '</tr>';
                    foreach ($d['linhas'] as $l) {
                        $h .= '<tr><td ' . $td . '>' . $e($l['nome']) . '</td>';
                        foreach ($d['colunas'] as $c) {
                            $v = $l['valores'][$c['chave']] ?? null;
                            $h .= '<td ' . $tdn . '>' . $e($v['f'] ?? '—') . (($v['rank'] ?? 0) === 1 ? ' ★' : '') . '</td>';
                        }
                        $h .= '</tr>';
                    }
                    $h .= '</table>';
                    break;
                case 'calor':
                    usort($d['valores'], fn($a, $b) => $b[2] <=> $a[2]);
                    $h .= '<div style="font-size:12px;color:#333;">Horários de maior volume: ' . $e(implode(', ', array_map(fn($v) => $d['dias'][$v[1]] . ' ' . $d['horas'][$v[0]] . ' (' . $v[2] . ')', array_slice($d['valores'], 0, 5)))) . '</div>';
                    break;
                case 'lista':
                    $h .= '<table style="border-collapse:collapse;width:100%;"><tr>';
                    foreach ($d['colunas'] as $c) {
                        $h .= '<th ' . $th . '>' . $e($c['rotulo']) . '</th>';
                    }
                    $h .= '</tr>';
                    foreach ($d['linhas'] as $l) {
                        $h .= '<tr>';
                        foreach ($d['colunas'] as $c) {
                            $h .= '<td ' . $td . '>' . $e($l[$c['chave']] ?? '') . '</td>';
                        }
                        $h .= '</tr>';
                    }
                    $h .= '</table><div style="font-size:11px;color:#999;margin-top:4px;">' . (int) $d['total'] . ' item(ns) no total' . ($d['total'] > count($d['linhas']) ? '; mostrando os ' . count($d['linhas']) . ' primeiros.' : '.') . '</div>';
                    break;
            }
        }
        $rodape = array_filter([trim((string) PluginDashboardConfig::getConfig('rodape_email')), 'Gerado em ' . date('d/m/Y H:i')]);
        $h .= '<div style="border-top:1px solid #dee2e6;margin-top:18px;padding-top:8px;font-size:11px;color:#999;text-align:center;">' . $e(implode(' · ', $rodape)) . '</div>';
        return $h . '</div>';
    }

    /**
     * Envia o relatório. $anexo: ['caminho', 'nome'] (PDF gerado no navegador) ou null.
     * Retorna ['ok', 'mensagem', 'enviados', 'falhas'].
     */
    public static function enviar(array $painel, PluginDashboardFiltro $filtro, array $destinos, string $assunto, string $mensagem, ?array $anexo, string $tipo = 'manual', int $agendamento = 0): array
    {
        $validos = [];
        foreach ($destinos as $email) {
            $email = trim((string) $email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validos[mb_strtolower($email)] = $email;
            }
        }
        if (!$validos) {
            return ['ok' => false, 'mensagem' => 'Informe pelo menos um destinatário com e-mail válido.', 'enviados' => 0, 'falhas' => 0];
        }
        $remetente = self::remetente();
        if ($remetente === null) {
            $erro = 'Não há remetente de e-mail: configure um no plugin ou em Configurar > Notificações do GLPI.';
            // A tentativa fica no histórico (importante nos envios agendados, que ninguém acompanha na tela)
            self::registrar((int) $painel['id'], $agendamento, $tipo, $filtro->rotuloPeriodo(), array_values($validos), 0, count($validos), $erro);
            return ['ok' => false, 'mensagem' => $erro, 'enviados' => 0, 'falhas' => count($validos)];
        }
        $assunto = trim((string) preg_replace('/[\r\n]+/', ' ', $assunto)) ?: ('Dashboard: ' . $painel['name'] . ' - ' . $filtro->rotuloPeriodo());
        $corpo = self::html($painel, $filtro, $mensagem);
        $html = '<div style="background:#f8f9fa;padding:16px;">' . $corpo . '</div>';
        $texto = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</div>', '</tr>', '</td>'], ["\n", "\n", "\n", "\n", ' '], $corpo)), ENT_QUOTES, 'UTF-8'));
        $enviados = [];
        $falhas = [];
        $erro = '';
        foreach ($validos as $email) {
            try {
                $mail = new GLPIMailer();
                $msg = $mail->getEmail();
                $msg->from(new \Symfony\Component\Mime\Address($remetente[0], $remetente[1]));
                $msg->to(new \Symfony\Component\Mime\Address($email));
                $msg->subject($assunto);
                $msg->html($html, 'utf-8');
                $msg->text((string) preg_replace("/\n{3,}/", "\n\n", $texto), 'utf-8');
                $msg->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
                $msg->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
                if ($anexo !== null && is_file($anexo['caminho'])) {
                    $msg->attachFromPath($anexo['caminho'], $anexo['nome'], 'application/pdf');
                }
                if ($mail->send()) {
                    $enviados[] = $email;
                } else {
                    $falhas[] = $email;
                    $erro = $erro ?: 'o servidor de e-mail recusou o envio';
                }
            } catch (\Throwable $e) {
                $falhas[] = $email;
                $erro = $erro ?: $e->getMessage();
            }
        }
        self::registrar((int) $painel['id'], $agendamento, $tipo, $filtro->rotuloPeriodo(), array_values($validos), count($enviados), count($falhas), $erro);
        if (!$enviados) {
            return ['ok' => false, 'mensagem' => 'Nenhum e-mail foi enviado' . ($erro !== '' ? ': ' . $erro : '.'), 'enviados' => 0, 'falhas' => count($falhas)];
        }
        $m = count($enviados) === 1 ? 'Relatório enviado para ' . $enviados[0] . '.' : 'Relatório enviado para ' . count($enviados) . ' destinatários.';
        return ['ok' => true, 'mensagem' => $m . ($falhas ? ' Falhou para: ' . implode(', ', $falhas) . '.' : ''), 'enviados' => count($enviados), 'falhas' => count($falhas)];
    }

    private static function registrar(int $painel, int $agendamento, string $tipo, string $periodo, array $emails, int $ok, int $falhas, string $erro): void
    {
        global $DB;
        $DB->insert(self::TABELA_ENVIOS, [
            'plugin_dashboard_paineis_id'      => $painel,
            'plugin_dashboard_agendamentos_id' => $agendamento,
            'users_id'      => (int) Session::getLoginUserID(),
            'tipo'          => $tipo,
            'periodo'       => mb_substr($periodo, 0, 60),
            'destinatarios' => json_encode($emails),
            'total'         => count($emails),
            'sucesso'       => $ok,
            'falha'         => $falhas,
            'erro'          => mb_substr($erro, 0, 2000),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function envios(int $painel, int $limite = 50): array
    {
        global $DB;
        $lista = iterator_to_array($DB->request(['FROM' => self::TABELA_ENVIOS, 'WHERE' => ['plugin_dashboard_paineis_id' => $painel], 'ORDER' => 'date_creation DESC', 'LIMIT' => $limite]), false);
        $nomes = PluginDashboardConfig::nomesUsuarios(array_column($lista, 'users_id'));
        return array_map(fn($l) => [
            'data' => Html::convDateTime((string) $l['date_creation']), 'tipo' => $l['tipo'] === 'agendado' ? 'Agendado' : 'Manual',
            'por' => $nomes[(int) $l['users_id']] ?? 'Tarefa automática', 'periodo' => (string) $l['periodo'],
            'destinatarios' => implode(', ', json_decode((string) $l['destinatarios'], true) ?: []),
            'resultado' => (int) $l['sucesso'] . '/' . (int) $l['total'], 'ok' => (int) $l['falha'] === 0 && (int) $l['sucesso'] > 0, 'erro' => (string) $l['erro'],
        ], $lista);
    }
}
