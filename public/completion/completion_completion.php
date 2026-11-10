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
 * Course completion status for a particular user/course
 *
 * @package core_completion
 * @category completion
 * @copyright 2009 Catalyst IT Ltd
 * @author Aaron Barnes <aaronb@catalyst.net.nz>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/completion/data_object.php');

/**
 * Course completion status for a particular user/course
 *
 * @package core_completion
 * @category completion
 * @copyright 2009 Catalyst IT Ltd
 * @author Aaron Barnes <aaronb@catalyst.net.nz>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_completion extends data_object {

    /** Direct inherited writes also participate in the course barrier. */
    public function insert() {
        global $DB;
        $provider = '\\report_reportsflow\\local\\regulatory_completion_preservation_service';
        if (!class_exists($provider)) {
            return parent::insert();
        }
        $courseid = (int)$this->course;
        $userid = (int)$this->userid;
        if ($courseid < 1 || $userid < 1) {
            throw new \report_reportsflow\local\preservation_unavailable_exception('record_scope_invalid');
        }
        return $provider::with_course_barriers([$courseid], function() use ($DB, $provider, $courseid, $userid) {
            $provider::assert_course_barrier($courseid);
            if ((int)$this->course !== $courseid || (int)$this->userid !== $userid
                    || $DB->record_exists($this->table, ['course' => $courseid, 'userid' => $userid])) {
                throw new \report_reportsflow\local\preservation_unavailable_exception('record_scope_changed');
            }
            return parent::insert();
        });
    }

    /** Refuse a direct stale update that would change canonical identity or times. */
    public function update() {
        global $DB;
        $provider = '\\report_reportsflow\\local\\regulatory_completion_preservation_service';
        if (!class_exists($provider) || empty($this->id)) {
            return parent::update();
        }
        $old = $DB->get_record($this->table, ['id' => $this->id], '*', MUST_EXIST);
        $courseid = (int)$old->course;
        return $provider::with_course_barriers([$courseid], function() use ($DB, $provider, $old, $courseid) {
            $provider::assert_course_barrier($courseid);
            $fresh = $DB->get_record($this->table, ['id' => $this->id], '*', MUST_EXIST);
            if ((int)$fresh->course !== $courseid || (int)$fresh->userid !== (int)$old->userid
                    || (int)$this->course !== $courseid || (int)$this->userid !== (int)$old->userid
                    || ((int)$fresh->timeenrolled > 0 && (int)$this->timeenrolled !== (int)$fresh->timeenrolled)
                    || ((int)$fresh->timestarted > 0 && (int)$this->timestarted !== (int)$fresh->timestarted)
                    || ((int)$fresh->timecompleted > 0 && (int)$this->timecompleted !== (int)$fresh->timecompleted)) {
                throw new \report_reportsflow\local\preservation_unavailable_exception('record_scope_changed');
            }
            return parent::update();
        });
    }

    /** Direct deletion locks the row's authoritative course. */
    public function delete() {
        global $DB;
        $provider = '\\report_reportsflow\\local\\regulatory_completion_preservation_service';
        if (!class_exists($provider) || empty($this->id)) {
            return parent::delete();
        }
        $old = $DB->get_record($this->table, ['id' => $this->id], 'id,course,userid', MUST_EXIST);
        $courseid = (int)$old->course;
        return $provider::with_course_barriers([$courseid], function() use ($DB, $provider, $old, $courseid) {
            $provider::assert_course_barrier($courseid);
            $fresh = $DB->get_record($this->table, ['id' => $this->id], 'id,course,userid', MUST_EXIST);
            if ((int)$fresh->course !== $courseid || (int)$fresh->userid !== (int)$old->userid
                    || (int)$this->course !== $courseid || (int)$this->userid !== (int)$old->userid) {
                throw new \report_reportsflow\local\preservation_unavailable_exception('record_scope_changed');
            }
            return parent::delete();
        });
    }

    /* @var string $table Database table name that stores completion information */
    public $table = 'course_completions';

    /* @var array $required_fields Array of required table fields, must start with 'id'. */
    public $required_fields = array('id', 'userid', 'course',
        'timeenrolled', 'timestarted', 'timecompleted', 'reaggregate');

    /* @var int $userid User ID */
    public $userid;

    /* @var int $course Course ID */
    public $course;

    /* @var int Time of course enrolment {@link completion_completion::mark_enrolled()} */
    public $timeenrolled;

    /**
     * Time the user started their course completion {@link completion_completion::mark_inprogress()}
     * @var int
     */
    public $timestarted;

    /* @var int Timestamp of course completion {@link completion_completion::mark_complete()} */
    public $timecompleted;

    /* @var int Flag to trigger cron aggregation (timestamp) */
    public $reaggregate;

    /** @var float user's course grade. */
    public $gradefinal;

    /**
     * Finds and returns a data_object instance based on params.
     *
     * @param array $params associative arrays varname = >value
     * @return data_object instance of data_object or false if none found.
     */
    public static function fetch($params) {
        $cache = cache::make('core', 'coursecompletion');

        $key = $params['userid'] . '_' . $params['course'];
        if ($hit = $cache->get($key)) {
            return $hit['value'];
        }

        $tocache = self::fetch_helper('course_completions', __CLASS__, $params);
        $cache->set($key, ['value' => $tocache]);
        return $tocache;
    }

    /**
     * Return status of this completion
     *
     * @return bool
     */
    public function is_complete() {
        return (bool) $this->timecompleted;
    }

    /**
     * Mark this user as started (or enrolled) in this course
     *
     * If the user is already marked as started, no change will occur
     *
     * @param integer $timeenrolled Time enrolled (optional)
     * @return  int|null id of completion record on successful update.
     */
    public function mark_enrolled($timeenrolled = null) {

        if ($this->timeenrolled === null) {

            if ($timeenrolled === null) {
                $timeenrolled = time();
            }

            $this->timeenrolled = $timeenrolled;
        }

        return $this->_save();
    }

    /**
     * Mark this user as inprogress in this course
     *
     * If the user is already marked as inprogress, the time will not be changed
     *
     * @param integer $timestarted Time started (optional)
     * @return  int|null id of completion record on successful update.
     */
    public function mark_inprogress($timestarted = null) {

        $timenow = time();

        // Set reaggregate flag
        $this->reaggregate = $timenow;

        if (!$this->timestarted) {

            if (!$timestarted) {
                $timestarted = $timenow;
            }

            $this->timestarted = $timestarted;
        }

        return $this->_save();
    }

    /**
     * Mark this user complete in this course
     *
     * This generally happens when the required completion criteria
     * in the course are complete.
     *
     * @param integer $timecomplete Time completed (optional)
     * @return  int|null id of completion record on successful update.
     */
    public function mark_complete($timecomplete = null) {
        global $DB;
        $provider = '\\report_reportsflow\\local\\regulatory_completion_preservation_service';
        if ((int)$this->course > 0 && class_exists($provider)) {
            $courseid = (int)$this->course;
            return $provider::with_course_barriers([$courseid], function() use ($DB, $provider, $courseid, $timecomplete) {
                $provider::assert_course_barrier($courseid);
                // This object may have been fetched before B. A prior writer
                // may already have marked the same learner complete.
                $stored = $DB->get_record('course_completions',
                    ['course' => $courseid, 'userid' => (int)$this->userid]);
                if ($stored && (int)$stored->timecompleted > 0) {
                    $this->id = (int)$stored->id;
                    $this->timecompleted = (int)$stored->timecompleted;
                    return null;
                }
                return $this->mark_complete_under_flow_barrier($timecomplete);
            });
        }
        return $this->mark_complete_under_flow_barrier($timecomplete);
    }

    /** Existing Core completion workflow, called under B when ReportsFlow is installed. */
    private function mark_complete_under_flow_barrier($timecomplete = null) {
        global $USER;

        // Never change a completion time.
        if ($this->timecompleted) {
            return null;
        }

        // Use current time if nothing supplied.
        if (!$timecomplete) {
            $timecomplete = time();
        }

        // Set time complete.
        $this->timecompleted = $timecomplete;
        // Save record.
        if ($result = $this->_save()) {
            $data = $this->get_record_data();
            \core\event\course_completed::create_from_completion($data)->trigger();
        }

        // Notify user.
        $course = get_course($data->course);
        $messagesubject = get_string('coursecompleted', 'completion');
        $options = new stdClass();
        $options->context = context_course::instance($course->id);
        $a = [
            'coursename' => format_string(get_course_display_name_for_list($course), true, $options),
            'courselink' => (string) new moodle_url('/course/view.php', array('id' => $course->id)),
        ];
        $messagebody = get_string('coursecompletedmessage', 'completion', $a);
        $messageplaintext = html_to_text($messagebody);

        $eventdata = new \core\message\message();
        $eventdata->courseid          = $course->id;
        $eventdata->component         = 'moodle';
        $eventdata->name              = 'coursecompleted';
        $eventdata->userfrom          = core_user::get_noreply_user();
        $eventdata->userto            = $data->userid;
        $eventdata->notification      = 1;
        $eventdata->subject           = $messagesubject;
        $eventdata->fullmessage       = $messageplaintext;
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml   = $messagebody;
        $eventdata->smallmessage      = $messageplaintext;

        if ($courseimage = \core_course\external\course_summary_exporter::get_course_image($course)) {
            $eventdata->customdata  = [
                'notificationpictureurl' => $courseimage,
            ];
        }
        message_send($eventdata);

        return $result;
    }

    /**
     * Save course completion status
     *
     * This method creates a course_completions record if none exists
     * @access  private
     * @return  int|null id of completion record on successful update.
     */
    private function _save() {
        $provider = '\\report_reportsflow\\local\\regulatory_completion_preservation_service';
        if ((int)$this->course > 0 && class_exists($provider)) {
            $courseid = (int)$this->course;
            return $provider::with_course_barriers([$courseid], function() use ($provider, $courseid) {
                $provider::assert_course_barrier($courseid);
                return $this->_save_under_flow_barrier();
            });
        }
        return $this->_save_under_flow_barrier();
    }

    /** Existing Core record and cache write path. */
    private function _save_under_flow_barrier() {
        global $DB;
        $provider = '\\report_reportsflow\\local\\regulatory_completion_preservation_service';
        if ((int)$this->course > 0 && class_exists($provider)) {
            $provider::assert_course_barrier((int)$this->course);
            if (isset($this->id) && (int)$this->id < 1) {
                // Preserve data_object's invalid-ID refusal without touching a
                // real canonical row that now exists for this course and user.
                $this->update();
                return null;
            }
            $stored = $DB->get_record('course_completions',
                ['course' => (int)$this->course, 'userid' => (int)$this->userid]);
            if ($stored) {
                if (isset($this->id) && (int)$this->id !== (int)$stored->id) {
                    throw new \report_reportsflow\local\preservation_unavailable_exception('record_scope_changed');
                }
                $this->id = (int)$stored->id;
                // An object loaded before B must not undo an earlier enrollment,
                // start or completion when it saves its own later transition.
                if ((int)$stored->timeenrolled > 0) {
                    $this->timeenrolled = (int)$stored->timeenrolled;
                }
                if ((int)$stored->timestarted > 0) {
                    $this->timestarted = (int)$stored->timestarted;
                }
                if ((int)$stored->timecompleted > 0) {
                    $this->timecompleted = (int)$stored->timecompleted;
                }
                if ((int)$stored->reaggregate > (int)$this->reaggregate) {
                    $this->reaggregate = (int)$stored->reaggregate;
                }
            } else if (isset($this->id)) {
                throw new \report_reportsflow\local\preservation_unavailable_exception('record_scope_changed');
            }
        }
        if ($this->timeenrolled === null) {
            $this->timeenrolled = 0;
        }

        // Save record
        if (isset($this->id)) {
            $success = $this->update();
        } else {
            // Make sure reaggregate field is not null
            if (!$this->reaggregate) {
                $this->reaggregate = 0;
            }

            // Make sure timestarted is not null
            if (!$this->timestarted) {
                $this->timestarted = 0;
            }

            $success = $this->insert();
        }

        if ($success) {
            // Update the cached record.
            $cache = cache::make('core', 'coursecompletion');
            $data = $this->get_record_data();
            $key = $data->userid . '_' . $data->course;
            $cache->set($key, ['value' => $data]);
            return $this->id;
        }

        return null;
    }
}
