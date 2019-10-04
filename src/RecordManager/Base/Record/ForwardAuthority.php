<?php
/**
 * Forward authority Record Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2016-2019.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Record;

use RecordManager\Base\Utils\MetadataUtils;

/**
 * Forward authority Record Class
 *
 * This is a class for processing Forward records for an authority index.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class ForwardAuthority extends Base
{
    protected $doc = null;

    /**
     * Set record data
     *
     * @param string $source Source ID
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for
     * file import)
     * @param string $data   Metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data)
    {
        parent::setData($source, $oaiID, $data);
        $this->doc = simplexml_load_string($data);
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        $doc = $this->getMainElement();
        return (string)$doc->AgentIdentifier->IDTypeName . ':'
            . (string)$doc->AgentIdentifier->IDValue;
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return MetadataUtils::trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        return $this->doc->asXML();
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return array
     */
    public function toSolrArray()
    {
        $data = [];

        $data['record_format'] = 'forwardAuthority';
        $data['fullrecord'] = MetadataUtils::trimXMLWhitespace($this->doc->asXML());
        $data['allfields'] = $this->getAllFields();
        $data['source'] = $this->getRecordSource();
        $data['record_type'] = $this->getRecordType();
        $data['heading'] = $data['heading_keywords'] = $this->getHeading();
        $data['use_for'] = $this->getUseForHeadings();
        $data['birth_date'] = $this->getBirthDate();
        $data['death_date'] = $this->getDeathDate();
        $data['birth_place'] = $this->getBirthPlace();
        $data['death_place'] = $this->getDeathPlace();
        $data['related_place'] = $this->getRelatedPlaces();
        $data['field_of_activity'] = $this->getFieldsOfActivity();
        $data['occupation'] = $this->getOccupations();
        $data['language'] = $this->getHeadingLanguage();
        $data['datasource_str_mv'] = $data['source_str_mv'] = $this->source;

        return $data;
    }

    /**
     * Get agency name
     *
     * @return array
     */
    protected function getAgencyName()
    {
        $doc = $this->getMainElement();
        $name = [];
        if (isset($doc->RecordSource)) {
            foreach ($this->getMainElement()->RecordSource as $src) {
                if (isset($src->SourceName)) {
                    $name[] = (string)$src->SourceName;
                }
            }
        }
        return empty($name) ? $this->source : implode('. ', $name);
    }

    /**
     * Get all XML fields
     *
     * @return string
     */
    protected function getAllFields()
    {
        $doc = $this->getMainElement();

        $fields = [];

        $fields[] = $this->getAgencyName();

        if (isset($doc->BiographicalNote)) {
            $fields[] = (string)$doc->BiographicalNote;
        }
        $fields[] = $this->getHeading();
        $fields = array_merge($fields, $this->getUseForHeadings());

        return $fields;
    }

    /**
     * Get birth date
     *
     * @return string
     */
    protected function getBirthDate()
    {
        if ($date = $this->getAgentDate('birth')) {
            return $date['date'];
        }

        return '';
    }

    /**
     * Get birth place
     *
     * @return string
     */
    protected function getBirthPlace()
    {
        if ($date = $this->getAgentDate('birth')) {
            return $date['place'];
        }
        return '';
    }

    /**
     * Get death date
     *
     * @return string
     */
    protected function getDeathDate()
    {
        if ($date = $this->getAgentDate('death')) {
            return $date['date'];
        }
        return '';
    }

    /**
     * Get death place
     *
     * @return string
     */
    protected function getDeathPlace()
    {
        if ($date = $this->getAgentDate('death')) {
            return $date['place'];
        }
        return '';
    }

    /**
     * Return date death/birth date and place.
     *
     * @param string $type death|birth
     *
     * @return mixed array with keys 'date' and 'place' or null when not defined
     */
    protected function getAgentDate($type)
    {
        $doc = $this->getMainElement();
        if (isset($doc->AgentDate)) {
            foreach ($doc->AgentDate as $d) {
                if (isset($d->AgentDateEventType)) {
                    $dateType = (int)$d->AgentDateEventType;
                    $date = (string)$d->DateText;
                    $place =  (string)$d->LocationName;
                    if (($type === 'birth' && $dateType === 51)
                        || ($type == 'death' && $dateType === 52)
                    ) {
                        return ['date' => $date, 'place' => $place];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get fields of activity
     *
     * @return array
     */
    protected function getFieldsOfActivity()
    {
        return [];
    }

    /**
     * Get heading
     *
     * @return string
     */
    protected function getHeading()
    {
        $doc = $this->getMainElement();

        if (isset($doc->CAgentName->PersonName)) {
            return (string)$doc->CAgentName->PersonName;
        } else if (isset($doc->CAgentName->CorporateName)) {
            return (string)$doc->CAgentName->CorporateName;
        }
        return '';
    }

    /**
     * Get heading language
     *
     * @return string
     */
    protected function getHeadingLanguage()
    {
        return '';
    }

    /**
     * Get occupations
     *
     * @return array
     */
    protected function getOccupations()
    {
        $doc = $this->getMainElement();

        $result = [];
        if (isset($doc->ProfessionalAffiliation)) {
            $label = '';
            if (isset($doc->ProfessionalAffiliation->Affiliation)) {
                $label = (string)$doc->ProfessionalAffiliation->Affiliation;
            }
            if (isset($doc->ProfessionalAffiliation->ProfessionalPosition)) {
                $position
                    = (string)$doc->ProfessionalAffiliation->ProfessionalPosition;
                $label = $label
                    ? $label .= ": $position"
                    : $position;
            }
            $result[] = $label;
        }
        return $result;
    }

    /**
     * Get related places
     *
     * @return array
     */
    protected function getRelatedPlaces()
    {
        $doc = $this->getMainElement();

        if (isset($doc->AgentPlace->LocationName)) {
            return [(string)$doc->AgentPlace->LocationName];
        }

        return [];
    }

    /**
     * Get record source
     *
     * @return string
     */
    protected function getRecordSource()
    {
        return $this->source;
    }

    /**
     * Get record type
     *
     * @return string
     */
    protected function getRecordType()
    {
        $doc = $this->getMainElement();
        if (isset($doc->AgentIdentifier->IDTypeName)) {
            return (string)$doc->AgentIdentifier->IDTypeName;
        }
        return '';
    }

    /**
     * Get use for headings
     *
     * @return string
     */
    protected function getUseForHeadings()
    {
        return [$this->getHeading()];
    }

    /**
     * Get the main metadata element
     *
     * @return SimpleXMLElement
     */
    protected function getMainElement()
    {
        $nodes = (array)$this->doc->children();
        $node = reset($nodes);
        return is_array($node) ? reset($node) : $node;
    }
}