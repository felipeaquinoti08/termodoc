<?php

namespace GlpiPlugin\Termodocs;

use CommonDBChild;
use CommonDBTM;
use GlpiPlugin\Termodocs\Signature\SignatureProviderManager;
use Session;
use Toolbox;
use User;

/**
 * A document needs signatures from *both* parties - the recipient
 * (colaborador, who always signs, whether receiving or returning) and
 * the deliverer (the IT-side counterpart) - to be finalized. Each gets
 * its own row here, keyed by (document, role); the document only
 * becomes ACCEPTED once both have signed, or REFUSED as soon as either
 * one refuses.
 */
class Acceptance extends CommonDBChild
{
    public static $itemtype  = Document::class;
    public static $items_id  = 'plugin_termodocs_documents_id';
    public static $rightname = 'plugin:termodocs:acceptance';

    public const ROLE_RECIPIENT = 1;
    public const ROLE_DELIVERER = 2;

    public static function getTypeName($nb = 0): string
    {
        return _n('Aceite', 'Aceites', $nb, 'termodocs');
    }

    public static function hasSigned(int $documents_id, int $role): bool
    {
        return self::getForRole($documents_id, $role) !== null;
    }

    public static function getForRole(int $documents_id, int $role): ?self
    {
        $acceptance = new self();
        if (
            $acceptance->getFromDBByCrit([
                'plugin_termodocs_documents_id' => $documents_id,
                'role'                          => $role,
            ])
        ) {
            return $acceptance;
        }
        return null;
    }

    /**
     * Records this role's formal acceptance of a document, after
     * re-checking the content hash so the acceptance always refers to
     * exactly what was shown on screen. A no-op (returns the existing
     * row) if this role already signed.
     */
    public static function accept(Document $document, int $role): self
    {
        return self::record($document, $role, Document::ACCEPTED, null);
    }

    public static function refuse(Document $document, int $role, string $reason): self
    {
        return self::record($document, $role, Document::REFUSED, $reason);
    }

    private static function record(Document $document, int $role, int $status, ?string $reason): self
    {
        $existing = self::getForRole($document->getID(), $role);
        if ($existing !== null) {
            return $existing;
        }

        $user = new User();
        $user->getFromDB(Session::getLoginUserID());

        $computed_hash = hash('sha256', $document->fields['rendered_html']);

        $acceptance = new self();
        $acceptance->add([
            'plugin_termodocs_documents_id' => $document->getID(),
            'role'                           => $role,
            'users_id'                       => Session::getLoginUserID(),
            'status'                         => $status,
            'accepted_name_snapshot'         => $user->getFriendlyName(),
            'ip_address'                     => Toolbox::getRemoteIpAddress(),
            'user_agent'                     => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'content_hash_signed'            => $computed_hash,
            'refusal_reason'                 => $reason,
            'signature_provider'             => $document->fields['signature_provider'] ?? SignatureProviderManager::INTERNAL,
        ]);

        self::recomputeDocumentStatus($document);

        return $acceptance;
    }

    /**
     * REFUSED as soon as either party refuses; ACCEPTED only once both
     * ROLE_RECIPIENT and ROLE_DELIVERER have an accepted row; otherwise
     * stays WAITING_ACCEPTANCE (still needs the other party).
     */
    private static function recomputeDocumentStatus(Document $document): void
    {
        $document->getFromDB($document->getID());
        if ((int) $document->fields['status'] !== Document::WAITING_ACCEPTANCE) {
            return;
        }

        $rows = (new self())->find(['plugin_termodocs_documents_id' => $document->getID()]);

        foreach ($rows as $row) {
            if ((int) $row['status'] === Document::REFUSED) {
                $document->update([
                    'id'             => $document->getID(),
                    'status'         => Document::REFUSED,
                    'date_finalized' => date('Y-m-d H:i:s'),
                ]);
                return;
            }
        }

        $signed_roles = array_map('intval', array_column($rows, 'role'));
        $required_roles = [self::ROLE_RECIPIENT, self::ROLE_DELIVERER];
        if (empty(array_diff($required_roles, $signed_roles))) {
            $document->update([
                'id'             => $document->getID(),
                'status'         => Document::ACCEPTED,
                'date_finalized' => date('Y-m-d H:i:s'),
            ]);
            self::applyAssetAssignment($document);
        }
    }

    /**
     * Once fully accepted (not before - the equipment shouldn't change
     * owner based on an unconfirmed document): for a delivery template,
     * assigns every linked asset to the recipient; for a return
     * template, clears that assignment. Silently skipped for itemtypes
     * without a `users_id` field (not every asset type supports direct
     * user assignment).
     */
    private static function applyAssetAssignment(Document $document): void
    {
        $template = new DocumentTemplate();
        if (!$template->getFromDB((int) $document->fields['plugin_termodocs_templates_id'])) {
            return;
        }

        $new_users_id = $template->isReturn() ? 0 : (int) $document->fields['users_id_recipient'];

        foreach (Document_Item::getItemsForDocument($document->getID()) as $link) {
            $itemtype = $link['itemtype'];
            if (!class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
                continue;
            }

            $item = new $itemtype();
            if (!$item->getFromDB($link['items_id']) || !$item->isField('users_id')) {
                continue;
            }

            $item->update([
                'id'        => $link['items_id'],
                'users_id'  => $new_users_id,
            ]);
        }
    }
}
