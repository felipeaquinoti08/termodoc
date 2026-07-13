<?php

use GlpiPlugin\Termodocs\DocumentTemplate;

Session::checkRight(DocumentTemplate::$rightname, READ);

$data = [
    'termodocs_export_type'    => 'templates',
    'termodocs_export_version' => 1,
    'exported_at'              => date('Y-m-d H:i:s'),
    'templates'                => DocumentTemplate::exportAll(),
];

$filename = 'termodocs_modelos_' . date('Y-m-d_His') . '.json';
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
