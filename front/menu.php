<?php

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Termodocs\Menu;

Session::checkLoginUser();

if (!Menu::canView()) {
    throw new AccessDeniedHttpException();
}

Html::header(Menu::getTypeName(1), '', 'management', Menu::class);

$menu = new Menu();
$menu->display();

Html::footer();
