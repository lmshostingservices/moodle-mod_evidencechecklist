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
 * Activity settings form.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Evidence Checklist settings form.
 */
class mod_evidencechecklist_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        global $DB;
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'checklistname', 'mod_evidencechecklist');
        $this->standard_intro_elements();

        $mform->addElement('header', 'unithdr', get_string('unitheader', 'mod_evidencechecklist'));
        $mform->addElement('static', 'unitinfo', '', get_string('unitheader_help', 'mod_evidencechecklist'));
        $mform->addElement('text', 'unitcode', get_string('unitcode', 'mod_evidencechecklist'), ['size' => 20]);
        $mform->setType('unitcode', PARAM_TEXT);
        $mform->addHelpButton('unitcode', 'unitcode', 'mod_evidencechecklist');
        $mform->addElement('text', 'unittitle', get_string('unittitle', 'mod_evidencechecklist'), ['size' => 64]);
        $mform->setType('unittitle', PARAM_TEXT);
        $mform->addHelpButton('unittitle', 'unittitle', 'mod_evidencechecklist');
        $mform->addElement('textarea', 'setting', get_string('setting', 'mod_evidencechecklist'), ['rows' => 2, 'cols' => 60]);
        $mform->setType('setting', PARAM_TEXT);
        $mform->addHelpButton('setting', 'setting', 'mod_evidencechecklist');
        $mform->addElement('textarea', 'equipment', get_string('equipment', 'mod_evidencechecklist'), ['rows' => 2, 'cols' => 60]);
        $mform->setType('equipment', PARAM_TEXT);
        $mform->addHelpButton('equipment', 'equipment', 'mod_evidencechecklist');
        $mform->addElement(
            'select',
            'defaultoccasions',
            get_string('defaultoccasions', 'mod_evidencechecklist'),
            array_combine(range(1, 5), range(1, 5))
        );
        $mform->setDefault('defaultoccasions', (int)(get_config('mod_evidencechecklist', 'defaultoccasions') ?: 1));
        $mform->addHelpButton('defaultoccasions', 'defaultoccasions', 'mod_evidencechecklist');

        $mform->addElement('header', 'assesshdr', get_string('assessmentheader', 'mod_evidencechecklist'));
        $forced = (bool)get_config('mod_evidencechecklist', 'forcemappinglock');
        $mform->addElement('advcheckbox', 'requiremapping', get_string('requiremapping', 'mod_evidencechecklist'));
        $mform->addHelpButton('requiremapping', 'requiremapping', 'mod_evidencechecklist');
        $mform->setDefault('requiremapping', (int)get_config('mod_evidencechecklist', 'requiremappingdefault'));
        if ($forced) {
            $mform->hardFreeze('requiremapping');
            $mform->setConstant('requiremapping', 1);
        }
        $mform->addElement('advcheckbox', 'livestudentview', get_string('livestudentview', 'mod_evidencechecklist'));
        $mform->addHelpButton('livestudentview', 'livestudentview', 'mod_evidencechecklist');
        $mform->setDefault('livestudentview', (int)get_config('mod_evidencechecklist', 'livestudentviewdefault'));
        $mform->addElement('advcheckbox', 'allowbulk', get_string('allowbulk', 'mod_evidencechecklist'));
        $mform->addHelpButton('allowbulk', 'allowbulk', 'mod_evidencechecklist');
        $mform->setDefault('allowbulk', (int)get_config('mod_evidencechecklist', 'allowbulkdefault'));
        $mform->addElement('advcheckbox', 'requirephoto', get_string('requirephoto', 'mod_evidencechecklist'));
        $mform->addHelpButton('requirephoto', 'requirephoto', 'mod_evidencechecklist');

        $mform->addElement('header', 'statementshdr', get_string('statementsheader', 'mod_evidencechecklist'));
        $mform->addElement(
            'textarea',
            'statements',
            get_string('statements', 'mod_evidencechecklist'),
            ['rows' => 9, 'cols' => 80]
        );
        $mform->setType('statements', PARAM_TEXT);
        $mform->addHelpButton('statements', 'statements', 'mod_evidencechecklist');
        $mform->setDefault('statements', \mod_evidencechecklist\local\defaults::statements());

        $this->standard_grading_coursemodule_elements();
        if (empty($this->current->instance)) {
            $scaleid = $DB->get_field_select(
                'scale',
                'id',
                'courseid = 0 AND ' . $DB->sql_like('name', ':name', false),
                ['name' => '%competence%'],
                IGNORE_MULTIPLE
            );
            if ($scaleid) {
                $mform->setDefault('grade', -$scaleid);
            }
        }

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Completion rules.
     *
     * @return array
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $name = 'completioncompetent' . $suffix;
        $mform->addElement('advcheckbox', $name, '', get_string('completioncompetent', 'mod_evidencechecklist'));
        $mform->addHelpButton($name, 'completioncompetent', 'mod_evidencechecklist');
        return [$name];
    }

    /**
     * Is a completion rule enabled?
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completioncompetent' . $this->get_suffix()]);
    }

    /**
     * Unset the rule when automatic completion is off.
     *
     * @param stdClass $data
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $autocompletion = !empty($data->{'completion' . $suffix}) &&
                $data->{'completion' . $suffix} == COMPLETION_TRACKING_AUTOMATIC;
            if (!$autocompletion) {
                $data->{'completioncompetent' . $suffix} = 0;
            }
        }
    }
}
