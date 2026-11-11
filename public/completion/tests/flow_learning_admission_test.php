<?php
namespace core_completion;

defined('MOODLE_INTERNAL') || die();

/** Native Core owner compatibility; current full bridge cases live in ReportsFlow. */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class flow_learning_admission_test extends \advanced_testcase {
    private array $forcedsettings;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();$this->resetAfterTest();$this->preventResetByRollback();$this->setAdminUser();
        $this->forcedsettings=$CFG->forced_plugin_settings??[];
        require_once($CFG->libdir.'/completionlib.php');
        if(!class_exists('\\mod_slideflow\\local\\privacyflow_bridge')||!class_exists('\\mod_examflow\\local\\privacyflow_bridge')
                ||!class_exists('\\local_privacyflow\\api')){$this->markTestSkipped('Native Flow providers and PrivacyFlow required.');}
        foreach(['mod_slideflow','mod_examflow'] as $component){$CFG->forced_plugin_settings[$component]['integritysecret']=bin2hex(random_bytes(32));}
        set_config('enablecompletion',1);set_config('environmentmode','production','local_privacyflow');
    }
    protected function tearDown(): void {
        global $CFG;$CFG->forced_plugin_settings=$this->forcedsettings;parent::tearDown();
    }
    private function fixture(string $module): array {
        global $DB,$PAGE;
        $this->setAdminUser();
        $course=$this->getDataGenerator()->create_course(['enablecompletion'=>1]);
        $activity=$this->getDataGenerator()->create_module($module,['course'=>$course->id,
            'name'=>'QA-SYNTHETIC-ONLY Core admission','completion'=>COMPLETION_TRACKING_MANUAL]);
        $user=$this->getDataGenerator()->create_user(['firstname'=>'QA','lastname'=>'Sintetico',
            'email'=>bin2hex(random_bytes(6)).'@example.invalid']);
        $this->getDataGenerator()->enrol_user($user->id,$course->id);
        \local_privacyflow\api::reconcile();$bridge='\\mod_'.$module.'\\local\\privacyflow_bridge';
        $DB->set_field('privacyflow_processing','adminstatus','pending',
            ['processingkey'=>$bridge::LEARNING_PROGRESS,'component'=>'mod_'.$module]);
        $DB->set_field('privacyflow_processing','requirement','required_for_activity',
            ['processingkey'=>$bridge::LEARNING_PROGRESS,'component'=>'mod_'.$module]);
        $this->setUser($user);$PAGE=new \moodle_page();
        $this->assertSame('POLICY_MISCONFIGURED',$bridge::context($bridge::LEARNING_PROGRESS,(int)$user->id)['decision']);
        $cm=get_coursemodule_from_instance($module,$activity->id,$course->id,false,MUST_EXIST);
        return [$course,$user,$cm,new \completion_info($course)];
    }
    public function test_learner_updates_require_admission_before_native_rows_or_cache(): void {
        global $DB;
        foreach(['slideflow','examflow'] as $module){
            [$course,$user,$cm,$completion]=$this->fixture($module);$error=null;$sink=$this->redirectEvents();
            try{$completion->update_state($cm,COMPLETION_COMPLETE,(int)$user->id);}
            catch(\moodle_exception $caught){$error=$caught;}finally{$sink->close();}
            $this->assertNotNull($error);$this->assertSame('privacyflowactivityblocked',$error->errorcode);
            $this->assertFalse($DB->record_exists('course_modules_completion',['coursemoduleid'=>$cm->id,'userid'=>$user->id]));
            $this->assertSame(COMPLETION_INCOMPLETE,(int)$completion->get_data($cm,false,(int)$user->id)->completionstate);
        }
    }
    public function test_direct_internal_storage_requires_the_same_admission(): void {
        global $DB;
        foreach(['slideflow','examflow'] as $module){
            [$course,$user,$cm,$completion]=$this->fixture($module);$data=clone $completion->get_data($cm,false,(int)$user->id);
            $data->completionstate=COMPLETION_COMPLETE;$data->viewed=1;$data->timemodified=time();
            $error=null;$sink=$this->redirectEvents();
            try{$completion->internal_set_data($cm,$data);}catch(\moodle_exception $caught){$error=$caught;}finally{$sink->close();}
            $this->assertNotNull($error);$this->assertSame('privacyflowactivityblocked',$error->errorcode);
            $this->assertFalse($DB->record_exists('course_modules_completion',['coursemoduleid'=>$cm->id,'userid'=>$user->id]));
            $this->assertFalse($DB->record_exists('course_modules_viewed',['coursemoduleid'=>$cm->id,'userid'=>$user->id]));
        }
    }
    public function test_teacher_override_is_distinct_and_cannot_be_claimed_by_the_learner(): void {
        global $DB;
        foreach(['slideflow','examflow'] as $module){
            [$course,$user,$cm,$completion]=$this->fixture($module);$error=null;
            try{$completion->update_state($cm,COMPLETION_COMPLETE,(int)$user->id,true);}
            catch(\required_capability_exception $caught){$error=$caught;}
            $this->assertInstanceOf(\required_capability_exception::class,$error);
            $this->setAdminUser();$teacher=$this->getDataGenerator()->create_user(['firstname'=>'QA','lastname'=>'Docente',
                'email'=>bin2hex(random_bytes(6)).'@example.invalid']);
            $role=$DB->get_field('role','id',['shortname'=>'teacher'],MUST_EXIST);
            $this->getDataGenerator()->enrol_user($teacher->id,$course->id,$role);$this->setUser($teacher);
            $this->assertTrue($completion->user_can_override_completion($teacher));
            $sink=$this->redirectEvents();try{$completion->update_state($cm,COMPLETION_COMPLETE,(int)$user->id,true);}finally{$sink->close();}
            $record=$DB->get_record('course_modules_completion',['coursemoduleid'=>$cm->id,'userid'=>$user->id],'*',MUST_EXIST);
            $this->assertSame(COMPLETION_COMPLETE,(int)$record->completionstate);$this->assertSame((int)$teacher->id,(int)$record->overrideby);
        }
    }
    public function test_nonflow_and_unchanged_operational_history_keep_their_native_paths(): void {
        global $DB;
        $course=$this->getDataGenerator()->create_course(['enablecompletion'=>1]);
        $page=$this->getDataGenerator()->create_module('page',['course'=>$course->id,'completion'=>COMPLETION_TRACKING_MANUAL]);
        $user=$this->getDataGenerator()->create_user(['email'=>bin2hex(random_bytes(6)).'@example.invalid']);
        $this->getDataGenerator()->enrol_user($user->id,$course->id);$this->setUser($user);
        $cm=get_coursemodule_from_instance('page',$page->id,$course->id,false,MUST_EXIST);
        $sink=$this->redirectEvents();try{(new \completion_info($course))->update_state($cm,COMPLETION_COMPLETE,(int)$user->id);}finally{$sink->close();}
        $this->assertSame(COMPLETION_COMPLETE,(int)$DB->get_field('course_modules_completion','completionstate',['coursemoduleid'=>$cm->id,'userid'=>$user->id],MUST_EXIST));
        foreach(['slideflow','examflow'] as $module){
            [$course,$user,$cm,$completion]=$this->fixture($module);$this->setAdminUser();
            // Trusted operational projection, not fabricated learning/assessment evidence.
            $sink=$this->redirectEvents();try{$completion->update_state($cm,COMPLETION_COMPLETE,(int)$user->id);}finally{$sink->close();}
            $before=$DB->get_record('course_modules_completion',['coursemoduleid'=>$cm->id,'userid'=>$user->id],'*',MUST_EXIST);
            $this->setUser($user);$sink=$this->redirectEvents();
            try{$completion->update_state($cm,COMPLETION_COMPLETE,(int)$user->id);}finally{$sink->close();}
            $this->assertEquals($before,$DB->get_record('course_modules_completion',['coursemoduleid'=>$cm->id,'userid'=>$user->id],'*',MUST_EXIST));
        }
    }
}
