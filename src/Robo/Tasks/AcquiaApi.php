<?php

namespace StanfordCaravan\Robo\Tasks;

use GuzzleHttp\Client;
use League\OAuth2\Client\Provider\GenericProvider;
use GuzzleHttp\Exception\GuzzleException;
use Robo\Tasks;

/**
 * Class AcquiaApi.
 *
 * @package Example\Blt\Plugin\Commands
 */
class AcquiaApi extends Tasks {

  /**
   * Acquia application uuid.
   *
   * @var string
   */
  protected $appId;

  /**
   * Acquia API Key.
   *
   * @var string
   */
  protected $key;

  /**
   * Acquia API Secret.
   *
   * @var string
   */
  protected $secret;

  /**
   * Data of all available environments on the application.
   *
   * @var array
   */
  protected $environments = [];

  /**
   * AcquiaApi constructor.
   *
   * @param array $env_ids
   *   Keyed array of Acquia environment IDS.
   * @param string $apiKey
   *   Acquia API Key.
   * @param string $apiSecret
   *   Acquia API Secret.
   */
  public function __construct($appId, $apiKey, $apiSecret) {
    assert(!empty($appId));
    assert(!empty($apiKey));
    assert(!empty($apiSecret));

    $this->appId = $appId;
    $this->key = $apiKey;
    $this->secret = $apiSecret;

    $environments = $this->getEnvironments();
    $this->environments = $environments['_embedded']['items'];
  }

  /**
   * Get data of all environments on the application.
   *
   * @return bool|string
   *   API Response.
   */
  public function getEnvironments() {
    return $this->callAcquiaApi("/applications/{$this->appId}/environments");
  }

  /**
   * Add a domain to a given environment.
   *
   * @param string $environment
   *   Environment to effect.
   * @param string $domain
   *   Domain to add: foo.stanford.edu.
   *
   * @return bool|string
   *   API Response.
   */
  public function addDomain($environment, $domain) {
    $id = $this->getEnvironmentId($environment);
    return $this->callAcquiaApi("/environments/{$id}/domains", 'POST', ['json' => ['hostname' => $domain]]);
  }

  /**
   * Get a list of all databases on an environment.
   *
   * @param string $environment
   *   Environment to list.
   *
   * @return bool|string
   *   API Response.
   */
  public function getDatabases($environment) {
    $id = $this->getEnvironmentId($environment);
    return $this->callAcquiaApi("/environments/{$id}/databases");
  }

  /**
   * Get a list of all backups on an environment for a given database.
   *
   * @param string $environment
   *   Acquia environment.
   * @param string $databaseName
   *   Acquia database name.
   *
   * @return bool|string
   *   API Response.
   */
  public function getDatabaseBackups($environment, $databaseName) {
    $id = $this->getEnvironmentId($environment);
    return $this->callAcquiaApi("/environments/{$id}/databases/$databaseName/backups");
  }

  /**
   * Delete a single database backup.
   *
   * @param string $environment
   *   Acquia environment.
   * @param string $databaseName
   *   Acquia database name.
   * @param int $backupId
   *   Database backup identifier.
   *
   * @return bool|string
   *   API Response.
   */
  public function deleteDatabaseBackup($environment, $databaseName, $backupId) {
    $id = $this->getEnvironmentId($environment);
    return $this->callAcquiaApi("/environments/{$id}/databases/$databaseName/backups/$backupId", 'DELETE');
  }

  /**
   * Add a database to all environments.
   *
   * @param string $db_name
   *   Database name to add.
   *
   * @return bool|string
   *   API Response.
   */
  public function addDatabase($db_name) {
    return $this->callAcquiaApi("/applications/{$this->appId}/databases", 'POST', ['json' => ['name' => $db_name]]);
  }

  /**
   * Add an SSL Certificate to a given environment.
   *
   * @param string $environment
   *   Environment to effect.
   * @param string $cert
   *   SSL Cert file contents.
   * @param string $key
   *   SSL Key file contents.
   * @param string $intermediate
   *   SSL Intermediate certificate file contents.
   * @param string|null $label
   *   Label for Acquia dashboard.
   *
   * @return bool|string
   *   API Response.
   */
  public function addCert($environment, $cert, $key, $intermediate, $label = NULL) {
    if (is_null($label)) {
      $label = date('Y-m-d G:i');
    }
    $data = [
      'json' => [
        'legacy' => FALSE,
        'certificate' => $cert,
        'private_key' => $key,
        'ca_certificates' => $intermediate,
        'label' => $label,
      ],
    ];
    $id = $this->getEnvironmentId($environment);
    return $this->callAcquiaApi("/environments/{$id}/ssl/certificates", 'POST', $data);
  }

  /**
   * Activate an SSL cert already installed on the environment.
   *
   * @param string $environment
   *   Environment to effect.
   * @param int $certId
   *   Certificate ID to activate.
   *
   * @return bool|string
   *   API Response.
   */
  public function activateCert($environment, $certId) {
    $id = $this->getEnvironmentId($environment);
    return $this->callAcquiaApi("/environments/{$id}/ssl/certificates/{$certId}/actions/activate", 'POST');
  }

  /**
   * Remove a cert from the environment.
   *
   * @param string $environment
   *   Environment to effect.
   * @param int $certId
   *   Certificate ID to remove.
   *
   * @return bool|string
   *   API Response.
   */
  public function removeCert($environment, $certId) {
    $id = $this->getEnvironmentId($environment);
    return $this->callAcquiaApi("/environments/{$id}/ssl/certificates/{$certId}", 'DELETE');
  }

  /**
   * Get all SSL certs that are on the environment.
   *
   * @param string $environment
   *   Environment to effect.
   *
   * @return bool|array
   *   API Response.
   */
  public function getCerts($environment) {
    $id = $this->getEnvironmentId($environment);
    return $response = $this->callAcquiaApi("/environments/{$id}/ssl/certificates");
  }

  /**
   * Deploy a git branch or tag to a certain environment.
   *
   * @param string $environment
   *   Environment to effect.
   * @param string $reference
   *   Git branch or tag name.
   *
   * @return bool|string
   *   API Response.
   */
  public function deployCode($environment, $reference) {
    $id = $this->getEnvironmentId($environment);
    return $this->callAcquiaApi("/environments/{$id}/code/actions/switch", 'POST', ['json' => ['branch' => $reference]]);
  }

  /**
   * Create a cron job on Acquia environment.
   *
   * @param string $environment
   *   Environment to effect.
   * @param string $command
   *   Cron command.
   * @param $label
   *   Cron label.
   * @param string $frequency
   *   Cron notation.
   *
   * @return bool|string
   *   API Response.
   */
  public function createCronJob($environment, $command, $label, $frequency = '0 */6 * * *') {
    $id = $this->getEnvironmentId($environment);
    $cron_data = [
      'command' => $command,
      'frequency' => $frequency,
      'label' => $label,
    ];
    return $this->callAcquiaApi("/environments/{$id}/crons", 'POST', ['json' => $cron_data]);
  }

  /**
   * Make an API call to Acquia Cloud API V2.
   *
   * @param string $path
   *   API Endpoint, options from: https://cloudapi-docs.acquia.com/.
   * @param string $method
   *   Request method: GET, POST, PUT, DELETE.
   * @param array $options
   *   Request options for post json data or headers.
   *
   * @return bool|string
   *   False if it fails, api response string if success.
   *
   * @see https://docs.acquia.com/acquia-cloud/develop/api/auth/
   */
  protected function callAcquiaApi($path, $method = 'GET', array $options = []) {

    $provider = new GenericProvider([
      'clientId' => $this->key,
      'clientSecret' => $this->secret,
      'urlAuthorize' => '',
      'urlAccessToken' => 'https://accounts.acquia.com/api/auth/oauth/token',
      'urlResourceOwnerDetails' => '',
    ]);

    // Try to get an access token using the client credentials grant.
    $accessToken = $provider->getAccessToken('client_credentials');

    // Generate a request object using the access token.
    $request = $provider->getAuthenticatedRequest(
      $method,
      'https://cloud.acquia.com/api/' . ltrim($path, '/'),
      $accessToken
    );

    // Send the request.
    $client = new Client();
    $response = $client->send($request, $options);
    $body = (string) $response->getBody();

    return json_decode($body, TRUE) ?: $body;
  }

  /**
   * Get the environment UUID from the environment name.
   *
   * @param string $environment_name
   *   Machine name like `dev`, `test`, `ode123`.
   *
   * @return string
   *   Acquia UUID of the environment.
   */
  protected function getEnvironmentId($environment_name) {
    foreach ($this->environments as $environment) {
      if ($environment['name'] == $environment_name) {
        return $environment['id'];
      }
    }
  }

}
