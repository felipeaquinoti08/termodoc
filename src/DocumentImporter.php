<?php

namespace GlpiPlugin\Termodocs;

use RuntimeException;
use Session;
use User;

/**
 * Two-step import for an exported Documents batch. Step 1 parses the
 * file and tries to resolve every referenced user (recipient, deliverer,
 * requester, each signer) by e-mail - the only identifier that's
 * meaningful across GLPI instances, since numeric user IDs are not.
 * Unresolved users are staged in session for a review screen where an
 * admin picks the right local user by hand; step 2 commits the batch
 * using whatever was resolved automatically or picked manually.
 */
class DocumentImporter
{
    private const SESSION_KEY = 'termodocs_pending_import';

    public static function stageFromJson(string $raw): string
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['documents']) || !is_array($data['documents'])) {
            throw new RuntimeException(__('Arquivo inválido.', 'termodocs'));
        }

        $staged = [];
        foreach (array_values($data['documents']) as $i => $entry) {
            $staged[$i] = [
                'entry'     => $entry,
                'recipient' => self::resolve($entry['recipient'] ?? []),
                'deliverer' => self::resolve($entry['deliverer'] ?? []),
                'requester' => self::resolve($entry['requester'] ?? []),
            ];
        }

        $token = bin2hex(random_bytes(16));
        $_SESSION[self::SESSION_KEY][$token] = $staged;

        return $token;
    }

    public static function getStaged(string $token): ?array
    {
        return $_SESSION[self::SESSION_KEY][$token] ?? null;
    }

    /**
     * @param array<int,array{recipient:int,deliverer:int,requester:int}> $manual_users
     *   per staged-index user picks from the review form (already
     *   includes the auto-resolved ones, carried through as hidden
     *   fields, so this is authoritative for every row).
     * @return array{ok:int,ko:int}
     */
    public static function commit(string $token, array $manual_users): array
    {
        $staged = self::getStaged($token);
        if ($staged === null) {
            throw new RuntimeException(__('Lote de importação expirado ou inválido.', 'termodocs'));
        }

        global $DB;

        $ok = 0;
        $ko = 0;

        foreach ($staged as $i => $item) {
            $entry = $item['entry'];

            $users_id_recipient = (int) ($manual_users[$i]['recipient'] ?? 0);
            $users_id_deliverer = (int) ($manual_users[$i]['deliverer'] ?? 0);
            $users_id_requester = (int) ($manual_users[$i]['requester'] ?? 0) ?: Session::getLoginUserID();

            if ($users_id_recipient === 0 || $users_id_deliverer === 0) {
                $ko++;
                continue;
            }

            $document = new Document();
            $added = $document->add([
                'plugin_termodocs_templates_id' => 0,
                'name'                          => $entry['name'] ?? __('Documento importado', 'termodocs'),
                'rendered_html'                 => $entry['rendered_html'] ?? '',
                'content_hash'                  => $entry['content_hash'] ?? '',
                'theme_css_snapshot'             => $entry['theme_css_snapshot'] ?? '',
                'signature_provider'             => $entry['signature_provider'] ?? 'internal',
                'users_id_recipient'             => $users_id_recipient,
                'users_id_deliverer'             => $users_id_deliverer,
                'users_id_requester'             => $users_id_requester,
                'status'                         => (int) ($entry['status'] ?? Document::WAITING_ACCEPTANCE),
                'entities_id'                    => Session::getActiveEntity(),
                'date_generated'                 => $entry['date_generated'] ?? null,
                'date_finalized'                 => $entry['date_finalized'] ?? null,
            ]);

            if (!$added) {
                $ko++;
                continue;
            }

            foreach (array_values($entry['items'] ?? []) as $idx => $linked) {
                // itemtype/items_id here are deliberately fake (this
                // instance's assets have no relation to the source
                // instance's IDs) - only item_alias is meant to be
                // shown. See Document_Item::getTable()'s unicity key:
                // the incrementing fake ID keeps rows in the same
                // document from colliding on it.
                $DB->insert(Document_Item::getTable(), [
                    'plugin_termodocs_documents_id' => $document->getID(),
                    'itemtype'                       => 'TermodocsImportedAsset',
                    'items_id'                        => $idx + 1,
                    'item_alias'                       => $linked['label'] ?? '',
                ]);
            }

            foreach ($entry['acceptances'] ?? [] as $acc) {
                $acc_users_id = self::resolve($acc['user'] ?? [])['users_id'];
                (new Acceptance())->add([
                    'plugin_termodocs_documents_id' => $document->getID(),
                    'role'                            => (int) ($acc['role'] ?? Acceptance::ROLE_RECIPIENT),
                    'users_id'                         => $acc_users_id,
                    'status'                            => (int) ($acc['status'] ?? Document::ACCEPTED),
                    'accepted_name_snapshot'             => $acc['accepted_name_snapshot'] ?? '',
                    'ip_address'                          => $acc['ip_address'] ?? '',
                    'user_agent'                           => $acc['user_agent'] ?? '',
                    'content_hash_signed'                   => $acc['content_hash_signed'] ?? '',
                    'refusal_reason'                          => $acc['refusal_reason'] ?? '',
                    'signature_provider'                       => $acc['signature_provider'] ?? 'internal',
                    'date_creation'                             => $acc['date_creation'] ?? null,
                ]);
            }

            $ok++;
        }

        unset($_SESSION[self::SESSION_KEY][$token]);

        return ['ok' => $ok, 'ko' => $ko];
    }

    private static function resolve(array $ref): array
    {
        $email = trim((string) ($ref['email'] ?? ''));
        $users_id = 0;

        if ($email !== '') {
            $user = new User();
            if ($user->getFromDBbyEmail($email)) {
                $users_id = (int) $user->getID();
            }
        }

        return [
            'users_id'  => $users_id,
            'email'     => $email,
            'firstname' => $ref['firstname'] ?? '',
            'realname'  => $ref['realname'] ?? '',
        ];
    }
}
