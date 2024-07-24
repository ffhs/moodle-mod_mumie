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

namespace mod_mumie\local\hook\output;

/**
 * Hook to allow adding HTML content to the top of the page body.
 *
 * @package    mod_mumie
 * @copyright  2017-2024 integral-learning GmbH (https://www.integral-learning.de/)
 * @author     Sascha Vogel (sascha.vogel@ffhs.ch)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_standard_top_of_body_html_generation {

    /**
     * Callback to add HTML content to the top of the page body.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     * @return void
     */
    public static function callback(\core\hook\output\before_standard_top_of_body_html_generation $hook): void {
        global $PAGE, $CFG;

        if (!strpos($PAGE->url, '/grade/report/')) {
            return;
        }

        require_once($CFG->dirroot . '/mod/mumie/gradesync.php');
        \mod_mumie\gradesync::update();
    }
}
