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
 * @property string $dagRecordId
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

    private $dagRecordId;
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
     * @param $config
     * @param \Stanford\MirrorMasterDataModule\Master $master
     * @param $childSaveRecordHook
     * @param null $dagId
     * @return bool
     */
    public function saveData($config, $master, $childSaveRecordHook, $firstEvetnId, $dagId = null)
    {
        //$this->emDebug("PROCEED: Target $childId does not exists (" . count($target_results) . ") in $child_pid or clobber true ($child_field_clobber).");

        //5. SET UP CHILD PROJECT TO SAVE DATA
        //GET logging variables from target project


        //add additional fields to be added to the SaveData call
        //set the new ID
        $record = $this->getRecord();
        $record[$this->getPrimaryKey()] = $this->getRecordId();

        //if child event id defined add it to child record
        if (!empty($this->getEventName())) {
            $record['redcap_event_name'] = ($this->getEventName());
        }

        //enter logging field for child-field-for-parent-id
        if (!empty($config['child-field-for-parent-id'])) {
            $record[$config['child-field-for-parent-id']] = $master->getRecordId();
        }

        //enter logging field for child-field-for-migration-timestamp
        if (!empty($config['child-field-for-migration-timestamp'])) {
            $record[$config['child-field-for-migration-timestamp']] = date('Y-m-d H:i:s');
        }

        $this->setRecord($record);
        //$this->emLog($newData, "SAVING THIS TO CHILD DATA");

        //6. UPDATE CHILD: Upload the data to child project

        // $result = REDCap::saveData(
        //     $child_pid,
        //     'json',
        //     json_encode(array($newData)),
        //     (($child_field_clobber == '1') ? 'overwrite' : 'normal'));

        // IN ORDER TO BE ABLE TO COPY CAT INSTRUMENTS WE ARE GOING TO USE THE UNDERLYING RECORDS SAVE METHOD INSTEAD OF REDCAP METHOD:
        /*$args = array(
            0 => $this->getChildProjectId(),
            1 => 'json',
            2 => json_encode(array($parentData)),
            3 => ($config['child-field-clobber'] == '1') ? 'overwrite' : 'normal',
            4 => 'YMD',
            5 => 'flat',
            6 => null,
            7 => true,
            8 => true,
            9 => true,
            10 => false,
            11 => true,
            12 => array(),
            13 => false,
            14 => false, // CONTINUE WITH UPLOADED FILES
            15 => false,
            16 => false,
            17 => true
        );*/

        //save child record (This can be moved to child object (whoever review this what do you think))
        $result = \REDCap::saveData($this->getProjectId(),
            'json',
            json_encode(array($this->getRecord())),
            ($this->isFieldClobber() == '1') ? 'overwrite' : 'normal',
            'YMD',
            'flat');

        //$result = call_user_func_array(array("Records", "saveData"), $args);

        $this->emDebug("SAVE RESULT", $result);


        // Check for upload errors
        if (!empty($result['errors'])) {
            return $result['errors'];
        } else {
            /**
             * let check if parent record is in a DAG, if so lets find the corresponding Child DAG and update the data accordingly
             */
            if (($config['same-dags-name'] || !empty($config['master-child-dag-map'])) && !empty($dagId)) {

                try {
                    //get first event in case child event name is not  defined.
                    if ($this->getEventName() == "" || $this->getEventName() == null) {
                        $this->setEventId($firstEvetnId);
                        //at this point no need for event name because everything is saved we just want to save dag information
                    }
                    /**
                     * temp solution till pull request is approved by Venderbilt
                     */
                    $record = $this->getRecordId();
                    $value = $dagId;
                    $fieldName = '__GROUPID__';
                    $childPid = $this->getProjectId();
                    $eventId = $this->getEventId();
                    db_query("INSERT INTO redcap_data (project_id, event_id, record, field_name, value) VALUES ($childPid, $eventId, '$record', '$fieldName', '$value')");

                    //get child event arm to be used to update id for the dropdown
                    $arm = $this->getArm();

                    //just update record list. only for Record Status Dashboard dropdown
                    db_query("UPDATE redcap_record_list SET dag_id = '$value' WHERE project_id = $childPid and arm = $arm and record = '$record'");


                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    $master->updateNotes($config, $msg);
                    return false;
                }
                //
                //$this->setDAG(array_pop($result['ids']), $this->getDagId(), $childPid, $event_id);
            }
            // Call save_record hook on child?
            if ($childSaveRecordHook) {

                //last check if no event name is defined for child then use event id obtained to get event name
                if (!$this->getEventName()) {
                    $this->setEventName(\REDCap::getEventNames(true, true,
                        $this->getEventId()));
                }

                // REDCap Hook injection point: Pass project_id and record name to method
                // \Hooks::call('redcap_save_record', array($childPid, $child_id, $_GET['page'], $child_event_name, $group_id, null, null, $_GET['instance']));
                \Hooks::call('redcap_save_record',
                    array(
                        $this->getProjectId(),
                        $this->getRecordId(),
                        filter_var($_GET['page'], FILTER_SANITIZE_STRING),
                        $this->getEventName(),
                        null
                    ));
            }

            $this->migrateFiles($master);

            return true;
        }
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
     * @param $config
     * @param \Stanford\MirrorMasterDataModule\Master $master
     * @param null $dagId
     */
    public function prepareChildRecord($config, $master, $dagId = null)
    {
        /**
         * let check if parent record is in a DAG, if so lets find the corresponding Child DAG and update the data accordingly
         */
        // if (($config['same-dags-name'] || !empty($config['master-child-dag-map'])) && strpos($master->getRecordId(),'-') !== false && $this->getChild()->isChangeRecordId()) {
        if (($config['same-dags-name'] || !empty($config['master-child-dag-map'])) && !empty($dagId) && $this->isChangeRecordId()) {
            //set child record id based on dag information saved inside the hook
            $this->setRecordId($this->getNextRecordDAGID($this->getProjectId(), $dagId));

            //set child record based on master record
            $record = $master->getRecord();

            //modify record id in value to use same value we got in the above tow lines them set the child record
            $record[$this->getPrimaryKey()] = $this->getRecordId();
            $this->setRecord($record);

        } else {
            //make sure the record we are saving into child project is the one we pulled from master. with specified fields.
            $this->setRecord($master->getRecord());
        }
    }

    /**
     * @param \Stanford\MirrorMasterDataModule\Master $master
     */
    private function migrateFiles($master){
        $project = $master->getProject();
        $masterFields = $project->metadata;
        $record = $this->getRecord();
        foreach ($record as $field => $value){
            if($masterFields[$field]['element_type'] == 'file'){
                $newDocId = copyFile($value, $this->getProjectId());
                $projectId = $this->getProjectId();
                $event = $this->getEventId();
                $recordId = $this->getRecordId();

                //Save file skip uploaded files values for some reason. so you need to save these values manually.
                $sql = "insert into redcap_data (`value`, project_id, event_id, record, field_name) values ('$newDocId' , '{$projectId}', '{$event}','" . db_escape($recordId) . "','{$field}')";
                db_query($sql);

            }
        }
        $this->setRecord($record);
    }

    /**
     * if dag is defined then put this id as fall back before run getChildRecordId which might change the reocrdi id based  on admin configuration
     * @param int $dagId
     * @return int
     */
    private function getNextRecordDAGID($childProjectId, $dagId)
    {
        $sql = "SELECT MAX(record) as recordId FROM redcap_data WHERE field_name = '__GROUPID__' AND `value` = $dagId AND project_id = '$childProjectId'";
        $q = db_query($sql);

        $row = db_fetch_row($q);
        if (!empty($row)) {
            $parts = explode("-", $row[0]);
            $recordId = end($parts) + 1;
            $this->setDagRecordId($dagId . "-" . $recordId);
        } else {
            $this->setDagRecordId($dagId . "-" . 1);
        }
        return $this->getDagRecordId();
    }


    /**
     * this function will set child record id(which might already be set by getNextRecordDAGID) in case configuration is different
     * @param $config
     * @param \Stanford\MirrorMasterDataModule\Master $master
     * @return bool
     */
    public function prepareRecordId($config, $master, $dagId = null)
    {
        $childId = null;

        // Method for creating the child id (child-id-create-new, child-id-like-parent, child-id-parent-specified)
        $childIdSelect = $config['child-id-select'];

        //get primary key for TARGET project
        $childPrimaryKey = $this->getPrimaryKey();

        //$this->emDebug($master->getRecordId(), $this->getProjectId(), $childIdSelect, $childPrimaryKey);
        $childId = '';
        switch ($childIdSelect) {
            case 'child-id-like-parent':
                $childId = $master->getRecordId();
                break;
            case 'child-id-parent-specified':
                $child_id_parent_specified_field = $config['child-id-parent-specified-field'];

                //get data from parent for the value in this field
                $results = REDCap::getData('json', $master->getRecordId(),
                    array($child_id_parent_specified_field),
                    $master->getEventName());
                $results = json_decode($results, true);
                $existing_target_data = current($results);

                $childId = $existing_target_data[$child_id_parent_specified_field];
                $this->emDebug($existing_target_data, $child_id_parent_specified_field,
                    $this->getRecordId(),
                    "PARENT SPECIFIED CHILD ID: " . $this->getRecordId());
                break;
            case 'child-id-create-new':

                //if child record id is was set from previous iteration of sub-setting loop then use that instead so the data is saved to save record
                if ($this->isChangeRecordId()) {
                    $childIdPrefix = $config['child-id-prefix'];
                    $childIDPadding = $config['child-id-padding'];

                    /**
                     * in case we are adding to DAG just keep whatever we already retrieved
                     */
                    if ($this->getDagRecordId() != null) {
                        $childId = $this->getDagRecordId();
                        // Make a padded number
                        if ($childIDPadding) {
                            // make sure we haven't exceeded padding, pad of 2 means
                            //$max = 10^$padding;
                            $max = 10 ** $childIDPadding;
                            if ($childId >= $max) {
                                $this->emLog("Error - $childId exceeds max of $max permitted by padding of $childIDPadding characters");
                                return false;
                            }
                            $childId = str_pad($childId, $childIDPadding, "0", STR_PAD_LEFT);
                            //$this->emLog("Padded to $padding for $i is $id");
                        }
                        //does prefix is wrapped with square brackets ? if so build the custom prefix.
                        preg_match("/\[.*?\]/", $childIdPrefix, $matches);
                        if (!empty($matches)) {
                            $string = $matches[0];
                            $string = str_replace(array('[', ']'), '', $string);
                            $parts = explode(":", $string);
                            $r = $this->buildCustomPrefix($parts[0], end($parts), $this->getProjectId(), $dagId);
                            $childIdPrefix = str_replace($matches[0], $r, $childIdPrefix);
                        }
                        $childId = $childIdPrefix . $childId;
                    } else {
                        //get next id from child project
                        $childId = $this->getNextRecordId($childIdPrefix, $childIDPadding);
                    }
                }
                break;
            default:
                $childId = $this->getNextRecordId();
        }

        $this->setRecordId($childId);
    }


    /**
     * build prefix using admin definition currently it has only DAG but its will be easy to add more cases :)
     * @param $type
     * @param $field
     * @param $projectId
     * @param $value
     * @return bool
     */
    private function buildCustomPrefix($type, $field, $projectId, $value)
    {
        switch (strtolower($type)) {
            case 'dag':
                switch (strtolower($field)) {
                    case 'name':
                        return MirrorMasterDataModule::getProjectDAGName($projectId, $value);
                        break;
                    case 'id':
                        return $value;
                        break;
                }
                break;
            default:
                return $value;
                break;
        }
    }

    /**
     * @return mixed
     */
    public function getDagRecordId()
    {
        return $this->dagRecordId;
    }

    /**
     * @param mixed $dagRecordId
     */
    public function setDagRecordId($dagRecordId)
    {
        $this->dagRecordId = $dagRecordId;
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
