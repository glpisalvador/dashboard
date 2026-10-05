<?php

/**
 * Plugin Dashboard - aba "Dashboard" no perfil (direitos nativos)
 */
class PluginDashboardProfile extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Dashboard';
    }

    public static function getAllRights(): array
    {
        return [[
            'itemtype' => 'PluginDashboardPainel',
            'label'    => 'Painéis do Dashboard',
            'field'    => PluginDashboardConfig::DIREITO,
        ]];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Profile && $item->getField('interface') !== 'helpdesk') {
            return self::createTabEntry('Dashboard', 0, null, 'ti ti-layout-dashboard');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Profile) {
            return true;
        }
        $perfil = new Profile();
        $perfil->getFromDB($item->getID());
        $pode = Session::haveRight('profile', UPDATE);
        echo '<div class="spaced">';
        if ($pode) {
            echo '<form method="post" action="' . PluginDashboardConfig::e(Profile::getFormURL()) . '">';
        }
        $perfil->displayRightsChoiceMatrix(self::getAllRights(), ['canedit' => $pode, 'default_class' => 'tab_bg_2', 'title' => 'Dashboard']);
        if ($pode) {
            echo '<div class="center">' . Html::hidden('id', ['value' => $item->getID()])
                . Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']) . '</div>';
            Html::closeForm();
        }
        echo '<p class="text-muted small mt-2"><i class="ti ti-info-circle"></i> Ver painéis: abre os painéis compartilhados. Criar e editar os próprios: monta painéis, envia e agenda relatórios. Administrar todos: altera e exclui qualquer painel.</p>';
        echo '</div>';
        return true;
    }
}
