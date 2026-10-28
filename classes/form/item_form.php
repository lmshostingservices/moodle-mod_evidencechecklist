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

namespace mod_evidencechecklist\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Criterion edit form.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_form extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $p = 'mod_evidencechecklist';
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'itemid');
        $mform->setType('itemid', PARAM_INT);
        $mform->addElement('textarea', 'criterion', get_string('criterion', $p), ['rows' => 2, 'cols' => 70]);
        $mform->addHelpButton('criterion', 'criterion', $p);
        $mform->setType('criterion', PARAM_TEXT);
        $mform->addRule('criterion', null, 'required', null, 'client');
        $mform->addElement('textarea', 'benchmark', get_string('benchmark', $p), ['rows' => 3, 'cols' => 70]);
        $mform->addHelpButton('benchmark', 'benchmark', $p);
        $mform->setType('benchmark', PARAM_TEXT);
        $mform->addElement('textarea', 'gaptip', get_string('gaptip', $p), ['rows' => 2, 'cols' => 70]);
        $mform->addHelpButton('gaptip', 'gaptip', $p);
        $mform->setType('gaptip', PARAM_TEXT);
        $mform->addElement('text', 'mappingrefs', get_string('mappingrefs', $p), ['size' => 40]);
        $mform->addHelpButton('mappingrefs', 'mappingrefs', $p);
        $mform->setType('mappingrefs', PARAM_TEXT);
        $mform->addElement('text', 'contexts', get_string('contexts', $p), ['size' => 40]);
        $mform->setType('contexts', PARAM_TEXT);
        $mform->addHelpButton('contexts', 'contexts', $p);
        $mform->setDefault('contexts', 'All');
        $mform->addElement('advcheckbox', 'mandatory', get_string('mandatory', $p));
        $mform->addHelpButton('mandatory', 'mandatory', $p);
        $mform->addElement('advcheckbox', 'verbal', get_string('verbalitem', $p));
        $mform->addHelpButton('verbal', 'verbalitem', $p);
        $mform->addElement(
            'filemanager',
            'itemimage',
            get_string('referenceimage', $p),
            null,
            ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['image']]
        );
        $mform->addHelpButton('itemimage', 'referenceimage', $p);
        $this->add_action_buttons();
    }
}
