<?php
/**
 * Enrichment Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2014-2019.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Enrichment;

use RecordManager\Base\Database\Database;
use RecordManager\Base\Record\Factory as RecordFactory;
use RecordManager\Base\Utils\Logger;

/**
 * Enrichment Class
 *
 * This is a base class for enrichment of records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Enrichment
{
    /**
     * Database
     *
     * @var Database
     */
    protected $db;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Maximum age of cached data in seconds
     *
     * @var number
     */
    protected $maxCacheAge;

    /**
     * HTTP Request
     *
     * @var \HTTP_Request2
     */
    protected $request = null;

    /**
     * Maximum number of HTTP request attempts
     *
     * @var int
     */
    protected $maxTries;

    /**
     * Delay between HTTP request attempts (seconds)
     *
     * @var int
     */
    protected $retryWait;

    /**
     * HTTP_Request2 configuration params
     *
     * @array
     */
    protected $httpParams = [
        'follow_redirects' => true
    ];

    /**
     * Number of requests handled per host
     *
     * @var array
     */
    protected $requestsHandled = [];

    /**
     * Time all successful requests have taken per host
     *
     * @var array
     */
    protected $requestsDuration = [];

    /**
     * Record factory.
     *
     * @var RecordFactory
     */
    protected $recordFactory;

    /**
     * Constructor
     *
     * @param Database      $db            Database connection (for cache)
     * @param Logger        $logger        Logger
     * @param array         $config        Main configuration
     * @param RecordFactory $recordFactory Record factory
     */
    public function __construct(
        Database $db, Logger $logger, $config
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = $config;

        $this->maxCacheAge = isset($config['Enrichment']['cache_expiration'])
            ? $config['Enrichment']['cache_expiration'] * 60
            : 86400;
        $this->maxTries = isset($config['Enrichment']['max_tries'])
            ? $config['Enrichment']['max_tries']
            : 90;
        $this->retryWait = isset($config['Enrichment']['retry_wait'])
            ? $config['Enrichment']['retry_wait']
            : 5;

        if (isset($config['HTTP'])) {
            $this->httpParams += $config['HTTP'];
        }
    }

    /**
     * Set record factory.
     *
     * @param RecordFactory $factory Record factory
     *
     * @return void
     */
    public function setRecordFactory(RecordFactory $factory)
    {
        $this->recordFactory = $factory;
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId  Source ID
     * @param object $record    Metadata Record
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    public function enrich($sourceId, $record, &$solrArray)
    {
        // Implemented in child classes
    }

    /**
     * A helper function that retrieves external metadata and caches it
     *
     * @param string   $url          URL to fetch
     * @param string   $id           ID of the entity to fetch
     * @param string[] $headers      Optional headers to add to the request
     * @param array    $ignoreErrors Error codes to ignore
     *
     * @return string Metadata (typically XML)
     * @throws Exception
     */
    protected function getExternalData($url, $id, $headers = [], $ignoreErrors = [])
    {
        $cached = $this->db->findUriCache(
            [
                '_id' => $id,
                'timestamp' => [
                    '$gt' => $this->db->getTimestamp(time() - $this->maxCacheAge)
                 ]
            ]
        );
        if (null !== $cached) {
            return $cached['data'];
        }

        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if ($port) {
            $host .= ":$port";
        }
        $retryWait = $this->retryWait;
        $response = null;
        for ($try = 1; $try <= $this->maxTries; $try++) {
            if (is_null($this->request)) {
                $this->request = new \HTTP_Request2(
                    $url,
                    \HTTP_Request2::METHOD_GET,
                    $this->httpParams
                );
                $this->request->setHeader('Connection', 'Keep-Alive');
                $this->request->setHeader('User-Agent', 'RecordManager');
            } else {
                $this->request->setUrl($url);
            }
            if ($headers) {
                $this->request->setHeader($headers);
            }

            $duration = 0;
            try {
                $startTime = microtime(true);
                $response = $this->request->send();
                $duration = microtime(true) - $startTime;
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    if ($retryWait < 30) {
                        // Progressively longer delay
                        $retryWait *= 2;
                    }
                    $this->logger->log(
                        'getExternalData',
                        "HTTP request for '$url' failed (" . $e->getMessage()
                        . "), retrying in {$retryWait} seconds (retry $try)...",
                        Logger::WARNING
                    );
                    $this->request = null;
                    sleep($retryWait);
                    continue;
                }
                throw $e;
            }
            if ($try < $this->maxTries) {
                $code = $response->getStatus();
                if ($code >= 300 && $code != 404 && !in_array($code, $ignoreErrors)
                ) {
                    $this->logger->log(
                        'getExternalData',
                        "HTTP request for '$url' failed ($code), retrying "
                        . "in {$this->retryWait} seconds (retry $try)...",
                        Logger::WARNING
                    );
                    $this->request = null;
                    sleep($this->retryWait);
                    continue;
                }
            }
            if ($try > 1) {
                $this->logger->log(
                    'getExternalData',
                    "HTTP request for '$url' succeeded on attempt $try",
                    Logger::WARNING
                );
            }
            if (isset($this->requestsHandled[$host])) {
                $this->requestsHandled[$host]++;
                $this->requestsDuration[$host] += $duration;
            } else {
                $this->requestsHandled[$host] = 1;
                $this->requestsDuration[$host] = $duration;
            }
            if ($this->requestsHandled[$host] % 1000 === 0) {
                $average = floor(
                    $this->requestsDuration[$host] / $this->requestsHandled[$host]
                    * 1000
                );
                $this->logger->log(
                    'getExternalData',
                    "{$this->requestsHandled[$host]} HTTP requests completed"
                    . " for $host, average time for a request $average ms",
                    Logger::INFO
                );
            }
            break;
        }

        $code = is_null($response) ? 999 : $response->getStatus();
        if ($code >= 300 && $code != 404 && !in_array($code, $ignoreErrors)) {
            throw new \Exception("Enrichment failed to fetch '$url': $code");
        }

        $data = $code < 300 ? $response->getBody() : '';

        try {
            $this->db->saveUriCache(
                [
                    '_id' => $id,
                    'timestamp' => $this->db->getTimestamp(),
                    'url' => $url,
                    'headers' => $headers,
                    'data' => $data
                ]
            );
        } catch (\Exception $e) {
            // Since this can be run in multiple processes, we might encounter
            // duplicate inserts at the same time, so ignore duplicate key errors.
            if (strncmp($e->getMessage(), 'E11000 ', 7) !== 0) {
                throw $e;
            }
        }

        return $data;
    }
}
