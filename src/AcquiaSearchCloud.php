<?php

namespace Drupal\SolrIndexManager;

use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\GenericProvider;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\acquia_search\Helper\Storage as AcquiaSearch;
use Drupal\acquia_connector\Helper\Storage as AcquiaConnector;

/**
 * AcquiaSearchCloud to automated provisioning of Search Cores.
 */
class AcquiaSearchCloud {
  use StringTranslationTrait;

  /**
   * Guzzle\Client instance.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Messenger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $loggerFactory;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Generic provider.
   *
   * @var \League\OAuth2\Client\Provider\GenericProvider
   */
  protected $authProvider;

  /**
   * Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal\Core\Entity\EntityManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The Client ID.
   *
   * @var string|null
   */
  protected $clientId;

  /**
   * The Client Secret.
   *
   * @var string|null
   */
  protected $clientSecret;

  /**
   * The Bayer Application ID.
   *
   * @var string|null
   */
  protected $applicationId;

  /**
   * The Search Index Config ID.
   *
   * @var string|null
   */
  protected $configSetId;

  /**
   * The Bayer Environment ID.
   *
   * @var string|null
   */
  protected $environmentId;

  /**
   * Constructs a new AcmsService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The guzzle client.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entiy type manager service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module handler.
   */
  public function __construct(
    ClientInterface $http_client,
    StateInterface $state,
    LoggerChannelFactory $logger_factory,
    MessengerInterface $messenger,
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    ModuleHandlerInterface $moduleHandler
  ) {
    $this->httpClient = $http_client;
    $this->state = $state;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * Create a search index for specified env.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function createSearchIndex($database_name) {
    $env_uuid = $this->environmentId;
    // Continue with Index creation.
    if ($env_uuid && $database_name) {
      try {
        $api_token = $this->getApiToken();
        $options = [
          'headers' => [
            'Access-Control-Allow-Origin' => 'https://cloud.acquia.com',
            'Accept' => 'application/json',
          ],
        ];

        $uri = 'https://cloud.acquia.com/api/environments/' . $env_uuid . '/search/indexes';
        $index_request = $this->authProvider->getAuthenticatedRequest('GET', $uri, $api_token, $options);
        $index_response = $this->authProvider->getHttpClient()->send($index_request);
        if ($index_response->getStatusCode() == 200) {
          $search_indexes = json_decode($index_response->getBody()->getContents(), TRUE);

          $indexes = $search_indexes['_embedded']['items'];
          foreach ($indexes as $index) {
            // Check that index exists and has active or progress state.
            if ($index['environment_id'] == $env_uuid && $index['database_role'] == $database_name) {
              $this->messenger->addStatus($this->t('Search index [@index_id] is already exists and @status', [
                '@index_id' => $index['id'],
                '@status' => $index['status'],
              ]));
              break;
            }
            else {
              // Make Sure Connection is setup.
              if ($this->acquiaSearchConnector()) {
                $this->createAcquiaSolrSearchIndex($env_uuid, $database_name);
                drupal_flush_all_caches();
              }
            }
          }
        }
      }
      catch (GuzzleException $guzzleException) {
        $this->loggerFactory->error('@error', ['@error' => $guzzleException->getMessage()]);
        $this->messenger->addError($this->t('Unable to get search index, please check logs for more details.'));
      }
    }
  }

  /**
   * Acquia Search Connector.
   */
  public function acquiaSearchConnector() {
    // Load the server config to fetch the proper cores.
    // Import settings from the connector if it is installed and configured.
    $connector = $this->moduleHandler->moduleExists('acquia_connector');
    $subscription = $this->state->get('acquia_subscription_data');

    if ($connector && !empty($subscription)) {
      $acquia_search_storage = new AcquiaSearch();
      $acquia_connector_storage = new AcquiaConnector();
      $api_host = $this->configFactory->get('acquia_search.settings')->get('api_host');
      $acquia_search_storage->setApiHost($api_host ?? 'https://api.sr-prod02.acquia.com');

      $acquia_search_storage->setApiKey($acquia_connector_storage->getKey());
      $acquia_search_storage->setIdentifier($acquia_connector_storage->getIdentifier());
      $acquia_search_storage->setUuid($subscription['uuid']);

      $servers = $this->entityTypeManager->getStorage('search_api_server')->loadMultiple();
      foreach ($servers as $search_api_server) {
        $search_api_server->save();
      }

      return TRUE;
    }

    return FALSE;
  }

  /**
   * API call to acquia cloud for creating search index.
   *
   * @param string $env_uuid
   *   The environment uuid.
   * @param string $database_name
   *   Name of database.
   */
  private function createAcquiaSolrSearchIndex(string $env_uuid, string $database_name) {
    // Check if config_set_id is proper.
    if (!isset($this->configSetId)) {
      $this->messenger->addError($this->t('Config Set ID for search index is not set in secrets'));
      return FALSE;
    }

    $index_metadata = [
      'config_set_id' => $this->configSetId,
      'database_role' => $database_name,
    ];

    $options = [
      'headers' => [
        'Content-type' => 'application/json',
        'Access-Control-Allow-Origin' => 'https://cloud.acquia.com',
        'Accept' => 'application/json',
      ],
      'body' => json_encode($index_metadata),
    ];

    $uri = 'https://cloud.acquia.com/api/environments/' . $env_uuid . '/search/indexes';
    try {
      $api_token = $this->getApiToken();
      $create_index_request = $this->authProvider->getAuthenticatedRequest('POST', $uri, $api_token, $options);
      $create_index_response = $this->authProvider->getHttpClient()->send($create_index_request);
      if ($create_index_response->getStatusCode() == 202) {
        $search_index = json_decode($create_index_response->getBody()->getContents(), TRUE);
        $this->messenger->addStatus($this->t('@message', ['@message' => $search_index['message']]));
      }
    }
    catch (GuzzleException $guzzleException) {
      $this->loggerFactory->error('@error', ['@error' => $guzzleException->getMessage()]);
      $this->messenger->addError($this->t('Unable to create search index, please check logs for more details.'));
    }
  }

  /**
   * Create batch to monitor status of solr search index.
   *
   * Once index get created successfully,
   * api return status as completed. We can utilize that api
   * to show progress through batch on our end.
   *
   * @param string $notifications
   *   The notification url.
   *
   * @return bool
   *   The index status.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function checkSolrIndexStatus(string $notifications): bool {
    $options = [
      'headers' => [
        'Access-Control-Allow-Origin' => 'https://cloud.acquia.com',
        'Accept' => 'application/json',
      ],
    ];
    try {
      $auth_token = $this->getApiToken();
      $index_status_request = $this->authProvider->getAuthenticatedRequest('GET', $notifications, $auth_token, $options);
      $index_status_response = $this->authProvider->getHttpClient()->send($index_status_request);
      if ($index_status_response->getStatusCode() == 200) {
        $body_content = json_decode($index_status_response->getBody()->getContents(), TRUE);
        if ($body_content['status'] == 'in-progress') {
          return TRUE;
        }
        return FALSE;
      }
    }
    catch (GuzzleException $ge) {
      $this->loggerFactory->error('@error', ['@error' => $ge->getMessage()]);
      $this->messenger->addError($this->t('Unable to get solr index status, please check logs for more details.'));
    }
    return FALSE;
  }

  /**
   * Get auth token.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function getApiToken() {
    $accessToken = $this->authProvider->getAccessToken('client_credentials');
    return $accessToken;
  }

  /**
   * Get auth token.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function setAuthProvider() {
    $this->authProvider = new GenericProvider([
      'clientId'                => $this->clientId,
      'clientSecret'            => $this->clientSecret,
      'urlAuthorize'            => '',
      'urlAccessToken'          => 'https://accounts.acquia.com/api/auth/oauth/token',
      'urlResourceOwnerDetails' => '',
    ]);

    $client = new GuzzleClient();
    $this->authProvider->setHttpClient($client);
  }

  /**
   * Set API credentials.
   */
  public function setApiCredentials($config_id) {
    $search_acsf_config = $this->configFactory->get($config_id);
    if (!empty($search_acsf_config)) {
      $this->clientId = $search_acsf_config->get('clientId');
      $this->clientSecret = $search_acsf_config->get('clientSecret');
      $this->applicationId = $search_acsf_config->get('application_id');
      $this->configSetId = $search_acsf_config->get('config_set_id');

      // Set the Auth Provider.
      $this->setAuthProvider();
      $this->environmentId = $this->getEnvironmentId();
      if (!$this->environmentId) {
        $this->messenger->addError($this->t('The command can be run only if Environment ID is found.'));
        return FALSE;
      }

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Get Environment ID.
   */
  public function getEnvironmentId() {
    $current_env_name = $_SERVER['AH_SITE_ENVIRONMENT'];
    if (!isset($current_env_name)) {
      $this->messenger->addError($this->t('Environment Name not found from acsf site variables.'));
      return FALSE;
    }

    $options = [
      'headers' => [
        'Access-Control-Allow-Origin' => 'https://cloud.acquia.com',
        'Accept' => 'application/json',
      ],
    ];
    $uri = 'https://cloud.acquia.com/api/applications/' . $this->applicationId . '/environments';
    try {
      $api_token = $this->getApiToken();
      $env_request = $this->authProvider->getAuthenticatedRequest('GET', $uri, $api_token, $options);
      $env_response = $this->authProvider->getHttpClient()->send($env_request);
      if ($env_response->getStatusCode() == 200) {
        $body_content = json_decode($env_response->getBody()->getContents(), TRUE);
        // Get all available environments for the application and
        // loop through to get the matching id of current env.
        if (isset($body_content['_embedded']['items'])) {
          $environments = $body_content['_embedded']['items'];
          foreach ($environments as $env) {
            if ($env['name'] == $current_env_name) {
              $env_id = $env['id'];
              break;
            }
          }
        }
        return $env_id ?? FALSE;
      }
    }
    catch (GuzzleException $ge) {
      $this->loggerFactory->error('@error', ['@error' => $ge->getMessage()]);
      $this->messenger->addError($this->t('Unable to get environment ID, please check logs for more details.'));
    }

    return FALSE;
  }

}
