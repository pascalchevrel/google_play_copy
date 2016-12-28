<?php
namespace Stores;

use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * API class
 *
 * This class manages all of our calls to the API.
 *
 * @package Stores
 */
class API
{
    /**
     * URL for the API request
     *
     * @var string
     */
    private $url;

    /**
     * List of parameters in the API request URI
     *
     * @var array
     */
    private $parameters;

    /**
     * List of query (GET) parameters in the API request URI
     *
     * @var array
     */
    private $query_parameters;

    /**
     * Information about supported and current API versions
     *
     * @var array
     */
    private $api_versions = [
        'supported' => ['v1'],
        'current'   => 'v1',
    ];

    /**
     * API version in use
     *
     * @var string
     */
    private $current_api_version = '';

    /**
     * List of available API services
     *
     * @var array
     */
    private $services = [
        'done',
        'firefoxlocales', // Legacy
        'listing',
        'localesmapping',
        'supportedlocales',
        'storelocales',
        'translation',
        'whatsnew',
    ];

    /**
     * Error message generated by the API request
     *
     * @var string
     */
    private $error;

    /**
     * Enable/disable logging
     *
     * @var boolean
     */
    private $logging = true;

    /**
     * Monolog logger object
     *
     * @var object
     */
    private $logger;

    /**
     * Used to store query parameters
     *
     * @var array
     */
    public $query = [];

    /**
     * Used to store query type
     *
     * @var string
     */
    public $query_type;

    /**
     * Project object to get information like supported locales
     *
     * @var object
     */
    private $project;

    /**
     * The constructor analyzes the URL to extract its parameters
     *
     * @param array $url parsed url
     */
    public function __construct($url)
    {
        if (! isset($url['path'])) {
            $url['path'] = '';
        }
        $this->url = $url;

        // We use the Monolog library to log our events
        $this->logger = new Logger('API');
        if ($this->logging) {
            $this->logger->pushHandler(new StreamHandler(INSTALL . 'logs/api-errors.log'));
        }
        // Also log to error console in Debug mode
        if (DEBUG) {
            $this->logger->pushHandler(new ErrorLogHandler());
        }

        $this->project = new Project;

        $this->parameters = $this->getParameters($url['path']);
        $this->query_parameters = isset($url['query'])
            ? $this->getQueryParameters($url['query'])
            : [];

        /*
            Start by analyzing the service, then trace back to the first
            parameter in the URI.
        */
        $service = '';
        if (isset($this->parameters[1])) {
            $service = $this->parameters[1];
            $this->query['service'] = $service;
        }

        $query_type = in_array($service, ['localesmapping', 'storelocales'])
            ? 'store'
            : 'product';
        $this->query_type = $query_type;
        if (isset($this->parameters[0])) {
            if ($query_type == 'store') {
                $this->query['product'] = '';
                $this->query['store'] = $this->parameters[0];
            } else {
                // Make sure to convert legacy product IDs to updated ones
                $this->query['product'] = $this->project->getUpdatedProductCode($this->parameters[0]);
                $this->query['store'] = $this->project->getProductStore($this->query['product']);
            }
        }

        if (isset($this->parameters[2])) {
            $this->query['channel'] = $this->parameters[2];
        }

        if ($service == 'translation' && isset($this->parameters[3])) {
            $this->query['locale'] = $this->parameters[3];
        }
    }

    /**
     * Get the name of the service queried
     *
     * @return string Name of the service
     */
    public function getService()
    {
        return $this->isValidService() ? $this->parameters[1] : 'Invalid service';
    }

    /**
     * Check if an API request is syntactically valid
     *
     * @return boolean True if valid request, false if invalid request
     */
    public function isValidRequest()
    {
        // No parameters passed
        if (! count($this->parameters)) {
            $this->log('No service requested');

            return false;
        }

        // Check that we have enough parameters for a query
        if (! $this->verifyEnoughParameters(1)) {
            return false;
        }

        // Check if the version is supported
        if (! $this->isSupportedAPIVersion()) {
            $this->log('Unsupported API version: ' . $this->current_api_version);

            return false;
        }

        // Check that the product is supported
        if ($this->query_type == 'product') {
            if (! $this->isValidProduct()) {
                return false;
            }
        }

        // Check that the store is supported
        if ($this->query_type == 'store') {
            if (! $this->isValidStore()) {
                return false;
            }
        }

        // Check if the service requested exists
        if (! $this->isValidService()) {
            return false;
        }

        // Check if the call to the service is technically valid
        if (! $this->isValidServiceCall($this->query['service'])) {
            return false;
        }

        return true;
    }

    /**
     * Check if we call a service that we do support and check that
     * the request is technically correct for that service
     *
     * @param string $service The name of the service
     *
     * @return boolean Returns True if we have a valid service call, False otherwise
     */
    private function isValidServiceCall($service)
    {
        $supported_channels = $this->project->getProductChannels($this->query['product']);
        switch ($service) {
            case 'firefoxlocales': // Legacy
                // TODO: remove this log and the service if unused
                $this->log('LEGACY request: /firefoxlocales');
            case 'supportedlocales':
                // {product}/supportedlocales/{channel}/
                if (! $this->verifyEnoughParameters(3)) {
                    return false;
                }

                if (! in_array($this->query['channel'], $supported_channels)) {
                    $this->log("'{$this->query['channel']}' is not a supported channel for {$this->query['product']}.");

                    return false;
                }
                break;
            case 'localesmapping':
                // {product}/localesmapping/{channel}/
                if (! $this->verifyEnoughParameters(2)) {
                    return false;
                }
                break;
            case 'translation':
                // {product}/translation/{channel}/{locale}
                if (! $this->verifyEnoughParameters(4)) {
                    return false;
                }

                if (! in_array($this->query['channel'], $supported_channels)) {
                    $this->log("'{$this->query['channel']}' is not a supported channel for {$this->query['product']}.");

                    return false;
                }

                if (! in_array($this->query['locale'], $this->project->getStoreMozillaCommonLocales($this->query['product'], $this->query['channel']))) {
                    $this->log("'{$this->query['locale']}' is not a supported locale for {$this->query['product']}/{$this->query['channel']}.");

                    return false;
                }
                break;
            case 'storelocales':
                /*
                    api/apple/storelocales/
                    We don't have anything specific to check as there is no parameter
                    for this service call
                */
                break;
            case 'done':
            case 'listing':
            case 'whatsnew':
                /*
                    /api/fx_android/done/beta/
                    /api/fx_android/listing/beta/
                    /api/fx_android/whatsnew/release/
                    We have an extra check for Whatsnew
                */
                if (! $this->verifyEnoughParameters(3)) {
                    return false;
                }

                if (! in_array($this->query['channel'], $supported_channels)) {
                    $this->log("'{$this->query['channel']}' is not a supported channel for {$this->query['product']}.");

                    return false;
                }

                if ($service == 'whatsnew') {
                    if (! $this->project->getLangFiles($this->query['product'], $this->query['channel'], 'whatsnew')) {
                        $this->log("Whatsnew section is not supported for {$this->query['product']} on '{$this->query['channel']}' channel.");

                        return false;
                    }
                }
                break;
            default:
                return false;
        }

        return true;
    }

    /**
     * Return the error message and an http 400 header
     *
     * @return array Error message for an Invalid API call
     */
    public function invalidAPICall()
    {
        http_response_code(400);

        return ['error' => $this->error];
    }

    /**
     * Check that we have enough parameters in the URL to satisfy the request
     *
     * @param int $number number of mandatory parameters
     *
     * @return boolean True if we can satisfy the request, false if we can't
     */
    private function verifyEnoughParameters($number)
    {
        if (count($this->parameters) < $number) {
            $this->log('Not enough parameters for this query.');

            return false;
        }

        return true;
    }

    /**
     * Check if the API version if supported
     *
     * @return boolean True if the version is supported, false otherwise
     */
    public function isSupportedAPIVersion()
    {
        return in_array($this->current_api_version, $this->api_versions['supported']);
    }

    /**
     * Check if the API version if formally valid (v1, v2, etc.)
     *
     * @param string $version API version
     *
     * @return boolean True if the version is valid, false otherwise
     */
    public function isValidAPIVersion($version)
    {
        return preg_match('/^v[0-9]{1,2}$/', $version) == 1
            ? true
            : false;
    }

    /**
     * Get the current API version
     *
     * @return string Current API version
     */
    public function getCurrentAPIVersion()
    {
        return $this->api_versions['current'];
    }

    /**
     * Check if the requested product is supported
     *
     * @return boolean True if the product is supported, false otherwise
     */
    private function isValidProduct()
    {
        if (! in_array($this->query['product'], $this->project->getSupportedProducts())) {
            $this->log("Product ({$this->parameters[0]}) is invalid.");

            return false;
        }

        return true;
    }

    /**
     * Check if the requested store is supported
     *
     * @return boolean True if the store is supported, false otherwise
     */
    private function isValidStore()
    {
        if (! in_array($this->query['store'], $this->project->getSupportedStores())) {
            $this->log("Store ({$this->parameters[0]}) is invalid.");

            return false;
        }

        return true;
    }

    /**
     * Check if the service called is valid
     *
     * @return boolean True if the service called is valid, False otherwise
     */
    private function isValidService()
    {
        if (! $this->verifyEnoughParameters(2)) {
            return false;
        }

        if (! in_array($this->parameters[1], $this->services)) {
            $this->log("The service requested ({$this->parameters[1]}) doesn't exist");

            return false;
        }

        return true;
    }

    /**
     * Utility function to log API call errors.
     *
     * @param string $message
     *
     * @return boolean True if we logged, false if we didn't log
     */
    private function log($message)
    {
        $this->error = $message;

        return $this->logging
            ? $this->logger->addWarning($message, [$this->url['path']])
            : false;
    }

    /**
     * Get the list of parameters for an API call.
     *
     * @param string $parameters The list of parameters from the URI
     *
     * @return array All the main parameters for the query
     */
    private function getParameters($parameters)
    {
        $parameters = explode('/', $parameters);
        // Remove empty values
        $parameters = array_filter($parameters);
        // Remove 'api' as all API calls start with /api
        array_shift($parameters);

        /*
            Store API version separately and remove the parameter.
            To support legacy calls without version we can't assume the
            first parameter after API is a version.
        */
        if (isset($parameters[0]) && $this->isValidAPIVersion($parameters[0])) {
            $this->current_api_version = $parameters[0];
            array_shift($parameters);
        } else {
            /*
                If version is missing, assume it's a legacy call to v1.
            */
            $this->current_api_version = 'v1';
            $this->log('LEGACY request without version. Fall back to v1.');
        }

        // Reorder keys
        $parameters = array_values($parameters);

        return array_map(
            function ($item) {
                return trim(urldecode($item));
            },
            $parameters
        );
    }

    /**
     * Get the list of query parameters for an API call.
     *
     * @param string $parameters The $_GET list of parameters
     *
     * @return array All the extra parameters as [key => value]
     */
    private function getQueryParameters($parameters)
    {
        foreach (explode('&', $parameters) as $item) {
            if (strstr($item, '=')) {
                list($key, $value) = explode('=', $item);
                $extra[$key] = $value;
            } else {
                /* Deal with empty queries such as:
                 query/?foo=
                 query/?foo
                 query/?foo&bar=toto
                */
                $extra[$item] = '';
            }
        }

        return $extra;
    }
}
