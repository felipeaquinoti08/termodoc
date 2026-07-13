<?php

namespace GlpiPlugin\Termodocs\Placeholder;

use CommonDBTM;
use Dropdown;
use Entity;
use User;

/**
 * Turns selected GLPI items + recipient/requester/entity into the plain
 * associative-array context passed to the sandboxed Twig renderer.
 *
 * Deliberately never exposes CommonDBTM objects to the template context:
 * only scalar values, resolved ahead of time.
 */
class ContextResolver
{
    public function resolve(
        array $items,
        User $recipient,
        User $requester,
        int $entities_id,
        array $document_meta
    ): array {
        $entity = new Entity();
        $entity->getFromDB($entities_id);

        $equipment_list = [];
        foreach ($items as [$itemtype, $items_id]) {
            $equipment_list[] = $this->resolveItem($itemtype, (int) $items_id);
        }

        return [
            'equipment' => $equipment_list[0] ?? [],
            'items'     => $equipment_list,
            'user'      => $this->resolveUser($recipient),
            'requester' => $this->resolveUser($requester),
            'entity'    => [
                'name'    => $entity->fields['name'] ?? '',
                'address' => $entity->fields['address'] ?? '',
            ],
            'document'  => $document_meta,
        ];
    }

    private function resolveItem(string $itemtype, int $items_id): array
    {
        if (!class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return [];
        }

        $item = new $itemtype();
        if (!$item->getFromDB($items_id)) {
            return [];
        }

        $data = [];
        foreach (CatalogBuilder::getFieldsForItemtype($itemtype) as $field) {
            $column = $field['field'];
            if (!array_key_exists($column, $item->fields)) {
                continue;
            }
            $data[$column] = $this->formatValue($item, $column, $item->fields[$column]);
        }

        $data['itemtype']  = $itemtype;
        $data['items_id']  = $items_id;
        $data['type_name'] = $itemtype::getTypeName(1);

        return $data;
    }

    private function formatValue(CommonDBTM $item, string $column, mixed $value): string
    {
        foreach ($item->searchOptions() as $opt) {
            $linkfield = $opt['linkfield'] ?? null;
            if ($linkfield === $column && ($opt['datatype'] ?? '') === 'dropdown') {
                return (string) Dropdown::getDropdownName($opt['table'], (int) $value);
            }
        }

        return (string) $value;
    }

    private function resolveUser(User $user): array
    {
        return [
            'firstname' => $user->fields['firstname'] ?? '',
            'realname'  => $user->fields['realname'] ?? '',
            'name'      => $user->fields['name'] ?? '',
            'email'     => $user->isNewItem() ? '' : ($user->getDefaultEmail() ?: ''),
            'phone'     => $user->fields['phone'] ?? '',
        ];
    }
}
