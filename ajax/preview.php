<?php

use GlpiPlugin\Termodocs\DocumentTemplate;
use GlpiPlugin\Termodocs\Placeholder\ContextResolver;
use GlpiPlugin\Termodocs\Placeholder\TemplateRenderer;

Session::checkRight(DocumentTemplate::$rightname, READ);

$header_html = $_POST['header_html'] ?? '';
$content_html = $_POST['content_html'] ?? '';
$footer_html = $_POST['footer_html'] ?? '';
$css = $_POST['css'] ?? '';
$itemtype = $_POST['itemtype'] ?? '';

$template = new DocumentTemplate();
$template->fields['background_documents_id'] = (int) ($_POST['background_documents_id'] ?? 0);

$sample_items = [];
if (class_exists($itemtype) && is_a($itemtype, CommonDBTM::class, true)) {
    $obj = new $itemtype();
    $rows = $obj->find([], [], 2);
    foreach (array_keys($rows) as $sample_id) {
        $sample_items[] = [$itemtype, (int) $sample_id];
    }
}

$recipient = new User();
$recipient->getFromDB(Session::getLoginUserID());

$context = (new ContextResolver())->resolve(
    $sample_items,
    $recipient,
    $recipient,
    Session::getActiveEntity(),
    ['date' => date('Y-m-d'), 'name' => __('Pré-visualização', 'termodocs')]
);

try {
    $renderer = TemplateRenderer::getInstance();
    $composed = $template->composeHtml(
        $renderer->render($header_html, $context),
        $renderer->render($content_html, $context),
        $renderer->render($footer_html, $context)
    );
} catch (\Throwable $e) {
    $composed = '<div style="color:red;font-family:sans-serif">' . htmlspecialchars($e->getMessage()) . '</div>';
}

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>';
echo $css;
echo '</style></head><body>';
echo $composed;
if (empty($sample_items)) {
    echo '<p style="color:#999;font-family:sans-serif;margin-top:2em">' .
        htmlspecialchars(__('Nenhum ativo de exemplo encontrado para este tipo; os campos que o referenciam ficam vazios.', 'termodocs')) .
        '</p>';
}
echo '</body></html>';
