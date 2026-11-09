<?php
// This file is part of Moodle - http://moodle.org/.
namespace mod_examflow;

defined('MOODLE_INTERNAL') || die();

/** Native Core completion writes must join the same course preservation barrier. */
final class core_flow_completion_barrier_test extends \advanced_testcase {
    public static function flow_modules(): array {
        return ['examflow' => ['examflow'], 'slideflow' => ['slideflow']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flow_modules')]
    public function test_activity_completion_writes_under_course_barrier(string $module): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->getDataGenerator()->create_module($module, [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_coursemodule_from_instance($module, $activity->id, $course->id, false, MUST_EXIST);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $user->id);
        $this->assertSame(COMPLETION_COMPLETE, (int)$DB->get_field('course_modules_completion',
            'completionstate', ['coursemoduleid' => $cm->id, 'userid' => $user->id], MUST_EXIST));
    }

    public function test_activity_completion_refuses_transaction_before_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->getDataGenerator()->create_module('examflow', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_coursemodule_from_instance('examflow', $activity->id, $course->id, false, MUST_EXIST);
        $completion = new \completion_info($course);
        $transaction = $DB->start_delegated_transaction();
        try {
            $completion->update_state($cm, COMPLETION_COMPLETE, $user->id);
            $this->fail('Core wrote activity completion before B.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('transaction_before_barrier', $expected->reason);
        } finally {
            $transaction->allow_commit();
        }
        $this->assertFalse($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
    }

    public function test_direct_activity_storage_refuses_transaction_before_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->getDataGenerator()->create_module('slideflow', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_coursemodule_from_instance('slideflow', $activity->id, $course->id, false, MUST_EXIST);
        $completion = new \completion_info($course);
        $data = $completion->get_data($cm, false, $user->id);
        $data->completionstate = COMPLETION_COMPLETE;
        $transaction = $DB->start_delegated_transaction();
        try {
            $completion->internal_set_data($cm, $data);
            $this->fail('Direct Core storage wrote before B.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('transaction_before_barrier', $expected->reason);
        } finally {
            $transaction->allow_commit();
        }
        $this->assertFalse($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
    }

    public function test_course_completion_writes_once_under_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $completion = new \completion_completion(['course' => (int)$course->id, 'userid' => (int)$user->id]);
        $stale = new \completion_completion(['course' => (int)$course->id, 'userid' => (int)$user->id]);
        $id = $completion->mark_complete();
        $this->assertGreaterThan(0, (int)$id);
        $this->assertGreaterThan(0, (int)$DB->get_field('course_completions',
            'timecompleted', ['id' => $id], MUST_EXIST));
        $this->assertNull($stale->mark_complete());
        $this->assertSame(1, $DB->count_records('course_completions',
            ['course' => $course->id, 'userid' => $user->id]));
    }

    public function test_course_completion_refuses_transaction_before_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $completion = new \completion_completion(['course' => (int)$course->id, 'userid' => (int)$user->id]);
        $transaction = $DB->start_delegated_transaction();
        try {
            $completion->mark_complete();
            $this->fail('Core wrote course completion before B.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('transaction_before_barrier', $expected->reason);
        } finally {
            $transaction->allow_commit();
        }
        $this->assertFalse($DB->record_exists('course_completions',
            ['course' => $course->id, 'userid' => $user->id]));
    }

    public function test_course_reset_refuses_a_transaction_started_before_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->getDataGenerator()->create_module('examflow', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_coursemodule_from_instance('examflow', $activity->id, $course->id, false, MUST_EXIST);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $user->id);
        $canonical = new \completion_completion(['course' => (int)$course->id, 'userid' => (int)$user->id]);
        $canonical->mark_complete();
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ([fn() => $completion->delete_all_state($cm),
                    fn() => $completion->reset_all_state($cm),
                    fn() => $completion->delete_course_completion_data(),
                    fn() => $completion->delete_all_completion_data()] as $delete) {
                try {
                    $delete();
                    $this->fail('Core deleted completion data before B.');
                } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                    $this->assertSame('transaction_before_barrier', $expected->reason);
                }
            }
        } finally {
            $transaction->allow_commit();
        }
        $this->assertTrue($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
        $this->assertTrue($DB->record_exists('course_completions',
            ['course' => $course->id, 'userid' => $user->id]));
        $completion->delete_all_completion_data();
        $this->assertFalse($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
        $this->assertFalse($DB->record_exists('course_completions',
            ['course' => $course->id, 'userid' => $user->id]));
    }

    public function test_delete_all_state_refuses_a_module_from_another_course(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $coursea = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $courseb = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->getDataGenerator()->create_module('examflow', [
            'course' => $courseb->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_coursemodule_from_instance('examflow', $activity->id, $courseb->id, false, MUST_EXIST);
        (new \completion_info($courseb))->update_state($cm, COMPLETION_COMPLETE, $user->id);
        try {
            (new \completion_info($coursea))->delete_all_state($cm);
            $this->fail('A barrier for another course deleted the activity.');
        } catch (\moodle_exception $expected) {
            $this->assertSame('invalidcoursemodule', $expected->errorcode);
        }
        $this->assertTrue($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
    }

    public function test_privacy_completion_deletes_refuse_a_transaction_before_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->getDataGenerator()->create_module('examflow', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_coursemodule_from_instance('examflow', $activity->id, $course->id, false, MUST_EXIST);
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $user->id);
        (new \completion_completion(['course' => (int)$course->id,
            'userid' => (int)$user->id]))->mark_complete();
        $context = \context_course::instance($course->id);
        $userlist = new \core_privacy\local\request\approved_userlist($context, 'completion', [$user->id]);
        $provider = \core_completion\privacy\provider::class;
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ([fn() => $provider::delete_completion($user, null, $cm->id),
                    fn() => $provider::delete_completion($user, $course->id),
                    fn() => $provider::delete_completion_by_approved_userlist($userlist, null, $cm->id),
                    fn() => $provider::delete_completion_by_approved_userlist($userlist, $course->id)] as $delete) {
                try {
                    $delete();
                    $this->fail('Privacy deleted completion before course B.');
                } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                    $this->assertSame('transaction_before_barrier', $expected->reason);
                }
            }
        } finally {
            $transaction->allow_commit();
        }
        $this->assertTrue($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
        $this->assertTrue($DB->record_exists('course_completions',
            ['course' => $course->id, 'userid' => $user->id]));
        $provider::delete_completion($user, null, $cm->id);
        $this->assertFalse($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
        $provider::delete_completion_by_approved_userlist($userlist, $course->id);
        $this->assertFalse($DB->record_exists('course_completions',
            ['course' => $course->id, 'userid' => $user->id]));
    }

    public function test_cross_course_criteria_clear_refuses_a_transaction_before_barriers(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $dependent = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $criteria = \completion_criteria::factory(['criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE]);
        $criteriadata = (object) [
            'id' => $dependent->id,
            'criteria_course' => [$prerequisite->id],
        ];
        $criteria->update_config($criteriadata);
        $reference = ['course' => $dependent->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE,
            'courseinstance' => $prerequisite->id];
        $this->assertTrue($DB->record_exists('course_completion_criteria', $reference));
        $completion = new \completion_info($prerequisite);
        $transaction = $DB->start_delegated_transaction();
        try {
            $completion->clear_criteria(true);
            $this->fail('Cross-course criteria were cleared before both course barriers.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('transaction_before_barrier', $expected->reason);
        } finally {
            $transaction->allow_commit();
        }
        $this->assertTrue($DB->record_exists('course_completion_criteria', $reference));
        $completion->clear_criteria(true);
        $this->assertFalse($DB->record_exists('course_completion_criteria', $reference));
    }

    public function test_global_aggregation_refuses_a_transaction_before_course_barriers(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $completionids = [];
        foreach ([1, 2] as $_) {
            $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
            $user = $this->getDataGenerator()->create_user();
            $criteria = \completion_criteria::factory(['criteriatype' => COMPLETION_CRITERIA_TYPE_SELF]);
            $criteriadata = (object) ['id' => $course->id, 'criteria_self' => 1];
            $criteria->update_config($criteriadata);
            $completion = new \completion_completion(['course' => (int)$course->id,
                'userid' => (int)$user->id]);
            $completion->mark_inprogress(time() - 3);
            $id = (int)$DB->get_field('course_completions', 'id',
                ['course' => $course->id, 'userid' => $user->id], MUST_EXIST);
            $DB->set_field('course_completions', 'reaggregate', time() - 2, ['id' => $id]);
            $completionids[] = $id;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ([$completionids[0], 0] as $target) {
                try {
                    aggregate_completions($target);
                    $this->fail('Core aggregation wrote before course B.');
                } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                    $this->assertSame('transaction_before_barrier', $expected->reason);
                }
            }
        } finally {
            $transaction->allow_commit();
        }
        foreach ($completionids as $id) {
            $this->assertGreaterThan(0, (int)$DB->get_field('course_completions', 'reaggregate', ['id' => $id]));
        }
        aggregate_completions(0);
        foreach ($completionids as $id) {
            $this->assertSame(0, (int)$DB->get_field('course_completions', 'reaggregate', ['id' => $id]));
        }
    }

    public function test_criterion_writers_refuse_a_transaction_before_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $criteria = \completion_criteria::factory(['criteriatype' => COMPLETION_CRITERIA_TYPE_SELF]);
        $criteria->course = $course->id;
        $this->assertGreaterThan(0, (int)$criteria->insert());
        $id = (int)$criteria->id;
        $newcriteria = \completion_criteria::factory(['criteriatype' => COMPLETION_CRITERIA_TYPE_SELF]);
        $newcriteria->course = $course->id;
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ([fn() => $newcriteria->insert(), fn() => $criteria->update(),
                    fn() => $criteria->delete()] as $write) {
                try {
                    $write();
                    $this->fail('Criterion writer bypassed course B.');
                } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                    $this->assertSame('transaction_before_barrier', $expected->reason);
                }
            }
        } finally {
            $transaction->allow_commit();
        }
        $this->assertSame(1, $DB->count_records('course_completion_criteria', ['course' => $course->id]));
        $this->assertTrue($DB->record_exists('course_completion_criteria', ['id' => $id]));
        $this->assertTrue($criteria->delete());
        $this->assertFalse($DB->record_exists('course_completion_criteria', ['id' => $id]));
    }

    public function test_aggregation_method_writers_refuse_a_transaction_before_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $aggregation = new \completion_aggregation(['course' => $course->id, 'criteriatype' => null]);
        $aggregation->setMethod(COMPLETION_AGGREGATION_ALL);
        $this->assertGreaterThan(0, (int)$aggregation->save());
        $id = (int)$aggregation->id;
        $newaggregation = new \completion_aggregation(['course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_SELF]);
        $newaggregation->setMethod(COMPLETION_AGGREGATION_ANY);
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ([fn() => $newaggregation->insert(), fn() => $aggregation->update(),
                    fn() => $aggregation->delete()] as $write) {
                try {
                    $write();
                    $this->fail('Aggregation-method writer bypassed course B.');
                } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                    $this->assertSame('transaction_before_barrier', $expected->reason);
                }
            }
        } finally {
            $transaction->allow_commit();
        }
        $this->assertSame(1, $DB->count_records('course_completion_aggr_methd', ['course' => $course->id]));
        $this->assertTrue($DB->record_exists('course_completion_aggr_methd', ['id' => $id]));
        $this->assertTrue($aggregation->delete());
        $this->assertFalse($DB->record_exists('course_completion_aggr_methd', ['id' => $id]));
    }

    public function test_course_criterion_insert_requires_both_owner_and_prerequisite_barriers(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $owner = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $prerequisite = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $criteria = \completion_criteria::factory(['criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE]);
        $criteria->course = $owner->id;
        $criteria->courseinstance = $prerequisite->id;
        $provider = \report_reportsflow\local\regulatory_completion_preservation_service::class;
        try {
            $provider::with_course_barriers([(int)$prerequisite->id], fn() => $criteria->insert());
            $this->fail('A prerequisite-only B granted a criterion write in another course.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('scope_expansion', $expected->reason);
        }
        $this->assertFalse($DB->record_exists('course_completion_criteria', [
            'course' => $owner->id, 'courseinstance' => $prerequisite->id]));
        $this->assertGreaterThan(0, (int)$criteria->insert());
        $this->assertTrue($DB->record_exists('course_completion_criteria', [
            'course' => $owner->id, 'courseinstance' => $prerequisite->id]));
    }

    public function test_module_deletion_refuses_a_transaction_before_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $user->id);
        $transaction = $DB->start_delegated_transaction();
        try {
            course_delete_module($cm->id);
            $this->fail('Module deletion started before course B.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('transaction_before_barrier', $expected->reason);
        } finally {
            $transaction->allow_commit();
        }
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $cm->id]));
        $this->assertTrue($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
        $this->assertNull(course_delete_module($cm->id));
        $this->assertFalse($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
    }

    public function test_course_content_removal_refuses_a_transaction_before_related_course_barriers(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $dependent = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $user->id);
        $criteria = \completion_criteria::factory(['criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE]);
        $criteriadata = (object) ['id' => $dependent->id, 'criteria_course' => [$course->id]];
        $criteria->update_config($criteriadata);
        $reference = ['course' => $dependent->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE, 'courseinstance' => $course->id];
        $transaction = $DB->start_delegated_transaction();
        try {
            remove_course_contents($course->id, false);
            $this->fail('Course content removal started before related course barriers.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('transaction_before_barrier', $expected->reason);
        } finally {
            $transaction->allow_commit();
        }
        $this->assertTrue($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
        $this->assertTrue($DB->record_exists('course_completion_criteria', $reference));
        remove_course_contents($course->id, false);
        $this->assertFalse($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
        $this->assertFalse($DB->record_exists('course_completion_criteria', $reference));
    }

    public function test_criterion_completion_refuses_a_transaction_before_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $criteria = \completion_criteria::factory(['criteriatype' => COMPLETION_CRITERIA_TYPE_SELF]);
        $criteria->course = $course->id;
        $criteria->insert();
        $completion = new \completion_criteria_completion([
            'course' => $course->id, 'userid' => $user->id, 'criteriaid' => $criteria->id]);
        $transaction = $DB->start_delegated_transaction();
        try {
            $completion->mark_complete();
            $this->fail('Criterion completion was written before course B.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('transaction_before_barrier', $expected->reason);
        } finally {
            $transaction->allow_commit();
        }
        $this->assertFalse($DB->record_exists('course_completion_crit_compl',
            ['course' => $course->id, 'userid' => $user->id]));
        $completion->mark_complete();
        $this->assertTrue($DB->record_exists('course_completion_crit_compl',
            ['course' => $course->id, 'userid' => $user->id]));
        $this->assertTrue($DB->record_exists('course_completions',
            ['course' => $course->id, 'userid' => $user->id]));
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ([fn() => $completion->update(), fn() => $completion->delete()] as $write) {
                try {
                    $write();
                    $this->fail('Direct criterion completion writer bypassed B.');
                } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                    $this->assertSame('transaction_before_barrier', $expected->reason);
                }
            }
        } finally {
            $transaction->allow_commit();
        }
        $this->assertTrue($DB->record_exists('course_completion_crit_compl', ['id' => $completion->id]));
    }

    public function test_stale_course_completion_object_cannot_undo_an_earlier_completion(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $key = ['course' => (int)$course->id, 'userid' => (int)$user->id];
        $stale = new \completion_completion($key);
        $current = new \completion_completion($key);
        $current->mark_complete(time() - 10);
        $completed = $DB->get_record('course_completions', $key, '*', MUST_EXIST);
        $stale->mark_inprogress();
        $this->assertSame(1, $DB->count_records('course_completions', $key));
        $this->assertSame((int)$completed->timecompleted,
            (int)$DB->get_field('course_completions', 'timecompleted', $key));
        $stale->mark_enrolled();
        $this->assertSame((int)$completed->timecompleted,
            (int)$DB->get_field('course_completions', 'timecompleted', $key));
    }

    public function test_direct_canonical_writers_refuse_stale_or_prebarrier_mutation(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $key = ['course' => (int)$course->id, 'userid' => (int)$user->id];
        $initial = new \completion_completion($key);
        $initial->mark_inprogress();
        $stale = new \completion_completion($key);
        $current = new \completion_completion($key);
        $current->mark_complete();
        try {
            $stale->update();
            $this->fail('Direct stale update removed canonical completion.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('record_scope_changed', $expected->reason);
        }
        $this->assertGreaterThan(0, (int)$DB->get_field('course_completions', 'timecompleted', $key));
        $newuser = $this->getDataGenerator()->create_user();
        $newcompletion = new \completion_completion(['course' => (int)$course->id,
            'userid' => (int)$newuser->id]);
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ([fn() => $newcompletion->insert(), fn() => $current->delete()] as $write) {
                try {
                    $write();
                    $this->fail('Direct canonical writer bypassed B.');
                } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                    $this->assertSame('transaction_before_barrier', $expected->reason);
                }
            }
        } finally {
            $transaction->allow_commit();
        }
        $this->assertFalse($DB->record_exists('course_completions',
            ['course' => $course->id, 'userid' => $newuser->id]));
        $this->assertGreaterThan(0, (int)$DB->get_field('course_completions', 'timecompleted', $key));
    }

    public function test_core_grade_update_for_flow_item_refuses_a_transaction_before_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/gradelib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->getDataGenerator()->create_module('examflow',
            ['course' => $course->id, 'grade' => 100]);
        $item = $DB->get_record('grade_items', ['courseid' => $course->id, 'itemtype' => 'mod',
            'itemmodule' => 'examflow', 'iteminstance' => $activity->id], '*', MUST_EXIST);
        $grades = (object) ['userid' => $user->id, 'rawgrade' => 80];
        $transaction = $DB->start_delegated_transaction();
        try {
            grade_update('mod/examflow', $course->id, 'mod', 'examflow', $activity->id, 0, $grades);
            $this->fail('Core grade_update changed a Flow grade before course B.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('transaction_before_barrier', $expected->reason);
        } finally {
            $transaction->allow_commit();
        }
        $this->assertFalse($DB->record_exists('grade_grades',
            ['itemid' => $item->id, 'userid' => $user->id]));
        $this->assertSame(GRADE_UPDATE_OK,
            grade_update('mod/examflow', $course->id, 'mod', 'examflow', $activity->id, 0, $grades));
        $this->assertSame(80.0, (float)$DB->get_field('grade_grades', 'rawgrade',
            ['itemid' => $item->id, 'userid' => $user->id], MUST_EXIST));
    }

    public function test_direct_flow_grade_item_writers_refuse_a_transaction_before_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/gradelib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->getDataGenerator()->create_module('examflow',
            ['course' => $course->id, 'grade' => 100]);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod',
            'itemmodule' => 'examflow', 'iteminstance' => $activity->id, 'itemnumber' => 0]);
        $this->assertNotFalse($item);
        $newitem = new \grade_item(['courseid' => $course->id, 'itemtype' => 'mod',
            'itemmodule' => 'examflow', 'iteminstance' => $activity->id, 'itemnumber' => 1]);
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ([fn() => $item->update(), fn() => $item->update_final_grade($user->id, 70),
                    fn() => $item->delete(), fn() => $newitem->insert()] as $write) {
                try {
                    $write();
                    $this->fail('Direct Flow grade item writer bypassed B.');
                } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                    $this->assertSame('transaction_before_barrier', $expected->reason);
                }
            }
        } finally {
            $transaction->allow_commit();
        }
        $this->assertTrue($DB->record_exists('grade_items', ['id' => $item->id]));
        $this->assertTrue($item->update_final_grade($user->id, 70));
        $this->assertSame(70.0, (float)$DB->get_field('grade_grades', 'finalgrade',
            ['itemid' => $item->id, 'userid' => $user->id], MUST_EXIST));
    }

    public function test_direct_flow_grade_grade_writers_refuse_a_transaction_before_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/gradelib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $activity = $this->getDataGenerator()->create_module('examflow',
            ['course' => $course->id, 'grade' => 100]);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod',
            'itemmodule' => 'examflow', 'iteminstance' => $activity->id, 'itemnumber' => 0]);
        $this->assertNotFalse($item);
        $this->assertTrue($item->update_final_grade($user->id, 70));
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $user->id]);
        $this->assertNotFalse($grade);
        $newgrade = new \grade_grade(['itemid' => $item->id, 'userid' => $other->id, 'rawgrade' => 60]);
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ([fn() => $grade->update(), fn() => $grade->delete(),
                    fn() => $newgrade->insert()] as $write) {
                try {
                    $write();
                    $this->fail('Direct Flow grade writer bypassed B.');
                } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                    $this->assertSame('transaction_before_barrier', $expected->reason);
                }
            }
        } finally {
            $transaction->allow_commit();
        }
        $this->assertSame(70.0, (float)$DB->get_field('grade_grades', 'finalgrade',
            ['id' => $grade->id], MUST_EXIST));
        $this->assertFalse($DB->record_exists('grade_grades',
            ['itemid' => $item->id, 'userid' => $other->id]));
    }

    public function test_flow_grade_completion_and_reset_wait_for_another_process_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $activity = $this->getDataGenerator()->create_module('examflow',
            ['course' => $course->id, 'grade' => 100, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_coursemodule_from_instance('examflow', $activity->id, $course->id, false, MUST_EXIST);
        $completioninfo = new \completion_info($course);
        $completioninfo->update_state($cm, COMPLETION_COMPLETE, $user->id);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod',
            'itemmodule' => 'examflow', 'iteminstance' => $activity->id, 'itemnumber' => 0]);
        $this->assertNotFalse($item);
        $completion = new \completion_completion(['course' => (int)$course->id, 'userid' => (int)$user->id]);
        $ready = tempnam($CFG->dataroot, 'flow_b_');
        $worker = __DIR__ . '/fixtures/core_flow_barrier_worker.php';
        $pipes = [];
        $process = proc_open([$CFG->pathtophp, $worker, (string)$course->id, $ready],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            unlink($ready);
            $this->fail('Could not launch the synthetic second lock owner.');
        }
        fclose($pipes[0]);
        try {
            $deadline = microtime(true) + 8;
            while (file_get_contents($ready) !== 'HELD' && microtime(true) < $deadline) {
                if (!proc_get_status($process)['running']) break;
                usleep(50000);
            }
            if (file_get_contents($ready) !== 'HELD') {
                $this->fail('Second process did not acquire the course barrier: ' . stream_get_contents($pipes[2]));
            }
            try {
                $item->update_final_grade($user->id, 70);
                $this->fail('The Flow grade writer bypassed another process course barrier.');
            } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                $this->assertSame('lock_timeout', $expected->reason);
            }
            $this->assertFalse($DB->record_exists('grade_grades',
                ['itemid' => $item->id, 'userid' => $user->id]));
            try {
                $completion->mark_complete();
                $this->fail('Core course completion bypassed another process course barrier.');
            } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                $this->assertSame('lock_timeout', $expected->reason);
            }
            $this->assertFalse($DB->record_exists('course_completions',
                ['course' => $course->id, 'userid' => $user->id]));
            try {
                $completioninfo->delete_all_state($cm);
                $this->fail('Core activity completion reset bypassed another process course barrier.');
            } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
                $this->assertSame('lock_timeout', $expected->reason);
            }
            $this->assertTrue($DB->record_exists('course_modules_completion',
                ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
        } finally {
            $deadline = microtime(true) + 8;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(50000);
            if (proc_get_status($process)['running']) proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            unlink($ready);
        }
        $this->assertTrue($item->update_final_grade($user->id, 70));
        $this->assertSame(70.0, (float)$DB->get_field('grade_grades', 'finalgrade',
            ['itemid' => $item->id, 'userid' => $user->id], MUST_EXIST));
        $this->assertGreaterThan(0, (int)$completion->mark_complete());
        $completioninfo->delete_all_state($cm);
        $this->assertFalse($DB->record_exists('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $user->id]));
    }
}
