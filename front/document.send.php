<?php

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Termodocs\Document;

Session::checkLoginUser();

$document = new Document();
if (!$document->getFromDB((int) ($_GET['documents_id'] ?? 0))) {
    throw new NotFoundHttpException();
}

if (!$document->canViewItem()) {
    throw new AccessDeniedHttpException();
}

if (empty($document->fields['pdf_document_id'])) {
    throw new NotFoundHttpException();
}

$pdf = new \Document();
if (!$pdf->getFromDB((int) $document->fields['pdf_document_id'])) {
    throw new NotFoundHttpException();
}

$pdf->getAsResponse()->send();
