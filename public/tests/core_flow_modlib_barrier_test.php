<?php
// This file is part of Moodle - http://moodle.org/.
namespace mod_examflow;

defined('MOODLE_INTERNAL') || die();

/** Native integration of the isolated Moodle Core course writer with Flow modules. */
final class core_flow_modlib_barrier_test extends \advanced_testcase {
    public function test_examflow_modedit_update_reaches_activity_and_gradebook(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->dirroot . '/course/modlib.php');
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examflow', [
            'course' => $course->id,
            'name' => 'Before Core edit',
        ]);
        $cm = get_coursemodule_from_instance('examflow', $activity->id, $course->id, false, MUST_EXIST);
        [$cm, , , $data] = get_moduleinfo_data($cm, $course);
        $data->name = 'After Core edit';
        update_moduleinfo($cm, $data, $course);

        $this->assertSame('After Core edit', (string)$DB->get_field('examflow', 'name',
            ['id' => $activity->id]));
        $item = \grade_item::fetch([
            'courseid' => $course->id, 'itemtype' => 'mod',
            'itemmodule' => 'examflow', 'iteminstance' => $activity->id,
        ]);
        $this->assertNotFalse($item);
        $this->assertSame('After Core edit', $item->itemname);
    }

    public function test_slideflow_modedit_update_reaches_activity_and_gradebook(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->dirroot . '/course/modlib.php');
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('slideflow', [
            'course' => $course->id,
            'name' => 'Before Core edit',
        ]);
        $cm = get_coursemodule_from_instance('slideflow', $activity->id, $course->id, false, MUST_EXIST);
        [$cm, , , $data] = get_moduleinfo_data($cm, $course);
        $data->name = 'After Core edit';
        update_moduleinfo($cm, $data, $course);

        $this->assertSame('After Core edit', (string)$DB->get_field('slideflow', 'name',
            ['id' => $activity->id]));
        $item = \grade_item::fetch([
            'courseid' => $course->id, 'itemtype' => 'mod',
            'itemmodule' => 'slideflow', 'iteminstance' => $activity->id,
        ]);
        $this->assertNotFalse($item);
        $this->assertSame('After Core edit', $item->itemname);
    }

    public function test_modedit_refuses_a_transaction_started_before_course_barrier(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->dirroot . '/course/modlib.php');
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examflow', [
            'course' => $course->id,
            'name' => 'Unchanged Core edit',
        ]);
        $cm = get_coursemodule_from_instance('examflow', $activity->id, $course->id, false, MUST_EXIST);
        [$cm, , , $data] = get_moduleinfo_data($cm, $course);
        $data->name = 'Forbidden Core edit';
        $transaction = $DB->start_delegated_transaction();
        try {
            update_moduleinfo($cm, $data, $course);
            $this->fail('Core wrote the module before acquiring course preservation.');
        } catch (\report_reportsflow\local\preservation_unavailable_exception $expected) {
            $this->assertSame('transaction_before_barrier', $expected->reason);
        } finally {
            $transaction->allow_commit();
        }
        $this->assertSame('Unchanged Core edit', (string)$DB->get_field('examflow', 'name',
            ['id' => $activity->id]));
    }
}
