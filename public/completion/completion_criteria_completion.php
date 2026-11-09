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
 * Completion data for a specific user, course and critieria
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
 * Completion data for a specific user, course and critieria
 *
 * @package core_completion
 * @category completion
 * @copyright 2009 Catalyst IT Ltd
 * @author Aaron Barnes <aaronb@catalyst.net.nz>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_criteria_completion extends data_object {

    /** Lock the owning course before a criterion-completion insert. */
    public function insert() {
        return $this->with_flow_criterion_completion_barrier(fn() => parent::insert(), false, true);
    }

    /** Lock old and new owning courses before a criterion-completion update. */
    public function update() {
        return $this->with_flow_criterion_completion_barrier(fn() => parent::update(), true, true);
    }

    /** Lock the authoritative owning course before deletion. */
    public function delete() {
        return $this->with_flow_criterion_completion_barrier(fn() => parent::delete(), true, false);
    }

    /** Validate the criterion binding after B and before any inherited DB writer. */
    private function with_flow_criterion_completion_barrier(callable $operation, bool $existing, bool $targetcriterion) {
        global $DB;
        $provider = '\\report_reportsflow\\local\\regulatory_completion_preservation_service';
        if (!class_exists($provider) || ($existing && empty($this->id))) {
            return $operation();
        }
        $old = $existing ? $DB->get_record($this->table, ['id' => $this->id],
            'id,course,criteriaid', MUST_EXIST) : null;
        $courseid = $targetcriterion ? (int)$this->course : null;
        $criteriaid = $targetcriterion ? (int)$this->criteriaid : null;
        $courseids = array_values(array_unique(array_filter([
            $old === null ? null : (int)$old->course, $courseid], static fn($id) => $id !== null)));
        if (!$courseids || array_filter($courseids, static fn($id) => $id < 1)
                || ($targetcriterion && $criteriaid < 1)) {
            throw new \report_reportsflow\local\preservation_unavailable_exception('record_scope_invalid');
        }
        sort($courseids, SORT_NUMERIC);
        return $provider::with_course_barriers($courseids, function() use ($DB, $provider, $courseids,
                $old, $courseid, $criteriaid, $targetcriterion, $operation) {
            foreach ($courseids as $id) {
                $provider::assert_course_barrier($id);
            }
            if ($old !== null) {
                $fresh = $DB->get_record($this->table, ['id' => $this->id],
                    'id,course,criteriaid', MUST_EXIST);
                if ((int)$fresh->course !== (int)$old->course
                        || (int)$fresh->criteriaid !== (int)$old->criteriaid) {
                    throw new \report_reportsflow\local\preservation_unavailable_exception('record_scope_changed');
                }
            }
            if ($targetcriterion) {
                if ((int)$this->course !== $courseid || (int)$this->criteriaid !== $criteriaid
                        || (int)$DB->get_field('course_completion_criteria', 'course',
                            ['id' => $criteriaid], MUST_EXIST) !== $courseid) {
                    throw new \report_reportsflow\local\preservation_unavailable_exception('record_scope_changed');
                }
            }
            return $operation();
        });
    }

    /* @var string Database table that stores completion type criteria */
    public $table = 'course_completion_crit_compl';

    /* @var array Array of required table fields, must start with 'id'. */
    public $required_fields = array('id', 'userid', 'course', 'criteriaid', 'gradefinal', 'rpl', 'unenroled', 'timecompleted');

    /* @var array Array of unique fields, used in where clauses */
    public $unique_fields = array('userid', 'course', 'criteriaid');

    /* @var int User ID */
    public $userid;

    /* @var int course ID */
    public $course;

    /* @var int The id of the course completion criteria this completion references */
    public $criteriaid;

    /* @var float The final grade for the user in the course (if completing a grade criteria) */
    public $gradefinal;

    /* @var string Record of prior learning, leave blank if none */
    public $rpl;

    /* @var int Timestamp of user unenrolment (if completing a unenrol criteria) */
    public $unenroled;

    /* @var int Timestamp of course criteria completion {@link completion_criteria_completion::mark_complete()} */
    public $timecompleted;

    /* @var completion_criterria Associated criteria object */
    private $_criteria;

    /**
     * Finds and returns a data_object instance based on params.
     *
     * @param array $params associative arrays varname=>value
     * @return data_object instance of data_object or false if none found.
     */
    public static function fetch($params) {
        return self::fetch_helper('course_completion_crit_compl', __CLASS__, $params);
    }

    /**
     * Finds and returns all data_object instances based on params.
     *
     * @param array $params associative arrays varname=>value
     * @return array array of data_object insatnces or false if none found.
     */
    public static function fetch_all($params) {}

    /**
     * Return status of this criteria completion
     *
     * @return bool
     */
    public function is_complete() {
        return (bool) $this->timecompleted;
    }

    /**
     * Mark this criteria complete for the associated user
     *
     * This method creates a course_completion_crit_compl record
     *
     * @param   int $timecompleted Time completed (optional).
     * @return  int id of completion record.
     */
    public function mark_complete($timecompleted = null) {
        $provider = '\\report_reportsflow\\local\\regulatory_completion_preservation_service';
        if (class_exists($provider)) {
            return $this->with_flow_criterion_completion_barrier(
                fn() => $this->mark_complete_under_flow_barrier($timecompleted), !empty($this->id), true);
        }
        return $this->mark_complete_under_flow_barrier($timecompleted);
    }

    /** Existing criterion and canonical course completion sequence. */
    private function mark_complete_under_flow_barrier($timecompleted) {
        if (empty($timecompleted)) {
            $timecompleted = time();
        }
        // Create record
        $this->timecompleted = $timecompleted;

        // Save record
        if ($this->id) {
            $this->update();
        } else {
            $this->insert();
        }

        // Mark course completion record as started (if not already)
        $cc = array(
            'course'    => $this->course,
            'userid'    => $this->userid
        );
        $ccompletion = new completion_completion($cc);
        $result = $ccompletion->mark_inprogress($this->timecompleted);
        return $result;
    }

    /**
     * Attach a preloaded criteria object to this object
     *
     * @param   $criteria   object  completion_criteria
     */
    public function attach_criteria(completion_criteria $criteria) {
        $this->_criteria = $criteria;
    }

    /**
     * Return the associated criteria with this completion
     * If nothing attached, load from the db
     *
     * @return completion_criteria
     */
    public function get_criteria() {

        if (!$this->_criteria) {
            global $DB;

            $params = array(
                'id'    => $this->criteriaid
            );

            $record = $DB->get_record('course_completion_criteria', $params);

            $this->attach_criteria(completion_criteria::factory((array) $record));
        }

        return $this->_criteria;
    }

    /**
     * Return criteria status text for display in reports {@link completion_criteria::get_status()}
     *
     * @return string
     */
    public function get_status() {
        return $this->_criteria->get_status($this);
    }
}
