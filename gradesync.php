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
 * This file defines the class gradesync used to
 *
 * @package mod_mumie
 * @copyright  2019 integral-learning GmbH (https://www.integral-learning.de/)
 * @author Tobias Goltz (tobias.goltz@integral-learning.de)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_mumie;

defined('MOODLE_INTERNAL') || die();

require_once ($CFG->dirroot . '/mod/mumie/lib.php');
require_once ($CFG->dirroot . '/auth/mumie/lib.php');
/**
 * This file defines the class gradesync
 *
 * @package mod_mumie
 * @copyright  2019 integral-learning GmbH (https://www.integral-learning.de/)
 * @author Tobias Goltz (tobias.goltz@integral-learning.de)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradesync {
    /**
     * Update grades for all MUMIE tasks that are currently displayed in the gradebook
     * @return void
     */
    public static function update() {
        global $PAGE, $USER, $COURSE;

        $isreportpage = strpos($PAGE->url, 'grader/index.php') !== false || strpos($PAGE->url, 'user/index.php') !== false;
        $isoverviewpage = (strpos($PAGE->url, 'overview/index.php') !== false);

        if ($isreportpage) {
            $userid = $USER->id;
            if (has_capability("mod/mumie:viewgrades", \context_course::instance($COURSE->id), $USER)) {
                // User id = 0 => update grades for all users!
                $userid = 0;
            }
            foreach (self::get_mumie_tasks_from_course($COURSE->id) as $mumie) {
                mumie_update_grades($mumie, $userid);
            }
        } else if ($isoverviewpage) {
            foreach (self::get_all_mumie_tasks_for_user($USER->id) as $mumie) {
                mumie_update_grades($mumie, $USER->id);
            }
        }
        return;
    }

    /**
     * Get all MUMIE tasks that are used in this course
     * @param int $courseid
     * @return array All MUMIE tasks that are used in the given course
     */
    public static function get_mumie_tasks_from_course($courseid) {
        global $DB;
        return $DB->get_records(MUMIE_TASK_TABLE, array("course" => $courseid));
    }

    /**
     * Get all MUMIE tasks, that are in courses the user is enrolled in
     *
     * @param int $userid
     * @return array All MUMIE tasks that are in courses, the user is enrolled in
     */
    public static function get_all_mumie_tasks_for_user($userid) {

        $allmumietasks = array();
        foreach (enrol_get_all_users_courses($userid) as $course) {
            $mumietasks = self::get_mumie_tasks_from_course($course->id);
            if (count($mumietasks) > 0) {
                $allmumietasks = array_merge($allmumietasks, $mumietasks);
            }
        }

        return $allmumietasks;
    }

    /**
     * Get grades from MUMIE server for a given MUMIE task
     *
     * @param stdClass $mumie instace of MUMIE task
     * @param int $userid If userid = 0, update all users
     * @return array grades for the given MUMIE task
     */
    public static function get_mumie_grades($mumie, $userid) {
        global $COURSE, $DB;
        $url = $mumie->server . '/public/xapi';
        $syncids = array();

        if ($userid == 0) {
            foreach (get_enrolled_users(\context_course::instance($COURSE->id)) as $user) {
                array_push($syncids, self::get_sync_id($user->id, $mumie->use_hashed_id));
            }
        } else {
            $syncids = array(self::get_sync_id($userid, $mumie->use_hashed_id));
        }

        $mumieids = array(self::get_mumie_id($mumie));
        $grades = array();
        $xapigrades = self::get_xapi_grades($mumie, $syncids, $mumieids);

        if (is_null($xapigrades)) {
            return null;
        }

        foreach ($xapigrades as $xapigrade) {
            $grade = new \stdClass();
            $grade->userid = self::get_moodle_user_id($xapigrade->actor->account->name, $mumie->use_hashed_id);
            $grade->rawgrade = 100 * $xapigrade->result->score->raw;

            $grades[$grade->userid] = $grade;
        }

        return $grades;
    }

    /**
     * Get xapi grades for a MUMIE task instance
     *
     * @param stdClass $mumie instance of MUMIE task we want to get grades for
     * @param array $syncids all users we want grades for
     * @param array $mumieids mumieid of mumie instance as an array
     * @return stdClass all requested grades for the given MUMIE task
     */
    public static function get_xapi_grades($mumie, $syncids, $mumieids) {
        $payload = json_encode(array("users" => $syncids, "course" => $mumie->mumie_coursefile,
            "objectIds" => $mumieids, "lastSync" => $mumie->lastsync));
        $ch = self::create_post_curl_request($mumie->server . "/public/xapi", $payload);
        $result = curl_exec($ch);

        curl_close($ch);
        return json_decode($result);
    }

    /**
     * Get a unique syncid from a userid that can be used on the MUMIE server as username
     * @param int $userid
     * @param int $hashid indicates whether the id should be hashed
     * @return string unique username for MUMIE servers derived from the moodle userid
     */
    public static function get_sync_id($userid, $hashid) {
        global $DB;
        $org = get_config('auth_mumie', 'mumie_org');
        if ($hashid == 1) {
            $hashidtable = 'auth_mumie_id_hashes';
            $hash = auth_mumie_get_hashed_id($userid);
            if (!$DB->get_record($hashidtable, array("the_user" => $userid))) {
                $DB->insert_record($hashidtable, array("the_user" => $userid, "hash" => $hash));
            }
            $userid = $hash;
        }
        return "GSSO_" . $org . "_" . $userid;
    }

    /**
     * Get moodleUserID from syncid
     * @param string $syncid
     * @param int $hashid indicates whether the id was hashed
     * @return string of moodle user
     */
    public static function get_moodle_user_id($syncid, $hashid) {
        $userid = substr(strrchr($syncid, "_"), 1);
        $hashidtable = 'auth_mumie_id_hashes';
        if ($hashid == 1) {
            global $DB;
            $userid = $DB->get_record($hashidtable, array("hash" => $userid))->the_user;
        }
        return $userid;
    }

    /**
     * Get the unique identifier for a MUMIE task
     *
     * @param stdClass $mumietask
     * @return string id for MUMIE task on MUMIE server
     */
    public static function get_mumie_id($mumietask) {
        $id = substr($mumietask->taskurl, strlen("link/"));
        $id = substr($id, 0, strpos($id, '?lang='));
        return $id;
    }

    /**
     * Creates a curl post request for a given url and json payload
     *
     * @param string $url
     * @param string $payload as json
     * @return cURL curl handle for json payload
     */
    public static function create_post_curl_request($url, $payload) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_USERAGENT, "My User Agent Name");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            "X-API-Key: " . get_config('auth_mumie', 'mumie_api_key'),
        )
        );

        return $ch;
    }
}
