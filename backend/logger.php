<?php function applog($tag,$msg,$ctx = [],$level = 'info') {
    static $dir = null;
    if($dir === null)$dir = __DIR__.'/logs';
    if(! is_dir($dir))@ mkdir($dir,0775,true);
    $file = $dir.'/app-'.date('Ymd').'.log';
    $ip = $_SERVER['REMOTE_ADDR']?? '-';
    $ctxStr = '';
    if($ctx) {
        $j = json_encode($ctx,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if(is_string($j))$ctxStr = ' '.substr($j,0,600);
    }$line = sprintf("[%s] [%s] [%s] [%s] %s |%s%s\n",date('H:i:s.v'),strtoupper($level),$_SERVER['REQUEST_METHOD']?? '-',$tag,$msg,$ip,$ctxStr);
    @ file_put_contents($file,$line,FILE_APPEND | LOCK_EX);
    $lm = strtolower((string)$msg);
    if($tag === 'OPENCODE_CLI' && (strpos($lm,'stall') !== false || strpos($lm,'hang') !== false || strpos($lm,'timeout') !== false)) {
        @ file_put_contents($dir.'/stall-'.date('Ymd').'.log',$line,FILE_APPEND | LOCK_EX);
    }
    if(in_array($tag,['AGENT','NET','CHAT','OPENCODE_CLI','OPENCODE_CARD'],true)) {
        @ file_put_contents($dir.'/agent-'.date('Ymd').'.log',$line,FILE_APPEND | LOCK_EX);
    }
    if(isset($ctx['ms']) || isset($ctx['in']) || isset($ctx['out']) || $tag === 'PERF') {
        @ file_put_contents($dir.'/perf-'.date('Ymd').'.log',$line,FILE_APPEND | LOCK_EX);
    }
    $rec = ['ts' => date('c'),'lvl' => $level,'tag' => $tag,'msg' => $msg,'ctx' => $ctx,'ip' => $ip];
    $js = json_encode($rec,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if(is_string($js)) @ file_put_contents($dir.'/json-'.date('Ymd').'.log',$js."\n",FILE_APPEND | LOCK_EX);
    $sess = (string)($_POST['thread_id'] ?? $_POST['ka_id'] ?? '');
    if($sess !== '') {
        $sid = preg_replace('/[^A-Za-z0-9_-]/','',substr($sess,0,24));
        if($sid !== '') @ file_put_contents($dir.'/sess-'.$sid.'.log',$line,FILE_APPEND | LOCK_EX);
    }
    return true;
}
function applog_perf(string $op,int $ms,array $extra = []) {
    return applog('PERF',$op,['ms' => $ms] + $extra,'info');
}
function applog_rotate() {
    if(random_int(1,200)!== 1)return;
    $dir = __DIR__.'/logs';
    foreach(glob($dir.'/{app,stall,agent,perf,json,debug}-*.log',GLOB_BRACE)?:[]as $f) {
        $m = [];
        if(preg_match('/(app|stall|agent|perf|json|debug)-(\d{8})\.log$/',$f,$m)&& isset($m[2])) {
            if(strtotime($m[2])< time()- 14 * 86400)@ unlink($f);
        }
    }
    foreach(glob($dir.'/sess-*.log') ?: [] as $sf) {
        if(@filemtime($sf) < time() - 3 * 86400) @unlink($sf);
        elseif(@filesize($sf) > 5 * 1048576) @file_put_contents($sf,'');
    }
    $slow = $dir.'/webui-fpm-slow.log';
    if(is_file($slow)&& @ filesize($slow)> 5 * 1048576)@ file_put_contents($slow,'');
    // Active log truncate (>5MB, bukan unlink — fd masih dipegang proses).
    foreach(['nginx-access.log','nginx-error.log','oc-serve.log','webui-fpm.log','pw_daemon.log','dns_proxy.log','backend.log','watchdog.log','xvfb.log'] as $n) {
        $p = $dir.'/'.$n;
        if(is_file($p)&& @ filesize($p)> 5 * 1048576)@ file_put_contents($p,'');
    }
    $dbg = __DIR__.'/debug.log';
    if(is_file($dbg) && @filesize($dbg) > 512 * 1024) {
        $dst = $dir.'/debug-'.date('Ymd').'.log';
        $chunk = @file_get_contents($dbg);
        if(is_string($chunk) && $chunk !== '') @file_put_contents($dst,$chunk,FILE_APPEND | LOCK_EX);
        @file_put_contents($dbg,'');
    }
    foreach((array)@ glob(sys_get_temp_dir().'/c0n73xt_spool_*.json')as $sp) {
        if(@ filemtime($sp)< time()- 7200)@ unlink($sp);
    }
    foreach((array)@ glob(sys_get_temp_dir().'/debz_retry_*.json') as $rf) {
        if(@ filemtime($rf)< time()- 1800)@ unlink($rf);
    }

    foreach((array)@ glob(sys_get_temp_dir().'/debz_task_*.json') as $tf) {
        if(@ filemtime($tf)< time()- 86400)@ unlink($tf);
    }
    $sumLog = $dir.'/summarizer.log';
    if(is_file($sumLog)&& @ filesize($sumLog)> 1 * 1048576)@ file_put_contents($sumLog,'');
    foreach(glob($dir.'/{app,agent,perf,json}-'.date('Ymd').'.log',GLOB_BRACE)?:[]as $today) {
        if(@ filesize($today)> 5 * 1048576)@ file_put_contents($today,'');
    }
    $lock = sys_get_temp_dir().'/debz_summarizer.lock';
    if(! is_file($lock)|| time()- (int)@ filemtime($lock)> 1800) {
        @ touch($lock);
        @ exec('nohup python3 '.escapeshellarg(__DIR__.'/scripts/summarizer.py').' >/dev/null 2>&1 &');
    }
}
if(realpath(__FILE__)=== realpath($_SERVER['SCRIPT_FILENAME']?? '')) {
    http_response_code(403);
    exit('no direct access');
}
