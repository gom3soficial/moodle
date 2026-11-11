<?php
/** Fast contracts execute the actual Core wrapper/helper with explicit infrastructure doubles. */
$mode=$argv[1]??'';
if(!in_array($mode,['with_provider','without_provider'],true)){throw new RuntimeException('Known provider mode required.');}
define('MUST_EXIST',1);
class moodle_exception extends RuntimeException {
    public function __construct(public string $errorcode){parent::__construct($errorcode);}
}
$events=[];$barrier=false;$throwbody=false;
class cache {
    private bool $locked=false;
    public static function make(string $component,string $area):self {
        if($component!=='core'||$area!=='coursemodinfo'){throw new RuntimeException('Wrong cache scope.');}
        return new self();
    }
    public function delete(int $courseid):void {
        global $events,$DB;
        if($courseid!==9||$DB->depth||!$this->locked){throw new RuntimeException('Cache deletion outside locked rolled-back course.');}
        $events[]='cache-delete';
    }
    public function acquire_lock(int $courseid):void {
        global $events;
        if($courseid!==9||$this->locked){throw new RuntimeException('Wrong cache lock.');}
        $this->locked=true;$events[]='cache-lock';
    }
    public function release_lock(int $courseid):void {
        global $events;
        if($courseid!==9||!$this->locked){throw new RuntimeException('Wrong cache unlock.');}
        $this->locked=false;$events[]='cache-unlock';
    }
}
$DB=new class {
    public int $depth=0;
    public function get_field(...$args): int{return 9;}
    public function start_delegated_transaction(): object {
        global $events,$barrier,$mode;
        if($mode==='with_provider'&&!$barrier){throw new RuntimeException('Transaction started before barrier.');}
        $events[]='begin';$this->depth++;
        return new class($this) {
            public function __construct(private object $db){}
            public function allow_commit():void{global $events;$events[]='commit';$this->db->depth--;}
            public function rollback(Throwable $error):never{global $events;$events[]='rollback';$this->db->depth=0;throw $error;}
        };
    }
};
if($mode==='with_provider'){
    eval(<<<'PHP'
namespace report_reportsflow\local;
class regulatory_completion_preservation_service {
    public static function with_course_barriers(array $ids,callable $operation){
        global $events,$barrier,$DB;
        if($DB->depth){throw new \RuntimeException('SQL before course barrier.');}
        $events[]='lock';$barrier=true;
        try{return $operation();}finally{
            if($DB->depth){throw new \RuntimeException('SQL still open on barrier release.');}
            $events[]='unlock';$barrier=false;
        }
    }
    public static function assert_course_barrier(int $id):void{global $events,$barrier;if(!$barrier){throw new \RuntimeException('Missing barrier.');}$events[]='assert';}
}
PHP);
}
function update_moduleinfo_preserve_flow_intro($cm,$info):void{global $events;$events[]='pin';}
function update_moduleinfo_under_flow_barrier($cm,$info,$course,$form=null){
    global $events,$throwbody,$DB;
    $events[]='body';
    if(in_array($info->modulename,['slideflow','examflow'],true)&&$DB->depth!==1){throw new RuntimeException('Body lacks its SQL owner.');}
    if($throwbody){throw $throwbody;}
    return [$cm,$info];
}
function rebuild_course_cache(int $courseid=0,bool $clearonly=false):void{
    global $events,$DB;
    if($DB->depth||!$clearonly||$courseid!==9){throw new RuntimeException('Cache not cleared after rollback.');}
    $events[]='cache';
}
$source=file_get_contents(__DIR__.'/../modlib.php');$tokens=token_get_all($source);$functions=[];
for($i=0;$i<count($tokens);$i++){
    if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION){continue;}
    $next=$i+1;while(is_array($tokens[$next])&&$tokens[$next][0]===T_WHITESPACE){$next++;}
    if(!is_array($tokens[$next])||$tokens[$next][0]!==T_STRING
            ||!in_array($tokens[$next][1],['update_moduleinfo','update_moduleinfo_in_flow_transaction'],true)){continue;}
    $name=$tokens[$next][1];$code='';$depth=0;$started=false;
    for(;$i<count($tokens);$i++){
        $token=$tokens[$i];$code.=is_array($token)?$token[1]:$token;
        if($token==='{'){$started=true;$depth++;}else if($token==='}'){$depth--;if($started&&$depth===0){break;}}
    }
    $functions[$name]=$code;
}
if(count($functions)!==2){throw new RuntimeException('Actual Core transaction functions missing.');}
eval(implode("\n",$functions));
$checks=0;$assert=static function(bool $ok)use(&$checks):void{$checks++;if(!$ok){throw new RuntimeException('Actual Core wrapper contract failed.');}};
$cm=(object)['id'=>73];$course=(object)['id'=>9];
foreach(['slideflow','examflow'] as $module){
    $info=(object)['modulename'=>$module];$events=[];$throwbody=false;
    $assert(update_moduleinfo($cm,$info,$course)===[$cm,$info]);
    $expected=$mode==='with_provider'?['lock','assert','pin','begin','body','commit','unlock']:['pin','begin','body','commit'];
    $assert($events===$expected);$assert($DB->depth===0&&!$barrier);
    $events=[];$throwbody=new RuntimeException('Synthetic original error');$caught=null;
    try{update_moduleinfo($cm,$info,$course);}catch(RuntimeException $error){$caught=$error;}
    $assert($caught===$throwbody);
    $expected=$mode==='with_provider'?['lock','assert','pin','begin','body','rollback','cache-lock','cache-delete','cache-unlock','cache','unlock']:['pin','begin','body','rollback','cache-lock','cache-delete','cache-unlock','cache'];
    $assert($events===$expected);$assert($DB->depth===0&&!$barrier);
}
$events=[];$throwbody=false;$page=(object)['modulename'=>'page'];
$assert(update_moduleinfo($cm,$page,$course)===[$cm,$page]);$assert($events===['body']);$assert($DB->depth===0);
if($mode==='with_provider'){
    $events=[];$caught=null;
    try{update_moduleinfo($cm,(object)['modulename'=>'slideflow'],(object)['id'=>10]);}catch(moodle_exception $error){$caught=$error;}
    $assert($caught?->errorcode==='invalidcoursemodule');$assert($events===['lock','unlock']);$assert($DB->depth===0&&!$barrier);
}
echo json_encode(['status'=>'PASS','assertions'=>$checks,'mode'=>$mode,'actualFunctionsExtracted'=>true,
    'sourceSha256'=>hash('sha256',$source),'nativeDatabase'=>false,'providerAndDatabaseDoubles'=>true]),PHP_EOL;
