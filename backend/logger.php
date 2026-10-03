<?php function applog($tag,$msg,$ctx = []) {
    static $dir = null;
    if($dir === null)$dir = __DIR__.'/logs';
    if(! is_dir($dir))@ mkdir($dir,0775,true);
    $file = $dir.'/app-'.date('Ymd').'.log';
    $ip = $_SERVER['REMOTE_ADDR']?? '-';
    $ua = isset($_SERVER['HTTP_USER_AGENT'])? substr($_SERVER['HTTP_USER_AGENT'],0,80): '-';
    $ctxStr = '';
    if($ctx) {
        $j = json_encode($ctx,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if(is_string($j))$ctxStr = ' '.substr($j,0,600);
    }$line = sprintf("[%s] [%s] [%s] %s |%s%s\n",date('H:i:s.v'),$_SERVER['REQUEST_METHOD']?? '-',$tag,$msg,$ip,$ctxStr);
    @ file_put_contents($file,$line,FILE_APPEND | LOCK_EX);
    // OC_CLI_STALL pisah ke file sendiri biar debug stall gak tenggelam di app log.
    $lm = strtolower((string)$msg);
    if($tag === 'OPENCODE_CLI' && (strpos($lm,'stall') !== false || strpos($lm,'hang') !== false || strpos($lm,'timeout') !== false)) {
        @ file_put_contents($dir.'/stall-'.date('Ymd').'.log',$line,FILE_APPEND | LOCK_EX);
    }
    return true;
}
function applog_rotate() {
    if(random_int(1,200)!== 1)return;
    $dir = __DIR__.'/logs';
    foreach(glob($dir.'/{app,stall}-*.log',GLOB_BRACE)?:[]as $f) {
        $m = [];
        if(preg_match('/(app|stall)-(\d{8})\.log$/',$f,$m)&& isset($m[2])) {
            if(strtotime($m[2])< time()- 14 * 86400)@ unlink($f);
        }
    }
    $slow = $dir.'/webui-fpm-slow.log';
    if(is_file($slow)&& @ filesize($slow)> 5 * 1048576)@ file_put_contents($slow,'');
    // Active log truncate (>5MB, bukan unlink — fd masih dipegang proses).
    foreach(['nginx-access.log','nginx-error.log','oc-serve.log','webui-fpm.log','pw_daemon.log','dns_proxy.log','backend.log','watchdog.log','xvfb.log'] as $n) {
        $p = $dir.'/'.$n;
        if(is_file($p)&& @ filesize($p)> 5 * 1048576)@ file_put_contents($p,'');
    }
    $dbg = __DIR__.'/debug.log';
    if(is_file($dbg)&& @ filesize($dbg)> 5 * 1048576)@ file_put_contents($dbg,'');
    foreach((array)@ glob(sys_get_temp_dir().'/c0n73xt_spool_*.json')as $sp) {
        if(@ filemtime($sp)< time()- 7200)@ unlink($sp);
    }
}
if(realpath(__FILE__)=== realpath($_SERVER['SCRIPT_FILENAME']?? '')) {
    http_response_code(403);
    exit('no direct access');
}
