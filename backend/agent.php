<?php require_once __DIR__."/agent-helpers.php";
function debz_ua_ok(string $ua): bool {
    if(stripos($ua,'compat')!== false)return false;
    return (bool)preg_match('#^[A-Za-z0-9._-]+/\d#',$ua);
}
function debz_generate_opencode_id(string $prefix): string {
    $hexPart = bin2hex(random_bytes(6));
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $base62Part = '';
    $max = strlen($chars)- 1;
    for($i = 0;
    $i < 14;
    $i ++) {
        $base62Part .= $chars[random_int(0,$max)];
    }
    return $prefix.$hexPart.$base62Part;
}
function debz_proxy_state_file(): string {
    return __DIR__.'/proxy-grabber/proxy_state.json';
}
function debz_proxy_list_file(string $type): string {
    return __DIR__.'/proxy-grabber/proxies_out/'.$type.'.txt';
}
function debz_proxy_blacklist_file(): string {
    return __DIR__.'/proxy-grabber/proxies_out/blacklist.json';
}
function debz_proxy_score_file(): string {
    return __DIR__.'/proxy-grabber/proxies_out/proxy_scores.json';
}
function debz_proxy_load_state():? array {
    $f = debz_proxy_state_file();
    if(! is_file($f)|| ! is_readable($f))return null;
    $raw = @ file_get_contents($f);
    if(empty($raw))return null;
    $j = json_decode($raw,true);
    return is_array($j)? $j: null;
}
function debz_proxy_state_update(callable $fn): array {
    $f = debz_proxy_state_file();
    $dir = dirname($f);
    if(! is_dir($dir))@ mkdir($dir,0770,true);
    $fp = @ fopen($f,'c+');
    if(! $fp) {
        $st = debz_proxy_load_state();
        $out = $fn(is_array($st)? $st:[]);
        return is_array($out)? $out:[];
    }@ flock($fp,LOCK_EX);
    $raw = stream_get_contents($fp);
    $st = json_decode((string)$raw,true);
    if(! is_array($st))$st = [];
    $out = $fn($st);
    if(is_array($out)) {
        @ rewind($fp);
        @ ftruncate($fp,0);
        @ fwrite($fp,json_encode($out,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @ fflush($fp);
    }@ flock($fp,LOCK_UN);
    @ fclose($fp);
    return is_array($out)? $out:[];
}
function debz_proxy_sticky_enabled(): bool {
    $st = debz_proxy_load_state();
    if(! is_array($st))return true;
    return($st['sticky_enabled']?? true)!== false;
}
function debz_proxy_sticky_ok(string $proxy): void {
    if($proxy === '')return;
    $enableSticky = debz_proxy_sticky_enabled();
    debz_proxy_state_update(function(array $st)use($proxy,$enableSticky): array {
        $st['sticky_proxy']= $proxy;
        $st['sticky_type']= (string)($st['sticky_type']?? 'http');
        $st['sticky']= $enableSticky;
        $st['last_live']= time();
        return $st;
    });
}
function debz_proxy_mark_used(string $proxy): void {
    debz_proxy_state_update(function(array $st)use($proxy): array {
        $st['last_used_proxy']= $proxy;
        $st['last_used_at']= time();
        $st['last_used_source']= 'php';
        return $st;
    });
}
function debz_proxy_blacklist_get_tier(int $failCount): int {
    $fails = max(1,(int)$failCount);
    if($fails <= 1)return 900;
    if($fails <= 2)return 3600;
    return 86400;
}
function debz_proxy_bl_tier_for(string $reason,int $failCount): int {
    if($reason === 'rl')return 86400;
    return debz_proxy_blacklist_get_tier($failCount);
}
function debz_proxy_is_blacklisted(string $proxy): bool {
    if($proxy === '')return false;
    $f = debz_proxy_blacklist_file();
    if(! is_file($f)|| ! is_readable($f))return false;
    $raw = @ file_get_contents($f);
    if(empty($raw))return false;
    $bl = json_decode($raw,true);
    if(! is_array($bl))return false;
    $host = parse_url('http://'.$proxy,PHP_URL_HOST)?: $proxy;
    $keysToCheck = ($host !== $proxy)?[$proxy,$host]:[$proxy];
    $now = time();
    foreach($keysToCheck as $k) {
        $entry = $bl[$k]?? null;
        if(! $entry)continue;
        if(! is_array($entry)) {
            $ts = (int)$entry;
            if($ts > 0 &&($now - $ts < 86400))return true;
        }else {
            $ts = (int)($entry['ts']?? 0);
            $fails = (int)($entry['fails']?? 1);
            $reason = (string)($entry['reason']?? '');
            $tier = debz_proxy_bl_tier_for($reason,$fails);
            if($now - $ts < $tier)return true;
        }
    }
    return false;
}
function debz_proxy_blacklist_add(string $proxy): void {
    if($proxy === '')return;
    $f = debz_proxy_blacklist_file();
    $dir = dirname($f);
    if(! is_dir($dir))@ mkdir($dir,0770,true);
    $raw = is_file($f)? @ file_get_contents($f): false;
    $bl = (! empty($raw)&& is_string($raw))? json_decode($raw,true):[];
    if(! is_array($bl))$bl = [];
    $now = time();
    $host = parse_url('http://'.$proxy,PHP_URL_HOST)?: $proxy;
    $keysToAdd = ($host !== $proxy)?[$proxy,$host]:[$proxy];
    $reason = $GLOBALS['debz_bl_reason']?? '';
    foreach($keysToAdd as $k) {
        $oldFails = is_array($bl[$k]?? null)? (int)($bl[$k]['fails']?? 0): 0;
        if(! is_array($bl[$k]?? null))$bl[$k]= [];
        $bl[$k]['ts']= $now;
        $bl[$k]['fails']= $oldFails + 1;
        if($reason !== '')$bl[$k]['reason']= $reason;
    }$GLOBALS['debz_bl_reason']= '';
    foreach($bl as $p => $e) {
        $eFails = is_array($e)? (int)($e['fails']?? 1): 1;
        $eTs = is_array($e)? (int)($e['ts']?? 0): (int)($e ?? 0);
        $eReason = is_array($e)? (string)($e['reason']?? ''): '';
        $tier = debz_proxy_bl_tier_for($eReason,$eFails);
        if($now - $eTs >= $tier)unset($bl[$p]);
    }@ file_put_contents($f,json_encode($bl,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),LOCK_EX);
}
function debz_proxy_health_check(string $proxy): bool {
    $parts = parse_url('http://'.$proxy);
    $host = $parts['host']?? '';
    $port = (int)($parts['port']?? 80);
    if($host === '' || $port <= 0 || $port > 65535)return false;
    $errno = 0;
    $errstr = '';
    $fp = @ fsockopen($host,$port,$errno,$errstr,5);
    if($fp) {
        fclose($fp);
        return true;
    }
    return false;
}
function debz_proxy_probe_urls(): array {
    $urls = [];
    $pf = __DIR__.'/proxy-grabber/probe_config.json';
    if(is_file($pf)&& is_readable($pf)) {
        $cfg = @ json_decode((string)@ file_get_contents($pf),true);
        if(is_array($cfg)&& ! empty($cfg['url'])) {
            $urls[]= (string)$cfg['url'];
        }
    }$urls[]= 'https://www.gstatic.com/generate_204';
    return array_values(array_unique($urls));
}
function debz_proxy_probe(string $proxy,int $timeoutMs = 2500): string {
    $parts = parse_url('http://'.$proxy);
    $host = $parts['host']?? '';
    $port = (int)($parts['port']?? 0);
    if($host === '' || $port <= 0 || $port > 65535)return 'error';
    if(! function_exists('curl_init'))return debz_proxy_probe_tcp($proxy,$timeoutMs);
    $timeout = max(1,min((int)$timeoutMs,8000));
    foreach(debz_proxy_probe_urls()as $url) {
        $isZen = (stripos($url,'opencode.ai')!== false || stripos($url,'/zen/')!== false);
        $ch = @ curl_init();
        if($ch === false)return 'error';
        @ curl_setopt($ch,CURLOPT_URL,$url);
        @ curl_setopt($ch,CURLOPT_PROXY,$proxy);
        @ curl_setopt($ch,CURLOPT_PROXYTYPE,CURLPROXY_HTTP);
        @ curl_setopt($ch,CURLOPT_HTTPPROXYTUNNEL,true);
        @ curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,$isZen);
        @ curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,$isZen? 2: 0);
        @ curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        @ curl_setopt($ch,CURLOPT_CONNECTTIMEOUT_MS,$timeout);
        @ curl_setopt($ch,CURLOPT_TIMEOUT_MS,$timeout);
        @ curl_setopt($ch,CURLOPT_USERAGENT,'Mozilla/5.0 (Linux; Android 14) Probe/2.0');
        @ curl_setopt($ch,CURLOPT_NOBODY,false);
        @ curl_setopt($ch,CURLOPT_FOLLOWLOCATION,false);
        $res = @ curl_exec($ch);
        $code = (int)@ curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $errn = (int)@ curl_errno($ch);
        $errs = (string)@ curl_error($ch);
        @ curl_close($ch);
        if($errn === 60 || $errn === 51 || $errn === 35 || stripos($errs,'SSL')!== false || stripos($errs,'certificate')!== false)return 'refused';
        if($code === 200 || $code === 204 || $code === 401 || $code === 403)return 'ok';
        if($code === 429)return 'refused';
        if($errn === 0 && $code > 0)continue;
        if($errn === 28)continue;
        if(preg_match('/refused|unreachable|reset by peer/i',$errs))return 'refused';
        if(preg_match('/timed ?out|timeout/i',$errs))continue;
        continue;
    }
    return 'timeout';
}
function debz_proxy_probe_tcp(string $proxy,int $timeoutMs = 2500): string {
    $parts = parse_url('http://'.$proxy);
    $host = $parts['host']?? '';
    $port = (int)($parts['port']?? 0);
    if($host === '' || $port <= 0 || $port > 65535)return 'error';
    $timeout = max(1,min((int)$timeoutMs,8000))/ 1000;
    $errno = 0;
    $errstr = '';
    $fp = @ fsockopen($host,$port,$errno,$errstr,$timeout);
    if($fp !== false) {
        fclose($fp);
        return 'ok';
    }$e = (int)$errno;
    if(in_array($e,[111,113,112,101,10060,10061,10065],true))return 'refused';
    if($e === 110)return 'timeout';
    if($errstr !== '' && preg_match('/refused|unreachable|timed out|timedout|timeout/i',$errstr)) {
        return preg_match('/refused|unreachable/i',$errstr)? 'refused': 'timeout';
    }
    return 'timeout';
}
function debz_proxy_probe_cached(string $proxy,int $timeoutMs = 2500): string {
    if(! isset($GLOBALS['_debz_probe_cache']))$GLOBALS['_debz_probe_cache']= [];
    if(isset($GLOBALS['_debz_probe_cache'][$proxy]))return $GLOBALS['_debz_probe_cache'][$proxy];
    $status = debz_proxy_probe($proxy,$timeoutMs);
    $GLOBALS['_debz_probe_cache'][$proxy]= $status;
    return $status;
}
function debz_proxy_probe_parallel(array $cands,int $timeoutMs = 4000): string {
    if(empty($cands))return '';
    if(! function_exists('curl_multi_init')) {
        $socks = [];
        $meta = [];
        $i = 0;
        foreach($cands as $p) {
            $i ++;
            $parts = parse_url('http://'.$p);
            $host = (string)($parts['host']?? '');
            $port = (int)($parts['port']?? 0);
            if($host === '' || $port <= 0 || $port > 65535)continue;
            $errno = 0;
            $errstr = '';
            $fp = @ stream_socket_client('tcp://'.$host.':'.$port,$errno,$errstr,1.0,STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if(! is_resource($fp))continue;
            stream_set_blocking($fp,false);
            $socks[$i]= $fp;
            $meta[$i]= $p;
            if(count($socks)>= 8)break;
        }
        if(empty($socks))return '';
        $deadline = microtime(true)+(max(0.2,min((int)$timeoutMs,4000))/ 1000);
        while(! empty($socks)&& microtime(true)< $deadline) {
            $r = $socks;
            $w = $socks;
            $e = null;
            $sel = @ stream_select($r,$w,$e,0,100000);
            if($sel === false) {
                foreach($socks as $sfp)if(is_resource($sfp))@ fclose($sfp);
                return '';
            }
            if($sel <= 0)continue;
            foreach($w as $idx => $sfp) {
                if(! is_resource($sfp)) {
                    unset($socks[$idx],$meta[$idx]);
                    continue;
                }$peer = @ stream_socket_get_name($sfp,true);
                if($peer !== false) {
                    foreach($socks as $other)if(is_resource($other)&& $other !== $sfp)@ fclose($other);
                    @ fclose($sfp);
                    return $meta[$idx];
                }@ fclose($sfp);
                unset($socks[$idx],$meta[$idx]);
            }
            foreach($r as $idx => $sfp) {
                if(isset($socks[$idx])) {
                    @ fclose($socks[$idx]);
                    unset($socks[$idx],$meta[$idx]);
                }
            }
        }
        foreach($socks as $sfp)if(is_resource($sfp))@ fclose($sfp);
        return '';
    }$mh = @ curl_multi_init();
    if($mh === false)return '';
    $urls = debz_proxy_probe_urls();
    $timeout = max(1,min((int)$timeoutMs,8000));
    $handles = [];
    $byProxy = [];
    $maxCand = min(8,count($urls)> 1? (int)floor(16 / count($urls)): 8);
    foreach(array_slice($cands,0,$maxCand)as $p) {
        $parts = parse_url('http://'.$p);
        $host = (string)($parts['host']?? '');
        $port = (int)($parts['port']?? 0);
        if($host === '' || $port <= 0 || $port > 65535)continue;
        foreach($urls as $url) {
            $isZen = (stripos($url,'opencode.ai')!== false || stripos($url,'/zen/')!== false);
            $ch = @ curl_init();
            if($ch === false)continue;
            @ curl_setopt($ch,CURLOPT_URL,$url);
            @ curl_setopt($ch,CURLOPT_PROXY,$p);
            @ curl_setopt($ch,CURLOPT_PROXYTYPE,CURLPROXY_HTTP);
            @ curl_setopt($ch,CURLOPT_HTTPPROXYTUNNEL,true);
            @ curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,$isZen);
            @ curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,$isZen? 2: 0);
            @ curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
            @ curl_setopt($ch,CURLOPT_CONNECTTIMEOUT_MS,$timeout);
            @ curl_setopt($ch,CURLOPT_TIMEOUT_MS,$timeout);
            @ curl_setopt($ch,CURLOPT_USERAGENT,'Mozilla/5.0 (Linux; Android 14) Probe/2.0');
            @ curl_multi_add_handle($mh,$ch);
            $id = (int)$ch;
            $handles[$id]= $p;
            $byProxy[$p][]= $ch;
        }
    }
    if(empty($handles)) {
        @ curl_multi_close($mh);
        return '';
    }$deadline = microtime(true)+($timeout / 1000);
    $running = null;
    do {
        $status = @ curl_multi_exec($mh,$running);
        while($info = curl_multi_info_read($mh)) {
            if(($info['result']?? 0)=== 0) {
                $code = (int)@ curl_getinfo($info['handle'],CURLINFO_RESPONSE_CODE);
                if($code === 200 || $code === 204 || $code === 401 || $code === 403) {
                    $picked = $handles[(int)$info['handle']]?? '';
                    if($picked !== '') {
                        @ curl_multi_remove_handle($mh,$info['handle']);
                        @ curl_close($info['handle']);
                        foreach($byProxy[$picked]??[]as $other) {
                            if(is_resource($other)&& $other !== $info['handle']) {
                                @ curl_multi_remove_handle($mh,$other);
                                @ curl_close($other);
                            }
                        }@ curl_multi_close($mh);
                        return $picked;
                    }
                }
            }@ curl_multi_remove_handle($mh,$info['handle']);
            @ curl_close($info['handle']);
        }
        if(! is_int($running)|| $running <= 0)break;
        if(microtime(true)>= $deadline)break;
        $sel = @ curl_multi_select($mh,0.05);
        if($sel < 0)usleep(5000);
    }while(true);
    foreach($handles as $id => $p) {
        @ curl_multi_remove_handle($mh,$id);
        @ curl_close($id);
    }@ curl_multi_close($mh);
    return '';
}
function debz_proxy_cli_latency(string $proxy): int {
    $parts = parse_url('http://'.$proxy);
    $host = $parts['host']?? '';
    $port = (int)($parts['port']?? 80);
    if($host === '' || $port <= 0 || $port > 65535)return - 1;
    $errno = 0;
    $errstr = '';
    $t0 = microtime(true);
    $fp = @ fsockopen($host,$port,$errno,$errstr,3);
    if($fp) {
        fclose($fp);
        return (int)((microtime(true)- $t0)* 1000);
    }
    return - 1;
}
function debz_proxy_load_scores(): array {
    $f = debz_proxy_score_file();
    if(! is_file($f)|| ! is_readable($f))return[];
    $raw = @ file_get_contents($f);
    if(empty($raw))return[];
    $j = json_decode($raw,true);
    return is_array($j)? $j:[];
}
function debz_proxy_save_scores(array $scores): void {
    $scoreFile = debz_proxy_score_file();
    $dir = dirname($scoreFile);
    if(! is_dir($dir))@ mkdir($dir,0770,true);
    $now = time();
    foreach($scores as $p => & $s) {
        $last = (int)($s['last']?? $now);
        $ageH = max(0,($now - $last)/ 3600);
        if($ageH > 24) {
            unset($scores[$p]);
            continue;
        }$decay = max(0.03125,pow(0.5,$ageH));
        $s['ok']= round((float)($s['ok']?? 0)* $decay,2);
        $s['fail']= round((float)($s['fail']?? 0)* $decay,2);
        $s['last']= $last;
    }unset($s);
    @ file_put_contents($scoreFile,json_encode($scores,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),LOCK_EX);
}
function debz_proxy_score_record(string $proxy,bool $success): void {
    if($proxy === '')return;
    $scores = debz_proxy_load_scores();
    $now = time();
    if(! isset($scores[$proxy])) {
        $scores[$proxy]= ['ok' => 0,'fail' => 0,'last' => $now];
    }
    if($success) {
        $scores[$proxy]['ok']= (int)($scores[$proxy]['ok']?? 0)+ 1;
    }else {
        $scores[$proxy]['fail']= (int)($scores[$proxy]['fail']?? 0)+ 1;
    }$scores[$proxy]['last']= $now;
    debz_proxy_save_scores($scores);
}
function debz_proxy_get_score(string $proxy): int {
    $scores = debz_proxy_load_scores();
    if(! isset($scores[$proxy]))return 50;
    $ok = (int)($scores[$proxy]['ok']?? 0);
    $fail = (int)($scores[$proxy]['fail']?? 0);
    $total = $ok + $fail;
    if($total === 0)return 50;
    return (int)round(($ok / $total)* 100);
}
function debz_proxy_auto_type(): string {
    $best = 'http';
    $bestN = - 1;
    foreach(['http','socks4','socks5']as $pt) {
        $lf = debz_proxy_list_file($pt);
        if(! is_file($lf))continue;
        $raw = @ file($lf,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if(! is_array($raw))continue;
        $n = 0;
        foreach($raw as $line) {
            $line = trim((string)$line);
            if($line !== '' && ! debz_proxy_is_blacklisted($line))$n ++;
        }
        if($n > $bestN) {
            $bestN = $n;
            $best = $pt;
        }
    }
    return $best;
}
function debz_proxy_pick(string $type,bool $forceNew = false): string {
    if($type === 'auto')$type = debz_proxy_auto_type();
    $lf = debz_proxy_list_file($type);
    if(! is_file($lf)|| ! is_readable($lf))return '';
    $raw = @ file($lf,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if(! is_array($raw)|| empty($raw))return '';
    $lines = [];
    $seen = [];
    foreach($raw as $line) {
        $line = trim((string)$line);
        if($line === '' || isset($seen[$line])|| debz_proxy_is_blacklisted($line))continue;
        $seen[$line]= true;
        $lines[]= $line;
    }
    if(empty($lines))return '';
    $triedRound = $GLOBALS['_debz_proxy_tried_round']??[];
    if(! empty($triedRound)) {
        $lines = array_values(array_filter($lines,function($p)use($triedRound) {
            return ! isset($triedRound[$p]);
        }));
        if(empty($lines))return '';
    }usort($lines,function($a,$b) {
        return debz_proxy_get_score($b)- debz_proxy_get_score($a);
    });
    $st = debz_proxy_load_state()??[];
    $sticky = ! empty($st['sticky']);
    if($sticky && ! $forceNew) {
        $current = (string)($st['sticky_proxy']?? '');
        if($current !== '' && in_array($current,$lines,true)&& ! debz_proxy_is_blacklisted($current)) {
            return $current;
        }
    }$rot = (string)($st['rotation']?? 'roundrobin');
    if($rot === 'random') {
        $pick = $lines[array_rand($lines)];
    }elseif($rot === 'first') {
        $pick = $lines[0];
    }else {
        $rr = (int)($st['rr_index']?? 0);
        $pick = $lines[$rr % count($lines)];
    }debz_proxy_state_update(function(array $s)use($pick,$type): array {
        $old = (string)($s['sticky_proxy']?? $s['last_proxy']?? '');
        $s['sticky_proxy']= $pick;
        $s['sticky_type']= $type;
        $s['sticky']= true;
        $s['last_live']= time();
        $s['last_proxy']= $pick;
        $s['rr_index']= (int)($s['rr_index']?? 0)+ 1;
        $s['rotate_event']= ['ts' => time(),'by' => 'php','old' => $old,'new' => $pick];
        return $s;
    });
    return $pick;
}
function debz_proxy_pick_live(string $type,bool $forceNew = false): array {
    if($type === 'auto')$type = debz_proxy_auto_type();
    $ls = debz_proxy_list_file($type);
    if(! is_file($ls)|| ! is_readable($ls))return['proxy' => '','usedType' => $type];
    $raw = @ file($ls,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if(! is_array($raw)|| empty($raw))return['proxy' => '','usedType' => $type];
    $lines = [];
    $seen = [];
    $triedRound = $GLOBALS['_debz_proxy_tried_round']??[];
    foreach($raw as $line) {
        $line = trim((string)$line);
        if($line === '' || isset($seen[$line]))continue;
        $seen[$line]= true;
        if(debz_proxy_is_blacklisted($line))continue;
        if(isset($triedRound[$line]))continue;
        $lines[]= $line;
    }
    if(empty($lines)) {
        if(! empty($triedRound))$GLOBALS['_debz_proxy_tried_round']= [];
        return['proxy' => '','usedType' => $type];
    }    usort($lines,function($a,$b) {
        return debz_proxy_get_score($b)- debz_proxy_get_score($a);
    });
    $stRot = debz_proxy_load_state()??[];
    $rot = (string)($stRot['rotation']?? 'roundrobin');
    if($rot === 'random') {
        shuffle($lines);
    }elseif($rot === 'roundrobin') {
        $rr = (int)($stRot['rr_index']?? 0);
        if(count($lines)> 1) {
            $off = $rr % count($lines);
            if($off > 0)$lines = array_merge(array_slice($lines,$off),array_slice($lines,0,$off));
        }
        debz_proxy_state_update(function(array $s): array {
            $s['rr_index']= (int)($s['rr_index']?? 0)+ 1;
            return $s;
        });
    }
    $enableSticky = debz_proxy_sticky_enabled();
    $batch = 8;
    while(! empty($lines)) {
        $chunk = array_splice($lines,0,$batch);
        $found = debz_proxy_probe_parallel($chunk,4000);
        if($found !== '') {
            debz_proxy_state_update(function(array $s)use($found,$type,$enableSticky): array {
                $old = (string)($s['sticky_proxy']?? $s['last_proxy']?? '');
                $s['sticky_proxy']= $found;
                $s['sticky_type']= $type;
                $s['sticky']= $enableSticky;
                $s['last_live']= time();
                $s['last_proxy']= $found;
                $s['rotate_event']= ['ts' => time(),'by' => 'php','old' => $old,'new' => $found];
                return $s;
            });
            return['proxy' => $found,'usedType' => $type];
        }
        foreach($chunk as $cand) {
            debz_proxy_score_record($cand,false);
            $GLOBALS['_debz_proxy_tried_round'][$cand]= true;
        }
    }
    return['proxy' => '','usedType' => $type];
}
function debz_proxy_failover(string $proxy,string $reason = '',bool $blacklist = true): void {
    if($proxy === '')return;
    if($blacklist) {
        $GLOBALS['debz_bl_reason']= ($reason === 'rl')? 'rl': '';
        debz_proxy_blacklist_add($proxy);
        $GLOBALS['debz_bl_reason']= '';
    }debz_proxy_score_record($proxy,false);
    $GLOBALS['_debz_proxy_tried_round'][$proxy]= true;
    debz_proxy_state_update(function(array $st)use($proxy,$reason,$blacklist): array {
        if(! empty($st['sticky_proxy'])&& $st['sticky_proxy']=== $proxy) {
            unset($st['sticky_proxy']);
            unset($st['sticky_type']);
            $st['sticky']= false;
            $st['rr_index']= (int)($st['rr_index']?? 0)+ 1;
            $st['last_failover']= ['ts' => time(),'proxy' => $proxy,'reason' => $reason,'blacklist' => $blacklist];
        }
        return $st;
    });
}
function debz_proxy_request_grab(): void {
    $dir = __DIR__.'/proxy-grabber';
    $py = $dir.'/proxy_grabber.py';
    if(! is_file($py))return;
    $lock = $dir.'/.grab_once.lock';
    $fp = @ fopen($lock,'c+');
    if($fp) {
        if(! @ flock($fp,LOCK_EX | LOCK_NB)) {
            @ fclose($fp);
            return;
        }$last = (int)@ filemtime($lock);
        if(time()- $last < 300) {
            @ flock($fp,LOCK_UN);
            @ fclose($fp);
            return;
        }@ ftruncate($fp,0);
        @ fwrite($fp,(string)time());
        @ fflush($fp);
        @ flock($fp,LOCK_UN);
        @ fclose($fp);
    }$cmd = 'cd '.escapeshellarg($dir).' && nohup python3 proxy_grabber.py --refresh >> run.log 2>&1 &';
    @ exec($cmd);
    if(function_exists('applog'))applog('PROXY','grab_requested',['throttle_sec' => 300]);
}
function debz_proxy_wait_pool(string $type,int $maxWaitSec = 120): bool {
    $t0 = time();
    $lastGrab = 0;
    while((time()- $t0)< $maxWaitSec) {
        $lf = debz_proxy_list_file($type);
        $has = false;
        if(is_file($lf)&& is_readable($lf)) {
            $raw = @ file($lf,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if(is_array($raw)) {
                foreach($raw as $line) {
                    $line = trim((string)$line);
                    if($line !== '' && ! debz_proxy_is_blacklisted($line)) {
                        $has = true;
                        break;
                    }
                }
            }
        }
        if($has)return true;
        if((time()- $lastGrab)>= 30) {
            debz_proxy_request_grab();
            $lastGrab = time();
        }
        if(function_exists('native_sleep_heartbeat'))native_sleep_heartbeat(4000);
        else usleep(4000000);
    }
    return false;
}
function debz_proxy_apply($ch,array $opts): string {
    $GLOBALS['_debz_last_proxy']= '';
    $st = debz_proxy_load_state();
    if(! is_array($st))return '';
    $mode = (string)($st['mode']?? 'proxy');
    $forceProxy = ! empty($opts['_forceProxy']);
    if(! $forceProxy) {
        if($mode === 'direct')return '';
        if($mode === 'auto') {
            $recent429 = ! empty($GLOBALS['_debz_direct_429_until'])&& time()< $GLOBALS['_debz_direct_429_until'];
            if(empty($opts['_useProxy'])&& ! $recent429)return '';
        }
    }$forceNew = ! empty($opts['_proxyFail']);
    $proxy = '';
    $usedType = '';
    if(! $forceNew) {
        $s2 = debz_proxy_load_state()??[];
        $stickyProxy = (string)($s2['sticky_proxy']?? '');
        $stickyType = (string)($s2['sticky_type']?? 'http');
        if(! empty($s2['sticky'])&& debz_proxy_sticky_enabled()&& $stickyProxy !== '' && ! debz_proxy_is_blacklisted($stickyProxy)) {
            $lastLive = (int)($s2['last_live']?? 0);
            if($lastLive > 0 && (time()- $lastLive)< 600) {
                $proxy = $stickyProxy;
                $usedType = $stickyType;
            }else {
                $probeStatus = debz_proxy_probe_cached($stickyProxy,3000);
                if($probeStatus === 'ok' || $probeStatus === 'timeout') {
                    $proxy = $stickyProxy;
                    $usedType = $stickyType;
                }else {
                    debz_proxy_failover($stickyProxy,($probeStatus === 'refused')? 'dead': 'slow',($probeStatus === 'refused'));
                    $forceNew = true;
                }
            }
        }
    }
    if($proxy === '') {
        $type = (string)($st['type']?? 'http');
        if($type === '')$type = 'http';
        if($type === 'auto')$type = debz_proxy_auto_type();
        $picked = debz_proxy_pick_live($type,$forceNew);
        $proxy = $picked['proxy'];
        $usedType = $picked['usedType']!== ''? $picked['usedType']: $type;
    }
    if($proxy === '') {
        $waitSec = max(10,(int)getenv('AI_PROXY_POOL_WAIT')?: 120);
        if(function_exists('termEmit'))termEmit('warn','⚠️ Pool proxy kosong / gak ada yang live. Nunggu proxy-grabber ngasih proxy baru, NO direct...');
        if(function_exists('applog'))applog('PROXY','pool_empty_wait',['type' => $type,'wait_sec' => $waitSec]);
        if(debz_proxy_wait_pool($type,$waitSec)) {
            unset($GLOBALS['_debz_proxy_tried_round']);
            $pk2 = debz_proxy_pick_live($type,true);
            $proxy = $pk2['proxy'];
            if($pk2['usedType']!== '')$usedType = $pk2['usedType'];
        }else {
            $GLOBALS['_debz_proxy_empty']= true;
            if(function_exists('termEmit'))termEmit('limit','⚠️ Pool proxy masih kosong setelah menunggu — request di-hold, TANPA direct. Coba lagi.');
            if(function_exists('applog'))applog('PROXY','pool_empty_hold',['type' => $type,'wait_sec' => $waitSec]);
            return '';
        }
    }$map = ['http' => CURLPROXY_HTTP,'socks4' => CURLPROXY_SOCKS4,'socks5' => CURLPROXY_SOCKS5];
    if($usedType === '' || ! isset($map[$usedType])) {
        $usedType = 'http';
    }$GLOBALS['_debz_last_proxy']= $proxy;
    curl_setopt($ch,CURLOPT_PROXY,$proxy);
    curl_setopt($ch,CURLOPT_PROXYTYPE,$map[$usedType]?? CURLPROXY_HTTP);
    if(! empty($st['proxy_user'])&& ! empty($st['proxy_pass'])) {
        curl_setopt($ch,CURLOPT_PROXYUSERPWD,$st['proxy_user'].':'.$st['proxy_pass']);
    }
    return $proxy;
}
function termEmit(string $kind,string $text): void {
    $emojis = ['info' => 'ℹ️','think' => '🧠','tool' => '🛠️','ok' => '✅','error' => '🚨','limit' => '⚠️','proxy' => '🌐','retry' => '🔄','fail' => '🔀'];
    $icon = $emojis[$kind]?? '🔹';
    if(function_exists('emit')) {
        emit(['type' => 'terminal','kind' => $kind,'line' =>" $icon   $text "]);
    }
}
function debz_cli_proxy_pick(): array {
    $st = debz_proxy_load_state();
    if(! is_array($st))return['',[]];
    $mode = (string)($st['mode']?? 'proxy');
    if($mode === 'direct')return['',[]];
    if($mode === 'auto') {
        $until = (int)($GLOBALS['_debz_direct_429_until']?? 0);
        if(time()>= $until && empty($GLOBALS['_debz_proxy_force_new']))return['',[]];
    }$type = (string)($st['type']?? 'http');
    if($type === '')$type = 'http';
    if($type === 'auto')$type = debz_proxy_auto_type();
    $forceNew = ! empty($GLOBALS['_debz_proxy_force_new']);
    $GLOBALS['_debz_proxy_force_new']= false;
    $proxy = '';
    $usedType = $type;
    if(! empty($st['sticky'])&& debz_proxy_sticky_enabled()&& ! $forceNew) {
        $sp = (string)($st['sticky_proxy']?? '');
        if($sp !== '' && ! debz_proxy_is_blacklisted($sp)) {
            $lastLive = (int)($st['last_live']?? 0);
            if($lastLive > 0 && (time()- $lastLive)< 600) {
                $proxy = $sp;
                $usedType = (string)($st['sticky_type']?? $type);
            }else {
                $probeStatus = debz_proxy_probe_cached($sp,3000);
                if($probeStatus === 'ok' || $probeStatus === 'timeout') {
                    $proxy = $sp;
                    $usedType = (string)($st['sticky_type']?? $type);
                }else {
                    debz_proxy_failover($sp,($probeStatus === 'refused')? 'dead': 'slow',($probeStatus === 'refused'));
                    $forceNew = true;
                }
            }
        }
    }
    if($proxy === '') {
        $pk = debz_proxy_pick_live($type,$forceNew);
        $proxy = $pk['proxy'];
        if($pk['usedType']!== '')$usedType = $pk['usedType'];
    }
    if($proxy === '') {
        $waitSec = max(10,(int)getenv('AI_PROXY_POOL_WAIT')?: 120);
        if(function_exists('termEmit'))termEmit('warn','⚠️ Pool proxy kosong / gak ada yang live. Nunggu proxy-grabber, NO direct...');
        if(function_exists('applog'))applog('PROXY','cli_pool_empty_wait',['type' => $type,'wait_sec' => $waitSec]);
        if(debz_proxy_wait_pool($type,$waitSec)) {
            unset($GLOBALS['_debz_proxy_tried_round']);
            $pk2 = debz_proxy_pick_live($type,true);
            $proxy = $pk2['proxy'];
            if($pk2['usedType']!== '')$usedType = $pk2['usedType'];
        }
    }
    if($proxy === '') {
        $GLOBALS['_debz_cli_proxy_empty']= true;
        if(function_exists('termEmit'))termEmit('limit','⚠️ Pool proxy masih kosong setelah menunggu — run CLI dibatalkan, TANPA direct. Coba lagi.');
        if(function_exists('applog'))applog('PROXY','cli_pool_empty_abort',['type' => $type]);
        return['',[]];
    }$GLOBALS['_debz_last_proxy']= $proxy;
    if($usedType === '' || ! in_array($usedType,['http','socks4','socks5'],true))$usedType = 'http';
    $scheme = 'http';
    if($usedType === 'socks5')$scheme = 'socks5h';
    elseif($usedType === 'socks4')$scheme = 'socks4a';
    $proxyUrl = $scheme.'://'.$proxy;
    $noProxy = 'localhost,127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,169.254.169.254';
    $env = ['NODE_USE_ENV_PROXY' => '1','HTTPS_PROXY' => $proxyUrl,'https_proxy' => $proxyUrl,'HTTP_PROXY' => $proxyUrl,'http_proxy' => $proxyUrl,'ALL_PROXY' => $proxyUrl,'all_proxy' => $proxyUrl,'NO_PROXY' => $noProxy,'no_proxy' => $noProxy,];
    return[$proxy,$env];
}
// Preflight konektivitas sebelum spawn CLI (mode direct, tanpa proxy).
// Di HP: kalau provider ga bisa dijangkau langsung, CLI gantung tanpa event
// error -> user lihat "ga ada balesan". Tangkap di sini, kasih pesan jelas.
// Hasil di-cache per host selama request (static) biar retry loop ga probe ulang.
function debz_net_preflight(string $baseUrl) {
    static $cache = [];
    $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
    if($host === '')return true;
    if(array_key_exists($host, $cache))return $cache[$host];
    $scheme = strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME));
    $port = (int)parse_url($baseUrl, PHP_URL_PORT);
    if($port <= 0)$port = ($scheme === 'http')? 80: 443;
    $t0 = microtime(true);
    $fp = @fsockopen($host, $port, $sec, $sem, 4);
    $dt = round((microtime(true) - $t0) * 1000);
    if(!is_resource($fp)) {
        $err = "HP tidak bisa menjangkau $host:$port langsung ($sem, {$dt}ms). Chat butuh internet ke provider ini — cek koneksi, atau pasang proxy di pengaturan.";
        $cache[$host] = $err;
        return $err;
    }
    fclose($fp);
    $cache[$host] = true;
    return true;
}
function debz_cli_is_proxy_err(string $text): bool {    if($text === '')return false;
    return (bool)preg_match('/(HTTP\s+429|HTTPS?\s+429|rate limit|quota habis|quota exhausted|too many requests)/i',$text)|| (bool)preg_match('/(connection|tunnel|proxy|could not connect|failed to connect|ECONN|ETIMEDOUT|ENETUNREACH|timeout|socket|reset|refused|broken pipe|network is unreachable|unexpected eof|empty reply|502|503|504)/i',$text);
}
function native_chat_once(string $baseUrl,string $apiKey,string $model,array $messages,array $tools,int $maxTokens,array $opts = []): array {
    $attempt = 0;
    $stripRD = ! empty($opts['strip_reasoning_details']);
    $maxRetry = isset($opts['_maxRetry'])? max(0,(int)$opts['_maxRetry']): max(0,native_config_int('AI_NET_RETRY',30));
    $stallRetries = 0;
    $maxStallRetry = max(0,native_config_int('AI_STALL_RETRY',10));
    $resumeOn = native_config_int('AI_RESUME_PARTIAL',1)=== 1;
    $keptContent = '';
    $keptReasoning = '';
    $GLOBALS['_debz_proxy_tried_round']= [];
    while(true) {
        $sendMsgs = $stripRD? native_strip_rd($messages): $messages;
        if($resumeOn && ($keptContent !== '' || $keptReasoning !== '')) {
            if($keptContent !== '')$sendMsgs[]= ['role' => 'assistant','content' => $keptContent];
            $sendMsgs[]= ['role' => 'user','content' => 'Lanjutkan tepat dari titik terputus, jangan mengulang dari awal.'];
        }
        $r = native_chat_once_raw($baseUrl,$apiKey,$model,$sendMsgs,$tools,$maxTokens,$opts);
        if($r['error']=== '' && $r['content']=== '' && empty($r['toolCalls'])&& $r['reasoning']=== '') {
            $r['error']= 'Blackhole proxy: stream selesai tapi 0 tokens diterima';
            if(($r['dl']?? 1)<= 0)$r['pre_data']= true;
        }
        if($r['error']!== '' && ! $stripRD && native_has_rd($messages)&& stripos($r['error'],'HTTP 400')!== false && $r['content']=== '' && $r['reasoning']=== '') {
            $stripRD = true;
            termEmit('info','Reasoning details ditolak provider, history dinormalisasi dan diulang');
            if(function_exists('applog'))applog('AGENT','strip reasoning_details lalu retry',['err' => substr($r['error'],0,200)]);
            continue;
        }
        if($r['error']=== '') {
            if($keptContent !== '' || $keptReasoning !== '') {
                $r['content'] = $keptContent.(string)$r['content'];
                $r['reasoning'] = $keptReasoning.(string)$r['reasoning'];
            }
            return $r;
        }
        $errTxt = (string)$r['error'];
        $isTransient = (stripos($errTxt,'cURL error')=== 0 || preg_match('/\bHTTP\s+5\d\d\b/i',$errTxt)|| stripos($errTxt,'HTTP 408')!== false || stripos($errTxt,'HTTP 429')!== false || stripos($errTxt,'timeout')!== false || stripos($errTxt,'timed out')!== false || stripos($errTxt,'temporar')!== false || stripos($errTxt,'Blackhole proxy')!== false);
        $is5xxViaProxy = preg_match('/\bHTTP\s+5\d\d\b/i',$errTxt)=== 1 && ! empty($GLOBALS['_debz_last_proxy']);
        $isProxyError = stripos($errTxt,'cURL error')=== 0 || stripos($errTxt,'timeout')!== false || stripos($errTxt,'timed out')!== false || stripos($errTxt,'HTTP 407')!== false || stripos($errTxt,'Failed to connect')!== false || stripos($errTxt,'Could not connect')!== false || stripos($errTxt,'Proxy CONNECT aborted')!== false || stripos($errTxt,'empty reply from server')!== false || stripos($errTxt,'Connection reset')!== false || stripos($errTxt,'Connection refused')!== false || stripos($errTxt,'Network is unreachable')!== false || stripos($errTxt,'Broken pipe')!== false || stripos($errTxt,'unexpected eof')!== false || stripos($errTxt,'Blackhole proxy')!== false || ! empty($is5xxViaProxy);
        $isSSLError = (stripos($errTxt,'cURL error')=== 0)&&(stripos($errTxt,'SSL')!== false || stripos($errTxt,'ssl')!== false || stripos($errTxt,'certificate')!== false || stripos($errTxt,'handshake')!== false)&& stripos($errTxt,'unexpected eof')=== false;
        $isRateLimit = stripos($errTxt,'HTTP 429')!== false;
        $isStall = ! empty($r['stall']);
        if(! $isTransient || $attempt >= $maxRetry) {
            if($resumeOn && ($keptContent !== '' || $keptReasoning !== '')) {
                $r['content'] = $keptContent.(string)$r['content'];
                $r['reasoning'] = $keptReasoning.(string)$r['reasoning'];
                if($r['error']!== '')$r['error'] .= ' (partial disambung dari proxy sebelumnya)';
            }
            return $r;
        }
        if($isStall) {
            if($stallRetries >= $maxStallRetry) {
                if($resumeOn && ($keptContent !== '' || $keptReasoning !== '')) {
                    $r['content'] = $keptContent.(string)$r['content'];
                    $r['reasoning'] = $keptReasoning.(string)$r['reasoning'];
                }
                return $r;
            }
            $stallRetries ++;
            if($resumeOn && ((string)$r['content'] !== '' || (string)$r['reasoning'] !== '')) {
                $keptContent = native_trunc($keptContent.(string)$r['content'],4000);
                $keptReasoning = native_trunc($keptReasoning.(string)$r['reasoning'],4000);
            }
            termEmit('retry',"⏱️ Stall: simpan partial (".strlen($keptContent)." chars), lanjut di rute baru... (stall-retry
            {
                $stallRetries
            }
            /
            {
                $maxStallRetry
            }
            ) ");
            if(function_exists('applog'))applog('PROXY','stall_retry',['attempt' => $attempt,'partial_content' => strlen((string)$r['content'])]);
        }elseif(($r['content']!== '' || $r['reasoning']!== '')&& ! $isProxyError) {
            return $r;
        }elseif(($r['content']!== '' || $r['reasoning']!== '')&& $isProxyError) {
            if($resumeOn) {
                $keptContent = native_trunc($keptContent.(string)$r['content'],4000);
                $keptReasoning = native_trunc($keptReasoning.(string)$r['reasoning'],4000);
                termEmit('retry',"✂️ Stream terputus. Simpan partial (".strlen($keptContent)." chars), putar proxy + lanjutkan...");
            }else {
                termEmit('retry',"✂️ Stream terputus. Membuang partial data dan putar proxy...");
            }
            $r['content']= '';
            $r['reasoning']= '';
            $r['toolCalls']= [];
        }$attempt ++;
        $backoff = min(1000 *(2 ** max(0,$attempt - 1)),12000);
        if($isRateLimit)$backoff = min(max($backoff,15000),30000);
        $shouldRotate = ($isProxyError || $isRateLimit)&& ! empty($GLOBALS['_debz_last_proxy']);
        if($isRateLimit && empty($GLOBALS['_debz_last_proxy'])) {
            $GLOBALS['_debz_direct_429_until']= time()+ 600;
        }
        if($shouldRotate) {
            $preData = ! empty($r['pre_data']);
            if($preData && ! $isRateLimit) {
                debz_proxy_failover($GLOBALS['_debz_last_proxy'],'think',false);
                termEmit('proxy',"🤔 Server mikir kelamaan (0 byte) — proxy sehat, TANPA blacklist, coba rute baru...");
                if(function_exists('applog'))applog('PROXY','think_timeout_soft',['proxy' => $GLOBALS['_debz_last_proxy']]);
            }else {
                debz_proxy_score_record($GLOBALS['_debz_last_proxy'],false);
                if($is5xxViaProxy && ! $isRateLimit) {
                    debz_proxy_failover($GLOBALS['_debz_last_proxy'],'upstream-5xx',false);
                }else {
                    $blReason = $isRateLimit? 'rl': '';
                    debz_proxy_failover($GLOBALS['_debz_last_proxy'],$blReason);
                }
            }$opts['_proxyFail']= true;
            $triedCount = count($GLOBALS['_debz_proxy_tried_round']??[]);
            if(stripos($errTxt,'Blackhole')!== false) {
                termEmit('fail',"🕳️ Blackhole proxy (0 tokens). Memutar ke proxy baru...");
            }elseif($isRateLimit) {
                termEmit('fail',"⚠️ Rate limit (429). Memutar ke proxy baru...");
            }elseif($is5xxViaProxy) {
                termEmit('fail'," 🔀 Provider error 5xx lewat proxy. Coba rute proxy lain... (coba ke- $triedCount ) ");
            }elseif($isSSLError) {
                termEmit('fail'," 🔒 SSL error pada proxy. Memutar ke proxy baru... (coba ke- $triedCount ) ");
            }else {
                termEmit('fail'," 🔀 Proxy error. Memutar ke proxy baru... (coba ke- $triedCount ) ");
            }
        }else {
            $opts['_proxyFail']= false;
        }$opts['_useProxy']= true;
        termEmit('retry'," ⏳ Retry # $attempt / $maxRetry  dalam  ".($backoff / 1000)."s...");
        native_sleep_heartbeat($backoff);
    }
}
function native_chat_once_raw(string $baseUrl,string $apiKey,string $model,array $messages,array $tools,int $maxTokens,array $opts = []): array {
    $tReq0 = microtime(true);
    $url = rtrim($baseUrl,'/').'/chat/completions';
    $payload = ['model' => $model,'messages' => $messages,'stream' => true,'stream_options' =>['include_usage' => true],'stop' =>["</| DSML | invoke>","EOF","</| DSML | tool_calls>","[DONE]"]];
    if(! empty($tools))$payload['tools']= $tools;
    if($maxTokens > 0)$payload['max_tokens']= min($maxTokens,8192);
    $isOR = stripos($baseUrl,'openrouter.ai')!== false;
    $isOpenCode = stripos($baseUrl,'opencode.ai')!== false || stripos($baseUrl,'opencode')!== false;
    $uaPolicy = '';
    $extraHeaders = [];
    foreach((array)$opts as $k => $v) {
        if($v === '' || $v === null)continue;
        if(in_array($k,['user_agent','headers','strip_reasoning_details','_maxRetry','_useProxy','_proxyFail'],true))continue;
        if($k === 'reasoning' && is_array($v)&& ! $isOR)continue;
        $payload[$k]= $v;
    }
    if(isset($opts['user_agent'])&& is_string($opts['user_agent']))$uaPolicy = trim($opts['user_agent']);
    if(isset($opts['headers'])&& is_array($opts['headers'])) {
        foreach($opts['headers']as $hn => $hv) {
            if(is_string($hn)&& $hn !== '' &&(is_string($hv)|| is_numeric($hv))&& (string)$hv !== '') {
                $extraHeaders[]= $hn.': '.(string)$hv;
            }
        }
    }
    if($isOpenCode) {
        $uaPolicy = 'opencode/1.18.31 ai-sdk/provider-utils/4.0.23 runtime/bun/1.3.14';
    }elseif($uaPolicy === '' && $isOR) {
        $uaPolicy = 'opencode/1.0 (linux; x64)';
    }
    if($uaPolicy !== '' && ! debz_ua_ok($uaPolicy)) {
        $uaPolicy = $isOpenCode? 'opencode/1.18.31 ai-sdk/provider-utils/4.0.23 runtime/bun/1.3.14': 'opencode/1.0 (linux; x64)';
    }$encoded = json_encode($payload,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if($encoded === false) {
        return['content' => '','reasoning' => '','reasoningDetails' =>[],'toolCalls' =>[],'usage' => null,'finish_reason' => '','error' => 'Payload Request JSON encode gagal: '.json_last_error_msg(),'http_code' => 0];
    }$chatHeaders = [];
    if(trim($apiKey)=== '' || strtolower(trim($apiKey))=== 'public') {
        $chatHeaders[]= 'Authorization: Bearer public';
    }else {
        $chatHeaders[]= 'Authorization: Bearer '.trim($apiKey);
    }$chatHeaders[]= 'Content-Type: application/json';
    $chatHeaders[]= 'Accept: */*';
    $chatHeaders[]= 'Connection: keep-alive';
    $chatHeaders[]= 'Cache-Control: no-cache';
    if($isOpenCode) {
        $chatHeaders[]= 'x-opencode-client: cli';
        $chatHeaders[]= 'x-opencode-project: global';
        $chatHeaders[]= 'x-opencode-session: '.debz_generate_opencode_id('ses_');
        $chatHeaders[]= 'x-opencode-request: '.debz_generate_opencode_id('msg_');
        $chatHeaders[]= 'Origin: https://opencode.ai';
        $chatHeaders[]= 'Referer: https://opencode.ai/';
        $chatHeaders[]= 'Sec-Fetch-Dest: empty';
        $chatHeaders[]= 'Sec-Fetch-Mode: cors';
        $chatHeaders[]= 'Sec-Fetch-Site: same-origin';
        $chatHeaders[]= 'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7';
    }
    if($isOR) {
        $chatHeaders[]= 'HTTP-Referer: https://c0n73xt.app';
        $chatHeaders[]= 'X-Title: Debz AI';
    }
    foreach($extraHeaders as $eh)$chatHeaders[]= $eh;
    if($uaPolicy !== '')array_unshift($chatHeaders,'User-Agent: '.$uaPolicy);
    $ch = curl_init($url);
    if($ch === false) {
        return['content' => '','reasoning' => '','reasoningDetails' =>[],'toolCalls' =>[],'usage' => null,'finish_reason' => '','error' => 'Gagal inisialisasi cURL provider','http_code' => 0];
    }$result = ['content' => '','reasoning' => '','reasoningDetails' =>[],'toolCalls' =>[],'usage' => null,'finish_reason' => '','error' => '','http_code' => 0,'stall' => false];
    $lineBuf = '';
    $rawBody = '';
    $pendingTool = [];
    $pendingRD = [];
    $sseEvents = 0;
    $invalidSse = 0;
    $processLine = function(string $line)use(& $result,& $pendingTool,& $pendingRD,& $sseEvents,& $invalidSse): void {
        $line = rtrim($line,"\r\n");
        if($line === '' || strncmp($line,'data:',5)!== 0)return;
        $json = trim(substr($line,5));
        if($json === '' || $json === '[DONE]')return;
        $obj = json_decode($json,true);
        if(! is_array($obj)) {
            $invalidSse ++;
            return;
        }$sseEvents ++;
        if(! empty($obj['usage'])&& is_array($obj['usage']))$result['usage']= $obj['usage'];
        $choice = $obj['choices'][0]?? null;
        if(! is_array($choice))return;
        if(isset($choice['finish_reason'])&& $choice['finish_reason']!== null && $choice['finish_reason']!== '') {
            $result['finish_reason']= (string)$choice['finish_reason'];
        }$d = $choice['delta']?? null;
        if(! is_array($d))return;
        if(isset($d['reasoning_content'])&& is_string($d['reasoning_content']))$result['reasoning'].= $d['reasoning_content'];
        elseif(isset($d['reasoning'])&& is_string($d['reasoning']))$result['reasoning'].= $d['reasoning'];
        if(isset($d['content'])&& is_string($d['content']))$result['content'].= $d['content'];
        if(! empty($d['reasoning_details'])&& is_array($d['reasoning_details'])) {
            foreach($d['reasoning_details']as $rd) {
                if(! is_array($rd))continue;
                $rdIdx = max(0,(int)($rd['index']?? 0));
                if(! isset($pendingRD[$rdIdx])) {
                    $pendingRD[$rdIdx]= ['type' => 'reasoning.text','text' => '','data' => '','format' => '','index' => $rdIdx];
                }
                if(! empty($rd['type']))$pendingRD[$rdIdx]['type']= (string)$rd['type'];
                if(! empty($rd['format']))$pendingRD[$rdIdx]['format']= (string)$rd['format'];
                if(isset($rd['text'])&& is_string($rd['text']))$pendingRD[$rdIdx]['text'].= $rd['text'];
                if(isset($rd['data'])&& is_string($rd['data']))$pendingRD[$rdIdx]['data'].= $rd['data'];
            }
        }
        if(! empty($d['tool_calls'])&& is_array($d['tool_calls'])) {
            foreach($d['tool_calls']as $tc) {
                if(! is_array($tc))continue;
                $idx = max(0,(int)($tc['index']?? 0));
                if(! isset($pendingTool[$idx]))$pendingTool[$idx]= ['id' => '','name' => '','args' => '','sig' => null];
                if(! empty($tc['id']))$pendingTool[$idx]['id']= (string)$tc['id'];
                if(! empty($tc['function'])&& is_array($tc['function'])) {
                    if(! empty($tc['function']['name']))$pendingTool[$idx]['name'].= (string)$tc['function']['name'];
                    if(isset($tc['function']['arguments'])&& is_string($tc['function']['arguments'])) {
                        $pendingTool[$idx]['args'].= $tc['function']['arguments'];
                    }
                }$sig = native_extract_tc_sig($tc);
                if($sig !== null)$pendingTool[$idx]['sig']= $sig;
            }
        }
    };
    $write = function($curl,string $chunk)use(& $lineBuf,& $rawBody,$processLine): int {
        $rawBody .= $chunk;
        $lineBuf .= $chunk;
        while(($nl = strpos($lineBuf,"\n"))!== false) {
            $line = substr($lineBuf,0,$nl + 1);
            $lineBuf = substr($lineBuf,$nl + 1);
            $processLine($line);
        }
        return strlen($chunk);
    };
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER => false,CURLOPT_POST => true,CURLOPT_HTTPHEADER => $chatHeaders,CURLOPT_POSTFIELDS => $encoded,CURLOPT_CONNECTTIMEOUT => 12,CURLOPT_TIMEOUT => 150,CURLOPT_LOW_SPEED_LIMIT => 10,CURLOPT_LOW_SPEED_TIME => 20,CURLOPT_TCP_KEEPALIVE => 1,CURLOPT_TCP_KEEPIDLE => 10,CURLOPT_TCP_KEEPINTVL => 5,CURLOPT_SSL_VERIFYPEER => false,CURLOPT_SSL_VERIFYHOST => 0,CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,CURLOPT_ENCODING => "",CURLOPT_WRITEFUNCTION => $write]);
    if(! empty($opts['_sslFallback'])) {
        if(function_exists('termEmit'))termEmit('info',"🔒 SSL verify disabled (fallback mode)");
    }$px = '';
    if(function_exists('debz_proxy_apply')) {
        $px = debz_proxy_apply($ch,$opts);
        if($px !== '') {
            if($px !== (string)($GLOBALS['_debz_proxy_shown']?? '')) {
                $GLOBALS['_debz_proxy_shown']= $px;
                if(function_exists('termEmit'))termEmit('proxy'," Routed via Proxy:  $px ");
            }
            if(function_exists('applog'))applog('PROXY','routed',['proxy' => $px]);
        }        curl_setopt($ch,CURLOPT_TIMEOUT,max(45,native_config_int('AI_PROXY_CURL_TIMEOUT',120)));
        curl_setopt($ch,CURLOPT_LOW_SPEED_TIME,max(8,native_config_int('AI_PROXY_LOWSPEED_S',25)));
    }
    if($px === '' && ! empty($GLOBALS['_debz_proxy_empty'])) {
        $GLOBALS['_debz_proxy_empty']= false;
        if(function_exists('applog'))applog('PROXY','routed_blocked_no_pool',[]);
        return['content' => '','reasoning' => '','reasoningDetails' =>[],'toolCalls' =>[],'usage' => null,'finish_reason' => '','error' => 'Proxy pool kosong — nunggu proxy-grabber tapi belum dapat proxy live. Request di-hold, TANPA direct. Coba lagi nanti.','http_code' => 0];
    }$ok = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $dlBytes = (int)curl_getinfo($ch,CURLINFO_SIZE_DOWNLOAD);
    $result['http_code']= $httpCode;
    $result['dl']= $dlBytes;
    $result['pre_data']= ($dlBytes <= 0 && $result['content']=== '' && $result['reasoning']=== '' && empty($pendingTool));
    if($lineBuf !== '') {
        $processLine($lineBuf);
        $lineBuf = '';
    }curl_close($ch);
    if($ok === true && $httpCode > 0 && $httpCode < 400 && ! empty($GLOBALS['_debz_last_proxy'])) {
        debz_proxy_score_record($GLOBALS['_debz_last_proxy'],true);
        debz_proxy_sticky_ok($GLOBALS['_debz_last_proxy']);
        $slowS = max(30,(int)getenv('AI_PROXY_SLOW_S')?: 120);
        if((microtime(true)- $tReq0)> $slowS) {
            $slowPx = $GLOBALS['_debz_last_proxy'];
            debz_proxy_failover($slowPx,'slow',false);
            if(function_exists('termEmit'))termEmit('proxy',"🐌 Proxy lemot (>".($slowS)."s) — jawaban kepake, request berikut ganti rute.");
            if(function_exists('applog'))applog('PROXY','slow_rotate',['proxy' => $slowPx,'elapsed_s' => round(microtime(true)- $tReq0,1)]);
        }
    }
    if($ok === false || $errno !== 0) {
        $result['error']= 'cURL error: '.($error !== ''? $error: 'errno '.$errno);
        if($errno === 28) {
            if($dlBytes <= 0 && preg_match('/with\s+(\d+)\s+bytes\s+received/i',(string)$error,$mB))$dlBytes = (int)$mB[1];
            if($dlBytes > 0) {
                $result['stall']= true;
                termEmit('fail'," ⏱️ Mid-stream stall: stream mati setelah
                {
                    $dlBytes
                }
                bytes diterima (partial  ".strlen($result['content'])." chars).");
                if(function_exists('applog'))applog('PROXY','midstream_stall',['proxy' => $GLOBALS['_debz_last_proxy']?? '','bytes' => $dlBytes,'partial_content' => strlen((string)$result['content']),'err' => substr($result['error'],0,200)]);
            }
        }
    }elseif($httpCode >= 400) {
        $errBody = '';
        $rawCandidates = [$result['content'],$rawBody];
        foreach($rawCandidates as $rc) {
            $j = json_decode($rc,true);
            if(is_array($j)) {
                $e = $j['error']?? $j['message']?? $j['detail']?? null;
                if(is_string($e)) {
                    $errBody = $e;
                    break;
                }elseif(is_array($e)) {
                    $errBody = (string)($e['message']?? json_encode($e,JSON_UNESCAPED_UNICODE));
                    break;
                }
            }
        }
        if($errBody === '') {
            $plain = trim(preg_replace('/^data:\s*/m','',$rawBody)?? $rawBody);
            $plain = trim(preg_replace('/\s+/',' ',$plain)?? $plain);
            if($plain !== '' && strpos($plain,'[')!== 0)$errBody = native_trunc($plain,300);
        }
        if($errBody === '')$errBody = native_trunc($error !== ''? $error: 'Provider request failed',300);
        $result['error']= 'HTTP '.$httpCode.($errBody !== ''? ' — '.$errBody: '');
        if($httpCode === 429)$result['error'].= ' (Rate Limit / Quota Habis)';
    }elseif($sseEvents === 0 && $result['content']=== '' && empty($pendingTool)&& $result['reasoning']=== '') {
        $result['error']= 'Provider mengirim response kosong/tidak valid';
    }
    foreach($pendingTool as $pt) {
        $name = trim((string)($pt['name']?? ''));
        if($name !== '')$result['toolCalls'][]= $pt;
    }ksort($pendingRD);
    foreach($pendingRD as $rIdx => $rdi) {
        if($rdi['text']=== '' && $rdi['data']=== '')continue;
        $item = ['type' => $rdi['type'],'index' => (int)$rIdx];
        if($rdi['text']!== '')$item['text']= $rdi['text'];
        if($rdi['data']!== '')$item['data']= $rdi['data'];
        if($rdi['format']!== '')$item['format']= $rdi['format'];
        $result['reasoningDetails'][]= $item;
    }
    if(function_exists('debz_proxy_mark_used'))debz_proxy_mark_used($GLOBALS['_debz_last_proxy']?? '');
    return $result;
}
function native_planner_enabled(): bool {
    return native_config_int('AI_PLANNER',1)=== 1;
}
function native_verifier_enabled(): bool {
    return native_config_int('AI_VERIFIER',1)=== 1;
}
function native_planner_max_retry(): int {
    return max(0,native_config_int('AI_PLANNER_RETRY',1));
}
function native_verifier_max_retry(): int {
    return max(0,native_config_int('AI_VERIFIER_RETRY',1));
}
function native_planner_plan(string $userText,int $maxTokens,array $extraOpts,string $baseUrl = '',string $apiKey = '',string $model = ''): string {
    if(! $userText || ! native_planner_enabled())return '';
    if($baseUrl === '' || $model === '')return '';
    $prompt = "Kamu adalah Planner. Buat rencana eksekusi singkat (maks 5 langkah, 1 baris per langkah, format: 1. <aksi>). Jangan eksekusi apa pun. Fokus: langkah konkret, urut, terukur.\n\nTugas: ".native_trunc($userText,1500);
    $msgs = [['role' => 'system','content' => 'Kamu adalah Planner yang membuat to-do list singkat dan konkret. Output hanya daftar langkah, tanpa basa-basi.']];
    $msgs[]= ['role' => 'user','content' => $prompt];
    $opts = $extraOpts;
    $opts['temperature']= 0.1;
    $opts['max_tokens']= min(600,max(100,(int)$maxTokens));
    $r = native_chat_once($baseUrl,$apiKey,$model,$msgs,[],600,$opts);
    if($r['error']!== '')return '';
    $plan = trim((string)$r['content']);
    if(strlen($plan)< 8)return '';
    return $plan;
}
function native_verifier_check(string $userText,string $finalContent,int $maxTokens,array $extraOpts,string $baseUrl = '',string $apiKey = '',string $model = ''): string {
    if(! $userText || ! native_verifier_enabled())return '';
    if($baseUrl === '' || $model === '')return '';
    $final = native_trunc($finalContent,4000);
    $prompt = "Verifikasi hasil pekerjaan berikut terhadap tugas user. Jawab hanya: PASS (jika selesai & benar) atau FAIL:<alasan singkat>.\n\nTugas: ".native_trunc($userText,800)."\n\nHasil:\n".$final;
    $msgs = [['role' => 'system','content' => 'Kamu adalah Verifier yang ketat. Output hanya PASS atau FAIL:<alasan>.']];
    $msgs[]= ['role' => 'user','content' => $prompt];
    $opts = $extraOpts;
    $opts['temperature']= 0.0;
    $opts['max_tokens']= 300;
    $r = native_chat_once($baseUrl,$apiKey,$model,$msgs,[],300,$opts);
    if($r['error']!== '')return '';
    $v = trim((string)$r['content']);
    if(preg_match('/^\s*PASS\b/i',$v))return '';
    if(preg_match('/^\s*FAIL\s*:\s*(.+)$/is',$v,$m))return trim($m[1]);
    return '';
}
function native_phase_emit(string $phase,string $msg): void {
    if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'info','line' => $phase.' '.$msg]);
}
function native_agent_run(array $P,array $messagesIn,int $maxTokens,string $userText,bool $toolsOn = true,array $providerChain = [],? array $PROVIDERS = null,bool $allowSessionIn = false): void {
    $baseUrl = (string)($P['base_url']?? '');
    $apiKey = (string)($P['api_key']?? '');
    $model = (string)($P['model']?? '');
    $extraOpts = isset($P['extra'])&& is_array($P['extra'])? $P['extra']:[];
    $allowSession = $allowSessionIn === true;
    $allowAlways = native_allow_all_get();
    $toolsNote = $toolsOn? "Tools tersedia dan dieksekusi nyata di server. Gunakan tool untuk memverifikasi fakta teknis, membaca file, menjalankan diagnosis, mengubah file, menguji hasil, atau melakukan automation. Jangan mengarang output tool.": "Mode chat biasa tanpa tools.";
    $sysPrompt = $userText !== ''? "Anda adalah Debz AI, Polyglot Principal Software Engineer, Senior Enterprise Architect, dan Expert Code Reviewer yang menguasai seluruh ekosistem pemrograman (JavaScript/TypeScript, Python, Go, Rust, Java, C++, C#, PHP, HTML, CSS, Ruby, SQL, serta berbagai framework modern). Anda memberikan jawaban dengan ketepatan analisis tingkat tinggi sekelas Gemini Pro dan GPT-4o.\n\nSecara otomatis, Anda wajib menyesuaikan diri berdasarkan bahasa pemrograman yang saya berikan dan mematuhi aturan berikut:\n\n1. BAHASA GAUL & GAYA SANTUN (WAJIB)\n   - Balas dengan bahasa Indonesia gaul, santai, dan akrab — kayak ngobrol sama temen sesama developer. Wajar pakai istilah kekinian (gas, mantap, gacor, dll) asal konteksnya pas.\n   - TETAP padat & langsung ke inti teknis: tanpa basa-basi, gak perlu pembuka kayak \"Tentu, ini kodenya...\" atau penutup \"Semoga membantu!\".\n\n2. ADAPTIF TERHADAP EKOSISTEM BAHASA (ECOSYSTEM-SPECIFIC BEST PRACTICES)\n   Jika kode menggunakan:\n   - TypeScript/JavaScript: Patuhi ESM, Strict Mode, Functional Programming, asinkronus yang bersih (async/await), dan minimalisasi dependensi npm.\n   - Python: Terapkan PEP 8, Type Hinting, struktur efisien (list comprehension/generator), dan penanganan memori yang tepat.\n   - Go: Terapkan idiomatic Go, penanganan error eksplisit (if err != nil), efisiensi goroutine/channel, dan zero-allocation jika memungkinkan.\n   - Rust: Patuhi aturan kepemilikan (ownership/borrowing), hindari 'unsafe' dan 'unwrap' tanpa penanganan, serta optimalkan manajemen memori.\n   - Java/C#: Patuhi SOLID principles, OOP yang bersih, penanganan eksepsi yang tepat, dan design patterns standar industri.\n   - C/C++: Prioritaskan manajemen memori yang aman (hindari memory leaks/buffer overflow), efisiensi pointer, dan optimasi kompiler.\n   - SQL: Terapkan optimasi indeks, hindari N+1 query, cegah SQL Injection dengan prepared statements, dan perhatikan efisiensi JOIN.\n\n3. STRUKTUR RESPONS (WAJIB)\n   - ANALISIS SINGKAT: Maksimal 2-3 kalimat di awal tentang pendekatan logika atau akar masalah (root cause) jika itu sebuah bug.\n   - BLOK KODE (PRODUCTION-READY): Tulis kode yang utuh, bersih, aman, memiliki error handling yang kuat, dan siap pakai di lingkungan produksi. Berikan komentar singkat pada baris yang kompleks.\n   - REKOMENDASI LANJUTAN: Gunakan poin-poin singkat hanya untuk menjelaskan kompleksitas algoritma (Big-O), celah keamanan yang dihindari, atau opsi optimasi skala besar.\n\n4. SIKAP REVIEWS & KOREKSI CRITICAL\n   Jika pendekatan atau arsitektur kode yang saya berikan suboptimal, rentan bug, atau tidak aman, koreksi saya secara langsung dan tunjukkan letak kesalahannya beserta solusi alternatif yang lebih efisien. ".$toolsNote." Utamakan akurasi, reasoning yang terstruktur secara internal, verifikasi sebelum menyimpulkan, dan hasil yang dapat dieksekusi. Jawab PADAT: langsung ke inti, maksimal ~300 kata kecuali diminta detail, gunakan bullet & kode, jangan mengulang-ulang atau bertele-tele. Untuk perubahan kode: pahami konteks dulu, buat perubahan minimal yang aman, validasi syntax, uji perilaku penting bila tools tersedia, lalu laporkan hasil nyata. Jangan mengklaim sudah menjalankan sesuatu jika belum. Jika requirement ambigu tetapi masih bisa dikerjakan, pilih asumsi paling aman dan nyatakan asumsi secara singkat. Jika bukti tidak cukup, katakan apa yang belum terverifikasi.".($toolsOn? " Bila tugas menghasilkan prosedur reusable yang benar-benar layak dipakai ulang, simpan sebagai skill setelah tugas selesai. Jangan menyimpan raw log, kredensial, token, atau percakapan.": "").native_inject_skills($userText,$toolsOn).native_load_rules(): "Anda adalah Debz AI, Polyglot Principal Software Engineer, Senior Enterprise Architect, dan Expert Code Reviewer yang menguasai seluruh ekosistem pemrograman (JavaScript/TypeScript, Python, Go, Rust, Java, C++, C#, PHP, HTML, CSS, Ruby, SQL, serta berbagai framework modern). Anda memberikan jawaban dengan ketepatan analisis tingkat tinggi sekelas Gemini Pro dan GPT-4o.\n\nSecara otomatis, Anda wajib menyesuaikan diri berdasarkan bahasa pemrograman yang saya berikan dan mematuhi aturan berikut:\n\n1. BAHASA GAUL & GAYA SANTUN (WAJIB)\n   - Balas dengan bahasa Indonesia gaul, santai, dan akrab — kayak ngobrol sama temen sesama developer. Wajar pakai istilah kekinian (gas, mantap, gacor, dll) asal konteksnya pas.\n   - TETAP padat & langsung ke inti teknis: tanpa basa-basi, gak perlu pembuka kayak \"Tentu, ini kodenya...\" atau penutup \"Semoga membantu!\".\n\n2. ADAPTIF TERHADAP EKOSISTEM BAHASA (ECOSYSTEM-SPECIFIC BEST PRACTICES)\n   Jika kode menggunakan:\n   - TypeScript/JavaScript: Patuhi ESM, Strict Mode, Functional Programming, asinkronus yang bersih (async/await), dan minimalisasi dependensi npm.\n   - Python: Terapkan PEP 8, Type Hinting, struktur efisien (list comprehension/generator), dan penanganan memori yang tepat.\n   - Go: Terapkan idiomatic Go, penanganan error eksplisit (if err != nil), efisiensi goroutine/channel, dan zero-allocation jika memungkinkan.\n   - Rust: Patuhi aturan kepemilikan (ownership/borrowing), hindari 'unsafe' dan 'unwrap' tanpa penanganan, serta optimalkan manajemen memori.\n   - Java/C#: Patuhi SOLID principles, OOP yang bersih, penanganan eksepsi yang tepat, dan design patterns standar industri.\n   - C/C++: Prioritaskan manajemen memori yang aman (hindari memory leaks/buffer overflow), efisiensi pointer, dan optimasi kompiler.\n   - SQL: Terapkan optimasi indeks, hindari N+1 query, cegah SQL Injection dengan prepared statements, dan perhatikan efisiensi JOIN.\n\n3. STRUKTUR RESPONS (WAJIB)\n   - ANALISIS SINGKAT: Maksimal 2-3 kalimat di awal tentang pendekatan logika atau akar masalah (root cause) jika itu sebuah bug.\n   - BLOK KODE (PRODUCTION-READY): Tulis kode yang utuh, bersih, aman, memiliki error handling yang kuat, dan siap pakai di lingkungan produksi. Berikan komentar singkat pada baris yang kompleks.\n   - REKOMENDASI LANJUTAN: Gunakan poin-poin singkat hanya untuk menjelaskan kompleksitas algoritma (Big-O), celah keamanan yang dihindari, atau opsi optimasi skala besar.\n\n4. SIKAP REVIEWS & KOREKSI CRITICAL\n   Jika pendekatan atau arsitektur kode yang saya berikan suboptimal, rentan bug, atau tidak aman, koreksi saya secara langsung dan tunjukkan letak kesalahannya beserta solusi alternatif yang lebih efisien.";
    $sysPrompt .= "\n\n5. ZERO PASSIVE (WAJIB) — DILARANG balas ack kosong/pasif kayak \"Siap, gue masih wait\", \"belum ngedit apa-apa\", \"kalau ada kode/path di-patch kirim\", \"gue diem\", dsb. Saat user nyebut path file + keluhan, itu perintah ACTION: baca file-nya, telusuri root cause, kasih diagnosa + patch langsung (backup dulu, show diff). Kalau user cek hidup (\"MASIH LA GUA JALAN?\"), jawab langsung status kerjaannya.";
    $chatMessages = [['role' => 'system','content' => $sysPrompt]];
    foreach($messagesIn as $m) {
        if(! is_array($m))continue;
        $rawRole = strtolower((string)($m['role']?? ''));
        if(! in_array($rawRole,['system','user','assistant','tool'],true))continue;
        if($rawRole === 'system') {
            $content = isset($m['content'])&& is_string($m['content'])? trim($m['content']): '';
            if($content !== '')$chatMessages[0]['content'].= "\n".$content;
            continue;
        }
        if($rawRole === 'tool') {
            $toolId = trim((string)($m['tool_call_id']?? ''));
            $content = isset($m['content'])&& is_string($m['content'])? $m['content']: '';
            if($toolId !== '')$chatMessages[]= ['role' => 'tool','tool_call_id' => $toolId,'content' => $content];
            continue;
        }$content = isset($m['content'])&& is_string($m['content'])? $m['content']: '';
        if($rawRole === 'assistant' && $content !== '' && ! empty($m['reasoning_details'])&& is_array($m['reasoning_details'])) {
            $rdClean = native_clean_rd($m['reasoning_details']);
            if($rdClean) {
                $chatMessages[]= ['role' => 'assistant','content' => $content,'reasoning_details' => $rdClean];
                continue;
            }
        }
        if($content !== '')$chatMessages[]= ['role' => $rawRole,'content' => $content];
    }
    if(trim($userText)!== '') {
        $dynRules = native_dynamic_rules($userText);
        if($dynRules !== '')$chatMessages[]= ['role' => 'system','content' => $dynRules];
        $chatMessages[]= ['role' => 'user','content' => $userText];
    }$chatMessages = native_context_cap($chatMessages);
    $tools = $toolsOn? native_tool_definitions():[];
    $MAX_ITER = max(1,native_config_int("AI_MAX_ITER",128));
    $MAX_REPEAT = max(2,native_config_int('AI_MAX_REPEAT',4));
    $EMPTY_RETRIES = max(0,native_config_int('AI_EMPTY_RETRY',2));
    termEmit('info'," Agent Start 🚀 | Model:  $model  | Tools:  ".($toolsOn? 'ON 🟢': 'OFF 🔴')."  | Max Iter:  $MAX_ITER ");
    $finalContent = '';
    $totalUsage = ['input_tokens' => 0,'output_tokens' => 0,'total_tokens' => 0];
    $emptyRetries = 0;
    $lastCallSig = '';
    $repeatCount = 0;
    $providerFailures = [];
    $GLOBALS['_native_verify_pass']= 0;
    $currentProvider = $P;
    $optsForCall = $extraOpts;
    if(! empty($providerChain))$optsForCall['_maxRetry']= 1;
    $planText = '';
    if($toolsOn && trim($userText)!== '') {
        $planText = native_planner_plan($userText,$maxTokens,$extraOpts,$baseUrl,$apiKey,$model);
        if($planText !== '') {
            native_phase_emit('📋','Planner: rencana '.substr_count($planText,"\n").' langkah');
            native_stats_record('planner_ok',['steps' => substr_count($planText,"\n")+ 1]);
            $chatMessages[]= ['role' => 'system','content' => "[SYSTEM: Rencana eksekusi (ikuti, tapi boleh adaptasi):]\n".$planText];
        }else {
            native_stats_record('planner_skip',['reason' => 'no_plan']);
        }
    }
    for($iter = 0;
    $iter < $MAX_ITER;
    $iter ++) {
        if(function_exists('emit'))emit(['type' => 'status','phase' => 'thinking','iter' => $iter + 1]);
        if(function_exists('emit'))emit(['type' => 'step','n' => $iter + 1]);
        termEmit('think',"Iterasi ".($iter + 1)." · Berpikir / Evaluasi...");
        $r = native_chat_once($baseUrl,$apiKey,$model,$chatMessages,$tools,$maxTokens,$optsForCall);
        $curSig = '';
        if(! empty($r['toolCalls'])&& is_array($r['toolCalls'])) {
            $sigParts = [];
            foreach($r['toolCalls']as $tc) {
                $normalizedArgs = native_normalize_tool_arguments((string)($tc['args']?? ''));
                $sigParts[]= (string)($tc['name']?? '?').':'.hash('sha256',$normalizedArgs);
            }$curSig = hash('sha256',implode('|',$sigParts));
        }
        if($curSig !== '' && $curSig === $lastCallSig) {
            $repeatCount ++;
            if($repeatCount === $MAX_REPEAT && function_exists('applog')) {
                applog('AGENT','same tool-call repeated (tolerated)',['iter' => $iter + 1,'sig' => $curSig,'repeat' => $repeatCount]);
            }
        }elseif($curSig !== '') {
            $repeatCount = 0;
        }
        if($curSig !== '')$lastCallSig = $curSig;
        if($r['error']!== '') {
            $errTxt = (string)$r['error'];
            $isLimit = preg_match('/\b(429|rate limit|quota)\b/i',$errTxt)=== 1;
            $failoverWorthy = $isLimit || preg_match('/\bHTTP\s+5\d\d\b/i',$errTxt)=== 1 || stripos($errTxt,'cURL error')=== 0 || stripos($errTxt,'timeout')!== false || stripos($errTxt,'timed out')!== false;
            if(function_exists('applog'))applog('AGENT','provider error',['iter' => $iter + 1,'provider' => (string)($currentProvider['name']?? $model),'err' => substr($errTxt,0,300)]);
            if($failoverWorthy && ! empty($providerChain)&& is_array($PROVIDERS)) {
                foreach($providerChain as $fcid) {
                    if(isset($providerFailures[$fcid]))continue;
                    $fp = $PROVIDERS['providers'][$fcid]?? null;
                    if(! is_array($fp))continue;
                    $providerFailures[$fcid]= true;
                    $nextBase = trim((string)($fp['base_url']?? ''));
                    $nextKey = (string)($fp['api_key']?? '');
                    $nextModel = trim((string)($fp['model']?? ''));
                    if($nextBase === '' || $nextModel === '')continue;
                    termEmit('fail','Failover berjalan → '.(string)($fp['name']?? $fcid).' · '.$nextModel);
                    $nextExtra = isset($fp['extra'])&& is_array($fp['extra'])? $fp['extra']:[];
                    if(! empty($providerChain))$nextExtra['_maxRetry']= 1;
                    $r2 = native_chat_once($nextBase,$nextKey,$nextModel,$chatMessages,$tools,$maxTokens,$nextExtra);
                    if($r2['error']=== '') {
                        $baseUrl = $nextBase;
                        $apiKey = $nextKey;
                        $model = $nextModel;
                        $currentProvider = $fp +['name' => $fcid];
                        $extraOpts = $nextExtra;
                        $optsForCall = $nextExtra;
                        $r = $r2;
                        termEmit('ok','Failover Provider Sukses Merespons!');
                        break;
                    }termEmit('error','Failover Gagal · '.native_trunc((string)$r2['error'],100));
                }
            }
            if($r['error']!== '') {
                if(function_exists('emit'))emit(['status' => '⚠️ Error: '.native_trunc($errTxt,90),'progress' => '⚠️ Error: '.native_trunc($errTxt,90),'emoji' => '⚠️']);
                if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n".($isLimit? "😫 **Provider limit/quota:** ": "⚠️ **Provider error:** ").$errTxt]]]]);
                termEmit('error',native_trunc($errTxt,160));
                if(function_exists('emitDone'))emitDone();
                return;
            }
        }
        if(! empty($r['reasoning'])) {
            termEmit('think','Reasoning Selesai · '.mb_strlen((string)$r['reasoning']).' chars');
        }$rdItems = ! empty($r['reasoningDetails'])&& is_array($r['reasoningDetails'])? $r['reasoningDetails']:[];
        if($rdItems && function_exists('emit'))emit(['type' => 'reasoning','items' => $rdItems,'iter' => $iter + 1]);
        if($r['content']!== '') {
            if(function_exists('emit')) {
                emit(['type' => 'status','phase' => 'writing']);
                emit(['choices' =>[['delta' =>['content' => $r['content']]]]]);
            }$finalContent .= $r['content'];
        }
        if(! empty($r['usage'])&& is_array($r['usage'])) {
            $u = $r['usage'];
            $in = (int)($u['prompt_tokens']?? $u['input_tokens']?? 0);
            $out = (int)($u['completion_tokens']?? $u['output_tokens']?? 0);
            $tot = (int)($u['total_tokens']??($in + $out));
            $totalUsage['input_tokens']+= $in;
            $totalUsage['output_tokens']+= $out;
            $totalUsage['total_tokens']+= $tot;
        }
        if(empty($r['toolCalls'])) {
            if(trim($finalContent)!== '') {
                $verifyRetries = 0;
                $vMax = native_verifier_max_retry();
                $vFail = '';
                if($toolsOn && trim($userText)!== '' && $vMax > 0) {
                    $vFail = native_verifier_check($userText,$finalContent,$maxTokens,$extraOpts,$baseUrl,$apiKey,$model);
                    if($vFail !== '') {
                        $GLOBALS['_native_verify_pass']++;
                        if($GLOBALS['_native_verify_pass']> native_verifier_max_pass()) {
                            native_phase_emit('🔍','Verifier: batas '.native_verifier_max_pass().' pass tercapai — hasil diterima');
                            native_stats_record('verifier_pass',['reason' => 'max_pass']);
                            $vFail = '';
                        }else {
                            native_phase_emit('🔍','Verifier: FAIL (pass '.$GLOBALS['_native_verify_pass'].'/'.native_verifier_max_pass().') — '.native_trunc($vFail,120));
                            native_stats_record('verifier_fail',['reason' => native_trunc($vFail,100)]);
                            while($vFail !== '' && $verifyRetries < $vMax) {
                                $verifyRetries ++;
                                $chatMessages[]= ['role' => 'system','content' => '[SYSTEM: Verifier menolak hasil: '.native_trunc($vFail,400).' Perbaiki hasil tersebut lalu keluarkan jawaban final yang benar.]'];
                                $r2 = native_chat_once($baseUrl,$apiKey,$model,$chatMessages,$tools,$maxTokens,$optsForCall);
                                if($r2['error']!== '') {
                                    $vFail = '';
                                    break;
                                }
                                if(! empty($r2['content'])) {
                                    if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n[Perbaikan Verifier]\n"]]]]);
                                    if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => $r2['content']]]]]);
                                    $finalContent .= "\n\n[Perbaikan Verifier]\n".$r2['content'];
                                }
                                if(! empty($r2['toolCalls'])) {
                                    $chatMessages[]= ['role' => 'assistant','content' => $r2['content']!== ''? $r2['content']: null,'tool_calls' =>[]];
                                    foreach($r2['toolCalls']as $tc) {
                                        $chatMessages[count($chatMessages)- 1]['tool_calls'][]= ['id' => 'call_v'.$verifyRetries.'_'.bin2hex(random_bytes(4)),'type' => 'function','function' =>['name' => trim((string)($tc['name']?? '')),'arguments' => native_normalize_tool_arguments((string)($tc['args']?? ''))!== ''? native_normalize_tool_arguments((string)($tc['args']?? '')): '{}']];
                                    }break;
                                }$vFail = native_verifier_check($userText,$finalContent,$maxTokens,$extraOpts,$baseUrl,$apiKey,$model);
                            }
                        }
                    }
                }native_stats_record('run_end',['in' => $totalUsage['input_tokens'],'out' => $totalUsage['output_tokens'],'iter' => $iter + 1]);
                termEmit('ok','Tugas Selesai ✨ · '.($iter + 1).' iterasi · '.$totalUsage['total_tokens'].' tokens');
                if($totalUsage['total_tokens']> 0 && function_exists('emit'))emit(['type' => 'usage']+ $totalUsage);
                native_rag_capture($userText,$finalContent);
                if(function_exists('emitDone'))emitDone();
                return;
            }
            if($emptyRetries < $EMPTY_RETRIES) {
                $emptyRetries ++;
                $chatMessages[]= ['role' => 'user','content' => 'Berikan jawaban final sekarang. Jangan hanya reasoning. Jika tugas memerlukan verifikasi, gunakan tool lalu keluarkan hasil final yang ringkas dan lengkap.'];
                termEmit('info','Output kosong · Mengulang ('.$emptyRetries.'/'.$EMPTY_RETRIES.')');
                continue;
            }
            if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ Model tidak menghasilkan jawaban final yang dapat ditampilkan."]]]]);
            termEmit('error','Empty final output');
            if(function_exists('emitDone'))emitDone();
            return;
        }$callIds = [];
        foreach($r['toolCalls']as $i => $tc) {
            $id = trim((string)($tc['id']?? ''));
            $callIds[$i]= $id !== ''? $id: 'call_'.$iter.'_'.$i.'_'.bin2hex(random_bytes(4));
        }$assistantMsg = ['role' => 'assistant','content' => $r['content']!== ''? $r['content']: null,'tool_calls' =>[]];
        foreach($r['toolCalls']as $i => $tc) {
            $name = trim((string)($tc['name']?? ''));
            $argsRaw = (string)($tc['args']?? '');
            $argsNormalized = native_normalize_tool_arguments($argsRaw);
            $tcMsg = ['id' => $callIds[$i],'type' => 'function','function' =>['name' => $name,'arguments' => $argsNormalized !== ''? $argsNormalized: '{}']];
            if(! empty($tc['sig']))$tcMsg['extra_content']= $tc['sig'];
            $assistantMsg['tool_calls'][]= $tcMsg;
        }
        if($rdItems)$assistantMsg['reasoning_details']= $rdItems;
        $chatMessages[]= $assistantMsg;
        foreach($r['toolCalls']as $i => $tc) {
            $callId = $callIds[$i];
            $toolName = trim((string)($tc['name']?? ''));
            $argsRaw = (string)($tc['args']?? '');
            $argsJson = native_normalize_tool_arguments($argsRaw);
            $args = json_decode($argsJson !== ''? $argsJson: '{}',true);
            if(! is_array($args)) {
                $outData = ['error' => 'Arguments tool tidak valid JSON','raw_arguments' => native_trunc($argsRaw,1000)];
            }else {
                $detail = native_tool_arg_summary($toolName,$args);
                if(function_exists('emit'))emit(['type' => 'tool','phase' => 'start','id' => $callId,'name' => $toolName,'detail' => $detail]);
                if($toolName === 'write_file') {
                    $wp = native_write_file_preview($args);
                    if($wp !== null && function_exists('emit'))emit(['type' => 'tool','phase' => 'preview','id' => $callId,'name' => $toolName]+ $wp);
                }termEmit('tool'," Mengeksekusi:  $toolName ".($detail !== ''? ' ➔ '.$detail: ''));
                $endpoint = native_tool_endpoint($toolName);
                $t0 = microtime(true);
                $strikeKey = '';
                $strikeAttempted = false;
                if($endpoint === null) {
                    $outData = ['error' => 'Tool tidak dikenal: '.$toolName];
                }else {
                    $strikeKey = native_strike_key($toolName,$args);
                    $strikeBlock = native_strike_check($strikeKey);
                    if($strikeBlock !== '') {
                        $outData = ['error' => $strikeBlock];
                        termEmit('limit','⛔ Circuit Breaker: '.$toolName.' diblokir (3x gagal identik)');
                    }else {
                        $strikeAttempted = true;
                        $approvalInfo = null;
                        list($ok,$outData)= native_call_tool($endpoint,$args,$approvalInfo);
                        if(! $ok && $approvalInfo !== null) {
                            $autoMode = $allowAlways? 'always':($allowSession? 'session': '');
                            if($autoMode !== '') {
                                if(function_exists('emit'))emit(['type' => 'approval_done','choice' => $autoMode,'auto' => true]);
                                $args['approved']= true;
                                list($ok2,$outData2)= native_call_tool($endpoint,$args,$approvalInfo2);
                                $outData = $ok2? $outData2:['error' => $outData2['error']?? 'Tool gagal setelah approval'];
                            }else {
                                if(function_exists('emit'))emit(['type' => 'approval','run_id' => 'native_'.$callId,'command' => $approvalInfo['command']?? '','reason' => 'Perintah memerlukan approval: '.($approvalInfo['reason']?? ''),'choices' =>['once','session','always','deny'],'tool' => $toolName]);
                                $choice = native_wait_approval('native_'.$callId,900);
                                if(function_exists('emit'))emit(['type' => 'approval_done','choice' => $choice,'run_id' => 'native_'.$callId]);
                                if($choice === 'deny') {
                                    $outData = ['error' => 'User menolak atau approval timeout'];
                                }else {
                                    if($choice === 'session')$allowSession = true;
                                    if($choice === 'always') {
                                        $allowAlways = true;
                                        native_set_allow_all(true);
                                    }$args['approved']= true;
                                    list($ok2,$outData2)= native_call_tool($endpoint,$args,$approvalInfo2);
                                    $outData = $ok2? $outData2:['error' => $outData2['error']?? 'Tool gagal setelah approval'];
                                }
                            }
                        }
                    }
                }$sum = native_tool_result_summary($toolName,$outData);
                $ms = (int)((microtime(true)- $t0)* 1000);
                $okTool = is_array($outData)? empty($outData['error']): true;
                if($strikeAttempted && $strikeKey !== '') {
                    if($okTool)native_strike_reset($strikeKey);
                    else native_strike_track($strikeKey);
                }
                if(function_exists('emit'))emit(['type' => 'tool','phase' => 'result','id' => $callId,'name' => $toolName,'summary' => $sum,'ok' => $okTool]);
                $prev = native_tool_result_preview($toolName,$args,$outData);
                if($prev !== null && function_exists('emit'))emit(['type' => 'tool','phase' => 'preview','id' => $callId,'name' => $toolName]+ $prev);
                termEmit($okTool? 'ok': 'error',($okTool? 'Hasil Tool Ok: ': 'Hasil Tool Gagal: ').$sum.' ['.$ms.'ms]');
                if(function_exists('applog'))applog('AGENT','tool done: '.$toolName,['ms' => $ms,'ok' => $okTool,'summary' => $sum]);
            }$toolContent = json_encode($outData,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if($toolContent === false)$toolContent = json_encode(['error' => 'Tool output JSON encode gagal']);
            $chatMessages[]= ['role' => 'tool','tool_call_id' => $callId,'content' => $toolContent];
        }
        if($iter > 0 && $iter % 3 === 0) {
            $chatMessages[]= ['role' => 'system','content' => 'REMINDER: Fokus pada penyelesaian tugas. Jangan berhalusinasi atau mencetak tag internal. Output jawaban final jika tugas sudah selesai, atau panggil tool dengan format yang benar.'];
        }
    }
    if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' =>" \n\n⚠️ **Batas iterasi agent (
    {
        $MAX_ITER
    }
    ) tercapai.** "]]]]);
    termEmit('limit','Batas Max Iterasi '.$MAX_ITER.' tercapai ⛔');
    if(function_exists('applog'))applog('AGENT','MAX_ITER tercapai',['iter' => $MAX_ITER]);
    if(function_exists('emitDone'))emitDone();
}
function native_agent_run_opencode_card(array $P,string $model,string $userText,string $openSession,string $mapFile,string $threadId,array $serveCfg,string $bin,bool $allowSessionIn = false,array $attachFiles = []): void {
    @ set_time_limit(0);
    global $emittedAnything,$doneSent;
    if(function_exists('applog'))applog('OPENCODE_CARD','start',['model' => $model,'thread' => substr($threadId,0,40),'user_len' => strlen($userText),'files' => count($attachFiles)]);
    $serverUrl = rtrim((string)($serveCfg['url']?? ''),'/');
    if($serverUrl === '')$serverUrl = 'http://127.0.0.1:'.(int)($serveCfg['port']?? 4096);
    $pass = (string)($serveCfg['password']?? '');
    $dirEnc = rawurlencode(str_replace('\\','/',__DIR__));
    $auth = 'Basic '.base64_encode('opencode:'.$pass);
    $httpReject = [];
    $httpTimeout = [];
    $sid = trim((string)$openSession);
    $newSession = false;
    if($sid === '') {
        $ch = curl_init($serverUrl.'/session');
        curl_setopt($ch,CURLOPT_POST,true);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch,CURLOPT_HTTPHEADER,['Authorization: '.$auth,'x-opencode-directory: '.$dirEnc,'Content-Type: application/json']);
        curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['directory' => __DIR__],JSON_UNESCAPED_SLASHES));
        curl_setopt($ch,CURLOPT_TIMEOUT,30);
        $raw = curl_exec($ch);
        curl_close($ch);
        $sj = json_decode((string)$raw,true);
        $sid = (string)($sj['id']?? $sj['sessionID']?? '');
        if($sid !== '') {
            $newSession = true;
            if($mapFile !== '')@ file_put_contents($mapFile,json_encode(['sessionID' => $sid,'updated' => date('c'),'model' => $model]),LOCK_EX);
        }
    }
    if($sid === '') {
        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Gagal membuat session opencode serve** (kartu approval).\n"]]]]);
        if(function_exists('emitDone'))emitDone();
        return;
    }$sseCmd = ['curl','-s','-N','-u','opencode:'.$pass,'-H','x-opencode-directory: '.$dirEnc,'-H','Accept: text/event-stream',$serverUrl.'/event'];
    $sseDesc = [0 =>['pipe','r'],1 =>['pipe','w'],2 =>['pipe','w']];
    $ssePipes = [];
    $sseProc = @ proc_open($sseCmd,$sseDesc,$ssePipes,null,null);
    if(! is_resource($sseProc)) {
        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Gagal subscribe SSE** (kartu approval): ".implode(' ',$sseCmd)."\n"]]]]);
        if(function_exists('emitDone'))emitDone();
        return;
    }stream_set_blocking($ssePipes[1],false);
    stream_set_blocking($ssePipes[2],false);
    $postDone = false;
    $cardDone = false;
    $msgParts = [['type' => 'text','text' => (string)$userText]];
    foreach($attachFiles as $af) {
        $af = (string)$af;
        if($af === '' || ! is_file($af))continue;
        $mime = (string)(@ mime_content_type($af)?: 'application/octet-stream');
        $raw = @ file_get_contents($af);
        if($raw === false || $raw === '')continue;
        $msgParts[]= ['type' => 'file','mime' => $mime,'filename' => basename($af),'url' => 'data:'.$mime.';base64,'.base64_encode($raw)];
    }
    $msgBody = json_encode(['providerID' => 'opencode','modelID' => $model,'parts' => $msgParts],JSON_UNESCAPED_SLASHES);
    $postTmp = tempnam(sys_get_temp_dir(),'occard_');
    $postBodyTmp = tempnam(sys_get_temp_dir(),'occard_body_');
    @ file_put_contents($postBodyTmp,$msgBody);
    $postCmd = ['curl','-s','-X','POST','-u','opencode:'.$pass,'-H','x-opencode-directory: '.$dirEnc,'-H','Content-Type: application/json','-d','@'.$postBodyTmp,'-o',$postTmp,'--max-time','1800',$serverUrl.'/session/'.$sid.'/message'];
    $postPipes = [];
    $postProc = @ proc_open($postCmd,[0 =>['pipe','r'],1 =>['pipe','w'],2 =>['pipe','w']],$postPipes,null,null);
    if(is_resource($postProc)) {
        fclose($postPipes[0]);
        stream_set_blocking($postPipes[1],false);
        stream_set_blocking($postPipes[2],false);
    }else {
        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Gagal menjalankan POST message** (kartu approval).\n"]]]]);
        if(is_file($postTmp))@ unlink($postTmp);
        if(isset($postBodyTmp)&& is_file($postBodyTmp))@ unlink($postBodyTmp);
        if(function_exists('emitDone'))emitDone();
        return;
    }$t0 = time();
    $lastAct = time();
    $toolCardN = 0;
    $buf = '';
    $msgRoles = [];
    $allowAlwaysCard = native_allow_all_get();
    $allowSessionCard = $allowSessionIn === true;
    while(! $cardDone) {
        if((time()- $lastAct)> 900) {
            fputs(STDERR,"OCCARD_STALL\n");
            break;
        }$read = [];
        if(is_resource($ssePipes[1])&& ! feof($ssePipes[1]))$read[]= $ssePipes[1];
        if(is_resource($postPipes[1])&& ! feof($postPipes[1]))$read[]= $postPipes[1];
        $write = null;
        $except = null;
        $n = @ stream_select($read,$write,$except,1);
        if($n === false)break;
        $drainedAny = false;
        foreach($read as $r) {
            $chunk = @ fread($r,65536);
            if($chunk === false || $chunk === '')continue;
            $drainedAny = true;
            if($r === $ssePipes[1]) {
                $buf .= $chunk;
                $lastAct = time();
                while(($dd = strpos($buf,"\n\n"))!== false) {
                    $block = substr($buf,0,$dd);
                    $buf = (string)substr($buf,$dd + 2);
                    foreach(explode("\n",$block)as $ln) {
                        $ln = trim($ln);
                        if(strpos($ln,'data: ')!== 0)continue;
                        $ev = json_decode(substr($ln,6),true);
                        if(! is_array($ev))continue;
                        $typ = (string)($ev['type']?? '');
                        $props = isset($ev['properties'])&& is_array($ev['properties'])? $ev['properties']:[];
                        if($typ === 'permission.asked' && function_exists('emit')) {
                            $pid = (string)($props['id']?? '');
                            $cmdLine = (string)($props['metadata']['command']?? '');
                            if($cmdLine === '') {
                                $pats = $props['patterns']??[];
                                $cmdLine = is_array($pats)&& isset($pats[0])? (string)$pats[0]: '';
                            }$permName = (string)($props['permission']?? 'tool');
                            if($allowAlwaysCard || $allowSessionCard) {
                                $autoResp = $allowAlwaysCard? 'always': 'once';
                                if(function_exists('emit'))emit(['type' => 'approval_done','choice' => $allowAlwaysCard? 'always': 'session','auto' => true]);
                                if($pid !== '') {
                                    $ch = curl_init($serverUrl.'/session/'.rawurlencode($sid).'/permissions/'.rawurlencode($pid));
                                    curl_setopt($ch,CURLOPT_POST,true);
                                    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
                                    curl_setopt($ch,CURLOPT_HTTPHEADER,['Authorization: '.$auth,'x-opencode-directory: '.$dirEnc,'Content-Type: application/json']);
                                    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['response' => $autoResp,'remember' => $allowAlwaysCard]));
                                    curl_setopt($ch,CURLOPT_TIMEOUT,30);
                                    curl_exec($ch);
                                    curl_close($ch);
                                }
                                if(function_exists('applog'))applog('OPENCODE_CARD','permission-auto',['id' => substr($pid,0,16),'choice' => $allowAlwaysCard? 'always': 'session','cmd' => substr($cmdLine,0,120)]);
                                continue;
                            }$toolCardN ++;
                            $runCardId = 'native_occard_'.$toolCardN.'_'.md5($pid);
                            if(function_exists('emit'))emit(['type' => 'approval','run_id' => $runCardId,'command' => $cmdLine,'reason' => 'opencode minta izin tool: '.$permName,'choices' =>['once','session','always','deny'],'tool' => 'opencode.'.$permName]);
                            $choice = native_wait_approval($runCardId,900);
                            if(function_exists('emit'))emit(['type' => 'approval_done','choice' => $choice,'run_id' => $runCardId]);
                            if($choice === 'session')$allowSessionCard = true;
                            if($choice === 'always') {
                                $allowAlwaysCard = true;
                                native_set_allow_all(true);
                            }$respMap = ['once' => 'once','always' => 'always','session' => 'once','deny' => 'reject'];
                            $resp = isset($respMap[$choice])? $respMap[$choice]: 'reject';
                            if($pid !== '') {
                                $ch = curl_init($serverUrl.'/session/'.rawurlencode($sid).'/permissions/'.rawurlencode($pid));
                                curl_setopt($ch,CURLOPT_POST,true);
                                curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
                                curl_setopt($ch,CURLOPT_HTTPHEADER,['Authorization: '.$auth,'x-opencode-directory: '.$dirEnc,'Content-Type: application/json']);
                                curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['response' => $resp,'remember' =>($choice === 'always')]));
                                curl_setopt($ch,CURLOPT_TIMEOUT,30);
                                curl_exec($ch);
                                curl_close($ch);
                            }
                            if($choice === 'deny')$lastAct = time();
                            if(function_exists('applog'))applog('OPENCODE_CARD','permission',['id' => substr($pid,0,16),'choice' => $choice,'cmd' => substr($cmdLine,0,120)]);
                            continue;
                        }
                        if($typ === 'message.updated') {
                            $info = isset($props['info'])&& is_array($props['info'])? $props['info']:[];
                            $mid = (string)($info['id']?? '');
                            $mrole = (string)($info['role']?? '');
                            if($mid !== '' && $mrole !== '')$msgRoles[$mid]= $mrole;
                            continue;
                        }$evType = $typ;
                        $evPart = isset($props['part'])&& is_array($props['part'])? $props['part']:[];
                        $evMsgId = (string)($evPart['messageID']?? '');
                        if($evMsgId !== '' && isset($msgRoles[$evMsgId])&& $msgRoles[$evMsgId]=== 'user')continue;
                        $bool = native_oc_card_emit($evType,$props,$evPart);
                        if($bool !== '') {
                            $lastAct = time();
                        }
                    }
                }
            }else {
                $chunkLen = strlen($chunk);
            }
        }
        if(is_resource($postProc)) {
            $ps = proc_get_status($postProc);
            if(isset($ps['running'])&& ! $ps['running']&& ! $postDone) {
                $postDone = true;
                @ fclose($postPipes[1]);
                @ fclose($postPipes[2]);
                $lastAct = time();
            }
        }
        if($postDone && ! $drainedAny) {
            $cardDone = true;
            break;
        }
        if(is_resource($ssePipes[1])) {
            $ss = proc_get_status($sseProc);
            if(isset($ss['running'])&& ! $ss['running']) {
                $cardDone = true;
                break;
            }
        }
        if(function_exists('connection_aborted')&& @ connection_aborted()=== 1) {
            $cardDone = true;
            break;
        }
    }
    if(is_resource($ssePipes[1])) {
        @ fclose($ssePipes[1]);
    }
    if(is_resource($ssePipes[2])) {
        @ fclose($ssePipes[2]);
    }
    if(is_resource($sseProc)) {
        $pgid = null;
        try {
            $pgid = proc_get_status($sseProc)['pid']?? null;
        }catch(Exception $e) {
            $pgid = null;
        }@ proc_terminate($sseProc,9);
        @ proc_close($sseProc);
    }
    if(is_resource($postPipes[1])) {
        @ fclose($postPipes[1]);
    }
    if(is_resource($postPipes[2])) {
        @ fclose($postPipes[2]);
    }
    if(is_resource($postProc)) {
        @ proc_terminate($postProc,9);
        @ proc_close($postProc);
    }
    if(is_file($postTmp))@ unlink($postTmp);
    if(isset($postBodyTmp)&& is_file($postBodyTmp))@ unlink($postBodyTmp);
    if(function_exists('emitDone'))emitDone();
}
function native_oc_card_emit(string $evType,array $props,array $evPart): string {
    $progress = '';
    try {
        $part = [];
        if(! empty($evPart)&& is_array($evPart))$part = $evPart;
        elseif(isset($props['part'])&& is_array($props['part']))$part = $props['part'];
        if($evType === 'message.part.updated' || $evType === 'message.part.delta') {
            $ptype = (string)($part['type']?? '');
            $txt = (string)($part['text']?? '');
            if($ptype === 'text' && $txt !== '') {
                if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => $txt]]]]);
                $progress = 'text';
            }elseif($ptype === 'reasoning' && $txt !== '') {
                if(function_exists('emit'))emit(['type' => 'reasoning','items' =>[['type' => 'reasoning.text','text' => $txt]]]);
                $progress = 'reasoning';
            }elseif($ptype === 'step-start') {
                $it = (int)($part['iterations']?? 0);
                if(function_exists('emit'))emit(['type' => 'status','phase' => 'thinking','iter' => $it + 1]);
                if(function_exists('emit'))emit(['type' => 'step','n' => $it + 1]);
                $progress = 'step';
            }elseif($ptype === 'step-finish') {
                if(isset($part['tokens'])&& is_array($part['tokens'])) {
                    $tk = $part['tokens'];
                    $tot = (int)($tk['total']?? 0);
                    if($tot > 0 && function_exists('emit'))emit(['type' => 'usage','input_tokens' => (int)($tk['input']?? 0),'output_tokens' => (int)($tk['output']?? 0),'total_tokens' => $tot]);
                }$progress = 'step_finish';
            }elseif($ptype === 'tool') {
                $tool = (string)($part['tool']?? '');
                $st = isset($part['state'])&& is_array($part['state'])? $part['state']:[];
                $stStatus = (string)($st['status']?? '');
                $detail = '';
                if(isset($st['input'])&& is_array($st['input'])) {
                    foreach(['command','file','path','query','pattern','url','name','skill','search']as $dk) {
                        if(! empty($st['input'][$dk])&& is_string($st['input'][$dk])) {
                            $detail = trunc($st['input'][$dk],64);
                            break;
                        }
                    }
                }
                if(! isset($GLOBALS['_oc_card_tool_n']))$GLOBALS['_oc_card_tool_n']= 0;
                $GLOBALS['_oc_card_tool_n']++;
                $ocId = 'occard_'.$GLOBALS['_oc_card_tool_n'];
                if(function_exists('emit'))emit(['type' => 'tool','phase' => 'start','id' => $ocId,'name' => $tool,'detail' => $detail]);
                if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'tool','line' => '🛠️ '.$tool.($detail !== ''? ' · '.$detail: '')]);
                if($stStatus === 'completed' || $stStatus === 'error') {
                    $outRaw = $st['output']?? '';
                    if(is_array($outRaw))$outRaw = json_encode($outRaw);
                    $stErr = (string)($st['error']?? '');
                    $sum = '';
                    if(function_exists('summarizeToolOutput'))$sum = summarizeToolOutput((string)$outRaw);
                    $prev = null;
                    if(function_exists('native_oc_tool_preview'))$prev = native_oc_tool_preview($tool,$st,$detail);
                    $ok = ($stStatus !== 'error');
                    if($sum === '' && $stErr !== '')$sum = $stErr;
                    if($sum === '')$sum = $stStatus;
                    if($prev !== null && function_exists('emit'))emit(['type' => 'tool','phase' => 'preview','id' => $ocId,'name' => $tool]+ $prev);
                    if(function_exists('emit'))emit(['type' => 'tool','phase' => 'result','id' => $ocId,'name' => $tool,'summary' => $sum,'ok' => $ok]);
                    if(function_exists('emit'))emit(['type' => 'terminal','kind' => $ok? 'ok': 'error','line' =>($ok? '✅ ': '❌ ').$tool.($sum !== ''? ' · '.trunc($sum,120): '')]);
                }$progress = 'tool';
            }
        }elseif($evType === 'text') {
            $txt = (string)($part['text']?? $props['text']?? '');
            if($txt !== '') {
                if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => $txt]]]]);
                $progress = 'text';
            }
        }
    }catch(Throwable $e) {
        if(function_exists('applog'))applog('OPENCODE_CARD','emit_err',['err' => substr((string)$e->getMessage(),0,200)]);
    }
    return $progress;
}
function debz_chat_images_save(array $filesInfo,int $maxFiles = 5,int $maxBytes = 20971520): array {
    $out = [];
    if(empty($filesInfo['name']))return $out;
    foreach(['name','type','tmp_name','error','size'] as $k) {
        if(! isset($filesInfo[$k])|| ! is_array($filesInfo[$k]))$filesInfo[$k] = [$filesInfo[$k]?? null];
    }
    $mediaDir = __DIR__.'/media';
    if(! is_dir($mediaDir)&& ! @ mkdir($mediaDir,0755,true)&& ! is_dir($mediaDir))return $out;
    $extOf = ['image/jpeg' => 'jpg','image/png' => 'png','image/webp' => 'webp','image/gif' => 'gif','image/avif' => 'avif','image/bmp' => 'bmp'];
    $n = count($filesInfo['name']);
    for($i = 0; $i < $n && count($out) < $maxFiles; $i++) {
        if(($filesInfo['error'][$i]?? UPLOAD_ERR_NO_FILE)!== UPLOAD_ERR_OK)continue;
        $tmp = (string)($filesInfo['tmp_name'][$i]?? '');
        if($tmp === '' || ! is_file($tmp))continue;
        if((int)@ filesize($tmp) > $maxBytes)continue;
        $mime = (string)(@ mime_content_type($tmp)?: '');
        if(! isset($extOf[$mime]))continue;
        $hash = (string)@ sha1_file($tmp);
        if($hash === '')continue;
        $tag = substr($hash,0,12);
        $hit = glob($mediaDir.'/img_*_'.$tag.'.'.$extOf[$mime]);
        if($hit && isset($hit[0])&& is_file($hit[0])) {
            $out[]= $hit[0];
            continue;
        }
        $fname = 'img_'.date('Ymd_His').'_'.$tag.'.'.$extOf[$mime];
        $dest = $mediaDir.'/'.$fname;
        if(@ move_uploaded_file($tmp,$dest)|| @ copy($tmp,$dest))$out[]= $dest;
    }
    if(function_exists('media_prune'))media_prune($mediaDir);
    return $out;
}
function native_agent_run_opencode_cli(array $P,array $messagesIn,int $maxTokens,string $userText,string $threadId = '',bool $toolsOn = true,bool $allowSessionIn = false,array $attachFiles = []): void {
    @ set_time_limit(0);
    global $emittedAnything,$doneSent;
    if(function_exists('applog'))applog('OPENCODE_CLI','start',['model' => (string)($P['model']?? ''),'thread' => substr($threadId,0,40),'user_len' => strlen($userText),'files' => count($attachFiles)]);
    $bin = '';
    if(isset($P['extra'])&& is_array($P['extra'])&& ! empty($P['extra']['cli_bin']))$bin = (string)$P['extra']['cli_bin'];
    if($bin === '') {
        $projBin = __DIR__.'/opencode-bin/opencode';
        $bin = (is_file($projBin)&& is_executable($projBin))? $projBin: 'opencode';
    }$model = (string)($P['model']?? '');
    if($model === '' || strpos($model,'/')=== false)$model = 'opencode/'.($model === ''? 'big-pickle': $model);
    $mapFile = $threadId !== ''?(sys_get_temp_dir().'/c0n73xt_oc_sessions_'.md5($threadId).'.json'): '';
    $openSession = '';
    if($mapFile !== '' && is_file($mapFile)) {
        $j = json_decode((string)@ file_get_contents($mapFile),true);
        if(is_array($j)&& ! empty($j['sessionID']))$openSession = (string)$j['sessionID'];
    }
    if(function_exists('emit'))emit(['type' => 'status','phase' => 'thinking']);
    if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'info','line' => '🚀 opencode-cli ('.$bin.') · model '.$model.($openSession !== ''? ' · lanjut session '.substr($openSession,0,12).'…': ' · session baru').($toolsOn? ' · ⚡ tools auto': ' · tools off').(count($attachFiles)? ' · 📎 '.count($attachFiles).' file': '')]);
    $userText = trim((string)$userText);
    if($userText === '') {
        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Pesan kosong** — tidak ada yang bisa diproses.\n"]]]]);
        if(function_exists('emitDone'))emitDone();
        return;
    }$maxProxyTry = max(2,native_config_int('AI_CLI_PROXY_TRY',5));
    $cliBaseEnv = getenv();
    $cliProxy = '';
    $cliProxyEnv = [];
    $capsSession = '';
    $usageIn = 0;
    $usageOut = 0;
    $usageTotal = 0;
    $stepInMax = 0;
    $proxyTryErr = '';
    for($proxyTry = 0;
    $proxyTry < $maxProxyTry;
    $proxyTry ++) {
        $cliProxyFail = false;
        [$cliProxy,$cliProxyEnv]= debz_cli_proxy_pick();
        for($hc = 0;
        $hc < 5 && $cliProxy !== '';
        $hc ++) {
            $probeStatus = debz_proxy_probe_cached($cliProxy,3000);
            if($probeStatus === 'ok' || $probeStatus === 'timeout')break;
            if(function_exists('applog'))applog('PROXY','cli_hc_reject',['proxy' => $cliProxy,'status' => $probeStatus,'try' => $proxyTry + 1]);
            debz_proxy_failover($cliProxy,($probeStatus === 'refused')? 'dead': 'slow',($probeStatus === 'refused'));
            $GLOBALS['_debz_proxy_force_new']= true;
            [$cliProxy,$cliProxyEnv]= debz_cli_proxy_pick();
        }
        if($cliProxy === '' && ! empty($GLOBALS['_debz_cli_proxy_empty'])) {
            $GLOBALS['_debz_cli_proxy_empty']= false;
            if(function_exists('termEmit'))termEmit('limit','⚠️ Run CLI dibatalkan: pool proxy kosong, nunggu grabber tapi belum ada proxy live (no forced direct).');
            if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Run CLI di-hold**: pool proxy kosong dan proxy-grabber belum dapat proxy live. Gak ada forced direct — jalur chat sengaja ditahan. Coba lagi beberapa saat lagi ya.\n"]]]]);
            if(function_exists('emitDone'))emitDone();
            if(function_exists('applog'))applog('CLI','packet_proxy_pool_empty',[]);
            return;
        }
        // Mode direct (khas APK: ga ada pool proxy di HP). Kalau provider ga
        // reachable, CLI gantung bisu sampai deadline 900s — gagalkan cepat
        // dengan pesan yang bisa dibaca user, jangan hening.
        if($cliProxy === '') {
            $pf = debz_net_preflight((string)($P['base_url']?? ''));
            if($pf !== true) {
                if(function_exists('termEmit'))termEmit('limit','⚠️ '.$pf);
                if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **".$pf."**\n"]]]]);
                if(function_exists('emitDone'))emitDone();
                if(function_exists('applog'))applog('CLI','packet_net_preflight_fail',['err' => (string)$pf]);
                return;
            }
            if(empty($GLOBALS['_debz_direct_noticed'])) {
                $GLOBALS['_debz_direct_noticed'] = true;
                if(function_exists('termEmit'))termEmit('info','ℹ️ Mode direct (tanpa proxy) ke '.((string)($P['base_url']?? 'provider')));
            }
        }
        $env = $cliBaseEnv;
        $env['XDG_CONFIG_HOME']= __DIR__.'/opencode-bin/.cfg_home';
        $env['XDG_DATA_HOME']= __DIR__.'/opencode-bin/.data_home';
        // bun/opencode butuh HOME (uv_os_homedir) + PATH tool rootfs.
        // php-fpm (apalagi di proot APK) sering env minim -> default aman.
        // empty() = desktop tak berubah (HOME/PATH selalu ada di sana).
        if(empty($env['HOME']))$env['HOME'] = '/root';
        if(empty($env['PATH']))$env['PATH'] = '/usr/bin:/bin:/usr/sbin:/sbin';
        if($cliProxy !== '') {
            foreach($cliProxyEnv as $_k => $_v) {
                $env[$_k]= (string)$_v;
            }
            if($cliProxy !== (string)($GLOBALS['_debz_proxy_shown']?? '')) {
                $GLOBALS['_debz_proxy_shown']= $cliProxy;
                if(function_exists('termEmit'))termEmit('proxy','Routed via Proxy: '.$cliProxy);
            }
            if(function_exists('applog'))applog('PROXY','cli_routed',['proxy' => $cliProxy,'try' => $proxyTry + 1]);
        }$serveCfg = null;
        $serveJson = __DIR__.'/opencode-bin/.serve.json';
        $sj = [];
        if(is_file($serveJson)) {
            $sjLoaded = json_decode((string)@ file_get_contents($serveJson),true);
            if(is_array($sjLoaded))$sj = $sjLoaded;
        }$probePorts = ! empty($sj['port'])?[(int)$sj['port'],4096]:[4096,4097,4098];
        foreach(array_unique($probePorts)as $cand) {
            $sfp = @ fsockopen('127.0.0.1',(int)$cand,$sec,$sem,1);
            if(! is_resource($sfp))continue;
            fclose($sfp);
            $pw = (string)($sj['password']?? '');
            if($pw === '') {
                $pwFile = __DIR__.'/opencode-bin/.serve_password';
                if(is_file($pwFile))$pw = trim((string)@ file_get_contents($pwFile));
            }
            if($pw === '')break;
            $serveCfg = ['port' => (int)$cand,'url' => ! empty($sj['url'])? (string)$sj['url']: 'http://127.0.0.1:'.(int)$cand,'password' => $pw,];
            $env['OPENCODE_SERVER_PASSWORD']= $pw;
            if(! is_file($serveJson)) {
                @ file_put_contents($serveJson,json_encode($serveCfg),LOCK_EX);
                @ chmod($serveJson,0600);
                if(function_exists('applog'))applog('OPENCODE_CLI','serve_marker_rebuilt',['port' => (int)$cand,'src' => $pw !== ''? 'password_file': 'marker']);
            }break;
        }
        if($serveCfg !== null && ! $toolsOn && count($attachFiles)=== 0) {
            native_agent_run_opencode_card($P,$model,$userText,$openSession,$mapFile,$threadId,$serveCfg,$bin,$allowSessionIn,$attachFiles);
            return;
        }
        if($serveCfg !== null) {
            $cmd = [$bin,'run','--attach',(string)$serveCfg['url'],'--format','json','--no-replay','-m',$model];
        }else {
            $cmd = [$bin,'run','--format','json','--no-replay','-m',$model];
        }
        if($openSession !== '') {
            $cmd[]= '-s';
            $cmd[]= $openSession;
        }
        if($toolsOn) {
            $cmd[]= '--auto';
        }foreach($attachFiles as $af) {
            $af = (string)$af;
            if($af !== '' && is_file($af)) {
                $cmd[]= '-f';
                $cmd[]= $af;
            }
        }$cmd[]= '--';
        $cmd[]= $userText;
        $spec = [0 =>['pipe','r'],1 =>['pipe','w'],2 =>['pipe','w']];
        $pipes = [];
        $proc = @ proc_open($cmd,$spec,$pipes,null,$env);
        if(! is_resource($proc)) {
            if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Gagal menjalankan opencode CLI.**\n\n".implode(' ',array_map('\strval',$cmd))."\n"]]]]);
            if(function_exists('emitDone'))emitDone();
            return;
        }fclose($pipes[0]);
        stream_set_blocking($pipes[1],false);
        stream_set_blocking($pipes[2],false);
        $stdoutBuf = '';
        $stderrBuf = '';
        $sawContent = false;
        $textEmitted = false;
        $hadError = false;
        $deferErrTxt = '';
        $lastBeat = time();
        $t0 = time();
        $cliDeadline = $t0 + 900;
        $hardCap = $t0 + 1800;
        $thinkingSent = false;
        $lastErrMsg = '';
        $cliProxyHang = false;
        $lastEventTs = time();
        $lastProgressTs = time();
        $cliTimedOut = false;
        $hangCfg = max(15,native_config_int('AI_CLI_PROXY_HANG',25));
        $hangFirst = max($hangCfg,native_config_int('AI_CLI_PROXY_HANG_FIRST',90));
        $hangBase = $hangFirst;
        $hangNotified = false;
        $loopDetected = false;
        $clientGone = false;
        $stepCount = 0;
        $lastTextChunk = '';
        $textRepeat = 0;
        $textLoopCap = 4;
        $killLoop = function(string $why)use(& $loopDetected): void {
            if($loopDetected)return;
            $loopDetected = true;
            if(function_exists('applog'))applog('OPENCODE_CLI','loop_detected',['reason' => $why]);
        };
        $touchProgress = function()use(& $lastProgressTs,& $cliDeadline): void {
            $lastProgressTs = time();
            $cliDeadline = time()+ 900;
        };
        while(true) {
            $status = proc_get_status($proc);
            $read = [$pipes[1],$pipes[2]];
            $write = null;
            $except = null;
            $n = @ stream_select($read,$write,$except,3);
            if($n !== false && $n > 0) {
                foreach($read as $r) {
                    $chunk = @ fread($r,65536);
                    if($chunk === false || $chunk === '')continue;
                    $lastEventTs = time();
                    if($r === $pipes[1])$stdoutBuf .= $chunk;
                    elseif($r === $pipes[2])$stderrBuf .= $chunk;
                }
            }while(($nl = strpos($stdoutBuf,"\n"))!== false) {
                $line = substr($stdoutBuf,0,$nl);
                $stdoutBuf = (string)substr($stdoutBuf,$nl + 1);
                $line = trim($line);
                if($line === '')continue;
                $ev = json_decode($line,true);
                if(! is_array($ev))continue;
                $t = (string)($ev['type']?? '');
                $part = isset($ev['part'])&& is_array($ev['part'])? $ev['part']:[];
                if($capsSession === '') {
                    $sidTry = (string)($part['sessionID']?? $ev['sessionID']?? '');
                    if($sidTry !== '')$capsSession = $sidTry;
                }
                switch($t) {
                    case 'step_start': $it = (int)($part['iterations']?? 0);
                    if(! $thinkingSent) {
                        if(function_exists('emit'))emit(['type' => 'status','phase' => 'thinking','iter' => $it + 1]);
                        $thinkingSent = true;
                    }
                    if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'info','line' => '▶ Langkah '.((int)$it + 1)]);
                    if(function_exists('emit'))emit(['type' => 'step','n' =>((int)$it + 1)]);
                    $stepCount ++;
                    $touchProgress();
                    break;
                    case 'reasoning': if(isset($part['text'])&& $part['text']!== '' && function_exists('emit')) {
                        emit(['type' => 'reasoning','items' =>[['type' => 'reasoning.text','text' => (string)$part['text']]]]);
                        $touchProgress();
                    }break;
                    case 'text': $txt = (string)($part['text']?? '');
                    if($txt !== '') {
                        if($txt === $lastTextChunk) {
                            $textRepeat ++;
                        }else {
                            $lastTextChunk = $txt;
                            $textRepeat = 1;
                        }
                        if($textRepeat >= $textLoopCap) {
                            $killLoop('teks identik diulang '.$textRepeat.'x: "'.trunc($txt,40).'"');
                            $lastBeat = time();
                            @ proc_terminate($proc,9);
                            break 3;
                        }
                        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => $txt]]]]);
                        $sawContent = true;
                        $textEmitted = true;
                        $touchProgress();
                    }break;
                    case 'tool_use': $tool = (string)($part['tool']?? '');
                    $st = isset($part['state'])&& is_array($part['state'])? $part['state']:[];
                    $stStatus = (string)($st['status']?? '');
                    $detail = '';
                    if(isset($st['input'])&& is_array($st['input'])) {
                        $detailIn = $st['input'];
                        foreach(['command','file','path','query','pattern','url','name','skill','search']as $dk) {
                            if(! empty($detailIn[$dk])&& is_string($detailIn[$dk])) {
                                $detail = trunc($detailIn[$dk],64);
                                break;
                            }
                        }
                    }$toolDisp = $tool.($detail !== ''? ' · '.$detail: '');
                    $GLOBALS['_oc_tool_n']= (int)($GLOBALS['_oc_tool_n']?? 0)+ 1;
                    $ocId = 'oc_'.$GLOBALS['_oc_tool_n'];
                    if(function_exists('emit'))emit(['type' => 'tool','phase' => 'start','id' => $ocId,'name' => $tool,'detail' => $detail]);
                    if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'tool','line' => '🛠️ '.$toolDisp]);
                    $touchProgress();
                    if($stStatus === 'completed' || $stStatus === 'error') {
                        $outRaw = $st['output']?? '';
                        if(is_array($outRaw))$outRaw = json_encode($outRaw);
                        $stErr = (string)($st['error']?? '');
                        $sum = '';
                        if(function_exists('summarizeToolOutput'))$sum = summarizeToolOutput((string)$outRaw);
                        $prev = null;
                        if(function_exists('native_oc_tool_preview'))$prev = native_oc_tool_preview($tool,$st,$detail);
                        if($stStatus === 'error') {
                            $permDenied = stripos($stErr,'reject')!== false && stripos($stErr,'permission')!== false;
                            $permDenied = $permDenied || stripos($stErr,'PermissionDenied')!== false;
                            if($sum === '' && $stErr !== '')$sum = $stErr;
                            if($prev !== null && function_exists('emit'))emit(['type' => 'tool','phase' => 'preview','id' => $ocId,'name' => $tool]+ $prev);
                            if(function_exists('emit'))emit(['type' => 'tool','phase' => 'result','id' => $ocId,'name' => $tool,'summary' => $sum !== ''? $sum: $stStatus,'ok' => false]);
                            if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'error','line' => '❌ '.$tool.($sum !== ''? ' · '.trunc($sum,120): '')]);
                            if($permDenied) {
                                if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'info','line' => 'ℹ️ Tool ditolak opencode (kebijakan permission internal). Mode ini tak punya kartu approval — coba instruksi yang tidak menyentuh file/path dilarang, atau aktifkan tools auto di pengaturan.']);
                            }
                        }else {
                            if($prev !== null && function_exists('emit'))emit(['type' => 'tool','phase' => 'preview','id' => $ocId,'name' => $tool]+ $prev);
                            if(function_exists('emit'))emit(['type' => 'tool','phase' => 'result','id' => $ocId,'name' => $tool,'summary' => $sum !== ''? $sum: $stStatus,'ok' => true]);
                            if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'ok','line' => '✅ '.$tool.($sum !== ''? ' · '.trunc($sum,120): '')]);
                        }
                    }break;
                    case 'step_finish': if(isset($part['tokens'])&& is_array($part['tokens'])) {
                        $tk = $part['tokens'];
                        $usageIn += (int)($tk['input']?? 0);
                        $usageOut += (int)($tk['output']?? 0);
                        $usageTotal += (int)($tk['total']?? 0);
                        $stepIn = (int)($tk['input']?? 0);
                        if($stepIn > $stepInMax) {
                            $stepInMax = $stepIn;
                            $hangBase = min(240,$hangCfg + (int)($stepIn / 20000));
                            if($stepIn >= 150000 && ! $hangNotified && function_exists('termEmit')) {
                                $hangNotified = true;
                                termEmit('warn','ℹ️ Konteks sesi besar (≈'.number_format($stepIn).' input tokens) — proxy gratis rawan gagal nyangga payload gede, retry dipangkas.');
                            }
                        }
                    }$touchProgress();
                    break;
                    case 'error': $em = isset($ev['error'])&& is_array($ev['error'])?($ev['error']['message']?? 'opencode error'):((string)($ev['error']?? $part['error']?? 'opencode error'));
                    $lastErrMsg = (string)$em;
                    $hadError = true;
                    $touchProgress();
                    if($cliProxy === '') {
                        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **opencode error:** ".$em."\n"]]]]);
                        if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'error','line' => '❌ opencode: '.trunc((string)$em,160)]);
                        $sawContent = true;
                    }else {
                        $deferErrTxt = (string)$em;
                    }break;
                    default: break;
                }
            }
            if(! $clientGone && function_exists('connection_aborted')&& @ connection_aborted()=== 1) {
                $clientGone = true;
                @ proc_terminate($proc,9);
                if(function_exists('applog'))applog('OPENCODE_CLI','client_gone',['terminate' => true]);
                break;
            }
            if(! $status['running'])break;
            if((time()- $lastBeat)>= 20) {
                $lastBeat = time();
                native_heartbeat();
            }
            if($cliProxy !== '' && ! $cliProxyHang &&(time()- $lastProgressTs)> $hangBase) {
                $cliProxyHang = true;
                if(function_exists('termEmit'))termEmit('retry','Proxy diam/hang ('.$hangBase.' detik tanpa progres, kill + reborn). Masuk failover...');
                if(function_exists('applog'))applog('PROXY','cli_hang_timeout',['proxy' => $cliProxy,'try' => $proxyTry + 1,'stall' => time()- $lastProgressTs,'base' => $hangBase]);
                @ proc_terminate($proc);
                break;
            }
            if($cliProxy === '' && ! $cliProxyHang &&(time()- $lastProgressTs)> 240) {
                $cliProxyHang = true;
                if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **opencode hang** (240 detik tanpa progres) — proses dihentikan, coba lagi.\n"]]]]);
                if(function_exists('termEmit'))termEmit('error','Direct mode diam (240 detik tanpa progres), proses dihentikan.');
                if(function_exists('applog'))applog('OPENCODE_CLI','direct_stall',['stall' => time()- $lastProgressTs]);
                @ proc_terminate($proc,9);
                $cliTimedOut = true;
                break;
            }
            if(time()> $cliDeadline ||(time()- $t0)> $hardCap) {
                if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Timeout CLI** — proses molor tanpa progres cukup, dihentikan (kill + reborn kalau via proxy).\n"]]]]);
                @ proc_terminate($proc,9);
                $cliTimedOut = true;
                break;
            }
        }
        if($loopDetected || $clientGone) {
            if(is_resource($pipes[1])) {
                @ fclose($pipes[1]);
            }
            if(is_resource($pipes[2])) {
                @ fclose($pipes[2]);
            }
            if(is_resource($proc)) {
                @ proc_terminate($proc,9);
                @ proc_close($proc);
            }
            if($loopDetected && ! $clientGone) {
                if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Agent berhenti otomatis — output teks yang sama diulang terus** (loop verbal, langkah ".$stepCount.").\n\nTool yang dipakai berulang tidak dianggap loop — biarin aja jalan.\n"]]]]);
                if(function_exists('termEmit'))termEmit('error','Loop terdeteksi, proses CLI dihentikan.');
            }
            if(function_exists('emitDone'))emitDone();
            return;
        }$stdoutBuf .= (string)@ stream_get_contents($pipes[1]);
        if(trim($stdoutBuf)!== '') {
            $ev = json_decode(trim($stdoutBuf),true);
            if(is_array($ev)) {
                if(($ev['type']?? '')=== 'text' && ! empty($ev['part']['text'])&& function_exists('emit')) {
                    emit(['choices' =>[['delta' =>['content' => (string)$ev['part']['text']]]]]);
                    $sawContent = true;
                }
                if(($ev['type']?? '')=== 'step_finish' && isset($ev['part']['tokens'])&& is_array($ev['part']['tokens'])) {
                    $tk = $ev['part']['tokens'];
                    $usageIn += (int)($tk['input']?? 0);
                    $usageOut += (int)($tk['output']?? 0);
                    $usageTotal += (int)($tk['total']?? 0);
                }
            }
        }fclose($pipes[1]);
        $stderrLeft = (string)@ stream_get_contents($pipes[2]);
        if($stderrLeft !== '')$stderrBuf .= $stderrLeft;
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        if($mapFile !== '' && $capsSession !== '') {
            @ file_put_contents($mapFile,json_encode(['sessionID' => $capsSession,'updated' => date('c'),'model' => $model]),LOCK_EX);
        }
        if($cliProxy !== '') {
            $failSignal = ($lastErrMsg !== ''? $lastErrMsg: '')."\n".trim((string)$stderrBuf);
            if($cliProxyHang) {
                $cliProxyFail = true;
                $proxyTryErr = 'proxy hang ('.$hangBase.' detik tanpa progres)';
            }elseif($hadError) {
                $cliProxyFail = true;
            }elseif(! $textEmitted && debz_cli_is_proxy_err($failSignal)) {
                $cliProxyFail = true;
            }elseif(! $textEmitted && trim($failSignal)=== '') {
                $cliProxyFail = true;
            }
            if($cliProxyFail && $proxyTryErr === '') {
                $proxyTryErr = trim($failSignal)!== ''? trim($failSignal):('opencode exit '.$exitCode.' tanpa output');
            }
        }$effMaxTry = $maxProxyTry;
        if($stepInMax >= 250000) {
            $effMaxTry = max(2,(int)round($maxProxyTry * 300000 / $stepInMax));
            if(function_exists('applog'))applog('PROXY','cli_retry_cap',['ctx_tokens' => $stepInMax,'cap' => $effMaxTry,'full' => $maxProxyTry]);
        }
        if(! $cliTimedOut && $cliProxyFail && $proxyTry < $effMaxTry - 1) {
            $blReason = preg_match('/429|rate limit|quota/i',$proxyTryErr)? 'rl': '';
            debz_proxy_failover($cliProxy,$blReason);
            $GLOBALS['_debz_proxy_force_new']= true;
            if(function_exists('termEmit'))termEmit('fail',($blReason === 'rl'? '⚠️ Rate limit (429) lewat proxy. Blacklist 24 jam + putar proxy baru': 'Proxy error. Blacklist 24 jam + putar proxy baru').'... (coba ke-'.($proxyTry + 2).')');
            if(function_exists('applog'))applog('PROXY','cli_failover',['proxy' => $cliProxy,'reason' => $blReason,'err' => substr($proxyTryErr,0,200),'try' => $proxyTry + 1]);
            continue;
        }
        if($deferErrTxt !== '') {
            if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **opencode error:** ".$deferErrTxt."\n"]]]]);
            if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'error','line' => '❌ opencode: '.trunc($deferErrTxt,160)]);
            $lastErrMsg = $deferErrTxt;
            $deferErrTxt = '';
            $sawContent = true;
        }
        if($cliProxy !== '' && $cliProxyFail && $stepInMax >= 250000) {
            $msg = "\n\nℹ️ **Konteks sesi sangat besar (≈ ".number_format($stepInMax)." input tokens)** — payload kegedean, proxy gratis remuk/blackhole sebelum bisa balas. Pool udah otomatis di-roll. Saran:\n- Bikin **sesi baru** buat lanjut tugas ringan, atau\n- Aktifkan **mode direct** untuk sesi segede ini.\n";
            if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => $msg]]]]);
            if(function_exists('termEmit'))termEmit('info','ℹ️ Konteks sesi sangat besar (≈'.number_format($stepInMax).' tokens) — payload melebihi kapasitas proxy gratis. Bikin sesi baru / mode direct buat tugas gede.');
            $sawContent = true;
        }
        if($cliProxy !== '' && ! $cliProxyFail) {
            debz_proxy_score_record($cliProxy,true);
            debz_proxy_sticky_ok($cliProxy);
        }
        if(function_exists('debz_proxy_mark_used'))debz_proxy_mark_used($cliProxy);
        $usageOut = max($usageOut,0);
        $usageIn = max($usageIn,0);
        if($usageTotal > 0 && function_exists('emit')) {
            emit(['type' => 'usage','input_tokens' => $usageIn,'output_tokens' => $usageOut,'total_tokens' => $usageTotal]);
        }
        if(! $sawContent && ! $cliTimedOut) {
            $err = trim($stderrBuf);
            $exitTxt = $exitCode === - 1? 'signal':('exit '.$exitCode);
            $msg = "\n\n⚠️ **Stream kosong** — opencode CLI tidak menghasilkan teks (".$exitTxt.").\n";
            if($err !== '')$msg .= "\n`".substr($err,0,500)."`\n";
            if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => $msg]]]]);
            if(function_exists('applog'))applog('OPENCODE_CLI','empty/exit',['exit' => $exitCode,'stderr' => substr($err,0,300)]);
        }
        if(function_exists('emitDone'))emitDone();
        return;
    }
}
function native_extract_tc_sig(array $tc):? array {
    if(isset($tc['extra_content'])&& is_array($tc['extra_content'])) {
        $ec = $tc['extra_content'];
        if(isset($ec['google'])&& is_array($ec['google'])&& ! empty($ec['google']['thought_signature'])) {
            return['google' =>['thought_signature' => $ec['google']['thought_signature']]];
        }
        if(! empty($ec['thought_signature']))return['google' =>['thought_signature' => $ec['thought_signature']]];
    }
    if(! empty($tc['thought_signature']))return['google' =>['thought_signature' => $tc['thought_signature']]];
    if(isset($tc['function'])&& is_array($tc['function'])) {
        $f = $tc['function'];
        if(! empty($f['thought_signature']))return['google' =>['thought_signature' => $f['thought_signature']]];
        if(isset($f['extra_content'])&& is_array($f['extra_content'])&& ! empty($f['extra_content']['thought_signature'])) {
            return['google' =>['thought_signature' => $f['extra_content']['thought_signature']]];
        }
    }
    return null;
}
function native_strip_rd(array $messages): array {
    $out = [];
    foreach($messages as $m) {
        if(! is_array($m))continue;
        unset($m['reasoning_details']);
        $out[]= $m;
    }
    return $out;
}
function native_has_rd(array $messages): bool {
    foreach($messages as $m) {
        if(is_array($m)&& ! empty($m['reasoning_details'])&& is_array($m['reasoning_details']))return true;
    }
    return false;
}
function native_clean_rd(array $items): array {
    $out = [];
    foreach($items as $rd) {
        if(! is_array($rd))continue;
        $item = ['type' => (string)($rd['type']?? 'reasoning.text')];
        if(isset($rd['text'])&& is_string($rd['text'])&& $rd['text']!== '')$item['text']= $rd['text'];
        if(isset($rd['data'])&& is_string($rd['data'])&& $rd['data']!== '')$item['data']= $rd['data'];
        if(isset($rd['format'])&& is_string($rd['format'])&& $rd['format']!== '')$item['format']= $rd['format'];
        if(isset($rd['index']))$item['index']= (int)$rd['index'];
        if(isset($item['text'])|| isset($item['data']))$out[]= $item;
    }
    return $out;
}
function native_heartbeat(): void {
    if(function_exists('emit')) {
        echo ": ka\n\n";
        if(function_exists('flush'))flush();
    }
}
function native_sleep_heartbeat(int $ms): void {
    $step = 5000;
    while($ms > 0) {
        $wait = min($ms,$step);
        usleep($wait * 1000);
        $ms -= $wait;
        native_heartbeat();
    }
}
function native_tool_definitions(): array {
    return[['type' => 'function','function' =>['name' => 'shell','description' => 'Jalankan command shell di server. Untuk cek sistem, install, git, network, dsb. Output dibatasi, kalau butuh file spesifik pakai tool lain.','parameters' =>['type' => 'object','properties' =>['command' =>['type' => 'string','description' => 'Command shell lengkap'],'cwd' =>['type' => 'string','description' => 'Working directory (default ~/debz-ai)']],'required' =>['command']]]],['type' => 'function','function' =>['name' => 'read_file','description' => 'Baca isi file teks.','parameters' =>['type' => 'object','properties' =>['path' =>['type' => 'string']],'required' =>['path']]]],['type' => 'function','function' =>['name' => 'write_file','description' => 'Tulis/replace isi file (buat file baru kalau belum ada).','parameters' =>['type' => 'object','properties' =>['path' =>['type' => 'string'],'content' =>['type' => 'string']],'required' =>['path','content']]]],['type' => 'function','function' =>['name' => 'list_dir','description' => 'List isi folder.','parameters' =>['type' => 'object','properties' =>['path' =>['type' => 'string']],'required' =>['path']]]],['type' => 'function','function' =>['name' => 'search','description' => 'Cari file by nama (regex) atau isi file. Return daftar path.','parameters' =>['type' => 'object','properties' =>['pattern' =>['type' => 'string','description' => 'Regex'],'path' =>['type' => 'string','description' => 'Folder search (default ~/debz-ai)'],'content' =>['type' => 'boolean','description' => 'true = cari di isi file, false = nama file']],'required' =>['pattern']]]],['type' => 'function','function' =>['name' => 'http_request','description' => 'Fetch URL / panggil API (GET/POST/PUT/DELETE). Buat riset web, akses API luar, cek status, dsb. Body dibatasi ~256KB.','parameters' =>['type' => 'object','properties' =>['url' =>['type' => 'string'],'method' =>['type' => 'string','enum' =>['GET','POST','PUT','DELETE'],'description' => 'default GET'],'headers' =>['type' => 'object','description' => 'header tambahan (opsional)'],'body' =>['type' => 'string','description' => 'request body (opsional)'],'timeout' =>['type' => 'integer','description' => 'detik, max 60']],'required' =>['url']]]],['type' => 'function','function' =>['name' => 'download_file','description' => 'Download file dari URL ke path lokal. Buat ambil gambar, APK, dataset, dll.','parameters' =>['type' => 'object','properties' =>['url' =>['type' => 'string'],'path' =>['type' => 'string','description' => 'path tujuan HARUS kasih nama file, misal ~/path/to/file.zip'],'max_mb' =>['type' => 'integer','description' => 'batas ukuran MB, default 200']],'required' =>['url','path']]]],['type' => 'function','function' =>['name' => 'db_query','description' => 'Query SQLite (SELECT/INSERT/UPDATE/DELETE). Buat nyimpen data terstruktur, kalender, tracking, dll.','parameters' =>['type' => 'object','properties' =>['db_path' =>['type' => 'string','description' => 'path file .db/.sqlite'],'sql' =>['type' => 'string'],'params' =>['type' => 'array','description' => 'parameter query (opsional)']],'required' =>['db_path','sql']]]],['type' => 'function','function' =>['name' => 'archive','description' => 'Buat atau ekstrak arsip zip/tar/tar.gz. action create (perlu files) atau extract (perlu target_dir).','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['create','extract']],'archive_path' =>['type' => 'string'],'files' =>['type' => 'array','description' => '(create) daftar path file/folder'],'target_dir' =>['type' => 'string','description' => '(extract) folder tujuan']],'required' =>['action','archive_path']]]],['type' => 'function','function' =>['name' => 'process_list','description' => 'List proses yang berjalan. Bisa difilter pakai pattern (nama/pid).','parameters' =>['type' => 'object','properties' =>['pattern' =>['type' => 'string','description' => 'filter substring (opsional)']]]]],['type' => 'function','function' =>['name' => 'process_kill','description' => 'Kill proses by pid atau nama (pattern). signal default 15 (SIGTERM), pilihan 1/2/9/15.','parameters' =>['type' => 'object','properties' =>['pid' =>['type' => 'integer'],'pattern' =>['type' => 'string'],'signal' =>['type' => 'integer']]]]],['type' => 'function','function' =>['name' => 'note','description' => 'Memori persisten AI ke notes.db. action: list/get/add/delete/search. Buat nyimpen fakta/keputusan antar sesi.','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['list','get','add','delete','search']],'key' =>['type' => 'string','description' => 'nama unik note (buat add/get/delete)'],'content' =>['type' => 'string','description' => 'isi note (buat add)'],'pattern' =>['type' => 'string','description' => 'keyword cari (buat search)']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'skill','description' => 'Kelola knowledge base skills (folder skills/): list, search by keyword, get isi SKILL.md, create skill baru dari prosedur reusable, delete, stats. Pake create buat nyimpen prosedur/learning yang bisa dipake lagi di tugas serupa.','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['list','search','get','create','delete','stats']],'name' =>['type' => 'string','description' => 'nama skill (buat get/create/delete)'],'category' =>['type' => 'string','description' => 'kategori folder (buat create, default custom)'],'content' =>['type' => 'string','description' => 'isi SKILL.md lengkap (buat create)'],'pattern' =>['type' => 'string','description' => 'keyword cari (buat search)']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'app_install','description' => 'Manajemen package via apk/pkg: search / install / remove / update / installed. Buat nambahin tools ke sistem.','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['search','install','remove','update','installed']],'package' =>['type' => 'string','description' => 'nama paket (buat search/install/remove)']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'computer_use','description' => 'Kendali komputer via layar virtual (CUA driver): lihat screenshot, klik, ketik, scroll, drag, buka URL di browser. Gunakan screenshot dulu buat liat layar, tentuin koordinat, baru aksi.','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['status','screenshot','open','launch','click','dblclick','rightclick','move','drag','type','key','scroll','meta'],'description' => 'aksi yang mau dijalankan; screenshot buat liat layar dulu'],'url' =>['type' => 'string','description' => '(open) URL yang dibuka di browser virtual'],'cmd' =>['type' => 'string','description' => '(launch) command program di layar virtual'],'x' =>['type' => 'integer','description' => '(click/dblclick/rightclick/move) koordinat X'],'y' =>['type' => 'integer','description' => '(click/dblclick/rightclick/move) koordinat Y'],'button' =>['type' => 'integer','description' => '(click/drag) 1=kiri, 2=tengah, 3=kanan; default 1'],'x1' =>['type' => 'integer','description' => '(drag) X awal'],'y1' =>['type' => 'integer','description' => '(drag) Y awal'],'x2' =>['type' => 'integer','description' => '(drag) X akhir'],'y2' =>['type' => 'integer','description' => '(drag) Y akhir'],'duration' =>['type' => 'number','description' => '(drag) durasi detik, default 0.3'],'text' =>['type' => 'string','description' => '(type) teks yang diketik'],'key' =>['type' => 'string','description' => '(key) hotkey, contoh: ctrl+c, Return, alt+Tab'],'dx' =>['type' => 'integer','description' => '(scroll) horizontal, negatif=kanan'],'dy' =>['type' => 'integer','description' => '(scroll) 1=turun 0, -1=naik; default 1'],'times' =>['type' => 'integer','description' => '(scroll) berapa kali scroll, max 20']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'browser','description' => 'Browser automation Playwright + Chromium headless (daemon persistent port 9333): buka URL, baca konten, klik elemen, isi form, screenshot, jalanin JS. Cocok buat test frontend & ambil data halaman. Command: goto|content|text|title|screenshot|click|type|press|wait|eval|close. State browser kebawa antar step (daemon), jadi bisa buka -> klik -> isi -> cek -> screenshot berurutan.','parameters' =>['type' => 'object','properties' =>['command' =>['type' => 'string','enum' =>['goto','content','text','title','screenshot','click','type','press','wait','eval','close'],'description' => 'aksi yang mau dijalankan'],'url' =>['type' => 'string','description' => '(goto) URL tujuan, contoh https://example.com'],'selector' =>['type' => 'string','description' => '(click/type) CSS selector elemen, contoh button.login atau input#search'],'text' =>['type' => 'string','description' => '(type) teks untuk diisi ke input'],'key' =>['type' => 'string','description' => '(press) tombol, contoh Enter / Tab / Backspace'],'path' =>['type' => 'string','description' => '(screenshot) path PNG tujuan, default ~/Workspaces/screenshots/browser_shot.png'],'js' =>['type' => 'string','description' => '(eval) kode JavaScript yang dijalankan di halaman'],'ms' =>['type' => 'integer','description' => '(wait) jeda milidetik, default 1000'],'index' =>['type' => 'integer','description' => '(click) index elemen kalau selector match banyak, default 0']],'required' =>['command']]]],['type' => 'function','function' =>['name' => 'web_search','description' => 'Cari informasi di web (mesin DuckDuckGo). Buat riset, verifikasi fakta, cek berita, cari dokumentasi. Return daftar judul+URL+snippet.','parameters' =>['type' => 'object','properties' =>['query' =>['type' => 'string','description' => 'kata kunci pencarian'],'max_results' =>['type' => 'integer','description' => 'jumlah hasil, 1-15, default 6'],'timeout' =>['type' => 'integer','description' => 'timeout detik, 5-40, default 15']],'required' =>['query']]]],['type' => 'function','function' =>['name' => 'backup','description' => 'Backup & restore data Debz AI (notes.db, providers, config, skills, dll). action: create (buat backup baru), list (daftar backup), restore (pulihkan dari backup), delete (hapus backup).','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['create','list','restore','delete'],'description' => 'aksi yang mau dijalankan'],'name' =>['type' => 'string','description' => 'nama backup (buat create/restore/delete), opsional utk create']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'scheduler','description' => 'Kelola job terjadwal di server: list (lihat semua job), add (tambah job baru dengan schedule interval:DETIK atau cron 5-field), remove (hapus job by id), toggle (aktif/nonaktif), run (jalankan job sekarang).','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['list','add','remove','toggle','run'],'description' => 'aksi yang mau dijalankan'],'command' =>['type' => 'string','description' => '(add) command shell yang dijalankan'],'schedule' =>['type' => 'string','description' => '(add) jadwal: interval:DETIK atau cron 5-field (min hour dom month dow)'],'name' =>['type' => 'string','description' => '(add) nama job (opsional)'],'id' =>['type' => 'string','description' => '(remove/toggle/run) id job'],'timeout' =>['type' => 'integer','description' => '(add) timeout detik, 5-600, default 120']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'rag_query','description' => 'Cari di knowledge base via embedding semantic (RAG). Actions: status (cek isi KB), ingest (tambah dokumen, path wajib di bawah /opt/rag/docs), query (cari chunk relevan, return teks + skor cosine). Pakai saat user tanya hal yang ada di dokumen/knowledge base internal.','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['status','ingest','query']],'text' =>['type' => 'string','description' => 'pertanyaan/teks (wajib utk query/embed)'],'path' =>['type' => 'string','description' => 'path dokumen/folder utk ingest (di bawah ~/Workspaces/rag_docs)'],'top' =>['type' => 'integer','description' => 'jumlah hasil, default 5']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'screenshot','description' => 'Ambil screenshot layar virtual (CUA/Xvfb). Return path + base64 + dimensi. Cocok buat lihat kondisi GUI sebelum aksi computer_use.','parameters' =>['type' => 'object','properties' => (object)[]]]],];
}
function native_tool_endpoint(string $name):? string {
    static $map = ['shell' => 'exec','read_file' => 'fs_read','write_file' => 'fs_write','list_dir' => 'fs_list','search' => 'fs_search','http_request' => 'http','download_file' => 'download','db_query' => 'db','archive' => 'archive','process_list' => 'ps','process_kill' => 'kill','note' => 'note','skill' => 'skill','app_install' => 'pkg','computer_use' => 'cua','browser' => 'browser','web_search' => 'web_search','backup' => 'backup','scheduler' => 'scheduler','screenshot' => 'screenshot','rag_query' => 'rag',];
    return $map[$name]?? null;
}
function native_tools_token(): string {
    static $token = null;
    if($token !== null)return $token;
    $token = '';
    $f = __DIR__.'/.ai-config.ini';
    if(! is_file($f)|| ! is_readable($f))return $token;
    foreach((array)@ file($f,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)as $line) {
        $line = trim((string)$line);
        if(stripos($line,'AI_TOOLS_TOKEN')!== 0)continue;
        $p = strpos($line,'=');
        if($p === false)continue;
        $token = trim(substr($line,$p + 1));
        break;
    }
    return $token;
}
function native_call_tool(string $endpoint,array $args,& $approvalInfo = null): array {
    $base = 'http://127.0.0.1:9191/api/'.ltrim($endpoint,'/');
    $token = native_tools_token();
    $payload = array_merge(['token' => $token],$args);
    $encoded = json_encode($payload,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if($encoded === false)return[false,['error' => 'tool payload JSON encode gagal: '.json_last_error_msg()]];
    $ch = curl_init($base);
    if($ch === false)return[false,['error' => 'gagal inisialisasi cURL tool']];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER => true,CURLOPT_POST => true,CURLOPT_HTTPHEADER =>['Content-Type: application/json','Accept: application/json','Connection: keep-alive'],CURLOPT_POSTFIELDS => $encoded,CURLOPT_CONNECTTIMEOUT => 30,CURLOPT_TIMEOUT => 300,CURLOPT_SSL_VERIFYPEER => false,CURLOPT_SSL_VERIFYHOST => 0,CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if($raw === false)return[false,['error' => 'tool server unreachable: '.($err !== ''? $err: 'cURL errno '.$errno)]];
    $raw = (string)$raw;
    $data = json_decode($raw,true);
    if(! is_array($data)) {
        return[false,['error' => 'tool server response invalid: '.native_trunc(trim($raw),300),'http_code' => $http]];
    }
    if($http === 202 && ! empty($data['need_approval'])) {
        $approvalInfo = $data;
        return[false,$data];
    }
    if($http >= 400)return[false,$data +['http_code' => $http]];
    return[true,$data];
}
function native_load_rules(): string {
    $f = __DIR__.'/AGENTS.md';
    if(! is_file($f))return '';
    $rules = file_get_contents($f);
    if($rules === false || trim($rules)=== '')return '';
    return "\n\n===== RULES WAJIB (baca & patuhi, dari AGENTS.md) =====\n".trim($rules)."\n";
}
function native_context_cap(array $messages): array {
    $cap = max(2,native_config_int('AI_CONTEXT_CAP',8));
    $n = count($messages);
    if($n <= $cap)return $messages;
    $head = array_slice($messages,0,1);
    $tail = array_slice($messages,$n -($cap - 1));
    $dropped = array_slice($messages,1,$n - $cap);
    $summary = native_summarize_dropped($dropped);
    if($summary !== '') {
        $head[0]['content'].= "\n\n[SYSTEM: Ringkasan konteks lama (offloaded ke notes.db):]\n".$summary;
    }
    return array_merge($head,$tail);
}
function native_summarize_dropped(array $dropped): string {
    if(empty($dropped))return '';
    $users = [];
    $tools = [];
    foreach($dropped as $m) {
        if(! is_array($m))continue;
        $role = (string)($m['role']?? '');
        $content = isset($m['content'])&& is_string($m['content'])? trim($m['content']): '';
        if($content === '')continue;
        if($role === 'user')$users[]= native_trunc($content,200);
        elseif($role === 'tool')$tools[]= '[tool] '.native_trunc($content,120);
        elseif($role === 'assistant')$tools[]= '[asisten] '.native_trunc($content,120);
    }$parts = [];
    if(! empty($users))$parts[]= 'User: '.implode(' | ',array_slice($users,- 3));
    if(! empty($tools))$parts[]= 'Tool: '.implode(' | ',array_slice($tools,- 4));
    $summary = implode("\n",$parts);
    if(strlen($summary)< 10)return '';
    $key = 'ctx-'.substr(hash('sha256',$summary),0,12);
    native_note_upsert($key,$summary);
    return $summary;
}
function native_note_upsert(string $key,string $content): void {
    try {
        $db = new PDO('sqlite:'.__DIR__.'/notes.db');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $st = $db->prepare("INSERT INTO notes (key, content, updated_at) VALUES (?, ?, datetime('now')) ON CONFLICT(key) DO UPDATE SET content = excluded.content, updated_at = datetime('now')");
        $st->execute([$key,$content]);
    }catch(Throwable $e) {
    }
}
function native_strike_key(string $toolName,array $args): string {
    $norm = json_encode($args,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $toolName.':'.hash('sha256',(string)$norm);
}
function native_strike_state_file(): string {
    return __DIR__.'/.strikes.json';
}
function native_strike_load(): array {
    $f = native_strike_state_file();
    if(! is_file($f)|| ! is_readable($f))return[];
    $raw = @ file_get_contents($f);
    if(empty($raw))return[];
    $j = json_decode($raw,true);
    return is_array($j)? $j:[];
}
function native_strike_save(array $st): void {
    $now = time();
    foreach($st as $k => $e) {
        if($now - (int)($e['ts']?? 0)> 3600)unset($st[$k]);
    }@ file_put_contents(native_strike_state_file(),json_encode($st,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),LOCK_EX);
}
function native_strike_limit(): int {
    return max(1,native_config_int('AI_STRIKE_LIMIT',3));
}
function native_strike_check(string $key): string {
    if($key === '')return '';
    $st = native_strike_load();
    $cnt = (int)($st[$key]['count']?? 0);
    if($cnt >= native_strike_limit()) {
        return '[SYSTEM: Tool gagal '.$cnt.'x berturut-turut. DILARANG mencoba cara yang sama. Ganti strategi atau hentikan tugas ini.]';
    }
    return '';
}
function native_strike_track(string $key): void {
    if($key === '')return;
    $st = native_strike_load();
    $st[$key]['count']= (int)($st[$key]['count']?? 0)+ 1;
    $st[$key]['ts']= time();
    native_strike_save($st);
    native_stats_record('strike_track',['key' => $key,'count' => $st[$key]['count']]);
}
function native_strike_reset(string $key): void {
    if($key === '')return;
    $st = native_strike_load();
    if(isset($st[$key])) {
        unset($st[$key]);
        native_strike_save($st);
        native_stats_record('strike_reset',['key' => $key]);
    }
}
function native_prompt_db(): PDO {
    static $db = null;
    if($db === null) {
        $db = new PDO('sqlite:'.__DIR__.'/notes.db');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    }
    return $db;
}
function native_prompt_manager_enabled(): bool {
    return native_config_int('AI_PROMPT_MANAGER',1)=== 1;
}
function native_prompt_est_tokens(string $s): int {
    return max(1,(int)ceil((function_exists('mb_strlen')? mb_strlen($s): strlen($s))/ 4));
}
function native_prompt_rules_ensure(): void {
    try {
        $db = native_prompt_db();
        $db->exec("CREATE TABLE IF NOT EXISTS prompt_rules (
            rule_id TEXT PRIMARY KEY,
            category TEXT NOT NULL,
            content_teks TEXT NOT NULL,
            priority INTEGER NOT NULL DEFAULT 100,
            last_used_timestamp INTEGER NOT NULL DEFAULT 0,
            use_count INTEGER NOT NULL DEFAULT 0
        )");
        $n = (int)$db->query("SELECT COUNT(*) FROM prompt_rules")->fetchColumn();
        if($n > 0)return;
        $seed = [['bahasa_gaul','bahasa_gaul','[SYSTEM: BALAS DENGAN BAHASA INDONESIA GAUL, SANTAI, & AKRAB — kayak ngobrol sama temen. Istilah kekinian (gas, mantap, gacor) wajar, tapi TETAP padat & to the point.]',100],['zero_fluff','zero_fluff','[SYSTEM: Tanpa basa-basi bertele-tele. Gak perlu pembuka "Tentu..." atau penutup "Semoga membantu!" — langsung ke inti teknis.]',99],['ecosystem_best_practice','ecosystem_best_practice','[SYSTEM: Terapkan ecosystem-specific best practices sesuai bahasa yang diminta (PEP8/ESM/idiomatic Go/ownership Rust/SOLID/dll).]',90],['structure','structure','[SYSTEM: Struktur respons WAJIB: ANALISIS SINGKAT (2-3 kalimat) → BLOK KODE production-ready → REKOMENDASI LANJUTAN (Big-O/keamanan/optimasi).]',80],['critical_review','critical_review','[SYSTEM: Jika kode user suboptimal/rentan bug, koreksi langsung + tunjukkan letak kesalahan + solusi alternatif yang lebih efisien.]',70],['memory_offload','memory_offload','[SYSTEM: Jangan mengingat log eksekusi panjang di context. Simpan temuan penting ke notes/database. Jika butuh data lama, gunakan tool note atau rag_query.]',60],['tool_honesty','tool_honesty','[SYSTEM: Jangan mengarang output tool. Verifikasi sebelum menyimpulkan. Jika bukti tidak cukup, katakan apa yang belum terverifikasi.]',50],];
        $st = $db->prepare("INSERT INTO prompt_rules (rule_id, category, content_teks, priority, last_used_timestamp, use_count) VALUES (?, ?, ?, ?, 0, 0)");
        foreach($seed as $r)$st->execute([$r[0],$r[1],$r[2],$r[3]]);
    }catch(Throwable $e) {
    }
}
function native_prompt_rules_select(string $userText,int $budgetTokens): array {
    native_prompt_rules_ensure();
    try {
        $db = native_prompt_db();
        $rows = $db->query("SELECT * FROM prompt_rules ORDER BY priority DESC, last_used_timestamp ASC")->fetchAll();
    }catch(Throwable $e) {
        return[];
    }
    if(empty($rows))return[];
    $kw = strtolower($userText);
    $selected = [];
    $usedTokens = 0;
    foreach($rows as $r) {
        $cat = (string)$r['category'];
        $relevant = false;
        if($cat === 'bahasa_gaul' || $cat === 'zero_fluff' || $cat === 'memory_offload') {
            $relevant = true;
        }elseif($cat === 'ecosystem_best_practice') {
            $relevant = preg_match('/\b(php|javascript|typescript|js|ts|python|go|rust|java|c#|c\+\+|c|ruby|sql|html|css)\b/i',$kw)=== 1;
        }elseif($cat === 'structure') {
            $relevant = preg_match('/\b(kode|code|script|fungsi|function|implement|buat|perbaiki|fix|debug|error|bug)\b/i',$kw)=== 1;
        }elseif($cat === 'critical_review') {
            $relevant = preg_match('/\b(review|koreksi|cek|analisa|analisis|perbaiki|fix|debug|error|bug|optimal)\b/i',$kw)=== 1;
        }elseif($cat === 'tool_honesty') {
            $relevant = preg_match('/\b(tool|shell|file|server|test|jalankan|install|browser|web|exec)\b/i',$kw)=== 1;
        }
        if(! $relevant)continue;
        $tokens = native_prompt_est_tokens((string)$r['content_teks']);
        if($usedTokens + $tokens > $budgetTokens)continue;
        $selected[]= $r;
        $usedTokens += $tokens;
    }
    return $selected;
}
function native_prompt_rules_touch(array $rules): void {
    if(empty($rules))return;
    try {
        $db = native_prompt_db();
        $now = time();
        $st = $db->prepare("UPDATE prompt_rules SET last_used_timestamp = ?, use_count = use_count + 1, priority = MAX(10, priority - 5) WHERE rule_id = ?");
        foreach($rules as $r)$st->execute([$now,(string)$r['rule_id']]);
        $db->exec("UPDATE prompt_rules SET priority = priority + 5 WHERE last_used_timestamp < ".($now - 86400)." AND priority < 200");
    }catch(Throwable $e) {
    }
}
function native_dynamic_rules(string $userText): string {
    if(! native_prompt_manager_enabled())return native_dynamic_rules_legacy($userText);
    $budget = max(100,native_config_int('AI_PROMPT_BUDGET',600));
    $rules = native_prompt_rules_select($userText,$budget);
    if(empty($rules)) {
        native_stats_record('prompt_skip',['reason' => 'no_rules']);
        return '';
    }$block = '';
    foreach($rules as $r)$block .= (string)$r['content_teks']."\n";
    native_prompt_rules_touch($rules);
    native_stats_record('prompt_inject',['rules' => count($rules),'tokens' => native_prompt_est_tokens($block)]);
    return trim($block);
}
function native_dynamic_rules_legacy(string $userText): string {
    $rules = [];
    $rules[]= '[SYSTEM: BALAS DENGAN BAHASA INDONESIA GAUL, SANTAI, & AKRAB — kayak ngobrol sama temen sesama developer. Padat & to the point, tanpa basa-basi.]';
    $rules[]= '[SYSTEM: Jangan mengingat log eksekusi panjang di context. Simpan temuan penting ke notes/database. Jika butuh data lama, gunakan tool note atau rag_query.]';
    if(preg_match('/\b(php|javascript|typescript|js|ts|python|go|rust|java|c#|c\+\+|c|ruby|sql|html|css)\b/i',$userText)) {
        $rules[]= '[SYSTEM: Terapkan ecosystem-specific best practices untuk bahasa yang diminta user (PEP8/ESM/idiomatic Go/ownership Rust/SOLID/dll).]';
    }
    return implode("\n",$rules);
}
function native_inject_skills(string $userText,bool $toolsOn): string {
    if(! $toolsOn || trim($userText)=== '')return '';
    $query = native_trunc(preg_replace('/\s+/u',' ',trim($userText))?? trim($userText),1200);
    list($ok,$data)= native_call_tool('skill',['action' => 'search','pattern' => $query]);
    if(! $ok || empty($data['results'])|| ! is_array($data['results']))return '';
    $block = "\n\n===== SKILL RELEVAN =====\n";
    $count = 0;
    foreach($data['results']as $s) {
        if(! is_array($s))continue;
        $category = trim((string)($s['category']?? 'custom'));
        $name = trim((string)($s['name']?? ''));
        $description = trim((string)($s['description']?? ''));
        if($name === '')continue;
        $block .= "• [".$category."/".$name."] ".$description."\n";
        if(++ $count >= 5)break;
    }
    if($count === 0)return '';
    $block .= "Gunakan skill hanya jika relevan. Ambil detail dengan skill action=get jika diperlukan.\n";
    return $block;
}
function native_normalize_tool_arguments(string $raw): string {
    $raw = trim($raw);
    if($raw === '')return '{}';
    $decoded = json_decode($raw,true);
    if(is_array($decoded)) {
        $encoded = json_encode($decoded,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false? $encoded: '{}';
    }$candidate = $raw;
    $candidate = preg_replace('/^\s*```(?:json)?\s*/i','',$candidate)?? $candidate;
    $candidate = preg_replace('/\s*```\s*$/','',$candidate)?? $candidate;
    $candidate = trim($candidate);
    $decoded = json_decode($candidate,true);
    if(is_array($decoded)) {
        $encoded = json_encode($decoded,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false? $encoded: '{}';
    }$candidate = preg_replace('/,\s*([}\]])/','$1',$candidate)?? $candidate;
    $decoded = json_decode($candidate,true);
    if(is_array($decoded)) {
        $encoded = json_encode($decoded,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false? $encoded: '{}';
    }
    return '';
}
function native_tool_arg_summary(string $name,array $args): string {
    switch($name) {
        case 'shell': return isset($args['command'])? native_trunc((string)$args['command'],64): '';
        case 'read_file': case 'write_file': case 'list_dir': return isset($args['path'])? native_trunc((string)$args['path'],64): '';
        case 'search': return isset($args['pattern'])? native_trunc((string)$args['pattern'],64): '';
        case 'http_request': return isset($args['url'])? native_trunc((string)$args['url'],64): '';
        case 'download_file': return(isset($args['url'])? native_trunc((string)$args['url'],40): '').' -> '.(isset($args['path'])? native_trunc((string)$args['path'],40): '');
        case 'db_query': return isset($args['sql'])? native_trunc((string)$args['sql'],64): '';
        case 'archive': return($args['action']?? '').' '.((string)($args['archive_path']?? ''));
        case 'process_list': return isset($args['pattern'])? 'filter: '.(string)$args['pattern']: 'semua';
        case 'process_kill': return isset($args['pid'])?('pid '.$args['pid']):('pattern '.(string)($args['pattern']?? ''));
        case 'skill': return($args['action']?? '').(isset($args['name'])? ' '.(string)$args['name']:(isset($args['pattern'])? ' '.native_trunc((string)$args['pattern'],40): ''));
        case 'note': return($args['action']?? '').(isset($args['key'])? ' '.(string)$args['key']: '');
        case 'app_install': return($args['action']?? '').(isset($args['package'])? ' '.(string)$args['package']: '');
        case 'computer_use': return($args['action']?? '').(isset($args['x'])&& isset($args['y'])? ' @'.$args['x'].','.$args['y']:(isset($args['text'])? ' '.native_trunc((string)$args['text'],30):(isset($args['url'])? ' '.native_trunc((string)$args['url'],40):(isset($args['key'])? ' '.(string)$args['key']: ''))));
        case 'browser': return($args['command']?? '').(isset($args['url'])? ' '.(string)$args['url']:(isset($args['selector'])? ' '.(string)$args['selector']: ''));
        case 'web_search': return isset($args['query'])? native_trunc((string)$args['query'],64): '';
        case 'backup': return($args['action']?? '').(isset($args['name'])? ' '.(string)$args['name']: '');
        case 'scheduler': return($args['action']?? '').(isset($args['name'])? ' '.(string)$args['name']:(isset($args['id'])? ' '.(string)$args['id']: ''));
        case 'rag_query': return($args['action']?? '').(isset($args['text'])? ' '.native_trunc((string)$args['text'],50):(isset($args['path'])? ' '.native_trunc((string)$args['path'],50): ''));
        case 'screenshot': return '';
    }
    return '';
}
function native_rag_docs_dir(): string {
    $cands = ['/data/delinux/opt/rag/docs','/home/debz/Workspaces/rag_docs',];
    foreach($cands as $c) {
        if(is_dir($c)&& is_writable($c))return $c;
    }
    foreach($cands as $c) {
        if(! @ mkdir($c,0777,true)&& ! is_dir($c))continue;
        if(is_writable($c))return $c;
    }
    return '';
}
function native_rag_capture(string $question,string $answer): void {
    $q = trim($question);
    $a = trim($answer);
    if(strlen($q)< 15 || strlen($a)< 30)return;
    if(preg_match('/^(hai|halo|ok|tes|oke|gass|lanjut|yo|yas|yoi)\s*$/i',$q))return;
    if(preg_match('/^⚠️\s*(Provider error|Error)/i',$a))return;
    $base = native_rag_docs_dir();
    if($base === '')return;
    $dir = $base.'/conversations';
    if(! is_dir($dir)&& ! @ mkdir($dir,0777,true))return;
    $ts = date('Y-m-d-His');
    $hash = substr(md5($q),0,8);
    $file ="
    {
        $dir
    }
    /qa-
    {
        $ts
    }
    -
    {
        $hash
    }
    .md ";
    $content = "# Q&A Auto-Captured\n\n";
    $content .=" **Tanya:**
    {
        $q
    }
    \n\n ";
    $content .=" **Jawab:**\n\n
    {
        $a
    }
    \n ";
    @ file_put_contents($file,$content);
}
function native_trunc(string $s,int $n): string {
    $n = max(0,$n);
    if($n === 0)return '';
    if(function_exists('mb_strlen')&& function_exists('mb_substr')) {
        return mb_strlen($s)> $n? mb_substr($s,0,$n).'…': $s;
    }
    return strlen($s)> $n? substr($s,0,$n).'…': $s;
}
function native_tool_result_summary(string $name,$out): string {
    if(! is_array($out))return 'ok';
    if(isset($out['error']))return 'error: '.native_trunc((string)$out['error'],60);
    if(isset($out['exit_code']))return 'exit '.$out['exit_code'];
    if(isset($out['total_count']))return $out['total_count'].' entri';
    if(isset($out['bytes_written']))return $out['bytes_written'].' bytes';
    if(isset($out['path'])&& isset($out['ok']))return 'saved '.$out['path'];
    if(isset($out['deleted']))return 'deleted '.$out['deleted'];
    if(isset($out['skills']))return count($out['skills']).' skill';
    if(isset($out['results'])&& isset($out['count'])&& isset($out['total']))return $out['count'].' skill';
    if(isset($out['bytes']))return $out['bytes'].' bytes';
    if(isset($out['truncated']))return($out['truncated']? '≥': '').count($out['results']??[]).' hasil';
    if(isset($out['http_code']))return 'HTTP '.$out['http_code'];
    if(isset($out['count']))return $out['count'].' baris';
    if(isset($out['affected']))return $out['affected'].' baris diubah';
    if(isset($out['killed']))return count($out['killed']).' proses';
    if(isset($out['notes']))return count($out['notes']).' note';
    if(isset($out['processes']))return $out['total'].' proses';
    if(isset($out['base64']))return 'screenshot '.($out['width']?? '?').'x'.($out['height']?? '?');
    if(isset($out['engine']))return $out['count'].' hasil (web_search)';
    if(isset($out['backups']))return count($out['backups']).' backup';
    if(isset($out['backup']))return 'backup '.$out['backup'];
    if(isset($out['jobs']))return count($out['jobs']).' job';
    if(isset($out['job']))return 'job '.($out['job']['id']?? '?');
    if(isset($out['scheduler_running']))return($out['scheduler_running']? 'running': 'stopped');
    return 'ok';
}
function native_home_expand(string $path): string {
    $path = trim((string)$path);
    if($path === '')return $path;
    if($path === '~')return '/home/debz';
    if(strncmp($path,'~/',2)=== 0)return '/home/debz/'.substr($path,2);
    return $path;
}
function native_tool_result_preview(string $toolName,array $args,$out):? array {
    if(! is_array($out)|| isset($out['error']))return null;
    $out['content']??= '';
    $out['stdout']??= '';
    $out['stderr']??= '';
    switch($toolName) {
        case 'read_file': case 'read': $c = (string)$out['content'];
        if($c === '')return null;
        $c = native_oc_strip_annotations($c);
        if($c === '')return null;
        return['kind' => 'read','path' => native_trunc(basename((string)($args['path']?? '?')),48),'text' => native_trunc($c,12000)];
        case 'shell': $body = (string)$out['stdout'];
        $stderr = (string)$out['stderr'];
        if($stderr !== '')$body .= ($body !== ''? "\n[stderr]\n": '').native_trunc($stderr,2000);
        if(trim($body)=== '')return null;
        return['kind' => 'exec','path' => native_trunc(preg_replace('/\s+/',' ',(string)($args['command']?? '?')),40),'text' => native_trunc($body,15000)];
        case 'search': $results = $out['results']?? null;
        if(! is_array($results))return null;
        $lines = [];
        foreach($results as $m) {
            if(! is_array($m))continue;
            $lines[]= (string)($m['path']?? '?').(isset($m['match'])? ' ['.$m['match'].']': '');
        }
        if(! $lines)return null;
        return['kind' => 'search','path' => 'file: '.native_trunc((string)($args['pattern']?? '?'),40),'text' => native_trunc(implode("\n",$lines),8000)];
        case 'web_search': $results = $out['results']?? null;
        if(! is_array($results))return null;
        $lines = [];
        foreach($results as $m) {
            if(! is_array($m))continue;
            $t = (string)($m['title']?? '');
            $u = (string)($m['url']?? '');
            $s = (string)($m['snippet']?? '');
            if($t !== '')$lines[]= $t;
            if($u !== '')$lines[]= '  '.$u;
            if($s !== '')$lines[]= '  '.native_trunc($s,160);
        }
        if(! $lines)return null;
        return['kind' => 'search','path' => 'web: '.native_trunc((string)($args['query']?? '?'),40),'text' => native_trunc(implode("\n",$lines),8000)];
        case 'list_dir': $entries = $out['entries']?? null;
        if(! is_array($entries))return null;
        $lines = [];
        foreach($entries as $e) {
            if(! is_array($e))continue;
            $lines[]= ($e['dir']?? false? 'd': '-').'  '.(string)($e['name']?? '?').(isset($e['size'])&& $e['size']!== null? '  '.$e['size'].'b': '');
        }
        if(! $lines)return null;
        return['kind' => 'list','path' => native_trunc((string)($args['path']?? '/'),40),'text' => native_trunc(implode("\n",$lines),8000)];
    }
    return null;
}
function native_write_file_preview(array $args):? array {
    $p = (string)($args['path']?? '');
    if($p === '')return null;
    $new = (string)($args['content']?? '');
    $abs = native_home_expand($p);
    $old = is_file($abs)? (string)@ file_get_contents($abs): '';
    if($old === $new)return null;
    return['kind' => 'write','path' => native_trunc(basename($p),48),'old' => native_trunc($old,12000),'new' => native_trunc($new,12000)];
}
function native_oc_strip_annotations(string $t): string {
    $t = str_replace("\r\n","\n",$t);
    $t = preg_replace('/(?m)^[ \t]*<(?:path|type|content|file|summary|line)\b[^>]*>[^<\r\n]*<\/(?:path|type|content|file|summary|line)>[ \t]*$/i','',$t)?? $t;
    $t = preg_replace('/(?m)^[ \t]*<\/?(?:path|type|content|file|summary|line)\b[^>]*>[ \t]*$/i','',$t)?? $t;
    $lines = explode("\n",$t);
    $num = 0;
    $all = 0;
    foreach($lines as $l) {
        $s = trim($l);
        if($s === '')continue;
        $all ++;
        if(preg_match('/^\d+:[ \t]+/',$s))$num ++;
    }
    if($all > 0 && $num >= $all * 0.8) {
        $t = preg_replace('/^(\s*)\d+:[\t ]/m','$1',$t)?? $t;
    }
    return trim($t,"\n");
}
function native_oc_tool_preview(string $tool,array $st,string $detail):? array {
    $out = $st['output']?? $st['outputText']?? '';
    if(is_array($out)) {
        $parts = [];
        array_walk_recursive($out,function($v)use(& $parts) {
            if(is_string($v))$parts[]= $v;
        });
        $out = implode("\n",$parts);
    }$out = (string)$out;
    $inp = isset($st['input'])&& is_array($st['input'])? $st['input']:[];
    if(in_array($tool,['edit','write','patch','apply_patch','multiedit','write_file','edit_file','update_file'],true)) {
        if(isset($st['metadata'])&& is_array($st['metadata'])) {
            foreach(['diff','patch','unified','filediff']as $k) {
                $v = $st['metadata'][$k]?? '';
                if(is_string($v)&& trim($v)!== '') {
                    return['kind' => 'diff','path' => native_trunc(basename((string)($inp['filePath']?? $inp['file']?? $inp['path']?? '?')),48),'text' => native_trunc($v,12000)];
                }
            }
        }
        if($tool === 'write') {
            $p = (string)($inp['path']?? '');
            $newc = (string)($inp['content']?? '');
            if($p !== '' && $newc !== '') {
                $abs = native_home_expand($p);
                $old = is_file($abs)? (string)@ file_get_contents($abs): '';
                if($old !== $newc) {
                    return['kind' => 'write','path' => native_trunc(basename($p),48),'old' => native_trunc($old,12000),'new' => native_trunc($newc,12000)];
                }
            }
        }
        return null;
    }
    if($tool === 'read') {
        $t = native_oc_strip_annotations($out);
        if($t === '')return null;
        $p = (string)($inp['filePath']?? $inp['path']?? $detail);
        $rg = '';
        if(! empty($inp['offset'])|| ! empty($inp['limit'])) {
            try {
                $o = (int)($inp['offset']?? 1);
                $l = (int)($inp['limit']?? 0);
                if($l > 0)$rg = ' baris '.$o.'-'.($o + $l - 1);
            }catch(\Throwable $e) {
            }
        }
        return['kind' => 'read','path' => native_trunc(basename((string)$p).$rg,48),'text' => native_trunc($t,12000)];
    }
    if(in_array($tool,['bash','shell','exec'],true)) {
        if(trim($out)=== '')return null;
        return['kind' => 'exec','path' => native_trunc(preg_replace('/\s+/',' ',(string)($inp['command']?? $detail)),40),'text' => native_trunc($out,15000)];
    }
    if(in_array($tool,['grep','glob','search','websearch','list','ls','fs_list'],true)) {
        if(trim($out)=== '')return null;
        return['kind' => 'search','path' => native_trunc($detail,40),'text' => native_trunc($out,8000)];
    }
    return null;
}
function native_verifier_max_pass(): int {
    return max(1,native_config_int('AI_VERIFIER_MAX_PASS',3));
}
function native_stats_file(): string {
    return __DIR__.'/.stats.json';
}
function native_stats_record(string $event,array $data = []): void {
    if(native_config_int('AI_STATS',1)!== 1)return;
    $f = native_stats_file();
    $st = [];
    if(is_file($f)) {
        $raw = @ file_get_contents($f);
        $j = json_decode((string)$raw,true);
        if(is_array($j))$st = $j;
    }$st['events'][]= ['t' => time(),'e' => $event]+ $data;
    if(count($st['events'])> 2000)$st['events']= array_slice($st['events'],- 2000);
    @ file_put_contents($f,json_encode($st,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),LOCK_EX);
}
function native_stats_summary(): array {
    $f = native_stats_file();
    $st = [];
    if(is_file($f)) {
        $raw = @ file_get_contents($f);
        $j = json_decode((string)$raw,true);
        if(is_array($j))$st = $j;
    }$out = ['planner_ok' => 0,'planner_skip' => 0,'verifier_pass' => 0,'verifier_fail' => 0,'strike_track' => 0,'strike_reset' => 0,'runs' => 0,'tokens_in' => 0,'tokens_out' => 0,'prompt_inject' => 0,'prompt_skip' => 0,'last_event' => 0];
    foreach(($st['events']??[])as $ev) {
        $e = (string)($ev['e']?? '');
        if($e === 'planner_ok')$out['planner_ok']++;
        elseif($e === 'planner_skip')$out['planner_skip']++;
        elseif($e === 'verifier_pass')$out['verifier_pass']++;
        elseif($e === 'verifier_fail')$out['verifier_fail']++;
        elseif($e === 'strike_track')$out['strike_track']++;
        elseif($e === 'strike_reset')$out['strike_reset']++;
        elseif($e === 'run_end') {
            $out['runs']++;
            $out['tokens_in']+= (int)($ev['in']?? 0);
            $out['tokens_out']+= (int)($ev['out']?? 0);
        }elseif($e === 'prompt_inject')$out['prompt_inject']++;
        elseif($e === 'prompt_skip')$out['prompt_skip']++;
        $out['last_event']= max($out['last_event'],(int)($ev['t']?? 0));
    }
    return $out;
}
function native_allow_all_file(): string {
    return __DIR__.'/.approval_always';
}
function native_allow_all_get(): bool {
    clearstatcache(true,native_allow_all_file());
    return is_file(native_allow_all_file());
}
function native_set_allow_all(bool $on): bool {
    $f = native_allow_all_file();
    if($on) {
        file_put_contents($f,date('c'));
        clearstatcache(true,$f);
        return is_file($f);
    }
    if(is_file($f))@ unlink($f);
    clearstatcache(true,$f);
    return true;
}
function native_wait_approval(string $runId,int $timeoutSec): string {
    $flag = sys_get_temp_dir().'/c0n73xt_appr_'.md5($runId);
    if(is_file($flag))@ unlink($flag);
    $t0 = time();
    while((time()- $t0)< $timeoutSec) {
        clearstatcache(true,$flag);
        if(is_file($flag)) {
            $v = trim((string)@ file_get_contents($flag));
            @ unlink($flag);
            if(in_array($v,['once','session','always'],true))return $v;
            return 'deny';
        }
        if(function_exists('clientIsGone')&& clientIsGone())return 'deny';
        usleep(500000);
        if(((time()- $t0)% 10)< 1)native_heartbeat();
    }
    return 'deny';
}
if(isset($_SERVER['SCRIPT_FILENAME'])&& realpath(__FILE__)=== realpath($_SERVER['SCRIPT_FILENAME'])) {
    if(PHP_SAPI !== 'cli' && function_exists('http_response_code'))http_response_code(403);
    exit('no direct access');
}
return true;
