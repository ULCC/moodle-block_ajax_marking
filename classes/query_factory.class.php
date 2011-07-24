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
 * Class file for the Assignment grading functions
 * 
 * @package    block
 * @subpackage ajax_marking
 * @copyright  2011 Matt Gibson
 * @author     Matt Gibson {@link http://moodle.org/user/view.php?id=81450}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * This is to build a query based on the parameters passed into the constructor. Without parameters,
 * the query should return all unmarked items across all of the site.
 */
class block_ajax_marking_query_factory {
    
   /**
     * This is to build whatever query is needed in order to return the requested nodes. It may be necessary
     * to compose this query from quite a few different pieces. Without filters, this should return all 
     * unmarked work across the whole site for this teacher.
     * 
     * In:
     * - filters as an array. course, coursemodule, student, others (as defined by module base classes
     * 
     * Issues:
     * - maintainability: easy to add and subtract query filters
     * 
     * @global moodle_database $DB
     * @param array $filters list of functions to run on the query. Methods of this or the module class
     */
    public static function get_query($filters = array()) {

        global $DB;

        // if not a union query, we will want to remember which module we are narrowed down to so we 
        // can apply the postprocessing hook later
        $singlemoduleclass = false; 
        
        $queryarray = array();
        $moduleid = false;
        $moduleclasses = block_ajax_marking_get_module_classes();
        
        $filternames = array_keys($filters);

        // If one of the filters is coursemodule, then we want to avoid querying all of the module 
        // tables and just stick to the one with that coursemodule. If not, we do a UNION of all the modules
        if (in_array('coursemoduleid', $filternames)) {
            // Get the right module id
            $moduleid = $DB->get_field('course_modules', 'module', array('id' => $filters['coursemoduleid']));
        }

        
        foreach ($moduleclasses as $modname => $moduleclass) {
            
            /** @var $modclass block_ajax_marking_module_base */

            if ($moduleid) {
                if ($moduleclass->get_module_id() == $moduleid) {
                    // We only want this one
                    $queryarray[$modname] = $moduleclass->query_factory();
                    $singlemoduleclass = $moduleclass; // we will use this ref to get acces to non-union module functions
                } else {
                    continue; // not using this one, so skip it
                }

            } else {
                // we want all of them for the union
                $queryarray[$modname] = $moduleclass->query_factory();
            }
            
            // Apply all the standard filters
            // Keeping this here, instead of in the factory as we may wish to interfere with it in future
            self::apply_sql_enrolled_students($queryarray[$modname]);
            self::apply_sql_visible($queryarray[$modname]);
            self::apply_sql_display_settings($queryarray[$modname]);
            self::apply_sql_owncourses($queryarray[$modname]);
            
            // Apply any filters specific to this request. First one should be a GROUP BY, the rest need 
            // to be WHEREs. Strong assumption here that they have been passed in in the correct order
            // i.e. starting from the requested nodes, and moving back up the tree e.g. 'student', 
            // 'assessment', 'course'
            $groupby = false;
            foreach ($filters as $name => $value) {
                if ($name == 'nextnodefilter') {
                    $filterfunctionname = 'apply_'.$value.'_filter';
                    $groupby = $value;
                    // The new filter is in the form 'nextnodefilter => 'functionname'. We want to pass the name
                    // in with an empty value, so we rearrange it here.
                    $value = false;
                } else {
                    $filterfunctionname = 'apply_'.$name.'_filter';
                }
                
                // Find the function. Core ones are part of this class, others will be methods of the module object
                if (method_exists(__CLASS__, $filterfunctionname)) {
                    // Core stuff will be part of the factory object
                    self::$filterfunctionname($queryarray[$modname], $value);
                } else if (method_exists($moduleclass, $filterfunctionname)) {
                    // All core filters are methods of query_base and module specific ones will be 
                    // methods of the module-specific subclass. If we have one of these, it will
                    // always be accompanied by a coursemoduleid, so will only be called on the relevant
                    // module query and not the rest
                    // TODO does this still pass by reference even though it's part of an array?
                    $moduleclass->$filterfunctionname($queryarray[$modname], $value);
                    
                } else {
                    // Can't find the function. Assume it's non-essential data.
                    //print_error('Can\'t find the '.$filterfunctionname.' function.');
                }
                
            }
            
            // Sometimes, the module will want to customise the query a bit after all the filters are applied
            // but before it's run. This is mostly to affect what data comes in the SELECT part of the query
            $moduleclass->alter_query_hook($queryarray[$modname], $groupby);

        }

        if (count($queryarray) > 1) {
            // make a union query if it's not got a specific coursemodule as a filter
            
            // We need to get the SELECT part of the UNION, which will be identical to the one from 
            // any of the subqueries
            $firstquery = reset($queryarray);
            
            $selectstring = $firstquery->get_select(true);
            
            // We also need a GROUP BY
            $groupbystring = $firstquery->get_groupby(true);

            // Make an array of queries to join with UNION
            $unionquerystring = array();
            $unionqueryparams = array();

            foreach ($queryarray as $query) {
                $unionquerystring[] = $query->to_string();
                $unionqueryparams = array_merge($unionqueryparams, $query->get_params());
            }

            // Implode with UNION
            $unionquerystring = implode(' UNION ', $unionquerystring);
            
            // Wrap in an outer SELECT * so we can have an overall GROUP BY and ORDER BY
            $unionquerystring = "{$selectstring} FROM ({$unionquerystring}) AS unionquery {$groupbystring}";
            
            // This is here only so that the fully composed query can be copy/pasted from the debugger into 
            // an SQL gui if needed
            $debugquery = self::debuggable_query($unionquerystring, $unionqueryparams);
            
            return $DB->get_records_sql($unionquerystring, $unionqueryparams);

        } else {
            $query = reset($queryarray);
            $debugquery = self::debuggable_query($query->to_string(), $query->get_params());
            $nodes = $query->execute();
            
            $singlemoduleclass->postprocess_nodes_hook($nodes, $filters);
            
            return $nodes;
        }


    }
    
    /**
     * Applies the filter needed for course nodes or their descendants
     * 
     * @param block_ajax_marking_query_base $query 
     * @param int $courseid Optional. Will apply SELECT and GROUP BY for nodes if missing
     * @param bool $union If we are glueing many module queries together, we will need to 
     *                    run a wrapper query that will select from the UNIONed subquery
     * @return void|string
     */
    private static function apply_courseid_filter($query, $courseid = 0, $union = false) {
        
        $selects = array(
                array(
                    'table' => 'moduletable', 
                    'column' => 'course',
                    'alias' => 'courseid'),
                array(
                    'table' => 'sub', 
                    'column' => 'id',
                    'alias' => 'count',
                    'function' => 'COUNT'),
                array(
                    'table' => 'course', 
                    'column' => 'shortname',
                    'alias' => 'name'),
                array(
                    'table' => 'course', 
                    'column' => 'fullname',
                    'alias' => 'tooltip')
        );
        
        if (!$courseid) {
            // Apply SELECT clauses for course nodes
            if (!$union) {
                foreach ($selects as $select) {
                    $query->add_select($select);
                }
            } else { // we need to select just the aliases
                $selectstring = '';
                foreach ($selects as $select) {
                    $selectstring .= isset($select['function']) ? $select['function'].'(' : '';
                    $selectstring .= 'unionquery.'.$select['alias'];
                    $selectstring .= isset($select['function']) ? ')' : '';
                }
            }

        } else {
            // Apply WHERE clause
            $query->add_where(array('type' => 'AND', 'condition' => 'moduletable.course = :'.$query->prefix_param_name('courseid')));
            $query->add_param('courseid', $courseid);
            
        }
        
    }
    
    /**
     * Applies the filter needed for assessment nodes or their descendants
     * 
     * @param block_ajax_marking_query_base $query 
     * @param int $coursemoduleid optional. Will apply SELECT and GROUP BY for nodes if missing
     * @return void
     */
    private static function apply_coursemoduleid_filter($query, $coursemoduleid = 0) {
        
        if (!$coursemoduleid) {
            
            // Same order as the next query will need them 
            $selects = array(
                array(
                    'table' => 'cm', 
                    'column' => 'id',
                    'alias' => 'coursemoduleid'),
                array(
                    'table' => 'sub', 
                    'column' => 'id',
                    'alias' => 'count',
                    'function' => 'COUNT'),
                array(
                    'column' => 'COALESCE(bama.display, bamc.display, 1)',
                    'alias' => 'display'),
                array(
                    'table' => 'moduletable', 
                    'column' => 'id',
                    'alias' => 'assessmentid'),
                array(
                    'table' => 'moduletable', 
                    'column' => 'name'),
                array(
                    'table' => 'moduletable', 
                    'column' => 'intro',
                    'alias' => 'tooltip'),
                // This is only needed to add the right callback function. 
                array(
                    'column' => "'".$query->get_modulename()."'",
                    'alias' => 'modulename'
                    )
            );
            
            foreach ($selects as $select) {
                $query->add_select($select);
            }
            
        } else {
            // Apply WHERE clause
            $query->add_where(array(
                    'type' => 'AND', 
                    'condition' => 'cm.id = :'.$query->prefix_param_name('coursemoduleid')));
            $query->add_param('coursemoduleid', $coursemoduleid);
            
        }
    }
    
    
    /**
     * This is not used for output, but just converts the parametrised query to one that can be copy/pasted
     * into an SQL GUI in order to debug SQL errors
     * 
     * @param string $query
     * @param array $params
     * @global type $CFG 
     * @return string
     */
    private function debuggable_query($query, $params) {
        
        global $CFG;
        
        // Substitute all the {tablename} bits
        $query = preg_replace('/\{/', $CFG->prefix, $query);
        $query = preg_replace('/}/', '', $query);
        
        // Now put all the params in place
        foreach ($params as $name => $value) {
            $pattern = '/:'.$name.'/';
            $replacevalue = (is_numeric($value) ? $value : "'".$value."'");
            $query = preg_replace($pattern, $replacevalue, $query);
        }
        
        return $query;
        
    }
    

//    public function apply_userid_filter(block_ajax_marking_query_base $query, $userid) {
//        
//        if (!$userid) { // display submissions - final nodes
//        
//            $data = new stdClass;
//            $data->nodetype = 'submission';
//
//            $usercolumnalias   = $query->get_userid_column();
////            $uniquecolumn      = $this->get_sql_submissions_unique_column();
////            $extramoduleselect = $this->get_sql_submissions_select($params['assessmentid']);
//
//            $selects = array(
//                array(
//                    'table' => 'sub', 
//                    'column' => 'id',
//                    'alias' => 'subid'),
////                array(
////                    'table' => 'cm', 
////                    'column' => 'id',
////                    'alias' => 'coursemoduleid'),
//                array( // Count in case we have user as something other than the last node
//                    'function' => 'COUNT',
//                    'table'    => 'sub',
//                    'column'   => 'id',
//                    'alias'    => 'count'),
////                array(
////                    'table' => 'moduletable', 
////                    'column' => 'intro',
////                    'alias' => 'description'),
//                array(
//                    'table' => 'sub', 
//                    'column' => 'timemodified',
//                    'alias' => 'time'),
//                array(
//                    'table' => 'sub', 
//                    'column' => 'userid'),
//                    // This is only needed to add the right callback function. 
//                array(
//                    'column' => "'".$query->get_modulename()."'",
//                    'alias' => 'modulename'
//                    )
//            );
//            
//            foreach ($selects as $select) {
//                $query->add_select($select);
//            }
//            
//            $query->add_from(array(
//                    'join' => 'INNER JOIN',
//                    'table' => 'user',
//                    'on' => 'user.id = '.$usercolumnalias
//            ));
//            
//        } else {
//            // Not sure we'll ever need this, but just in case...
//            $query->add_where(array(
//                    'type' => 'AND', 
//                    'condition' => 'sub.id = :'.$query->prefix_param_name('submissionid')));
//            $query->add_param('submissionid', $userid);
//        }
//    }
    
    
    /**
     * We need to check whether the assessment can be displayed (the user may have hidden it).
     * This sql can be dropped into a query so that it will get the right students
     * 
     * @param block_ajax_marking_query_base $query a query object to apply these changes to
     * @return void
     */
    protected function apply_sql_display_settings($query) {
        
        $query->add_from(array(
                'join' => 'LEFT JOIN',
                'table' => 'block_ajax_marking',
                'alias' => 'bama',
                'on' => 'cm.id = bama.coursemoduleid'
        ));
        $query->add_from(array(
                'join' => 'LEFT JOIN',
                'table' => 'block_ajax_marking',
                'alias' => 'bamc',
                'on' => 'moduletable.course = bamc.courseid'
        ));
                        
        // either no settings, or definitely display
        // TODO doesn't work without proper join table for groups
                            
        // student might be a member of several groups. As long as one group is in the settings table, it's ok.
        // TODO is this more or less efficient than doing an inner join to a subquery?
                        
        // WHERE starts with the course defaults in case we find no assessment preference
        // Hopefully short circuit evaluation will makes this efficient.
        
        // TODO can this be made more elegant with a recursive bit in get_where() ?
        $useridfield = $query->get_userid_column();
        $groupsubquerya = self::get_sql_groups_subquery('bama', $useridfield);
        $groupsubqueryc = self::get_sql_groups_subquery('bamc', $useridfield); // EXISTS ([user in relevant group])
        $query->add_where(array(
                'type' => 'AND', 
                
                // Logic: show if we have:
                // - no item settings records, or a setting set to 'default' (legacy need)
                // - a course settings record that allows display
                'condition' => "(   bama.display = ".BLOCK_AJAX_MARKING_CONF_SHOW."
                    
                                    OR
                                    
                                    ( bama.display = ".BLOCK_AJAX_MARKING_CONF_GROUPS." AND {$groupsubquerya} )
                                    
                                    OR
                                    
                                    ( ( bama.display IS NULL OR bama.display = ".BLOCK_AJAX_MARKING_CONF_DEFAULT." ) 
                                        
                                        AND 
                                        
                                        ( bamc.display IS NULL 
                                          OR bamc.display = ".BLOCK_AJAX_MARKING_CONF_SHOW."
                                          OR (bamc.display = ".BLOCK_AJAX_MARKING_CONF_GROUPS. " AND {$groupsubqueryc})
                                        )
                                    ) 
                                 )"));

    }
    
    /**
     * All modules have a common need to hide work which has been submitted to items that are now hidden.
     * Not sure if this is relevant so much, but it's worth doing so that test data and test courses don't appear.
     * 
     * @return array The join string, where string and params array. Note, where starts with 'AND'
     */
    protected function apply_sql_visible(block_ajax_marking_query_base $query) {
        
        global $DB;
        
        $query->add_from(array(
                'join' => 'INNER JOIN',
                'table' => 'course_modules',
                'alias' => 'cm',
                'on' => 'cm.instance = moduletable.id'
        ));
        $query->add_from(array(
                'join' => 'INNER JOIN',
                'table' => 'course',
                'alias' => 'course',
                'on' => 'course.id = moduletable.course'
        ));
        
        // Get coursemoduleids for all items of this type in all courses as one query
        $courses = block_ajax_marking_get_my_teacher_courses();  // There will be some courses, or we would not be here
        
        list($coursesql, $params) = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED);
        
        // Get all coursemodules the current user could potentially access. 
        // TODO this may return literally millions for a whole site admin. Change it to the one that's 
        // limited by explicit category and course permissions
        $sql = "SELECT id 
                  FROM {course_modules}
                 WHERE course {$coursesql}
                   AND module = :moduleid";
        $params['moduleid'] = $query->get_module_id();
        $coursemoduleids = $DB->get_records_sql($sql, $params); // no point caching - only one request per module per page request
        // Get all contexts (will cache them)
        $contexts = get_context_instance(CONTEXT_MODULE, array_keys($coursemoduleids));
        // Use has_capability to loop through them finding out which are blocked. Unset all that we have
        // parmission to grade, leaving just those we are not allowed (smaller list)
        foreach ($contexts as $key => $context) {
            
            if (has_capability($query->get_capability(), $context)) { // this is fast because contexts are cached
                unset($contexts[$key]);
            }
        }
        // return a get_in_or_equals with NOT IN if there are any, or empty strings if there arent.
        if (!empty($contexts)) {
            list($returnsql, $returnparams) = $DB->get_in_or_equal(array_keys($contexts), SQL_PARAMS_NAMED, 'context0000', false);
            $query->add_where(array('type' => 'AND', 'condition' => "cm.id {$permissionsql}"));
            $query->add_params($params);
        }
        
        $query->add_where(array('type' => 'AND', 'condition' => 'cm.module = :'.$query->prefix_param_name('visiblemoduleid')));
        $query->add_where(array('type' => 'AND', 'condition' => 'cm.visible = 1'));
        $query->add_where(array('type' => 'AND', 'condition' => 'course.visible = 1'));
        
        
        $query->add_param('visiblemoduleid', $query->get_module_id());
        
    }
    
    /**
     * Makes sure we only get stuff for the courses this user is a teacher in
     * 
     * @param block_ajax_marking_query_base $query 
     * @return void
     */
    private function apply_sql_owncourses(block_ajax_marking_query_base $query) {
        
        global $DB;
        
        $courses = block_ajax_marking_get_my_teacher_courses();
        
        $courseids = array_keys($courses);
        
        if ($courseids) {
            $startname = $query->prefix_param_name('courseid0000');
            list($sql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, $startname);
        
            $query->add_where(array(
                    'type' => 'AND', 
                    'condition' => "course.id {$sql}"));
            $query->add_params($params, false);
        }
    }
    
    /**
     * Returns an SQL snippet that will tell us whether a student is enrolled in this course
     * Needs to also check parent contexts.
     * 
     * @param string $useralias the thing that contains the userid e.g. s.userid
     * @param string $moduletable the thing that contains the courseid e.g. a.course
     * @return array The join and where strings, with params. (Where starts with 'AND)
     */
    private function apply_sql_enrolled_students(block_ajax_marking_query_base $query) {
        
        global $DB, $CFG, $USER;
        
        $usercolumn = $query->get_userid_column();

        // TODO Hopefully, this will be an empty string when none are enabled
        if ($CFG->enrol_plugins_enabled) {
            // returns list of english names of enrolment plugins
            $plugins = explode(',', $CFG->enrol_plugins_enabled);
            $startparam = $query->prefix_param_name('enrol001');
            list($enabledsql, $params) = $DB->get_in_or_equal($plugins, SQL_PARAMS_NAMED, $startparam);
            $query->add_params($params, false);
        } else {
            // no enabled enrolment plugins
            $enabledsql = ' = :'.$query->prefix_param_name('never');
            $query->add_param('never', -1);
        }
        
        $query->add_from(array(
                'join' => 'INNER JOIN',
                'table' => 'user_enrolments',
                'alias' => 'ue',
                'on' => "ue.userid = {$usercolumn}"
        ));
        $query->add_from(array(
                'join' => 'INNER JOIN',
                'table' => 'enrol',
                'alias' => 'e',
                'on' => 'e.id = ue.enrolid'
        ));

        $query->add_where(array('type' => 'AND', 'condition' => 'e.courseid = moduletable.course'));
        $query->add_where(array('type' => 'AND', 'condition' => "{$usercolumn} != :".$query->prefix_param_name('currentuser')));
        $query->add_where(array('type' => 'AND', 'condition' => "e.enrol {$enabledsql}"));
        
        $query->add_param('currentuser', $USER->id);
        
    }
    
    /**
     * Provides an EXISTS(xxx) subquery that tells us whether there is a group with user x in it
     * 
     * @param string $configalias this is the alias of the config table in the SQL
     * @return string SQL fragment 
     */
    private function get_sql_groups_subquery($configalias, $useridfield) {
        
        $groupsql = " EXISTS (SELECT 1 
                                FROM {groups_members} gm
                          INNER JOIN {groups} g
                                  ON gm.groupid = g.id 
                          INNER JOIN {block_ajax_marking_groups} gs
                                  ON g.id = gs.groupid
                               WHERE gm.userid = {$useridfield}
                                 AND gs.configid = {$configalias}.id) ";
                                 
        return $groupsql;                         
        
    }


}


?>
