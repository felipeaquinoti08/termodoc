<?php

use GlpiPlugin\Termodocs\DocumentTemplate;
use GlpiPlugin\Termodocs\Menu;

Session::checkRight(DocumentTemplate::$rightname, CREATE);

global $CFG_GLPI;
$self_url = $CFG_GLPI['root_doc'] . '/plugins/termodocs/front/documenttemplate.import.php';

if (isset($_POST['do_import']) && isset($_FILES['import_file'])) {
    $raw = file_get_contents($_FILES['import_file']['tmp_name']);
    $data = json_decode((string) $raw, true);

    if (!is_array($data) || empty($data['templates']) || !is_array($data['templates'])) {
        Session::addMessageAfterRedirect(__('Arquivo inválido.', 'termodocs'), false, ERROR);
        Html::redirect($self_url);
    }

    $ok = 0;
    $ko = 0;
    foreach ($data['templates'] as $entry) {
        try {
            DocumentTemplate::importOne($entry);
            $ok++;
        } catch (\Throwable $e) {
            $ko++;
        }
    }

    Session::addMessageAfterRedirect(
        sprintf(
            __('%d modelo(s) importado(s) com sucesso (inativos - revise e ative cada um). %d falharam.', 'termodocs'),
            $ok,
            $ko
        ),
        true,
        $ko > 0 ? WARNING : INFO
    );
    Html::redirect(DocumentTemplate::getFormURL());
}

Html::header(__('Importar modelos', 'termodocs'), '', 'management', Menu::class);

echo '<form method="post" enctype="multipart/form-data" action="' . $self_url . '" class="card m-3 p-3" style="max-width:600px">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<label class="mb-2">' . __('Arquivo JSON exportado do Termodocs', 'termodocs') . '</label>';
echo '<input type="file" name="import_file" accept="application/json" class="form-control mb-3" required>';
echo '<div class="form-text mb-3">' .
    __('Os modelos importados entram desativados, para você revisar antes de ativar.', 'termodocs') .
    '</div>';
echo Html::submit(__('Importar', 'termodocs'), ['name' => 'do_import']);
echo '</form>';

Html::footer();
