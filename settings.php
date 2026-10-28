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
 * Site settings for Evidence Checklist.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $p = 'mod_evidencechecklist';
    $settings->add(new admin_setting_heading("$p/intro", '', get_string('settings_intro', $p)));
    $settings->add(new admin_setting_heading(
        "$p/activation",
        get_string('activationheading', $p),
        get_string('acquisitiondescription', $p) . html_writer::tag('p', html_writer::link(
            new moodle_url('/mod/evidencechecklist/activate.php'),
            get_string('checkactivation', $p)
        ))
    ));
    $settings->add(new admin_setting_configtext(
        "$p/siteid",
        get_string('siteid', $p),
        get_string('activationcredentials', $p),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        "$p/apikey",
        get_string('apikey', $p),
        get_string('activationcredentials', $p),
        ''
    ));

    $settings->add(new admin_setting_heading("$p/assesshdr", get_string('assessmentheader', $p), ''));
    $settings->add(
        new admin_setting_configcheckbox(
            "$p/requiremappingdefault",
            get_string('requiremapping', $p),
            get_string('requiremappingdefault_desc', $p),
            1
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            "$p/forcemappinglock",
            get_string('forcemappinglock', $p),
            get_string('forcemappinglock_desc', $p),
            0
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            "$p/livestudentviewdefault",
            get_string('livestudentview', $p),
            get_string('livestudentview_help', $p),
            1
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            "$p/allowbulkdefault",
            get_string('allowbulk', $p),
            get_string('allowbulk_help', $p),
            1
        )
    );
    $settings->add(
        new admin_setting_configselect(
            "$p/defaultoccasions",
            get_string('defaultoccasions', $p),
            get_string('defaultoccasions_help', $p),
            1,
            array_combine(range(1, 5), range(1, 5))
        )
    );
    $settings->add(
        new admin_setting_configtextarea(
            "$p/defaultstatements",
            get_string('statements', $p),
            get_string('defaultstatements_desc', $p),
            get_string('defaultstatements', $p),
            PARAM_TEXT,
            80,
            9
        )
    );

    $settings->add(new admin_setting_heading("$p/helphdr", get_string('assessortips', $p), ''));
    $settings->add(
        new admin_setting_configcheckbox(
            "$p/tipsdefault",
            get_string('tipsdefault', $p),
            get_string('tipsdefault_desc', $p),
            1
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            "$p/dictation",
            get_string('dictation', $p),
            get_string('dictation_desc', $p),
            1
        )
    );

    $settings->add(new admin_setting_heading("$p/mediahdr", get_string('mediaheader', $p), ''));
    $settings->add(
        new admin_setting_configtext(
            "$p/imagemaxpx",
            get_string('imagemaxpx', $p),
            get_string('imagemaxpx_desc', $p),
            1600,
            PARAM_INT
        )
    );
    $settings->add(
        new admin_setting_configtext(
            "$p/maxvideoseconds",
            get_string('maxvideoseconds', $p),
            get_string('maxvideoseconds_desc', $p),
            120,
            PARAM_INT
        )
    );
    $settings->add(
        new admin_setting_configtext(
            "$p/maxuploadmb",
            get_string('maxuploadmb', $p),
            get_string('maxuploadmb_desc', $p),
            200,
            PARAM_INT
        )
    );
    $settings->add(
        new admin_setting_configexecutable(
            "$p/ffmpegpath",
            get_string('ffmpegpath', $p),
            get_string('ffmpegpath_desc', $p),
            ''
        )
    );

    $settings->add(new admin_setting_heading("$p/brandhdr", get_string('brandheader', $p), ''));
    $settings->add(
        new admin_setting_configcolourpicker(
            "$p/colourgreen",
            get_string('colourgreen', $p),
            get_string('colourgreen_desc', $p),
            '#20c75d'
        )
    );
    $settings->add(
        new admin_setting_configcolourpicker(
            "$p/colourmagenta",
            get_string('colourmagenta', $p),
            get_string('colourmagenta_desc', $p),
            '#c50180'
        )
    );

    $settings->add(new admin_setting_heading("$p/idhdr", get_string('identityheader', $p), ''));
    $settings->add(
        new admin_setting_configcheckbox(
            "$p/useverifyid",
            get_string('useverifyid', $p),
            get_string('useverifyid_desc', $p),
            1
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            "$p/verifyidsitewide",
            get_string('verifyidsitewide', $p),
            get_string('verifyidsitewide_desc', $p),
            1
        )
    );
}
