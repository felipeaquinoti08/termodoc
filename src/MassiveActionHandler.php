<?php

namespace GlpiPlugin\Termodocs;

use CommonDBTM;
use Dropdown;
use GlpiPlugin\Termodocs\DocumentGen\DocumentGenerator;
use Html;
use MassiveAction;
use Session;
use Throwable;
use User;

/**
 * Plugs a "Generate document (Termodocs)" massive action into the search
 * lists of asset itemtypes the plugin does not own (Computer, Monitor...),
 * via the AUTO_MASSIVE_ACTIONS hook - the idiomatic way for a plugin to
 * extend massive actions on itemtypes it doesn't control.
 */
class MassiveActionHandler
{
    public const ACTION_GENERATE = 'generate_document';

    public static function getActionsForItemtype(string $itemtype): array
    {
        if (!Document::canCreate() || empty(self::getEligibleTemplates($itemtype))) {
            return [];
        }

        return [
            self::class . MassiveAction::CLASS_ACTION_SEPARATOR . self::ACTION_GENERATE
                => __('Gerar documento (Termodocs)', 'termodocs'),
        ];
    }

    private static function getEligibleTemplates(string $itemtype): array
    {
        $template = new DocumentTemplate();
        $eligible = [];
        foreach ($template->find(['is_active' => 1, 'is_deleted' => 0]) as $id => $row) {
            $allowed = json_decode((string) ($row['allowed_itemtypes'] ?? '[]'), true) ?: [];
            if (in_array($itemtype, $allowed, true)) {
                $eligible[$id] = $row;
            }
        }
        return $eligible;
    }

    public static function showMassiveActionsSubForm(MassiveAction $ma): bool
    {
        if ($ma->getAction() !== self::ACTION_GENERATE) {
            return false;
        }

        $itemtype = $ma->POST['itemtype'] ?? '';
        $templates = self::getEligibleTemplates($itemtype);

        if (empty($templates)) {
            echo '<div class="alert alert-warning">' .
                __('Nenhum modelo de documento disponível para este tipo de ativo.', 'termodocs') . '</div>';
            return true;
        }

        $options = [];
        foreach ($templates as $id => $row) {
            $options[$id] = $row['name'];
        }

        echo '<table class="tab_cadre_fixe"><tr><td>';
        echo DocumentTemplate::getTypeName(1);
        echo '</td><td>';
        Dropdown::showFromArray('plugin_termodocs_templates_id', $options);
        echo '</td></tr><tr><td>' . __('Destinatário', 'termodocs') . '</td><td>';
        User::dropdown(['name' => 'users_id_recipient', 'right' => 'all']);
        echo '</td></tr></table>';

        echo Html::submit(_x('button', 'Post'), ['name' => 'massiveaction']);

        return true;
    }

    public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids): void
    {
        if ($ma->getAction() !== self::ACTION_GENERATE) {
            return;
        }

        $itemtype = $item->getType();
        $templates_id = (int) ($ma->POST['plugin_termodocs_templates_id'] ?? 0);
        $users_id_recipient = (int) ($ma->POST['users_id_recipient'] ?? 0);

        $template = new DocumentTemplate();
        if (!$templates_id || !$users_id_recipient || !$template->getFromDB($templates_id)) {
            $ma->addMessage(__('Modelo ou destinatário não informado.', 'termodocs'));
            foreach ($ids as $id) {
                $ma->itemDone($itemtype, $id, MassiveAction::ACTION_KO);
            }
            return;
        }

        $entities_id = Session::getActiveEntity();
        $generator = DocumentGenerator::getInstance();

        try {
            if ((bool) $template->fields['allow_multiple_items']) {
                $items = array_map(static fn($id) => [$itemtype, (int) $id], $ids);
                $generator->generate($template, $items, $users_id_recipient, $entities_id);
                foreach ($ids as $id) {
                    $ma->itemDone($itemtype, $id, MassiveAction::ACTION_OK);
                }
            } else {
                foreach ($ids as $id) {
                    $generator->generate($template, [[$itemtype, (int) $id]], $users_id_recipient, $entities_id);
                    $ma->itemDone($itemtype, $id, MassiveAction::ACTION_OK);
                }
            }
        } catch (Throwable $e) {
            foreach ($ids as $id) {
                $ma->itemDone($itemtype, $id, MassiveAction::ACTION_KO);
            }
            $ma->addMessage($e->getMessage());
        }
    }
}
