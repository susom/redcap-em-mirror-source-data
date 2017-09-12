<?php
namespace Stanford\MirrorMasterDataModule;

redcap_connect();

class MirrorMasterDataModule extends \ExternalModules\AbstractExternalModule
{


    private $config_fields = array();
    private $proj;
    private $project_id;

    /**
     * MirrorMasterDataModule constructor.
     *
     * Parse through config file and store fields and values

    public function __construct()
    {
        parent::__construct();

    $this->Proj = new \Project($this->project_id);
    $this->project_id = $this->Proj->project_id;

    //        \Plugin::log($this->Proj->project_id, "DEBUG", "PROJECT ID");
    //\Plugin::log($this->Proj, "DEBUG", "proj");
    $this->config_fields = $this->setupConfig();


    }
     */

    /**
     * Rearrange the config settings to be by child project
     * and then the config properties -> value
     *
     * @return array
     */
    function setupConfig()
    {
        //get the config defintion
        $config = $this->getConfig();

        //get the sub-settings
        $config_fields = $config['project-settings']['1']['sub_settings'];
//        \Plugin::log($config_fields, "DEBUG", "==========this is the config json file");

        //iterate through the config fields and pull up all the keys
        //arrange them so that each field is under each project
        $keys = array();
        foreach ($config_fields as $key => $item) {
            $value = $this->getProjectSetting($item['key']);
            for ($i = 0; $i < count($value); $i++) {
                //        \Plugin::log($value, "DEBUG", "$i: $key for ".$item['key']);
                $keys[$i][$item['key']] = $value[$i];
            }
        }

        return $keys;

    }

    function handleChildProject($config)
    {
        //get record_id
        $pk_field = \REDCap::getRecordIdField();
        $record_id = isset($_REQUEST[$pk_field]) ? $_REQUEST[$pk_field] : "";
        $page = isset($_REQUEST['page']) ? $_REQUEST['page'] : "";

        \Plugin::log("PAGE IS " . $page);
        //0. CHECK if in the right instrument
        $trigger_form = $config['trigger-form'];
        if ((!empty($trigger_form)) && ($trigger_form != $page)) {
            return;
        }

        //1. CHECK if migration has already been completed
        $parent_fields[] = $config['parent-field-for-child-id'];
        $parent_fields[] = $config['migration-timestamp'];

        $results = \REDCap::getData('json', $record_id, $parent_fields);
        $results = json_decode($results, true);
        $newData = current($results);

        //check if timestamp and child_id are set (both? just one?)
        //if exists, then don't do anything since already migrated
        $migration_timestamp = $newData[$config['migration-timestamp']];
        \Plugin::log($migration_timestamp, "DEBUG", "MIGRATION_TIMESTAMP");

        //get  child pid and get data
        $child_pid = $config['child-project-id'];
        \Plugin::log($child_pid, "DEBUG", 'Child pid is $child_pid and parent pid is ' . $this->project_id);

        if (!empty($migration_timestamp) ) { //|| (isset($migration_timestamp))) {
            //TODO: add to logging?
            \Plugin::log("No data migration:  already completed for record " . $record_id . " to child project " . $child_pid);
            return;
        } else {
            \Plugin::log("NO TIMESTAMP -- KEEP GOING");
        }

        //2. CHECK IF LOGIC IS TRIGGERED
        $mirror_logic = $config['mirror-logic'];
        // validation
        if (! $this->testLogic($mirror_logic, $record_id)) {
            //\Plugin::log($logic, "DEBUG", "LOGIC validated to false so skipping");
            return;
        }

        //3. INTERSECT DATA:  Get fields present in screening project that are also present in the main project
        ////todo: clean child_pid/ db_escape or intval
        /// redcap: prep
        /// test_script of scripts an dtest out the clean methods...
        ///
        $sql = "select field_name from redcap_metadata a where a.project_id = " . $child_pid .
            " and field_name in (select b.field_name from redcap_metadata b where b.project_id = " . $this->project_id . ");";
        $q = db_query($sql);
        \Plugin::log($sql, "DEBUG", "SQL");

        $arr_fields = array();
        while ($row = db_fetch_assoc($q)) $arr_fields[] = $row['field_name'];
//        \Plugin::log($arr_fields, "DEBUG" , "ARR_FIELDS");

        //exclude-fields
        $exclude = $config['exclude-fields'];
        //include-only-fields
        $include_only = $config['include-only-fields'];

        \Plugin::log($exclude, "DEBUG", 'EXCLUDE THESE arr_fields:' .empty($exclude));
        \Plugin::log(empty($exclude), "DEBUG", 'EMPTY THESE arr_fields:' .empty($exclude));
        \Plugin::log(isset($exclude), "DEBUG", 'ISSET THESE arr_fields:' .empty($exclude));
        \Plugin::log(count($exclude), "DEBUG", 'COUNT THESE arr_fields:' .empty($exclude));
        //if (!empty($exclude)) {
        if (count($exclude) >1) {
            $arr_fields = array_diff($arr_fields, $exclude);
//            \Plugin::log($arr_fields, "DEBUG",'EXCLUDED arr_fields:');
        } else if (count($include_only) > 1) {
            //giving precedence to exclude / include if both are entered
            $arr_fields = array_intersect($arr_fields, $include_only);
            \Plugin::log($arr_fields, "DEBUG",'INCLUDED FIELDS arr_fields:');
        }

        if (empty($arr_fields)) {
            //TODO: Where should this data to users be bubbled up? notes field? logging?
            \Plugin::log("There were no intersect fields between the parent (" . $this->project_id .
                ") and child projects (" . $child_pid . ").");
            return;
        }


        //4. RETRIEVE INTERSECT DATA FROM PARENT

        //modules method doesn't seem to have option for select list?
        //$data = $this->getData($this->project_id, $record_id);
        $results = \REDCap::getData('json', $record_id, $arr_fields, $config['master-event-name']);
        $results = json_decode($results, true);
        $newData = current($results);

        \Plugin::log($newData, "DEBUG", "INTERSECT DATA");


        //5. SET UP CHILD PROJECT TO SAVE DATA
        //GET admin variables from child project
        $child_id = $config['child-field-for-parent-id'];
        $child_event_name = $config['child-event-name'];
        $child_field_clobber = $config['child-field-clobber'];
        $child_id_prefix = $config['child-id-prefix'];
        $child_id_delimiter = $config['child-id-delimiter'];
        $child_id_padding = $config['child-id-padding'];
        //get next id from child project
        $next_id = $this->getNextID($child_pid, $child_id_prefix, $child_id_delimiter, $child_id_padding);
//        \Plugin::log($next_id, "DEBUG", "NEXTID ".key($next_id));

        //add additional fields to be added to the SaveData call
        $newData[key($next_id)] = reset($next_id);
        if (!empty($child_event_name)) {
            $newData['redcap_event_name'] = ($child_event_name);
        }
        if (!empty($child_id)) {
            $newData[$child_id] = $record_id;
        }

        //6. UPDATE CHILD: Upload the data to child project
        $result = \REDCap::saveData(
            $child_pid,
            'json',
            json_encode(array($newData)),
            (($child_field_clobber=='1') ? 'overwrite' : 'normal'));
//        \Plugin::log($child_field_clobber, "DEBUG", "TERNARY: ".(($child_field_clobber) ? 'overwrite' : 'normal'));

//        \Plugin::log($result, "DEBUG", "Creating new record: ".reset($next_id));
        // Check for upload errors
        if (!empty($result['errors'])) {
            $msg = "Error creating record in CHILD project ".$child_pid." - ask administrator to review logs: " . ($result['errors']);

            \Plugin::log($msg);
            \Plugin::log($result, "DEBUG", "CHILD ERROR");
        } else {
            $msg = "Successfully migrated.";
            $parent_fields[] = $config['migration-timestamp'];
            $parent_fields[] = $config['parent-field-for-child-id'];
            $parent_data[$config['parent-field-for-child-id']] = reset($next_id);
            $parent_data[$config['migration-timestamp']] = date('Y-m-d H:i:s');
        }

        //7. UPDATE PARENT
        $parent_data[$pk_field] = $record_id;
        $parent_data[$config['migration-notes']] = $msg;

        if (!empty($master_event_name)) {
            $parent_data['redcap-event-name'] = $config['master-event-name'];
        }

        $result = \REDCap::saveData(
            $this->project_id,
            'json',
            json_encode(array($parent_data)),
            'overwrite');

//        \Plugin::log($result, "DEBUG", "updated parent record: ".$record_id);
        // Check for upload errors
        if (!empty($result['errors'])) {
            $msg = "Error creating record in Parent project ".$this->project_id. " - ask administrator to review logs: " . json_encode($result);
            //$sr->updateFinalReviewNotes($msg);
            //todo: bubble up to user
            \Plugin::log($msg);
            \Plugin::log("RESULT OF PARENT: " .print_r($result, true));
            return false;
        }

        return true;
    }

    /**
     * @param $logic
     * @param $record
     * @return bool|string
     */
    public function testLogic($logic, $record) {

        \Plugin::log('Testing record '. $record . ' with ' . $logic, "DEBUG");
        //if blank logic, then return true;
        \Plugin::log($logic, "DEBUG", 'logic');
        \Plugin::log(empty($logic), "DEBUG", 'EMPTY  logic');
        \Plugin::log(isset($logic), "DEBUG", 'ISSET  logic');
        \Plugin::log(count($logic), "DEBUG", 'COUNT  logic');
        \Plugin::log(($logic==''), "DEBUG", 'IS LOGIC = blank');

        if (empty($logic)) {
            \Plugin::log("EMPTY SO RETURNING TRUE: ". empty($logic));
            return true;
        }

        \Plugin::log('EVENT FOR  '. $record . ' is ' . $this->redcap_event_name, "DEBUG");
        if (\LogicTester::isValid($logic)) {

            // Append current event details
            if (\REDCap::isLongitudinal() && $this->redcap_event_name) {
                $logic = \LogicTester::logicPrependEventName($logic, $this->redcap_event_name);
                \Plugin::log(__FUNCTION__ . ": logic updated with selected event as " . $logic, "INFO");
            }

            if (\LogicTester::evaluateLogicSingleRecord($logic, $record)) {
                $result = true;
                \Plugin::log($result, "DEBUG", "VALIDATED TO TRUE");
            } else {
                $result = false;
                \Plugin::log($result, "DEBUG", "LOGIC VALIDATED to FALSE");
                return false;
            }
        } else {
            $result = "Invalid Syntax";
            \Plugin::log($result, "DEBUG", "LOGIC : INVALID SYNTAX");
        }
        return $result;
    }


    /**
     * For example
     * $target_project_pid = STUDY_PID
     * $prefix = "2151"
     * $delilimiter = "-"
     * $padding = 4
     */
    function getNextID($target_project_pid, $prefix='', $delimiter = '', $padding = 0) {
        //need to find pk in target_project_pid
        $target_dict = \REDCap::getDataDictionary($target_project_pid,'array');

        reset($target_dict);
        $pk = key($target_dict);
//        \Plugin::log($pk, "DEBUG", "target pk");

        // Determine next record in child project
        $all_ids = \REDCap::getData($target_project_pid, 'array', NULL, $pk);

        //if empty then last_id is 0
        if (empty($all_ids)) {
            $last_id = $prefix.$delimiter.'0';
            \Plugin::log($last_id, "DEBUG","there is no existing, set last to ".$last_id);
        } else {
            ksort($all_ids);
            end($all_ids);
            $last_id = key($all_ids);
        }

//        \Plugin::log($last_id, "DEBUG", "this is the last id found for project ".$target_project_id);

        $re = '/'.$prefix.$delimiter.'(?\'candidate\'\d*)/';
        preg_match_all($re, $last_id, $matches, PREG_SET_ORDER, 0);
        $candidate = $matches[0]['candidate'];
        //\Plugin::log($matches,"DEBUG","matches with candidate: ".$candidate);

        $incremented = intval($candidate) + 1;
        \Plugin::log($incremented,"DEBUG", "this is incremented");
        if (($padding > 0) && ($padding > strlen((string)$incremented))) {
            $padded = str_pad($incremented, $padding, '0', STR_PAD_LEFT);
        } else {
            $padded = $incremented;
        }

        $next_id = $prefix.$delimiter.$padded;

        //return key and value
        return array($pk=>$next_id);
    }

    function hook_save_record($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1)
    {
        $this->Proj = new \Project($this->project_id);
        $this->project_id = $this->Proj->project_id;

//        \Plugin::log($this->Proj->project_id, "DEBUG", "PROJECT ID");
        //\Plugin::log($this->Proj, "DEBUG", "proj");
        $this->config_fields = $this->setupConfig();

        //iterate over each of the child records
        foreach ($this->config_fields as $key => $value) {
            \Plugin::log("Dealing with child: $key");
            $this->handleChildProject($value);

        }
    }
}


    function redcap_connect() {
        // can just do require '../../redcap_connect.php (or some appropriate relative path)
        // the code here looks for redcap_connect.php up successive parent directories
        $dir = dirname(__FILE__);
        while (!file_exists($dir.'/redcap_connect.php') && strlen($dir) > 3) {
            $dir = dirname($dir);
        }
        if (file_exists($dir.'/redcap_connect.php')) {
            require_once $dir.'/redcap_connect.php';
            return;
        }
        exit;
    }

