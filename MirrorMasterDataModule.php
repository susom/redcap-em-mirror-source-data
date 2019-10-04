<?php

namespace Stanford\MirrorMasterDataModule;


include_once 'emLoggerTrait.php';
include_once 'Master.php';
include_once 'Child.php';

use Project;
use REDCap;
use Records;
use Sabre\DAV\Exception;

/**
 * Class MirrorMasterDataModule
 * @package Stanford\MirrorMasterDataModule
 * @property Master $master
 * @property Child $child
 * @property array $masterDAGs
 * @property array $childrenDAGs
 * @property array $dagMaps
 * @property string $triggerEvent
 * @property string $triggerInstrument
 * @property array $migrationFields
 * @property int $dagId
 * @property string $dagRecordId
 */
class MirrorMasterDataModule extends \ExternalModules\AbstractExternalModule
{

    /*

    AM- If you already have a value for the child_id in the parent project and you have clobber on, should you
    just update or create another child record with a new id?



     */
    use emLoggerTrait;

    // PARENT INFO
    /**
     * @var Master
     */
    private $master;

    /**
     * @var Child
     */
    private $child;

    /**
     * @var array
     */
    private $masterDAGs;

    /**
     * @var array
     */
    private $childrenDAGs;

    /**
     * @var array
     */
    private $dagMaps;

    /**
     * @var string
     */
    private $triggerEvent;

    /**
     * @var string
     */
    private $triggerInstrument;

    /**
     * @var array
     */
    private $migrationFields;

    /**
     * @var string
     */
    private $dagId;

    /**
     * @var string
     */
    private $dagRecordId;

    /**
     * @return array
     */
    public function getMigrationFields()
    {
        return $this->migrationFields;
    }

    /**
     * @param array $migrationFields
     */
    public function setMigrationFields($migrationFields)
    {
        $this->migrationFields = $migrationFields;
    }

    /**
     * @return Master
     */
    public function getMaster()
    {
        return $this->master;
    }

    /**
     * @param Master $master
     */
    public function setMaster($master)
    {
        $this->master = $master;
    }

    /**
     * @return Child
     */
    public function getChild()
    {
        return $this->child;
    }

    /**
     * @param Child $child
     */
    public function setChild($child)
    {
        $this->child = $child;
    }

    /**
     * @return array
     */
    public function getMasterDAGs()
    {
        return $this->masterDAGs;
    }

    /**
     */
    public function setMasterDAGs()
    {
        $this->masterDAGs = $this->getProjectSetting("master-dag");
    }

    /**
     * @return array
     */
    public function getChildrenDAGs()
    {
        return $this->childrenDAGs;
    }

    /**
     */
    public function setChildrenDAGs()
    {
        $this->childrenDAGs = $this->getProjectSetting("child-dag");
    }

    /**
     * @return array
     */
    public function getDagMaps()
    {
        return $this->dagMaps;
    }

    /**
     * @param array $dagMaps
     */
    public function setDagMaps($dagMaps)
    {
        $this->dagMaps = $dagMaps;
    }

    /**
     * @return string
     */
    public function getTriggerEvent()
    {
        return $this->triggerEvent;
    }

    /**
     * @param string $triggerEvent
     */
    public function setTriggerEvent($triggerEvent)
    {
        $this->triggerEvent = $triggerEvent;
    }

    /**
     * @return string
     */
    public function getTriggerInstrument()
    {
        return $this->triggerInstrument;
    }

    /**
     * @param string $triggerInstrument
     */
    public function setTriggerInstrument($triggerInstrument)
    {
        $this->triggerInstrument = $triggerInstrument;
    }


    /**
     * @return mixed
     */
    public function getDagId()
    {
        return $this->dagId;
    }

    /**
     * @param mixed $dagId
     */
    public function setDagId($dagId)
    {
        $this->dagId = $dagId;
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
     * map master and child dags. $key is used to link between current sub-setting instance to the dag instance.
     * @param array $config
     * @param int $key
     */
    private function processMapDAGs($config, $key)
    {

        /**
         * if same dags names between master and child is checked:
         * 1. will check if map is defined manually for each dags in master and child
         * 2. if dags in master has no manual mapping in config.json them try to find dag with same name in child project.
         */
        if ($config['same-dags-name']) {
            $masterDags = $this->getProjectDags($this->getProjectId());
            while ($row = db_fetch_assoc($masterDags)) {
                //in case no match let check if dags map is manually defined
                $childDagIndex = $this->searchForDAGIndex($this->getMasterDAGs()[$key], $row['group_id']);
                if (!is_null($childDagIndex)) {
                    $dags[] = array(
                        "master" => $row['group_id'],
                        "child" => $this->getProjectDAGID($config['child-project-id'],
                            $this->getChildrenDAGs()[$key][$childDagIndex])
                    );
                } else {
                    //search if master dag name match dag name in child project.
                    $childDag = $this->getProjectDags($config['child-project-id'], $row['group_name']);
                    if ($childDag) {
                        $childRow = db_fetch_assoc($childDag);
                        $dags[] = array("master" => $row['group_id'], "child" => $childRow['group_id']);
                        $config['master-child-dags'] = json_encode($dags);
                    }
                }
            }
        } else {
            if ($config['master-child-dag-map']) {
                foreach ($config['master-child-dag-map'] as $instanceKey => $instance) {
                    $dags[] = array(
                        "master" => $this->getMasterDAGs()[$key][$instanceKey],
                        "child" => $this->getProjectDAGID($config['child-project-id'],
                            $this->getChildrenDAGs()[$key][$instanceKey])
                    );
                }
            }

        }
        if (!empty($dags)) {
            $this->setDagMaps($dags);
        }
    }

    /**
     * Hook method which gets called at save
     *
     * @param $project_id
     * @param null $record
     * @param $instrument
     * @param $event_id
     * @param null $group_id
     * @param null $survey_hash
     * @param null $response_id
     * @param int $repeat_instance
     */
    public function hook_save_record(
        $project_id,
        $record,
        $instrument,
        $event_id,
        $group_id
    ) {

        //Initiate Master Entity and all information for Master Project will be saved there and you can access it via getter methods DO NOT ACCESS direct parameters

        $this->setMaster(new Master($project_id, $event_id, $record, $instrument));
        $this->emDebug(
            "PROJECT: $project_id",
            "RECORD: $record",
            "EVENT_ID: $event_id",
            "INSTRUMENT: $instrument",
            "REDCAP_EVENT_NAME: " . $this->getMaster()->getEventName());

        // Loop through each MMD Setting
        $subsettings = $this->getSubSettings('mirror-instances');

        //flag to run finalMirrorProcess
        $final = false;
        /**
         * we are getting master/child dags instances outside sub-setting because REDCap return true/false instead of values inside sub-setting for Master/Child dags.
         */
        $this->setMasterDAGs();

        $this->setChildrenDAGs();

        foreach ($subsettings as $key => $config) {
            /**
             * if dags mapping is defined/enabled process the map between master and current child project in sub-setting
             */
            if ($config['same-dags-name'] || !empty($config['master-child-dag-map'])) {
                $this->processMapDAGs($config, $key);
            }

            /**
             * in case we have DAGs mapped loop over all of them and insert only the one user belongs to.
             */
            if (!empty($this->getDagMaps()) && !is_null($group_id)) {
                foreach ($this->getDagMaps() as $dag) {
                    $config['master-child-dags'] = json_encode($dag);
                    $this->setDagId($dag['child']);
                    $this->emDebug("config", $config);
                    //check if user inside current dag
                    if (($config['same-dags-name'] || !empty($config['master-child-dag-map'])) && !$this->isUserInDAG($config['child-project-id'],
                            USERID,
                            $this->getDagId())) {
                        //we are in wrong DAG
                        continue;
                    }
                    $final = $this->mirrorData($config);
                }
            } else {
                $this->emDebug("config", $config);
                $final = $this->mirrorData($config);
            }
        }
        //when done sub-setting loop check if mirror completed if so run finilize mirror.
        if ($final) {
            $this->finalizeMirrorProcess();
        }
    }

    /**
     * this function will return array key index for specific dag from dag map
     * @param $dags
     * @param $name
     * @return int|string|null
     */
    private function searchForDAGIndex($dags, $name)
    {
        foreach ($dags as $key => $dag) {
            if ($dag == $name) {
                return $key;
            }
        }
        return null;
    }

    /**
     * check if user is in the passed dag
     * @param int $projectId
     * @param string $username
     * @param int $group_id
     * @return bool
     */
    private function isUserInDAG($projectId, $username, $group_id)
    {
        $sql = "SELECT username FROM redcap_user_rights WHERE username = '$username' AND group_id = $group_id AND project_id = $projectId";
        $q = db_query($sql);

        $row = db_fetch_row($q);
        if (!empty($row)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * check If clobber is defined and if child record is already migrated
     * @param array $config
     * @return bool
     */
    private function checkMigrationCompleted($config)
    {
        //$this->emLog("Found trigger form ". $trigger_form);

        //1. CHECK if migration has already been completed
        $parent_fields = array_filter(array(
            $config['migration-timestamp'],
            $config['parent-field-for-child-id']
        ));

        $results = REDCap::getData('json', $this->getMaster()->getRecordId(), $parent_fields);
        $results = json_decode($results, true);
        $parentData = current($results);

        //check if timestamp and child_id are set (both? just one?)
        //if exists, then don't do anything since already migrated
        $migration_timestamp = $parentData[$config['migration-timestamp']];

        $child_field_clobber = $config['child-field-clobber'];

        //migration_timestamp already has a value and Child_field_clobber is not set
        if (!empty($migration_timestamp) && ($this->getChild()->isFieldClobber())) {
            // Timestamp present - do not re-migrate
            $existing_msg = "No data migration: Clobber not turned on and migration already completed for record "
                . $this->getMaster()->getRecordId() . " to child project " . $this->getChild()->getProjectId();
            $this->emDebug($existing_msg);

            // //reset the migration timestamp and child_id
            $log_data = array();
            $log_data[$config['parent-field-for-child-id']] = '';
            $log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
            //
            // //UPDATE PARENT LOG
            // This was to-do added by Andy so I added it to update master record that migration did not happened because it already done before.
            $this->updateNoteInParent($this->getMaster()->getRecordId(), $this->getMaster()->getPrimaryKey(), $config,
                $existing_msg, $log_data);

            return false;
        }

        $this->emDebug("Doing data migration",
            "Clobber: " . $child_field_clobber,
            "Migration timestamp: " . $migration_timestamp,
            "Record: " . $this->getMaster()->getRecordId(),
            "Child project: " . $this->getChild()->getProjectId()
        );

        return true;
    }

    /**
     * evaluate logic if exist in the sub-setting configuration
     * @param array $config
     * @return bool
     */
    private function processMirrorLogic($config)
    {
        //2. CHECK IF LOGIC IS TRIGGERED
        if (!empty($config['mirror-logic'])) {
            $result = REDCap::evaluateLogic($config['mirror-logic'], $this->getMaster()->getProjectId(),
                $this->getMaster()->getRecordId(),
                $this->getMaster()->getEventName());
            if ($result === false) {
                $this->emDebug("Logic False - Skipping");
                return false;
            }
        }
        return true;
    }

    /**
     * this function will be executed when child project completed migration or sub-setting loop is completed
     */
    private function finalizeMirrorProcess()
    {
        $master = $this->getMaster()->getRecord();
        $config = $this->getChild()->getConfig();
        $msg = "Successfully migrated.";
        if (!empty($config['parent-field-for-child-id'])) {
            $master[$config['parent-field-for-child-id']] = $this->getChild()->getRecordId();
        }
        if (!empty($config['migration-timestamp'])) {
            $master[$config['migration-timestamp']] = date('Y-m-d H:i:s');
        }

        //7. UPDATE PARENT Note
        $this->updateNoteInParent($this->getMaster()->getRecordId(), $this->getMaster()->getPrimaryKey(), $config, $msg,
            $master);

    }

    /**
     * Migrate data for child project specified in $config parameter
     * @param $config
     * @return bool
     */
    private function mirrorData($config)
    {

        //set child id and project object if child not defined or child-project-id is different from current child-project-id
        if (!$this->getChild() || $this->getChild()->getProjectId() != $config['child-project-id']) {
            /**
             * before we change child object lets update parent notes using the child configuration.
             */
            if ($this->getChild() && $this->getChild()->getProjectId() != $config['child-project-id']) {
                $this->finalizeMirrorProcess();
            } else {
                $this->setChild(new Child($config['child-project-id']));
                //save configuration so when we are done with this child we can update parent note.
                $this->getChild()->setConfig($config);
            }
        } else {
            $this->getChild()->setChangeRecordId(false);
        }

        /**
         * for child we have only event name if defined. if so lets get event id then set it in child object.
         */
        if ($config['child-event-name'] != '') {
            $this->getChild()->setEventName($config['child-event-name']);

            $this->getChild()->setEventId($this->getChild()->getProject()->getEventIdUsingUniqueEventName($config['child-event-name']));
        }

        //$child_pid = ;


        /**
         * set flag to be used later in the execution.
         */
        $this->getChild()->setFieldClobber($config['child-field-clobber']);

        //0. CHECK if in right EVENT (only applies if master-event-name is not null)
        $this->setTriggerEvent($config['master-event-name']);

        $this->emDebug("Trigger event", $this->getTriggerEvent(), "Current Event", $this->getMaster()->getEventId());

        // If event is specified but different than current event, then we can abort
        if ((!empty($this->getTriggerEvent())) && ($this->getTriggerEvent() != $this->getMaster()->getEventId())) {
            $this->emDebug("Aborting - wrong event");
            return false;
        }

        //0. CHECK if in the right instrument
        $this->setTriggerInstrument($config['trigger-form']);

        if ((!empty($this->getTriggerInstrument())) && ($this->getTriggerInstrument() != $this->getMaster()->getInstrument())) {
            $this->emDebug("Aborting - wrong instrument");
            return false;
        }

        //check if mirror processed already
        if (!$this->checkMigrationCompleted($config)) {
            return false;
        }


        //verify logic
        if (!$this->processMirrorLogic($config)) {
            return false;
        }


        /**
         * intersect master with child fields based on 'fields-to-migrate' and current event selection. also include/exclude fields. this set the fields to $migrationFields
         */

        $this->getIntersectFields($config);

        $this->emDebug("Intersection is " . count($this->getMigrationFields()));

        if (empty($this->getMigrationFields())) {
            //Log msg in Notes field
            $msg = "There were no intersect fields between the parent (" . $this->getMaster()->getProjectId() .
                ") and child projects (" . $this->getChild()->getProjectId() . ").";
            $this->emDebug($msg);
            //reset the migration timestamp and child_id

            // TODO: ABM - I DONT UNDERSTAND WHY WE RESET HERE?
            $log_data = array();
            $log_data[$config['parent-field-for-child-id']] = '';
            $log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
            $this->updateNoteInParent($this->getMaster()->getRecordId(), $this->getMaster()->getPrimaryKey(), $config,
                $msg, $log_data);
            return false;
        }

        //4. Get data from master to be saved on child

        //set master record based on selected fields
        $results = REDCap::getData('json', $this->getMaster()->getRecordId(), $this->getMigrationFields(),
            $this->getMaster()->getEventName());
        $results = json_decode($results, true);
        $this->getMaster()->setRecord(current($results));


        /**
         * let check if parent record is in a DAG, if so lets find the corresponding Child DAG and update the data accordingly
         */
        if (($config['same-dags-name'] || !empty($config['master-child-dag-map'])) && strpos($this->getMaster()->getRecordId(),
                '-') !== false && $this->getChild()->isChangeRecordId()) {
            //set child record id based on dag information saved inside the hook
            $this->getChild()->setRecordId($this->getNextRecordDAGID($this->getChild()->getProjectId(),
                $this->getDagId()));

            //set child record based on master record
            $record = $this->getMaster()->getRecord();

            //modify record id in value to use same value we got in the above tow lines them set the child record
            $record[$this->getMaster()->getPrimaryKey()] = $this->getChild()->getRecordId();
            $this->getChild()->setRecord($record);

        } else {
            //make sure the record we are saving into child project is the one we pulled from master. with specified fields.
            $this->getChild()->setRecord($this->getMaster()->getRecord());
        }
        // $this->emDebug("DATA FROM PARENT INTERSECT FIELDS", $parentData);

        //5.5 Determine the ID in the CHILD project.
        $this->getChildRecordId($config);

        if ((!empty($this->getChild()->isRecordIdExist())) && ($this->getChild()->isFieldClobber() != '1')) {
            //Log no migration
            $msg = "Error creating record in TARGET project. ";
            $msg .= "Target ID, " . $this->getChild()->getRecordId() . ", already exists in Child project " . $this->getChild()->getProjectId() . " and clobber is set to false (" . $this->getChild()->isFieldClobber() . ").";
            $this->emDebug($msg);
        } else {
            //$this->emDebug("PROCEED: Target $child_id does not exists (" . count($target_results) . ") in $child_pid or clobber true ($child_field_clobber).");

            //5. SET UP CHILD PROJECT TO SAVE DATA
            //TODO: logging variable needs to be done separately because of events
            //GET logging variables from target project
            $child_field_for_parent_id = $config['child-field-for-parent-id'];


            //add additional fields to be added to the SaveData call
            //set the new ID
            $record = $this->getChild()->getRecord();
            $record[$this->getChild()->getPrimaryKey()] = $this->getChild()->getRecordId();

            //if child event id defined add it to child record
            if (!empty($this->getChild()->getEventName())) {
                $record['redcap_event_name'] = ($this->getChild()->getEventName());
            }

            //enter logging field for child-field-for-parent-id
            if (!empty($config['child-field-for-parent-id'])) {
                $record[$config['child-field-for-parent-id']] = $this->getMaster()->getRecordId();
            }
            $this->getChild()->setRecord($record);
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
            $result = \REDCap::saveData($this->getChild()->getProjectId(),
                'json',
                json_encode(array($this->getChild()->getRecord())),
                ($this->getChild()->isFieldClobber() == '1') ? 'overwrite' : 'normal',
                'YMD',
                'flat');

            //$result = call_user_func_array(array("Records", "saveData"), $args);

            $this->emDebug("SAVE RESULT", $result);


            // Check for upload errors
            if (!empty($result['errors'])) {
                $msg = "Error creating record in CHILD project " . $this->getChild()->getProjectId() . " - ask administrator to review logs: " . print_r($result['errors'],
                        true);
                $this->emError($msg);
                $this->emError("CHILD ERROR", $result);
            } else {
                /**
                 * let check if parent record is in a DAG, if so lets find the corresponding Child DAG and update the data accordingly
                 */
                if (($config['same-dags-name'] || !empty($config['master-child-dag-map'])) && !empty($this->getDagId())) {

                    try {
                        //get first event in case child event name is not  defined.
                        if ($this->getChild()->getEventName() == "" || $this->getChild()->getEventName() == null) {
                            $this->getChild()->setEventId($this->getFirstEventId($this->getChild()->getProjectId()));

                            //at this point no need for event name because everything is saved we just want to save dag information
                        }
                        /**
                         * temp solution till pull request is approved by Venderbilt
                         */
                        $record = $this->getChild()->getRecordId();
                        $value = $this->getDagId();
                        $fieldName = '__GROUPID__';
                        $child_pid = $this->getChild()->getProjectId();
                        $event_id = $this->getChild()->getEventId();
                        $this->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, value) VALUES ($child_pid, $event_id, '$record', '$fieldName', '$value')");

                        //get child event arm to be used to update id for the dropdown
                        $arm = $this->getChild()->getArm();

                        //just update record list. only for Record Status Dashboard dropdown
                        $this->query("UPDATE redcap_record_list SET dag_id = '$value' WHERE project_id = $child_pid and arm = $arm and record = '$record'");


                    } catch (\Exception $e) {
                        $msg = $e->getMessage();
                        $this->updateNoteInParent($this->getMaster()->getRecordId(),
                            $this->getMaster()->getPrimaryKey(), $config, $msg);
                        return false;
                    }
                    //
                    //$this->setDAG(array_pop($result['ids']), $this->getDagId(), $child_pid, $event_id);
                }
                // Call save_record hook on child?
                $child_save_record_hook = $this->getProjectSetting('child-save-record-hook');
                if ($child_save_record_hook) {

                    //last check if no event name is defined for child then use event id obtained to get event name
                    if (!$this->getChild()->getEventName()) {
                        $this->getChild()->setEventName(\REDCap::getEventNames(true, true,
                            $this->getChild()->getEventId()));
                    }

                    // REDCap Hook injection point: Pass project_id and record name to method
                    // TODO - Handle child group ID
                    // \Hooks::call('redcap_save_record', array($child_pid, $child_id, $_GET['page'], $child_event_name, $group_id, null, null, $_GET['instance']));
                    \Hooks::call('redcap_save_record',
                        array(
                            $this->getChild()->getProjectId(),
                            $this->getChild()->getRecordId(),
                            $_GET['page'],
                            $this->getChild()->getEventName(),
                            null
                        ));
                }
            }
        }

        return true;
    }

    /**
     * get dag name using its id
     * @param $projectId
     * @param $id
     * @return bool
     */
    private function getProjectDAGName($projectId, $id)
    {
        $sql = "SELECT group_name FROM redcap_data_access_groups WHERE project_id = '$projectId' AND group_id = '$id'";
        $q = db_query($sql);

        if (db_num_rows($q) > 0) {
            $row = db_fetch_assoc($q);
            return $row['group_name'];
        } else {
            return false;
        }
    }

    /**
     * get dag id using its name
     * @param $projectId
     * @param $name
     * @return bool
     */
    private function getProjectDAGID($projectId, $name)
    {
        $sql = "SELECT group_id FROM redcap_data_access_groups WHERE project_id = '$projectId' AND group_name = '$name'";
        $q = db_query($sql);

        if (db_num_rows($q) > 0) {
            $row = db_fetch_assoc($q);
            return $row['group_id'];
        } else {
            return false;
        }
    }

    /**
     * get project dag information
     * @param int $projectId
     * @param null $name
     * @return bool|\mysqli_result
     */
    private function getProjectDags($projectId, $name = null)
    {
        if (is_null($name)) {
            $sql = "SELECT * FROM redcap_data_access_groups WHERE  project_id = $projectId";
        } else {
            $sql = "SELECT * FROM redcap_data_access_groups WHERE LOWER(group_name) = '$name' AND project_id = $projectId";
        }

        $q = db_query($sql);

        if (db_num_rows($q) > 0) {
            return $q;
        } else {
            return false;
        }
    }

    /**
     * if dag is defined then put this id as fall back before run getChildRecordId which might change the reocrdi id based  on admin configuration
     * @param int $dagId
     * @return int
     */
    private function getNextRecordDAGID($childProjectId, $dagId)
    {
        $sql = "SELECT MAX(record) as record_id FROM redcap_data WHERE field_name = '__GROUPID__' AND `value` = $dagId AND project_id = '$childProjectId'";
        $q = db_query($sql);

        $row = db_fetch_row($q);
        if (!empty($row)) {
            $parts = explode("-", $row[0]);
            $record_id = end($parts) + 1;
            $this->setDagRecordId($dagId . "-" . $record_id);
        } else {
            $this->setDagRecordId($dagId . "-" . 1);
        }
        return $this->getDagRecordId();
    }


    /**
     * this function will set child record id(which might already be set by getNextRecordDAGID) in case configuration is different
     * @param array $config
     */
    private function getChildRecordId($config)
    {
        $child_id = null;

        // Method for creating the child id (child-id-create-new, child-id-like-parent, child-id-parent-specified)
        $child_id_select = $config['child-id-select'];

        //get primary key for TARGET project
        $child_pk = $this->getChild()->getPrimaryKey();

        $this->emDebug($this->getMaster()->getRecordId(), $this->getChild()->getProjectId(), $child_id_select,
            $child_pk);

        switch ($child_id_select) {
            case 'child-id-like-parent':
                $this->getChild()->setRecordId($this->getMaster()->getRecordId());
                break;
            case 'child-id-parent-specified':
                $child_id_parent_specified_field = $config['child-id-parent-specified-field'];

                //get data from parent for the value in this field
                $results = REDCap::getData('json', $this->getMaster()->getRecordId(),
                    array($child_id_parent_specified_field),
                    $this->getMaster()->getEventName());
                $results = json_decode($results, true);
                $existing_target_data = current($results);

                $this->getChild()->setRecordId($existing_target_data[$child_id_parent_specified_field]);
                $this->emDebug($existing_target_data, $child_id_parent_specified_field,
                    $this->getChild()->getRecordId(),
                    "PARENT SPECIFIED CHILD ID: " . $this->getChild()->getRecordId());
                break;
            case 'child-id-create-new':

                //if child record id is was set from previous iteration of sub-setting loop then use that instead so the data is saved to save record
                if ($this->getChild()->isChangeRecordId()) {
                    $child_id_prefix = $config['child-id-prefix'];
                    $child_id_padding = $config['child-id-padding'];

                    /**
                     * in case we are adding to DAG just keep whatever we already retrieved
                     */
                    if ($this->getDagRecordId() != null) {
                        $child_id = $this->getDagRecordId();
                        // Make a padded number
                        if ($child_id_padding) {
                            // make sure we haven't exceeded padding, pad of 2 means
                            //$max = 10^$padding;
                            $max = 10 ** $child_id_padding;
                            if ($child_id >= $max) {
                                $this->emLog("Error - $child_id exceeds max of $max permitted by padding of $child_id_padding characters");
                                return false;
                            }
                            $child_id = str_pad($child_id, $child_id_padding, "0", STR_PAD_LEFT);
                            //$this->emLog("Padded to $padding for $i is $id");
                        }
                        //does prefix is wrapped with square brackets ? if so build the custom prefix.
                        preg_match("/\[.*?\]/", $child_id_prefix, $matches);
                        if (!empty($matches)) {
                            $string = $matches[0];
                            $string = str_replace(array('[', ']'), '', $string);
                            $parts = explode(":", $string);
                            $r = $this->buildCustomPrefix($parts[0], end($parts), $this->getChild()->getProjectId(),
                                $this->getDagId());
                            $child_id_prefix = str_replace($matches[0], $r, $child_id_prefix);
                        }
                        $child_id = $child_id_prefix . $child_id;
                    } else {
                        //get next id from child project
                        $child_id = $this->getChild()->getNextRecordId($child_id_prefix, $child_id_padding);
                    }
                    $this->getChild()->setRecordId($child_id);
                }
                break;
            default:
                $child_id = $this->getChild()->getNextRecordId();
                $this->getChild()->setRecordId($child_id);
        }
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
                        return $this->getProjectDAGName($projectId, $value);
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
     * @param Master | Child $project
     */
    private function getProjectFields($project)
    {
        /**
         * if event is defined use it otherwise use first event ID
         */
        if ($project->getEventId()) {
            $instruments = $project->getProject()->eventsForms[$project->getEventId()];
        } else {
            $instruments = current($project->getProject()->eventsForms);
        }

        $result = array();
        foreach ($instruments as $instrument) {
            $fields = array_keys($project->getProject()->forms[$instrument]['fields']);
            if (empty($result)) {
                $result = $fields;
            } else {
                $result = array_merge($result, $fields);
            }
        }
        return $result;
    }

    /**
     * based on configuration find out the field will be migrated from master project into child project
     * @param array $config
     */
    private function getIntersectFields($config)
    {

        $arr_fields = array();
        //branching logic reset does not clear out old values - force clear here
        switch ($config['fields-to-migrate']) {
            case 'migrate-intersect':
                //get master instrument for current event
                $masterFields = $this->getProjectFields($this->getMaster());

                $childFields = $this->getProjectFields($this->getChild());

                $arr_fields = array_intersect($masterFields, $childFields);
                break;
            case 'migrate-intersect-specified':
                //get master instrument for current event
                $masterFields = $this->getProjectFields($this->getMaster());

                $childFields = $this->getProjectFields($this->getChild());

                //get all fields
                $arr_fields = array_intersect($masterFields, $childFields);

                //intersect with included only.
                $arr_fields = array_intersect($arr_fields, $config['include-only-fields']);

                break;
            case 'migrate-child-form':
                $arr_fields = $this->getMaster()->getProject()->forms[$config['include-only-form-parent']];
                break;
            case 'migrate-parent-form':
                $arr_fields = $this->getChild()->getProject()->forms[$config['include-only-form-child']];
                break;
        }


        //lastly remove exclude fields if specified
        if (count($config['exclude-fields']) > 1) {
            $arr_fields = array_diff($arr_fields, $config['exclude-fields']);
            //$this->emDebug($arr_fields, 'EXCLUDED arr_fields:');
        }

        $this->setMigrationFields($arr_fields);

    }

    /**
     * Bubble up status to user via the timestamp and notes field in the parent form
     * in config file as 'migration-notes'
     * @param $record_id : record_id of current record
     * @param $pk_field : the first field in parent project (primary key)
     * @param $config : config fields for migration module
     * @param $msg : Message to enter into Notes field
     * @param $parent_data : If child migration successful, data about migration to child (else leave as null)
     * @return bool        : return fail/pass status of save data
     */
    private function updateNoteInParent($record_id, $pk_field, $config, $msg, $parent_data = array())
    {
        //$this->emLog($parent_data, "DEBUG", "RECEIVED THIS DATA");
        $parent_data[$pk_field] = $record_id;
        if (isset($config['migration-notes'])) {
            $parent_data[$config['migration-notes']] = $msg;
        }

        if (!empty($config['master-event-name'])) {
            //assuming that current event is the right event
            //$this->emLog("Event name from REDCap::getEventNames : $master_event / EVENT name from this->redcap_event_name: ".$this->redcap_event_name);
            $parent_data['redcap_event_name'] = $this->getMaster()->getEventName(); //$config['master-event-name'];
        }

        //$this->emLog($parent_data, "Saving Parent Data");
        $result = REDCap::saveData(
            $this->getMaster()->getProjectId(),
            'json',
            json_encode(array($parent_data)),
            'overwrite');

        // Check for upload errors
        if (!empty($result['errors'])) {
            $msg = "Error creating record in PARENT project " . $this->getMaster()->getProjectId() . " - ask administrator to review logs: " . json_encode($result);
            //$sr->updateFinalReviewNotes($msg);
            //todo: bubble up to user : should this be sent to logging?
            $this->emError($msg);
            $this->emError("RESULT OF PARENT: " . print_r($result, true));
            //logEvent($description, $changes_made="", $sql="", $record=null, $event_id=null, $project_id=null);
            REDCap::logEvent("Mirror Master Data Module", $msg, null, $record_id, $config['master-event-name']);
            return false;
        }

    }
}

