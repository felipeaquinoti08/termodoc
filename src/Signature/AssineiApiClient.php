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
     * GET /v1/Cofre/GetAll - only used from front/config.php's "Listar
     * cofres existentes" helper, so an admin can find/confirm a Cofre ID
     * without leaving GLPI (it's not surfaced anywhere obvious in
     * Assinei's own portal UI).
     */
    public function listVaults(): array
    {
        return $this->request('GET', '/v1/Cofre/GetAll');
    }

    /**
     * POST /v1/Cofre - same "Criar novo cofre" helper as listVaults().
     * `sigilo`: 1 = público (dentro do tenant), per the "Integração -
     * Processo Básico" example.
     */
    public function createVault(string $titulo, string $descricao = ''): array
    {
        return $this->request('POST', '/v1/Cofre', [
            'titulo'    => $titulo,
            'descricao' => $descricao,
            'sigilo'    => 1,
        ]);
    }
}
