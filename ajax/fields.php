<?php

use GlpiPlugin\Termodocs\DocumentTemplate;
use GlpiPlugin\Termodocs\Placeholder\CatalogBuilder;

Session::checkRight(DocumentTemplate::$rightname, READ);

header('Content-Type: application/json; charset=UTF-8');

$itemtype = $_GET['itemtype'] ?? '';
if (!in_array($itemtype, array_keys(DocumentTemplate::getEligibleItemtypes()), true)) {
    echo json_encode([]);
    exit;
}

echo json_encode(CatalogBuilder::getGroupedCatalog($itemtype));
