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
 * Main block file
 *
 * @package    block
 * @subpackage ajax_marking
 * @copyright  2008 Matt Gibson
 * @author     Matt Gibson {@link http://moodle.org/user/view.php?id=81450}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * This class builds a marking block on the front page which loads assignments and submissions
 * dynamically into a tree structure using AJAX. All marking occurs in pop-up windows and each node
 * removes itself from the tree after its pop up is graded.
 *
 * @copyright 2008-2010 Matt Gibson
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_ajax_marking extends block_base {

    /**
     * Standard init function, sets block title and version number
     *
     * @return void
     */
    function init() {
        $this->title = get_string('ajaxmarking', 'block_ajax_marking');
        $this->version = 2010050601;
    }

    /**
     * Standard specialization function
     *
     * @return void
     */
    function specialization() {
            $this->title = get_string('marking', 'block_ajax_marking');
    }

    /**
     * Standard get content function returns $this->content containing the block HTML etc
     *
     * @return object
     */
    function get_content() {

        if ($this->content !== null) {
            return $this->content;
        }
        
        global $CFG, $USER, $DB, $PAGE;

        require_once($CFG->dirroot.'/blocks/ajax_marking/lib.php');

        $courses = block_ajax_marking_get_my_teacher_courses();

        if (count($courses) > 0) { // Grading permissions exist in at least one course, so display the block

            //start building content output
            $this->content = new stdClass;

            // make the non-ajax list whatever happens. Then allow the AJAX tree to usurp it if
            // necessary
            require_once($CFG->dirroot.'/blocks/ajax_marking/actions/html_list.php');
            
            $this->content->text .= '<div id="block_ajax_marking_html_list">';
            $this->content->text .= $htmllist;
            $this->content->text .= '</div>';
            $this->content->footer = '';

            // Build the AJAX stuff on top of the plain HTML list
            //if ($CFG->enableajax && $USER->ajax && !$USER->screenreader) {

                // Add a style to hide the HTML list and prevent flicker
                $s  = '<script type="text/javascript" defer="defer">';
                $s .= '/* <![CDATA[ */ var styleElement = document.createElement("style");';
                $s .= 'styleElement.type = "text/css";';
                $s .= 'if (styleElement.styleSheet) {';
                $s .=     'styleElement.styleSheet.cssText = "#block_ajax_marking_html_list { display: none; }";';
                $s .= '} else {';
                $s .=     'styleElement.appendChild(document.createTextNode("#block_ajax_marking_html_list {display: none;}"));';
                $s .= '}';
                $s .= 'document.getElementsByTagName("head")[0].appendChild(styleElement);';
                $s .= '/* ]]> */</script>';

                $this->content->text .=  $s;

                $variables  = array(
                        'wwwroot'             => $CFG->wwwroot,
                        'totaltomark'         => get_string('totaltomark',         'block_ajax_marking'),
                        'userid'              => $USER->id,
                        'instructions'        => get_string('instructions',        'block_ajax_marking'),
                        'nogradedassessments' => get_string('nogradedassessments', 'block_ajax_marking'),
                        'nothingtomark'       => get_string('nothingtomark',       'block_ajax_marking'),
                        'refresh'             => get_string('refresh',             'block_ajax_marking'),
                        'configure'           => get_string('configure',           'block_ajax_marking'),
                        'connectfail'         => get_string('connectfail',         'block_ajax_marking'),
                        'nogroups'            => get_string('nogroups',            'block_ajax_marking'),
                        'settingsheadertext'  => get_string('settingsheadertext',  'block_ajax_marking'),
                        'fullname'            => fullname($USER),
                        'showthisassessment'  => get_string('showthisassessment',  'block_ajax_marking'),
                        'showthiscourse'      => get_string('showthiscourse',      'block_ajax_marking'),
                        'showwithgroups'      => get_string('showwithgroups',      'block_ajax_marking'),
                        'hidethisassessment'  => get_string('hidethisassessment',  'block_ajax_marking'),
                        'hidethiscourse'      => get_string('hidethiscourse',      'block_ajax_marking'),
                        'coursedefault'       => get_string('coursedefault',       'block_ajax_marking'),
                        'debuglevel'          => $CFG->debug
                );
                
                $jsmodule = array(
                    'name'     => 'block_ajax_marking',
                    'fullpath' => '/blocks/ajax_marking/module.js.php',
                    'requires' => array('yui2-treeview', 'yui2-button', 'yui2-connection', 'yui2-json'),
                    'strings' => array(
                        array('totaltomark',         'block_ajax_marking'),
                        array('instructions',        'block_ajax_marking'),
                        array('nogradedassessments', 'block_ajax_marking'),
                        array('nothingtomark',       'block_ajax_marking'),
                        array('refresh',             'block_ajax_marking'),
                        array('configure',           'block_ajax_marking'),
                        array('connectfail',       'block_ajax_marking'),
                        array('nogroups',       'block_ajax_marking'),
                        array('settingsheadertext',       'block_ajax_marking'),
                        array('showthisassessment',       'block_ajax_marking'),
                        array('showthiscourse',       'block_ajax_marking'),
                        array('showwithgroups',       'block_ajax_marking'),
                        array('hidethisassessment',       'block_ajax_marking'),
                        array('hidethiscourse',       'block_ajax_marking'),
                        array('coursedefault',       'block_ajax_marking'),
                        array('hidethiscourse',       'block_ajax_marking'),
                    )
                );

                $jsvariables  = "M.block_ajax_marking.variables = [];
                        var YAV = M.block_ajax_marking.variables;
                        YAV['wwwroot']             = '".$CFG->wwwroot."';
                        YAV['totaltomark']         = '".get_string('totaltomark',         'block_ajax_marking')."';
                        YAV['userid']              = '".$USER->id."';
                        YAV['instructions']        = '".get_string('instructions',        'block_ajax_marking')."';
                        YAV['nogradedassessments'] = '".get_string('nogradedassessments', 'block_ajax_marking')."';
                        YAV['nothingtomark']       = '".get_string('nothingtomark',       'block_ajax_marking')."';
                        YAV['refresh']             = '".get_string('refresh',             'block_ajax_marking')."';
                        YAV['configure']           = '".get_string('configure',           'block_ajax_marking')."';
                        YAV['connectfail']         = '".get_string('connectfail',         'block_ajax_marking')."';
                        YAV['nogroups']            = '".get_string('nogroups',            'block_ajax_marking')."';
                        YAV['settingsheadertext']  = '".get_string('settingsheadertext',  'block_ajax_marking')."';
                        YAV['fullname']            = '".fullname($USER)."';
                        YAV['showthisassessment']  = '".get_string('showthisassessment',  'block_ajax_marking')."';
                        YAV['showthiscourse']      = '".get_string('showthiscourse',      'block_ajax_marking')."';
                        YAV['showwithgroups']      = '".get_string('showwithgroups',      'block_ajax_marking')."';
                        YAV['hidethisassessment']  = '".get_string('hidethisassessment',  'block_ajax_marking')."';
                        YAV['hidethiscourse']      = '".get_string('hidethiscourse',      'block_ajax_marking')."';
                        YAV['coursedefault']       = '".get_string('coursedefault',       'block_ajax_marking')."';
                        YAV['debuglevel']          = '".$CFG->debug."';
                ";

                // for integrating the block_marking stuff, this stuff (divs) should all be created
                // by javascript.
                $this->content->text .= "
                    <div id='total'>
                        <div id='totalmessage'></div>
                        <div id='count'></div>
                        <div id='mainicon'></div>
                    </div>
                    <div id='status'></div>
                    <div id='treediv' class='yui-skin-sam'></div>";

                // Don't warn about javascript if the screenreader option is set - it was deliberate
                if (!$USER->screenreader) {
                    $this->content->text .= '<noscript><p>AJAX marking block prefers javascript, ';
                    $this->content->text .= 'but you have it turned off.</p></noscript>';
                }

//                if ($CFG->debug == DEBUG_DEVELOPER) {
//                    $PAGE->requires->yui2_lib('logger');
//                }

                //$PAGE->requires->js_init_call('M.block_ajax_marking.hide_html_list', null, true, $jsmodule);
                $PAGE->requires->js_init_call('M.block_ajax_marking.initialise', null, true, $jsmodule);
                
                // also need to add any js from individual modules, which the html list will
                // have provided

                $this->content->footer .= '<div id="block_ajax_marking_refresh_button"></div><div id="block_ajax_marking_configure_button"></div>';

           // }

        } else {
            // no grading permissions in any courses - don't display the block. Exception for
            // when the block is just installed and editing is on. Might look broken otherwise.
            if ($PAGE->user_is_editing()) {
                $this->content->text .= get_string('nogradedassessments', 'block_ajax_marking');
                $this->content->footer = '';
            } else {
                // this will stop the other functions like has_content() from running all the way through this again
                $this->content = false;
            }

        }
        return $this->content;
    }

    /**
     * Standard function - does the block allow configuration for specific instances of itself
     * rather than sitewide?
     *
     * @return bool false
     */
    function instance_allow_config() {
        return false;
    }

    /**
     * Runs the check for plugins after the first install.
     *
     * @return void
     */
    function after_install() {

        echo 'after install';

        global $CFG;

        include_once($CFG->dirroot.'/blocks/ajax_marking/db/upgrade.php');
        block_ajax_marking_update_modules();

    }
}