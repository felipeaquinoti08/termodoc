<?php

namespace GlpiPlugin\Termodocs\Placeholder;

use CommonDBTM;
use Glpi\Asset\Asset;
use GlpiPlugin\Termodocs\Document;

/**
 * Discovers, for a given itemtype, which of its own fields are safe to
 * expose as placeholders (`{{ equipment.<field> }}`), reusing GLPI's own
 * searchOptions() metadata rather than hand-maintaining a field list.
 */
class CatalogBuilder
{
    private static array $cache = [];

    public static function getFieldsForItemtype(string $itemtype): array
    {
        if (isset(self::$cache[$itemtype])) {
            return self::$cache[$itemtype];
        }

        if (!class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return self::$cache[$itemtype] = [];
        }

        global $DB;
        $item = new $itemtype();
        $own_table = $item->getTable();
        $own_columns = array_keys($DB->listFields($own_table) ?: []);

        $fields = [];
        foreach ($item->searchOptions() as $opt) {
            if (!isset($opt['table'], $opt['field'], $opt['name'])) {
                continue;
            }

            if ($opt['table'] === $own_table && in_array($opt['field'], $own_columns, true)) {
                $column = $opt['field'];
                if (str_starts_with($column, 'custom_')) {
                    continue;
                }
                $fields[$column] = strip_tags((string) $opt['name']);
                continue;
            }

            $linkfield = $opt['linkfield'] ?? null;
            if (
                is_string($linkfield)
                && in_array($linkfield, $own_columns, true)
                && ($opt['datatype'] ?? '') === 'dropdown'
            ) {
                $fields[$linkfield] = strip_tags((string) $opt['name']);
            }
        }

        if (is_a($itemtype, Asset::class, true)) {
            foreach (self::getCustomFieldLabels($itemtype) as $column => $label) {
                $fields[$column] = $label;
            }
        }

        ksort($fields);

        $result = [];
        foreach ($fields as $column => $label) {
            $result[] = [
                'field' => $column,
                'label' => $label,
                'token' => '{{ equipment.' . $column . ' }}',
            ];
        }

        return self::$cache[$itemtype] = $result;
    }

    private static function getCustomFieldLabels(string $itemtype): array
    {
        $labels = [];
        $definition = $itemtype::getDefinition();
        foreach ($definition->getCustomFieldDefinitions() as $field) {
            $labels['custom_' . $field['system_name']] = $field['label'];
        }
        return $labels;
    }

    /**
     * Field catalog grouped for UI display, plus the fixed recipient/entity/
     * document groups that exist regardless of the selected itemtype.
     */
    public static function getGroupedCatalog(string $itemtype): array
    {
        return [
            __('Equipamento', 'termodocs')  => self::getFieldsForItemtype($itemtype),
            __('Colaborador (recebendo/devolvendo)', 'termodocs')  => [
                ['field' => 'firstname', 'label' => __('First name'), 'token' => '{{ user.firstname }}'],
                ['field' => 'realname', 'label' => __('Last name'), 'token' => '{{ user.realname }}'],
                ['field' => 'name', 'label' => __('Login'), 'token' => '{{ user.name }}'],
                ['field' => 'email', 'label' => _n('Email', 'Emails', 1), 'token' => '{{ user.email }}'],
                ['field' => 'phone', 'label' => __('Phone'), 'token' => '{{ user.phone }}'],
            ],
            __('Responsável (TI)', 'termodocs')  => [
                ['field' => 'firstname', 'label' => __('First name'), 'token' => '{{ requester.firstname }}'],
                ['field' => 'realname', 'label' => __('Last name'), 'token' => '{{ requester.realname }}'],
            ],
            _n('Entity', 'Entities', 1) => [
                ['field' => 'name', 'label' => __('Name'), 'token' => '{{ entity.name }}'],
                ['field' => 'address', 'label' => __('Address'), 'token' => '{{ entity.address }}'],
            ],
            Document::getTypeName(1) => [
                ['field' => 'date', 'label' => __('Data de geração', 'termodocs'), 'token' => '{{ document.date }}'],
                ['field' => 'name', 'label' => __('Name'), 'token' => '{{ document.name }}'],
            ],
        ];
    }
}
