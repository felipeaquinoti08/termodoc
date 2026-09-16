<?php

use GlpiPlugin\Termodocs\AssineiImporter;
use GlpiPlugin\Termodocs\Document;
use GlpiPlugin\Termodocs\DocumentTemplate;
use GlpiPlugin\Termodocs\Menu;

/**
 * Dedicated wizard for bringing documents already signed on
 * Assinei.digital *before* this integration existed into GLPI - see
 * AssineiImporter's own doc comment. Deliberately a separate screen from
 * front/document.import.php (cross-instance JSON export/import of
 * documents this plugin itself generated): the two have nothing in
 * common besides both ending with a new termodocs Document row.
 */

const TERMODOCS_ASSINEI_MAX_WIZARD_ROWS = 8;

Session::checkRight(Document::$rightname, CREATE);

global $CFG_GLPI;
$self_url = $CFG_GLPI['root_doc'] . '/plugins/termodocs/front/document.import_assinei.php';

/**
 * @return array<int, array{0: string, 1: int}>
 */
function termodocs_assinei_extract_items(array $source): array
{
    $items = [];
    foreach ((array) ($source['items'] ?? []) as $row) {
        $itemtype = $row['itemtype'] ?? '';
        $items_id = (int) ($row['items_id'] ?? 0);
        if ($itemtype !== '' && $items_id > 0) {
            $items[] = [$itemtype, $items_id];
        }
    }
    return $items;
}

function termodocs_assinei_hidden_items(array $items): void
{
    foreach ($items as $i => [$itemtype, $items_id]) {
        echo Html::hidden("items[$i][itemtype]", ['value' => $itemtype]);
        echo Html::hidden("items[$i][items_id]", ['value' => $items_id]);
    }
}

if (isset($_POST['assinei_lookup'])) {
    $documento_id = AssineiImporter::parseLink((string) ($_POST['link'] ?? ''));
    if ($documento_id === null) {
        Session::addMessageAfterRedirect(
            __('Não foi possível reconhecer um ID de documento nesse link.', 'termodocs'),
            false,
            ERROR
        );
        Html::redirect($self_url);
    }

    Html::redirect($self_url . '?step=operation&documento_id=' . urlencode($documento_id));
} elseif (isset($_POST['assinei_import'])) {
    $documento_id = (string) ($_POST['documento_id'] ?? '');
    $operation_type = (int) ($_POST['operation_type'] ?? 0);
    $templates_id = (int) ($_POST['plugin_termodocs_templates_id'] ?? 0);
    $items = termodocs_assinei_extract_items($_POST);
    $users_id_recipient = (int) ($_POST['users_id_recipient'] ?? 0);
    $users_id_deliverer = (int) ($_POST['users_id_deliverer'] ?? 0);

    if ($documento_id === '' || $templates_id <= 0 || empty($items) || $users_id_recipient <= 0 || $users_id_deliverer <= 0) {
        Session::addMessageAfterRedirect(__('Preencha todos os campos obrigatórios.', 'termodocs'), false, ERROR);
        Html::back();
    }

    (new DocumentTemplate())->check($templates_id, READ);

    try {
        $document = AssineiImporter::import(
            $documento_id,
            $operation_type,
            $templates_id,
            $users_id_recipient,
            $users_id_deliverer,
            $items
        );
        Session::addMessageAfterRedirect(__('Documento importado com sucesso.', 'termodocs'), true, INFO);
        Html::redirect(Document::getFormURLWithID($document->getID()));
    } catch (\Throwable $e) {
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        Html::back();
    }
}

Html::header(__('Importar do Assinei.digital', 'termodocs'), '', 'management', Menu::class);

$step = (string) ($_GET['step'] ?? '');

if ($step === '' ) {
    echo '<div class="card m-3 p-3" style="max-width:700px">';
    echo '<label class="mb-2">' . __('Link ou ID do documento no Assinei.digital', 'termodocs') . '</label>';
    echo '<form method="post" action="' . $self_url . '">';
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo '<input type="text" name="link" class="form-control mb-3" placeholder="https://app.assinei.digital/documentos/..." required>';
    echo '<div class="form-text mb-3">' .
        __('Aceita o link de "Documentos" (.../documentos/{id}), o link de assinatura (.../assinatura/?d={id}&...) ou apenas o ID.', 'termodocs') .
        '</div>';
    echo Html::submit(__('Buscar', 'termodocs'), ['name' => 'assinei_lookup']);
    echo '</form></div>';
} elseif ($step === 'operation') {
    $documento_id = (string) ($_GET['documento_id'] ?? '');

    try {
        $lookup = AssineiImporter::lookup($documento_id);
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger m-3">' . htmlspecialchars($e->getMessage()) . '</div>';
        Html::footer();
        exit;
    }

    echo '<div class="card m-3 p-3" style="max-width:700px">';
    echo '<h5>' . htmlspecialchars($lookup['titulo']) . '</h5>';
    echo '<table class="table table-sm mb-3"><thead><tr><th>' . __('Participante', 'termodocs') . '</th><th>' .
        __('E-mail', 'termodocs') . '</th><th>' . __('Assinado', 'termodocs') . '</th><th>' .
        __('Usuário GLPI', 'termodocs') . '</th></tr></thead><tbody>';
    foreach ($lookup['participants'] as $p) {
        echo '<tr><td>' . htmlspecialchars($p['nome']) . '</td><td>' . htmlspecialchars($p['email']) . '</td><td>';
        echo $p['assinado']
            ? '<span class="badge bg-green-lt"><i class="ti ti-check"></i> ' . __('Sim', 'termodocs') . '</span>'
            : '<span class="badge bg-orange-lt">' . __('Não', 'termodocs') . '</span>';
        echo '</td><td>' . ($p['users_id'] > 0 ? htmlspecialchars($p['user_name']) : '<span class="text-muted">' . __('Não encontrado', 'termodocs') . '</span>') . '</td></tr>';
    }
    echo '</tbody></table>';

    if (!$lookup['any_signed']) {
        echo '<div class="alert alert-warning">' .
            __('Nenhum participante assinou este documento ainda no Assinei.digital - a importação é destinada a termos já assinados.', 'termodocs') .
            '</div></div>';
        Html::footer();
        exit;
    }

    echo '<form method="get" action="' . $self_url . '">';
    echo Html::hidden('step', ['value' => 'items']);
    echo Html::hidden('documento_id', ['value' => $documento_id]);
    echo '<label class="mb-2">' . __('Esta é uma entrega ou devolução de equipamento?', 'termodocs') . '</label><br>';
    echo '<div class="d-flex gap-2 mb-2">';
    echo '<button type="submit" class="btn btn-outline-primary" name="operation_type" value="' . DocumentTemplate::OPERATION_DELIVERY . '">' .
        '<i class="ti ti-arrow-up-right"></i> ' . __('Entrega', 'termodocs') . '</button>';
    echo '<button type="submit" class="btn btn-outline-primary" name="operation_type" value="' . DocumentTemplate::OPERATION_RETURN . '">' .
        '<i class="ti ti-arrow-down-left"></i> ' . __('Devolução', 'termodocs') . '</button>';
    echo '</div>';
    echo '</form></div>';
} elseif ($step === 'items') {
    $documento_id = (string) ($_GET['documento_id'] ?? '');
    $operation_type = (int) ($_GET['operation_type'] ?? 0);
    $is_return = $operation_type === DocumentTemplate::OPERATION_RETURN;

    $eligible_itemtypes = array_keys(DocumentTemplate::getEligibleItemtypesForOperation($operation_type));
    if (empty($eligible_itemtypes)) {
        echo '<div class="alert alert-warning m-3">' .
            __('Nenhum modelo de documento ativo para esta operação.', 'termodocs') . '</div>';
        Html::footer();
        exit;
    }

    echo '<form method="get" action="' . $self_url . '" class="card m-3 p-3" style="max-width:700px">';
    echo Html::hidden('step', ['value' => 'review']);
    echo Html::hidden('documento_id', ['value' => $documento_id]);
    echo Html::hidden('operation_type', ['value' => $operation_type]);
    echo '<label class="mb-2">' . _n('Ativo associado a este documento', 'Ativos associados a este documento', 2, 'termodocs') . '</label>';
    echo '<div class="form-text mb-2">' .
        __('Todos os ativos são selecionáveis aqui, independentemente do responsável atual - este é um registro histórico.', 'termodocs') .
        '</div>';

    $dropdown_ajax_page = $CFG_GLPI['root_doc'] . '/plugins/termodocs/ajax/dropdown_items.php';

    for ($i = 0; $i < TERMODOCS_ASSINEI_MAX_WIZARD_ROWS; $i++) {
        echo '<div class="td-item-row mb-2"' . ($i > 0 ? ' style="display:none"' : '') . ' data-td-row="' . $i . '">';
        Dropdown::showSelectItemFromItemtypes([
            'itemtypes'     => $eligible_itemtypes,
            'itemtype_name' => "items[$i][itemtype]",
            'items_id_name' => "items[$i][items_id]",
            'ajax_page'     => $dropdown_ajax_page,
            'rand'          => mt_rand(),
        ]);
        echo '</div>';
    }

    echo '<button type="button" id="td-add-row-btn" class="btn btn-sm btn-outline-secondary mb-3">' .
        '<i class="ti ti-plus"></i> ' . __('Adicionar outro ativo', 'termodocs') . '</button><br>';
    echo Html::submit(__('Continuar'), ['name' => 'continue']);
    echo '</form>';

    $max_rows = TERMODOCS_ASSINEI_MAX_WIZARD_ROWS;
    echo Html::scriptBlock(<<<JS
        (function() {
            const maxRows = {$max_rows};
            const addBtn = document.getElementById('td-add-row-btn');
            let nextRow = 1;
            addBtn.addEventListener('click', function() {
                const row = document.querySelector('.td-item-row[data-td-row="' + nextRow + '"]');
                if (row) {
                    row.style.display = '';
                    nextRow++;
                }
                if (nextRow >= maxRows) {
                    addBtn.style.display = 'none';
                }
            });
        })();
    JS);
} elseif ($step === 'review') {
    $documento_id = (string) ($_GET['documento_id'] ?? '');
    $operation_type = (int) ($_GET['operation_type'] ?? 0);
    $items = termodocs_assinei_extract_items($_GET);
    $itemtypes_selected = array_unique(array_column($items, 0));

    if (empty($items)) {
        echo '<div class="alert alert-warning m-3">' . __('Selecione ao menos um ativo.', 'termodocs') . '</div>';
        Html::footer();
        exit;
    }

    try {
        $lookup = AssineiImporter::lookup($documento_id);
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger m-3">' . htmlspecialchars($e->getMessage()) . '</div>';
        Html::footer();
        exit;
    }

    $templates = [];
    $template_obj = new DocumentTemplate();
    foreach ($template_obj->find(['is_active' => 1, 'is_deleted' => 0, 'operation_type' => $operation_type]) as $id => $row) {
        $allowed = json_decode((string) ($row['allowed_itemtypes'] ?? '[]'), true) ?: [];
        if (count(array_diff($itemtypes_selected, $allowed)) > 0) {
            continue;
        }
        if (count($items) > 1 && empty($row['allow_multiple_items'])) {
            continue;
        }
        $templates[$id] = $row['name'];
    }

    if (empty($templates)) {
        echo '<div class="alert alert-warning m-3">' .
            __('Nenhum modelo de documento disponível para os ativos selecionados.', 'termodocs') . '</div>';
        Html::footer();
        exit;
    }

    echo '<div class="card m-3 p-3" style="max-width:700px">';
    echo '<h5>' . htmlspecialchars($lookup['titulo']) . '</h5>';

    echo '<form method="post" action="' . $self_url . '">';
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo Html::hidden('documento_id', ['value' => $documento_id]);
    echo Html::hidden('operation_type', ['value' => $operation_type]);
    termodocs_assinei_hidden_items($items);

    echo '<div class="mb-3">' . DocumentTemplate::getTypeName(1) . '<br>';
    Dropdown::showFromArray('plugin_termodocs_templates_id', $templates);
    echo '</div>';

    echo '<div class="mb-3">' . __('Colaborador (quem assinou no Assinei)', 'termodocs') . '<br>';
    User::dropdown([
        'name'  => 'users_id_recipient',
        'right' => 'all',
        'value' => $lookup['suggested_recipient'],
    ]);
    echo '</div>';

    echo '<div class="mb-3">' . __('Responsável (TI)', 'termodocs') . '<br>';
    User::dropdown([
        'name'  => 'users_id_deliverer',
        'right' => 'all',
        'value' => Session::getLoginUserID(),
    ]);
    echo '<div class="form-text">' .
        __('O Assinei.digital não registra um segundo signatário do lado da TI para este tipo de termo - selecione quem está confirmando esta importação.', 'termodocs') .
        '</div>';
    echo '</div>';

    echo Html::submit(__('Importar documento', 'termodocs'), ['name' => 'assinei_import']);
    echo '</form></div>';
}

Html::footer();
