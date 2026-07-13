<?php

use GlpiPlugin\Termodocs\Document;

Session::checkRight(Document::$rightname, READ);

$data = [
    'termodocs_export_type'    => 'documents',
    'termodocs_export_version' => 1,
    'exported_at'              => date('Y-m-d H:i:s'),
    'documents'                => Document::exportAll(),
];

$filename = 'termodocs_documentos_' . date('Y-m-d_His') . '.json';
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
