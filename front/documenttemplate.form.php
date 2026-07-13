<?php

use GlpiPlugin\Termodocs\DocumentTemplate;
use GlpiPlugin\Termodocs\Menu;

Session::checkRight(DocumentTemplate::$rightname, READ);

$item = new DocumentTemplate();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    if ($newID = $item->add($_POST)) {
        if ($_SESSION['glpibackcreated']) {
            Html::redirect($item->getLinkURL());
        }
    }
    Html::back();
} elseif (isset($_POST['delete'])) {
    $item->check($_POST['id'], DELETE);
    $item->delete($_POST);
    $item->redirectToList();
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, true);
    $item->redirectToList();
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} else {
    Html::header(DocumentTemplate::getTypeName(1), '', 'management', Menu::class);
    $item->display(['id' => $_GET['id'] ?? 0]);
    Html::footer();
}
