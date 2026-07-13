<?php

use GlpiPlugin\Termodocs\PendingSignatures;

Session::checkLoginUser();

$is_central = Session::getCurrentInterface() === 'central';
if ($is_central) {
    Html::header(PendingSignatures::getTypeName(1), '', 'management', PendingSignatures::class);
} else {
    Html::helpHeader(PendingSignatures::getTypeName(1));
}

$rows = PendingSignatures::getMine();
usort($rows, static fn ($a, $b) => strcmp($b['document']['date_generated'], $a['document']['date_generated']));

echo '<div class="card m-3 p-3">';

$count = 0;
echo '<table class="table table-sm">';
echo '<thead><tr><th>' . __('Name') . '</th><th>' . __('Meu papel', 'termodocs') . '</th><th>' . __('Data de geração', 'termodocs') . '</th><th></th></tr></thead><tbody>';
foreach ($rows as $row) {
    $document = $row['document'];
    $count++;
    echo '<tr>';
    echo '<td>' . htmlspecialchars($document['name']) . '</td>';
    echo '<td>' . htmlspecialchars($row['role_label']) . '</td>';
    echo '<td>' . htmlspecialchars($document['date_generated']) . '</td>';
    echo '<td><a class="btn btn-primary btn-sm" href="' . $CFG_GLPI['root_doc'] .
        '/plugins/termodocs/front/acceptance.php?documents_id=' . (int) $document['id'] . '">' .
        '<i class="ti ti-signature"></i> ' . __('Revisar e assinar', 'termodocs') . '</a></td>';
    echo '</tr>';
}
echo '</tbody></table>';

if ($count === 0) {
    echo '<p class="text-muted">' . __('Nenhuma assinatura pendente no momento.', 'termodocs') . '</p>';
}

echo '</div>';

if ($is_central) {
    Html::footer();
} else {
    Html::helpFooter();
}
