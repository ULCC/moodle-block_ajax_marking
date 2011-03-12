<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The main library file for the ajax marking block
 *
 * @package   blocks-ajax_marking
 * @copyright 2008-2010 Matt Gibson
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// show/hide constants for config settings
define('AMB_CONF_DEFAULT', 0);
define('AMB_CONF_SHOW', 1);
define('AMB_CONF_GROUPS', 2);
define('AMB_CONF_HIDE', 3);

// include the upgrade file so we have access to amb_update_modules() in case of no settings
include_once($CFG->dirroot.'/blocks/ajax_marking/db/upgrade.php');

require_once("$CFG->dirroot/enrol/locallib.php");


/**
 * All of the main functions for the AJAX marking block are contained within this class. Its is
 * instantiated as an object each time a request is made, then automatically runs and outputs based
 * on the post data provided. Output is in JSON format, ready to be parsed into object form by
 * eval() in the javascript callback object.
 *
 * Each module provides grading code in the form of a file called modname_grading.php which is
 * checked for and included if present. For each installed module that is gradable, an object is
 * created from the class definition in the above file, which is stored within the main object as
 * $this->modname. The main code then runs through the process of generating the nodes for output,
 * getting the data from these module objects as it goes.
 *
 * The module objects make use of some shared functions and shared data, so the main object is
 * passed in by reference to each one.
 *
 * @package   blocks-ajax_marking
 * @copyright 2008-2010 Matt Gibson
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ajax_marking_functions {

    public $output = '';
    public $config = false;
    public $students = '';
    public $courseids = '';
    public $courses = '';
    public $teachers = '';



    /**
     * Setup of inital variables, run every time.
     *
     * @param bool $html flag indicating whether this is to be a html list or not. Defaults to false.
     *
     * @return void
     */
    function initial_setup($html=false) {
        //TODO should this be the constructor?


        if ($html) {
            $this->type = 'html';
        }

        global $USER, $CFG, $DB;

        

        // Now, build an array of the names of modules with grading code available
        // This assumes that a modulename_grading.php file has been created and is in the main
        // block directory
        $this->modulesettings = unserialize(get_config('block_ajax_marking', 'modules'));

        if (empty($this->modulesettings)) {
            amb_update_modules();
            $this->modulesettings = unserialize(get_config('block_ajax_marking', 'modules'));
        }
        // instantiate function classes for each of the available modules and store them
        foreach ($this->modulesettings as $modulename => $module) {
            // echo "{$CFG->dirroot}{$module->dir}/{$modulename}_grading.php ";
            require_once("{$CFG->dirroot}{$module->dir}/{$modulename}_grading.php");
            //include("{$module->dir}/{$modulename}_grading.php");
            $classname = $modulename.'_functions';
            //pass this object in so that a reference to it can be stored, allowing library functions
            // to be called
            $this->$modulename = new $classname($this);
        }

        // Not used yet
        $this->students = new stdClass;
        $this->students->details = array();
        $this->students->ids = new stdClass;

        // call expensive queries only when needed. The
        // ajax calls that dont need this are all the submissions ones and some
        // of the config save ones
        $views_needing_courses = array(
                'html',
                'main',
                'config_course',
                'config_main_tree',
                'course');
        $needs_list_of_courses = in_array($this->type, $views_needing_courses);

        $module_type = in_array($this->type, array_keys($this->modulesettings));

        // in the case of modules with more than 3 node levels e.g. quiz, the intermediate
        // level(s) will need this query too. The type will in this case be stored in the
        // module_grading.php file as one of the keys of the $this->functions array
        $level_check = false;

        foreach ($this->modulesettings as $modulename => $module) {

            if (in_array($this->type, array_keys($this->$modulename->functions))) {
                $level_check = true;
            }
        }

        if ($needs_list_of_courses || $module_type || $level_check) {
            $this->courses = ajax_marking_functions::get_my_teacher_courses($USER->id);
           // $this->courses = enrol_get_my_courses('fullname, id') or die('get my courses error');

            if ($this->courses) {
                $this->make_courseids_list();
                $this->get_teachers();
            }
        }

        // only the main nodes need groups.
        $standard_get_my_groups_types = array(
                'html',
                'main',
                'course');
        $standard_groups_type = in_array($this->type, $standard_get_my_groups_types);

        if ($standard_groups_type || $module_type || $level_check) {
            $this->group_members = $this->get_my_groups();
        }

        // get all configuration options set by this user
        $sql = 'SELECT * FROM {block_ajax_marking} WHERE userid = :userid';
        $params = array('userid' => $USER->id);
        $this->groupconfig = $DB->get_records_sql($sql, $params);
    }

    /**
     * Formats the summary text so that it works in the tooltips without odd characters
     *
     * @param string $text the summary text to formatted
     * @param bool $stripbr optional flag which removes <strong> tags
     *
     * @return string the cleaned text
     */
    function clean_summary_text($text, $stripbr=true) {

        if ($stripbr == true) {
                $text = strip_tags($text, '<strong>');
        }
        $text = str_replace(array('\n', '\r', '"'), array('', '', '&quot;'), $text);

        return $text;
    }

    /**
     * this function controls how long the names will be in the block. different levels need
     * different lengths as the tree indenting varies. The aim is for all names to reach as far to
     * the right as possible without causing a line break. Forum discussions will be clipped if you
     * don't alter that setting in forum_submissions(). It also removes any HTML tags.
     *
     * @param string $text the string to clean up
     * @param int $length - how many characters to cut down to. Defaults to unlimited
     *
     * @return string
     */
    function clean_name_text($text,  $length=0) {

        $text = strip_tags($text, '');

        $text = htmlentities($text, ENT_QUOTES);

        if ($length) {
            $text = substr($text, 0, $length);
        }

        $text = str_replace(array('\n', '\r', '"'), array('', '', '&quot;'), $text);
        return $text;
    }

    /**
     * This function returns a comma separated list of all student ids in a course. It uses the
     * config variable for gradebookroles to get ones other than 'student' and to make it language
     * neutral. Point is that when students leave the course, often their work remains, so we need
     * to check that we are only using work from currently enrolled students.
     *
     * @param int $courseid the id number of the course
     *
     * @return void
     */
    function get_course_students($course) {

        global $DB;

        if (!isset($this->students->ids->{$course->id})) {

            $manager = new course_enrolment_manager($course);
            //$table = new course_enrolment_users_table($manager, $PAGE->url);
            $coursestudents = $manager->get_users('lastname');

            //$course_context = get_context_instance(CONTEXT_COURSE, $courseid);

            // get the roles that are specified as graded in site config settings
            //$student_roles = $DB->get_field('config', 'value', array('name' => 'gradebookroles'));

            // get students in this course with this role. This defaults to getting them from higher
            // up contexts too. If you don't need this and want to avoid the performance hit, replace
            // 'true' with 'false' in the next line
            //$coursestudents = get_role_users($student_roles, $course_context, true);

            if (count($coursestudents) > 0) {

                // save the list so it can be reused
                $this->students->ids->{$course->id}   = array_keys($coursestudents);
                // keep all student details so they can be used for making nodes.
                if (!empty($this->students->details)) {
                    $this->students->details = $this->students->details + $coursestudents;
                } else {
                    $this->students->details = $coursestudents;
                }
            }
        }
    }


    /**
     * This is to get the teachers so that forum posts that have been graded by another teacher
     * can be hidden automatically. No courseid = get all teachers in all courses.
     *
     * @TODO Can this be cached?
     *
     * @return void
     */
    function get_teachers() {

        $teachers = array();

        // TODO get the roles that are specified as being able to grade forums
        $teacherroles = array(3, 4);

        foreach ($this->courses as $course) {

            // this mulidimensional array isn't currently used.
            // $teachers[$coure->id] = array();

            // get teachers in this course with this role
            $context = get_context_instance(CONTEXT_COURSE, $course->id);
            $course_teachers = get_role_users($teacherroles, $context);

            if ($course_teachers) {

                foreach ($course_teachers as $course_teacher) {

                    if (!in_array($course_teacher->id, $teachers)) {

                        $teachers[] = $course_teacher->id;
                    }
                }
            }
        }

        if (count($teachers, 1) > 0) { // some teachers were returned

             $this->teachers = $teachers;
        } else {
             $this->teachers = false;
        }
    }

    /**
     * function to make the summary for submission nodes, showing how long ago it was
     * submitted
     *
     * @param int $seconds the number of seconds since submission
     * @param bool $discussion flag - is this a discussion in which case we need to say something different
     *
     * @return string
     */
    function make_time_summary($seconds, $discussion=false) {
        $weeksstr = get_string('weeks', 'block_ajax_marking');
        $weekstr = get_string('week', 'block_ajax_marking');
        $daysstr = get_string('days', 'block_ajax_marking');
        $daystr = get_string('day', 'block_ajax_marking');
        $hoursstr = get_string('hours', 'block_ajax_marking');
        $hourstr = get_string('hour', 'block_ajax_marking');
        // make the time bold unless its a discussion where there is already a lot of bolding
        $submitted = '';
        $ago = get_string('ago', 'block_ajax_marking');

        if ($seconds<3600) {
            $name = $submitted.'<1 '.$hourstr;

        } else if ($seconds<7200) {
            $name = $submitted.'1 '.$hourstr;

        } else if ($seconds<86400) {
            $hours = floor($seconds/3600);
            $name = $submitted.$hours.' '.$hoursstr;

        } else if ($seconds<172800) {
            $name = $submitted.'1 '.$daystr;

        } else {
            $days = floor($seconds/86400);
            $name = $submitted.$days.' '.$daysstr;
        }
        $name .= ' '.$ago;
        return $name;
    }

    /**
     * This is to build the data ready to be written to the db, using the parameters submitted so far.
     * Others might be added to this object later byt he functions that call it, to match different
     * scenarios
     *
     * @return void
     */
    function make_config_data() {
        global $USER;
        $this->data                 = new stdClass;
        $this->data->userid         = $USER->id;
        $this->data->assessmenttype = $this->assessmenttype;
        $this->data->assessmentid   = $this->assessmentid;
        $this->data->showhide       = $this->showhide;
    }

/**
     * Takes data as the $this->data object and writes it to the db as either a new record or an
     * updated one. Might be to show or not show or show by groups.
     * Called from config_set, config_groups, make_config_groups_radio_buttons ($this->data->groups)
     *
     * @return bool
     */
    function config_write() {

        global $USER;
        $existingrecord  = false;
        $recordid = '';

        $existingrecord = get_record('block_ajax_marking', 'assessmenttype', $this->assessmenttype,
                            'assessmentid', $this->assessmentid, 'userid', $USER->id);
        if ($existingrecord) {

            // get all existing group stuff. We can assume that if there is no existing config
            // record then there will also be no groups as config records are never deleted
            $currentgroups = get_records('block_ajax_marking', 'configid', $existingrecord->id);

            // a record exists, so we update
            $recordid = $this->data->id = $existingrecord->id;

            if (!update_record('block_ajax_marking', $this->data)) {
                return false;
            }

        } else {
            // no record, so we create one
            $recordid = insert_record('block_ajax_marking', $this->data);

            if (!$recordid) {
                return false;
            }
        }

        // Save each group
        if (isset($this->data->groups)) {

            $groups = explode(' ', trim($this->data->groups));

            if ($groups) {

                foreach ($groups as $group) {

                    $data = new stdClass;
                    $data->groupid = $group;
                    $data->display = 1;
                    $data->configid = $recordid;

                    // is there an existing record?
                    if ($currentgroups) {

                        foreach ($currentgroups as $currentgroup) {

                            if ($currentgroup->groupid == $group) {
                                $data->id = $currentgroup->id;
                                break;
                            }
                        }
                    }

                    if (isset($data->id)) {
                        if(!update_record('block_ajax_marking_groups', $data)) {
                            return false;
                        }
                    } else {
                        if (!insert_record('block_ajax_marking_groups', $data)) {
                            return false;
                        }
                    }
                }
            }
        }
        // no errors so far
        return true;
    }


    /**
     * finds the groups info for a given course for the config tree. It then needs to check if those
     * groups are to be displayed for this assessment and user. can probably be merged with the
     * function above. Outputs a json object straight to AJAX
     *
     * The call might be for a course, not an assessment, so the presence of $assessmentid is used
     * to determine this.
     *
     * @param int $courseid the id of the course
     * @param string $assessmenttype type of assessment e.g. forum, workshop
     * @param int $assessmentid optional id of the assessment. Not needed for a course level config bit
     * @return void
     */
    function make_config_groups_radio_buttons($courseid, $assessmenttype, $assessmentid=null) {

        global $DB;

        $groups           = '';
        $current_settings = '';
        $current_groups   = '';
        $groupslist       = '';

        // get currently saved groups settings, if there are any, so that check boxes can be marked
        // correctly
        if ($assessmentid) {
            $config_settings = $this->get_groups_settings($assessmenttype, $assessmentid);
        } else {
            $config_settings = $this->get_groups_settings('course', $courseid);
        }

        if ($config_settings) {

            //only make the array if there is not a null value
            if ($config_settings->groups) {

                if (($config_settings->groups != 'none') && ($config_settings->groups != null)) {
                    //turn space separated list of groups from possible config entry into an array
                    $current_groups = explode(' ', $config_settings->groups);
                }
            }
        }
        $groups = $DB->get_records('groups', array('courseid' => $courseid));

        if ($groups) {

            foreach ($groups as $group) {

                // make a space separated list for saving if this is the first time
                if (!$config_settings || !$config_settings->groups) {
                        $groupslist .= $group->id.' ';

                }
                $this->output .= ',{';

                // do they have a record for which groups to display? if no records yet made, default
                // to display, i.e. box is checked
                if ($current_groups) {
                    $settodisplay = in_array($group->id, $current_groups);
                    $this->output .= ($settodisplay) ? '"display":"true",' : '"display":"false",';

                } else if ($config_settings && ($config_settings->groups == 'none')) {
                    // all groups should not be displayed.
                    $this->output .= '"display":"false",';

                } else {
                    //default to display if there was no entry so far (first time)
                    $this->output .= '"display":"true",';
                }
                $this->output .= '"label":"'.$group->name.'",';
                $this->output .= '"name":"' .$group->name.'",';
                $this->output .= '"id":"'   .$group->id.'"';
                $this->output .= '}';
            }

            if (!$config_settings || !$config_settings->groups) {
                // save the groups if this is the first time
                $this->data->groups = $groupslist;

                $this->config_write();
            }

        }
        // TODO - what if there are no groups - does the return function in javascript deal with this?
    }

    /**
     * Sometimes, it will be necessary to display group nodes if the user has specified this
     * and if there are groups set up for that course.
     *
     * This is the function that is called from the assessment_submissions functions to
     * take care of checking config settings and filtering the submissions if necessary. It behaves
     * differently depending on the users preferences, and is called from both the clicked
     * assessment node (forum, workshop) and also the clicked group nodes if there are any. It
     * returns the nodes to be built.
     *
     * @param object $submissions object with $submission->userid of the unmarked submissions for this assessment
     * @param string $type the type of assessment e.g. forum, assignment
     * @param int $assessmentid the id of the assessment
     * @param int $courseid the id of the course
     * @return mixed false if set to hidden, or groups exist and nodes are built. True if set to
     *               display all, if no config settings exist
     *
     */
    function assessment_groups_filter($submissions, $type, $assessmentid, $courseid) {

        global $CFG, $DB;

        //need to get the groups for this assignment from the config object
        //$combinedrefs = $type.$assessmentid;
        $config_settings = $this->get_groups_settings($type, $assessmentid);
        $course_settings = $this->get_groups_settings('course', $courseid);

        // maybe nothing was there, so we need a default, i.e. show all.
        if (!$config_settings) {

            if (!$course_settings) {
                // no settings at all, default to show
                return true;
            } else {
                // use the course settings

                if ($course_settings->showhide == AMB_CONF_SHOW) {
                    return true;
                }
                // perhaps it is set to hidden
                if ($course_settings->showhide == AMB_CONF_HIDE) {
                    return false;
                }

                // we will use this further down
                $settings = $course_settings;

            }

        } else {
            // maybe its set to show all
            if ($config_settings->showhide == AMB_CONF_SHOW) {
                return true;
            }
            // perhaps it is set to hidden
            if ($config_settings->showhide == AMB_CONF_HIDE) {
                return false;
            }

            $settings = $config_settings;
        }

        // no return so far means it must be set to groups, so we make the groups output and then stop.
        $this->output   = '[{"type":"groups"}';
        $trimmed_groups = trim($settings->groups);

        // prepare an array of ids along with array, from the space separated list of groupsfrom the DB
        $groupsarray = explode(' ', $trimmed_groups);
        //$csv_groups  = implode(',', $groupsarray);
        //TODO make this into a cached query for all groups in this course.

        list($usql, $params) = $DB->get_in_or_equal($groupsarray, SQL_PARAMS_NAMED);
        $sql = "SELECT id, name, description FROM {groups} WHERE id $usql";
        $groupdetails = $DB->get_records_sql($sql, $params);

        //now cycle through each group, plucking out the correct members for each one.
        //some people may be in 2 groups, so will show up twice. not sure what to do about that.
        //Maybe use groups mode from DB...

        foreach ($groupsarray as $group) {

            global $DB;

            $count = 0;

            if ($submissions) {

                foreach ($submissions as $submission) {

                    // check against the group members to see if 1. this is the right group and 2. the
                    // id is a member
                    if ($this->check_group_membership($group, $submission->userid)) {
                        $count++;
                    }
                }
            }

            $summary = $groupdetails[$group]->description ? $groupdetails[$group]->description : 'no summary';
            $assessment = $DB->get_record($type, array('id' => $assessmentid));
            $coursemodule = $DB->get_record('course_modules', array('module' => $this->modulesettings[$type]->id,
                                            'instance' => $assessment->id));

            if ($count > 0) {
                // make the group node
                $this->output .= ',';
                $this->output .= '{';
                $this->output .= '"label":"' .$this->add_icon('group')."(<span class='AMB_count'>";
                $this->output .=              $count.'</span>) '.$groupdetails[$group]->name.'",';
                $this->output .= '"name":"'  .$groupdetails[$group]->name.'",';
                $this->output .= '"group":"' .$group.'",'; // id of submission for hyperlink
                $this->output .= '"id":"'    .$assessmentid.'",'; // id of assignment for hyperlink
                $this->output .= '"title":"' .$this->clean_name_text($summary).'",';
                $this->output .= '"cmid":"'  .$coursemodule->id.'",';
                $this->output .= '"icon":"'  .$this->add_icon('group').'",';
                $this->output .= '"type":"'  .$type.'",';
                // seconds sent to allow style to change according to how long it has been
                //$this->output .= '"seconds":"'.$seconds.'",';
                // send the time of submission for tooltip
                //$this->output .= '"time":"'.$submission->timemodified.'",';
                $this->output .= '"count":"' .$count.'"';
                $this->output .= '}';
            }
        }
        $this->output .= ']';
        return false;

    }

    /**
     * A peculiarity with assignments, due to the pop up system in place at the moment,
     * is that the pop-up javascript tries to update the underlying page when it's closed,
     * but because we are no on that page when it is called, we get a javascript error because those DOM
     * elements are missing. This function was to simulate the collapse of all of the table elements
     * so that they would not need updating.
     *
     * Never worked properly
     *
     * @return void
     */
    function assignment_expand() {

        if (!isset($SESSION->flextable)) {
               $SESSION->flextable = array();
        }

        if (!isset($SESSION->flextable['mod-assignment-submissions']->collapse)) {
            $SESSION->flextable['mod-assignment-submissions']->collapse = array();
        }

        $SESSION->flextable['mod-assignment-submissions']->collapse['submissioncomment'] = true;
        $SESSION->flextable['mod-assignment-submissions']->collapse['grade']             = true;
        $SESSION->flextable['mod-assignment-submissions']->collapse['timemodified']      = true;
        $SESSION->flextable['mod-assignment-submissions']->collapse['timemarked']        = true;
        $SESSION->flextable['mod-assignment-submissions']->collapse['status']            = true;

    }

    /**
     * See previous function
     *
     * @return void
     */
    function assignment_contract() {

        if (isset($SESSION->flextable['mod-assignment-submissions']->collapse)) {
            $SESSION->flextable['mod-assignment-submissions']->collapse['submissioncomment'] = false;
            $SESSION->flextable['mod-assignment-submissions']->collapse['grade']             = false;
            $SESSION->flextable['mod-assignment-submissions']->collapse['timemodified']      = false;
            $SESSION->flextable['mod-assignment-submissions']->collapse['timemarked']        = false;
            $SESSION->flextable['mod-assignment-submissions']->collapse['status']            = false;
        }
    }

    /**
     * Fetches all of the group members of all of the courses that this user is a part of. probably
     * needs to be narrowed using roles so that only those courses where the user has marking
     * capabilities get fetched. Not perfect yet, as the check for role assignments could throw
     * up a student with a role in a different course to that which they are in a group for. This
     * is not a problem, as this list is used to filter student submissions returned from SQL
     * including a check for being one of the course students. The bit in ths function just serves
     * to limit the size a little.
     *
     * @global <type> $CFG
     * @return object $group_members results object, as provided by db.
     */
    function get_my_groups() {

        global $CFG, $DB;
        $courseids = null;

        if (!$this->courses) {
            return false;
        }

        list($usql, $params) = $DB->get_in_or_equal($this->courseids, SQL_PARAMS_NAMED);

        $sql = "SELECT gm.*
                  FROM {groups_members} gm
            INNER JOIN {groups} g
                    ON gm.groupid = g.id
                 WHERE g.courseid $usql";

        $groupmembers = $DB->get_records_sql($sql, $params);
        return $groupmembers;
    }

    /**
     * Fetches the correct config settings row from the settings object, given the details
     * of an assessment item
     *
     * @param string $assessmenttype e.g. forum, workshop
     * @param int $assessmentid the id number of the assessment
     * @return object a row from the config table of the DB
     */
    function get_groups_settings($assessmenttype, $assessmentid) {

        if ($this->groupconfig) {

            foreach ($this->groupconfig as $key => $config_row) {
                $righttype = ($config_row->assessmenttype == $assessmenttype);
                $rightid = ($config_row->assessmentid == $assessmentid);

                if ($righttype && $rightid) {
                    return $config_row;
                }

            }

        }
        // no settings have been stored yet - all to be left as default
        return false;
    }

    /**
     * This is to find out whether the assessment item should be displayed or not, according to the user's
     * preferences
     *
     * @param string $assessmenttype e.g. form, workshop
     * @param int $assessmentid   id# of that assessment
     * @param int $courseid the id number of the course
     * @return bool
     */
    function check_assessment_display_settings($assessmenttype, $assessmentid, $courseid) {

        // find the relevant row of the config object
        $settings = $this->get_groups_settings($assessmenttype, $assessmentid);

        //if (!isset($assessment->course)) {
           // print_r($assessment);
        // }

        $course_settings = $this->get_groups_settings('course', $courseid);

        if ($settings) {

            if ($settings->showhide == AMB_CONF_HIDE) {
                return false;
            } else {
                return true;
            }

        } else if ($course_settings) {
            // if there was no settings object for the item, check for a course level default
            if ($course_settings->showhide == AMB_CONF_HIDE) {
                return false;
            } else {
                return true;
            }
        }
        // default to show
        return true;
    }

    /**
     * This takes the settings for a particular assessment item and checks whether the submission
     * should be added to the count for it, depending on the assessment's display settings and the
     * student's group membership.
     *
     * @param string $assessmenttype e.g. form, workshop
     * @param object $submission the submission object
     * @return bool
     */
    function check_submission_display_settings($assessmenttype, $submission) {

        $settings        = $this->get_groups_settings($assessmenttype, $submission->id);
        $course_settings = $this->get_groups_settings('course', $submission->course);

        // several options:
        // 1. there are no settings, so default to show
        // 2. there are settings and it is set to show by groups, so show, but only if the student
        // is in a group that is to be shown
        // 3. the settings say 'show'

        //echo "submission display check";

        if ($settings) {
            $displaywithoutgroups = ($settings->showhide == AMB_CONF_SHOW);
            $displaywithgroups    = ($settings->showhide == AMB_CONF_GROUPS);
            $intherightgroup      = $this->check_group_membership($settings->groups, $submission->userid);

            if ($displaywithoutgroups || ($displaywithgroups && $intherightgroup)) {
                return true;
            }

        } else {
            // check at course level for a default
            if ($course_settings) {
                $displaywithgroups    = ($course_settings->showhide == AMB_CONF_GROUPS);
                $intherightgroup      = $this->check_group_membership($course_settings->groups, $submission->userid);
                $displaywithoutgroups = ($course_settings->showhide == AMB_CONF_SHOW);

                if ($displaywithoutgroups || ($displaywithgroups && $intherightgroup)) {
                    return true;
                } else {
                    return false;
                }

            } else {
                 // default to show if no settings saved yet.
                return true;
            }
        }
    }

    /**
     * This runs through the previously retrieved group members list looking for a match between
     * student id and group id. If one is found, it returns true. False means that the student is
     * not a member of said group, or there were no groups supplied. Takes a space separated list so
     * that it can be used with groups list taken straight from the user settings in the DB
     *
     * @param string $groups A space separated list of groups.
     * @param int $memberid the student id to be searched for
     * @return bool
     */
    function check_group_membership($groups, $memberid) {

        $groups_array = array();
        $groups = trim($groups);
        $groups_array = explode(' ', $groups);

        if (!empty($this->group_members)) {

            foreach ($this->group_members as $group_member) {

                if ($group_member->id == $memberid) {

                    if (in_array($group_member->groupid, $groups_array)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Fetches the fullname for a given userid. All student details are retrieved in a single SQL
     * query at the start and the stored object is checked with this function. Included is a check
     * for very long names (>15 chars), which will need hyphenating
     *
     * @param int $userid the id of the user
     * @return string
     */
    function get_fullname($userid) {

        $student_details = $this->students->details[$userid];

        if (strlen($student_details->firstname) > 15) {
            $name = substr_replace($student_details->firstname, '-', 15, 0);
        } else {
            $name = $student_details->firstname;
        }
        $name .= ' ';

        if (strlen($student_details->lastname) > 15) {
            $name .= substr_replace($student_details->lastname, '-', 15, 0);
        } else {
            $name .= $student_details->lastname;
        }

        return $name;
    }

    /**
     * Makes the JSON data for output. Called only from the submissions functions.
     *
     * @param string $name - The name for the link
     * @param int $submission_id - Submission id for the link
     * @param int $assessment_id - Assessment id or coursemodule id for the link
     * @param string $summary - Text for the tooltip
     * @param string $type - Type of assessment. false if its a submission
     * @param int $seconds - Number of second ago that this was submitted - for the colour coding
     * @param int $time_modified - Time submitted in unix format, for the tooltip(?)
     * @param int $count the number of submissions, used in case of e.g. forum discussions
     * @param bool $dynamic should it be possible to expand the node?
     * @return void
     */
    function make_submission_node($data) {
    //function make_submission_node($name, $submission_id, $assessment_id, $summary,
    //                              $type, $seconds, $time_modified, $count=1, $dynamic=false) {

        // transformations for specific parts of the data
        $data['label'] = $this->add_icon('user').htmlentities($data['name'], ENT_QUOTES);
        //$data['assessmentid'] = $data['submissionid'];
        //$data['id'] = $data['submissionid'];
        $data['icon'] = $this->add_icon('user');
        $data['count'] = isset($data['count']) ? $data['count'] : 1;
        //$data['uniqueid'] = $data['type'].'submission'.$data['submissionid'];

        // notes
        // $submission_id needs underscore removed and is really userid of the student
        // assessmentid is sometimes cmid
        // $summary needs to become 'title'
        // $time_modified needs to be 'time'

        $output = ',';
        $output .= '{';

        $addcomma = false;

        foreach ($data as $label => $value) {
            $output .= $addcomma ? ',' :'';
            $output .= '"'.$label.'":"'.$value.'"';
            $addcomma = true;
        }

        $output .= '}';

        return $output;

        // TODO - make the input for this function into an array/object

//            $this->output .= '"label":"';
            // some assessment types only have 2 levels as they cannot be displayed per student
            // e.g. journals

//            $this->output .= $this->add_icon('user');
//            $this->output .= htmlentities($name, ENT_QUOTES).'",';
            // this bit gets the user icon anyway as it isn't used at level 2
//            $this->output .= '"name":"'    .htmlentities($name, ENT_QUOTES).'",';
//            $this->output .= '"dynamic":"false",';
            // id of submission for hyperlink
//            $this->output .= '"submissionid":"'     .$submission_id.'",';
            // id of assignment for hyperlink
//            $this->output .= '"assessmentid":"'     .$assessment_id.'",';
            // might need uniqueId to replace it
//            $this->output .= '"id":"'      .$assessment_id.'",';
//            $this->output .= '"title":"'   .$this->clean_summary_text($summary).'",';
//            $this->output .= '"type":"'    .$type.'",';
//            $this->output .= '"icon":"'    .$this->add_icon('user').'",';
            // 'seconds ago' sent to allow style to change according to how long it has been
//            $this->output .= '"seconds":"' .$seconds.'",';
            // send the time of submission for tooltip
//            $this->output .= '"time":"'    .$time_modified.'",';
//            $this->output .= '"uniqueid":"'.$type.'submission'.$submission_id.'",';
//            $this->output .= '"count":"'   .$count.'"';
        
    }

    /**
     * Makes a list of unique ids from an sql object containing submissions for many different
     * assessments. Called from the assessment level functions e.g. quizzes() and
     * count_course_submissions() Must be per course due to the cmid
     *
     * @param object $submissions Must have
     *               $submission->id as the assessment id and
     *               $submission->cmid as coursemodule id (optional for quiz question)
     *               $submission->description as the desription
     *               $submission->name as the name
     * @param bool $course are we listing them for a course level node?
     * @return array array of ids => cmids
     */
    function list_assessment_ids($submissions, $course=false) {

        $ids = array();

        foreach ($submissions as $submission) {

            if ($course) {

                if ($submission->course != $course) {
                    continue;
                }
            }
            $check = in_array($submission->id, $ids);

            if (!$check) {

                $ids[$submission->id]->id = $submission->id;

                if (isset($submission->cmid)) {
                    $ids[$submission->id]->cmid = $submission->cmid;
                } else {
                    $ids[$submission->id]->cmid = null;
                }

                if (isset($submission->description)) {
                    $ids[$submission->id]->description = $submission->description;
                } else {
                    $ids[$submission->id]->description = null;
                }

                if (isset($submission->name)) {
                    $ids[$submission->id]->name = $submission->name;
                } else {
                    $ids[$submission->id]->name = null;
                }

                if (isset($submission->timemodified)) {
                    $ids[$submission->id]->timemodified = $submission->timemodified;
                } else {
                    $ids[$submission->id]->timemodified = null;
                }
            }
        }
        return $ids;
    }

    /**
     * For SQL statements, a comma separated list of course ids is needed. It is vital that only
     * courses where the user is a teacher are used and also that the front page is excluded.
     *
     * @return void
     */
    function make_courseids_list() {

        global $USER, $DB;

        if ($this->courses) {

            $this->courseids = array();

            // retrieve the teacher role id (3)
            // TODO make this into a setting
            $teacherrole = $DB->get_field('role', 'id', array('shortname' => 'editingteacher'));

            foreach ($this->courses as $key=>$course) {

                $allowed_role = false;

                // exclude the front page.
//                if ($course->id == 1) {
//                    unset($this->courses[$key]);
//                    continue;
//                }

                // role check bit borrowed from block_marking, thanks to Mark J Tyers [ZANNET]
                $teachers = 0;
                $noneditingteachers = 0;

                // check for editing teachers
                $context = get_context_instance(CONTEXT_COURSE, $course->id);
                $teachers = get_role_users($teacherrole, $context, true);

                if ($teachers) {

                    foreach ($teachers as $teacher) {

                        if ($teacher->id == $USER->id) {
                            $allowed_role = true;
                        }
                    }
                }

                if (!$allowed_role) {
                    // check the non-editing teacher role id (4) only if the last bit failed
                    $noneditingteacherrole = $DB->get_field('role', 'id', array('shortname' => 'teacher'));
                    // check for non-editing teachers
                    $noneditingteachers = get_role_users($noneditingteacherrole, $context, true);

                    if ($noneditingteachers) {

                        foreach ($noneditingteachers as $key2=>$val2) {

                            if ($val2->id == $USER->id) {
                                $allowed_role = true;
                            }
                        }
                    }
                }
                // if still nothing, don't use this course
                if (!$allowed_role) {
                    unset($this->courses[$key]);
                    continue;
                }
                // otherwise, add it to the list
                $this->courseids[] = $course->id;

            }
        }
    }

    /**
     * Checks whether the user has grading permission for this assessment
     *
     * @param string $type the type of assessment e.g. 'assignment, 'workshop'
     * @param object $assessment a row from the db
     * @return bool
     */
    function assessment_grading_permission($type, $assessment) {

        global $USER;

        $context = get_context_instance(CONTEXT_MODULE, $assessment->cmid);

        $cap = $this->$type->capability;

        if (has_capability($cap, $context, $USER->id, false)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Makes an assessment node for either the main tree or the config tree
     *
     * @param object $assessment the object with all the assessment data
     * @param bool $config flag - are we doing the config tree?
     * @return void
     */
    function make_assessment_node($assessment, $config=false) {

        // cut it at 200 characters
        $shortsum = substr($assessment->description, 0, 200);

        if (strlen($shortsum) < strlen($assessment->description)) {
            $shortsum .= '...';
        }
        $length = ($config) ? false : 30;

        $this->output .= ',';
        $this->output .= '{';
        $this->output .= '"label":"'        .$this->add_icon($assessment->type);
        $this->output .= ($this->config) ? '' : "(<span class='AMB_count'>".$assessment->count.'</span>) ';
        $this->output .= $this->clean_name_text($assessment->name, $length).'",';

        // Level 2 only nodes will be marked as non-dynamic if they are set to 'show' and dynamic
        // if they are set to 'groups'.
        $this->output .= ($assessment->dynamic) ? '"dynamic":"true",' : '"dynamic":"false",';

        $this->output .= '"name":"'         .$this->clean_name_text($assessment->name, $length).'",';

        $this->output .= '"id":"'           .$assessment->id.'",';
        $this->output .= '"icon":"'         .$this->add_icon($assessment->type).'",';
        // $this->output .= '"icon":"'         .$this->add_icon($assessment->type).'",';
        $this->output .= '"assessmentid":"' .$assessment->id.'",';
        $this->output .= '"cmid":"'         .$assessment->cmid.'",';
        $this->output .= '"type":"'         .$assessment->type.'",';
        $this->output .= '"uniqueid":"'     .$assessment->type.$assessment->id.'",';

        $this->output .= '"title":"';

        if ($config) {
            // make a tooltip showing current settings
            $course_settings = $this->get_groups_settings('course', $assessment->course);

            $this->output .= get_string('currentsettings', 'block_ajax_marking').': ';

            if (isset($course_settings->showhide)) {

                switch ($course_settings->showhide) {

                    case 1:
                        $this->output .= get_string('showthiscourse', 'block_ajax_marking');
                        break;

                    case 2:
                        $this->output .= get_string('showwithgroups', 'block_ajax_marking');
                        break;

                    case 3:
                        $this->output .= get_string('hidethiscourse', 'block_ajax_marking');
                }

            } else {
                $this->output .= get_string('showthiscourse', 'block_ajax_marking');
            }

            // end tooltip bit
            $this->output .= '"';
        } else {
            $this->output .= get_string('modulename', $assessment->type).': '.$this->clean_summary_text($shortsum).'"';
        }

        if ($assessment->count) {
            $this->output .= ',"count":"'   .$assessment->count.'"';
        }

        $this->output .= '}';
    }

    /**
     * It turned out to be impossible to add icons reliably
     * with CSS, so this function generates the right img tag
     *
     * @param string $type This is the name of the type of icon. For assessments it is the db name
     * of the module
     * @return string the HTML for the icon
     */
    function add_icon($type) {

        global $CFG, $OUTPUT;

        // TODO make this work properly - load all icons in HTML, then apply them using css as needed
        return;

        $result = "<img class='amb-icon' src='";

        // If custompix is not enabled, assume that the theme does not have any icons worth using
        // and use the main pix folder
//        if ($THEME->custompix) {
//            $result .= 'theme/'.current_theme().'/';
//        }

        // TODO - make question into a function held within the quiz file
        switch ($type) {

            case 'course':
                $result .= $OUTPUT->pix_url('i/course')."' alt='course icon'";
                break;

            // TODO - how to deal with 4 level modules dynamically?
            case 'question':
                $result .= $OUTPUT->pix_url('i/questions')."'";
                break;

            case 'journal':
                $result .= $OUTPUT->pix_url('icon', 'journal')."'";
                break;

            case 'group':
                $result .= $OUTPUT->pix_url('i/users')."'";
                break;

            case 'user':
                $result .= $OUTPUT->pix_url('i/user')."' alt='user icon'";
                break;

            default:

                $result .= $OUTPUT->pix_url('icon', $type)."' alt='".$type." icon'";
        }
        $result .= ' />';
        return $result;
    }

    /**
     * This is to make the nodes for the ul/li list that is used if AJAX is disabled.
     *
     * @param object $item contains data about thecourse or assessment
     * @return string
     */
    function make_html_node($item) {
        global $CFG;
        //item could be course or assessment
        $node = '<li class="AMB_html"><a href="'.$item->link.'" title="';
        $node .= $this->clean_name_text($item->description).'" >'.$this->add_icon($item->type);
        $node .= '<strong>('.$item->count.')</strong> '.$item->name.'</a></li>';
        return $node;
    }

    /**
     * Records the display settings for one group in the database
     *
     * @param int $groupid The id of the group
     * @param int $display 1 to show it, 0 to hide it
     * @param int $configid The id of the row int he config table that this corresponds to
     * @return bool
     */
    function set_group_display($groupid, $display, $configid) {

        $data = new stdClass;
        $data->groupid = $groupid;
        $data->configid = $configid;
        $data->display = $display;

        $current = get_record('block_ajax_marking_groups', 'groupid', $groupid);

        if ($current) {
            $data->id = $current->id;
            update_record('block_ajax_marking_groups', $data);
        } else {
            insert_record('block_ajax_marking_groups', $data);
        }
    }

    /**
     * Returns the sql and params array for 'IN (x, y, z)' where xyz are the ids of teacher or non-editing
     * teacher roles
     * @return <type>
     */
    static function teacherrole_sql() {

        global $DB;

        // TODO should be a site wide or block level setting
        $teacherroles = $DB->get_records('role', array('archetype' => 'teacher'));
        $editingteacherroles = $DB->get_records('role', array('archetype' => 'editingteacher'));
        $teacherroleids = array_keys($teacherroles + $editingteacherroles);
        //list($rolesql, $roleparams)
        return $DB->get_in_or_equal($teacherroleids);
    }

    static function get_number_of_category_levels() {

        global $DB;

        $sql = 'SELECT MAX(cx.depth) as depth
                  FROM {context} cx
                 WHERE cx.contextlevel <= ? ';
        $params = array(CONTEXT_COURSECAT);

        $categorylevels = $DB->get_record_sql($sql, $params);
        $categorylevels = $categorylevels->depth;
        $categorylevels--; // ignore site level category to get actual number of categories
        return $categorylevels;
    }

    /**
     * This is to find out what courses a person has a teacher role. This is instead of
     * enrol_get_my_courses(), which would prevent teachers from being assigned at category level
     *
     * @param int $userid
     * @param string|array $fields either a single field from the courses table to return, or an array. Default: *
     */
    static function get_my_teacher_courses($userid, $fields='*') {

        // NOTE could also use subquery without union
        global $DB;

        list($rolesql, $roleparams) = ajax_marking_functions::teacherrole_sql();

        $fieldssql = 'c.';
        $fieldssql .= is_array($fields) ? implode(', c.', $fields) : $fields;

        // option one: use enrolments only then filter to see if there is a teacher role.
        // this will miss any category or site level roles

        // Option 2 collect all the contexts whose parent paths are mentioned in a role assignment record
        // This will get all the courses, but may be horribly slow
        // Logic:
        // - get all role assignments for this user
        // - get all direct joined contexts for these role assignments
        // - get all contexts where

        // get this from DB - how deep are the contexts? Includes top level, so always 1 more than
        // actual number of categories
//        $sql = 'SELECT MAX(cx.depth) as depth
//                  FROM {context} cx
//                 WHERE cx.contextlevel <= ? ';
//        $params = array(CONTEXT_COURSECAT);
//
//        $categorylevels = $DB->get_record_sql($sql, $params);
//        $categorylevels = $categorylevels->depth;


        $categorylevels = ajax_marking_functions::get_number_of_category_levels();


        // Main bit

        // all directly assigned roles
        $select = "SELECT {$fieldssql}
                     FROM {course} c
               INNER JOIN {context} cx
                       ON cx.instanceid = c.id
               INNER JOIN {role_assignments} ra
                       ON ra.contextid = cx.id
                    WHERE cx.contextlevel = ?
                      AND ra.userid = ?
                      AND ra.roleid {$rolesql} ";
        $params = array(CONTEXT_COURSE, $userid);
        $params = array_merge($params, $roleparams);
        $courseswithcourseroles = $DB->get_records_sql($select, $params);


        // roles assigned in category 1 or 2 etc
        //
        // what if roles are assigned in two categories that are parent/child?
        $select1 = "SELECT {$fieldssql}
                     FROM {course} c

                LEFT JOIN {course_categories} cat1
                       ON c.category = cat1.id ";

        $select2 =   "WHERE EXISTS (SELECT 1
                                      FROM {context} cx
                                INNER JOIN {role_assignments} ra
                                        ON ra.contextid = cx.id
                                     WHERE cx.contextlevel = ?
                                       AND ra.userid = ?
                                       AND ra.roleid {$rolesql}
                                       AND (cx.instanceid = cat1.id ";

        // loop adding extra join tables. $categorylevels = 2 means we only need one level of categories (which
        // we already have with the first left join above) so we start from 2 and only add anything if
        // there are 3 levels or more
        // TODO does this cope with no hierarchy at all?
        for ($i = 2; $i <= $categorylevels; $i++) {

            $previouscat = $i-1;
            $select1 .= "LEFT JOIN {course_categories} cat{$i}
                               ON cat{$previouscat}.parent = cat{$i}.id ";

            $select2 .= "OR cx.instanceid = cat{$i}.id ";
        }

        $query = $select1.$select2.'))';

        $params = array(CONTEXT_COURSECAT, $userid);
        $params = array_merge($params, $roleparams);

        $courseswithcategoryroles = $DB->get_records_sql($query, $params);

        return $courseswithcourseroles + $courseswithcategoryroles;

    }

}


/**
 * This class forms the basis of the objects that hold and process the module data. The aim is for
 * node data to be returned ready for output in JSON or HTML format. Each module that's active will
 * provide a class definition in it's modname_grading.php file, which will extend this base class
 * and add methods specific to that module which can return the right nodes.
 *
 * @package   blocks-ajax_marking
 * @copyright 2008-2010 Matt Gibson
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_base {

    /**
     * This counts how many unmarked assessments of a particular type are waiting for a particular
     * course. It is called from the courses() function when the top level nodes are built
     *
     * @param int $course id of the course we are counting submissions for
     * @return int the number of unmarked assessments
     */
    function count_course_submissions($course) {

        $type = $this->type;
        // html_list.php will be doing this many times, so we reuse the data.
        if (!isset($this->all_submissions)) {
            $this->get_all_unmarked();
        }

        // maybe there is nothing to mark?
        if (isset($this->all_submissions) && !$this->all_submissions) {
            return 0;
        }

        // Run through all of the unmarked work, extracting the ids of the assessment items they
        // belong to.

        // get a list of all the assessments in this course, ready to loop through
        $this->assessment_ids = $this->mainobject->list_assessment_ids($this->all_submissions, $course);

        // echo count($this->assessment_ids).' ';
        // Now check all of these assessment ids to see if the user has grading capabilities
        foreach ($this->assessment_ids as $key => $assessment) {

            if (!$this->mainobject->assessment_grading_permission($type, $assessment)) {
                unset($this->assessment_ids[$key]);
            }
            // TODO get the group settings here and attach them to the array, avoiding a
            // get_groups_settings function call later for each submission

            // This assessment might be set to 'hidden'
            if (!$this->mainobject->check_assessment_display_settings($this->type, $assessment->id, $course)) {
                unset($this->assessment_ids[$key]);
            }

        }

        $count = 0;

        // loop through all of the submissions, ignoring any that should not be counted
        foreach ($this->all_submissions as $submission) {

            $check = null;

            // Is this assignment attached to this course?
            if ($submission->course != $course) {
                continue;
            }

            // the object may contain assessments with no submissions
            if (!isset($submission->userid)) {
                continue;
            } else {
                // is the submission from a current user of this course
                if (!in_array($submission->userid, $this->mainobject->students->ids->$course)) {
                    continue;
                }
            }

            // check against previously filtered list of assignments - permission to grade?
            if (!isset($this->assessment_ids[$submission->id])) {
                continue;
            }

            if (!$this->mainobject->check_submission_display_settings($this->type, $submission)) {
                continue;
            }
            $count++;
        }
        return $count;
    }

    /**
     * This function will check through all of the assessments of a particular type (depends on
     * instantiation - there is one of these objects made for each type of assessment) for a
     * particular course, then return the nodes for a course ready for the main tree
     *
     * @param int $courseid the id of the course
     * @param bool $html Are we making a HTML list?
     * @return mixed array or void depending on the html type
     */
    function course_assessment_nodes($courseid, $html=false) {

        global $CFG, $SESSION, $USER;
        $dynamic = true;

        // the HTML list needs to know the count for the course
        $html_output = '';
        $html_count = 0;

        // if the unmarked stuff for all courses has already been requested (html_list.php), filter
        // it to save a DB query.
        // this will be the case only if making the non-ajax <ul> list
        if (isset($this->all_submissions) && !empty($this->submissions)) {

            $unmarked = new stdClass();

            foreach ($this->all_submissions as $key => $submission) {

                if ($submission->course == $courseid) {
                     $unmarked->$key = $submission;
                }
            }
            $this->course_unmarked->$courseid = $unmarked;
        } else {
            // We have no data, so get it from the DB (normal ajax.php call)
            $this->course_unmarked->$courseid = $this->get_all_course_unmarked($courseid);

        }

        // now loop through the returned items, checking for permission to grade etc.

        // check that there is stuff to loop through
        if (isset($this->course_unmarked->$courseid) && !empty($this->course_unmarked->$courseid)) {

            // we need all the assessment ids for the loop, so we make an array of them
            $assessments = $this->mainobject->list_assessment_ids($this->course_unmarked->$courseid);

            foreach ($assessments as $assessment) {

                // counter for number of unmarked submissions
                $count = 0;

                // permission to grade?
                $modulecontext = get_context_instance(CONTEXT_MODULE, $assessment->cmid);

                if (!has_capability($this->capability, $modulecontext, $USER->id)) {
                    continue;
                }

                if (!$this->mainobject->config) {

                    //we are making the main block tree, not the configuration tree

                    // retrieve the user-defined display settings for this assessment item
                    //$settings = $this->mainobject->get_groups_settings($this->type, $assessment->id);

                    // check if this item should be displayed at all
                    $oktodisplay = $this->mainobject->check_assessment_display_settings($this->type,
                                                                                        $assessment->id,
                                                                                        $courseid);

                    if (!$oktodisplay) {
                        continue;
                    }

                    // If the submission is for this assignment and group settings are 'display all',
                    // or 'display by groups' and the user is a group member of one of them, count it.
                    foreach ($this->course_unmarked->$courseid as $assessment_submission) {

                        if ($assessment_submission->id == $assessment->id) {

                            if (!isset($assessment_submission->userid)) {
                                continue;
                            }

                            // if the item is set to group display, it may not be right to add the
                            // student's submission if they are in the wrong group
                            $oktodisplay = $this->mainobject->check_submission_display_settings($this->type,
                                                                                                $assessment_submission);

                            if (!$oktodisplay) {
                                continue;
                            }
                            $count++;
                        }
                    }

                    // if there are no unmarked assignments, just skip this one. Important not to skip
                    // it in the SQL as config tree needs all assignments
                    if ($count == 0) {
                        continue;
                    }
                }

                // if there are only two levels, there will only need to be dynamic load if there are groups to display
                if ($this->levels() == 2) {

                    $assessment_settings = $this->mainobject->get_groups_settings($this->type, $assessment->id);
                    $course_settings = $this->mainobject->get_groups_settings('course', $courseid);

                    // default to false
                    $dynamic = false;

                    if ($assessment_settings) {

                        if ($assessment_settings->showhide == AMB_CONF_GROUPS) {
                            $dynamic = true;
                        }

                    } else if ($course_settings && ($course_settings->showhide == AMB_CONF_GROUPS)) {
                        $dynamic = true;
                    }
                }

                $assessment->count   = $count;
                $assessment->type    = $this->type;
                $assessment->icon    = $this->mainobject->add_icon($this->type);
                $assessment->dynamic = $dynamic;

                if ($html) {
                    // make a node for returning as part of an array
                    $assessment->link    = $this->make_html_link($assessment);
                    $html_output .= $this->mainobject->make_html_node($assessment);
                    $html_count += $assessment->count;
                } else {
                    // add this node to the JSON output object
                    $this->mainobject->make_assessment_node($assessment);
                }
            }

            if ($html) {
                // text string of <li> nodes
                $html_array = array('count' =>$html_count, 'data' => $html_output);
                return $html_array;
            }
        }
    }

    /**
     * This counts the assessments that a course has available. Called when the config tree is built.
     *
     * @param int $course course id of the course we are counting for
     * @return int count of items
     */
    function count_course_assessment_nodes($course) {

        if (!isset($this->assessments)) {
            $this->get_all_gradable_items();
        }

        $count = 0;

        if ($this->assessments) {

            foreach ($this->assessments as $assessment) {
                // permissions check
                if (!$this->mainobject->assessment_grading_permission($this->type, $assessment)) {
                    continue;
                }
                //is it for this course?
                if ($assessment->course == $course) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * creates assessment nodes of a particular type and course for the config tree
     *
     * @param int $course the id number of the course
     * @param string $modulename e.g. forum
     * @return void
     */
    function config_assessment_nodes($course, $modulename) {

        $this->get_all_gradable_items();

        if ($this->assessments) {

            foreach ($this->assessments as $assessment) {

                $context = get_context_instance(CONTEXT_MODULE, $assessment->cmid);

                if (!$this->mainobject->assessment_grading_permission($modulename, $assessment)) {
                    continue;
                }

                if ($assessment->course == $course) {
                    $assessment->type = $this->type;
                    // TODO - alter SQL so that this line is not needed.
                    $assessment->description = $assessment->summary;
                    $assessment->dynamic = false;
                    $assessment->count = false;
                    $this->mainobject->make_assessment_node($assessment, true);
                }
            }
        }
    }

    /**
     * This is to allow the ajax call to be sent to the correct function. When the
     * type of one of the pluggable modules is sent back via the ajax call, the ajax_marking_response constructor
     * will refer to this function in each of the module objects in turn from the default in the switch statement
     *
     * @param string $type the type name variable from the ajax call
     * @return bool
     */
    function return_function($type) {

        if (array_key_exists($type, $this->functions)) {
            $function = $this->functions[$type];
            $this->$function();
            return true;
        } else {
            return false;
        }
    }

    /**
     * Getter function for the levels stored as part of the
     * pluggable modules object
     *
     * @return int the number of levels
     */
    function levels() {

        //$levels = count($this->functions) + 2;
        return $this->levels;
    }

    /**
     * Rather than waste resources getting loads of students we don't need via get_role_users() then
     * cross referencing, we use this to drop the right SQL into a sub query. Without it, some large
     * installations hit a barrier using IN($course_students) e.g. oracle can't cope with more than
     * 1000 expressions in an IN() clause
     *
     * @param object $context the context object we want to get users for
     * @param bool $parent should we look in higher contexts too?
     */
    function get_role_users_sql($context, $parent=true, $paramtype=SQL_PARAMS_NAMED) {

        global $CFG, $DB;

        $parentcontexts = '';
        $parentcontextssql = '';
        // need an empty one for array_merge() later
        $parentcontextsparams = array();

        $parentcontexts = substr($context->path, 1); // kill leading slash
        // $parentcontexts = str_replace('/', ',', $parentcontexts);
        $parentcontexts = explode('/', $parentcontexts);

        if ($parentcontexts !== '') {
            //$parentcontexts = ' OR ra.contextid IN ('.$parentcontexts.' )';
            list($parentcontextssql, $parentcontextsparams) = $DB->get_in_or_equal($parentcontexts, $paramtype, 'param9000');
        }

        // get the roles that are specified as graded in site config settings. Will sometimes be here,
        // sometimes not depending on ajax call
        if (empty($this->mainobject->student_roles)) {
            $this->mainobject->student_roles = $DB->get_field('config','value', array('name'=>'gradebookroles'));
        }

//        if (is_array($this->mainobject->student_roles)) {
//            $roleselect = ' AND ra.roleid IN ('.implode(',', $this->mainobject->student_roles).')';
//        } elseif (!empty($this->mainobject->student_roles)) { // should not test for int, because it can come in as a string
//            $roleselect = "AND ra.roleid = {$this->mainobject->student_roles}";
//        } else {
//            $roleselect = '';
//        }

        // start this set of params at a later point to avoid collisions
        list($studentrolesql, $studentroleparams) = $DB->get_in_or_equal($this->mainobject->student_roles, $paramtype, 'param0900');

        $sql = " SELECT u.id
                   FROM {role_assignments} ra
                   JOIN {user} u
                     ON u.id = ra.userid
                   JOIN {role} r
                     ON ra.roleid = r.id
                  WHERE ra.contextid {$parentcontextssql}
                    AND ra.roleid {$studentrolesql}";

        $data = new stdClass;
        $data->params = $parentcontextsparams + $studentroleparams;
        $data->sql = $sql;
        return $data;

    }

    /**
     * Returns an SQL snippet that will tll us 
     */
    private function get_enrolled_student_sql() {




    }

   


}