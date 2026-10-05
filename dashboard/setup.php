<?php

/**
 * Plugin Dashboard - GLPI 11 e 12
 * Painéis montados pelo usuário com indicadores, gráficos (ECharts nativo do GLPI), rankings,
 * comparação de técnicos e listas de chamados, problemas, mudanças e projetos. Filtros em tempo
 * real, comparação de períodos, detalhamento, PDF, e-mail e envios agendados.
 */

define('PLUGIN_DASHBOARD_VERSION', '2.0.0');
define('PLUGIN_DASHBOARD_MIN_GLPI', '11.0.0');
define('PLUGIN_DASHBOARD_MAX_GLPI', '12.99.99');

function plugin_init_dashboard(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['dashboard'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('dashboard')) {
        return;
    }

    Plugin::registerClass('PluginDashboardPainel');
    Plugin::registerClass('PluginDashboardProfile', ['addtabon' => ['Profile']]);
    Plugin::registerClass('PluginDashboardMenu');
    Plugin::registerClass('PluginDashboardAgendamento');

    $PLUGIN_HOOKS['config_page']['dashboard'] = 'front/config.form.php';
    $PLUGIN_HOOKS['menu_toadd']['dashboard'] = ['tools' => 'PluginDashboardMenu'];
}

function plugin_version_dashboard(): array
{
    return [
        'name'         => 'Dashboard',
        'version'      => PLUGIN_DASHBOARD_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DASHBOARD_MIN_GLPI,
                'max' => PLUGIN_DASHBOARD_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_dashboard_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_DASHBOARD_MIN_GLPI, '>=');
}

function plugin_dashboard_check_config($verbose = false): bool
{
    return true;
}
