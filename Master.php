<?php

namespace Stanford\MirrorMasterDataModule;

use REDCap;

/**
 * Class Master
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
 * @property string $PREFIX
 *
 */
class Master
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

    public $PREFIX;
    /**
     * Master constructor.
     * @param $projectId
     * @param $eventId
     * @param null $recordId
     * @param null $instrument
     * @param null $dags
     */
    public function __construct($projectId, $eventId, $prefix, $recordId = null, $instrument = null, $dags = null)
    {
        $this->setProjectId($projectId);

        $this->setEventId($eventId);

        $this->setEventName(\REDCap::getEventNames(true, true, $this->getEventId()));

        $this->setProject(new \Project($this->getProjectId()));

        $this->setPrimaryKey($this->getProject()->table_pk);
        if (!is_null($recordId)) {
            $this->setRecordId($recordId);
        }

        if (!is_null($instrument)) {
            $this->setInstrument($instrument);
        }

        if (!is_null($dags)) {
            $this->setDags($dags);
        }

        /**
         * this public so we do not have to modify emLoggerTrait
         */
        $this->PREFIX;
    }

    /**
     * Bubble up status to user via the timestamp and notes field in the parent form
     * in config file as 'migration-notes'
     * @param $config : config fields for migration module
     * @param $msg : Message to enter into Notes field
     * @param $parent_data : If child migration successful, data about migration to child (else leave as null)
     * @return bool        : return fail/pass status of save data
     */
    public function updateNotes($config, $msg, $parent_data = array())
    {
        //$this->emLog($parent_data, "DEBUG", "RECEIVED THIS DATA");
        $parent_data[$this->getPrimaryKey()] = $this->getRecordId();
        if (isset($config['migration-notes'])) {
            $parent_data[$config['migration-notes']] = $msg;
        }

        if (!empty($config['master-event-name'])) {
            //assuming that current event is the right event
            //$this->emLog("Event name from REDCap::getEventNames : $master_event / EVENT name from this->redcap_event_name: ".$this->redcap_event_name);
            $parent_data['redcap_event_name'] = $this->getEventName(); //$config['master-event-name'];
        }

        //$this->emLog($parent_data, "Saving Parent Data");
        $result = REDCap::saveData(
            $this->getProjectId(),
            'json',
            json_encode(array($parent_data)),
            'overwrite');

        // Check for upload errors
        if (!empty($result['errors'])) {
            $msg = "Error creating record in PARENT project " . $this->getProjectId() . " - ask administrator to review logs: " . json_encode($result);
            //$sr->updateFinalReviewNotes($msg);
            //todo: bubble up to user : should this be sent to logging?
            $this->emError($msg);
            $this->emError("RESULT OF PARENT: " . print_r($result, true));
            //logEvent($description, $changes_made="", $sql="", $record=null, $event_id=null, $project_id=null);
            REDCap::logEvent("Mirror Master Data Module", $msg, null, $this->getRecordId(),
                $config['master-event-name']);
            return false;
        }

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


}
