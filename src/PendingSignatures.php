<?php

namespace GlpiPlugin\Termodocs;

use CommonGLPI;
use QuerySubQuery;
use Session;

/**
 * A standalone menu entry visible to *any* logged-in user (not gated by
 * plugin:termodocs:document, which only IT staff hold) - lists the
 * documents where the current user holds either role (recipient or
 * deliverer) and that role hasn't signed yet, so anyone can find what's
 * pending on them without any admin right.
 */
class PendingSignatures extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Assinaturas pendentes', 'termodocs');
    }

    public static function getMenuName(): string
    {
        return __('Assinaturas pendentes', 'termodocs');
    }

    public static function getIcon(): string
    {
        return 'ti ti-signature';
    }

    public static function canView(): bool
    {
        return Session::getLoginUserID() !== false;
    }

    /**
     * Documents where the current user holds a role (recipient or
     * deliverer) and hasn't signed that role yet, still waiting overall.
     */
    private static function pendingCriteriaForRole(string $user_field, int $role): array
    {
        return [
            $user_field => Session::getLoginUserID(),
            'status'     => Document::WAITING_ACCEPTANCE,
            'is_deleted' => 0,
            'NOT'        => [
                'id' => new QuerySubQuery([
                    'SELECT' => 'plugin_termodocs_documents_id',
                    'FROM'   => 'glpi_plugin_termodocs_acceptances',
                    'WHERE'  => ['role' => $role],
                ]),
            ],
        ];
    }

    public static function countMine(): int
    {
        return count(self::getMine());
    }

    /**
     * @return array<int,array{document:array,role_label:string}> keyed by document id
     */
    public static function getMine(): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_termodocs_documents',
            'WHERE' => self::pendingCriteriaForRole('users_id_recipient', Acceptance::ROLE_RECIPIENT),
        ]) as $row) {
            $role_label = Document::getRoleLabelForTemplate((int) $row['plugin_termodocs_templates_id'], Acceptance::ROLE_RECIPIENT);
            $rows[$row['id']] = ['document' => $row, 'role_label' => $role_label];
        }

        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_termodocs_documents',
            'WHERE' => self::pendingCriteriaForRole('users_id_deliverer', Acceptance::ROLE_DELIVERER),
        ]) as $row) {
            $role_label = Document::getRoleLabelForTemplate((int) $row['plugin_termodocs_templates_id'], Acceptance::ROLE_DELIVERER);
            $rows[$row['id']] = ['document' => $row, 'role_label' => $role_label];
        }

        return $rows;
    }
}
