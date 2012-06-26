<?php
/**
 * Record Manager
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012.
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
 * @link     http://pear.php.net/package/DB_DataObject/ PEAR Documentation
 */

require_once 'PEAR.php';
require_once 'HTTP/Request2.php';
require_once 'Logger.php';
require_once 'RecordFactory.php';
require_once 'FileSplitter.php';
require_once 'HarvestOaiPmh.php';
require_once 'HarvestMetaLib.php';
require_once 'HarvestSfx.php';
require_once 'XslTransformation.php';
require_once 'MetadataUtils.php';

/**
 * RecordManager Class
 *
 * This is the main class for RecordManager.
 *
 */
class RecordManager
{
    public $verbose = false;
    public $quiet = false;
    public $harvestFromDate = null;
    public $harvestUntilDate = null;

    protected $_basePath = '';
    protected $_log = null;
    protected $_db = null;
    protected $_dataSourceSettings = null;

    protected $_harvestType = '';
    protected $_format = '';
    protected $_idPrefix = '';
    protected $_sourceId = '';
    protected $_institution = '';
    protected $_recordXPath = '';
    protected $_componentParts = '';
    protected $_dedup = false;
    protected $_normalizationXSLT = null;
    protected $_solrTransformationXSLT = null;
    protected $_recordSplitter = null; 
    protected $_pretransformation = '';
    protected $_indexMergedParts = true;

    protected $_uniqIdPrefix = '';
    protected $_uniqIdCounter = 0;
    protected $_counts = false;
    
    /**
     * Constructor
     * 
     * @param boolean $console 	Specify whether RecordManager is executed on the console, 
     * 						   	so log output is also output to the console.
     */
    public function __construct($console = false)
    {
        global $configArray, $mappings;

        $this->_uniqIdPrefix = uniqid();

        date_default_timezone_set($configArray['Site']['timezone']);

        $this->_log = new Logger();
        if ($console) {
            $this->_log->logToConsole = true;
        }
        
        if (isset($configArray['Mongo']['counts']) && $configArray['Mongo']['counts']) {
            $this->_counts = true;
        }

        $basePath = substr(__FILE__, 0, strrpos(__FILE__, DIRECTORY_SEPARATOR));
        $basePath = substr($basePath, 0, strrpos($basePath, DIRECTORY_SEPARATOR));
        $this->_dataSourceSettings = parse_ini_file("$basePath/conf/datasources.ini", true);
        
        // Read in mapping tables
        if ($handle = opendir("$basePath/mappings")) {
          while (false !== ($entry = readdir($handle))) {
              if ($entry != "." && $entry != "..") {
                $table = basename($entry, ".ini");
                $mappings[$table] = parse_ini_file("$basePath/mappings/$entry", true);
              }
            }
            closedir($handle);
        }
         
        $this->_basePath = $basePath;

        $mongo = new Mongo($configArray['Mongo']['url']);
        $this->_db = $mongo->selectDB($configArray['Mongo']['database']);
        MongoCursor::$timeout = isset($configArray['Mongo']['cursor_timeout']) ? $configArray['Mongo']['cursor_timeout'] : 300000;
    }

    /**
     * Load records into the database from a file
     * 
     * @param string $source  	Source id
     * @param string $file    	File name containing the records
     * @throws Exception
     * @return int   			Number of records loaded
     */
    public function loadFromFile($source, $file)
    {
        $this->_log->log('loadFromFile', "Loading records from '$file' into '$source'");
        $this->_loadSourceSettings($source);
        if (!$this->_recordXPath) {
            $this->_log->log('loadFromFile', 'recordXPath not defined', Logger::FATAL);
            throw new Exception('recordXPath not defined');
        }
        $data = file_get_contents($file);
        if ($data === false) {
            throw new Exception("Could not read file '$file'");
        }
        
        if ($this->_pretransformation) {
            if ($this->verbose) {
                echo "Executing pretransformation...\n";
            }
            $data = $this->_pretransform($data);
        }
        
        if ($this->verbose) {
            echo "Creating FileSplitter...\n";
        }
        $splitter = new FileSplitter($data, $this->_recordXPath);
        $count = 0;
        
        if ($this->verbose) {
            echo "Storing records...\n";
        }
        while (!$splitter->getEOF())
        {
            $data = $splitter->getNextRecord();
            if ($this->verbose) {
                echo "Storing a record...\n";
            }
            $count += $this->storeRecord('', false, $data);
            if ($this->verbose) {
                echo "Stored records: $count...\n";
            }
        }
        
        $this->_log->log('loadFromFile', "$count records loaded");
        return $count;
    }

    /**
     * Export records from the database to a file
     * 
     * @param string $file			File name where to write exported records
     * @param string $deletedFile	File name where to write ID's of deleted records
     * @param string $fromDate		Starting date (e.g. 2011-12-24)
     * @param int    $skipRecords   Export only one per each $skipRecords records for a sample set
     * @param string $sourceId      Source ID to export, or empty or * for all
     * @param string $singleId		Export only a record with the given ID
     */
    public function exportRecords($file, $deletedFile, $fromDate, $skipRecords = 0, $sourceId = '', $singleId = '')
    {
        if ($file == '-') {
            $file = 'php://stdout';
        }

        if (file_exists($file)) {
            unlink($file);
        }
        if (file_exists($deletedFile)) {
            unlink($deletedFile);
        }
        file_put_contents($file, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n<collection>\n", FILE_APPEND);

        foreach ($this->_dataSourceSettings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings)) {
                    continue;
                }

                $this->_loadSourceSettings($source);

                $this->_log->log('exportRecords', "Creating record list (from " . ($fromDate ? $fromDate : 'the beginning') . ", source $source)...");

                $params = array();
                if ($singleId) {
                    $params['_id'] = $singleId;
                    $params['source_id'] = $source;
                } else {
                    $params['source_id'] = $source;
                    if ($fromDate) {
                        $params['updated'] = array('$gte' => new MongoDate(strtotime($fromDate)));
                    }
                    $params['update_needed'] = false;
                }
                $records = $this->_db->record->find($params);
                $total = $this->_counts ? $records->count() : 'the';
                $count = 0;
                $deduped = 0;
                $deleted = 0;
                $this->_log->log('exportRecords', "Exporting $total records from $source...");
                if ($skipRecords) {
                	$this->_log->log('exportRecords', "(1 per each $skipRecords records)");
                }
                foreach ($records as $record) {
                    if ($record['deleted']) {
                        file_put_contents($deletedFile, "{$record['_id']}\n", FILE_APPEND);
                        ++$deleted;
                    } else {
                        ++$count;
                        if ($skipRecords > 0 && $count % $skipRecords != 0) {
                            continue;
                        }
                        $metadataRecord = RecordFactory::createRecord($record['format'], $this->_getRecordData($record, true), $record['oai_id']);
                        if (isset($record['dedup_key']) && $record['dedup_key']) {
                            ++$deduped;
                        }
                        $metadataRecord->setIDPrefix($this->_idPrefix . '.');
                        $metadataRecord->addDedupKeyToMetadata((isset($record['dedup_key']) && $record['dedup_key']) ? $record['dedup_key'] : $record['_id']);
                        file_put_contents($file, $metadataRecord->toXML() . "\n", FILE_APPEND);
                    }
                    if ($count % 1000 == 0) {
                        $this->_log->log('exportRecords', "$deleted deleted, $count normal (of which $deduped deduped) records exported from $source");
                    }
                }
                $this->_log->log('exportRecords', "Completed with $deleted deleted, $count normal (of which $deduped deduped) records exported from $source");
            } catch (Exception $e) {
                $this->_log->log('exportRecords', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
        file_put_contents($file, "</collection>\n", FILE_APPEND);
    }

    /**
     * Send updates to a Solr index (e.g. VuFind)
     * 
     * @param string|null  	$fromDate Starting date for updates (if empty string, last update date stored in the 
     * 						database is used and if null, all records are processed)
     * @param string		$sourceId Source ID to update, or empty or * for all sources
     * @param string 		$singleId Export only a record with the given ID
     * @param bool  		$noCommit If true, changes are not explicitly committed
     */
    public function updateSolrIndex($fromDate = null, $sourceId = '', $singleId = '', $noCommit = false)
    {
        global $configArray;
        
        $commitInterval = isset($configArray['Solr']['max_commit_interval']) ? $configArray['Solr']['max_commit_interval'] : 50000;
        $maxUpdateRecords = isset($configArray['Solr']['max_update_records']) ? $configArray['Solr']['max_update_records'] : 5000;
        $maxUpdateSize = isset($configArray['Solr']['max_update_size']) ? $configArray['Solr']['max_update_size'] : 1024;
        $maxUpdateSize *= 1024;
        
        if (isset($fromDate) && $fromDate) {
            $mongoFromDate = new MongoDate(strtotime($fromDate));
        }
        
        foreach ($this->_dataSourceSettings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings)) {
                    continue;
                }

                $this->_loadSourceSettings($source);
                	
                if (!isset($fromDate)) {
                    $state = $this->_db->state->findOne(array('_id' => "Last Index Update $source"));
                    if (isset($state)) {
                        $mongoFromDate = $state['value'];
                    } else {
                        unset($mongoFromDate);
                    }
                }
                $from = isset($mongoFromDate) ? date('Y-m-d H:i:s', $mongoFromDate->sec) : 'the beginning';
                $this->_log->log('updateSolrIndex', "Creating record list (from $from), source $source)...");
                // Take the last indexing date now and store it when done
                $lastIndexingDate = new MongoDate();
                $params = array();
                if ($singleId) {
                    $params['_id'] = $singleId;
                    $params['source_id'] = $source;
                    $lastIndexingDate = null;
                } else {
                    $params['source_id'] = $source;
                    if (isset($mongoFromDate)) {
                        $params['updated'] = array('$gte' => $mongoFromDate);
                    }
                    $params['update_needed'] = false;
                }
                $records = $this->_db->record->find($params);
                $records->immortal(true);

                // Special case: building hierarchy
                $buildingHierarchy = isset($configArray['Solr']['hierarchical_facets']) 
                    && in_array('building', $configArray['Solr']['hierarchical_facets']);
                
                // Load mapping files
                $mappingFiles = array();
                foreach ($settings as $key => $value) {
                    if (substr($key, -8, 8) == '_mapping') {
                        $field = substr($key, 0, -8);
                        $mappingFiles[$field] = parse_ini_file($this->_basePath . '/mappings/' . $value);
                    }
                }
                
                $total = $this->_counts ? $records->count() : 'the';
                $count = 0;
                $deduped = 0;
                $mergedComponents = 0;
                $deleted = 0;
                $buffer = '';
                $bufferLen = 0;
                $buffered = 0;
                $delList = array();;
                if ($noCommit) {
                    $this->_log->log('updateSolrIndex', "Indexing $total records (with no forced commits) from $source...");
                } else {
                    $this->_log->log('updateSolrIndex', "Indexing $total records (max commit interval $commitInterval records) from $source...");
                }
                $starttime = microtime(true);
                foreach ($records as $record) {
                    if ($record['deleted']) {
                        $this->_solrRequest(json_encode(array('delete' => array('id' => $record['_id']))));
                        ++$deleted;
                    } else {
                        $metadataRecord = RecordFactory::createRecord($record['format'], $this->_getRecordData($record, true), $record['oai_id']);

                        $hiddenComponent = false;
                        if ($record['host_record_id']) {
                            if ($this->_componentParts == 'merge_all') {
                                $hiddenComponent = true;
                            } elseif ($this->_componentParts == 'merge_non_articles' || $this->_componentParts == 'merge_non_earticles') {
                                $format = $metadataRecord->getFormat();
                                if ($format != 'eJournalArticle' && $format != 'JournalArticle') {
                                    $hiddenComponent = true;
                                } elseif ($format == 'JournalArticle' && $this->_componentParts == 'merge_non_earticles') {
                                    $hiddenComponent = true;
                                }
                            }
                        }

                        if ($hiddenComponent && !$this->_indexMergedParts) {
                            continue;
                        }
                        
                        $hasComponentParts = false;
                        $components = null;
                        if (!$record['host_record_id'] && $this->_componentParts != 'as_is') {
                            // Fetch all component parts for merging
                            $components = $this->_db->record->find(array('host_record_id' => $record['_id'], 'deleted' => false));
                            $hasComponentParts = $components->hasNext();
                            $format = $metadataRecord->getFormat();
                            $merge = false;
                            if ($this->_componentParts == 'merge_all') {
                                $merge = true;
                            } elseif ($format != 'eJournal' && $format != 'Journal' && $format != 'Serial') {
                                $merge = true;
                            } elseif (($format == 'Journal' || $format == 'Serial') && $this->_componentParts == 'merge_non_earticles') {
                                $merge = true;
                            }
                            if (!$merge) {
                                unset($components);
                            }
                        }
                        
                        $metadataRecord->setIDPrefix($this->_idPrefix . '.');
                        if (isset($components)) {
                            $mergedComponents += $metadataRecord->mergeComponentParts($components);
                        }
                        if (isset($this->_solrTransformationXSLT)) {
                            $data = $this->_solrTransformationXSLT->transformToSolrArray($metadataRecord->toXML());
                        } else {
                            $data = $metadataRecord->toSolrArray();
                        }

                        $data['id'] = $record['_id'];
                        
                        // Record links between host records and component parts
                        if ($metadataRecord->getIsComponentPart()) {
                            $hostRecord = null;
                            if ($record['host_record_id']) {
                                $hostRecord = $this->_db->record->findOne(array('_id' => $record['host_record_id']));
                                $data['hierarchy_parent_id'] = $record['host_record_id'];
                            }
                            if (!$hostRecord) {
                                $this->_log->log('updateSolrIndex', 'Host record ' . $record['host_record_id'] . ' not found for record ' . $record['_id'], Logger::WARNING);
                                $data['container_title'] = $metadataRecord->getContainerTitle();
                            } else {
                                $hostMetadataRecord = RecordFactory::createRecord($hostRecord['format'], $this->_getRecordData($hostRecord, true), $hostRecord['oai_id']);
                                $data['container_title'] = $data['hierarchy_parent_title'] = $hostMetadataRecord->getTitle();
                            }
                            $data['container_volume'] = $metadataRecord->getVolume();
                            $data['container_issue'] = $metadataRecord->getIssue();
                            $data['container_start_page'] = $metadataRecord->getStartPage();
                            $data['container_reference'] = $metadataRecord->getContainerReference();
                        }
                        if ($hasComponentParts) {
                            $data['is_hierarchy_id'] = $record['_id'];
                            $data['is_hierarchy_title'] = $metadataRecord->getTitle();
                        }
                        
                        if (!isset($data['institution'])) {
                            $data['institution'] = $this->_institution;
                        }
                        if (!isset($data['collection'])) {
                            $data['collection'] = $record['source_id'];
                        }

                        // Map field values according to any mapping files
                        foreach ($mappingFiles as $field => $map) {
                            if (isset($data[$field])) {
                                if (is_array($data[$field])) {
                                    foreach ($data[$field] as &$value) {
                                        if (isset($map[$value])) {
                                            $value = $map[$value];
                                        }
                                    }
                                    $data[$field] = array_unique($data[$field]);
                                } else {
                                    if (isset($map[$data[$field]])) {
                                        $data[$field] = $map[$data[$field]];
                                    }
                                }
                            }
                        }
                        
                        // Special case: Hierarchical facet support for building (institution/location)
                        if ($buildingHierarchy) {
                            if (isset($data['building']) && $data['building']) {
                                $building = array('0/' . $this->_institution);
                                foreach ($data['building'] as $datavalue) {
                                    $values = explode('/', $datavalue);
                                    $hierarchyString = $this->_institution;
                                    for ($i = 0; $i < count($values); $i++) {
                                        $hierarchyString .= '/' . $values[$i];
                                        $building[] = ($i + 1) . "/$hierarchyString";
                                    }
                                }
                                $data['building'] = $building;
                            } else {
                                $data['building'] = array(
                                    '0/' . $this->_institution
                                );
                            }
                        }
                        
                        if (!isset($data['allfields'])) {
                            $all = array();
                            foreach ($data as $key => $field) {
                                if (in_array($key, array('fullrecord', 'thumbnail', 'id', 'recordtype'))) {
                                    continue;
                                }
                                if (is_array($field)) {
                                    $all[] = implode(' ', $field);
                                } else {
                                    $all[] = $field;
                                }
                            }
                            $data['allfields'] = implode(' ', MetadataUtils::array_iunique($all));
                        }
                        
                        $data['dedup_key'] = isset($record['dedup_key']) && $record['dedup_key'] ? $record['dedup_key'] : $record['_id'];
                        $data['first_indexed'] = $this->_formatTimestamp($record['created']->sec);
                        $data['last_indexed'] = $this->_formatTimestamp($record['updated']->sec);
                        $data['recordtype'] = $record['format'];
                        if (!isset($data['fullrecord'])) {
                            $data['fullrecord'] = $metadataRecord->toXML();
                        }

                        if ($hiddenComponent) {
                            $data['hidden_component_boolean'] = true;
                        }

                        foreach ($data as $key => $value) {
                            // Checking only for empty() won't work as 0 is empty too
                            if (empty($value) && $value !== 0 && $value !== 0.0 && $value !== '0') {
                                unset($data[$key]);
                            }
                        }

                        if ($this->verbose) {
                            echo "Metadata for record {$record['_id']}: \n";
                            print_r($data);
                            echo "JSON for record {$record['_id']}: \n" . json_encode($data) . "\n";
                        }
                        
                        $jsonData = json_encode($data);
                        if ($buffered > 0) {
                            $buffer .= ",\n";
                        }
                        $buffer .= $jsonData;
                        $bufferLen += strlen($jsonData);
                        ++$count;
                        if (++$buffered >= $maxUpdateRecords || $bufferLen > $maxUpdateSize) {
                            $this->_solrRequest("[\n$buffer\n]");
                            $avg = round($buffered / (microtime(true) - $starttime));
                            $buffer = '';
                            $bufferLen = 0;
                            $buffered = 0;
                            $this->_log->log('updateSolrIndex', "$count records (of which $deleted deleted) with $mergedComponents merged parts indexed from $source, $avg records/sec");
                            $starttime = microtime(true);
                        }
                        if (!$noCommit && $count % $commitInterval == 0) {
                            $this->_log->log('updateSolrIndex', "Intermediate commit...");
                            $this->_solrRequest('{ "commit": {} }');
                        }
                    }
                }
                if ($buffered > 0) {
                    $this->_solrRequest("[\n$buffer\n]");
                }
                if (!empty($delList)) {
                    $this->_solrRequest(json_encode(array('delete' => $delList)));
                }

                if (isset($lastIndexingDate)) {
                    $state = array('_id' => "Last Index Update $source", 'value' => $lastIndexingDate);
                    $this->_db->state->save($state);
                }

                $this->_log->log('updateSolrIndex', "Completed with $count records (of which $deleted deleted) with $mergedComponents merged parts indexed from $source");
            } catch (Exception $e) {
                $this->_log->log('updateSolrIndex', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
        if (!$noCommit) {
            $this->_log->log('updateSolrIndex', "Final commit...");
            $this->_solrRequest('{ "commit": {} }');
            $this->_log->log('updateSolrIndex', "Commit complete");
        }
    }

    /**
     * Renormaliza records in a data source
     *
     * @param string $sourceId	Source ID to renormalize
     * @param string $singleId	Renormalize only a single record with the given ID
     */
    public function renormalize($sourceId, $singleId)
    {
        foreach ($this->_dataSourceSettings as $source => $settings) {
            if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                continue;
            }
            if (empty($source) || empty($settings)) {
                continue;
            }
            $this->_loadSourceSettings($source);
            $this->_log->log('renormalize', "Creating record list for '$source'...");
    
            $params = array('deleted' => false);
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['source_id'] = $source;
            } else {
                $params['source_id'] = $source;
            }
            $records = $this->_db->record->find($params);
            $total = $this->_counts ? $records->count() : 'the';
            $count = 0;
    
            $this->_log->log('renormalize', "Processing $total records from '$source'...");
            $starttime = microtime(true);
            foreach ($records as $record) {
                $originalData = $this->_getRecordData($record, false);
                $normalizedData = $originalData;
                if (isset($this->_normalizationXSLT)) {
                    $origMetadataRecord = RecordFactory::createRecord($record['format'], $originalData, $record['oai_id']);
                    $normalizedData = $this->_normalizationXSLT->transform($origMetadataRecord->toXML(), array('oai_id' => $record['oai_id']));
                }
    
                $metadataRecord = RecordFactory::createRecord($record['format'], $normalizedData, $record['oai_id']);
                $hostID = $metadataRecord->getHostRecordID();
                if ($hostID) {
                    $hostID = $this->_idPrefix . '.' . $hostID;
                }
                $normalizedData = $metadataRecord->serialize();
                if ($this->_dedup && !$hostID) {
                    $this->_updateDedupCandidateKeys($record, $metadataRecord);
                    $record['update_needed'] = true;
                } else {
                    $record['title_keys'] = null;                
                    $record['isbn_keys'] = null;                
                    $record['update_needed'] = false;
                }

                if ($normalizedData == $originalData) {
                    $record['normalized_data'] = '';
                } else {
                    $record['normalized_data'] = new MongoBinData(gzdeflate($normalizedData));
                }
                $record['host_record_id'] = $hostID;
                $record['dedup_key'] = '';
                $record['updated'] = new MongoDate();
                $this->_db->record->save($record);
                
                if ($this->verbose) {
                    echo "Metadata for record {$record['_id']}: \n";
                    $record['normalized_data'] = $this->_getRecordData($record, false);
                    $record['original_data'] = $this->_getRecordData($record, true);
                    if ($record['normalized_data'] === $record['original_data']) {
                        $record['normalized_data'] = '';
                    }
                    print_r($record);
                }
                                
                ++$count;
                if ($count % 1000 == 0) {
                    $avg = round(1000 / (microtime(true) - $starttime));
                    $this->_log->log('renormalize', "$count records processed from '$source', $avg records/sec");
                    $starttime = microtime(true);
                }
            }
            $this->_log->log('renormalize', "Completed with $count records processed from '$source'");
        }
    }

    /**
     * Find duplicate records and give them dedup keys
     * 
     * @param string $sourceId		Source ID to process, or empty or * for all sources where dedup is enabled
     * @param string $allRecords    If true, process all records regardless of their status (otherwise only freshly imported or updated records are processed)
     * @param string $singleId		Process only a record with the given ID
     */
    public function deduplicate($sourceId, $allRecords = false, $singleId = '')
    {
        foreach ($this->_dataSourceSettings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings) || !isset($settings['dedup']) || !$settings['dedup']) {
                    continue;
                }

                $this->_loadSourceSettings($source);
                $this->_log->log('deduplicate', "Creating record list for '$source'" . ($allRecords ? ' (all records)' : '') . '...');

                $params = array('deleted' => false, 'host_record_id' => '');
                if ($singleId) {
                    $params['_id'] = $singleId;
                    $params['source_id'] = $source;
                } else {
                    $params['source_id'] = $source;
                    if (!$allRecords) {
                        $params['update_needed'] = true;
                    }
                }
                $records = $this->_db->record->find($params);
                $total = $this->_counts ? $records->count() : 'the';
                $count = 0;
                $deduped = 0;
                $starttime = microtime(true);
                $this->_tooManyCandidatesKeys = array();
                $this->_log->log('deduplicate', "Processing $total records for '$source'...");
                foreach ($records as $record) {
                    $startRecordTime = microtime(true);
                    if ($this->_dedupRecord($record)) {
                        if ($this->verbose) {
                            echo '+';
                        }
                        ++$deduped;
                    } else {
                        if ($this->verbose)
                        echo '.';
                    }
                    if (microtime(true) - $startRecordTime > 0.7) {
                        if ($this->verbose) {
                            echo "\n";
                        }
                        $this->_log->log('deduplicate', "Candidate search for " . $record['_id'] . " took " . (microtime(true) - $startRecordTime));
                    }
                    ++$count;
                    if ($count % 1000 == 0) {
                        $avg = round(1000 / (microtime(true) - $starttime));
                        if ($this->verbose) {
                            echo "\n";
                        }
                        $this->_log->log('deduplicate', "$count records processed for '$source', $deduped deduplicated, $avg records/sec");
                        $starttime = microtime(true);
                    }
                }
                $this->_log->log('deduplicate', "Completed with $count records processed for '$source'");
            } catch (Exception $e) {
                $this->_log->log('deduplicate', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
    }

    /**
     * Harvest records from a data source
     * 
     * @param string $repository			Source ID to harvest
     * @param string $harvestFromDate		Override start date (otherwise harvesting is done from the previous harvest date)
     * @param string $harvestUntilDate		Override end date (otherwise current date is used)
     * @param string $startResumptionToken	Override OAI-PMH resumptionToken to resume interrupted harvesting process (note 
     * 										that tokens may have a limited lifetime)  
     */
    public function harvest($repository = '', $harvestFromDate = null, $harvestUntilDate = null, $startResumptionToken = '')
    {
        global $configArray;

        if (empty($this->_dataSourceSettings)) {
            $this->_log->log('harvest', "Please add data source settings to datasources.ini", Logger::FATAL);
            throw new Exception("Data source settings missing in datasources.ini");
        }

        // Loop through all the sources and perform harvests
        foreach ($this->_dataSourceSettings as $source => $settings) {
            try {
                if ($repository && $repository != '*' && $source != $repository) {
                    continue;
                }
                if (empty($source) || empty($settings) || !isset($settings['url'])) {
                    continue;
                }
                $this->_log->log('harvest', "Harvesting from {$source}...");

                $this->_loadSourceSettings($source);

                if ($this->verbose) {
                    $settings['verbose'] = true;
                }
                
                if ($this->_harvestType == 'metalib') {
                    // MetaLib doesn't handle deleted records, so we'll just fetch everything and compare with what we have
                    $this->_log->log('harvest', "Fetching records from MetaLib...");
                    $harvest = new HarvestMetaLib($this->_log, $this->_db, $source, $this->_basePath, $settings);
                    $harvestedRecords = $harvest->launch();

                    $this->_log->log('harvest', "Processing MetaLib records...");
                    // Create keyed array
                    $records = array();
                    foreach ($harvestedRecords as $record) {
                        $marc = RecordFactory::createRecord('marc', $record, '');
                        $id = $marc->getID();
                        $records["$source.$id"] = $record;
                    }
                    
                    $this->_log->log('harvest', "Merging results with the records in database...");
                    $deleted = 0;
                    $unchanged = 0;
                    $changed = 0;
                    $added = 0; 
                    $dbRecords = $this->_db->record->find(array('deleted' => false, 'source_id' => $source));
                    foreach ($dbRecords as $dbRecord) {
                        $id = $dbRecord['_id'];
                        if (!isset($records[$id])) {
                            // Record not in harvested records, mark deleted
                            $this->storeRecord($id, true, '');
                            unset($records[$id]);
                            ++$deleted;
                            continue;
                        }
                        // Check if the record has changed
                        $dbMarc = RecordFactory::createRecord('marc', $this->_getRecordData($dbRecord, false), '');
                        $marc = RecordFactory::createRecord('marc', $records[$id], '');
                        if ($marc->serialize() != $this->_getRecordData($dbRecord, false)) {
                            // Record changed, update...
                            $this->storeRecord($id, false, $records[$id]);
                            ++$changed;
                        } else {
                            ++$unchanged;
                        }
                        unset($records[$id]);
                    }
                    $this->_log->log('harvest', "Adding new records...");
                    foreach ($records as $id => $record) {
                        $this->storeRecord($id, false, $record);
                        ++$added;
                    }
                    $this->_log->log('harvest', "$added new, $changed changed, $unchanged unchanged and $deleted deleted records processed");
                } elseif ($this->_harvestType == 'sfx') {
                    $harvest = new HarvestSfx($this->_log, $this->_db, $source, $this->_basePath, $settings);
                    if (isset($harvestFromDate)) {
                        $harvest->setStartDate($harvestFromDate);
                    }
                    if (isset($harvestUntilDate)) {
                        $harvest->setEndDate($harvestUntilDate);
                    }
                    $harvest->launch(array($this, 'storeRecord'));
                } else {
                    $harvest = new HarvestOAIPMH($this->_log, $this->_db, $source, $this->_basePath, $settings, $startResumptionToken);
                    if (isset($harvestFromDate)) {
                        $harvest->setStartDate($harvestFromDate);
                    }
                    if (isset($harvestUntilDate)) {
                        $harvest->setEndDate($harvestUntilDate);
                    }
                    $harvest->harvest(array($this, 'storeRecord'));
                    if (isset($settings['deletions']) && $settings['deletions'] == 'ListIdentifiers') {
                        // The repository doesn't support reporting deletions, so list all identifiers
                        // and mark deleted records that were not found
                        $this->_log->log('harvest', "Processing deletions");
                        
                        $this->_log->log('harvest', "Unmarking records");
                        $this->_db->record->update(
                            array('source_id' => $this->_sourceId, 'deleted' => false),
                            array('$unset' => array('mark' => 1)),
                            array('multiple' => true)
                        );

                        $this->_log->log('harvest', "Fetching identifiers");
                        $harvest->listIdentifiers(array($this, 'markRecord'));
                        
                        $this->_log->log('harvest', "Marking deleted records");
                        $result = $this->_db->record->update(
                            array('source_id' => $this->_sourceId, 'deleted' => false, 'mark' => array('$exists' => false)),
                            array('$set' => array('deleted' => true, 'updated' => new MongoDate())),
                            array('safe' => true, 'timeout' => 3000000, 'multiple' => true)
                        );
                        $this->_log->log('harvest', $result['n'] . " deleted records");
                    }
                }
                $this->_log->log('harvest', "Harvesting from {$source} completed");
            } catch (Exception $e) {
                $this->_log->log('harvest', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
    }

    /**
     * Dump a single record to console
     * 
     * @param string $recordID 	ID of the record to be dumped
     */
    public function dumpRecord($recordID)
    {
        if (!$recordID) {
            throw new Exception('dump: record id must be specified');
        }
        $records = $this->_db->record->find(array('_id' => $recordID));
        foreach ($records as $record) {
            $record['original_data'] = $this->_getRecordData($record, false);
            $record['normalized_data'] = $this->_getRecordData($record, true);
            if ($record['original_data'] == $record['normalized_data']) {
                $record['normalized_data'] = '';
            }
            print_r($record);
        }
    }
    
    /**
     * Delete records of a single data source from the Mongo database
     * @param string $sourceId
     */
    public function deleteRecords($sourceId)
    {
        $params = array();
        $params['source_id'] = $sourceId;
        $this->_log->log('deleteRecords', "Deleting records from data source $sourceId...");
        $this->_db->record->remove($params, array('safe' => true, 'timeout' => 3000000));
        $this->_log->log('deleteRecords', "Deleting last harvest date from data source $sourceId...");
        $this->_db->state->remove(array('_id' => "Last Harvest Date $sourceId"), array('safe' => true));
        $this->_log->log('deleteRecords', "Deletion of $sourceId completed");
    }

    /**
     * Delete records of a single data source from the Solr index
     * @param string $sourceId
     */
    public function deleteSolrRecords($sourceId)
    {
        $this->_log->log('deleteSolrRecords', "Deleting data source $sourceId from Solr...");
        $this->_solrRequest('{ "delete": { "query": "id:' . $sourceId . '.*" } }');
        $this->_log->log('deleteSolrRecords', "Committing changes...");
        $this->_solrRequest('{ "commit": {} }');
        $this->_log->log('deleteSolrRecords', "Deletion of $sourceId from Solr completed");
    }
    
    /**
     * Optimize the Solr index
     */
    public function optimizeSolr()
    {
        $this->_log->log('optimizeSolr', 'Optimizing Solr index');
        $this->_solrRequest('{ "optimize": {} }');
        $this->_log->log('optimizeSolr', 'Solr optimization completed');
    }
    
    /**
     * Save a record into the database. Used by e.g. OAI-PMH harvesting.
     * 
     * @param string $oaiID			ID of the record as received from OAI-PMH
     * @param bool   $deleted		Whether the record is to be deleted
     * @param string $recordData	Record metadata
     * @throws Exception
     * @return number				Number of records processed (can be > 1 for split records)
     */
    public function storeRecord($oaiID, $deleted, $recordData)
    {
        if ($deleted) {
            // A single OAI-PMH record may have been split to multiple records
            $records = $this->_db->record->find(array('oai_id' => $oaiID));
            $count = 0;
            foreach ($records as $record) {
                $record['deleted'] = true;
                $record['updated'] = new MongoDate();
                $this->_db->record->save($record);
                ++$count;
            }
            return $count;
        }

        $dataArray = Array();
        if ($this->_recordSplitter) {
            if ($this->verbose) {
                echo "Splitting records...\n";
            }
            if (is_string($this->_recordSplitter)) {
                require_once $this->_recordSplitter;
                $className = substr($this->_recordSplitter, 0, -4);
                $splitter = new $className($recordData);
                while (!$splitter->getEOF()) {
                    $dataArray[] = $splitter->getNextRecord();
                }
            } else {
                $doc = new DOMDocument();
                $doc->loadXML($recordData);
                if ($this->verbose) {
                    echo "XML Doc Created...\n";
                }
                $transformedDoc = $this->_recordSplitter->transformToDoc($doc);
                if ($this->verbose) {
                    echo "XML Transformation Done...\n";
                }
                $records = simplexml_import_dom($transformedDoc);
                if ($this->verbose) {
                    echo "Creating record array...\n";
                }
                foreach ($records as $record) {
                    $dataArray[] = $record->saveXML();
                }
            }
        } else {
            $dataArray = array($recordData);
        }

        if ($this->verbose) {
            echo "Storing array of " . count($dataArray) . " records...\n";
        }
                
        $count = 0;
        foreach ($dataArray as $data) {
            if (isset($this->_normalizationXSLT)) {
                $metadataRecord = RecordFactory::createRecord($this->_format, $this->_normalizationXSLT->transform($data, array('oai_id' => $oaiID)), $oaiID);
                $normalizedData = $metadataRecord->serialize();
                $originalData = RecordFactory::createRecord($this->_format, $data, $oaiID)->serialize();
            }
            else {
                $metadataRecord = RecordFactory::createRecord($this->_format, $data, $oaiID);
                $originalData = $metadataRecord->serialize();
                $normalizedData = $originalData;
            }
    
            $hostID = $metadataRecord->getHostRecordID();
            if ($hostID) {
                $hostID = $this->_idPrefix . '.' . $hostID;
            }
            $id = $metadataRecord->getID();
            if (!$id) {
                throw new Exception("Empty ID returned for record $oaiID");
            }
            $id = $this->_idPrefix . '.' . $id;
            $dbRecord = $this->_db->record->findOne(array('_id' => $id));
            if ($dbRecord) {
                if (!isset($dbRecord['created'])) {
                    $dbRecord['created'] = $dbRecord['updated'] = new MongoDate();
                } else {
                    $dbRecord['updated'] = new MongoDate();
                }
            } else {
                $dbRecord = array();
                $dbRecord['_id'] = $id;
                $dbRecord['created'] = $dbRecord['updated'] = new MongoDate();
            }
            if ($normalizedData) {
                if ($data == $normalizedData) {
                    $normalizedData = '';
                };
            }
            $originalData = gzdeflate($originalData);
            if ($normalizedData) {
                $normalizedData = gzdeflate($normalizedData);
            }
            $dbRecord['source_id'] = $this->_sourceId;
            $dbRecord['oai_id'] = $oaiID;
            $dbRecord['deleted'] = false;
            $dbRecord['host_record_id'] = $hostID;
            $dbRecord['format'] = $this->_format;
            $dbRecord['original_data'] = new MongoBinData($originalData);
            $dbRecord['normalized_data'] = $normalizedData ? new MongoBinData($normalizedData) : '';
            if ($this->_dedup && !$hostID) {
                $this->_updateDedupCandidateKeys($dbRecord, $metadataRecord);
                $dbRecord['update_needed'] = true;
            } else {
                $record['title_keys'] = null;                
                $record['isbn_keys'] = null;                
                $dbRecord['update_needed'] = false;
            }
            $this->_db->record->save($dbRecord);
            ++$count;
        }
        return $count;
    }

    /**
     * Mark a record "seen". Used by OAI-PMH harvesting when deletions are not supported.
     *
     * @param string $oaiID			ID of the record as received from OAI-PMH
     * @param bool   $deleted		Whether the record is to be deleted
    
     * @throws Exception
     */
    public function markRecord($oaiID, $deleted)
    {
        if ($deleted) {
            // Don't mark deleted records...
            return;
        }
        $this->_db->record->update(
            array('oai_id' => $oaiID),
            array('$set' => array('mark' => true)),
            array('multiple' => true)
        );
    }
    
    /**
     * Update dedup candidate keys for the given record
     * 
     * @param object $record			Database record
     * @param object $metadataRecord	Metadata record for the used format
     */
    protected function _updateDedupCandidateKeys(&$record, $metadataRecord)
    {
        $record['title_keys'] = array(MetadataUtils::createTitleKey($metadataRecord->getTitle(true)));
        $record['isbn_keys'] = $metadataRecord->getISBNs();
    }

    /**
     * Find a single duplicate for the given record and set a dedup key for them
     * 
     * @param object $record		Database record
     * 
     * @return boolean				Whether a duplicate was found
     */
    protected function _dedupRecord($record)
    {
        if ($this->verbose) {
            echo 'Original ' . $record['_id'] . ":\n" . $this->_getRecordData($record, true) . "\n";
        }
        
        $origRecord = RecordFactory::createRecord($record['format'], $this->_getRecordData($record, true), $record['oai_id']);
        $key = MetadataUtils::createTitleKey($origRecord->getTitle(true));
        $keyArray = array($key);
        $ISBNArray = $origRecord->getISBNs();

        $matchRecord = null;
        foreach (array('ISBN' => $ISBNArray, 'key' => $keyArray) as $type => $array) {
            foreach ($array as $keyPart) {
                if (!$keyPart || isset($this->_tooManyCandidatesKeys[$keyPart])) {
                    continue;
                }
                	
                $startTime = microtime(true);

                if ($this->verbose) {
                    echo "Search: '$keyPart'\n";
                }
                $params = array('source_id' => array('$ne' => $this->_sourceId), 'host_record_id' => '');
                if ($type == 'ISBN') {
                    $params['isbn_keys'] = $keyPart;
                }
                else {
                    $params['title_keys'] = $keyPart;
                }
                $candidates = $this->_db->record->find($params);
                $processed = 0;
                if ($candidates->hasNext()) {
                    // We have candidates
                    if ($this->verbose) {
                        echo "Found candidates\n";
                    }
                    //echo "Found " . $candidates->count() . " candidates for '$keyPart'\n";

                    // Go through the candidates, try to match
                    $matchRecord = null;
                    foreach ($candidates as $candidate) {
                        if ($candidate['source_id'] == $this->_sourceId) {
                            continue;
                        }
                        // Verify the candidate has not been deduped with this source yet
                        if (isset($candidate['dedup_key']) && $candidate['dedup_key'] && (!isset($record['dedup_key']) || $candidate['dedup_key'] != $record['dedup_key'])) {
                            if ($this->_db->record->find(array('dedup_key' => $candidate['dedup_key'], 'source_id' => $this->_sourceId))->hasNext()) {
                                if ($this->verbose) {
                                    echo "Candidate {$candidate['_id']} already deduplicated\n";
                                }
                                continue;
                            }
                        }

                        if (++$processed > 1000) {
                            // Too many candidates, give up..
                            $this->_log->log('dedupRecord', "Too many candidates for record " . $record['_id'] . " with key '$keyPart'", Logger::DEBUG);
                            $this->_tooManyCandidatesKeys[$keyPart] = 1;
                            if (count($this->_tooManyCandidatesKeys) > 20000) {
                                array_shift($this->_tooManyCandidatesKeys);
                            }
                            break;
                        }

                        if ($this->_matchRecords($record, $origRecord, $candidate)) {
                            $matchRecord = $candidate;
                            break 3;
                        }
                    }
                }
            }
        }

        if ($matchRecord) {
            $this->_markDuplicates($record, $matchRecord);
            return true;
        } 
        $record['dedup_key'] = null;
        $record['updated'] = new MongoDate();
        $record['update_needed'] = false;
        $this->_db->record->save($record);
        return false;
    }

    protected function _matchRecords($record, $origRecord, $candidate)
    {
        $cRecord = RecordFactory::createRecord($candidate['format'], $this->_getRecordData($candidate, true), $record['oai_id']);
        if ($this->verbose) {
            echo 'Candidate ' . $candidate['_id'] . ":\n" . $this->_getRecordData($candidate, true) . "\n";
        }
         
        // Check for common ISBN
        $origISBNs = $origRecord->getISBNs();
        $cISBNs = $cRecord->getISBNs();
        $isect = array_intersect($origISBNs, $cISBNs);
        if (!empty($isect)) {
            // Shared ISBN -> match
            if ($this->verbose) {
                echo "++ISBN match:\n";
                print_r($origISBNs);
                print_r($cISBNs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return true; 
        }
        
        $origISSNs = $origRecord->getISSNs();
        $cISSNs = $cRecord->getISSNs();
        $commonISSNs = array_intersect($origISSNs, $cISSNs);
        if (!empty($origISSNs) && !empty($cISSNs) && empty($commonISSNs)) {
            // Both have ISSNs but none match
            if ($this->verbose) {
                echo "++ISSN mismatch:\n";
                print_r($origISSNs);
                print_r($cISSNs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return false;
        }
        
        if ($origRecord->getFormat() != $cRecord->getFormat()) {
            if ($this->verbose) {
                echo "--Format mismatch: " . $origRecord->getFormat() . ' != ' . $cRecord->getFormat() . "\n";
            }
            return false;
        }
        $origYear = $origRecord->getPublicationYear();
        $cYear = $cRecord->getPublicationYear();
        if ($origYear && $cYear && $origYear != $cYear) {
            if ($this->verbose) {
                echo "--Year mismatch: $origYear != $cYear\n";
            }
            return false;
        }
        $pages = $origRecord->getPageCount();
        $cPages = $cRecord->getPageCount();
        if ($pages && $cPages && abs($pages-$cPages) > 10) {
            if ($this->verbose) {
                echo "--Pages mismatch ($pages != $cPages)\n";
            }
            return false;
        }
        
        if ($origRecord->getSeriesISSN() != $cRecord->getSeriesISSN()) {
            return false;
        }
        if ($origRecord->getSeriesNumbering() != $cRecord->getSeriesNumbering()) {
            return false;
        }
        
        $origTitle = MetadataUtils::normalize($origRecord->getTitle(true));
        $cTitle = MetadataUtils::normalize($cRecord->getTitle(true));
        if (!$origTitle || !$cTitle) {
            // No title match without title...
            if ($this->verbose) {
                echo "No title - no further matching\n";
            }
            return false;
        }
        $lev = levenshtein(substr($origTitle, 0, 255), substr($cTitle, 0, 255));
        $lev = $lev / strlen($origTitle) * 100;
        if ($this->verbose) {
            echo "Title lev: $lev\n";
        }
        if ($lev >= 10) {
            return false;
        }
        
        $origAuthor = MetadataUtils::normalize($origRecord->getMainAuthor());
        $cAuthor = MetadataUtils::normalize($cRecord->getMainAuthor());
        $authorLev = 0;
        if ($origAuthor && $cAuthor) {
            if (!MetadataUtils::authorMatch($origAuthor, $cAuthor)) {
                $authorLev = levenshtein(substr($origAuthor, 0, 255), substr($cAuthor, 0, 255));
                $authorLev = $authorLev / mb_strlen($origAuthor) * 100;
                if ($authorLev > 20) {
                    if ($this->verbose) {
                        echo "\nAuthor lev discard (lev: $lev, authorLev: $authorLev):\n";
                        echo $origRecord->getFullTitle() . "\n";
                        echo "   $origAuthor - $origTitle\n";
                        echo $cRecord->getFullTitle() . "\n";
                        echo "   $cAuthor - $cTitle\n";
                    }
                    return false;
                }
            }
        }
                 
        if ($this->verbose) {
            echo "\nTitle match (lev: $lev, authorLev: $authorLev):\n";
            echo $origRecord->getFullTitle() . "\n";
            echo "   $origAuthor - $origTitle.\n";
            echo $cRecord->getFullTitle() . "\n";
            echo "   $cAuthor - $cTitle.\n";
        }
        // We have a match!
        return true;
    }
    
    /**
     * Mark two records as duplicates
     * 
     * @param object $rec1	The record for which a duplicate was searched
     * @param object $rec2	The found duplicate
     */
    protected function _markDuplicates($rec1, $rec2)
    {
        if (isset($rec2['dedup_key']) && $rec2['dedup_key']) {
            $rec1['dedup_key'] = $rec2['dedup_key'];
        }
        else
        {
            $key = 'dedup' . $this->_uniqIdPrefix . (++$this->_uniqIdCounter);
            $rec1['dedup_key'] = $key;
            $rec2['dedup_key'] = $key;
        }
        $rec1['updated'] = new MongoDate();
        $rec1['update_needed'] = false;            
        $this->_db->record->save($rec1);
        $rec2['updated'] = new MongoDate();
        $rec2['update_needed'] = false;
        $this->_db->record->save($rec2);
        
        if (!isset($rec1['host_record_id']) || !$rec1['host_record_id']) {
            $this->_dedupComponentParts($rec1);
        }
    }
    
    /**
     * Deduplicate component parts of a record
     * 
     * Component part deduplication is special. It will only go through
     * component parts of other records deduplicated with the host record
     * and stops when it finds a set of component parts that match.
     * 
     * @param mongo record $hostRecord
     */
    protected function _dedupComponentParts($hostRecord)
    {
        assert('$hostRecord["dedup_key"]');
        if ($this->verbose) {
            echo "Deduplicating component parts...\n";
        }
        $components1iter = $this->_db->record->find(array('host_record_id' => $hostRecord['_id']));
        if (!$components1iter->hasNext()) {
            return;
        }
        $components1 = array();
        foreach ($components1iter as $component1) {
            $components1[MetadataUtils::createIdSortKey($component1['_id'])] = $component1;
        }
        ksort($components1);
        
        // Go through all other records with same dedup key and see if their component parts match
        $otherRecords = $this->_db->record->find(array('deleted' => false, 'dedup_key' => $hostRecord['dedup_key']));
        foreach ($otherRecords as $otherRecord) {
            if ($otherRecord['source_id'] == $hostRecord['source_id']) {
                continue;
            }
            $components2iter = $this->_db->record->find(array('host_record_id' => $otherRecord['_id']));
            $components2 = array();
            foreach ($components2iter as $component2) {
                $components2[MetadataUtils::createIdSortKey($component2['_id'])] = $component2;
            }
            ksort($components2);
            $components2 = array_values($components2);
            $allMatch = true;
            $idx = -1;
            foreach ($components1 as $component1) {
                if (++$idx >= count($components2)) {
                    $allMatch = false;
                    break;
                }
                $component2 = $components2[$idx];
                if ($this->verbose) {
                    echo "Comparing {$component1['_id']} with {$component2['_id']}\n";
                }
                if ($this->verbose) {
                    echo 'Original ' . $component1['_id'] . ":\n" . $this->_getRecordData($component1, true) . "\n";
                }
                $metadataComponent1 = RecordFactory::createRecord($component1['format'], $this->_getRecordData($component1, true), $component1['oai_id']);
                if (!$this->_matchRecords($component1, $metadataComponent1, $component2)) {
                    $allMatch = false;
                    break;
                }
            }
            if ($allMatch) {
                if ($this->verbose) {
                    echo "All component parts match between {$hostRecord['_id']} and {$otherRecord['_id']}\n";
                }
                foreach ($components1 as $component1) {
                    $component2 = $components2[$idx];
                    $this->_markDuplicates($component1, $component2);
                }
                break;
            }
        }
    }

    /**
     * Execute a pretransformation on data before it is split into records and loaded. Used when loading from a file.
     * 
     * @param string $data	The original data
     * @return string		Transformed data
     */
    protected function _pretransform($data)
    {
        if (!isset($this->_pre_xslt))
        {
            $style = new DOMDocument();
            $style->load($this->_basePath . '/transformations/' . $this->_pretransformation);
            $this->_pre_xslt = new XSLTProcessor();
            $this->_pre_xslt->importStylesheet($style);
            $this->_pre_xslt->setParameter('', 'source_id', $this->_sourceId);
            $this->_pre_xslt->setParameter('', 'institution', $this->_institution);
            $this->_pre_xslt->setParameter('', 'format', $this->_format);
            $this->_pre_xslt->setParameter('', 'id_prefix', $this->_idPrefix);
        }
        $doc = new DOMDocument();
        $doc->loadXML($data);
        return $this->_pre_xslt->transformToXml($doc);
    }

    /**
     * Create a timestamp string from the given unix timestamp
     * 
     * @param int 		$timestamp 	Unix timestamp
     * @return string				Formatted string
     */
    protected function _formatTimestamp($timestamp)
    {
        $date = new DateTime('', new DateTimeZone('UTC'));
        $date->setTimeStamp($timestamp);
        return $date->format('Y-m-d') . 'T' . $date->format('H:i:s') . 'Z';
    }

    /**
     * Make a JSON request to the Solr server
     * 
     * @param string $body	The JSON request
     */
    protected function _solrRequest($body)
    {
        global $configArray;

        $request = new HTTP_Request2($configArray['Solr']['update_url'], HTTP_Request2::METHOD_POST, 
            array('ssl_verify_peer' => false));
        $request->setHeader('User-Agent', 'RecordManager');
        if (isset($configArray['Solr']['username']) && isset($configArray['Solr']['password'])) {
            $request->setAuth($configArray['Solr']['username'], $configArray['Solr']['password'], HTTP_Request2::AUTH_BASIC);
        }
        $request->setHeader('Content-Type', 'application/json');
        $request->setBody($body);
        $response = $request->send();
        $code = $response->getStatus();
        if ($code >= 300) {
            throw new Exception("Solr server request failed ($code). Request:\n$body\n\nResponse:\n" . $response->getBody());
        }
    }

    /**
     * Get record metadata from a database record
     * 
     * @param object $record		Database record
     * @param bool   $normalized	Whether to return the original (false) or normalized (true) record
     * @return string				Metadata as a string
     */
    protected function _getRecordData(&$record, $normalized)
    {
        if ($normalized) {
            $data = $record['normalized_data'] ? $record['normalized_data'] : $record['original_data'];
        } else {
            $data = $record['original_data'];
        }
        return is_string($data) ? $data : gzinflate($data->bin);
    }
    
    /**
     * Load the data source settings and setup some functions
     *
     * @param string $source	Source ID 
     * @throws Exception
     */
    protected function _loadSourceSettings($source)
    {
        if (!isset($this->_dataSourceSettings[$source])) {
            $this->_log->log('loadSourceSettings', "Settings not found for data source $source", Logger::FATAL);
            throw new Exception("Error: settings not found for $source\n");
        }
        $settings = $this->_dataSourceSettings[$source];
        if (!isset($settings['institution'])) {
            $this->_log->log('loadSourceSettings', "institution not set for $source", Logger::FATAL);
            throw new Exception("Error: institution not set for $source\n");
        }
        if (!isset($settings['format'])) {
            $this->_log->log('loadSourceSettings', "format not set for $source", Logger::FATAL);
            throw new Exception("Error: format not set for $source\n");
        }
        $this->_format = $settings['format'];
        $this->_sourceId = $source;
        $this->_idPrefix = isset($settings['idPrefix']) && $settings['idPrefix'] ? $settings['idPrefix'] : $source;
        $this->_institution = $settings['institution'];
        $this->_recordXPath = isset($settings['recordXPath']) ? $settings['recordXPath'] : '';
        $this->_dedup = isset($settings['dedup']) ? $settings['dedup'] : false;
        $this->_componentParts = isset($settings['componentParts']) && $settings['componentParts'] ? $settings['componentParts'] : 'as_is';
        $this->_pretransformation = isset($settings['preTransformation']) ? $settings['preTransformation'] : '';
        $this->_indexMergedParts = isset($settings['indexMergedParts']) ? $settings['indexMergedParts'] : true;
        $this->_harvestType = isset($settings['type']) ? $settings['type'] : '';
        
        $params = array('source_id' => $this->_sourceId, 'institution' => $this->_institution, 'format' => $this->_format, 'id_prefix' => $this->_idPrefix);
        $this->_normalizationXSLT = isset($settings['normalization']) && $settings['normalization'] ? new XslTransformation($this->_basePath . '/transformations', $settings['normalization'], $params) : null;
        $this->_solrTransformationXSLT = isset($settings['solrTransformation']) && $settings['solrTransformation'] ? new XslTransformation($this->_basePath . '/transformations', $settings['solrTransformation'], $params) : null;
        
        if (isset($settings['recordSplitter'])) {
            if (substr($settings['recordSplitter'], -4) == '.php') {
                $this->_recordSplitter = $settings['recordSplitter']; 
            } else {
                $style = new DOMDocument();
                $xslFile = $this->_basePath . '/transformations/' . $settings['recordSplitter'];
                if ($style->load($xslFile) === false) {
                    throw new Exception("Could not load $xslFile");
                }
                $this->_recordSplitter = new XSLTProcessor();
                $this->_recordSplitter->importStylesheet($style);
            }
        } else {
            $this->_recordSplitter = null;
        }
    }
}

