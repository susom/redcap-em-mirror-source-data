<?php

namespace Stanford\MirrorSourceDataModule;


include_once 'emLoggerTrait.php';
include_once 'Source.php';
include_once 'Destination.php';

use Project;
use REDCap;
use Records;
use Sabre\DAV\Exception;

/**
 * Class MirrorSourceDataModule
 * @package Stanford\MirrorSourceDataModule
 * @property Source $source
 * @property Destination $destination
 * @property array $sourceDAGs
 * @property array $destinationrenDAGs
 * @property array $dagMaps
 * @property string $triggerEvent
 * @property string $triggerInstrument
 * @property array $migrationFields
 * @property int $dagId
 * @property string $dagRecordId
 * @property boolean $userInDestinationDag
 */
class MirrorSourceDataModule extends \ExternalModules\AbstractExternalModule
{

    /*

    AM- If you already have a value for the destination_id in the source project and you have clobber on, should you
    just update or create another destination record with a new id?



     */
    use emLoggerTrait;

    // SOURCE INFO
    /**
     * @var Source
     */
    private $source;

    /**
     * @var Destination
     */
    private $destination;

    /**
     * @var array
     */
    private $sourceDAGs;

    /**
     * @var array
     */
    private $destinationrenDAGs;

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
    private $userInDestinationDag = false;
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
            //Initiate Source Entity and all information for Source Project will be saved there and you can access it via getter methods DO NOT ACCESS direct parameters

            $this->setSource(new Source($project_id, $event_id, $this->PREFIX, $record, $instrument));
            $this->emDebug(
                "PROJECT: $project_id",
                "RECORD: $record",
                "EVENT_ID: $event_id",
                "INSTRUMENT: $instrument",
                "REDCAP_EVENT_NAME: " . $this->getSource()->getEventName());

            // Loop through each MMD Setting
            $subSettings = $this->getSubSettings('mirror-instances', $project_id);

            //flag to run finalMirrorProcess
            $final = false;
            /**
             * we are getting source/destination dags instances outside sub-setting because REDCap return true/false instead of values inside sub-setting for Source/Destination dags.
             */
            $this->setSourceDAGs();

            $this->setDestinationrenDAGs();

            foreach ($subSettings as $key => $config) {

                //verify logic
                if (!$this->processMirrorLogic($config)) {
                    continue;
                }
                /**
                 * if dags mapping is defined/enabled process the map between source and current destination project in sub-setting
                 */
                if ($config['same-dags-name'] || !empty($config['source-destination-dag-map'])) {
                    $this->processMapDAGs($config, $key);
                }

                /**
                 * in case we have DAGs mapped loop over all of them and insert only the one user belongs to. if su
                 */
                if (!empty($this->getDagMaps()) && !is_null($group_id)) {

                    foreach ($this->getDagMaps() as $dag) {
                        $config['source-destination-dags'] = json_encode($dag);
                        $this->setDagId($dag['destination']);
                        //$this->emDebug("config", $config);


                        //exception when user is super user then add only the record to destination dag mapped to the source dag
                        if (defined('SUPER_USER') && SUPER_USER && $dag['source'] == $group_id) {

                            $this->getSource()->canUpdateNotes = $this->mirrorData($config);
                            $this->setUserInDestinationDag(true);
                        } else {
                            //check if user inside current dag
                            if (($config['same-dags-name'] || !empty($config['source-destination-dag-map'])) && !$this->isUserInDAG($config['destination-project-id'],
                                    USERID, $this->getDagId())) {
                                //we are in wrong DAG
                                continue;
                            } else {

                                $this->getSource()->canUpdateNotes = $this->mirrorData($config);
                                $this->setUserInDestinationDag(true);
                            }
                        }
                    }

                    if (!$this->isUserInDestinationDag()) {
                        $this->getSource()->updateNotes($config, USERID . " is not assigned to any destination DAG");
                    }
                } else {
                   // $this->emDebug("config", $config);
                    $this->getSource()->canUpdateNotes = $this->mirrorData($config);
                    //when done sub-setting loop check if mirror completed if so run finalize mirror.
                    if ($this->getSource()->canUpdateNotes) {
                        $this->finalizeMirrorProcess();
                        //so update is only done if true came from mirrordata function
                        $this->getSource()->canUpdateNotes = false;
                    }
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
        $sql = sprintf("SELECT username FROM redcap_user_rights WHERE username = '%s' AND group_id = %s AND project_id = %s", db_escape($username), db_escape($group_id), db_escape($projectId));
        //$sql = "SELECT username FROM redcap_user_rights WHERE username = '$username' AND group_id = $group_id AND project_id = $projectId";
        $q = db_query($sql);

        $row = db_fetch_row($q);
        if (!empty($row)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * check If clobber is defined and if destination record is already migrated
     * @param array $config
     * @return bool
     */
    private function checkMigrationCompleted($config)
    {
        //$this->emLog("Found trigger form ". $trigger_form);

        //1. CHECK if migration has already been completed
        $source_fields = array_filter(array(
            $config['migration-timestamp'],
            $config['source-field-for-destination-id']
        ));

        $results = REDCap::getData('json', $this->getSource()->getRecordId(), $source_fields);
        $results = json_decode($results, true);
        $sourceData = current($results);

        //check if timestamp and destination_id are set (both? just one?)
        //if exists, then don't do anything since already migrated
        $migrationTimestamp = $sourceData[$config['migration-timestamp']];

        $destination_field_clobber = $config['destination-field-clobber'];

        //migration_timestamp already has a value and Destination_field_clobber is not set
        if (!empty($migrationTimestamp) && (!$this->getDestination()->isFieldClobber())) {
            // Timestamp present - do not re-migrate
            $message = "No data migration: Clobber not turned on and migration already completed for record "
                . $this->getSource()->getRecordId() . " to destination project " . $this->getDestination()->getProjectId() . '. To re-save the record in the destination project please reset the value in following field: ' . $config['migration-timestamp'];
           // $this->emDebug($message);

            // //reset the migration timestamp and destination_id
            //$log_data = array();
            //$log_data[$config['source-field-for-destination-id']] = '';
            //$log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
            //
            // //UPDATE SOURCE LOG
            // This was to-do added by Andy so I added it to update source record that migration did not happened because it already done before.
            $this->getSource()->updateNotes($config, $message);

            return false;
        }

//        $this->emDebug("Doing data migration",
//            "Clobber: " . $destination_field_clobber,
//            "Migration timestamp: " . $migrationTimestamp,
//            "Record: " . $this->getSource()->getRecordId(),
//            "Destination project: " . $this->getDestination()->getProjectId()
//        );

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
            $result = REDCap::evaluateLogic($config['mirror-logic'], $this->getSource()->getProjectId(),
                $this->getSource()->getRecordId(),
                $this->getSource()->getEventName());
            if ($result === false) {
                $this->emDebug("Logic False - Skipping");
                return false;
            }
        }
        return true;
    }

    /**
     * this function will be executed when destination project completed migration or sub-setting loop is completed
     */
    private function finalizeMirrorProcess()
    {
        $source = $this->getSource()->getRecord();
        $config = $this->getDestination()->getConfig();
        $msg = "Successfully migrated.";

        //$source has all the record fields, resaving won't work with randomization
        // so limit it to the migration notes field
        $log_data = array();
        $log_data[REDCap::getRecordIdField()] = $this->getRecordId();
        if (!empty($config['source-field-for-destination-id'])) {
            $log_data[$config['source-field-for-destination-id']] = $this->getDestination()->getRecordId();
        }
        if (!empty($config['migration-timestamp'])) {
            $log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
        }

        //7. UPDATE SOURCE Note
        $this->getSource()->updateNotes($config, $msg, $log_data);
    }

    /**
     * @param array $config
     */
    private function initiateDestinationProject($config)
    {
        //set destination id and project object if destination not defined or destination-project-id is different from current destination-project-id
        if (!$this->getDestination() || $this->getDestination()->getProjectId() != $config['destination-project-id']) {
            /**
             * before we change destination object lets update source notes using the destination configuration.
             */
            if ($this->getDestination() && $this->getDestination()->getProjectId() != $config['destination-project-id']) {
                // we are holding the value on source object so when destination object changed we check previous migration to previous destination if succeeded.
                if ($this->getSource()->canUpdateNotes) {
                    $this->finalizeMirrorProcess();
                }
                $this->setDestination(new Destination($config['destination-project-id'], $this->PREFIX, $config['destination-next-id-increment']));
                //save configuration so when we are done with this destination we can update source note.
                $this->getDestination()->setConfig($config);
            } else {
                $this->setDestination(new Destination($config['destination-project-id'], $this->PREFIX, $config['destination-next-id-increment']));
                //save configuration so when we are done with this destination we can update source note.
                $this->getDestination()->setConfig($config);
            }
        } else {
            //if this destination object is used more than once time then prevent updating record id to save all information for same record in different events
            $this->getDestination()->setChangeRecordId(false);
        }

        /**
         * for destination we have only event name if defined. if so lets get event id then set it in destination object.
         */
        if ($config['destination-event-name'] != '') {
            $this->getDestination()->setEventName($config['destination-event-name']);

            $this->getDestination()->setEventId($this->getDestination()->getProject()->getEventIdUsingUniqueEventName($config['destination-event-name']));
        } else {

            # if no event is specified make sure destination use the first event id for the case when pulling next record id.
            $this->getDestination()->setEventId($this->getFirstEventId($this->getDestination()->getProjectId()));
            $this->getDestination()->setEventName(\REDCap::getEventNames(true, true, $this->getDestination()->getEventId()));
        }


        /**
         * set flag to be used later in the execution.
         */
        $this->getDestination()->setFieldClobber($config['destination-field-clobber']);


        /**
         * if checkbox is check to generate a survey for the user when mirroring is complete.
         */
        $this->getDestination()->setGenerateSurvey($config['destination-project-generate-survey']);

        /**
         * capture the instrument in destination project where we will generate the survey and save the list to source.
         */
        if ($this->getDestination()->isGenerateSurvey()) {
            $this->getDestination()->setSurvey($config['destination-project-survey-instrument']);
        }
    }

    /**
     * @param $config
     * @return bool
     */
    private function prepareMirrorData($config)
    {

        //reset the amster record to null
        $this->getSource()->resetRecord();

        //0. CHECK if in right EVENT (only applies if source-event-name is not null)
        $this->setTriggerEvent($config['source-event-name']);

        //$this->emDebug("Trigger event", $this->getTriggerEvent(), "Current Event", $this->getSource()->getEventId());

        // If event is specified but different than current event, then we can abort
        if ((!empty($this->getTriggerEvent())) && ($this->getTriggerEvent() != $this->getSource()->getEventId())) {
            $this->emDebug("Aborting - wrong event");
            return false;
        }

        //0. CHECK if in the right instrument
        $this->setTriggerInstrument($config['trigger-form']);

        if ((!empty($this->getTriggerInstrument())) && ($this->getTriggerInstrument() != $this->getSource()->getInstrument())) {
            $this->emDebug("Aborting - wrong instrument");
            return false;
        }

        //check if mirror processed already
        if (!$this->checkMigrationCompleted($config)) {
            return false;
        }

        /**
         * intersect source with destination fields based on 'fields-to-migrate' and current event selection. also include/exclude fields. this set the fields to $migrationFields
         */

        $this->getSource()->setMigrationFields($config, $this->getDestination());


        //$this->emDebug("Intersection is " . count($this->getSource()->getMigrationFields()));

        if (empty($this->getSource()->getMigrationFields())) {
            //Log msg in Notes field
            $msg = "There were no intersect fields between the source (" . $this->getSource()->getProjectId() .
                ") and destination projects (" . $this->getDestination()->getProjectId() . ").";
            $this->emDebug($msg);
            //reset the migration timestamp and destination_id

            // TODO: ABM - I DONT UNDERSTAND WHY WE RESET HERE?
            $log_data = array();
            $log_data[$config['source-field-for-destination-id']] = '';
            $log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
            $this->getSource()->updateNotes($config, $msg, $log_data);
            return false;
        }

        return true;
    }

    /**
     * Migrate data for destination project specified in $config parameter
     * @param $config
     * @return bool
     */
    private function mirrorData($config)
    {

        //set destination object with other required parameters
        $this->initiateDestinationProject($config);


        /**
         * last thing if destination has enabled to save survey and the survey is defined then we need to get get the field for source where to save the the survey url and throw error if not defined.
         */
        if ($this->getDestination()->isGenerateSurvey() && $this->getDestination()->getSurvey()) {
            if ($config['field-to-save-destination-survey-url'] == '') {
                //update source notes
                $this->getSource()->updateNotes($config,
                    'Destination survey option is defined but no field in source defined to save the survey url. ');

                return false;
            }

            $this->getSource()->setSurveyField($config['field-to-save-destination-survey-url']);
        }

        //prepare and validate required data for migration
        if (!$this->prepareMirrorData($config)) {
            return false;
        }


        //find out type of Destination record then set it.
        $this->getDestination()->prepareDestinationRecord($config, $this->getSource(), $this->getDagId());

        // $this->emDebug("DATA FROM SOURCE INTERSECT FIELDS", $sourceData);

        //5.5 Determine the ID in the DESTINATION project.
        $this->getDestination()->prepareRecordId($config, $this->getSource(), $this->getDagId());


        //check if destination record is already saved
        if ((!empty($this->getDestination()->isRecordIdExist())) && (!$this->getDestination()->isFieldClobber())) {
            //Log no migration
            $msg = "Error creating record in TARGET project. ";
            $msg .= "Target ID, " . $this->getDestination()->getRecordId() . ", already exists in Destination project " . $this->getDestination()->getProjectId() . " and clobber is set to false (" . $this->getDestination()->isFieldClobber() . ").";
            $this->emDebug($msg);
            //update source notes
            $this->getSource()->updateNotes($config, $msg);
            return false;
        } else {
            /**
             * save record on destination project
             */
            $result = $this->getDestination()->saveData($config, $this->getSource(),
                $this->getProjectSetting('destination-save-record-hook'),
                $this->getFirstEventId($this->getDestination()->getProjectId()), $this->getDagId());

            //if (is_array($result)) {
            //3 possible returns for result:
            //  1. Error string if error while saving (not an array)
            //  2. false if fails while handling a dag
            //  3. true if passes
            //report error if anything but a pass
            if ($result !== true) {
                $msg = "Error creating record in DESTINATION project " . $this->getDestination()->getProjectId() . " - ask administrator to review logs: ";
                $this->emError($msg);
                $this->emError("DESTINATION ERROR");
                $data[$config['migration-notes']] = $msg;

                //update source notes
                $this->getSource()->updateNotes($config, $msg, $data);
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
        $sql = sprintf("SELECT group_name FROM redcap_data_access_groups WHERE project_id = %s AND group_id = '%s'", db_escape($projectId), db_escape($id));
        //$sql = "SELECT group_name FROM redcap_data_access_groups WHERE project_id = '$projectId' AND group_id = '$id'";
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
        $sql = sprintf("SELECT group_id FROM redcap_data_access_groups WHERE project_id = %s AND group_name = '%s'", db_escape($projectId), db_escape($name));
        //$sql = "SELECT group_id FROM redcap_data_access_groups WHERE project_id = '$projectId' AND group_name = '$name'";
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
            $sql = sprintf("SELECT * FROM redcap_data_access_groups WHERE  project_id = %s", db_escape($projectId));
        } else {
            $sql = sprintf("SELECT * FROM redcap_data_access_groups WHERE LOWER(group_name) = '%s' AND  project_id = %s", db_escape($name), db_escape($projectId));
            //$sql = "SELECT * FROM redcap_data_access_groups WHERE LOWER(group_name) = '$name' AND project_id = $projectId";
        }

        $q = db_query($sql);

        if (db_num_rows($q) > 0) {
            return $q;
        } else {
            return false;
        }
    }

    /**
     * @return Source
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * @param Source $source
     */
    public function setSource($source)
    {
        $this->source = $source;
    }

    /**
     * @return Destination
     */
    public function getDestination()
    {
        return $this->destination;
    }

    /**
     * @param Destination $destination
     */
    public function setDestination($destination)
    {
        $this->destination = $destination;
    }

    /**
     * @return array
     */
    public function getSourceDAGs()
    {
        return $this->sourceDAGs;
    }

    /**
     */
    public function setSourceDAGs()
    {
        $this->sourceDAGs = $this->getProjectSetting("source-dag");
    }

    /**
     * @return array
     */
    public function getDestinationrenDAGs()
    {
        return $this->destinationrenDAGs;
    }

    /**
     */
    public function setDestinationrenDAGs()
    {
        $this->destinationrenDAGs = $this->getProjectSetting("destination-dag");
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
    public function isUserInDestinationDag()
    {
        return $this->userInDestinationDag;
    }

    /**
     * @param bool $userInDestinationDag
     */
    public function setUserInDestinationDag($userInDestinationDag)
    {
        $this->userInDestinationDag = $userInDestinationDag;
    }




    /**
     * map source and destination dags. $key is used to link between current sub-setting instance to the dag instance.
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
         * if same dags names between source and destination is checked:
         * 1. will check if map is defined manually for each dags in source and destination
         * 2. if dags in source has no manual mapping in config.json them try to find dag with same name in destination project.
         */
        if ($config['same-dags-name']) {
            $sourceDags = $this->getProjectDags($this->getProjectId());
            while ($row = db_fetch_assoc($sourceDags)) {
                //in case no match let check if dags map is manually defined
                $destinationDagIndex = $this->searchForDAGIndex($this->getSourceDAGs()[$key], $row['group_id']);
                if (!is_null($destinationDagIndex)) {
                    $dags[] = array(
                        "source" => $row['group_id'],
                        "destination" => $this->getProjectDAGID($config['destination-project-id'],
                            $this->getDestinationrenDAGs()[$key][$destinationDagIndex])
                    );
                } else {
                    //search if source dag name match dag name in destination project.
                    $destinationDag = $this->getProjectDags($config['destination-project-id'], $row['group_name']);
                    if ($destinationDag) {
                        $destinationRow = db_fetch_assoc($destinationDag);
                        $dags[] = array("source" => $row['group_id'], "destination" => $destinationRow['group_id']);
                        $config['source-destination-dags'] = json_encode($dags);
                    } else {
                        $this->emDebug("No Destination DAG ", " No Destination DAG match the Source DAG " . $row['group_id']);
                    }
                }
            }
        } else {
            if ($config['source-destination-dag-map']) {
                foreach ($config['source-destination-dag-map'] as $instanceKey => $instance) {

                    # if source or destination are null or false skip
                    if (($this->getSourceDAGs()[$key][$instanceKey] == false || is_null($this->getSourceDAGs()[$key][$instanceKey])) || ($this->getProjectDAGID($config['destination-project-id'],
                                $this->getDestinationrenDAGs()[$key][$instanceKey]) == false || is_null($this->getProjectDAGID($config['destination-project-id'],
                                $this->getDestinationrenDAGs()[$key][$instanceKey])))) {
                        continue;
                    }

                    $dags[] = array(
                        "source" => $this->getSourceDAGs()[$key][$instanceKey],
                        "destination" => $this->getProjectDAGID($config['destination-project-id'],
                            $this->getDestinationrenDAGs()[$key][$instanceKey])
                    );
                }
            }

        }
        if (!empty($dags)) {
            $this->setDagMaps($dags);
        }
    }
}

