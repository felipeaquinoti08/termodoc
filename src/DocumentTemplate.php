<?php

namespace GlpiPlugin\Termodocs;

use CommonDBTM;
use CommonGLPI;
use Document as GlpiDocument;
use Dropdown;
use Glpi\Asset\AssetDefinitionManager;
use GlpiPlugin\Termodocs\Placeholder\TemplateRenderer;
use GlpiPlugin\Termodocs\Signature\SignatureProviderManager;
use Html;
use Search;
use Session;

/**
 * A document template is fully self-contained: header, footer, CSS,
 * optional background image and the term's own content, all edited
 * together on one screen. There is deliberately no separate, selectable
 * "theme" entity - each template owns its own look.
 */
class DocumentTemplate extends CommonDBTM
{
    public static $rightname = 'plugin:termodocs:template';

    public const CLASSIC_ITEMTYPES = [
        'Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Printer', 'Phone',
    ];

    public const OPERATION_DELIVERY = 1;
    public const OPERATION_RETURN   = 2;

    public static function getOperationTypes(): array
    {
        return [
            self::OPERATION_DELIVERY => __('Entrega', 'termodocs'),
            self::OPERATION_RETURN   => __('Devolução', 'termodocs'),
        ];
    }

    public function getOperationType(): int
    {
        return (int) ($this->fields['operation_type'] ?? self::OPERATION_DELIVERY);
    }

    public function isReturn(): bool
    {
        return $this->getOperationType() === self::OPERATION_RETURN;
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Modelo de documento', 'Modelos de documento', $nb, 'termodocs');
    }

    public static function getIcon(): string
    {
        return 'ti ti-file-text';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Menu || !self::canView()) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(2), 0, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Menu) {
            return false;
        }
        if (self::canCreate()) {
            global $CFG_GLPI;
            echo '<div class="mb-2 d-flex gap-2">';
            echo '<a class="btn btn-primary btn-sm" href="' . self::getFormURL() .
                '"><i class="ti ti-plus"></i> ' . __('Add') . '</a>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="' . $CFG_GLPI['root_doc'] .
                '/plugins/termodocs/front/documenttemplate.export.php"><i class="ti ti-download"></i> ' .
                __('Exportar', 'termodocs') . '</a>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="' . $CFG_GLPI['root_doc'] .
                '/plugins/termodocs/front/documenttemplate.import.php"><i class="ti ti-upload"></i> ' .
                __('Importar', 'termodocs') . '</a>';
            echo '</div>';
        }
        Search::show(self::class);
        return true;
    }

    /**
     * Portable representation of every non-deleted template (active or
     * not), including the background image embedded as base64 so a
     * template stays self-contained across GLPI instances - the
     * `background_documents_id` FK on this instance would otherwise be
     * meaningless on another one.
     */
    public static function exportAll(): array
    {
        $out = [];

        foreach ((new self())->find(['is_deleted' => 0]) as $row) {
            $entry = [
                'name'                 => $row['name'],
                'description'          => $row['description'],
                'header_html'          => $row['header_html'],
                'footer_html'          => $row['footer_html'],
                'content_html'         => $row['content_html'],
                'css'                  => $row['css'],
                'operation_type'       => (int) $row['operation_type'],
                'allowed_itemtypes'    => json_decode((string) ($row['allowed_itemtypes'] ?? '[]'), true) ?: [],
                'allow_multiple_items' => (bool) $row['allow_multiple_items'],
                'signature_provider'   => $row['signature_provider'],
                'is_active'            => (bool) $row['is_active'],
            ];

            $bg_id = (int) ($row['background_documents_id'] ?? 0);
            if ($bg_id) {
                $doc = new GlpiDocument();
                if ($doc->getFromDB($bg_id) && !empty($doc->fields['filepath'])) {
                    $path = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
                    if (is_file($path)) {
                        $entry['background_image'] = [
                            'filename' => $doc->fields['filename'],
                            'mime'     => $doc->fields['mime'],
                            'data'     => base64_encode((string) file_get_contents($path)),
                        ];
                    }
                }
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Recreates one template from exported data. Always inactive
     * regardless of the exported `is_active` value: an imported template
     * hasn't been reviewed on this instance yet, so it shouldn't be
     * immediately eligible for document generation.
     *
     * @throws \RuntimeException if Twig-lint validation (via add()'s
     *   prepareInputForAdd) rejects the imported content.
     */
    public static function importOne(array $data): int
    {
        $bg_documents_id = 0;
        if (!empty($data['background_image']['data'])) {
            $tmp_name = 'termodocs_import_' . bin2hex(random_bytes(8));
            file_put_contents(GLPI_TMP_DIR . '/' . $tmp_name, base64_decode((string) $data['background_image']['data']));

            $doc = new GlpiDocument();
            $doc->add([
                'name'        => $data['background_image']['filename'] ?? $tmp_name,
                'entities_id' => Session::getActiveEntity(),
                '_filename'   => [$tmp_name],
            ]);
            $bg_documents_id = (int) $doc->getID();
        }

        $template = new self();
        $added = $template->add([
            'name'                     => ($data['name'] ?? __('Sem nome', 'termodocs')) . ' (' . __('Importado', 'termodocs') . ')',
            'description'              => $data['description'] ?? '',
            'header_html'              => $data['header_html'] ?? '',
            'footer_html'              => $data['footer_html'] ?? '',
            'content_html'             => $data['content_html'] ?? '',
            'css'                      => $data['css'] ?? '',
            'operation_type'           => (int) ($data['operation_type'] ?? self::OPERATION_DELIVERY),
            'allowed_itemtypes'        => json_encode($data['allowed_itemtypes'] ?? []),
            'allow_multiple_items'     => empty($data['allow_multiple_items']) ? 0 : 1,
            'signature_provider'       => $data['signature_provider'] ?? SignatureProviderManager::INTERNAL,
            'is_active'                => 0,
            'background_documents_id'  => $bg_documents_id,
            'entities_id'              => Session::getActiveEntity(),
        ]);

        if (!$added) {
            throw new \RuntimeException(sprintf(
                __('Falha ao importar o modelo "%s" (conteúdo inválido).', 'termodocs'),
                $data['name'] ?? '?'
            ));
        }

        return (int) $template->getID();
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => 2,
            'table'    => self::getTable(),
            'field'    => 'description',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'            => 3,
            'table'         => self::getTable(),
            'field'         => 'operation_type',
            'name'          => __('Operação', 'termodocs'),
            'datatype'      => 'specific',
            'searchtype'    => 'equals',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => self::getTable(),
            'field'    => 'allow_multiple_items',
            'name'     => __('Permite múltiplos ativos', 'termodocs'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => self::getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => self::getTable(),
            'field'    => 'date_creation',
            'name'     => __('Data de criação', 'termodocs'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => self::getTable(),
            'field'    => 'date_mod',
            'name'     => __('Última modificação', 'termodocs'),
            'datatype' => 'datetime',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'operation_type') {
            $values = is_array($values) ? $values : ['operation_type' => $values];
            return self::getOperationTypes()[(int) $values['operation_type']] ?? '?';
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Lists every itemtype eligible as a target: the classic hardcoded asset
     * types plus every active custom AssetDefinition.
     */
    public static function getEligibleItemtypes(): array
    {
        $itemtypes = [];
        foreach (self::CLASSIC_ITEMTYPES as $itemtype) {
            $itemtypes[$itemtype] = $itemtype::getTypeName(1);
        }

        if (class_exists(AssetDefinitionManager::class)) {
            foreach (AssetDefinitionManager::getInstance()->getDefinitions(true) as $definition) {
                $class = $definition->getAssetClassName();
                $itemtypes[$class] = $definition->getName();
            }
        }

        return $itemtypes;
    }

    /**
     * Itemtypes actually usable for the given operation direction (Entrega
     * or Devolução): only those referenced by at least one active template
     * of that operation_type, so the wizard's asset picker doesn't offer
     * types with no matching template.
     */
    public static function getEligibleItemtypesForOperation(int $operation_type): array
    {
        $all = self::getEligibleItemtypes();

        $eligible = [];
        foreach ((new self())->find(['is_active' => 1, 'is_deleted' => 0, 'operation_type' => $operation_type]) as $row) {
            $allowed = json_decode((string) ($row['allowed_itemtypes'] ?? '[]'), true) ?: [];
            foreach ($allowed as $itemtype) {
                if (isset($all[$itemtype])) {
                    $eligible[$itemtype] = $all[$itemtype];
                }
            }
        }

        return $eligible;
    }

    public function getAllowedItemtypes(): array
    {
        $raw = $this->fields['allowed_itemtypes'] ?? '[]';
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function isItemtypeAllowed(string $itemtype): bool
    {
        return in_array($itemtype, $this->getAllowedItemtypes(), true);
    }

    /**
     * Wraps already placeholder-resolved header/content/footer HTML into
     * the page structure - the single place that defines "where the
     * term's content goes" (always between header and footer).
     */
    public function composeHtml(string $header_html, string $content_html, string $footer_html): string
    {
        $bg_url = $this->getBackgroundUrl();
        $bg_style = $bg_url
            ? ' style="background-image:url(\'' . htmlspecialchars($bg_url) . '\');background-size:cover;background-position:center;"'
            : '';

        return '<div class="td-page"' . $bg_style . '>'
            . '<div class="td-header">' . $header_html . '</div>'
            . '<div class="td-content">' . $content_html . '</div>'
            . '<div class="td-footer">' . $footer_html . '</div>'
            . '</div>';
    }

    public function getBackgroundUrl(): ?string
    {
        global $CFG_GLPI;
        if (empty($this->fields['background_documents_id'])) {
            return null;
        }
        return $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $this->fields['background_documents_id'];
    }

    public function getBackgroundFilePath(): ?string
    {
        if (empty($this->fields['background_documents_id'])) {
            return null;
        }
        $doc = new GlpiDocument();
        if (!$doc->getFromDB((int) $this->fields['background_documents_id']) || empty($doc->fields['filepath'])) {
            return null;
        }
        $path = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
        return is_file($path) ? $path : null;
    }

    /**
     * Plain id => name list of image documents already uploaded to GLPI's
     * GED (Gerência > Documentos), for a simple background picker.
     */
    public static function getBackgroundImageOptions(): array
    {
        global $DB;
        $options = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_documents',
            'WHERE' => ['is_deleted' => 0, 'mime' => ['LIKE', 'image/%']],
            'ORDER' => 'name ASC',
        ]) as $row) {
            $options[$row['id']] = $row['name'] ?: $row['filename'];
        }
        return $options;
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareContentInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareContentInput($input);
    }

    private function prepareContentInput(array $input): array
    {
        // Signing is mandatory: never let this be turned off, regardless
        // of what a (nonexistent, but defensively handled) form field or
        // API caller might submit.
        $input['require_acceptance'] = 1;

        if (isset($input['allowed_itemtypes']) && is_array($input['allowed_itemtypes'])) {
            $cleaned = array_values(array_filter($input['allowed_itemtypes'], static fn($v) => is_string($v) && $v !== ''));
            $input['allowed_itemtypes'] = json_encode($cleaned);
        }

        foreach (['header_html', 'footer_html', 'content_html'] as $field) {
            if (!isset($input[$field])) {
                continue;
            }
            $errors = TemplateRenderer::getInstance()->lint($input[$field]);
            if (!empty($errors)) {
                Session::addMessageAfterRedirect(
                    __('Erro de sintaxe no campo', 'termodocs') . " \"$field\": " . implode(' ', $errors),
                    false,
                    ERROR
                );
                return [];
            }
        }

        return $input;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo '<tr class="tab_bg_1"><td>' . __('Name') . '</td><td>';
        echo Html::input('name', ['value' => $this->fields['name'] ?? '']);
        echo '</td><td>' . __('Active') . '</td><td>';
        Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Tipo de operação', 'termodocs') . '</td><td>';
        Dropdown::showFromArray('operation_type', self::getOperationTypes(), [
            'value' => $this->fields['operation_type'] ?? self::OPERATION_DELIVERY,
        ]);
        echo ' <small class="text-muted">' .
            __('Define se este modelo é usado para entregar ou para devolver equipamento.', 'termodocs') .
            '</small>';
        echo '</td><td colspan="2"></td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Imagem de fundo', 'termodocs') . '</td><td>';
        Dropdown::showFromArray('background_documents_id', self::getBackgroundImageOptions(), [
            'value'               => $this->fields['background_documents_id'] ?? 0,
            'display_emptychoice' => true,
        ]);
        echo '</td><td>' . __('Modo de assinatura', 'termodocs') . '</td><td>';
        Dropdown::showFromArray(
            'signature_provider',
            SignatureProviderManager::getInstance()->getAvailableProviders(),
            ['value' => $this->fields['signature_provider'] ?? SignatureProviderManager::INTERNAL]
        );
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Tipos de ativos elegíveis', 'termodocs') . '</td><td colspan="3">';
        Dropdown::showFromArray('allowed_itemtypes', self::getEligibleItemtypes(), [
            'values'   => $this->getAllowedItemtypes(),
            'multiple' => true,
            'width'    => '100%',
        ]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Permitir selecionar múltiplos itens', 'termodocs') . '</td><td colspan="3">';
        Dropdown::showYesNo('allow_multiple_items', $this->fields['allow_multiple_items'] ?? 1);
        echo ' <small class="text-muted">' .
            __('A assinatura de quem recebe/devolve é sempre obrigatória e não pode ser desativada.', 'termodocs') .
            '</small>';
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Comments') . '</td><td colspan="3">';
        echo '<textarea class="form-control" name="description" rows="2">' . htmlspecialchars($this->fields['description'] ?? '') . '</textarea>';
        echo '</td></tr>';

        $is_new = $this->isNewItem();

        echo '<tr class="tab_bg_1"><td colspan="4"><b>' . __('Cabeçalho', 'termodocs') . '</b></td></tr>';
        echo '<tr class="tab_bg_1"><td colspan="4">';
        echo '<textarea class="form-control" id="termodocs-header-html" name="header_html" rows="4">';
        echo htmlspecialchars($is_new ? DocumentTemplateDefaults::getHeaderHtml() : ($this->fields['header_html'] ?? ''));
        echo '</textarea>';
        echo '</td></tr>';
        Html::initEditorSystem('termodocs-header-html', mt_rand(), true, false, false, 150);

        echo '<tr class="tab_bg_1"><td colspan="4"><b>' . __('Rodapé', 'termodocs') . '</b> ';
        echo '<small class="text-muted">' . __('Use {PAGENO} e {nb} para número da página e total de páginas.', 'termodocs') . '</small>';
        echo '</td></tr>';
        echo '<tr class="tab_bg_1"><td colspan="4">';
        echo '<textarea class="form-control" id="termodocs-footer-html" name="footer_html" rows="4">';
        echo htmlspecialchars($is_new ? DocumentTemplateDefaults::getFooterHtml() : ($this->fields['footer_html'] ?? ''));
        echo '</textarea>';
        echo '</td></tr>';
        Html::initEditorSystem('termodocs-footer-html', mt_rand(), true, false, false, 150);

        echo '<tr class="tab_bg_1"><td colspan="4">';
        echo '<div class="d-flex justify-content-between align-items-center">';
        echo '<label class="mb-0"><b>' . __('Conteúdo do termo', 'termodocs') . '</b></label>';
        echo '<span>';
        echo '<select id="termodocs-field-itemtype" class="form-select form-select-sm d-inline-block w-auto">';
        foreach (self::getEligibleItemtypes() as $itemtype => $label) {
            echo '<option value="' . htmlspecialchars($itemtype) . '">' . htmlspecialchars($label) . '</option>';
        }
        echo '</select> ';
        echo '<select id="termodocs-field-picker" class="form-select form-select-sm d-inline-block w-auto"><option value="">' . __('Inserir campo...', 'termodocs') . '</option></select> ';
        echo '<button type="button" id="termodocs-preview-btn" class="btn btn-sm btn-outline-secondary">' .
            __('Pré-visualizar', 'termodocs') . '</button>';
        echo '</span></div>';
        echo '<textarea class="form-control text-monospace" id="termodocs-content-html" name="content_html" rows="20">';
        echo htmlspecialchars($is_new ? DocumentTemplateDefaults::getContentHtml() : ($this->fields['content_html'] ?? ''));
        echo '</textarea>';
        echo '<small class="text-muted">' . __('Use {% for item in items %}...{% endfor %} para listar os ativos selecionados na geração (ex: item.name, item.serial).', 'termodocs') . '</small>';
        echo '</td></tr>';
        // Deliberately NOT using Html::initEditorSystem() here (unlike
        // header/footer above): this field's content mixes Twig control
        // tags ({% for item in items %}) with <table> markup, and a
        // WYSIWYG editor's underlying browser HTML parser silently
        // foster-parents bare text sitting directly inside <table> (but
        // outside <td>/<tr>) out of the table entirely - which corrupts
        // the for/endfor pair the moment an admin opens or saves the
        // form through the rich editor, breaking item-data placeholders
        // without any visible error. Plain source editing round-trips
        // byte-exact instead.

        echo '<tr class="tab_bg_1"><td colspan="4"><i class="ti ti-info-circle"></i> ';
        echo __('CSS avançado (opcional): estiliza as classes .td-page, .td-header, .td-content e .td-footer, além de qualquer classe usada no conteúdo. Mantenha compatível com o gerador de PDF (evite CSS grid/flexbox complexo).', 'termodocs');
        echo '</td></tr>';
        echo '<tr class="tab_bg_1"><td colspan="4">';
        echo '<textarea class="form-control text-monospace" name="css" rows="10">';
        echo htmlspecialchars($is_new ? DocumentTemplateDefaults::getCss() : ($this->fields['css'] ?? ''));
        echo '</textarea>';
        echo '</td></tr>';

        global $CFG_GLPI;
        $preview_url = $CFG_GLPI['root_doc'] . '/plugins/termodocs/ajax/preview.php';
        $csrf_token = Session::getNewCSRFToken();

        echo Html::scriptBlock(<<<JS
            (function() {
                const itemtypeSelect = document.getElementById('termodocs-field-itemtype');
                const fieldSelect = document.getElementById('termodocs-field-picker');
                const textarea = document.getElementById('termodocs-content-html');

                function loadFields() {
                    fieldSelect.innerHTML = '<option value="">{$this->escapeJs(__('Inserir campo...', 'termodocs'))}</option>';
                    fetch(CFG_GLPI.root_doc + '/plugins/termodocs/ajax/fields.php?itemtype=' + encodeURIComponent(itemtypeSelect.value))
                        .then(r => r.json())
                        .then(groups => {
                            Object.keys(groups).forEach(function(group) {
                                const optgroup = document.createElement('optgroup');
                                optgroup.label = group;
                                groups[group].forEach(function(field) {
                                    const opt = document.createElement('option');
                                    opt.value = field.token;
                                    opt.textContent = field.label + ' (' + field.token + ')';
                                    optgroup.appendChild(opt);
                                });
                                fieldSelect.appendChild(optgroup);
                            });
                        });
                }

                function getEditor(id) {
                    return (typeof tinymce !== 'undefined') ? tinymce.get(id) : null;
                }

                itemtypeSelect.addEventListener('change', loadFields);
                fieldSelect.addEventListener('change', function() {
                    if (!fieldSelect.value) {
                        return;
                    }
                    const editor = getEditor('termodocs-content-html');
                    if (editor) {
                        editor.execCommand('mceInsertContent', false, fieldSelect.value);
                        editor.focus();
                    } else {
                        const start = textarea.selectionStart;
                        const end = textarea.selectionEnd;
                        const value = textarea.value;
                        textarea.value = value.slice(0, start) + fieldSelect.value + value.slice(end);
                        textarea.focus();
                    }
                    fieldSelect.value = '';
                });

                loadFields();

                document.getElementById('termodocs-preview-btn').addEventListener('click', function() {
                    ['termodocs-header-html', 'termodocs-footer-html', 'termodocs-content-html'].forEach(function(id) {
                        const editor = getEditor(id);
                        if (editor) {
                            editor.save();
                        }
                    });
                    const bgSelect = document.querySelector('[name="background_documents_id"]');
                    const body = new URLSearchParams();
                    body.set('header_html', document.getElementById('termodocs-header-html').value);
                    body.set('footer_html', document.getElementById('termodocs-footer-html').value);
                    body.set('content_html', textarea.value);
                    body.set('css', document.querySelector('[name="css"]').value);
                    body.set('background_documents_id', bgSelect ? bgSelect.value : '');
                    body.set('itemtype', itemtypeSelect.value);
                    body.set('_glpi_csrf_token', '{$csrf_token}');

                    fetch('{$preview_url}', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: body.toString()
                    })
                        .then(r => r.text())
                        .then(function(html) {
                            const win = window.open('', '_blank');
                            win.document.write(html);
                            win.document.close();
                        });
                });
            })();
        JS);

        $this->showFormButtons($options);
        return true;
    }

    private function escapeJs(string $value): string
    {
        return addslashes($value);
    }
}
