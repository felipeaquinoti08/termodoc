<?php

use GlpiPlugin\Termodocs\Document;

/**
 * Drop-in replacement for core's ajax/dropdownAllItems.php, used as the
 * `ajax_page` override for Dropdown::showSelectItemFromItemtypes() in the
 * document wizard: forces a `users_id` condition server-side (never
 * trusting a client-supplied one) so the items_id picker itself only
 * offers assets consistent with the chosen operation - unassociated
 * assets for Entrega, already-associated ones for Devolução - instead of
 * only catching the mismatch after the fact.
 */

global $CFG_GLPI;

header('Content-Type: text/html; charset=UTF-8');
Html::header_nocache();

Session::checkRight(Document::$rightname, CREATE);

$itemtype = $_POST['idtable'] ?? '';
if (!$itemtype || !class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
    exit;
}

$mode = $_GET['termodocs_mode'] ?? '';

if (isset($_POST['entity_restrict'])) {
    $_POST['entity_restrict'] = Session::getMatchingActiveEntities($_POST['entity_restrict']);
}

$condition_array = null;
$tmp = new $itemtype();
if ($tmp->isField('users_id') && in_array($mode, ['available', 'assigned'], true)) {
    $condition_array = $mode === 'available' ? ['users_id' => 0] : ['NOT' => ['users_id' => 0]];
}

$link = ($itemtype === 'User') ? 'getDropdownUsers.php' : 'getDropdownValue.php';

$rand = (int) ($_POST['rand'] ?? mt_rand());
$field_id = Html::cleanId('dropdown_' . $_POST['name'] . $rand);
$displaywith = Dropdown::getDisplayWith($itemtype);

$p = [
    'value'               => 0,
    'valuename'           => Dropdown::EMPTY_VALUE,
    'itemtype'            => $itemtype,
    'display_emptychoice' => $_POST['display_emptychoice'] ?? true,
    'displaywith'         => $displaywith,
];
$idor_params = [
    'displaywith' => $displaywith,
];

if (isset($_POST['entity_restrict'])) {
    $p['entity_restrict']           = $_POST['entity_restrict'];
    $idor_params['entity_restrict'] = $_POST['entity_restrict'];
}

if ($condition_array !== null) {
    $condition_hash = Dropdown::addNewCondition($condition_array);
    $p['condition']           = $condition_hash;
    $idor_params['condition'] = $condition_hash;
}

if (isset($_POST['used']) && !is_array($_POST['used'])) {
    $_POST['used'] = Toolbox::jsonDecode($_POST['used'], true);
}
if (isset($_POST['used'][$itemtype])) {
    $p['used'] = $_POST['used'][$itemtype];
}
if (isset($_POST['width'])) {
    $p['width'] = $_POST['width'];
}

$p['_idor_token'] = Session::getNewIDORToken($itemtype, $idor_params);

echo Html::jsAjaxDropdown(
    $_POST['name'],
    $field_id,
    $CFG_GLPI['root_doc'] . '/ajax/' . $link,
    $p
);
