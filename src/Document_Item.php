<?php

namespace GlpiPlugin\Termodocs;

use CommonDBRelation;

class Document_Item extends CommonDBRelation
{
    public static $itemtype_1    = Document::class;
    public static $items_id_1    = 'plugin_termodocs_documents_id';
    public static $take_entity_1 = true;

    public static $itemtype_2    = 'itemtype';
    public static $items_id_2    = 'items_id';
    public static $take_entity_2 = false;

    public static function getTypeName($nb = 0): string
    {
        return _n('Ativo vinculado', 'Ativos vinculados', $nb, 'termodocs');
    }

    public function prepareInputForAdd($input)
    {
        if (empty($input['itemtype']) || empty($input['items_id'])) {
            trigger_error('itemtype and items_id are mandatory', E_USER_WARNING);
            return false;
        }
        return parent::prepareInputForAdd($input);
    }

    public static function getItemsForDocument(int $documents_id): array
    {
        global $DB;

        $items = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_termodocs_documents_id' => $documents_id],
        ]);

        foreach ($iterator as $row) {
            $items[] = $row;
        }

        return $items;
    }
}
