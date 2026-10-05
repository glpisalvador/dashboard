<?php

/**
 * Plugin Dashboard - item no menu Ferramentas
 */
class PluginDashboardMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Dashboard';
    }

    public static function getMenuName(): string
    {
        return 'Dashboard';
    }

    public static function getIcon(): string
    {
        return 'ti ti-layout-dashboard';
    }

    public static function canView(): bool
    {
        return PluginDashboardConfig::pode(READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $url = PluginDashboardConfig::url('painel.php');
        $menu = ['title' => self::getMenuName(), 'page' => $url, 'icon' => self::getIcon(), 'links' => ['search' => $url]];
        if (PluginDashboardConfig::ehAdmin()) {
            $menu['links']['config'] = PluginDashboardConfig::url('config.form.php');
        }
        return $menu;
    }
}
