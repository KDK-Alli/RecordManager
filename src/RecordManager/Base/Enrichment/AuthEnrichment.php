<?php
/**
 * Enrich biblio records with authority record data.
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2014-2020.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Base\Enrichment;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Database\Factory as DatabaseFactory;
use RecordManager\Base\Record\Factory as RecordFactory;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * Enrich biblio records with authority record data.
 *
 * This is a base class for enrichment from authority record data.
 * Authority records are retrieved from the database.
 * Subclasses need to implement the 'enrich' method
 * (i.e. call enrichField with an URI and name of the Solr field to enrich).
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class AuthEnrichment extends Enrichment
{
    /**
     * Authority database
     *
     * @var Database
     */
    protected $authorityDb;

    /**
     * Constructor
     *
     * @param Database      $db            Database connection (for cache)
     * @param Logger        $logger        Logger
     * @param array         $config        Main configuration
     * @param RecordFactory $recordFactory Record factory
     */
    public function __construct(
        Database $db, Logger $logger, array $config,
        RecordFactory $recordFactory
    ) {
        parent::__construct($db, $logger, $config, $recordFactory);

        // Copy main configuration and modify it with the AuthorityEnrichment
        // settings
        $dbConfig = $config;
        $dbType = $dbConfig['Database']['backend'] ?? 'Mongo';

        $connection = $config['AuthorityEnrichment']['connection']
            ?? $config['AuthorityEnrichment']['url'] ?? '';
        if ($connection) {
            $dbConfig[$dbType]['connection'] = $connection;
        }

        if (!empty($config['AuthorityEnrichment']['database'])) {
            $dbConfig[$dbType]['database']
                = $config['AuthorityEnrichment']['database'];
        }

        try {
            $this->authorityDb = DatabaseFactory::createDatabase($dbConfig);
        } catch (\Exception $e) {
            $this->logger->logFatal(
                'startup',
                'Failed to connect to authority database: ' . $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param array  $solrArray          Metadata to be sent to Solr
     * @param string $id                 Authority record id
     * @param string $solrField          Target Solr field
     * @param bool   $includeInAllfields Whether to include the enriched
     *                                   value also in allFields
     *
     * @return void
     */
    protected function enrichField(&$solrArray, $id, $solrField,
        $includeInAllfields = false
    ) {
        if (!($data = $this->authorityDb->getRecord($id))) {
            return;
        }

        $authRecord = $this->recordFactory->createRecord(
            $data['format'],
            MetadataUtils::getRecordData($data, true),
            $id,
            $data['source_id']
        );

        if ($altNames = $authRecord->getAlternativeNames()) {
            $solrArray[$solrField]
                = array_merge($solrArray[$solrField] ?? [], $altNames);
            if ($includeInAllfields) {
                $solrArray['allfields']
                    = array_merge($solrArray['allfields'], $altNames);
            }
        }
    }
}
