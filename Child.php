<?php

namespace Stanford\MirrorMasterDataModule;

use REDCap;

/**
 * Class Child
 * @package Stanford\MirrorMasterDataModule
 * @property int $projectId
 * @property \Project $project
 * @property int $eventId
 * @property array $dags
 * @property array $record
 * @property string $recordId
 * @property string $instrument
 * @property string $eventName
 * @property string $primaryKey
 * @property boolean $fieldClobber
 * @property boolean $changeRecordId
 * @property array $config
 * @property string $PREFIX
 */
class Child
{

    use emLoggerTrait;

    private $projectId;

    private $project;

    private $eventId;

    private $dags;

    private $record;

    private $recordId;

    private $instrument;

    private $eventName;

    private $primaryKey;

    private $fieldClobber;

    private $changeRecordId = true;

    private $config;

    public $PREFIX;
    /**
     * Master constructor.
     * @param $projectId
     * @param null $eventId
     * @param null $recordId
     * @param null $instrument
     * @param null $dags
     */
    public function __construct($projectId, $PREFIX)
    {
        $this->setProjectId($projectId);
        /**
         * this public so we do not have to modify emLoggerTrait
         */
        $this->PREFIX = $PREFIX;

        $this->setProject(new \Project($this->getProjectId()));

        $this->setPrimaryKey($this->getProject()->table_pk);
    }



    /**
     * @param $pid
     * @param int $event_id : Pass NULL or '' if CLASSICAL
     * @param string $prefix
     * @param bool $padding
     * @return bool|int|string
     * @throws
     */
    public function getNextRecordId($prefix = '', $padding = false)
    {
        $q = \REDCap::getData($this->getProjectId(), 'array', null, array($this->getPrimaryKey()), $this->getEventId());
        //$this->emLog($q, "Found records in project $pid using $id_field");
        $i = 1;
        do {
            // Make a padded number
            if ($padding) {
                // make sure we haven't exceeded padding, pad of 2 means
                //$max = 10^$padding;
                $max = 10 ** $padding;
                if ($i >= $max) {
                    $this->emLog("Error - $i exceeds max of $max permitted by padding of $padding characters");
                    return false;
                }
                $id = str_pad($i, $padding, "0", STR_PAD_LEFT);
                //$this->emLog("Padded to $padding for $i is $id");
            } else {
                $id = $i;
            }

            // Add the prefix
            $id = $prefix . $id;
            //$this->emLog("Prefixed id for $i is $id for event_id $event_id and idfield $id_field");

            $i++;
        } while (!empty($q[$id][$this->getEventId()][$this->getPrimaryKey()]));

        $this->emLog("Next ID in project " . $this->getProjectId() . " for field " . $this->getPrimaryKey() . " is $id");

        return $id;
    }

    /**
     * check if child record already saved before
     * @return bool
     */
    public function isRecordIdExist()
    {
        $results = REDCap::getData($this->getProjectId(), 'json', $this->getRecordId(), null, $this->getEventName());
        $results = json_decode($results, true);
        $target_results = current($results);
        if ((!empty($target_results))) {
            return true;
        }
        return false;
    }

    /**
     * get child arm for current event
     * @return bool|int
     */
    public function getArm()
    {
        $arm = db_result(db_query("select arm_num from redcap_events_arms a, redcap_events_metadata e where a.arm_id = e.arm_id and e.event_id = " . $this->getEventId()),
            0);
        // Just in case arm is blank somehow
        if ($arm == "" || !is_numeric($arm)) {
            $arm = 1;
        }
        return $arm;
    }


    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param array $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @return bool
     */
    public function isChangeRecordId()
    {
        return $this->changeRecordId;
    }

    /**
     * @param bool $changeRecordId
     */
    public function setChangeRecordId($changeRecordId)
    {
        $this->changeRecordId = $changeRecordId;
    }


    /**
     * @return int
     */
    public function getProjectId()
    {
        return $this->projectId;
    }

    /**
     * @param int $projectId
     */
    public function setProjectId($projectId)
    {
        $this->projectId = $projectId;
    }

    /**
     * @return \Project
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * @param \Project $project
     */
    public function setProject($project)
    {
        $this->project = $project;
    }

    /**
     * @return int
     */
    public function getEventId()
    {
        return $this->eventId;
    }

    /**
     * @param int $eventId
     */
    public function setEventId($eventId)
    {
        $this->eventId = $eventId;
    }

    /**
     * @return array
     */
    public function getDags()
    {
        return $this->dags;
    }

    /**
     * @param array $dags
     */
    public function setDags($dags)
    {
        $this->dags = $dags;
    }

    /**
     * @return array
     */
    public function getRecord()
    {
        return $this->record;
    }

    /**
     * @param array $record
     */
    public function setRecord($record)
    {
        $this->record = $record;
    }

    /**
     * @return string
     */
    public function getRecordId()
    {
        return $this->recordId;
    }

    /**
     * @param string $recordId
     */
    public function setRecordId($recordId)
    {
        $this->recordId = $recordId;
    }

    /**
     * @return string
     */
    public function getInstrument()
    {
        return $this->instrument;
    }

    /**
     * @param string $instrument
     */
    public function setInstrument($instrument)
    {
        $this->instrument = $instrument;
    }

    /**
     * @return string
     */
    public function getEventName()
    {
        return $this->eventName;
    }

    /**
     * @param string $eventName
     */
    public function setEventName($eventName)
    {
        $this->eventName = $eventName;
    }

    /**
     * @return string
     */
    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    /**
     * @param string $primaryKey
     */
    public function setPrimaryKey($primaryKey)
    {
        $this->primaryKey = $primaryKey;
    }

    /**
     * @return bool
     */
    public function isFieldClobber()
    {
        return $this->fieldClobber;
    }

    /**
     * @param bool $fieldClobber
     */
    public function setFieldClobber($fieldClobber)
    {
        $this->fieldClobber = $fieldClobber;
    }

    /**
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * @param string $prefix
     */
    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
    }

}
