<?php

namespace GlpiPlugin\Termodocs\Signature;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin REST client for the Assinei.digital ("Documentos API 2.0" /
 * Aliare Identity) HTTP API - see
 * https://docassinei.dev.conexa.com.br/docs/category/documenta%C3%A7%C3%A3o-api
 *
 * This wraps only the handful of calls the delivery/return sign-off flow
 * needs (create document + participants, send for signature, check
 * status). It deliberately does not attempt the certificate/A3/manual
 * signing endpoints - those are for building a custom in-app signing UI,
 * while this integration hands signers off to Assinei's own hosted
 * signing page (reached through the link Assinei sends them directly),
 * which is what `document_signed`/`document_refused` webhook events
 * report back.
 *
 * Every method throws AssineiApiException on anything but a 2xx
 * response, with the raw request/response captured on the exception so
 * callers can log it verbatim - useful while confirming the exact field
 * names Assinei's tenant actually returns (the public docs describe the
 * shape but were read through a summarizing fetch, not copied
 * byte-for-byte from a live response).
 */
class AssineiApiClient
{
    private array $config;
    private ?string $access_token = null;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? AssineiConfig::get();
    }

    private function client(): Client
    {
        return new Client(['timeout' => 30]);
    }

    private function authenticate(): string
    {
        if ($this->access_token !== null) {
            return $this->access_token;
        }

        try {
            $response = $this->client()->post($this->config['auth_url'], [
                'headers' => [
                    'Content-Type'      => 'application/x-www-form-urlencoded',
                    'Accept'            => 'application/json',
                    'Subscription-Key'  => $this->config['subscription_key'],
                    'User'              => $this->config['portal_user'],
                    'Password'          => $this->config['portal_password'],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw AssineiApiException::fromGuzzleException('POST', $this->config['auth_url'], $e);
        }

        $body = json_decode((string) $response->getBody(), true) ?? [];
        $token = $body['access_token'] ?? $body['accessToken'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new AssineiApiException(
                'POST',
                $this->config['auth_url'],
                $response->getStatusCode(),
                (string) $response->getBody(),
                'Resposta de autenticação sem access_token.'
            );
        }

        return $this->access_token = $token;
    }

    /**
     * @param array<string,mixed>|null $json
     * @return array<string,mixed> decoded JSON body
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $url = rtrim($this->config['base_url'], '/') . '/' . ltrim($path, '/');

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->authenticate(),
                'X-Tenant'      => $this->config['tenant_id'],
                'Accept'        => 'application/json',
            ],
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->client()->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw AssineiApiException::fromGuzzleException($method, $url, $e);
        }

        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Some endpoints (e.g. Enviar) may reply with an empty body
            // on 2xx - not an error, just nothing to parse.
            return [];
        }

        return $decoded;
    }

    /**
     * Combined "create document + participants" call
     * (POST /v1/Cofre/{cofreId}/NovoDocumento) - the recommended
     * single-shot flow per "Integração - Processo Básico". Returns the
     * decoded response; the created document's id is under `data` for
     * some endpoints and top-level `id` for others in the published
     * docs, so callers should check both (see
     * AssineiDigitalProvider::extractDocumentId()).
     *
     * @param array<int,array{nome:string,email:string,documento?:string}> $participants
     */
    public function createDocumentWithParticipants(
        string $titulo,
        string $nomeArquivo,
        string $documentoBase64,
        array $participants
    ): array {
        $participantesDocumento = [];
        foreach ($participants as $p) {
            $entry = [
                'participanteNome'       => $p['nome'],
                'participanteEmail'      => $p['email'],
                'participanteTipoPessoa' => 0, // 0 = pessoa física
                'tiposParticipante'      => [
                    ['tipoParticipanteId' => $this->config['participante_tipo_id']],
                ],
            ];
            if (!empty($p['documento'])) {
                $entry['documentoDeIdentificacao'] = $p['documento'];
            }
            $participantesDocumento[] = $entry;
        }

        return $this->request('POST', '/v1/Cofre/' . $this->config['cofre_id'] . '/NovoDocumento', [
            'titulo'                 => $titulo,
            'nomeArquivo'             => $nomeArquivo,
            'documentoBase64'          => $documentoBase64,
            'cofreId'                   => $this->config['cofre_id'],
            'dataDocumento'               => date('c'),
            'participantesDocumento'        => $participantesDocumento,
        ]);
    }

    /**
     * POST /v1/DocumentoEnvio/{documentoId}/Enviar - actually dispatches
     * the document to every participant and flips its status to
     * "aguardando assinatura". Nothing before this call notifies anyone.
     */
    public function sendForSignature(string $documentoId, string $ip): array
    {
        return $this->request('POST', '/v1/DocumentoEnvio/' . $documentoId . '/Enviar', [
            'ip'       => $ip,
            'dataHora' => date('c'),
        ]);
    }

    /**
     * GET /v1/DocumentoBusca/{documentoId}/Status - used for manual
     * reconciliation (e.g. an admin re-checking a document whose webhook
     * may have been lost), not part of the normal flow.
     */
    public function getStatus(string $documentoId): array
    {
        return $this->request('GET', '/v1/DocumentoBusca/' . $documentoId . '/Status');
    }

    /**
     * GET /v1/DocumentoBusca/{documentoId} - carries
     * urlDocumentoAssinado, the signed PDF's download URL, once
     * complete.
     */
    public function getDocument(string $documentoId): array
    {
        return $this->request('GET', '/v1/DocumentoBusca/' . $documentoId);
    }

    /**
     * GET /v1/DocumentoBusca/{documentoId}/Participante - called once,
     * right after initiate() sends a document, to capture each
     * participant's own unique id (see
     * Document.external_participants/AssineiDigitalProvider::initiate()).
     * Not confirmed against a live tenant which exact field carries that
     * id, so callers should check a short list of candidates rather than
     * one guessed name.
     */
    public function getParticipants(string $documentoId): array
    {
        return $this->request('GET', '/v1/DocumentoBusca/' . $documentoId . '/Participante');
    }

    /**
     * Downloads a file from an absolute URL such as
     * getDocument()'s urlDocumentoAssinado/urlDocumento - unlike every
     * other method here this is NOT a relative path under the API's
     * base_url: Assinei hands back full Azure Blob Storage URLs with
     * their own embedded SAS signature (already valid for an anonymous
     * GET), so this bypasses request()'s auth/X-Tenant headers and
     * base_url prefixing entirely.
     */
    public function downloadFile(string $url): string
    {
        try {
            $response = $this->client()->get($url);
        } catch (GuzzleException $e) {
            throw AssineiApiException::fromGuzzleException('GET', $url, $e);
        }

        return (string) $response->getBody();
    }

    /**
     * GET /v1/Cofre/GetAll - confirmed (against a real tenant) to hang
     * until timeout in production; not called from anywhere in the
     * plugin. Left here only in case Assinei fixes it later - use
     * getVault() to confirm a specific Cofre ID instead, which does work.
     */
    public function listVaults(): array
    {
        return $this->request('GET', '/v1/Cofre/GetAll');
    }

    /**
     * POST /v1/Cofre - "Criar novo cofre" helper in front/config.php.
     * `sigilo`: 1 = público (dentro do tenant), per the "Integração -
     * Processo Básico" example. A "Cofre" here shows up as "Pasta" under
     * Documentos in Assinei's own portal UI.
     */
    public function createVault(string $titulo, string $descricao = ''): array
    {
        return $this->request('POST', '/v1/Cofre', [
            'titulo'    => $titulo,
            'descricao' => $descricao,
            'sigilo'    => 1,
        ]);
    }

    /**
     * GET /v1/Cofre/{cofreId} - "Verificar" helper in front/config.php,
     * confirming a saved Cofre ID still exists/is reachable (confirmed
     * working, unlike listVaults() above).
     */
    public function getVault(string $cofreId): array
    {
        return $this->request('GET', '/v1/Cofre/' . $cofreId);
    }

    /**
     * POST /v1/CofreUsuario/CofresDoUsuarioPaginados - responds (unlike
     * GET /v1/Cofre/GetAll above, which hangs), but its `count`/`total`
     * fields and how many rows come back are inconsistent between
     * identical calls (confirmed against a real tenant: 90 results with
     * a claimed total of 5134 one time, 15 results with a claimed total
     * of 15 the next, neither explained by `pageSize` or the `nome`
     * filter) - not reliable enough to build a full listing feature on.
     * Every one of those test calls did still include the specific
     * cofre being looked for, though, which is why findVaultByName()
     * below builds on it for a single best-effort name lookup rather
     * than avoiding it entirely.
     */
    public function listUserVaults(int $pageSize = 200): array
    {
        return $this->request('POST', '/v1/CofreUsuario/CofresDoUsuarioPaginados', [
            'pageNumber' => 1,
            'pageSize'   => $pageSize,
        ]);
    }

    /**
     * Resolves a Cofre/Pasta's name to its id - the API's own `nome`
     * filter parameter on the paginated endpoint doesn't actually filter
     * (confirmed: searching "GLPI" returned unrelated results too), so
     * this fetches a page and matches the title client-side instead.
     * Best-effort given listUserVaults()'s inconsistency above: returns
     * null if not found in whatever page came back, which does not
     * necessarily mean the cofre doesn't exist.
     */
    public function findVaultByName(string $nome): ?array
    {
        $response = $this->listUserVaults();
        foreach (($response['data'] ?? []) as $vault) {
            if (isset($vault['titulo']) && strcasecmp(trim((string) $vault['titulo']), trim($nome)) === 0) {
                return $vault;
            }
        }
        return null;
    }
}
