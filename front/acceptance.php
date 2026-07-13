<?php

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Termodocs\Acceptance;
use GlpiPlugin\Termodocs\Document;

Session::checkLoginUser();

$document = new Document();
if (!$document->getFromDB((int) ($_GET['documents_id'] ?? $_POST['documents_id'] ?? 0))) {
    throw new NotFoundHttpException();
}

$my_role = $document->getMyRole();
if ($my_role === null) {
    throw new AccessDeniedHttpException();
}

global $CFG_GLPI;
$self_url = $CFG_GLPI['root_doc'] . '/plugins/termodocs/front/acceptance.php?documents_id=' . $document->getID();

$already_signed = Acceptance::hasSigned($document->getID(), $my_role);

if (isset($_POST['accept'])) {
    if (!$already_signed && (int) $document->fields['status'] === Document::WAITING_ACCEPTANCE) {
        Acceptance::accept($document, $my_role);
    }
    // Redirect back to this same self-service-friendly page (not to
    // document.form.php, which requires the admin-only READ right and
    // would deny recipients access to their own just-signed document).
    Html::redirect($self_url);
} elseif (isset($_POST['refuse'])) {
    if (!$already_signed && (int) $document->fields['status'] === Document::WAITING_ACCEPTANCE) {
        Acceptance::refuse($document, $my_role, (string) ($_POST['refusal_reason'] ?? ''));
    }
    Html::redirect($self_url);
}

$document->getFromDB($document->getID());
$already_signed = Acceptance::hasSigned($document->getID(), $my_role);
$is_waiting = (int) $document->fields['status'] === Document::WAITING_ACCEPTANCE;

$is_central = Session::getCurrentInterface() === 'central';
if ($is_central) {
    Html::header(__('Aceite do documento', 'termodocs'), '', 'management', \GlpiPlugin\Termodocs\Menu::class);
} else {
    Html::helpHeader(__('Aceite do documento', 'termodocs'));
}

TemplateRenderer::getInstance()->display('@termodocs/acceptance.html.twig', [
    'document'            => $document,
    'my_role_label'       => $document->getRoleLabel($my_role),
    'can_act'             => !$already_signed && $is_waiting,
    'waiting_other_party' => $already_signed && $is_waiting,
]);

if ($is_central) {
    Html::footer();
} else {
    Html::helpFooter();
}
