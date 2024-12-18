<?php

namespace Stanford\MirrorSourceDataModule;

use REDCap;

/**
 * Class Destination
 * @package Stanford\MirrorSourceDataModule
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
 * @property boolean $generateSurvey
 * @property boolean $incrementRecordId
 * @property array $config
 * @property string $PREFIX
 * @property string $dagRecordId
 * @property string $survey
 */
class Destination
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

    private $generateSurvey;

    private $survey;

    private $incrementRecordId = false;

    /**
     * Source constructor.
     * @param $projectId
     * @param null $eventId
     * @param null $recordId
     * @param null $instrument
     * @param null $dags
     */
    public function __construct($projectId, $PREFIX, $incrementRecordId)
    {
        $this->setProjectId($projectId);
        /**
         * this public so we do not have to modify emLoggerTrait
         */
        $this->PREFIX = $PREFIX;

        $this->setProject(new \Project($this->getProjectId()));

        $this->setPrimaryKey($this->getProject()->table_pk);

        $this->setIncrementRecordId($incrementRecordId);
    }


    /**
     * @param $config
     * @param \Stanford\MirrorSourceDataModule\Source $source
     * @param $destinationSaveRecordHook
     * @param null $dagId
     * @return bool
     */
    public function saveData($config, $source, $destinationSaveRecordHook, $firstEvetnId, $dagId = null)
    {
        //$this->emDebug("PROCEED: Target $destinationId does not exists (" . count($target_results) . ") in $destination_pid or clobber true ($destination_field_clobber).");

        //5. SET UP DESTINATION PROJECT TO SAVE DATA
        //GET logging variables from target project


        //add additional fields to be added to the SaveData call
        //set the new ID
        $record = $this->getRecord();
        $record[$this->getPrimaryKey()] = $this->getRecordId();

        //if destination event id defined add it to destination record
        if (!empty($this->getEventName())) {
            $record['redcap_event_name'] = ($this->getEventName());
        }

        //enter logging field for destination-field-for-source-id
        if (!empty($config['destination-field-for-source-id'])) {
            $record[$config['destination-field-for-source-id']] = $source->getRecordId();
        }

        //enter logging field for destination-field-for-migration-timestamp
        if (!empty($config['destination-field-for-migration-timestamp'])) {
            $record[$config['destination-field-for-migration-timestamp']] = date('Y-m-d H:i:s');
        }

        //if redcap_repeat_instrument and redcap_repeat_instance are blank then strip them.
        if ((isset($record['redcap_repeat_instrument'])) && ($record['redcap_repeat_instrument'] == '')) {
            unset($record['redcap_repeat_instrument']);

            if ((isset($record['redcap_repeat_instance'])) && ($record['redcap_repeat_instance'] == '')) {
                unset($record['redcap_repeat_instance']);
            }
        }

        $this->setRecord($record);
        //$this->emLog($newData, "SAVING THIS TO DESTINATION DATA");

        //6. UPDATE DESTINATION: Upload the data to destination project

        // $result = REDCap::saveData(
        //     $destination_pid,
        //     'json',
        //     json_encode(array($newData)),
        //     (($destination_field_clobber == '1') ? 'overwrite' : 'normal'));

        // IN ORDER TO BE ABLE TO COPY CAT INSTRUMENTS WE ARE GOING TO USE THE UNDERLYING RECORDS SAVE METHOD INSTEAD OF REDCAP METHOD:
        /*$args = array(
            0 => $this->getDestinationProjectId(),
            1 => 'json',
            2 => json_encode(array($sourceData)),
            3 => ($config['destination-field-clobber'] == '1') ? 'overwrite' : 'normal',
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

        //save destination record (This can be moved to destination object (whoever review this what do you think))
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
            return $result['errors']; // this is a string, not an array
        } else {
            /**
             * let check if source record is in a DAG, if so lets find the corresponding Destination DAG and update the data accordingly
             */
            if (($config['same-dags-name'] || !empty($config['source-destination-dag-map'])) && !empty($dagId)) {

                try {
                    //get first event in case destination event name is not  defined.
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
                    $destinationPid = $this->getProjectId();
                    $eventId = $this->getEventId();
                    $data_table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($destinationPid) : "redcap_data";
                    db_query("INSERT INTO $data_table (project_id, event_id, record, field_name, value) VALUES ($destinationPid, $eventId, '$record', '$fieldName', '$value')");

                    //get destination event arm to be used to update id for the dropdown
                    $arm = $this->getArm();

                    //just update record list. only for Record Status Dashboard dropdown
                    db_query("UPDATE redcap_record_list SET dag_id = '$value' WHERE project_id = $destinationPid and arm = $arm and record = '$record'");


                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    $source->updateNotes($config, $msg);
                    return false;
                }
                //
                //$this->setDAG(array_pop($result['ids']), $this->getDagId(), $destinationPid, $event_id);
            }
            // Call save_record hook on destination?
            if ($destinationSaveRecordHook[0]) {

                //last check if no event name is defined for destination then use event id obtained to get event name
                if (!$this->getEventName()) {
                    $this->setEventName(\REDCap::getEventNames(true, true,
                        $this->getEventId()));
                }

                $this->emDebug('About to call saveRecordHook in event ' . $this->getEventId() );

                // REDCap Hook injection point: Pass project_id and record name to method
                // \Hooks::call('redcap_save_record', array($destinationPid, $destination_id, $_GET['page'], $destination_event_name, $group_id, null, null, $_GET['instance']));

                if(!empty($this->getProjectId())) {
                    $result = \Hooks::call('redcap_save_record',
                        array(
                            $this->getProjectId(),
                            $this->getRecordId(),
                            filter_var($_GET['page'], FILTER_SANITIZE_STRING),
                            $this->getEventId(),
                            null,
                            null,
                            null,
                            null
                        )
                    );
                    $this->emDebug('MMD Save Record Hook Result');
                } else {
                    $this->emError('MMD Save Record Hook has no project id, skipping save record call (likely a configuration issue) ');
                }


            } else {
                $this->emDebug('No save record hook');
            }


            $this->migrateFiles($source);

            // check if any survey is defined and save link to source.
            $this->processSurvey($config, $source);

            return true;
        }
    }

    /**
     * @param array $config
     * @param \Stanford\MirrorSourceDataModule\Source $source
     */
    private function processSurvey($config, $source)
    {
        if ($this->isGenerateSurvey() && $this->getSurvey()) {
            // lets generate survey url for the instrument we defined in the config.json
            $link = \REDCap::getSurveyLink($this->getRecordId(), $this->getSurvey(), $this->getEventId(), 1,
                $this->getProjectId());

            $source->saveSurveyURL($config, $link);
//            header('Location: ' . $link);
//            exit;
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
        $i = $this->initiateRecordId($q, $prefix, $padding);
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

    private function initiateRecordId($query, $prefix, $padding)
    {
        if ($this->isIncrementRecordId()) {

            // in case we have prefix we only want to records with that prefix not whole list.
            if ($prefix) {
                $array = array();
                foreach (array_keys($query) as $item) {
                    if (strncmp($item, $prefix, strlen($prefix)) === 0) {
                        $array[] = $item;
                    }
                }
                if (!empty($array)) {
                    $id = $array[count($array) - 1];
                } else {
                    $id = 1;
                }
            } else {
                $id = array_keys($query)[count($query) - 1];
            }

            // first step is to remove prefix
            $id = str_replace($prefix, '', $id);

            // if padding exist then trim the id.
            if ($padding) {
                $id = ltrim('0', $id);
            }

            return $id;
        }
        return 1;
    }

    /**
     * check if destination record already saved before
     * @return bool
     */
    public function isRecordIdExist()
    {
        $results = REDCap::getData($this->getProjectId(), 'json', $this->getRecordId(), null, $this->getEventName());
        if(!is_array($results)){
            $results = json_decode($results, true);
        }
        $target_results = current($results);
        if ((!empty($target_results))) {
            return true;
        }
        return false;
    }

    /**
     * get destination arm for current event
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
     * @param \Stanford\MirrorSourceDataModule\Source $source
     * @param null $dagId
     */
    public function prepareDestinationRecord($config, $source, $dagId = null)
    {
        /**
         * let check if source record is in a DAG, if so lets find the corresponding Destination DAG and update the data accordingly
         */
        // if (($config['same-dags-name'] || !empty($config['source-destination-dag-map'])) && strpos($source->getRecordId(),'-') !== false && $this->getDestination()->isChangeRecordId()) {
        if (($config['same-dags-name'] || !empty($config['source-destination-dag-map'])) && !empty($dagId) && $this->isChangeRecordId()) {
            //set destination record id based on dag information saved inside the hook
            $this->setRecordId($this->getNextRecordDAGID($this->getProjectId(), $dagId));

            //set destination record based on source record
            $record = $source->getRecord();

            //modify record id in value to use same value we got in the above tow lines them set the destination record
            $record[$this->getPrimaryKey()] = $this->getRecordId();
            $this->setRecord($record);

        } else {
            //make sure the record we are saving into destination project is the one we pulled from source. with specified fields.
            $this->setRecord($source->getRecord());
        }
    }

    /**
     * @param \Stanford\MirrorSourceDataModule\Source $source
     */
    private function migrateFiles($source){
        $project = $source->getProject();
        $sourceFields = $project->metadata;
        $record = $this->getRecord();
        foreach ($record as $field => $value){
            if($sourceFields[$field]['element_type'] == 'file'){
                $newDocId = copyFile($value, $this->getProjectId());
                $projectId = $this->getProjectId();
                $event = $this->getEventId();
                $recordId = $this->getRecordId();
                $data_table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($projectId) : "redcap_data";

                //Save file skip uploaded files values for some reason. so you need to save these values manually.
                $sql = "insert into $data_table (`value`, project_id, event_id, record, field_name) values ('$newDocId' , '{$projectId}', '{$event}','" . db_escape($recordId) . "','{$field}')";
                db_query($sql);

            }
        }
        $this->setRecord($record);
    }

    /**
     * if dag is defined then put this id as fall back before run getDestinationRecordId which might change the reocrdi id based  on admin configuration
     * @param int $dagId
     * @return int
     */
    private function getNextRecordDAGID($destinationProjectId, $dagId)
    {
        $data_table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($destinationProjectId) : "redcap_data";

        $sql = "SELECT MAX(record) as recordId FROM $data_table WHERE field_name = '__GROUPID__' AND `value` = $dagId AND project_id = '$destinationProjectId'";
        $q = db_query($sql);

        $row = db_fetch_row($q);
        if (!empty($row)) {
            $parts = explode("-", $row[0]);
            if(is_int(end($parts))){
                $recordId = intval(end($parts)) + 1;
            }else{
                // if record for dag is a string. xyz_abc

                $recordId = end($parts) . '_' . rand();
                error_log('Mirror Destination string record with dag:' . $recordId);
            }

            $this->setDagRecordId($dagId . "-" . $recordId);
        } else {
            $this->setDagRecordId($dagId . "-" . 1);
        }
        return $this->getDagRecordId();
    }


    /**
     * this function will set destination record id(which might already be set by getNextRecordDAGID) in case configuration is different
     * @param $config
     * @param \Stanford\MirrorSourceDataModule\Source $source
     * @return bool
     */
    public function prepareRecordId($config, $source, $dagId = null)
    {
        $destinationId = null;

        // Method for creating the destination id (destination-id-create-new, destination-id-like-source, destination-id-source-specified)
        $destinationIdSelect = $config['destination-id-select'];

        //get primary key for TARGET project
        $destinationPrimaryKey = $this->getPrimaryKey();

        //$this->emDebug($source->getRecordId(), $this->getProjectId(), $destinationIdSelect, $destinationPrimaryKey);
        $destinationId = '';
        switch ($destinationIdSelect) {
            case 'destination-id-like-source':
                $destinationId = $source->getRecordId();
                break;
            case 'destination-id-source-specified':
                $destination_id_source_specified_field = $config['destination-id-source-specified-field'];

                //get data from source for the value in this field
                $results = REDCap::getData('json', $source->getRecordId(),
                    array($destination_id_source_specified_field),
                    $source->getEventName());
                $results = json_decode($results, true);
                $existing_target_data = current($results);

                $destinationId = $existing_target_data[$destination_id_source_specified_field];
//                $this->emDebug($existing_target_data, $destination_id_source_specified_field,
//                    $this->getRecordId(),
//                    "SOURCE SPECIFIED DESTINATION ID: " . $this->getRecordId());
                break;
            case 'destination-id-create-new':

                //if destination record id is was set from previous iteration of sub-setting loop then use that instead so the data is saved to save record
                if ($this->isChangeRecordId()) {
                    $destinationIdPrefix = $config['destination-id-prefix'];
                    $destinationIDPadding = $config['destination-id-padding'];

                    /**
                     * in case we are adding to DAG just keep whatever we already retrieved
                     */
                    if ($this->getDagRecordId() != null) {
                        $destinationId = $this->getDagRecordId();
                        // Make a padded number
                        if ($destinationIDPadding) {
                            // make sure we haven't exceeded padding, pad of 2 means
                            //$max = 10^$padding;
                            $max = 10 ** $destinationIDPadding;
                            if ($destinationId >= $max) {
                                $this->emLog("Error - $destinationId exceeds max of $max permitted by padding of $destinationIDPadding characters");
                                return false;
                            }
                            $destinationId = str_pad($destinationId, $destinationIDPadding, "0", STR_PAD_LEFT);
                            //$this->emLog("Padded to $padding for $i is $id");
                        }
                        //does prefix is wrapped with square brackets ? if so build the custom prefix.
                        preg_match("/\[.*?\]/", $destinationIdPrefix, $matches);
                        if (!empty($matches)) {
                            $string = $matches[0];
                            $string = str_replace(array('[', ']'), '', $string);
                            $parts = explode(":", $string);
                            $r = $this->buildCustomPrefix($parts[0], end($parts), $this->getProjectId(), $dagId);
                            $destinationIdPrefix = str_replace($matches[0], $r, $destinationIdPrefix);
                        }
                        $destinationId = $destinationIdPrefix . $destinationId;
                    } else {
                        //get next id from destination project
                        $destinationId = $this->getNextRecordId($destinationIdPrefix, $destinationIDPadding);
                    }
                }
                break;
            default:
                $destinationId = $this->getNextRecordId();
        }

        $this->setRecordId($destinationId);
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
                        return MirrorSourceDataModule::getProjectDAGName($projectId, $value);
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

    /**
     * @return bool
     */
    public function isGenerateSurvey()
    {
        return $this->generateSurvey;
    }

    /**
     * @param bool $generateSurvey
     */
    public function setGenerateSurvey($generateSurvey)
    {
        $this->generateSurvey = $generateSurvey;
    }

    /**
     * @return string
     */
    public function getSurvey()
    {
        return $this->survey;
    }

    /**
     * @param string $survey
     */
    public function setSurvey($survey)
    {
        $this->survey = $survey;
    }

    /**
     * @return bool
     */
    public function isIncrementRecordId()
    {
        return $this->incrementRecordId;
    }

    /**
     * @param bool $incrementRecordId
     */
    public function setIncrementRecordId($incrementRecordId)
    {
        $this->incrementRecordId = $incrementRecordId;
    }


}
