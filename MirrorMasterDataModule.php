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
 * @property boolean $userInChildDag
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
     * @var boolean
     */
    private $userInChildDag = false;
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

        try {
            //Initiate Master Entity and all information for Master Project will be saved there and you can access it via getter methods DO NOT ACCESS direct parameters

            $this->setMaster(new Master($project_id, $event_id, $this->PREFIX, $record, $instrument));
            $this->emDebug(
                "PROJECT: $project_id",
                "RECORD: $record",
                "EVENT_ID: $event_id",
                "INSTRUMENT: $instrument",
                "REDCAP_EVENT_NAME: " . $this->getMaster()->getEventName());

            // Loop through each MMD Setting
            $subSettings = $this->getSubSettings('mirror-instances', $project_id);

            //flag to run finalMirrorProcess
            $final = false;
            /**
             * we are getting master/child dags instances outside sub-setting because REDCap return true/false instead of values inside sub-setting for Master/Child dags.
             */
            $this->setMasterDAGs();

            $this->setChildrenDAGs();

            foreach ($subSettings as $key => $config) {

                //verify logic
                if (!$this->processMirrorLogic($config)) {
                    continue;
                }
                /**
                 * if dags mapping is defined/enabled process the map between master and current child project in sub-setting
                 */
                if ($config['same-dags-name'] || !empty($config['master-child-dag-map'])) {
                    $this->processMapDAGs($config, $key);
                }

                /**
                 * in case we have DAGs mapped loop over all of them and insert only the one user belongs to. if su
                 */
                if (!empty($this->getDagMaps()) && !is_null($group_id)) {

                    foreach ($this->getDagMaps() as $dag) {
                        $config['master-child-dags'] = json_encode($dag);
                        $this->setDagId($dag['child']);
                        $this->emDebug("config", $config);


                        //exception when user is super user then add only the record to child dag mapped to the master dag
                        if (SUPER_USER && $dag['master'] == $group_id) {

                            $this->getMaster()->canUpdateNotes = $this->mirrorData($config);
                            $this->setUserInChildDag(true);
                        } else {
                            //check if user inside current dag
                            if (($config['same-dags-name'] || !empty($config['master-child-dag-map'])) && !$this->isUserInDAG($config['child-project-id'],
                                    USERID, $this->getDagId())) {
                                //we are in wrong DAG
                                continue;
                            } else {

                                $this->getMaster()->canUpdateNotes = $this->mirrorData($config);
                                $this->setUserInChildDag(true);
                            }
                        }
                    }

                    if (!$this->isUserInChildDag()) {
                        $this->getMaster()->updateNotes($config, USERID . " is not assigned to any child DAG");
                    }
                } else {
                    $this->emDebug("config", $config);
                    $this->getMaster()->canUpdateNotes = $this->mirrorData($config);
                }
            }
            //when done sub-setting loop check if mirror completed if so run finalize mirror.
            if ($this->getMaster()->canUpdateNotes) {
                $this->finalizeMirrorProcess();
                //so update is only done if true came from mirrordata function
                $this->getMaster()->canUpdateNotes = false;
            } else {
                if ($this->getMaster()->getProjectId() == 19800) {
                    $this->emLog($this->getMaster()->getRecord());
                }
            }
        } catch (\LogicException $e) {
            echo $e->getMessage();
        } catch (\Exception $e) {
            echo $e->getMessage();
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
        $migrationTimestamp = $parentData[$config['migration-timestamp']];

        $child_field_clobber = $config['child-field-clobber'];

        //migration_timestamp already has a value and Child_field_clobber is not set
        if (!empty($migrationTimestamp) && (!$this->getChild()->isFieldClobber())) {
            // Timestamp present - do not re-migrate
            $message = "No data migration: Clobber not turned on and migration already completed for record "
                . $this->getMaster()->getRecordId() . " to child project " . $this->getChild()->getProjectId() . '. To re-save the record in the child project please reset the value in following field: ' . $config['migration-timestamp'];
            $this->emDebug($message);

            // //reset the migration timestamp and child_id
            //$log_data = array();
            //$log_data[$config['parent-field-for-child-id']] = '';
            //$log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
            //
            // //UPDATE PARENT LOG
            // This was to-do added by Andy so I added it to update master record that migration did not happened because it already done before.
            $this->getMaster()->updateNotes($config, $message);

            return false;
        }

        $this->emDebug("Doing data migration",
            "Clobber: " . $child_field_clobber,
            "Migration timestamp: " . $migrationTimestamp,
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
        $this->getMaster()->updateNotes($config, $msg, $master);
    }

    /**
     * @param array $config
     */
    private function initiateChildProject($config)
    {
        //set child id and project object if child not defined or child-project-id is different from current child-project-id
        if (!$this->getChild() || $this->getChild()->getProjectId() != $config['child-project-id']) {
            /**
             * before we change child object lets update parent notes using the child configuration.
             */
            if ($this->getChild() && $this->getChild()->getProjectId() != $config['child-project-id']) {
                // we are holding the value on master object so when child object changed we check previous migration to previous child if succeeded.
                if ($this->getMaster()->canUpdateNotes) {
                    $this->finalizeMirrorProcess();
                }
                $this->setChild(new Child($config['child-project-id'], $this->PREFIX, $config['child-next-id-increment']));
                //save configuration so when we are done with this child we can update parent note.
                $this->getChild()->setConfig($config);
            } else {
                $this->setChild(new Child($config['child-project-id'], $this->PREFIX, $config['child-next-id-increment']));
                //save configuration so when we are done with this child we can update parent note.
                $this->getChild()->setConfig($config);
            }
        } else {
            //if this child object is used more than once time then prevent updating record id to save all information for same record in different events
            $this->getChild()->setChangeRecordId(false);
        }

        /**
         * for child we have only event name if defined. if so lets get event id then set it in child object.
         */
        if ($config['child-event-name'] != '') {
            $this->getChild()->setEventName($config['child-event-name']);

            $this->getChild()->setEventId($this->getChild()->getProject()->getEventIdUsingUniqueEventName($config['child-event-name']));
        } else {

            # if no event is specified make sure child use the first event id for the case when pulling next record id.
            $this->getChild()->setEventId($this->getFirstEventId($this->getChild()->getProjectId()));
            $this->getChild()->setEventName(\REDCap::getEventNames(true, true, $this->getChild()->getEventId()));
        }


        /**
         * set flag to be used later in the execution.
         */
        $this->getChild()->setFieldClobber($config['child-field-clobber']);


        /**
         * if checkbox is check to generate a survey for the user when mirroring is complete.
         */
        $this->getChild()->setGenerateSurvey($config['child-project-generate-survey']);

        /**
         * capture the instrument in child project where we will generate the survey and save the list to master.
         */
        if ($this->getChild()->isGenerateSurvey()) {
            $this->getChild()->setSurvey($config['child-project-survey-instrument']);
        }
    }

    /**
     * @param $config
     * @return bool
     */
    private function prepareMirrorData($config)
    {


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

        /**
         * intersect master with child fields based on 'fields-to-migrate' and current event selection. also include/exclude fields. this set the fields to $migrationFields
         */

        $this->getMaster()->setMigrationFields($config, $this->getChild());


        $this->emDebug("Intersection is " . count($this->getMaster()->getMigrationFields()));

        if (empty($this->getMaster()->getMigrationFields())) {
            //Log msg in Notes field
            $msg = "There were no intersect fields between the parent (" . $this->getMaster()->getProjectId() .
                ") and child projects (" . $this->getChild()->getProjectId() . ").";
            $this->emDebug($msg);
            //reset the migration timestamp and child_id

            // TODO: ABM - I DONT UNDERSTAND WHY WE RESET HERE?
            $log_data = array();
            $log_data[$config['parent-field-for-child-id']] = '';
            $log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
            $this->getMaster()->updateNotes($config, $msg, $log_data);
            return false;
        }

        return true;
    }

    /**
     * Migrate data for child project specified in $config parameter
     * @param $config
     * @return bool
     */
    private function mirrorData($config)
    {

        //set child object with other required parameters
        $this->initiateChildProject($config);


        /**
         * last thing if child has enabled to save survey and the survey is defined then we need to get get the field for master where to save the the survey url and throw error if not defined.
         */
        if ($this->getChild()->isGenerateSurvey() && $this->getChild()->getSurvey()) {
            if ($config['field-to-save-child-survey-url'] == '') {
                //update parent notes
                $this->getMaster()->updateNotes($config,
                    'Child survey option is defined but no field in master defined to save the survey url. ');

                return false;
            }

            $this->getMaster()->setSurveyField($config['field-to-save-child-survey-url']);
        }

        //prepare and validate required data for migration
        if (!$this->prepareMirrorData($config)) {
            return false;
        }


        //find out type of Child record then set it.
        $this->getChild()->prepareChildRecord($config, $this->getMaster(), $this->getDagId());

        // $this->emDebug("DATA FROM PARENT INTERSECT FIELDS", $parentData);

        //5.5 Determine the ID in the CHILD project.
        $this->getChild()->prepareRecordId($config, $this->getMaster(), $this->getDagId());


        //check if child record is already saved
        if ((!empty($this->getChild()->isRecordIdExist())) && (!$this->getChild()->isFieldClobber())) {
            //Log no migration
            $msg = "Error creating record in TARGET project. ";
            $msg .= "Target ID, " . $this->getChild()->getRecordId() . ", already exists in Child project " . $this->getChild()->getProjectId() . " and clobber is set to false (" . $this->getChild()->isFieldClobber() . ").";
            $this->emDebug($msg);
            //update parent notes
            $this->getMaster()->updateNotes($config, $msg);
            return false;
        } else {
            /**
             * save record on child project
             */
            $result = $this->getChild()->saveData($config, $this->getMaster(),
                $this->getProjectSetting('child-save-record-hook'),
                $this->getFirstEventId($this->getChild()->getProjectId()), $this->getDagId());
            if (is_array($result)) {
                $msg = "Error creating record in CHILD project " . $this->getChild()->getProjectId() . " - ask administrator to review logs: " . print_r($result,
                        true);
                $this->emError($msg);
                $this->emError("CHILD ERROR", $result);
                $data[$config['migration-notes']] = $msg;

                //update parent notes
                $this->getMaster()->updateNotes($config, $msg, $data);
                return false;
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
    public static function getProjectDAGName($projectId, $id)
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
     * @return bool
     */
    public function isUserInChildDag()
    {
        return $this->userInChildDag;
    }

    /**
     * @param bool $userInChildDag
     */
    public function setUserInChildDag($userInChildDag)
    {
        $this->userInChildDag = $userInChildDag;
    }




    /**
     * map master and child dags. $key is used to link between current sub-setting instance to the dag instance.
     * @param array $config
     * @param int $key
     */
    private function processMapDAGs($config, $key)
    {
        # disabled for now because this might cause issues for projects with DAGs already configured. !!
        # if enforce dags option not check ignore any saved
        /*if(!$config['enforce-dag-configuration']){
            return false;
        }*/
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
                    } else {
                        $this->emDebug("No Child DAG ", " No Child DAG match the Master DAG " . $row['group_id']);
                    }
                }
            }
        } else {
            if ($config['master-child-dag-map']) {
                foreach ($config['master-child-dag-map'] as $instanceKey => $instance) {

                    # if master or child are null or false skip
                    if (($this->getMasterDAGs()[$key][$instanceKey] == false || is_null($this->getMasterDAGs()[$key][$instanceKey])) || ($this->getProjectDAGID($config['child-project-id'],
                                $this->getChildrenDAGs()[$key][$instanceKey]) == false || is_null($this->getProjectDAGID($config['child-project-id'],
                                $this->getChildrenDAGs()[$key][$instanceKey])))) {
                        continue;
                    }

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
}

