<?php
namespace core_course;

defined('MOODLE_INTERNAL') || die();

/** SQL ownership/cache handling independent of optional Flow providers. */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class flow_activity_settings_transaction_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;
        parent::setUp(); $this->resetAfterTest(); $this->preventResetByRollback(); $this->setAdminUser();
        require_once($CFG->dirroot.'/course/modlib.php');
    }
    public function test_atomic_helper_commits_and_preserves_callback_result(): void {
        global $DB;
        $course=$this->getDataGenerator()->create_course(['fullname'=>'QA-SYNTHETIC-ONLY original']);
        $result=update_moduleinfo_in_flow_transaction((int)$course->id,function()use($DB,$course){
            $this->assertTrue($DB->is_transaction_started());
            $DB->set_field('course','fullname','QA-SYNTHETIC-ONLY committed',['id'=>$course->id]);
            return ['native_result'=>73];
        });
        $this->assertSame(['native_result'=>73],$result);
        $this->assertSame('QA-SYNTHETIC-ONLY committed',$DB->get_field('course','fullname',['id'=>$course->id]));
        $this->assertFalse($DB->is_transaction_started());
    }
    public function test_error_rolls_back_native_rows_and_invalidates_a_warmed_course_cache(): void {
        global $DB;
        $course=$this->getDataGenerator()->create_course();
        $page=$this->getDataGenerator()->create_module('page',['course'=>$course->id,'name'=>'QA-SYNTHETIC-ONLY original page','showdescription'=>0]);
        $before=$DB->get_record('page',['id'=>$page->id]); $cm=$DB->get_record('course_modules',['id'=>$page->cmid]);
        $this->assertSame($before->name,get_fast_modinfo($course->id)->get_cm($page->cmid)->name);
        $expected=new \RuntimeException('QA synthetic rollback'); $observed=null;
        try{
            update_moduleinfo_in_flow_transaction((int)$course->id,function()use($DB,$page,$course,$expected){
                $DB->set_field('page','name','QA-SYNTHETIC-ONLY uncommitted',['id'=>$page->id]);
                $DB->set_field('course_modules','showdescription',1,['id'=>$page->cmid]);
                rebuild_course_cache((int)$course->id,true);
                $this->assertSame('QA-SYNTHETIC-ONLY uncommitted',get_fast_modinfo($course->id)->get_cm($page->cmid)->name);
                throw $expected;
            });
        }catch(\RuntimeException $error){$observed=$error;}
        $this->assertSame($expected,$observed);
        $this->assertEquals($before,$DB->get_record('page',['id'=>$page->id]));
        $this->assertEquals($cm,$DB->get_record('course_modules',['id'=>$page->cmid]));
        $this->assertSame($before->name,get_fast_modinfo($course->id)->get_cm($page->cmid)->name);
        $this->assertFalse($DB->is_transaction_started());
    }
    public function test_nested_helper_does_not_commit_its_callers_transaction(): void {
        global $DB;
        $course=$this->getDataGenerator()->create_course(['fullname'=>'QA-SYNTHETIC-ONLY outer original']);
        $transaction=$DB->start_delegated_transaction();
        $result=update_moduleinfo_in_flow_transaction((int)$course->id,static function()use($DB,$course){
            $DB->set_field('course','fullname','QA-SYNTHETIC-ONLY outer pending',['id'=>$course->id]); return 47;
        });
        $this->assertSame(47,$result); $this->assertTrue($DB->is_transaction_started());
        try{$transaction->rollback(new \RuntimeException('QA outer rollback'));}catch(\RuntimeException $expected){}
        $this->assertSame('QA-SYNTHETIC-ONLY outer original',$DB->get_field('course','fullname',['id'=>$course->id]));
        $this->assertFalse($DB->is_transaction_started());
    }
    public function test_nonflow_page_keeps_the_native_module_update_path(): void {
        global $DB,$PAGE;
        $course=$this->getDataGenerator()->create_course();
        $page=$this->getDataGenerator()->create_module('page',['course'=>$course->id,'name'=>'QA-SYNTHETIC-ONLY page']);
        $PAGE=new \moodle_page(); $cm=get_coursemodule_from_instance('page',$page->id,$course->id,false,MUST_EXIST);
        [$cm,,,$data]=get_moduleinfo_data($cm,$course); $data->name='QA-SYNTHETIC-ONLY native page updated';
        $data->page=['itemid'=>0,'text'=>'<p>QA-SYNTHETIC-ONLY page content</p>','format'=>FORMAT_HTML];
        $data->printintro=1; $data->printlastmodified=1;
        $sink=$this->redirectEvents(); [$updated]=update_moduleinfo($cm,$data,$course); $sink->close();
        $this->assertSame((int)$cm->id,(int)$updated->id);
        $this->assertSame($data->name,$DB->get_field('page','name',['id'=>$page->id]));
        $this->assertFalse($DB->is_transaction_started());
    }
}
