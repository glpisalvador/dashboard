<?php

/**
 * Plugin Dashboard - instalação e desinstalação
 */

function plugin_dashboard_install(): bool
{
    global $DB;

    require_once __DIR__ . '/inc/config.class.php';
    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    if (!$DB->tableExists('glpi_plugin_dashboard_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dashboard_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` longtext NULL,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }

    // Painéis: widgets e filtros padrão em JSON
    if (!$DB->tableExists('glpi_plugin_dashboard_paineis')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dashboard_paineis` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `visibilidade` varchar(20) NOT NULL DEFAULT 'privado',
            `perfis` text NULL,
            `modelo` varchar(30) NOT NULL DEFAULT '',
            `filtros` longtext NULL,
            `widgets` longtext NULL,
            `atualizacao` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `visibilidade` (`visibilidade`)
        ) $opcoes");
    }

    if (!$DB->tableExists('glpi_plugin_dashboard_agendamentos')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dashboard_agendamentos` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_dashboard_paineis_id` int unsigned NOT NULL,
            `name` varchar(255) NOT NULL DEFAULT '',
            `frequencia` varchar(10) NOT NULL DEFAULT 'semanal',
            `dia` tinyint unsigned NOT NULL DEFAULT 1,
            `hora` tinyint unsigned NOT NULL DEFAULT 8,
            `periodo` varchar(20) NOT NULL DEFAULT '7d',
            `usuarios` text NULL,
            `emails` text NULL,
            `mensagem` longtext NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `ultimo_envio` timestamp NULL DEFAULT NULL,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `plugin_dashboard_paineis_id` (`plugin_dashboard_paineis_id`),
            KEY `is_active` (`is_active`)
        ) $opcoes");
    }

    if (!$DB->tableExists('glpi_plugin_dashboard_envios')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_dashboard_envios` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_dashboard_paineis_id` int unsigned NOT NULL DEFAULT 0,
            `plugin_dashboard_agendamentos_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `tipo` varchar(10) NOT NULL DEFAULT 'manual',
            `periodo` varchar(60) NOT NULL DEFAULT '',
            `destinatarios` text NULL,
            `total` int unsigned NOT NULL DEFAULT 0,
            `sucesso` int unsigned NOT NULL DEFAULT 0,
            `falha` int unsigned NOT NULL DEFAULT 0,
            `erro` text NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `plugin_dashboard_paineis_id` (`plugin_dashboard_paineis_id`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    foreach (PluginDashboardConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_dashboard_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert('glpi_plugin_dashboard_configs', ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor) : $valor]);
        }
    }

    // Direitos nativos (Administração > Perfis > Dashboard); administradores recebem tudo
    $direito = PluginDashboardConfig::DIREITO;
    if (count($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $direito], 'LIMIT' => 1])) === 0) {
        ProfileRight::addProfileRights([$direito]);
        $perfis = [];
        foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $r) {
            if (((int) $r['rights'] & UPDATE) === UPDATE) {
                $perfis[] = (int) $r['profiles_id'];
            }
        }
        $todos = READ | CREATE | PURGE;
        if ($perfis) {
            $DB->update('glpi_profilerights', ['rights' => $todos], ['name' => $direito, 'profiles_id' => $perfis]);
        }
        if (isset($_SESSION['glpiactiveprofile']['id']) && in_array((int) $_SESSION['glpiactiveprofile']['id'], $perfis, true)) {
            $_SESSION['glpiactiveprofile'][$direito] = $todos;
        }
    }

    CronTask::register('PluginDashboardAgendamento', 'DashboardEnviarRelatorios', HOUR_TIMESTAMP, [
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'Dashboard: envia os relatórios agendados dos painéis',
    ]);

    return true;
}

function plugin_dashboard_uninstall(): bool
{
    // Regra do projeto: as tabelas e os direitos ficam; só a tarefa automática sai
    CronTask::unregister('dashboard');
    return true;
}
