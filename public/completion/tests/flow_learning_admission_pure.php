<?php
// Executes the actual private Core guard with explicit API/DB/capability doubles.
$mode=$argv[1]??'';
if(!in_array($mode,['supported','missing_bridge','missing_method','missing_constant','standalone'],true)){
    throw new RuntimeException('Known admission contract mode required.');
}
class coding_exception extends RuntimeException {}
$USER=(object)['id'=>17];$events=[];$bridgefailure=null;$dbmodule='slideflow';
$DB=new class {
    public function get_field(string $table,string $field,array $where):string {
        global $dbmodule;
        if($table!=='modules'||$field!=='name'||($where['id']??0)!==73){throw new RuntimeException('Unexpected source lookup.');}
        return $dbmodule;
    }
};
if($mode!=='standalone'){eval('namespace local_privacyflow; class api {}');}
if($mode==='supported'){
    eval(<<<'PHP'
namespace mod_slideflow\local {
    class privacyflow_bridge {
        public const LEARNING_PROGRESS='privacyflow.processing.learning.progress.v1';
        public static function enforce_activity_admission(string $key,int $userid):void {
            global $events,$bridgefailure;$events[]=['slideflow',$key,$userid];if($bridgefailure){throw $bridgefailure;}
        }
    }
}
namespace mod_examflow\local {
    class privacyflow_bridge {
        public const LEARNING_PROGRESS='privacyflow.processing.learning.progress.v1';
        public static function enforce_activity_admission(string $key,int $userid):void {
            global $events,$bridgefailure;$events[]=['examflow',$key,$userid];if($bridgefailure){throw $bridgefailure;}
        }
    }
}
PHP);
}else if(in_array($mode,['missing_method','missing_constant'],true)){
    foreach(['mod_slideflow','mod_examflow'] as $namespace){
        $body=$mode==='missing_method'?"public const LEARNING_PROGRESS='privacyflow.processing.learning.progress.v1';"
            :'public static function enforce_activity_admission(string $key,int $userid):void {throw new \\RuntimeException("Incomplete contract must never be called.");}';
        eval('namespace '.$namespace.'\\local; class privacyflow_bridge {'.$body.'}');
    }
}
$source=file_get_contents(__DIR__.'/../../lib/completionlib.php');$tokens=token_get_all($source);$method=null;
for($i=0;$i<count($tokens);$i++){
    if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION){continue;}
    $next=$i+1;while(is_array($tokens[$next])&&$tokens[$next][0]===T_WHITESPACE){$next++;}
    if(!is_array($tokens[$next])||$tokens[$next][0]!==T_STRING||$tokens[$next][1]!=='require_flow_learning_admission'){continue;}
    if($method!==null){throw new RuntimeException('Ambiguous actual Core guard.');}
    $method='';$depth=0;$started=false;
    for(;$i<count($tokens);$i++){
        $token=$tokens[$i];$method.=is_array($token)?$token[1]:$token;
        if($token==='{'||(is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))){$started=true;$depth++;}
        else if($token==='}'){$depth--;if($started&&$depth===0){break;}}
    }
}
if(!$method){throw new RuntimeException('Actual Core admission guard missing.');}
eval('class flow_admission_harness {public bool $staff=false; public function user_can_override_completion($user):bool{return $this->staff;}'
    .'public function gate($cm,int $userid):void{$this->require_flow_learning_admission($cm,$userid);} private '.$method.'}');
$checks=0;$assert=static function(bool $ok)use(&$checks):void{$checks++;if(!$ok){throw new RuntimeException('Actual Core admission contract failed.');}};
$harness=new flow_admission_harness();
foreach(['slideflow','examflow'] as $module){
    $cm=(object)['modname'=>$module,'module'=>73];$dbmodule=$module;
    foreach([false,true] as $lookup){
        $events=[];$error=null;$candidate=$lookup?(object)['module'=>73]:$cm;
        try{$harness->gate($candidate,17);}catch(Throwable $caught){$error=$caught;}
        if($mode==='supported'){
            $assert($error===null);$assert($events===[[$module,'privacyflow.processing.learning.progress.v1',17]]);
        }else if($mode==='standalone'){$assert($error===null);$assert($events===[]);}
        else{$assert($error instanceof coding_exception);$assert($events===[]);}
    }
    foreach([19,0] as $subject){$events=[];$harness->gate($cm,$subject);$assert($events===[]);}
    $harness->staff=true;$events=[];$harness->gate($cm,17);$assert($events===[]);$harness->staff=false;
    if($mode==='supported'){
        $events=[];$bridgefailure=new RuntimeException('Synthetic denied required purpose');$error=null;
        try{$harness->gate($cm,17);}catch(Throwable $caught){$error=$caught;}
        $assert($error===$bridgefailure);$assert(count($events)===1);$bridgefailure=null;
    }
}
$events=[];$harness->gate((object)['modname'=>'page','module'=>73],17);$assert($events===[]);
echo json_encode(['status'=>'PASS','assertions'=>$checks,'mode'=>$mode,'actualPrivateMethodExtracted'=>true,
    'sourceSha256'=>hash('sha256',$source),'methodSha256'=>hash('sha256',str_replace("\r\n","\n",$method)),
    'nativeDatabase'=>false,'explicitInfrastructureDoubles'=>true]),PHP_EOL;
