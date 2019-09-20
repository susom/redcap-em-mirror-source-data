<?php

namespace Stanford\MirrorMasterDataModule;

use Project;
use REDCap;
use Records;


class MirrorMasterDataModule extends \ExternalModules\AbstractExternalModule
{

    /*

    AM- If you already have a value for the child_id in the parent project and you have clobber on, should you
    just update or create another child record with a new id?



     */


    // PARENT INFO
    private $project_id;
    private $record_id;
    private $instrument;
    private $event_id;
    private $redcap_event_name;  //only set if longitudinal

    private $dagId;

    private $dagRecordId;

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
    function hook_save_record(
        $project_id,
        $record,
        $instrument,
        $event_id,
        $group_id,
        $survey_hash,
        $response_id,
        $repeat_instance
    ) {

        // WRITE OUT PARENT INFORMATION TO OBJECT
        $this->project_id = $project_id;
        $this->record_id = $record;
        $this->instrument = $instrument;
        $this->event_id = $event_id;
        $this->redcap_event_name = \REDCap::getEventNames(true, false, $event_id);

        $this->emDebug(
            "PROJECT: $project_id",
            "RECORD: $record",
            "EVENT_ID: $event_id",
            "INSTRUMENT: $instrument",
            "REDCAP_EVENT_NAME: " . $this->redcap_event_name);

        // Loop through each MMD Setting
        $subsettings = $this->getSubSettings('child-projects');
        foreach ($subsettings as $config) {
            $this->emDebug("config", $config);
            $this->handleChildProject($config);
        }
    }

    private function isUserInDAG($projecId, $username, $group_id)
    {
        $sql = "SELECT username FROM redcap_user_rights WHERE username = '$username' AND group_id = $group_id AND project_id = $projecId";
        $q = db_query($sql);

        $row = db_fetch_row($q);
        if (!empty($row)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Migrate data for child project specified in $config parameter
     * @param $config
     * @return bool
     */
    function handleChildProject($config)
    {


        //get record_id
        $pk_field = REDCap::getRecordIdField();
        $record_id = $this->record_id;
        $instrument = $this->instrument;
        $event_id = $this->event_id;
        $event_name = $this->redcap_event_name;


        //get child pid and get data
        $child_pid = $config['child-project-id'];


        //0. CHECK if in right EVENT (only applies if master-event-name is not null)
        $trigger_event = $config['master-event-name'];
        $this->emDebug("Trigger event", $trigger_event, "Current Event", $event_id);

        // If event is specified but different than current event, then we can abort
        if ((!empty($trigger_event)) && ($trigger_event != $event_id)) {
            // TODO: We may want to consider removing this - would you ever want to store the field with the logic in another event?
            $this->emDebug("Aborting - wrong event");
            return false;
        }

        //0. CHECK if in the right instrument
        $trigger_form = $config['trigger-form'];
        if ((!empty($trigger_form)) && ($trigger_form != $instrument)) {
            $this->emDebug("Aborting - wrong instrument");
            return false;
        }

        //$this->emLog("Found trigger form ". $trigger_form);

        //1. CHECK if migration has already been completed
        $parent_fields = array_filter(array(
            $config['migration-timestamp'],
            $config['parent-field-for-child-id']
        ));

        $results = REDCap::getData('json', $record_id, $parent_fields);
        $results = json_decode($results, true);
        $parentData = current($results);

        //check if timestamp and child_id are set (both? just one?)
        //if exists, then don't do anything since already migrated
        $migration_timestamp = $parentData[$config['migration-timestamp']];
        $parent_field_for_child_id = $parentData[$config['parent-field-for-child-id']];


        $child_field_clobber = $config['child-field-clobber'];


        //migration_timestamp already has a value and Child_field_clobber is not set
        if (!empty($migration_timestamp) && ($child_field_clobber != '1')) {
            // Timestamp present - do not re-migrate
            $existing_msg = "No data migration: Clobber not turned on and migration already completed for record "
                . $record_id . " to child project " . $child_pid;
            $this->emDebug($existing_msg);

            // //reset the migration timestamp and child_id
            // $log_data = array();
            // $log_data[$config['parent-field-for-child-id']] = '';
            // $log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
            //
            // //UPDATE PARENT LOG
            // // TODO: REVIEW (ANDY) - DO WE UPDATE PARENT LOGS ON SKIPPING RE-MIGRATION?
            // $this->updateNoteInParent($record_id, $pk_field, $config, $existing_msg, $log_data);

            return false;
        }

        $this->emDebug("Doing data migration",
            "Clobber: " . $child_field_clobber,
            "Migration timestamp: " . $migration_timestamp,
            "Record: " . $record_id,
            "Child project: " . $child_pid
        );


        //2. CHECK IF LOGIC IS TRIGGERED
        $mirror_logic = $config['mirror-logic'];
        if (!empty($mirror_logic)) {
            $result = REDCap::evaluateLogic($mirror_logic, $this->project_id, $this->record_id,
                $this->redcap_event_name);
            if ($result === false) {
                $this->emDebug("Logic False - Skipping");
                return false;
            }
        }


        //3. INTERSECT FIELDS:  Get fields present in screening project that are also present in the main project
        //3A. Restrict fields to specified forms?
        //todo: UI for config should have dropdown for child forms (only available for current project (parent))


        $arr_fields = $this->getIntersectFields($child_pid, $this->project_id, $config['fields-to-migrate'],
            $config['include-only-form-child'],
            $config['include-only-form-parent'], $config['exclude-fields'], $config['include-only-fields']);

        $this->emDebug("Intersection is " . count($arr_fields));

        if (empty($arr_fields)) {
            //Log msg in Notes field
            $msg = "There were no intersect fields between the parent (" . $this->project_id .
                ") and child projects (" . $child_pid . ").";
            $this->emDebug($msg);
            //reset the migration timestamp and child_id

            // TODO: ABM - I DONT UNDERSTAND WHY WE RESET HERE?
            $log_data = array();
            $log_data[$config['parent-field-for-child-id']] = '';
            $log_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
            $this->updateNoteInParent($record_id, $pk_field, $config, $msg, $log_data);
            return false;
        }

        //4. RETRIEVE INTERSECT DATA FROM PARENT

        //modules method doesn't seem to have option for select list?
        //$data = $this->getData($this->project_id, $record_id);

        $results = REDCap::getData('json', $record_id, $arr_fields, $config['master-event-name']);
        $results = json_decode($results, true);
        $parentData = current($results);


        /**
         * let check if parent record is in a DAG, if so lets find the corresponding Child DAG and update the data accordingly
         */
        if ($config['master-child-dags'] != '' && strpos($parentData[$pk_field], '-') !== false) {
            $this->getChildDAG($config['master-child-dags'], $config['child-project-id']);
            $parentData = $this->prepareChildDagData($parentData, $pk_field);
            if ($config['master-child-dags'] != '' && !$this->isUserInDAG($config['child-project-id'], USERID,
                    $this->getDagId())) {
                //we are in wrong DAG
                return false;
            }
        }
        // $this->emDebug("DATA FROM PARENT INTERSECT FIELDS", $parentData);

        //get primary key for TARGET project
        $child_pk = self::getPrimaryKey($child_pid);

        //5.5 Determine the ID in the CHILD project.
        $child_id = $this->getChildRecordId($record_id, $config, $child_pid);

        //5.6 check if this child id already exists?  IF yes, check clobber. maybe not necesssary, since overwrite will handle it.
        // actually go ahead and do it to handle cases where they only want one initial creation.
        //todo: this event name is only for TARGET form.  not for logging variable
        $child_event_name = $config['child-event-name'];

        $results = REDCap::getData($child_pid, 'json', $child_id, null, $config['child-event-name']);
        $results = json_decode($results, true);
        $target_results = current($results);

        //$this->emDebug(empty($target_results),$target_results, "TARGET DATA for child id $child_id in pid $child_pid with event $child_event_name");

        // Place to keep optional data for parent record
        $parent_data = array();

        if ((!empty($target_results)) && ($child_field_clobber != '1')) {
            //Log no migration
            $msg = "Error creating record in TARGET project. ";
            $msg .= "Target ID, $child_id, already exists (count = " . count($target_results) . ")  in $child_pid and clobber is set to false ($child_field_clobber).";
            $this->emDebug($msg);
        } else {
            //$this->emDebug("PROCEED: Target $child_id does not exists (" . count($target_results) . ") in $child_pid or clobber true ($child_field_clobber).");

            //5. SET UP CHILD PROJECT TO SAVE DATA
            //TODO: logging variable needs to be done separately because of events
            //GET logging variables from target project
            $child_field_for_parent_id = $config['child-field-for-parent-id'];


            //add additional fields to be added to the SaveData call
            //set the new ID
            $parentData[$child_pk] = $child_id;
            if (!empty($child_event_name)) {
                $parentData['redcap_event_name'] = ($child_event_name);
            }

            //enter logging field for child-field-for-parent-id
            if (!empty($child_field_for_parent_id)) {
                $parentData[$child_field_for_parent_id] = $this->record_id;
            }

            //$this->emLog($newData, "SAVING THIS TO CHILD DATA");

            //6. UPDATE CHILD: Upload the data to child project

            // $result = REDCap::saveData(
            //     $child_pid,
            //     'json',
            //     json_encode(array($newData)),
            //     (($child_field_clobber == '1') ? 'overwrite' : 'normal'));

            // IN ORDER TO BE ABLE TO COPY CAT INSTRUMENTS WE ARE GOING TO USE THE UNDERLYING RECORDS SAVE METHOD INSTEAD OF REDCAP METHOD:
            $args = array(
                0 => $child_pid,
                1 => 'json',
                2 => json_encode(array($parentData)),
                3 => ($child_field_clobber == '1') ? 'overwrite' : 'normal',
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
            );

            $result = call_user_func_array(array("Records", "saveData"), $args);

            $this->emDebug("SAVE RESULT", $result);


            // Check for upload errors
            if (!empty($result['errors'])) {
                $msg = "Error creating record in CHILD project " . $child_pid . " - ask administrator to review logs: " . print_r($result['errors'],
                        true);
                $this->emError($msg);
                $this->emError("CHILD ERROR", $result);
            } else {
                /**
                 * let check if parent record is in a DAG, if so lets find the corresponding Child DAG and update the data accordingly
                 */
                if ($config['master-child-dags'] != '') {

                    //get first event in case child event name is not  defined.
                    if ($child_event_name == "" || $child_event_name == null) {
                        $event_id = $this->getFirstEventId($child_pid);
                    } else {
                        $event_id = REDCap::getEventIdFromUniqueEvent($child_event_name);
                    }
                    /**
                     * temp solution till pull request is approved by Venderbilt
                     */
                    $record = array_pop($result['ids']);
                    $value = $this->getDagId();
                    $fieldName = '__GROUPID__';
                    $this->query("DELETE FROM redcap_data where project_id = $child_pid and event_id = $event_id and record = '$record' and field_name = '$fieldName'");

                    $this->query("INSERT INTO redcap_data (project_id, event_id, record, field_name, value) VALUES ($child_pid, $event_id, '$record', '$fieldName', '$value')");

                    $arm = getArm();

                    $result = $this->query("SELECT MAX(sort) as max_sort FROM redcap_record_list where project_id = $child_pid and arm = $arm and record = '$record' and dag_id = '$value'");

                    $max = $result->fetch_assoc()['max_sort'] + 1;
                    if ($max == null) {
                        $max = 1;
                    }
                    $xxx = "INSERT INTO redcap_record_list (project_id, arm, record, dag_id, sort) VALUES ($child_pid, $arm, '$record', '$value', '$max')";
                    $this->query("DELETE FROM redcap_record_list WHERE project_id = $child_pid and arm = $arm and record = '$record'");

                    $this->query("INSERT INTO redcap_record_list (project_id, arm, record, dag_id, sort) VALUES ($child_pid, $arm, '$record', '$value', '$max')");

                    //
                    //$this->setDAG(array_pop($result['ids']), $this->getDagId(), $child_pid, $event_id);
                }
                $msg = "Successfully migrated.";
                if (!empty($config['parent-field-for-child-id'])) {
                    $parent_data[$config['parent-field-for-child-id']] = $child_id;
                }
                if (!empty($config['migration-timestamp'])) {
                    $parent_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
                }
            }
        }

        //7. UPDATE PARENT
        $this->updateNoteInParent($record_id, $pk_field, $config, $msg, $parent_data);

        return true;
    }

    /**
     * get child dag based on saved value from config.json
     * @param array $config
     * @param int $childProjectId
     * @return mixed
     */
    private function getChildDAG($config, $childProjectId)
    {
        $dags = json_decode($config, true);
        $child = strtolower($dags['child']);
        $sql = "SELECT group_id FROM redcap_data_access_groups WHERE LOWER(group_name) = '$child' AND project_id = $childProjectId";
        $q = db_query($sql);

        $row = db_fetch_row($q);
        if (!empty($row)) {
            $this->setDagId($row[0]);
            return $this->getDagId();
        } else {
            $this->emDebug("No Child DAG found");
        }
    }

    /**
     * @param int $dagId
     * @return int
     */
    private function getNextRecordDAGID($dagId)
    {
        $sql = "SELECT MAX(record) as record_id FROM redcap_data WHERE field_name = '__GROUPID__' AND `value` = $dagId";
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
     * @param array $parentData
     * @return array
     */
    private function prepareChildDagData($parentData, $pk_field)
    {
        $parentData[$pk_field] = $this->getNextRecordDAGID($this->getDagId());
        return $parentData;
    }
    // Get the record id for the child
    function getChildRecordId($record_id, $config, $child_pid)
    {
        /**
         * in case we are adding to DAG just keep whatever we already retrieved
         */
        if ($this->getDagRecordId() != null) {
            return $this->getDagRecordId();
        }
        $child_id = null;

        // Method for creating the child id (child-id-create-new, child-id-like-parent, child-id-parent-specified)
        $child_id_select = $config['child-id-select'];

        //get primary key for TARGET project
        $child_pk = self::getPrimaryKey($child_pid);

        $this->emDebug($record_id, $child_pid, $child_id_select, $child_pk);

        switch ($child_id_select) {
            case 'child-id-like-parent':
                $child_id = $this->record_id;
                break;
            case 'child-id-like-parent':
                $child_id = $this->record_id;
                break;
            case 'child-id-parent-specified':
                $child_id_parent_specified_field = $config['child-id-parent-specified-field'];

                //get data from parent for the value in this field
                $results = REDCap::getData('json', $record_id, array($child_id_parent_specified_field),
                    $config['master-event-name']);
                $results = json_decode($results, true);
                $existing_target_data = current($results);

                $child_id = $existing_target_data[$child_id_parent_specified_field];
                $this->emDebug($existing_target_data, $child_id_parent_specified_field, $child_id,
                    "PARENT SPECIFIED CHILD ID: " . $child_id);
                break;
            case 'child-id-create-new':
                $child_id_prefix = $config['child-id-prefix'];
                $child_id_padding = $config['child-id-padding'];

                $event_id = self::getEventIdFromName($child_pid, $config['child-event-name']);
                //get next id from child project
                $child_id = $this->getNextID($child_pid, $event_id, $child_id_prefix, $child_id_padding);
                break;
            default:
                $event_id = self::getEventIdFromName($child_pid, $config['child-event-name']);
                $child_id = $this->getNextID($child_pid, $event_id);
        }
        return $child_id;
    }


    function getIntersectFields(
        $child_pid,
        $parent_pid,
        $fields_to_migrate,
        $include_from_child_form,
        $include_from_parent_form,
        $exclude,
        $include_only
    ) {

        //branching logic reset does not clear out old values - force clear here
        switch ($fields_to_migrate) {
            case 'migrate-intersect':
                $include_from_child_form = null;
                $include_from_parent_form = null;
                $include_only = null;
                break;
            case 'migrate-intersect-specified':
                $include_from_child_form = null;
                $include_from_parent_form = null;
                break;
            case 'migrate-child-form':
                $include_from_parent_form = null;
                $include_only = null;
                break;
            case 'migrate-parent-form':
                $include_from_child_form = null;
                $include_only = null;
                break;
        }
        // redcap: prep is deprecated and now use db_escape (which calls db_real_escape_string)
        //if either child or parent comes up empty, return and log to user cause of error
        if (empty(intval($child_pid)) || empty($parent_pid)) {
            $this->emError("Either child and parent pids was missing." . intval($child_pid) . " and " . $parent_pid);
            return false;
        }

        //todo: ADD support for multiple child forms and multiple parent forms
        $sql_child_form = ((empty(db_escape($include_from_child_form))) ? "" : " and a.form_name = '" . db_escape($include_from_child_form) . "'");
        $sql_parent_form = ((empty(db_escape($include_from_parent_form))) ? "" : " and b.form_name = '" . db_escape($include_from_parent_form) . "'");

        $sql = "select field_name from redcap_metadata a where a.project_id = " . intval($child_pid) . $sql_child_form .
            " and field_name in (select b.field_name from redcap_metadata b where b.project_id = " . $parent_pid . $sql_parent_form . ");";
        $q = db_query($sql);
        //$this->emDebug($sql);

        $arr_fields = array();
        while ($row = db_fetch_assoc($q)) {
            $arr_fields[] = $row['field_name'];
        }

        //if (!empty($exclude)) {
        if (count($exclude) > 1) {
            $arr_fields = array_diff($arr_fields, $exclude);
            //$this->emDebug($arr_fields, 'EXCLUDED arr_fields:');
        } else {
            if (count($include_only) > 1) {
                //giving precedence to exclude / include if both are entered
                $arr_fields = array_intersect($arr_fields, $include_only);
                //$this->emDebug($include_only, $arr_fields, 'INCLUDED FIELDS arr_fields:');
            }
        }

        return $arr_fields;

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
    function updateNoteInParent($record_id, $pk_field, $config, $msg, $parent_data = array())
    {
        //$this->emLog($parent_data, "DEBUG", "RECEIVED THIS DATA");
        $parent_data[$pk_field] = $record_id;
        if (isset($config['migration-notes'])) {
            $parent_data[$config['migration-notes']] = $msg;
        }

        if (!empty($config['master-event-name'])) {
            //assuming that current event is the right event
            $master_event = $this->redcap_event_name; // \REDCap::getEventNames(true, false, $config['master-event-name']);
            //$this->emLog("Event name from REDCap::getEventNames : $master_event / EVENT name from this->redcap_event_name: ".$this->redcap_event_name);
            $parent_data['redcap_event_name'] = $master_event; //$config['master-event-name'];
        }

        //$this->emLog($parent_data, "Saving Parent Data");
        $result = REDCap::saveData(
            $this->project_id,
            'json',
            json_encode(array($parent_data)),
            'overwrite');

        // Check for upload errors
        if (!empty($result['errors'])) {
            $msg = "Error creating record in PARENT project " . $this->project_id . " - ask administrator to review logs: " . json_encode($result);
            //$sr->updateFinalReviewNotes($msg);
            //todo: bubble up to user : should this be sent to logging?
            $this->emError($msg);
            $this->emError("RESULT OF PARENT: " . print_r($result, true));
            //logEvent($description, $changes_made="", $sql="", $record=null, $event_id=null, $project_id=null);
            REDCap::logEvent("Mirror Master Data Module", $msg, null, $record_id, $config['master-event-name']);
            return false;
        }

    }


    static function getPrimaryKey($project_id)
    {
        //need to find pk in target_project_pid
        $dd = REDCap::getDataDictionary($project_id, 'array');
        reset($dd);
        $pk = key($dd);
        return $pk;
    }


    /**
     * @param $pid
     * @param int $event_id : Pass NULL or '' if CLASSICAL
     * @param string $prefix
     * @param bool $padding
     * @return bool|int|string
     * @throws
     */
    public function getNextId($pid, $event_id, $prefix = '', $padding = false)
    {

        $thisProj = new \Project($pid);
        $id_field = $thisProj->table_pk;

        //If Classical no event or null is passed
        if (($event_id == '') OR ($event_id == null)) {
            $event_id = $this->getFirstEventId($pid);
        }

        $this->emLog("PK for $pid is $id_field looking for event: " . $event_id . " in pid: " . $pid .
            " with prefix: $prefix and padding: $padding");

        $q = \REDCap::getData($pid, 'array', null, array($id_field), $event_id);
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
        } while (!empty($q[$id][$event_id][$id_field]));

        $this->emLog("Next ID in project $pid for field $id_field is $id");
        return $id;
    }

    /**
     * Returns the event id given a project id and event name
     * This was needed because the existing plugin function only operates in the context of the current project.
     * @param $project_id
     * @param $event_name
     * @return int|null     Returns the event_id or null if not found
     * @throws
     */
    public static function getEventIdFromName($project_id, $event_name)
    {
        if (empty($event_name)) {
            return null;
        }

        $thisProj = new \Project($project_id, false);
        $thisProj->loadEventsForms();
        $event_id_names = $thisProj->getUniqueEventNames();
        $event_names_id = array_flip($event_id_names);

        $result = empty($event_names_id[$event_name]) ? null : $event_names_id[$event_name];
        return $result;
    }


    /**
     *
     * emLogging integration
     *
     */
    function emLog()
    {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "INFO");
    }

    function emDebug()
    {
        // Check if debug enabled
        if ($this->getSystemSetting('enable-system-debug-logging') || (!empty($_GET['pid']) && $this->getProjectSetting('enable-project-debug-logging'))) {
            $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
            $emLogger->emLog($this->PREFIX, func_get_args(), "DEBUG");
        }
    }

    function emError()
    {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "ERROR");
    }
}

