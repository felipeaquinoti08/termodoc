<?php

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

Html::header(__('Termodocs', 'termodocs'));

function termodocs_copy_field(string $id, string $value): string
{
    $html = '<code id="' . $id . '" style="word-break:break-all;">' . htmlspecialchars($value) . '</code> ';
    $html .= '<button type="button" class="btn btn-sm btn-outline-secondary termodocs-copy-btn" data-target="' . $id . '">';
    $html .= '<i class="ti ti-copy"></i> ' . __('Copiar', 'termodocs');
    $html .= '</button>';
    return $html;
}

/**
 * Renders the "Assinei.digital" settings sub-tab. Each e-signature
 * provider registered in SignatureProviderManager gets its own
 * function + <li>/<div> pair below - this is the one and only one
 * today, but the markup is already split so a second provider (e.g.
 * Clicksign) is a copy of this block plus its own tab entry, not a
 * restructuring.
 */
function termodocs_config_tab_assinei(array $config): void
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
    echo '<div class="form-text">' . __('Identificador (GUID) da conta/tenant Aliare - visível nas configurações da conta em app.assinei.digital, ou informado pela equipe da Assinei.digital ao criar o acesso.', 'termodocs') . '</div>';
    echo '</td></tr>';

    echo '<tr><td>' . __('Cofre ID', 'termodocs') . '</td><td>';
    echo Html::input('cofre_id', ['value' => $config['cofre_id'], 'size' => 50]);
    echo '<div class="form-text">' . __('Crie um Cofre em app.assinei.digital (área de Cofres/Vaults) ou via POST /v1/Cofre e cole o ID aqui - todos os termos gerados por este GLPI serão salvos nele.', 'termodocs') . '</div>';
    echo '</td></tr>';

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
termodocs_config_tab_assinei($config);
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
