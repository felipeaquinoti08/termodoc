<?php

use GlpiPlugin\Termodocs\Acceptance;
use GlpiPlugin\Termodocs\Document;
use GlpiPlugin\Termodocs\DocumentGen\DocumentGenerator;
use GlpiPlugin\Termodocs\Document_Item;
use GlpiPlugin\Termodocs\DocumentTemplate;
use GlpiPlugin\Termodocs\Menu;
use GlpiPlugin\Termodocs\Signature\SignatureProviderManager;

const TERMODOCS_MAX_WIZARD_ROWS = 8;

Session::checkRight(Document::$rightname, READ);

/**
 * Reads items[N][itemtype]/items[N][items_id] from a request array,
 * keeping only complete, non-empty pairs.
 *
 * @return array<int, array{0: string, 1: int}>
 */
function termodocs_extract_items(array $source): array
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

function termodocs_hidden_items(array $items): void
{
    foreach ($items as $i => [$itemtype, $items_id]) {
        echo Html::hidden("items[$i][itemtype]", ['value' => $itemtype]);
        echo Html::hidden("items[$i][items_id]", ['value' => $items_id]);
    }
}

/**
 * Ownership-consistency check for a set of selected assets against the
 * chosen operation direction: on Entrega, an asset must currently have
 * no owner (it must be returned first before it can be delivered again);
 * on Devolução, it must currently belong to somebody, and every selected
 * asset must belong to the *same* somebody (the person doing the
 * returning). Itemtypes without a `users_id` field (no ownership concept)
 * are silently skipped. Returns an error message, or null if all clear.
 */
function termodocs_validate_ownership(array $items, bool $is_return): ?string
{
    $owners = [];

    foreach ($items as [$itemtype, $items_id]) {
        $obj = getItemForItemtype($itemtype);
        if (!$obj || !$obj->getFromDB($items_id) || !$obj->isField('users_id')) {
            continue;
        }

        $label = $itemtype::getTypeName(1) . ' ' . $obj->getName();
        $current_owner = (int) $obj->fields['users_id'];

        if ($is_return) {
            if ($current_owner === 0) {
                return sprintf(__('%s não está associado a nenhum usuário; não pode ser devolvido.', 'termodocs'), $label);
            }
            $owners[$current_owner] = true;
        } elseif ($current_owner !== 0) {
            return sprintf(__('%s já está associado a um usuário; devolva-o antes de uma nova entrega.', 'termodocs'), $label);
        }
    }

    if ($is_return && count($owners) > 1) {
        return __('Os ativos selecionados pertencem a colaboradores diferentes; selecione ativos de apenas um colaborador por devolução.', 'termodocs');
    }

    return null;
}

function termodocs_return_owner(array $items): int
{
    foreach ($items as [$itemtype, $items_id]) {
        $obj = getItemForItemtype($itemtype);
        if ($obj && $obj->getFromDB($items_id) && $obj->isField('users_id') && (int) $obj->fields['users_id'] !== 0) {
            return (int) $obj->fields['users_id'];
        }
    }
    return 0;
}

if (isset($_POST['do_generate'])) {
    Session::checkRight(Document::$rightname, CREATE);

    $items = termodocs_extract_items($_POST);
    if (empty($items)) {
        Session::addMessageAfterRedirect(__('Selecione ao menos um ativo.', 'termodocs'), false, ERROR);
        Html::back();
    }

    $template = new DocumentTemplate();
    $template->check((int) $_POST['plugin_termodocs_templates_id'], READ);

    // Re-validated here regardless of what step 2 already checked: GET/POST
    // params are user-editable, and this is the authoritative point past
    // which the asset actually changes owner.
    $ownership_error = termodocs_validate_ownership($items, $template->isReturn());
    if ($ownership_error !== null) {
        Session::addMessageAfterRedirect($ownership_error, false, ERROR);
        Html::back();
    }

    $users_id_recipient = (int) $_POST['users_id_recipient'];
    if ($template->isReturn()) {
        $owner = termodocs_return_owner($items);
        if ($owner !== 0 && $owner !== $users_id_recipient) {
            Session::addMessageAfterRedirect(
                __('O colaborador informado não é quem está associado a este(s) ativo(s).', 'termodocs'),
                false,
                ERROR
            );
            Html::back();
        }
    }

    $document = DocumentGenerator::getInstance()->generate(
        $template,
        $items,
        $users_id_recipient,
        (int) $_SESSION['glpiactive_entity'],
        (int) ($_POST['users_id_deliverer'] ?? 0)
    );

    Html::redirect(Document::getFormURLWithID($document->getID()));
} elseif (isset($_POST['cancel'])) {
    Session::checkRight(Document::$rightname, PURGE);

    $document = new Document();
    $document->getFromDB((int) $_POST['id']);
    if ((int) $document->fields['status'] === Document::WAITING_ACCEPTANCE) {
        $document->update([
            'id'     => $document->getID(),
            'status' => Document::CANCELED,
        ]);
    }
    Html::back();
} elseif (isset($_POST['save_notes'])) {
    // Purely operational annotation (condition on physical
    // handover/return + a free-text note) - never touches
    // rendered_html/content_hash (the frozen legal record) or the
    // signature flow, so it's allowed regardless of status.
    Session::checkRight(Document::$rightname, CREATE);

    $document = new Document();
    if (!$document->getFromDB((int) $_POST['id'])) {
        Html::back();
    }

    $document->update([
        'id'    => $document->getID(),
        'notes' => trim((string) ($_POST['notes'] ?? '')),
    ]);

    $valid_conditions = array_keys(Document::getConditionOptions());
    $submitted = $_POST['condition'] ?? [];
    foreach (Document_Item::getItemsForDocument($document->getID()) as $linked) {
        $condition = (string) ($submitted[$linked['id']] ?? '');
        (new Document_Item())->update([
            'id'        => $linked['id'],
            'condition' => in_array($condition, $valid_conditions, true) ? $condition : null,
        ]);
    }

    // Bakes the just-saved condition/notes into a fresh copy of the PDF -
    // never touches rendered_html/content_hash (the frozen legal text
    // itself stays exactly as generated), only the downloadable file.
    $document->getFromDB($document->getID());
    $new_pdf_id = DocumentGenerator::getInstance()->regeneratePdfWithConditions($document);
    if ($new_pdf_id > 0) {
        $document->update(['id' => $document->getID(), 'pdf_document_id' => $new_pdf_id]);
    }

    Html::redirect(Document::getFormURLWithID($document->getID()));
} elseif (isset($_POST['send_signature'])) {
    // Deliberately gated the same as generating a document in the first
    // place (CREATE), not just READ - picking a provider here can kick
    // off a real external side effect (e-mails to the
    // recipient/deliverer) that a plain viewer of the document
    // shouldn't be able to trigger.
    Session::checkRight(Document::$rightname, CREATE);

    $document = new Document();
    if (!$document->getFromDB((int) $_POST['id'])) {
        Html::back();
    }

    // Only an already-known provider key is ever accepted - falls back
    // to whatever the document already had rather than trusting an
    // unrecognized value from the request.
    $available_providers = SignatureProviderManager::getInstance()->getAvailableProviders();
    $chosen_provider = (string) ($_POST['signature_provider'] ?? '');
    if (!array_key_exists($chosen_provider, $available_providers)) {
        $chosen_provider = $document->fields['signature_provider'] ?? SignatureProviderManager::INTERNAL;
    }

    // Re-checked here (not just in Document::showForm()'s
    // can_choose_signature, which only controls whether the picker is
    // shown) - status/acceptances could have changed since the page
    // was rendered, e.g. two admins racing on the same document. Once
    // either party has actually acted (an Acceptance row exists) or the
    // document was already sent externally (external_reference set),
    // the provider is locked - switching after that point could leave
    // a signature already recorded under a provider the document no
    // longer claims to use, or send a document to a second provider
    // while it's still out for signature on the first one.
    $not_yet_engaged = (int) $document->fields['status'] === Document::WAITING_ACCEPTANCE
        && empty($document->fields['external_reference'])
        && Acceptance::getForRole($document->getID(), Acceptance::ROLE_RECIPIENT) === null
        && Acceptance::getForRole($document->getID(), Acceptance::ROLE_DELIVERER) === null;

    if ($not_yet_engaged) {
        if ($chosen_provider !== $document->fields['signature_provider']) {
            $document->update(['id' => $document->getID(), 'signature_provider' => $chosen_provider]);
            $document->getFromDB($document->getID());
        }

        if ($chosen_provider !== SignatureProviderManager::INTERNAL) {
            SignatureProviderManager::getInstance()->resolve($chosen_provider)->initiate($document);
        }
    }

    Html::redirect(Document::getFormURLWithID($document->getID()));
} elseif (isset($_GET['generate']) && empty($_GET['operation_type'])) {
    // Step 0: which direction is this document for? Drives both which
    // assets can be picked next and how they get validated.
    Session::checkRight(Document::$rightname, CREATE);

    Html::header(__('Gerar documento', 'termodocs'), '', 'management', Menu::class);

    // Preserve any assets already pre-selected (e.g. from the "Gerar
    // documento" button on an asset's own tab) so choosing the operation
    // here doesn't lose them - they carry straight through to step 2.
    $preselected_items = termodocs_extract_items($_GET);
    $items_qs = '';
    foreach ($preselected_items as $i => [$itemtype, $items_id]) {
        $items_qs .= '&items[' . $i . '][itemtype]=' . urlencode($itemtype) . '&items[' . $i . '][items_id]=' . $items_id;
    }

    echo '<div class="card m-3 p-3" style="max-width:700px">';
    echo '<label class="mb-3">' . __('O que você quer registrar?', 'termodocs') . '</label>';
    echo '<div class="d-flex gap-2">';
    echo '<a class="btn btn-outline-primary" href="' . Document::getFormURL() .
        '?generate=1&operation_type=' . DocumentTemplate::OPERATION_DELIVERY . $items_qs . '">' .
        '<i class="ti ti-arrow-up-right"></i> ' . __('Entrega', 'termodocs') . '</a>';
    echo '<a class="btn btn-outline-primary" href="' . Document::getFormURL() .
        '?generate=1&operation_type=' . DocumentTemplate::OPERATION_RETURN . $items_qs . '">' .
        '<i class="ti ti-arrow-down-left"></i> ' . __('Devolução', 'termodocs') . '</a>';
    echo '</div></div>';

    Html::footer();
} elseif (isset($_GET['generate']) && empty($_GET['items'])) {
    // Step 1: operation known, no assets pre-selected yet - pick one or
    // more assets, of possibly different types, but only among itemtypes
    // that actually have a template for this operation direction.
    Session::checkRight(Document::$rightname, CREATE);

    $operation_type = (int) $_GET['operation_type'];
    $is_return = $operation_type === DocumentTemplate::OPERATION_RETURN;

    Html::header(__('Gerar documento', 'termodocs'), '', 'management', Menu::class);

    $eligible_itemtypes = array_keys(DocumentTemplate::getEligibleItemtypesForOperation($operation_type));

    if (empty($eligible_itemtypes)) {
        echo '<div class="alert alert-warning m-3">' .
            __('Nenhum modelo de documento ativo para esta operação.', 'termodocs') . '</div>';
        Html::footer();
        exit;
    }

    echo '<div class="alert alert-info m-3" style="max-width:700px">' .
        ($is_return
            ? __('Devolução: selecione ativo(s) que já estejam associados a um colaborador.', 'termodocs')
            : __('Entrega: selecione ativo(s) que ainda não estejam associados a um colaborador.', 'termodocs')) .
        '</div>';

    echo '<form method="get" action="' . Document::getFormURL() . '" class="card m-3 p-3" style="max-width:700px">';
    echo Html::hidden('generate', ['value' => 1]);
    echo Html::hidden('operation_type', ['value' => $operation_type]);
    echo '<label class="mb-2">' . _n('Ativo', 'Ativos', 2, 'termodocs') . '</label>';

    global $CFG_GLPI;
    $dropdown_ajax_page = $CFG_GLPI['root_doc'] . '/plugins/termodocs/ajax/dropdown_items.php?termodocs_mode=' .
        ($is_return ? 'assigned' : 'available');

    for ($i = 0; $i < TERMODOCS_MAX_WIZARD_ROWS; $i++) {
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

    $max_rows = TERMODOCS_MAX_WIZARD_ROWS;
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

    Html::footer();
} elseif (isset($_GET['generate'])) {
    // Step 2: operation + assets known - validate ownership, then pick
    // template + recipient + deliverer.
    Session::checkRight(Document::$rightname, CREATE);

    $operation_type = (int) $_GET['operation_type'];
    $is_return = $operation_type === DocumentTemplate::OPERATION_RETURN;
    $items = termodocs_extract_items($_GET);
    $itemtypes_selected = array_unique(array_column($items, 0));

    Html::header(__('Gerar documento', 'termodocs'), '', 'management', Menu::class);

    if (empty($items)) {
        echo '<div class="alert alert-warning m-3">' . __('Selecione ao menos um ativo.', 'termodocs') . '</div>';
        Html::footer();
        exit;
    }

    $ownership_error = termodocs_validate_ownership($items, $is_return);
    if ($ownership_error !== null) {
        echo '<div class="alert alert-danger m-3" style="max-width:700px">' . htmlspecialchars($ownership_error) . '</div>';
        echo '<div class="m-3"><a class="btn btn-outline-secondary" href="' . Document::getFormURL() .
            '?generate=1&operation_type=' . $operation_type . '">' . __('Voltar', 'termodocs') . '</a></div>';
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
    echo '<label class="mb-1">' . _n('Ativo selecionado', 'Ativos selecionados', count($items), 'termodocs') . '</label>';
    echo '<ul class="mb-3">';
    foreach ($items as [$itemtype, $items_id]) {
        $obj = getItemForItemtype($itemtype);
        $label = ($obj && $obj->getFromDB($items_id)) ? $obj->getName() : "#$items_id";
        echo '<li>' . htmlspecialchars($itemtype::getTypeName(1)) . ': ' . htmlspecialchars($label) . '</li>';
    }
    echo '</ul>';

    echo '<form method="post" action="' . Document::getFormURL() . '">';
    termodocs_hidden_items($items);
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo '<div class="mb-3">' . DocumentTemplate::getTypeName(1) . '<br>';
    Dropdown::showFromArray('plugin_termodocs_templates_id', $templates);
    echo '</div>';

    if ($is_return) {
        $owner = termodocs_return_owner($items);
        $owner_obj = new User();
        $owner_obj->getFromDB($owner);
        echo '<div class="mb-3">' . __('Colaborador (devolvendo)', 'termodocs') . '<br>';
        echo '<strong>' . htmlspecialchars($owner_obj->getFriendlyName()) . '</strong>';
        echo Html::hidden('users_id_recipient', ['value' => $owner]);
        echo '<div class="form-text">' . __('Determinado automaticamente pelo responsável atual do(s) ativo(s).', 'termodocs') . '</div>';
        echo '</div>';
        echo '<div class="mb-3">' . __('Recebido por (TI)', 'termodocs') . '<br>';
        User::dropdown(['name' => 'users_id_deliverer', 'right' => 'all', 'value' => Session::getLoginUserID()]);
        echo '</div>';
    } else {
        echo '<div class="mb-3">' . __('Colaborador (recebendo)', 'termodocs') . '<br>';
        User::dropdown(['name' => 'users_id_recipient', 'right' => 'all']);
        echo '</div>';
        echo '<div class="mb-3">' . __('Entregador', 'termodocs') . '<br>';
        User::dropdown(['name' => 'users_id_deliverer', 'right' => 'all', 'value' => Session::getLoginUserID()]);
        echo '</div>';
    }

    echo Html::submit(__('Gerar documento', 'termodocs'), ['name' => 'do_generate']);
    echo '</form></div>';

    Html::footer();
} else {
    Html::header(Document::getTypeName(1), '', 'management', Menu::class);
    $item = new Document();
    $item->display(['id' => $_GET['id'] ?? 0]);
    Html::footer();
}
