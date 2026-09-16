<?php

namespace GlpiPlugin\Termodocs;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Termodocs\Signature\SignatureProviderManager;
use Html;
use Search;
use Session;
use User;

class Document extends CommonDBTM
{
    public static $rightname = 'plugin:termodocs:document';

    public const DRAFT              = 1;
    public const WAITING_ACCEPTANCE = 2;
    public const ACCEPTED           = 3;
    public const REFUSED            = 4;
    public const CANCELED           = 5;

    public static function getTypeName($nb = 0): string
    {
        return _n('Documento', 'Documentos', $nb, 'termodocs');
    }

    public static function getIcon(): string
    {
        return 'ti ti-file-certificate';
    }

    /**
     * A short, scannable code baked into every document's name (see
     * DocumentGenerator::generate()) - easier to reference out loud or in
     * a support conversation than the full name or the internal ID, and
     * unlike the recipient's name, never something a party could cause to
     * change after the fact. 36^6 (~2.2 billion) combinations makes a
     * collision practically impossible for any realistic volume of
     * documents, so this doesn't bother checking uniqueness against
     * existing ones.
     */
    public static function generateReferenceCode(): string
    {
        $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $charset[random_int(0, strlen($charset) - 1)];
        }
        return $code;
    }

    public static function getStatuses(): array
    {
        return [
            self::DRAFT              => __('Rascunho', 'termodocs'),
            self::WAITING_ACCEPTANCE => __('Aguardando aceite', 'termodocs'),
            self::ACCEPTED           => __('Aceito', 'termodocs'),
            self::REFUSED            => __('Recusado', 'termodocs'),
            self::CANCELED           => __('Cancelado', 'termodocs'),
        ];
    }

    public static function getStatusLabel(int $status): string
    {
        return self::getStatuses()[$status] ?? '?';
    }

    public static function getStatusBadgeClass(int $status): string
    {
        return match ($status) {
            self::WAITING_ACCEPTANCE => 'bg-orange-lt',
            self::ACCEPTED           => 'bg-green-lt',
            self::REFUSED            => 'bg-red-lt',
            self::CANCELED           => 'bg-secondary-lt',
            default                  => 'bg-azure-lt',
        };
    }

    public function canViewItem(): bool
    {
        if (Session::haveRight(static::$rightname, READ)) {
            return true;
        }
        return $this->isRecipient() || $this->isDeliverer();
    }

    public function isRecipient(): bool
    {
        return (int) $this->fields['users_id_recipient'] === Session::getLoginUserID();
    }

    public function isDeliverer(): bool
    {
        return (int) $this->fields['users_id_deliverer'] === Session::getLoginUserID();
    }

    public function isReturn(): bool
    {
        return self::isReturnForTemplate((int) $this->fields['plugin_termodocs_templates_id']);
    }

    public static function isReturnForTemplate(int $templates_id): bool
    {
        $template = new DocumentTemplate();
        return $template->getFromDB($templates_id) && $template->isReturn();
    }

    /**
     * Role labels are direction-aware: in a Devolução, the "colaborador"
     * is the one giving the equipment back (not receiving), and the "TI"
     * party is the one taking custody back (not delivering) - using the
     * generic recipient/deliverer wording here would read backwards.
     */
    public function getRoleLabel(int $role): string
    {
        return self::getRoleLabelForTemplate((int) $this->fields['plugin_termodocs_templates_id'], $role);
    }

    public static function getRoleLabelForTemplate(int $templates_id, int $role): string
    {
        $is_return = self::isReturnForTemplate($templates_id);

        if ($role === Acceptance::ROLE_DELIVERER) {
            return $is_return ? __('Recebido por (TI)', 'termodocs') : __('Entregador', 'termodocs');
        }

        return $is_return ? __('Colaborador (devolvendo)', 'termodocs') : __('Colaborador (recebendo)', 'termodocs');
    }

    /**
     * `rendered_html` (and its content_hash) stays frozen exactly as
     * generated - that snapshot is the legal record of what was agreed
     * to, so it's never rewritten. This produces a display-only copy
     * with each signed party's name dropped into the styled placeholder
     * their template's signature block already reserves for them
     * (`<div class="td-signature-name" data-td-role="recipient|deliverer">`).
     * Nothing is persisted; call this wherever the document is *shown*,
     * never store the result back into `rendered_html`.
     */
    /**
     * $extra_content_html (e.g. buildConditionsSectionHtml()'s table) is
     * spliced in right before the closing `.td-footer` div, matching
     * exactly where DocumentGenerator::regeneratePdfWithConditions()
     * appends the same block to the PDF's content before composing -
     * otherwise the web preview would show it dropped after the whole
     * page (outside the styled .td-page wrapper), while the PDF shows it
     * inside the content area, and the two would visibly disagree.
     */
    public function composeSignedHtml(string $extra_content_html = ''): string
    {
        $html = (string) ($this->fields['rendered_html'] ?? '');

        if ($extra_content_html !== '') {
            $html = str_replace('<div class="td-footer">', $extra_content_html . '<div class="td-footer">', $html);
        }

        foreach (['recipient' => Acceptance::ROLE_RECIPIENT, 'deliverer' => Acceptance::ROLE_DELIVERER] as $marker => $role) {
            $acceptance = Acceptance::getForRole((int) $this->fields['id'], $role);
            if ($acceptance === null || (int) $acceptance->fields['status'] !== self::ACCEPTED) {
                continue;
            }

            $placeholder = '<div class="td-signature-name" data-td-role="' . $marker . '"></div>';
            $filled = '<div class="td-signature-name" data-td-role="' . $marker . '">' .
                htmlspecialchars(trim((string) $acceptance->fields['accepted_name_snapshot'])) . '</div>';
            $html = str_replace($placeholder, $filled, $html);

            $date_placeholder = '<div class="td-signature-date" data-td-role="' . $marker . '"></div>';
            $signed_at = $acceptance->fields['date_creation'] ?? null;
            if ($signed_at) {
                $date_filled = '<div class="td-signature-date" data-td-role="' . $marker . '">' .
                    __('Assinado em', 'termodocs') . ' ' . htmlspecialchars(Html::convDateTime($signed_at)) . '</div>';
                $html = str_replace($date_placeholder, $date_filled, $html);
            }
        }

        return $html;
    }

    /**
     * Returns the acceptance role (Acceptance::ROLE_*) the current user
     * holds on this document, or null if they're neither party (e.g. an
     * admin just browsing with the READ right).
     */
    public function getMyRole(): ?int
    {
        if ($this->isRecipient()) {
            return Acceptance::ROLE_RECIPIENT;
        }
        if ($this->isDeliverer()) {
            return Acceptance::ROLE_DELIVERER;
        }
        return null;
    }

    /**
     * True once an external provider (Assinei.digital...) owns the
     * signing step for this document - GLPI's own accept/refuse buttons
     * (front/acceptance.php) must stay hidden then, since clicking them
     * would record a "signature" here while the actual legal signing
     * still happens on the provider's hosted page; only its webhook
     * (Acceptance::acceptExternal()/refuseExternal()) may record one.
     */
    public function isExternallySigned(): bool
    {
        return ($this->fields['signature_provider'] ?? SignatureProviderManager::INTERNAL) !== SignatureProviderManager::INTERNAL;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Menu) {
            if (!self::canView()) {
                return '';
            }
            return self::createTabEntry(self::getTypeName(2), 0, $item::class, self::getIcon());
        }

        if ($item instanceof User) {
            if (!self::canViewUserTab($item)) {
                return '';
            }
            $count = self::countForUser((int) $item->getID());
            return self::createTabEntry(__('Documentos assinados', 'termodocs'), $count, $item::class, self::getIcon());
        }

        if (!self::canView()) {
            return '';
        }

        global $DB;
        $count = $DB->request([
            'COUNT'  => 'c',
            'FROM'   => self::getTable(),
            'INNER JOIN' => [
                Document_Item::getTable() => [
                    'FKEY' => [
                        Document_Item::getTable() => 'plugin_termodocs_documents_id',
                        self::getTable()          => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                Document_Item::getTable() . '.itemtype' => $item->getType(),
                Document_Item::getTable() . '.items_id' => $item->getID(),
                self::getTable() . '.is_deleted'         => 0,
            ],
        ])->current()['c'] ?? 0;

        return self::createTabEntry(self::getTypeName(2), $count, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Menu) {
            if (self::canCreate()) {
                global $CFG_GLPI;
                echo '<div class="mb-2 d-flex gap-2">';
                echo '<a class="btn btn-primary btn-sm" href="' .
                    self::getFormURL() . '?generate=1"><i class="ti ti-plus"></i> ' .
                    __('Gerar documento', 'termodocs') . '</a>';
                echo '<a class="btn btn-outline-secondary btn-sm" href="' . $CFG_GLPI['root_doc'] .
                    '/plugins/termodocs/front/document.export.php"><i class="ti ti-download"></i> ' .
                    __('Exportar', 'termodocs') . '</a>';
                echo '<a class="btn btn-outline-secondary btn-sm" href="' . $CFG_GLPI['root_doc'] .
                    '/plugins/termodocs/front/document.import.php"><i class="ti ti-upload"></i> ' .
                    __('Importar', 'termodocs') . '</a>';
                echo '<a class="btn btn-outline-secondary btn-sm" href="' . $CFG_GLPI['root_doc'] .
                    '/plugins/termodocs/front/document.import_assinei.php"><i class="ti ti-file-import"></i> ' .
                    __('Importar do Assinei.digital', 'termodocs') . '</a>';
                echo '</div>';
            }
            Search::show(self::class);
            return true;
        }

        if ($item instanceof User) {
            if (!self::canViewUserTab($item)) {
                return false;
            }

            $documents = self::decorateForDisplay(self::getForUser((int) $item->getID()));

            TemplateRenderer::getInstance()->display('@termodocs/document_tab.html.twig', [
                'item'          => $item,
                'documents'     => $documents,
                'has_templates' => false,
                'generate_url'  => null,
                'can_create'    => false,
                'empty_message' => __('Nenhum documento assinado ainda.', 'termodocs'),
            ]);

            return true;
        }

        global $DB;

        $documents = [];
        foreach ($DB->request([
            'SELECT' => [self::getTable() . '.*'],
            'FROM'   => self::getTable(),
            'INNER JOIN' => [
                Document_Item::getTable() => [
                    'FKEY' => [
                        Document_Item::getTable() => 'plugin_termodocs_documents_id',
                        self::getTable()          => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                Document_Item::getTable() . '.itemtype' => $item->getType(),
                Document_Item::getTable() . '.items_id' => $item->getID(),
                self::getTable() . '.is_deleted'         => 0,
            ],
            'ORDER' => self::getTable() . '.date_creation DESC',
        ]) as $row) {
            $documents[] = $row;
        }
        $documents = self::decorateForDisplay($documents);

        $eligible_templates = [];
        foreach ((new DocumentTemplate())->find(['is_active' => 1, 'is_deleted' => 0]) as $id => $row) {
            $allowed = json_decode((string) ($row['allowed_itemtypes'] ?? '[]'), true) ?: [];
            if (in_array($item->getType(), $allowed, true)) {
                $eligible_templates[$id] = $row;
            }
        }

        TemplateRenderer::getInstance()->display('@termodocs/document_tab.html.twig', [
            'item'               => $item,
            'documents'          => $documents,
            'has_templates'      => !empty($eligible_templates),
            'generate_url'       => self::getFormURL() . '?generate=1&items[0][itemtype]=' . urlencode($item->getType()) . '&items[0][items_id]=' . $item->getID(),
            'can_create'         => self::canCreate(),
        ]);

        return true;
    }

    /**
     * A user sees their own signed-documents tab regardless of the
     * admin-only plugin:termodocs:document right, same self-service
     * carve-out as canViewItem() for a single document - otherwise
     * employees without that right could never see their own tab on
     * their own profile.
     */
    private static function canViewUserTab(User $item): bool
    {
        return Session::haveRight(self::$rightname, READ) || (int) $item->getID() === Session::getLoginUserID();
    }

    private static function countForUser(int $users_id): int
    {
        global $DB;
        return $DB->request([
            'COUNT'  => 'c',
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'OR' => [
                    'users_id_recipient' => $users_id,
                    'users_id_deliverer' => $users_id,
                ],
                'is_deleted' => 0,
            ],
        ])->current()['c'] ?? 0;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function getForUser(int $users_id): array
    {
        global $DB;
        $documents = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'OR' => [
                    'users_id_recipient' => $users_id,
                    'users_id_deliverer' => $users_id,
                ],
                'is_deleted' => 0,
            ],
            'ORDER' => 'date_creation DESC',
        ]) as $row) {
            $documents[] = $row;
        }
        return $documents;
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return array<int,array<string,mixed>>
     */
    private static function decorateForDisplay(array $documents): array
    {
        foreach ($documents as &$row) {
            $row['view_url']     = self::getFormURLWithID((int) $row['id']);
            $row['status_label'] = self::getStatusLabel((int) $row['status']);
            $row['status_class'] = self::getStatusBadgeClass((int) $row['status']);
        }
        unset($row);
        return $documents;
    }

    /**
     * Options for the per-item condition picker (document's own page)
     * and the PDF's condition table - shared here so both stay in sync
     * with the same three values/labels.
     *
     * @return array<string,string> value => label
     */
    public static function getConditionOptions(): array
    {
        return [
            'bom'     => __('Bom', 'termodocs'),
            'regular' => __('Regular', 'termodocs'),
            'ruim'    => __('Ruim', 'termodocs'),
        ];
    }

    /**
     * The same "Condição dos Equipamentos" block used two places: baked
     * into the regenerated PDF (DocumentGenerator::regeneratePdfWithConditions())
     * and appended to the web preview iframe (showForm() below) - a
     * single source of truth so the on-screen preview never drifts from
     * what the downloaded PDF actually shows.
     *
     * @param array<int,array<string,mixed>> $linked_items rows from
     *   Document_Item::getItemsForDocument()
     */
    public static function buildConditionsSectionHtml(self $document, array $linked_items): string
    {
        $condition_labels = self::getConditionOptions();
        // Inline (not CSS-class-based) so the color survives both TCPDF
        // (which never sees theme_css_snapshot's own classes here) and
        // the web preview, exactly the same way in both places.
        $condition_colors = [
            'bom'     => ['background' => '#dcfce7', 'color' => '#166534'],
            'regular' => ['background' => '#ffedd5', 'color' => '#9a3412'],
            'ruim'    => ['background' => '#fee2e2', 'color' => '#991b1b'],
        ];
        $default_color = ['background' => '#f3f4f6', 'color' => '#6b7280'];

        $rows = '';
        foreach ($linked_items as $linked) {
            $label = htmlspecialchars(
                (string) ($linked['item_alias'] ?: self::describeItemForExport($linked['itemtype'], (int) $linked['items_id']))
            );
            $condition = $linked['condition'] ?? null;
            $condition_label = $condition_labels[$condition] ?? __('Não avaliado', 'termodocs');
            $color = $condition_colors[$condition] ?? $default_color;

            $badge = '<span style="display:inline-block;padding:2px 10px;border-radius:10px;'
                . 'background:' . $color['background'] . ';color:' . $color['color'] . ';">'
                . htmlspecialchars($condition_label) . '</span>';

            $rows .= '<tr><td style="padding:6px 4px;border-bottom:1px solid #e5e7eb;">' . $label . '</td>'
                . '<td style="padding:6px 4px;border-bottom:1px solid #e5e7eb;">' . $badge . '</td></tr>';
        }

        $notes = trim((string) ($document->fields['notes'] ?? ''));

        $html = '<div class="td-conditions" style="margin-top:24px;">'
            . '<h3>' . __('Condição dos Equipamentos', 'termodocs') . '</h3>'
            . '<table style="width:100%;border-collapse:collapse;font-size:10pt;">'
            . '<thead><tr>'
            . '<th style="text-align:left;border-bottom:1px solid #333;padding:4px;">' . __('Equipamento', 'termodocs') . '</th>'
            . '<th style="text-align:left;border-bottom:1px solid #333;padding:4px;">' . __('Condição', 'termodocs') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';

        if ($notes !== '') {
            $html .= '<p style="margin-top:12px;"><strong>' . __('Observações:', 'termodocs') . '</strong> '
                . nl2br(htmlspecialchars($notes)) . '</p>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<int,array<string,mixed>> $items rows from
     *   Document_Item::getItemsForDocument()
     * @return array<int,array<string,mixed>>
     */
    private static function decorateItemsWithCondition(array $items): array
    {
        $options = self::getConditionOptions();
        $classes = ['bom' => 'bg-green-lt', 'regular' => 'bg-orange-lt', 'ruim' => 'bg-red-lt'];

        foreach ($items as &$linked) {
            $linked['display_name'] = $linked['item_alias'] ?: self::describeItemForExport($linked['itemtype'], (int) $linked['items_id']);

            $condition = $linked['condition'] ?? null;
            if ($condition === null || !isset($options[$condition])) {
                $linked['condition_label'] = null;
                $linked['condition_class'] = null;
            } else {
                $linked['condition_label'] = $options[$condition];
                $linked['condition_class'] = $classes[$condition];
            }
        }
        unset($linked);
        return $items;
    }

    public static function onAssetPurge(CommonDBTM $item): void
    {
        (new Document_Item())->deleteByCriteria([
            'itemtype' => $item->getType(),
            'items_id' => $item->getID(),
        ]);
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => 11,
            'table'         => self::getTable(),
            'field'         => 'status',
            'name'          => __('Status'),
            'datatype'      => 'specific',
            'searchtype'    => 'equals',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => 12,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'linkfield' => 'users_id_recipient',
            'name'     => __('Recipient'),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id'       => 15,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'linkfield' => 'users_id_deliverer',
            'name'     => __('Responsável (TI)', 'termodocs'),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id'       => 13,
            'table'    => self::getTable(),
            'field'    => 'date_generated',
            'name'     => __('Data de geração', 'termodocs'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => 14,
            'table'    => self::getTable(),
            'field'    => 'date_finalized',
            'name'     => __('Data de finalização', 'termodocs'),
            'datatype' => 'datetime',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'status') {
            $values = is_array($values) ? $values : ['status' => $values];
            return self::getStatusLabel((int) $values['status']);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $items = self::decorateItemsWithCondition(Document_Item::getItemsForDocument((int) $ID));
        $recipient = new User();
        $recipient->getFromDB((int) $this->fields['users_id_recipient']);
        $deliverer = new User();
        $deliverer->getFromDB((int) $this->fields['users_id_deliverer']);

        $is_return = $this->isReturn();

        $my_role = $this->getMyRole();
        $my_acceptance = $my_role !== null ? Acceptance::getForRole((int) $ID, $my_role) : null;
        $is_waiting = (int) $this->fields['status'] === self::WAITING_ACCEPTANCE;

        // The signature provider (internal vs. Assinei.digital vs. any
        // other one registered later) stays a free choice - made or
        // changed from the document's own page, see
        // front/document.form.php's send_signature action - for as long
        // as nothing has actually happened on it yet: no acceptance
        // recorded for either role, and never sent to an external
        // provider. Past that point it's locked, so switching can't
        // strand a signature already recorded under a provider the
        // document no longer claims, or send it to a second provider
        // while it's still out for signature on the first one.
        $not_yet_engaged = $is_waiting
            && empty($this->fields['external_reference'])
            && Acceptance::getForRole((int) $ID, Acceptance::ROLE_RECIPIENT) === null
            && Acceptance::getForRole((int) $ID, Acceptance::ROLE_DELIVERER) === null;

        TemplateRenderer::getInstance()->display('@termodocs/document_form.html.twig', [
            'item'                => $this,
            'items'               => $items,
            'recipient'           => $recipient,
            'deliverer'           => $deliverer,
            'is_return'           => $is_return,
            'recipient_role_label' => $this->getRoleLabel(Acceptance::ROLE_RECIPIENT),
            'deliverer_role_label' => $this->getRoleLabel(Acceptance::ROLE_DELIVERER),
            'status_label'        => self::getStatusLabel((int) $this->fields['status']),
            'status_class'        => self::getStatusBadgeClass((int) $this->fields['status']),
            'can_accept'          => $my_role !== null && $my_acceptance === null && $is_waiting && !$this->isExternallySigned(),
            'waiting_other_party' => $my_acceptance !== null && $is_waiting,
            'waiting_externally'  => $my_role !== null && $my_acceptance === null && $is_waiting && $this->isExternallySigned(),
            'can_choose_signature' => self::canCreate() && $not_yet_engaged,
            'available_providers' => SignatureProviderManager::getInstance()->getAvailableProviders(),
            'current_provider_key' => $this->fields['signature_provider'] ?? SignatureProviderManager::INTERNAL,
            'signature_provider_label' => SignatureProviderManager::getInstance()
                ->resolve($this->fields['signature_provider'] ?? SignatureProviderManager::INTERNAL)
                ->getLabel(),
            'recipient_signed'    => Acceptance::hasSigned((int) $ID, Acceptance::ROLE_RECIPIENT),
            'deliverer_signed'    => Acceptance::hasSigned((int) $ID, Acceptance::ROLE_DELIVERER),
            // Gates the raw-payload "Diagnóstico" panel - the
            // recipient/deliverer can already view this page themselves
            // (see canViewItem()), but that technical detail is only
            // useful to whoever administers the integration.
            'is_admin'            => Session::haveRight(self::$rightname, READ),
            'condition_options'   => self::getConditionOptions(),
            // Appended to the preview iframe below (never stored back
            // into rendered_html) so the on-screen preview matches what
            // "Salvar observações" just baked into the downloadable PDF -
            // same builder, see buildConditionsSectionHtml()'s own comment.
            'conditions_preview_html' => empty($items) ? '' : self::buildConditionsSectionHtml($this, $items),
        ]);

        return true;
    }

    /**
     * Portable representation of every non-deleted document, regardless
     * of status (signed or not). Users are referenced by e-mail rather
     * than ID, since IDs are meaningless across GLPI instances; linked
     * assets are reduced to a plain text label for the same reason (see
     * describeItemForExport()) - `rendered_html`/`content_hash` already
     * carry the full legal content, so the asset link is informational
     * only, never re-resolved on import.
     */
    public static function exportAll(): array
    {
        $out = [];

        foreach ((new self())->find(['is_deleted' => 0]) as $row) {
            $template_name = '';
            $template = new DocumentTemplate();
            if ($template->getFromDB((int) $row['plugin_termodocs_templates_id'])) {
                $template_name = $template->fields['name'];
            }

            $entry = [
                'name'               => $row['name'],
                'rendered_html'      => $row['rendered_html'],
                'content_hash'       => $row['content_hash'],
                'theme_css_snapshot' => $row['theme_css_snapshot'],
                'signature_provider' => $row['signature_provider'],
                'status'             => (int) $row['status'],
                'template_name_hint' => $template_name,
                'date_generated'     => $row['date_generated'],
                'date_finalized'     => $row['date_finalized'],
                'recipient'          => self::exportUserRef((int) $row['users_id_recipient']),
                'deliverer'          => self::exportUserRef((int) $row['users_id_deliverer']),
                'requester'          => self::exportUserRef((int) $row['users_id_requester']),
                'items'              => [],
                'acceptances'        => [],
            ];

            foreach (Document_Item::getItemsForDocument((int) $row['id']) as $item_row) {
                $entry['items'][] = [
                    'label' => self::describeItemForExport($item_row['itemtype'], (int) $item_row['items_id']),
                ];
            }

            foreach ((new Acceptance())->find(['plugin_termodocs_documents_id' => $row['id']]) as $acc) {
                $entry['acceptances'][] = [
                    'role'                   => (int) $acc['role'],
                    'status'                 => (int) $acc['status'],
                    'accepted_name_snapshot' => $acc['accepted_name_snapshot'],
                    'ip_address'             => $acc['ip_address'],
                    'user_agent'             => $acc['user_agent'],
                    'content_hash_signed'    => $acc['content_hash_signed'],
                    'refusal_reason'         => $acc['refusal_reason'],
                    'signature_provider'     => $acc['signature_provider'],
                    'date_creation'          => $acc['date_creation'],
                    'user'                   => self::exportUserRef((int) $acc['users_id']),
                ];
            }

            $out[] = $entry;
        }

        return $out;
    }

    private static function exportUserRef(int $users_id): array
    {
        $empty = ['email' => '', 'firstname' => '', 'realname' => '', 'login' => ''];
        if ($users_id === 0) {
            return $empty;
        }

        $user = new User();
        if (!$user->getFromDB($users_id)) {
            return $empty;
        }

        return [
            'email'     => $user->getDefaultEmail() ?: '',
            'firstname' => $user->fields['firstname'] ?? '',
            'realname'  => $user->fields['realname'] ?? '',
            'login'     => $user->fields['name'] ?? '',
        ];
    }

    /**
     * A human-readable "Tipo: Nome" label for an asset - originally just
     * for exportAll() (see its own doc comment), now also reused by
     * decorateItemsWithCondition() for the same reason: item_alias is
     * only ever populated by DocumentImporter for cross-instance
     * documents, so a normally-generated document's linked items have
     * nothing better to fall back to than "Itemtype #id" without this.
     */
    public static function describeItemForExport(string $itemtype, int $items_id): string
    {
        if (!class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return $itemtype . ' #' . $items_id;
        }

        $item = new $itemtype();
        $name = $item->getFromDB($items_id) ? $item->getName() : ('#' . $items_id);
        return $itemtype::getTypeName(1) . ': ' . $name;
    }
}
