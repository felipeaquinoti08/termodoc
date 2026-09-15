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
        // cofre_nome is just the display name typed here - cofre_id (the
        // value the API actually needs) is deliberately NOT part of this
        // generic save, so this form can never overwrite it with a blank
        // value; only "Buscar"/"Criar novo cofre" below ever set it,
        // atomically together with the matching name.
        'cofre_nome'            => trim((string) ($_POST['cofre_nome'] ?? '')),
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

// Admins never type/paste a raw Cofre ID (UUID) anywhere - they only
// ever deal with a name, either an existing one to look up ("Buscar")
// or a new one to create ("Criar novo cofre"). Both actions below
// resolve/create the real ID and save it together with the matching
// name immediately (redirecting on success), so cofre_id and cofre_nome
// can never end up out of sync with each other.
//
// "Cofre" in the API shows up as "Pasta" under Documentos in Assinei's
// own portal UI (different name, easy to miss). Both actions need
// Subscription-Key, usuário/senha and Tenant ID already saved above -
// they only read the already-persisted config, never what's currently
// typed in the form, so they can't accidentally overwrite a saved
// secret with a blank one.
//
// There is deliberately no "Listar cofres existentes" button: GET
// /v1/Cofre/GetAll hangs until timeout in production (confirmed against
// this tenant), and the paginated alternative
// (AssineiApiClient::listUserVaults(), used by "Buscar" below for a
// single best-effort name match) returns inconsistent result counts
// between identical calls - not reliable enough to present as a
// definitive list, only as a "did we happen to find this one name" check.
if (isset($_POST['search_vault'])) {
    $nome = trim((string) ($_POST['cofre_nome'] ?? ''));
    if (!AssineiConfig::hasAuthCredentials()) {
        Session::addMessageAfterRedirect(
            __('Salve Subscription-Key, usuário/senha e Tenant ID antes de buscar um cofre.', 'termodocs'),
            false,
            ERROR
        );
    } elseif ($nome === '') {
        Session::addMessageAfterRedirect(__('Preencha o nome do cofre antes de buscar.', 'termodocs'), false, ERROR);
    } else {
        try {
            $vault = (new AssineiApiClient())->findVaultByName($nome);
            if ($vault === null || !is_string($vault['id'] ?? null)) {
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('Nenhum cofre chamado "%s" foi encontrado. A busca da Assinei é limitada (nem sempre traz tudo) - confira o nome exato no portal deles, ou crie um novo abaixo.', 'termodocs'),
                        $nome
                    ),
                    false,
                    ERROR
                );
            } else {
                AssineiConfig::set(['cofre_nome' => $vault['titulo'] ?? $nome, 'cofre_id' => $vault['id']]);
                Session::addMessageAfterRedirect(sprintf(__('Cofre "%s" encontrado e salvo.', 'termodocs'), $vault['titulo'] ?? $nome));
                Html::redirect($CFG_GLPI['root_doc'] . '/plugins/termodocs/front/config.php');
            }
        } catch (AssineiApiException $e) {
            Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        }
    }
}

if (isset($_POST['create_vault'])) {
    if (!AssineiConfig::hasAuthCredentials()) {
        Session::addMessageAfterRedirect(
            __('Salve Subscription-Key, usuário/senha e Tenant ID antes de criar um cofre.', 'termodocs'),
            false,
            ERROR
        );
    } else {
        $titulo = trim((string) ($_POST['new_vault_titulo'] ?? ''));
        if ($titulo === '') {
            Session::addMessageAfterRedirect(__('Informe um nome para o novo cofre.', 'termodocs'), false, ERROR);
        } else {
            try {
                $response = (new AssineiApiClient())->createVault($titulo);
                $new_vault_id = is_string($response['data'] ?? null)
                    ? $response['data']
                    : ($response['id'] ?? $response['data']['id'] ?? null);
                if (!is_string($new_vault_id) || $new_vault_id === '') {
                    Session::addMessageAfterRedirect(
                        __('O cofre foi criado, mas não consegui identificar o ID retornado - confira em app.assinei.digital.', 'termodocs'),
                        false,
                        ERROR
                    );
                } else {
                    AssineiConfig::set(['cofre_nome' => $titulo, 'cofre_id' => $new_vault_id]);
                    Session::addMessageAfterRedirect(sprintf(__('Cofre "%s" criado e salvo.', 'termodocs'), $titulo));
                    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/termodocs/front/config.php');
                }
            } catch (AssineiApiException $e) {
                Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
            }
        }
    }
}

/**
 * "Atualizações" tab - runs `git` directly against the plugin's own
 * checkout under plugins/termodocs (a real git clone of
 * github.com/felipeaquinoti08/termodoc, not just downloaded files),
 * since the PHP-FPM image now ships git for exactly this. No branch/ref
 * ever comes from user input - always `origin`/the currently checked
 * out branch - so there's nothing here for a submitted form field to
 * inject into the command.
 */
function termodocs_plugin_dir(): string
{
    return dirname(__DIR__);
}

/**
 * @return array{0:int,1:string} [exit code, combined stdout+stderr]
 */
function termodocs_run_git(array $args): array
{
    $cmd = 'git ' . escapeshellarg('-C') . ' ' . escapeshellarg(termodocs_plugin_dir());
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    exec($cmd . ' 2>&1', $output, $return_code);
    return [$return_code, implode("\n", $output)];
}

$update_pending_log = null;
$update_apply_output = null;

if (isset($_POST['check_updates']) || isset($_POST['apply_update'])) {
    [$fetch_code, $fetch_output] = termodocs_run_git(['fetch', 'origin']);

    if ($fetch_code !== 0) {
        Session::addMessageAfterRedirect(
            sprintf(__('Não foi possível buscar atualizações: %s', 'termodocs'), $fetch_output),
            false,
            ERROR
        );
    } elseif (isset($_POST['check_updates'])) {
        [, $branch] = termodocs_run_git(['rev-parse', '--abbrev-ref', 'HEAD']);
        [, $update_pending_log] = termodocs_run_git(['log', '--oneline', 'HEAD..origin/' . trim($branch)]);
    } else {
        [$pull_code, $pull_output] = termodocs_run_git(['pull', '--ff-only']);
        if ($pull_code !== 0) {
            Session::addMessageAfterRedirect(
                sprintf(__('git pull falhou, nada foi alterado: %s', 'termodocs'), $pull_output),
                false,
                ERROR
            );
        } else {
            // Deliberately NOT calling Plugin::install()/activate() in
            // this same request: setup.php (PLUGIN_TERMODOCS_VERSION and
            // every hook function) was already `require`'d earlier in
            // THIS process's boot, before the pull - a `define()`d
            // constant can't pick up the just-pulled file's new value
            // until a fresh PHP request loads it. Stashing the pull
            // output and redirecting hands the rest to a new request,
            // where GLPI's own PostBootListener\CheckPluginsStates has
            // already re-read the updated setup.php and flagged the
            // version change before our code below even runs.
            $_SESSION['termodocs_update_pull_output'] = $pull_output;
            Html::redirect($CFG_GLPI['root_doc'] . '/plugins/termodocs/front/config.php?updated=1');
        }
    }
}

if (isset($_GET['updated'])) {
    $update_apply_output = (string) ($_SESSION['termodocs_update_pull_output'] ?? '');
    unset($_SESSION['termodocs_update_pull_output']);

    // Mirrors exactly what `bin/console plugin:install`/`activate` do -
    // re-running the install function (safe/idempotent, see
    // install/install.php's migration functions) and setting the DB's
    // version/state rows, all in-process so no shell_exec into a second
    // PHP process is needed for this half.
    $plugin = new Plugin();
    if ($plugin->getFromDBbyDir('termodocs')) {
        $plugin_id = $plugin->getID();
        $plugin->install($plugin_id);
        $plugin->getFromDB($plugin_id);
        if ((int) $plugin->fields['state'] !== Plugin::ACTIVATED) {
            $plugin->activate($plugin_id);
        }
    }
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
 * "Criar novo cofre" - a straight text input + submit, kept as its own
 * function purely for symmetry with the rest of this tab's sections.
 * create_vault's handler near the top of this file redirects on
 * success, so there is nothing to render here on that path - only a
 * failed attempt (a flash message, shown by Html::header() itself)
 * lands back on this same form.
 */
function termodocs_render_vault_helper(): void
{
    echo '<div class="card card-body bg-light mb-3">';
    echo '<div class="d-flex gap-2 flex-wrap">';
    echo Html::input('new_vault_titulo', ['placeholder' => __('Nome do novo cofre', 'termodocs'), 'size' => 30]);
    echo Html::submit(__('Criar novo cofre', 'termodocs'), ['name' => 'create_vault']);
    echo '</div>';
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
    echo '<div class="form-text">' . __('Em app.assinei.digital (não no developers.aliare.digital): perfil do usuário → "Detalhes da conta". Se não aparecer lá, peça ao time de operação da Assinei.digital.', 'termodocs') . '</div>';
    echo '</td></tr>';

    echo '<tr><td>' . __('Nome do Cofre', 'termodocs') . '</td><td>';
    echo Html::input('cofre_nome', ['value' => $config['cofre_nome'], 'size' => 40]);
    echo ' ' . Html::submit(__('Buscar', 'termodocs'), ['name' => 'search_vault']);
    echo '<div class="form-text">' . __('Chamado de "Cofre" na API, mas aparece como "Pasta" dentro de Documentos no portal da Assinei.digital. Digite o nome exato de uma pasta já existente e clique em "Buscar" - o ID técnico é resolvido e salvo automaticamente, sem precisar copiar/colar nada. Salve o Tenant ID acima primeiro. A busca da Assinei é limitada e pode não encontrar toda pasta existente; se não achar, confira o nome exato no portal deles ou crie uma nova logo abaixo.', 'termodocs') . '</div>';
    if ($config['cofre_id'] !== '') {
        echo '<div class="form-text mt-1">' . sprintf(
            __('ID técnico resolvido: %s', 'termodocs'),
            termodocs_copy_field('termodocs-cofre-id', $config['cofre_id'])
        ) . '</div>';
    }
    echo '</td></tr>';

    echo '</table>';

    termodocs_render_vault_helper();

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

/**
 * "Atualizações" sub-tab - lets an admin pull the latest commit from
 * github.com/felipeaquinoti08/termodoc and reinstall/reactivate the
 * plugin without a shell into the server (needs the PHP-FPM image to
 * ship `git` - see docker/php/Dockerfile in the infra repo). `git pull`
 * only ever runs with `--ff-only`: if the checkout has local changes or
 * history has diverged, it fails loudly instead of attempting a merge
 * against a live plugin.
 */
function termodocs_config_tab_updates(?string $update_pending_log, ?string $update_apply_output): void
{
    [, $current_commit] = termodocs_run_git(['log', '-1', '--format=%h %ad %s', '--date=short']);

    echo '<p>' . sprintf(__('Commit atual: %s', 'termodocs'), '<code>' . htmlspecialchars($current_commit) . '</code>') . '</p>';

    echo '<div class="d-flex gap-2 mb-3">';
    echo Html::submit(__('Verificar atualizações', 'termodocs'), ['name' => 'check_updates']);
    echo Html::submit(__('Atualizar agora', 'termodocs'), ['name' => 'apply_update', 'class' => 'btn btn-primary']);
    echo '</div>';

    if ($update_pending_log !== null) {
        if (trim($update_pending_log) === '') {
            echo '<div class="alert alert-success">' . __('Já está na versão mais recente.', 'termodocs') . '</div>';
        } else {
            echo '<div class="alert alert-info">';
            echo '<strong>' . __('Commits pendentes:', 'termodocs') . '</strong>';
            echo '<pre class="mb-0 mt-1">' . htmlspecialchars($update_pending_log) . '</pre>';
            echo '</div>';
        }
    }

    if ($update_apply_output !== null) {
        echo '<div class="alert alert-success">';
        echo '<strong>' . __('Atualizado e reativado com sucesso.', 'termodocs') . '</strong>';
        echo '<pre class="mb-0 mt-1">' . htmlspecialchars($update_apply_output) . '</pre>';
        echo '</div>';
    }
}

echo '<div class="card m-3">';
echo '<div class="card-body">';
echo '<h3>' . __('Assinatura eletrônica', 'termodocs') . '</h3>';
echo '<p class="text-muted">' . __('Uma aba por provedor de assinatura eletrônica suportado.', 'termodocs') . '</p>';
echo '</div></div>';

// After a redirect back from "Atualizar agora" (?updated=1), land
// straight on the "Atualizações" tab so the result is visible without
// an extra click.
$updates_tab_active = isset($_GET['updated']);

echo '<form method="post" action="' . $CFG_GLPI['root_doc'] . '/plugins/termodocs/front/config.php" class="card m-3">';
echo '<div class="card-header p-0">';
echo '<ul class="nav nav-tabs card-header-tabs px-3 pt-2" role="tablist">';
echo '<li class="nav-item" role="presentation">';
echo '<button class="nav-link' . ($updates_tab_active ? '' : ' active') . '" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#termodocs-tab-assinei">';
echo __('Assinei.digital', 'termodocs');
echo '</button></li>';
// Um novo provedor de assinatura (Clicksign, Autentique...) entra aqui
// como mais um <li>/<button data-bs-target="#termodocs-tab-<chave>"> +
// sua própria função termodocs_config_tab_<chave>(), sem mexer no que
// já existe. "Atualizações" abaixo é a mesma ideia mas pra uma
// preocupação do plugin como um todo, não de um provedor específico.
echo '<li class="nav-item" role="presentation">';
echo '<button class="nav-link' . ($updates_tab_active ? ' active' : '') . '" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#termodocs-tab-updates">';
echo __('Atualizações', 'termodocs');
echo '</button></li>';
echo '</ul>';
echo '</div>';

echo '<div class="card-body">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo '<div class="tab-content">';
echo '<div class="tab-pane fade' . ($updates_tab_active ? '' : ' show active') . '" id="termodocs-tab-assinei" role="tabpanel">';
termodocs_config_tab_assinei($config);
echo '</div>';
echo '<div class="tab-pane fade' . ($updates_tab_active ? ' show active' : '') . '" id="termodocs-tab-updates" role="tabpanel">';
termodocs_config_tab_updates($update_pending_log, $update_apply_output);
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
