<?php

use GlpiPlugin\Termodocs\Document;
use GlpiPlugin\Termodocs\DocumentImporter;
use GlpiPlugin\Termodocs\Menu;

Session::checkRight(Document::$rightname, CREATE);

global $CFG_GLPI;
$self_url = $CFG_GLPI['root_doc'] . '/plugins/termodocs/front/document.import.php';

if (isset($_POST['do_upload']) && isset($_FILES['import_file'])) {
    try {
        $raw = file_get_contents($_FILES['import_file']['tmp_name']);
        $token = DocumentImporter::stageFromJson((string) $raw);
        Html::redirect($self_url . '?review=' . urlencode($token));
    } catch (\Throwable $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        Html::redirect($self_url);
    }
} elseif (isset($_POST['do_commit'])) {
    $token = (string) ($_POST['token'] ?? '');
    $manual = [];
    foreach ((array) ($_POST['manual'] ?? []) as $idx => $roles) {
        $manual[(int) $idx] = [
            'recipient' => (int) ($roles['recipient'] ?? 0),
            'deliverer' => (int) ($roles['deliverer'] ?? 0),
            'requester' => (int) ($roles['requester'] ?? 0),
        ];
    }

    try {
        $result = DocumentImporter::commit($token, $manual);
        Session::addMessageAfterRedirect(
            sprintf(
                __('%d documento(s) importado(s). %d ignorado(s) por falta de colaborador ou entregador definido.', 'termodocs'),
                $result['ok'],
                $result['ko']
            ),
            true,
            $result['ko'] > 0 ? WARNING : INFO
        );
    } catch (\Throwable $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }

    Html::redirect(Document::getFormURL());
}

Html::header(__('Importar documentos', 'termodocs'), '', 'management', Menu::class);

if (isset($_GET['review'])) {
    $token = (string) $_GET['review'];
    $staged = DocumentImporter::getStaged($token);

    if ($staged === null) {
        echo '<div class="alert alert-warning m-3">' . __('Lote de importação expirado ou inválido.', 'termodocs') . '</div>';
    } else {
        echo '<div class="alert alert-info m-3">' .
            __('Revise os colaboradores/entregadores não identificados por e-mail antes de confirmar.', 'termodocs') .
            '</div>';

        echo '<form method="post" action="' . $self_url . '" class="m-3">';
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('token', ['value' => $token]);

        echo '<table class="table table-sm card">';
        echo '<thead><tr><th>' . __('Documento', 'termodocs') . '</th><th>' .
            __('Colaborador', 'termodocs') . '</th><th>' .
            __('Entregador/TI', 'termodocs') . '</th><th>' .
            __('Solicitante (auditoria)', 'termodocs') . '</th></tr></thead><tbody>';

        foreach ($staged as $idx => $item) {
            $entry = $item['entry'];
            echo '<tr><td>' . htmlspecialchars($entry['name'] ?? '?') . '</td>';

            foreach (['recipient', 'deliverer', 'requester'] as $role) {
                $resolved = $item[$role];
                echo '<td>';
                if ($resolved['users_id'] > 0) {
                    echo '<span class="badge bg-green-lt"><i class="ti ti-check"></i> ' .
                        htmlspecialchars(trim($resolved['firstname'] . ' ' . $resolved['realname'])) . '</span>';
                    echo Html::hidden("manual[$idx][$role]", ['value' => $resolved['users_id']]);
                } else {
                    echo '<div class="text-danger small mb-1">' .
                        __('Não encontrado', 'termodocs') . ': ' . htmlspecialchars($resolved['email'] ?: '?') . '</div>';
                    User::dropdown(['name' => "manual[$idx][$role]", 'right' => 'all']);
                }
                echo '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo Html::submit(__('Confirmar importação', 'termodocs'), ['name' => 'do_commit']);
        echo '</form>';
    }
} else {
    echo '<form method="post" enctype="multipart/form-data" action="' . $self_url . '" class="card m-3 p-3" style="max-width:600px">';
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo '<label class="mb-2">' . __('Arquivo JSON exportado do Termodocs', 'termodocs') . '</label>';
    echo '<input type="file" name="import_file" accept="application/json" class="form-control mb-3" required>';
    echo '<div class="form-text mb-3">' .
        __('Ativos vinculados viram apenas texto informativo; não são relinkados a nenhum ativo real.', 'termodocs') .
        '</div>';
    echo Html::submit(__('Enviar', 'termodocs'), ['name' => 'do_upload']);
    echo '</form>';
}

Html::footer();
