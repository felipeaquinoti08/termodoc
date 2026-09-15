<?php

use GlpiPlugin\Termodocs\Signature\AssineiApiClient;
use GlpiPlugin\Termodocs\Signature\AssineiApiException;
use GlpiPlugin\Termodocs\Signature\AssineiConfig;

Session::checkRight('config', UPDATE);

global $CFG_GLPI;

if (isset($_POST['update']) || isset($_POST['regenerate_webhook_secret'])) {
    $values = [
        'is_active'             => (int) ($_POST['is_active'] ?? 0),
        'base_url'              => trim((string) ($_POST['base_url'] ?? '')),
        'auth_url'              => trim((string) ($_POST['auth_url'] ?? '')),
        'portal_user'           => trim((string) ($_POST['portal_user'] ?? '')),
        'tenant_id'             => trim((string) ($_POST['tenant_id'] ?? '')),
        'cofre_id'              => trim((string) ($_POST['cofre_id'] ?? '')),
        'participante_tipo_id'  => trim((string) ($_POST['participante_tipo_id'] ?? '')) ?: AssineiConfig::DEFAULT_PARTICIPANT_TYPE_ID,
    ];

    // Secrets are always rendered empty below - only overwrite what was
    // actually retyped, same as GlpiPlugin\Entrasso\Config's client_secret.
    if (trim((string) ($_POST['subscription_key'] ?? '')) !== '') {
        $values['subscription_key'] = trim((string) $_POST['subscription_key']);
    }
    if (trim((string) ($_POST['portal_password'] ?? '')) !== '') {
        $values['portal_password'] = trim((string) $_POST['portal_password']);
    }

    if (isset($_POST['regenerate_webhook_secret'])) {
        $values['webhook_secret'] = AssineiConfig::generateWebhookSecret();
    }

    AssineiConfig::set($values);
    Session::addMessageAfterRedirect(__('Configuração salva com sucesso.', 'termodocs'));
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/termodocs/front/config.php');
}

$config = AssineiConfig::get();
if ($config['webhook_secret'] === '') {
    AssineiConfig::set(['webhook_secret' => AssineiConfig::generateWebhookSecret()]);
    $config = AssineiConfig::get();
}

// "Listar/Criar cofre" helpers below - Assinei's own portal doesn't
// obviously surface a Cofre ID anywhere, so this lets an admin find or
// create one without leaving GLPI (needs Subscription-Key, usuário/senha
// and Tenant ID already saved above - these two actions only read the
// already-persisted config, never what's currently typed in the form,
// so they can't accidentally overwrite a saved secret with a blank one).
$vaults_result = null;
$created_vault_id = null;
if (isset($_POST['list_vaults']) || isset($_POST['create_vault'])) {
    if (!AssineiConfig::isConfigured()) {
        Session::addMessageAfterRedirect(
            __('Salve Subscription-Key, usuário/senha, Tenant ID e Cofre ID (pode deixar em branco por enquanto) antes de listar/criar cofres.', 'termodocs'),
            false,
            ERROR
        );
    } else {
        try {
            $client = new AssineiApiClient();
            if (isset($_POST['list_vaults'])) {
                $vaults_result = termodocs_normalize_vaults($client->listVaults());
            } else {
                $titulo = trim((string) ($_POST['new_vault_titulo'] ?? ''));
                if ($titulo === '') {
                    Session::addMessageAfterRedirect(__('Informe um nome para o novo cofre.', 'termodocs'), false, ERROR);
                } else {
                    $response = $client->createVault($titulo);
                    $created_vault_id = is_string($response['data'] ?? null)
                        ? $response['data']
                        : ($response['id'] ?? $response['data']['id'] ?? null);
                }
            }
        } catch (AssineiApiException $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        }
    }
}

Html::header(__('Termodocs', 'termodocs'));

/**
 * Response shape for GET /v1/Cofre/GetAll isn't confirmed against a
 * live tenant - tries the plausible wrappers and always falls back to
 * showing the raw JSON so a mismatch is visible rather than an empty
 * list with no explanation.
 */
function termodocs_normalize_vaults(array $response): array
{
    $data = $response['data'] ?? $response;
    if (isset($data['id'])) {
        return [$data];
    }
    return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
}

function termodocs_copy_field(string $id, string $value): string
{
    $html = '<code id="' . $id . '" style="word-break:break-all;">' . htmlspecialchars($value) . '</code> ';
    $html .= '<button type="button" class="btn btn-sm btn-outline-secondary termodocs-copy-btn" data-target="' . $id . '">';
    $html .= '<i class="ti ti-copy"></i> ' . __('Copiar', 'termodocs');
    $html .= '</button>';
    return $html;
}

/**
 * "Listar cofres existentes" / "Criar novo cofre" - see the $vaults_result
 * / $created_vault_id computation near the top of this file. Renders
 * whatever came back from the last submit (null on a normal page load).
 */
function termodocs_render_vault_helper(?array $vaults_result, ?string $created_vault_id): void
{
    echo '<div class="card card-body bg-light mb-3">';
    echo '<div class="d-flex gap-2 flex-wrap">';
    echo Html::submit(__('Listar cofres existentes', 'termodocs'), ['name' => 'list_vaults']);
    echo Html::input('new_vault_titulo', ['placeholder' => __('Nome do novo cofre', 'termodocs'), 'size' => 30]);
    echo Html::submit(__('Criar novo cofre', 'termodocs'), ['name' => 'create_vault']);
    echo '</div>';

    if ($created_vault_id !== null) {
        echo '<div class="alert alert-success mt-2 mb-0">' .
            sprintf(__('Cofre criado: %s - copie para o campo "Cofre ID" acima e salve.', 'termodocs'), '<code>' . htmlspecialchars($created_vault_id) . '</code>') .
            '</div>';
    }

    if ($vaults_result !== null) {
        if (empty($vaults_result)) {
            echo '<div class="alert alert-info mt-2 mb-0">' . __('Nenhum cofre encontrado para este tenant - use "Criar novo cofre" acima.', 'termodocs') . '</div>';
        } else {
            echo '<table class="table table-sm mt-2 mb-0"><thead><tr><th>' .
                __('Nome', 'termodocs') . '</th><th>' . __('ID', 'termodocs') . '</th></tr></thead><tbody>';
            foreach ($vaults_result as $i => $vault) {
                $id = $vault['id'] ?? $vault['cofreId'] ?? '';
                $titulo = $vault['titulo'] ?? $vault['nome'] ?? __('(sem título)', 'termodocs');
                echo '<tr><td>' . htmlspecialchars((string) $titulo) . '</td><td>' .
                    termodocs_copy_field('termodocs-vault-' . $i, (string) $id) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    echo '</div>';
}

/**
 * Renders the "Assinei.digital" settings sub-tab. Each e-signature
 * provider registered in SignatureProviderManager gets its own
 * function + <li>/<div> pair below - this is the one and only one
 * today, but the markup is already split so a second provider (e.g.
 * Clicksign) is a copy of this block plus its own tab entry, not a
 * restructuring.
 */
function termodocs_config_tab_assinei(array $config, ?array $vaults_result, ?string $created_vault_id): void
{
    echo '<table class="table">';

    echo '<tr><td>' . __('Ativo', 'termodocs') . '</td><td>';
    Dropdown::showYesNo('is_active', $config['is_active']);
    echo '<div class="form-text">' . __('Modelos de documento com "Modo de assinatura" = Assinei.digital só serão realmente enviados se estiver ativo e configurado (abaixo).', 'termodocs') . '</div>';
    echo '</td></tr>';

    echo '<tr><td>' . __('URL base da API', 'termodocs') . '</td><td>';
    echo Html::input('base_url', ['value' => $config['base_url'], 'size' => 60]);
    echo '</td></tr>';

    echo '<tr><td>' . __('URL de autenticação (Aliare Identity)', 'termodocs') . '</td><td>';
    echo Html::input('auth_url', ['value' => $config['auth_url'], 'size' => 60]);
    echo '</td></tr>';

    echo '<tr><td>' . __('Subscription-Key', 'termodocs') . '</td><td>';
    echo '<input type="password" name="subscription_key" class="form-control" style="max-width:400px" autocomplete="new-password" placeholder="' .
        ($config['subscription_key'] !== '' ? __('•••••••• (mantenha em branco para não alterar)', 'termodocs') : '') . '">';
    echo '<div class="form-text">' . __('Portal do Desenvolvedor (developers.aliare.digital) → Assinei API → chave de assinatura da API ("Subscription-Key"), gerada ao solicitar acesso/assinatura da API junto à equipe da Assinei.digital.', 'termodocs') . '</div>';
    echo '</td></tr>';

    echo '<tr><td>' . __('Usuário do portal Assinei.digital', 'termodocs') . '</td><td>';
    echo Html::input('portal_user', ['value' => $config['portal_user'], 'size' => 50]);
    echo '<div class="form-text">' . __('O e-mail de login usado em app.assinei.digital (conta com perfil de desenvolvedor/administrador) - é com ele que este GLPI se autentica na API, não é criado à parte.', 'termodocs') . '</div>';
    echo '</td></tr>';

    echo '<tr><td>' . __('Senha do portal Assinei.digital', 'termodocs') . '</td><td>';
    echo '<input type="password" name="portal_password" class="form-control" style="max-width:400px" autocomplete="new-password" placeholder="' .
        ($config['portal_password'] !== '' ? __('•••••••• (mantenha em branco para não alterar)', 'termodocs') : '') . '">';
    echo '</td></tr>';

    echo '<tr><td>' . __('Tenant ID', 'termodocs') . '</td><td>';
    echo Html::input('tenant_id', ['value' => $config['tenant_id'], 'size' => 50]);
    echo '<div class="form-text">' . __('Em app.assinei.digital (não no developers.aliare.digital): perfil do usuário → "Detalhes da conta". Se não aparecer lá, peça ao time de operação da Assinei.digital.', 'termodocs') . '</div>';
    echo '</td></tr>';

    echo '<tr><td>' . __('Cofre ID', 'termodocs') . '</td><td>';
    echo Html::input('cofre_id', ['value' => $config['cofre_id'], 'size' => 50]);
    echo '<div class="form-text">' . __('Salve o Tenant ID acima primeiro - depois use "Listar cofres existentes" ou "Criar novo cofre" logo abaixo para achar/gerar o ID sem sair do GLPI.', 'termodocs') . '</div>';
    echo '</td></tr>';

    echo '</table>';

    termodocs_render_vault_helper($vaults_result, $created_vault_id);

    echo '<table class="table">';

    echo '<tr><td>' . __('Tipo de participante', 'termodocs') . '</td><td>';
    echo Html::input('participante_tipo_id', ['value' => $config['participante_tipo_id'], 'size' => 50]);
    echo '<div class="form-text">' . __('ID (GUID) retornado por GET /v1/Participante/Tipo. Por padrão usa "Parte", genérico o suficiente para colaborador e responsável de TI.', 'termodocs') . '</div>';
    echo '</td></tr>';

    echo '</table>';

    echo '<h4 class="mt-3">' . __('Webhook (recebimento de assinatura/recusa)', 'termodocs') . '</h4>';
    echo '<table class="table">';
    echo '<tr><td>' . __('URL do webhook', 'termodocs') . '</td><td>';
    echo termodocs_copy_field('termodocs-webhook-url', AssineiConfig::getWebhookUrl() . '&secret=' . $config['webhook_secret']);
    echo '<div class="form-text">' . __('Envie esta URL completa (já inclui o segredo) para a equipe da Assinei.digital ao pedir a configuração do webhook - ela é a única forma deles validarem a origem da chamada, então trate-a como uma senha.', 'termodocs') . '</div>';
    echo '</td></tr>';
    echo '<tr><td>' . __('Segredo do webhook', 'termodocs') . '</td><td>';
    echo Html::submit(__('Gerar novo segredo', 'termodocs'), ['name' => 'regenerate_webhook_secret']);
    echo '<div class="form-text">' . __('Invalida a URL do webhook atual - será preciso reenviar a nova URL para a Assinei.digital depois de gerar.', 'termodocs') . '</div>';
    echo '</td></tr>';
    echo '</table>';
}

echo '<div class="card m-3">';
echo '<div class="card-body">';
echo '<h3>' . __('Assinatura eletrônica', 'termodocs') . '</h3>';
echo '<p class="text-muted">' . __('Uma aba por provedor de assinatura eletrônica suportado.', 'termodocs') . '</p>';
echo '</div></div>';

echo '<form method="post" action="' . $CFG_GLPI['root_doc'] . '/plugins/termodocs/front/config.php" class="card m-3">';
echo '<div class="card-header p-0">';
echo '<ul class="nav nav-tabs card-header-tabs px-3 pt-2" role="tablist">';
echo '<li class="nav-item" role="presentation">';
echo '<button class="nav-link active" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#termodocs-tab-assinei">';
echo __('Assinei.digital', 'termodocs');
echo '</button></li>';
// Um novo provedor (Clicksign, Autentique...) entra aqui como mais um
// <li>/<button data-bs-target="#termodocs-tab-<chave>"> + sua própria
// função termodocs_config_tab_<chave>(), sem mexer no que já existe.
echo '</ul>';
echo '</div>';

echo '<div class="card-body">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo '<div class="tab-content">';
echo '<div class="tab-pane fade show active" id="termodocs-tab-assinei" role="tabpanel">';
termodocs_config_tab_assinei($config, $vaults_result, $created_vault_id);
echo '</div>';
echo '</div>';

echo '<div class="mt-3">' . Html::submit(_sx('button', 'Save'), ['name' => 'update']) . '</div>';

echo '</div>';
echo '</form>';

$copied_label = addslashes(__('Copiado!', 'termodocs'));
echo Html::scriptBlock(<<<JS
    document.querySelectorAll('.termodocs-copy-btn').forEach(function(btn) {
        const original = btn.innerHTML;
        btn.addEventListener('click', function() {
            const text = document.getElementById(btn.dataset.target).textContent;
            navigator.clipboard.writeText(text).then(function() {
                btn.innerHTML = '<i class="ti ti-check"></i> {$copied_label}';
                setTimeout(function() { btn.innerHTML = original; }, 1500);
            });
        });
    });
JS);

Html::footer();
