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
 * Assessor guidance tips: where they appear and their sources. Text lives in language strings.
 *
 * @module     mod_evidencechecklist/tips
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const ASQA = 'https://www.asqa.gov.au/';
const GUIDE = ASQA + 'sites/default/files/2025-08/practice-guide-assessment.pdf';

export const LINKS = {
    competency: GUIDE,
    nograde: ASQA + 'sites/default/files/2020-02/Understanding%20assessment%20video%20transcript.pdf',
    benchmark: GUIDE,
    rules: GUIDE,
    principles: GUIDE,
    dimensions: 'https://ontargetworkskills.com/australias-vet-system/dimensions-of-competency/',
    knowledge: GUIDE,
    notobserved: GUIDE,
    simulation: 'https://training.gov.au/',
    verbal: GUIDE,
    bulk: GUIDE,
    nys: GUIDE,
    thirdparty: ASQA + 'sites/default/files/2020-01/FACT_SHEET_Using_other_parties_to_collect_assessment_evidence.pdf',
    identity: GUIDE,
    signoff: GUIDE,
    credential: ASQA + 'sites/default/files/2025-10/Practice%20Guide%20-%20Credential%20Policy.pdf',
    retention: 'https://asqa.gov.au/sites/default/files/2023-04/' +
        'Guidance%20for%20Providers%20-%20Retention%20requirements%20for%20' +
        'completed%20student%20assessment%20items.pdf',
};

/** Order used by the Guide panel. */
export const ORDER = ['competency', 'nograde', 'benchmark', 'rules', 'principles', 'dimensions', 'knowledge',
    'notobserved', 'simulation', 'verbal', 'bulk', 'nys', 'thirdparty', 'identity', 'signoff', 'credential', 'retention'];
