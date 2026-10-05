<?php

/**
 * Plugin Dashboard - atalho para a configuração
 */

Session::checkLoginUser();
Html::redirect(PluginDashboardConfig::url('config.form.php'));
