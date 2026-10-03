<?php ini_set('display_errors',0);
error_reporting(E_ALL & ~ E_DEPRECATED & ~ E_NOTICE);
set_time_limit(0);
try {
    ini_set('max_execution_time','0');
}catch(Exception $e) {
}while(ob_get_level()> 0) {
    ob_end_clean();
}header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if($_SERVER['REQUEST_METHOD']=== 'OPTIONS') {
    http_response_code(200);
    exit(0);
}$configFile = __DIR__.'/.ai-config.ini';
$config = file_exists($configFile)? parse_ini_file($configFile):[];
$apiKey = $config['AI_API_KEY']?? '';
$model = $config['AI_MODEL']?? 'gpt-4o-mini';
require_once __DIR__.'/logger.php';
applog_rotate();
applog('BOOT','api.php hit',['action' => $_GET['action']?? '-','post_keys' => array_keys($_POST)]);
require_once __DIR__.'/providers.php';
$PROVIDERS = providers_load();
$routing = providers_routing($PROVIDERS);
$providerId = '';
if(! empty($_POST['provider_id']))$providerId = (string)$_POST['provider_id'];
elseif(! empty($_GET['provider_id']))$providerId = (string)$_GET['provider_id'];
if($providerId === '__auto__')$providerId = '';
$isLLMCall = ! isset($_GET['action'])|| $_GET['action']=== 'compact';
$explicitPick = $providerId !== '';
if(! $explicitPick && $isLLMCall && $routing === 'roundrobin') {
    $providerId = providers_rr_pick($PROVIDERS);
    applog('ROUTING','roundrobin pick',['id' => $providerId]);
}elseif(! $explicitPick) {
    $act = (string)($PROVIDERS['active_webui']?? $PROVIDERS['active']?? '');
    if($act !== '' && ! empty($PROVIDERS['providers'][$act])&&($PROVIDERS['providers'][$act]['enabled']?? true)!== false) {
        $providerId = $act;
    }else {
        $enIds = providers_enabled_ids($PROVIDERS);
        $providerId = $enIds? $enIds[0]: '';
    }
}
if($providerId === '' || empty($PROVIDERS['providers'][$providerId])) {
    $enIds = providers_enabled_ids($PROVIDERS);
    $providerId = $enIds? $enIds[0]: '';
}$P = isset($PROVIDERS['providers'][$providerId])? $PROVIDERS['providers'][$providerId]: null;
$providerChain = [];
if($routing !== 'fixed') {
    foreach(providers_enabled_ids($PROVIDERS)as $_pid) {
        if($_pid !== $providerId)$providerChain[]= $_pid;
    }
}
if(isset($_GET['action'])&& $_GET['action']!== 'providers' && $_GET['action']!== 'client_log') {
    applog('PROVIDER','selected',['id' => $providerId,'mode' => $P['mode']?? '-','model' => $P['model']?? '-','base' => $P['base_url']?? '-']);
}
if(isset($_GET['action'])&& $_GET['action']=== 'client_log') {
    header('Content-Type: application/json');
    if($_SERVER['REQUEST_METHOD']!== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST aja']);
        exit;
    }$in = json_decode((string)file_get_contents('php://input'),true);
    $items = [];
    if(is_array($in['batch']?? null))$items = $in['batch'];
    elseif(is_array($in))$items = [$in];
    if(! $items) {
        http_response_code(400);
        echo json_encode(['error' => 'bad json']);
        exit;
    }
    foreach($items as $it) {
        if(! is_array($it))continue;
        $lvl = in_array(($it['level']?? ''),['error','warn','info'],true)? $it['level']: 'info';
        applog('CLIENT.'.strtoupper($lvl),(string)($it['message']?? '-'),['url' => (string)($it['url']?? '-'),'extra' => isset($it['extra'])? substr(json_encode($it['extra'],JSON_UNESCAPED_UNICODE),0,500): '-',]);
    }
    echo json_encode(['ok' => true,'n' => count($items)]);
    exit;
}
if(isset($_GET['action'])&& $_GET['action']=== 'health') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $logDir = __DIR__.'/logs';
    $cache = $logDir.'/.health.json';
    if(is_file($cache)&& time()- (int)@ filemtime($cache)< 5) {
        $c = @ file_get_contents($cache);
        if(is_string($c)&& $c !== '') { echo $c; exit; }
    }
    $today = date('Ymd');
    $appLog = $logDir.'/app-'.$today.'.log';
    $cliStart = is_file($appLog)? (int)@ shell_exec('grep -c "OPENCODE_CLI" '.escapeshellarg($appLog).' 2>/dev/null'): 0;
    $stallLog = $logDir.'/stall-'.$today.'.log';
    if(is_file($stallLog))$stallN = (int)trim((string)@ shell_exec('wc -l < '.escapeshellarg($stallLog).' 2>/dev/null'));
    else $stallN = is_file($appLog)? (int)@ shell_exec('grep -c "direct_stall\\|proxy_hang" '.escapeshellarg($appLog).' 2>/dev/null'): 0;
    $fpmN = 0; $fpmMax = 16;
    $mpid = trim((string)@ shell_exec('pgrep -f "php-fpm: master" 2>/dev/null | head -1'));
    if($mpid !== '' && ctype_digit($mpid)) $fpmN = (int)trim((string)@ shell_exec('ps --ppid '.escapeshellarg($mpid).' -o args= 2>/dev/null | grep -c "pool "'));
    if($fpmN <= 0) {
        $ps = @ shell_exec('ps -eo comm,args 2>/dev/null | grep -c "[p]hp-fpm: pool"');
        if($ps !== null && trim((string)$ps) !== '') $fpmN = (int)trim((string)$ps);
    }
    $fc = @ file_get_contents(__DIR__.'/../../rootfs/opt-debz/php-fpm-debz.conf');
    if(! is_string($fc)|| ! preg_match('/pm\.max_children\s*=\s*(\d+)/',$fc,$mm)) $fc = @ file_get_contents(__DIR__.'/config/webui-fpm.conf');
    if(is_string($fc)&& preg_match('/pm\.max_children\s*=\s*(\d+)/',$fc,$mm))$fpmMax = (int)$mm[1];
    $ocVer = ''; $ocCache = sys_get_temp_dir().'/debz-ocver.txt';
    if(is_file($ocCache)&& time()- (int)@ filemtime($ocCache)< 300)$ocVer = trim((string)@ file_get_contents($ocCache));
    else {
        $ocBin = __DIR__.'/opencode-bin/opencode';
        $ov = @ shell_exec('opencode --version 2>/dev/null || '.escapeshellarg($ocBin).' --version 2>/dev/null');
        if(is_string($ov)&& trim($ov)!== '') { $ocVer = trim(substr($ov,0,80)); @ file_put_contents($ocCache,$ocVer,LOCK_EX); }
    }
    $slowLog = $logDir.'/php-fpm-slow.log';
    if(! is_file($slowLog))$slowLog = $logDir.'/webui-fpm-slow.log';
    $slowB = is_file($slowLog)? (int)@ filesize($slowLog): 0;
    $slowTot = is_file($slowLog)? (int)@ shell_exec('grep -c "pool " '.escapeshellarg($slowLog).' 2>/dev/null'): 0;
    $slowExp = is_file($slowLog)? (int)@ shell_exec('grep -c "stream_select" '.escapeshellarg($slowLog).' 2>/dev/null'): 0;
    $ocUp = false;
    $fp = @ fsockopen('127.0.0.1',4096,$en,$es,1);
    if(is_resource($fp)) { $ocUp = true; @ fclose($fp); }
    $out = json_encode(['ok' => true,'ts' => time(),'fpm_workers' => $fpmN,'fpm_max' => $fpmMax,'oc_serve' => $ocUp ? 'up' : 'down','stall_today' => $stallN,'cli_events_today' => $cliStart,'disk_free_b' => @ disk_free_space(__DIR__),'disk_total_b' => @ disk_total_space(__DIR__),'opencode' => $ocVer],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if(is_string($out))@ file_put_contents($cache,$out,LOCK_EX);
    echo $out;
    exit;
}
$responsesEndpoint = trim($config['AI_ENDPOINT_RESPONSES']?? '');
if($responsesEndpoint === '') {
    $base = trim($config['AI_ENDPOINT']?? 'https://api.openai.com/v1/chat/completions');
    $responsesEndpoint = preg_replace('#/chat/completions$#','/responses',$base);
    if($responsesEndpoint === $base) {
        $responsesEndpoint = rtrim($base,'/').'/responses';
    }
}
if(isset($_GET['action'])&& $_GET['action']=== 'ka') {
    header('Content-Type: application/json');
    $rid = isset($_GET['run_id'])? trim((string)$_GET['run_id']): '';
    if($rid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'run_id wajib']);
        exit;
    }$f = sys_get_temp_dir().'/c0n73xt_ka_'.md5($rid);
    @ touch($f);
    $nowKa = time();
    $nKa = 0;
    foreach((array)@glob(sys_get_temp_dir().'/c0n73xt_ka_*') as $oldKa) {
        if($nKa >= 100)break;
        if(@filemtime($oldKa) < $nowKa - 3600) {
            @unlink($oldKa);
            $nKa++;
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}
if(isset($_GET['action'])&& $_GET['action']=== 'providers') {
    header('Content-Type: application/json');
    if($_SERVER['REQUEST_METHOD']=== 'GET') {
        $mask = providers_mask($PROVIDERS);
        echo json_encode(['success' => true,'active' => $providerId]+ $mask);
        exit;
    }
    if($_SERVER['REQUEST_METHOD']=== 'POST') {
        $in = json_decode((string)file_get_contents('php://input'),true);
        if(! is_array($in)) {
            http_response_code(400);
            echo json_encode(['error' => 'bad json']);
            exit;
        }$op = (string)($in['op']?? '');
        if($op === 'activate') {
            $id = trim((string)($in['id']?? ''));
            if($id === '' || empty($PROVIDERS['providers'][$id])) {
                http_response_code(404);
                echo json_encode(['error' => 'provider gak ada']);
                exit;
            }$PROVIDERS['active_webui']= $id;
            $PROVIDERS['providers'][$id]['enabled']= true;
            providers_save($PROVIDERS);
            echo json_encode(['success' => true,'active' => $id]);
            exit;
        }
        if($op === 'save') {
            $id = trim((string)($in['id']?? ''));
            $name = trim((string)($in['name']?? ''));
            $baseUrl = trim((string)($in['base_url']?? ''));
            $apiKeyIn = (string)($in['api_key']?? '');
            $modelIn = trim((string)($in['model']?? ''));
            $mode = in_array(($in['mode']?? ''),['agent','native','chat','opencode-cli'],true)? $in['mode']: 'chat';
            if($id === '' || $name === '' || $baseUrl === '') {
                http_response_code(400);
                echo json_encode(['error' => 'id/name/base_url wajib']);
                exit;
            }
            if(! preg_match('#^https?://#i',$baseUrl)) {
                http_response_code(400);
                echo json_encode(['error' => 'base_url harus http(s)://...']);
                exit;
            }$p = $PROVIDERS['providers'][$id]??['enabled' => true];
            $p['name']= $name;
            $p['base_url']= rtrim($baseUrl,'/');
            if($apiKeyIn !== '' && $apiKeyIn !== '____')$p['api_key']= $apiKeyIn;
            if($modelIn !== '')$p['model']= $modelIn;
            $p['mode']= $mode;
            $extraIn = $in['extra']?? null;
            if(is_array($extraIn)) {
                $clean = [];
                foreach($extraIn as $ek => $ev) {
                    if($ev === '' || $ev === null)continue;
                    $clean[$ek]= $ev;
                }
                if($clean)$p['extra']= $clean;
                else unset($p['extra']);
            }elseif($extraIn === 'clear') {
                unset($p['extra']);
            }$PROVIDERS['providers'][$id]= $p;
            providers_save($PROVIDERS);
            $afCount = 0;
            $afErr = '';
            if(($p['enabled']?? true)!== false && $p['base_url']!== '') {
                $af = providers_refresh_models($PROVIDERS,$id);
                $afCount = count($af['models']??[]);
                $afErr = (string)($af['error']?? '');
                if($afCount > 0)applog('PROVIDERS','auto-fetch save '.$id,['count' => $afCount]);
            }
            echo json_encode(['success' => true,'id' => $id,'auto_fetch' =>['count' => $afCount,'error' => $afErr?: '-']]);
            exit;
        }
        if($op === 'delete') {
            $id = trim((string)($in['id']?? ''));
            if($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'id wajib']);
                exit;
            }unset($PROVIDERS['providers'][$id]);
            if(($PROVIDERS['active']?? '')=== $id && ! empty($PROVIDERS['providers'])) {
                $PROVIDERS['active']= (string)array_key_first($PROVIDERS['providers']);
            }providers_save($PROVIDERS);
            echo json_encode(['success' => true]);
            exit;
        }
        if($op === 'test') {
            $tUrl = trim((string)($in['base_url']?? ''));
            $tKey = (string)($in['api_key']?? '');
            $tId = trim((string)($in['id']?? ''));
            if($tId !== '' && $tKey === '____' && ! empty($PROVIDERS['providers'][$tId]['api_key'])) {
                $tKey = (string)$PROVIDERS['providers'][$tId]['api_key'];
            }
            if(! preg_match('#^https?://#i',$tUrl)) {
                http_response_code(400);
                echo json_encode(['error' => 'base_url invalid']);
                exit;
            }
            // PROXY-FREE + ZEN DIRECT: opencode-cli/zen tidak pakai /models probe.
            // Probe /models dengan Bearer kosong di proot = hang/401 bisu -> frontend "network error".
            if(stripos($tUrl,'opencode.ai/zen')!== false) {
                $tp = ($tId !== '' && ! empty($PROVIDERS['providers'][$tId]))? $PROVIDERS['providers'][$tId]: null;
                $tm = ($tp && ! empty($tp['models'])&& is_array($tp['models']))? array_slice(array_values($tp['models']),0,500): [($tp['model']?? 'muse-spark-1.3-contributor-free')];
                echo json_encode(['success' => true,'http' => 200,'models' => $tm,'direct' => 'zen-cli']);
                exit;
            }            $tUa = trim((string)($in['ua']?? ''));
            if($tUa === '' && stripos($tUrl,'openrouter.ai')!== false)$tUa = 'opencode/1.0 (linux; x64)';
            if(! providers_need_curl()) {
                echo json_encode(['success' => false,'http' => 0,'models' => [],'error' => 'php-curl tidak aktif di HP (rootfs lama) — update APK']);
                exit;
            }
            $tHeaders = ['Authorization: Bearer '.$tKey];
            if($tUa !== '')$tHeaders[]= 'User-Agent: '.$tUa;
            $cht = curl_init(rtrim($tUrl,'/').'/models');
            curl_setopt_array($cht,[CURLOPT_RETURNTRANSFER => true,CURLOPT_HTTPHEADER => $tHeaders,CURLOPT_CONNECTTIMEOUT => 8,CURLOPT_TIMEOUT => 25,CURLOPT_SSL_VERIFYPEER => false,CURLOPT_SSL_VERIFYHOST => 0,CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
            $rawT = curl_exec($cht);
            $httpT = curl_getinfo($cht,CURLINFO_RESPONSE_CODE);
            $errT = curl_error($cht);
            if($rawT === false) {
                http_response_code(502);
                echo json_encode(['error' => 'unreachable: '.$errT]);
                exit;
            }$decT = json_decode($rawT,true);
            $idsT = [];
            if(is_array($decT)&& isset($decT['data'])) {
                foreach($decT['data']as $mm) {
                    if(isset($mm['id']))$idsT[]= $mm['id'];
                }
            }
            echo json_encode(['success' => $httpT >= 200 && $httpT < 300,'http' => $httpT,'models' => array_slice($idsT,0,500)]);
            exit;
        }
        if($op === 'toggle') {
            $id = trim((string)($in['id']?? ''));
            if($id === '' || empty($PROVIDERS['providers'][$id])) {
                http_response_code(404);
                echo json_encode(['error' => 'provider gak ada']);
                exit;
            }$to = $in['enabled']?? null;
            if($to === null)$to = !(($PROVIDERS['providers'][$id]['enabled']?? true)!== false);
            $PROVIDERS['providers'][$id]['enabled']= ($to === true || $to === 1 || $to === '1');
            providers_save($PROVIDERS);
            applog('PROVIDERS','toggle',['id' => $id,'enabled' => $PROVIDERS['providers'][$id]['enabled']? 1: 0]);
            $afCount2 = 0;
            $afErr2 = '';
            if($PROVIDERS['providers'][$id]['enabled']) {
                $hasModels = ! empty($PROVIDERS['providers'][$id]['models']);
                if(! $hasModels) {
                    $af2 = providers_refresh_models($PROVIDERS,$id);
                    $afCount2 = count($af2['models']??[]);
                    $afErr2 = (string)($af2['error']?? '');
                    if($afCount2 > 0)applog('PROVIDERS','auto-fetch toggle-on '.$id,['count' => $afCount2]);
                }
            }
            echo json_encode(['success' => true,'id' => $id,'enabled' => $PROVIDERS['providers'][$id]['enabled'],'auto_fetch' =>['count' => $afCount2,'error' => $afErr2?: '-']]);
            exit;
        }
        if($op === 'gensession') {
            $gUrl = trim((string)($in['base_url']?? ''));
            $gKey = (string)($in['api_key']?? '');
            $gId = trim((string)($in['id']?? ''));
            if($gId !== '' &&($gKey === '' || $gKey === '____')&& ! empty($PROVIDERS['providers'][$gId]['api_key'])) {
                $gKey = (string)$PROVIDERS['providers'][$gId]['api_key'];
            }
            if(! preg_match('#^https?:\/\/#i',$gUrl)) {
                http_response_code(400);
                echo json_encode(['error' => 'base_url invalid']);
                exit;
            }
            // PROXY-FREE + ZEN DIRECT: jangan probe /models & /chat/completions.
            // Probe model=test = bakar kuota + hang di proot -> frontend "network error".
            if(stripos($gUrl,'opencode.ai/zen')!== false) {
                $b = random_bytes(16);
                $b[6]= chr((ord($b[6])& 0x0f)| 0x40);
                $b[8]= chr((ord($b[8])& 0x3f)| 0x80);
                $zs = vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));
                echo json_encode(['success' => true,'session_id' => $zs,'source' => 'generated','error' => '-']);
                exit;
            }$gUa = trim((string)($in['ua']?? ''));
            if($gUa === '' && stripos($gUrl,'openrouter.ai')!== false)$gUa = 'opencode/1.0 (linux; x64)';
            $gHeaders = ['Authorization: Bearer '.$gKey];
            if($gUa !== '')$gHeaders[]= 'User-Agent: '.$gUa;
            $gHeaders[]= 'Accept: application/json';
            $foundSid = '';
            $gSource = '';
            $gErr = '';
            if(! providers_need_curl()) {
                echo json_encode(['success' => true,'session_id' => providers_new_sid(),'source' => 'generated','error' => '-']);
                exit;
            }
            $curlM = curl_init(rtrim($gUrl,'/').'/models');
            curl_setopt_array($curlM,[CURLOPT_RETURNTRANSFER => true,CURLOPT_HTTPHEADER => $gHeaders,CURLOPT_CONNECTTIMEOUT => 8,CURLOPT_TIMEOUT => 25,CURLOPT_SSL_VERIFYPEER => false,CURLOPT_SSL_VERIFYHOST => 0,CURLOPT_HEADER => true,CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
            $rawM = curl_exec($curlM);
            if($rawM !== false) {
                $hdrM = substr($rawM,0,(int)curl_getinfo($curlM,CURLINFO_HEADER_SIZE));
                if(preg_match('/^x-session-id:\s*(.+)$/mi',$hdrM,$mM)) {
                    $foundSid = trim($mM[1]);
                    $gSource = 'response';
                }
            }curl_close($curlM);
            if($foundSid === '') {
                $bodyC = json_encode(['model' => 'test','messages' =>[['role' => 'user','content' => 'hi']],'max_tokens' => 1]);
                $curlC = curl_init(rtrim($gUrl,'\/').'\/chat\/completions');
                curl_setopt_array($curlC,[CURLOPT_RETURNTRANSFER => true,CURLOPT_HTTPHEADER => array_merge($gHeaders,['Content-Type: application\/json']),CURLOPT_POST => true,CURLOPT_POSTFIELDS => $bodyC,CURLOPT_CONNECTTIMEOUT => 8,CURLOPT_TIMEOUT => 25,CURLOPT_SSL_VERIFYPEER => false,CURLOPT_SSL_VERIFYHOST => 0,CURLOPT_HEADER => true,CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
                $rawC = curl_exec($curlC);
                if($rawC !== false) {
                    $hdrC = substr($rawC,0,(int)curl_getinfo($curlC,CURLINFO_HEADER_SIZE));
                    if(preg_match('/^x-session-id:\s*(.+)$/mi',$hdrC,$mC)) {
                        $foundSid = trim($mC[1]);
                        $gSource = 'response';
                    }
                }curl_close($curlC);
            }
            if($foundSid === '') {
                if(function_exists('random_bytes')) {
                    $b = random_bytes(16);
                    $b[6]= chr((ord($b[6])& 0x0f)| 0x40);
                    $b[8]= chr((ord($b[8])& 0x3f)| 0x80);
                    $foundSid = vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));
                }else {
                    $foundSid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)| 0x4000,mt_rand(0,0x3fff)| 0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
                }$gSource = 'generated';
            }
            echo json_encode(['success' => true,'session_id' => $foundSid,'source' => $gSource,'error' => $gErr?: '-']);
            exit;
        }
        if($op === 'smartparams') {
            $spUrl = trim((string)($in['base_url']?? ''));
            $spKey = (string)($in['api_key']?? '');
            $spId = trim((string)($in['id']?? ''));
            $spModel = trim((string)($in['model']?? ''));
            if($spId !== '' &&($spKey === '' || $spKey === '____')&& ! empty($PROVIDERS['providers'][$spId]['api_key'])) {
                $spKey = (string)$PROVIDERS['providers'][$spId]['api_key'];
            }
            if(! preg_match('#^https?://#i',$spUrl)) {
                http_response_code(400);
                echo json_encode(['error' => 'base_url invalid']);
                exit;
            }
            if($spModel === '') {
                http_response_code(400);
                echo json_encode(['error' => 'model kosong — isi dulu kolom model']);
                exit;
            }
            // PROXY-FREE + ZEN DIRECT: tanpa probe network. Heuristik nama doang.
            if(stripos($spUrl,'opencode.ai/zen')!== false) {
                $hl = strtolower($spModel);
                $zr = (strpos($hl,'think')!== false || strpos($hl,'reason')!== false || strpos($hl,'r1')!== false || strpos($hl,'glm')!== false || strpos($hl,'qwq')!== false || strpos($hl,'o1')!== false || strpos($hl,'o3')!== false);
                $ze = $zr? ['temperature' => 0.6]: ['temperature' => 0.7];
                echo json_encode(['success' => true,'is_reasoning' => $zr,'is_openrouter' => false,'max_tokens' => 8192,'extra' => $ze,'extra_json' => json_encode($ze,JSON_UNESCAPED_SLASHES),'supported_params' => null,'notes' => ['zen direct: tanpa probe network (anti network error)']]);
                exit;
            }$spUa = trim((string)($in['ua']?? ''));
            if($spUa === '' && stripos($spUrl,'openrouter.ai')!== false)$spUa = 'opencode/1.0 (linux; x64)';
            $spHeaders = ['Accept: application/json'];
            if($spKey !== '')$spHeaders[]= 'Authorization: Bearer '.$spKey;
            if($spUa !== '')$spHeaders[]= 'User-Agent: '.$spUa;
            $sid = trim((string)($in['sid']?? ''));
            if($sid !== '')$spHeaders[]= 'x-session-id: '.$sid;
            $notes = [];
            $isReasoning = false;
            $maxTok = 0;
            $supportedParams = null;
            $isOpenRouter = stripos($spUrl,'openrouter.ai')!== false;
            if(! providers_need_curl()) {
                echo json_encode(['success' => true,'is_reasoning' => false,'is_openrouter' => $isOpenRouter,'max_tokens' => 8192,'extra' => ['temperature' => 0.7],'extra_json' => '{"temperature":0.7}','supported_params' => null,'notes' => ['tanpa probe: php-curl tidak aktif di HP (rootfs lama)']]);
                exit;
            }
            $curlM = curl_init(rtrim($spUrl,'/').'/models');
            curl_setopt_array($curlM,[CURLOPT_RETURNTRANSFER => true,CURLOPT_HTTPHEADER => $spHeaders,CURLOPT_CONNECTTIMEOUT => 8,CURLOPT_TIMEOUT => 25,CURLOPT_SSL_VERIFYPEER => false,CURLOPT_SSL_VERIFYHOST => 0,CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
            $rawM = curl_exec($curlM);
            $codeM = (int)curl_getinfo($curlM,CURLINFO_RESPONSE_CODE);
            curl_close($curlM);
            if($rawM !== false && $codeM === 200) {
                $jM = json_decode($rawM,true);
                $list = [];
                if(isset($jM['data'])&& is_array($jM['data']))$list = $jM['data'];
                elseif(is_array($jM))$list = $jM;
                foreach($list as $mm) {
                    if(! is_array($mm))continue;
                    if((string)($mm['id']?? '')!== $spModel)continue;
                    if(! empty($mm['supported_parameters'])&& is_array($mm['supported_parameters'])) {
                        $supportedParams = $mm['supported_parameters'];
                        if(in_array('reasoning',$supportedParams,true)|| in_array('reasoning_effort',$supportedParams,true))$isReasoning = true;
                    }
                    if(! empty($mm['max_completion_tokens']))$maxTok = (int)$mm['max_completion_tokens'];
                    elseif(! empty($mm['top_provider']['max_completion_tokens']))$maxTok = (int)$mm['top_provider']['max_completion_tokens'];
                    break;
                }
                if($supportedParams !== null)$notes[]= 'metadata /models: '.count($supportedParams).' param didukung';
            }$bodyP = json_encode(['model' => $spModel,'messages' =>[['role' => 'user','content' => 'hi']],'max_tokens' => 300,'stream' => false]);
            $curlP = curl_init(rtrim($spUrl,'/').'/chat/completions');
            curl_setopt_array($curlP,[CURLOPT_RETURNTRANSFER => true,CURLOPT_HTTPHEADER => array_merge($spHeaders,['Content-Type: application/json']),CURLOPT_POST => true,CURLOPT_POSTFIELDS => $bodyP,CURLOPT_CONNECTTIMEOUT => 8,CURLOPT_TIMEOUT => 25,CURLOPT_SSL_VERIFYPEER => false,CURLOPT_SSL_VERIFYHOST => 0,CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
            $rawP = curl_exec($curlP);
            $codeP = (int)curl_getinfo($curlP,CURLINFO_RESPONSE_CODE);
            curl_close($curlP);
            $probeOk = false;
            if($rawP !== false && $codeP === 200) {
                $jP = json_decode($rawP,true);
                if(is_array($jP)&& ! empty($jP['choices'][0])) {
                    $probeOk = true;
                    $rt = (int)($jP['usage']['completion_tokens_details']['reasoning_tokens']?? 0);
                    if($rt > 0) {
                        $isReasoning = true;
                        $notes[]= 'probe: reasoning_tokens='.$rt.' (model thinking)';
                    }else $notes[]= 'probe: reasoning_tokens=0 (model biasa)';
                    $msgP = $jP['choices'][0]['message']??[];
                    if(! empty($msgP['reasoning'])|| ! empty($msgP['reasoning_content'])) {
                        $isReasoning = true;
                        $notes[]= 'probe: ada field reasoning di response';
                    }
                }
            }elseif($rawP !== false) {
                $notes[]= 'probe HTTP '.$codeP.' (429/limit — fallback heuristik nama model)';
            }else {
                $notes[]= 'probe gagal (network) — fallback heuristik nama model';
            }
            if(! $isReasoning && $probeOk === false) {
                $heur = ['think','reason','r1','glm-5','glm-4.5','glm-4.6','nemotron','inkling','o1','o3','o4','qwq','deepseek-r','marco','mirai','gpt-5'];
                foreach($heur as $h) {
                    if(stripos($spModel,$h)!== false) {
                        $isReasoning = true;
                        $notes[]= 'heuristik: nama model mengandung "'.$h.'"';
                        break;
                    }
                }
            }$extra = [];
            if($isReasoning) {
                if($isOpenRouter) {
                    $extra['reasoning']= ['enabled' => true];
                    $notes[]= 'OpenRouter thinking → reasoning:{enabled:true}';
                }else {
                    $notes[]= 'model thinking non-OpenRouter → TANPA reasoning nested (cukup max_tokens gede)';
                }$extra['temperature']= 0.6;
            }else {
                $extra['temperature']= 0.7;
                $notes[]= 'model biasa → parameter bersih (temperature 0.7)';
            }
            if($maxTok <= 0)$maxTok = 8192;
            $notes[]= 'max_tokens disarankan: '.$maxTok;
            echo json_encode(['success' => true,'is_reasoning' => $isReasoning,'is_openrouter' => $isOpenRouter,'max_tokens' => $maxTok,'extra' => $extra,'extra_json' => json_encode($extra,JSON_UNESCAPED_SLASHES),'supported_params' => $supportedParams,'notes' => $notes]);
            exit;
        }
        if($op === 'routing') {
            $mode = (string)($in['routing']?? '');
            if(! in_array($mode,['fixed','roundrobin','failover'],true)) {
                http_response_code(400);
                echo json_encode(['error' => 'routing harus fixed|roundrobin|failover']);
                exit;
            }$PROVIDERS['routing']= $mode;
            providers_save($PROVIDERS);
            applog('PROVIDERS','routing mode: '.$mode,[]);
            echo json_encode(['success' => true,'routing' => $mode]);
            exit;
        }
        if($op === 'fetchmodels') {
            $id = trim((string)($in['id']?? ''));
            if($id === '' || empty($PROVIDERS['providers'][$id])) {
                http_response_code(404);
                echo json_encode(['error' => 'provider gak ada']);
                exit;
            }$r = providers_refresh_models($PROVIDERS,$id);
            applog('PROVIDERS','fetchmodels '.$id,['err' => $r['error']?: '-','count' => count($r['models'])]);
            if($r['error']!== '') {
                echo json_encode(['success' => false,'error' => $r['error'],'models' => array_slice($r['models'],0,400),'count' => count($r['models'])]);
                exit;
            }
            echo json_encode(['success' => true,'models' => array_slice($r['models'],0,400),'count' => count($r['models'])]);
            exit;
        }
        if($op === 'setmodel') {
            $id = trim((string)($in['id']?? ''));
            $mIn2 = trim((string)($in['model']?? ''));
            if($id === '' || empty($PROVIDERS['providers'][$id])) {
                http_response_code(404);
                echo json_encode(['error' => 'provider gak ada']);
                exit;
            }
            if($mIn2 === '') {
                http_response_code(400);
                echo json_encode(['error' => 'model wajib']);
                exit;
            }$PROVIDERS['providers'][$id]['model']= $mIn2;
            providers_save($PROVIDERS);
            echo json_encode(['success' => true,'id' => $id,'model' => $mIn2]);
            exit;
        }http_response_code(400);
        echo json_encode(['error' => 'op gak dikenal']);
        exit;
    }
}
function media_prune(string $dir, int $keepDays = 7, int $maxBytes = 300 * 1024 * 1024): void {
    $items = [];
    foreach(glob($dir.'/img_*')?: [] as $p) {
        $st = @stat($p);
        if($st)$items[] = ['path' => $p,'mtime' => $st['mtime'],'size' => $st['size']];
    }
    if(! $items)return;
    $cutoff = time() - ($keepDays * 86400);
    $total = 0;
    $alive = [];
    foreach($items as $it) {
        if($it['mtime'] < $cutoff)@ unlink($it['path']); else $alive[] = $it;
    }
    foreach($alive as $it)$total += $it['size'];
    if($total <= $maxBytes)return;
    usort($alive, function($a, $b){ return $a['mtime'] <=> $b['mtime']; });
    foreach($alive as $it) {
        if($total <= $maxBytes * 0.8)break;
        if(@ unlink($it['path']))$total -= $it['size'];
    }
}
if(isset($_GET['action'])&& $_GET['action']=== 'media_upload') {
    header('Content-Type: application/json');
    if($_SERVER['REQUEST_METHOD']!== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }if(empty($_FILES['images'])|| ! is_array($_FILES['images']['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'gak ada file di images[]']);
        exit;
    }
    require_once __DIR__.'/agent.php';
    $saved = debz_chat_images_save($_FILES['images']);
    $scheme = (($_SERVER['HTTPS']?? '')=== 'on'|| ($_SERVER['HTTP_X_FORWARDED_PROTO']?? '')=== 'https')? 'https': 'http';
    $base = $scheme.'://'.($_SERVER['HTTP_HOST']?? '127.0.0.1:8080');
    $files = [];
    foreach($saved as $abs) {
        $bn = basename((string)$abs);
        $files[] = ['url' => $base.'/media/'.$bn,'path' => 'media/'.$bn,'name' => $bn,'mime' => (string)(@ mime_content_type($abs)?: ''),'bytes' => (int)@ filesize($abs)];
    }
    $total = is_array($_FILES['images']['name'])? count($_FILES['images']['name']): 0;
    $ok = count($files);
    applog('MEDIA',$ok.' file tersimpan',['total' => $total]);
    if($ok === 0) {
        echo json_encode(['success' => false,'error' => 'gak ada image valid (maks 5 file, maks 20MB, jpg/png/webp/gif/avif/bmp)','files' => []],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    echo json_encode(['success' => true,'files' => $files],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
if(isset($_GET['action'])&& $_GET['action']=== 'compact') {
    header('Content-Type: application/json');
    if($_SERVER['REQUEST_METHOD']!== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }$inC = json_decode((string)file_get_contents('php://input'),true);
    if(! is_array($inC)|| ! is_array($inC['messages']?? null)|| ! $inC['messages']) {
        http_response_code(400);
        echo json_encode(['error' => 'messages wajib array']);
        exit;
    }require_once __DIR__.'/agent.php';
    $pC = $P?:['base_url' => 'http://127.0.0.1:20128/v1','api_key' => '','model' => 'debz_ai'];
    $transkrip = '';
    foreach($inC['messages']as $mC) {
        $rC = ($mC['role']?? '')=== 'user'? 'LU': 'DEBZ';
        $cC = (string)($mC['content']?? '');
        if(trim($cC)=== '')continue;
        $transkrip .= $rC.': '.$cC."\n\n";
    }$msgSum = [['role' => 'system','content' => 'Lu mesin peringkas. Balas HANYA JSON: {"summary":"<ringkasan padat bahasa Indonesia>"}'],['role' => 'user','content' => "Ringkas percakapan berikut jadi 1 paragraf padat (maks 150 kata). Pertahankan: keputusan, nama file/command penting, kesimpulan teknis, hal yang belum kelar.\n\nTRANSCRIPT:\n".mb_substr($transkrip,0,30000)]];
    $rC = native_chat_once($pC['base_url'],$pC['api_key'],$pC['model'],$msgSum,[],800,(isset($pC['extra'])&& is_array($pC['extra']))? $pC['extra']:[]);
    applog('COMPACT',$rC['error']!== ''? 'gagal: '.$rC['error']: 'ok '.strlen((string)($rC['content']?? '')).'B',['provider' => $pC['base_url']]);
    $outC = ['success' => false,'error' => $rC['error']?: 'gak ada jawaban'];
    $txt = trim($rC['content']?? '');
    $txt = preg_replace('/^```[a-z]*\s*/i','',$txt);
    $txt = preg_replace('/```\s*$/','',$txt);
    $txt = trim($txt);
    $decC = json_decode($txt,true);
    if(is_array($decC)&& ! empty($decC['summary'])) {
        $outC = ['success' => true,'summary' => (string)$decC['summary']];
    }elseif($txt !== '') {
        $outC = ['success' => true,'summary' => mb_substr($txt,0,1200)];
    }
    echo json_encode($outC,JSON_UNESCAPED_UNICODE);
    exit;
}
if(isset($_GET['action'])&& $_GET['action']=== 'run_status') {
    header('Content-Type: application/json');
    $rid = isset($_GET['run_id'])? trim((string)$_GET['run_id']): '';
    if($rid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'run_id wajib']);
        exit;
    }
    if(strpos($rid,'local_') === 0) {
        $sf = sse_spool_path($rid);
        foreach((array)@glob(sys_get_temp_dir().'/c0n73xt_spool_*.json') as $oldSp) {
            if(@filemtime($oldSp) < time() - 7200)@unlink($oldSp);
        }
        foreach((array)@glob(sys_get_temp_dir().'/c0n73xt_ka_*') as $oldKa) {
            if(@filemtime($oldKa) < time() - 3600)@unlink($oldKa);
        }
        if(!is_file($sf)) {
            http_response_code(404);
            echo json_encode(['status' => 'missing','output' => '']);
            exit;
        }
        $sd = json_decode((string)@file_get_contents($sf),true);
        if(!is_array($sd)) {
            http_response_code(404);
            echo json_encode(['status' => 'missing','output' => '']);
            exit;
        }
        $st = (string)($sd['status'] ?? 'running');
        $stale = time() - (int)@filemtime($sf);
        if($st === 'running' && $stale > 300)$st = 'failed';
        echo json_encode(['status' => $st,'output' => (string)($sd['output'] ?? ''),'usage' => $sd['usage'] ?? null,'updated' => (int)($sd['updated'] ?? 0),'error' => $st === 'failed' ? 'backend sunyi >5 menit (proses kemungkinan mati)' : ''],JSON_UNESCAPED_UNICODE);
        exit;
    }$baseEndpointS = rtrim(trim($config['AI_ENDPOINT']?? 'http://127.0.0.1:20128/v1/chat/completions'),'/');
    $baseApiS = preg_replace('#/chat/completions$#','',$baseEndpointS);
    if($baseApiS === $baseEndpointS)$baseApiS = preg_replace('#/responses$#','',$baseEndpointS);
    $baseApiS = rtrim($baseApiS,'/');
    if(! providers_need_curl()) {
        http_response_code(502);
        echo json_encode(['error' => 'php-curl tidak aktif di HP (rootfs lama) — update APK']);
        exit;
    }
    $chs = curl_init($baseApiS.'/runs/'.rawurlencode($rid));
    curl_setopt($chs,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($chs,CURLOPT_HTTPHEADER,['Authorization: Bearer '.$apiKey]);
    curl_setopt($chs,CURLOPT_CONNECTTIMEOUT,30);
    curl_setopt($chs,CURLOPT_TIMEOUT,300);
    curl_setopt($chs,CURLOPT_SSL_VERIFYPEER,false);
    curl_setopt($chs,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_1_1);
    curl_setopt($chs,CURLOPT_SSL_VERIFYHOST,0);
    $respS = curl_exec($chs);
    $httpS = curl_getinfo($chs,CURLINFO_RESPONSE_CODE);
    $errS = curl_error($chs);
    http_response_code($respS === false? 502: (int)$httpS);
    echo($respS === false)? json_encode(['error' => 'gateway unreachable: '.$errS]): $respS;
    exit;
}
if(isset($_GET['action'])&& $_GET['action']=== 'approval') {
    header('Content-Type: application/json');
    if($_SERVER['REQUEST_METHOD']!== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }$body = json_decode(file_get_contents('php://input'),true);
    $approvalRunId = isset($body['run_id'])? trim((string)$body['run_id']): '';
    $approvalChoice = isset($body['choice'])? trim((string)$body['choice']): '';
    if($approvalRunId === '' || ! in_array($approvalChoice,['once','session','always','deny'],true)) {
        http_response_code(400);
        echo json_encode(['error' => 'run_id dan choice (once/session/always/deny) wajib']);
        exit;
    }
    if(strpos($approvalRunId,'native_')=== 0) {
        $flag = sys_get_temp_dir().'/c0n73xt_appr_'.md5($approvalRunId);
        $w = file_put_contents($flag,$approvalChoice);
        applog('APPROVAL','native answered: '.$approvalChoice,['run_id' => $approvalRunId]);
        echo json_encode(['success' =>($w !== false),'mode' => 'native','resolved' =>($w !== false)? 1: 0,'choice' => $approvalChoice]);
        exit;
    }$baseEndpoint0 = rtrim(trim($config['AI_ENDPOINT']?? 'http://127.0.0.1:20128/v1/chat/completions'),'/');
    $baseApi0 = preg_replace('#/chat/completions$#','',$baseEndpoint0);
    if($baseApi0 === $baseEndpoint0)$baseApi0 = preg_replace('#/responses$#','',$baseEndpoint0);
    $baseApi0 = rtrim($baseApi0,'/');
    if(! providers_need_curl()) {
        http_response_code(502);
        echo json_encode(['error' => 'php-curl tidak aktif di HP (rootfs lama) — update APK']);
        exit;
    }
    $ch0 = curl_init($baseApi0.'/runs/'.rawurlencode($approvalRunId).'/approval');
    curl_setopt($ch0,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch0,CURLOPT_HTTPHEADER,['Authorization: Bearer '.$apiKey,'Content-Type: application/json']);
    curl_setopt($ch0,CURLOPT_POST,true);
    curl_setopt($ch0,CURLOPT_POSTFIELDS,json_encode(['choice' => $approvalChoice]));
    curl_setopt($ch0,CURLOPT_CONNECTTIMEOUT,30);
    curl_setopt($ch0,CURLOPT_TIMEOUT,300);
    curl_setopt($ch0,CURLOPT_SSL_VERIFYPEER,false);
    curl_setopt($ch0,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_1_1);
    curl_setopt($ch0,CURLOPT_SSL_VERIFYHOST,0);
    $resp0 = curl_exec($ch0);
    $http0 = curl_getinfo($ch0,CURLINFO_RESPONSE_CODE);
    $err0 = curl_error($ch0);
    curl_close($ch0);
    http_response_code($resp0 === false? 502: (int)$http0);
    echo($resp0 === false)? json_encode(['error' => 'gateway unreachable: '.$err0]): $resp0;
    exit;
}
if(isset($_GET['action'])&& $_GET['action']=== 'approval_mode') {
    header('Content-Type: application/json');
    $alwaysFile = __DIR__.'/.approval_always';
    if($_SERVER['REQUEST_METHOD']=== 'POST') {
        $bodyM = json_decode(file_get_contents('php://input'),true);
        $on = ! empty($bodyM['always']);
        if($on)$okM = @ file_put_contents($alwaysFile,date('c'))!== false;
        else {
            if(is_file($alwaysFile))@ unlink($alwaysFile);
            $okM = true;
        }clearstatcache(true,$alwaysFile);
        applog('APPROVAL','allow-all '.($on? 'ON': 'OFF'));
        echo json_encode(['success' => $okM,'always' => $on && is_file($alwaysFile)]);
        exit;
    }clearstatcache(true,$alwaysFile);
    echo json_encode(['always' => is_file($alwaysFile)]);
    exit;
}
if(isset($_GET['action'])&& $_GET['action']=== 'proxy_next') {
    header('Content-Type: application/json');
    http_response_code(410);
    echo json_encode(['error' => 'proxy dihapus (PROXY-FREE BUILD): semua request direct, tidak ada pool']);
    exit;
}
if(isset($_GET['action'])&& $_GET['action']=== 'model') {
    header('Content-Type: application/json');
    if($_SERVER['REQUEST_METHOD']=== 'GET') {
        echo json_encode(['model' => $model]);
        exit;
    }
    if($_SERVER['REQUEST_METHOD']=== 'POST') {
        $inputData = json_decode(file_get_contents('php://input'),true);
        $newModel = $inputData['model']?? '';
        if(empty($newModel)) {
            http_response_code(400);
            echo json_encode(['success' => false,'error' => 'Model tidak boleh kosong']);
            exit;
        }
        if(file_exists($configFile)) {
            $content = file_get_contents($configFile);
            $content = preg_replace('/^AI_MODEL\s*=.*$/m','AI_MODEL = '.$newModel,$content);
            if(strpos($content,'AI_MODEL')=== false) {
                $content = rtrim($content)."\nAI_MODEL = ".$newModel."\n";
            }file_put_contents($configFile,$content);
        }else {
            file_put_contents($configFile,"AI_MODEL = ".$newModel."\n");
        }
        echo json_encode(['success' => true,'model' => $newModel]);
        exit;
    }
}
if($_SERVER['REQUEST_METHOD']!== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
if($P && ! empty($P['api_key']))$apiKey = $P['api_key'];
if($P && ! empty($P['model']))$model = $P['model'];
if(! $P &&(empty($apiKey)|| $apiKey === 'YOUR_API_KEY_HERE')) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'AI_API_KEY not configured. Edit .ai-config.ini.']);
    exit;
}$messages = [];
if(! empty($_POST['messages'])) {
    $messages = json_decode($_POST['messages'],true);
}
if(empty($messages)) {
    $inputRaw = file_get_contents('php://input');
    $input = json_decode($inputRaw,true);
    if(! empty($input['messages']))$messages = $input['messages'];
}
if(empty($messages)|| ! is_array($messages)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request / messages empty']);
    exit;
}header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
echo str_repeat(' ',2048)."\n";
echo ": ka\n\n";
if(function_exists('ob_flush'))@ob_flush();
flush();
require_once __DIR__.'/agent.php';
if($P && in_array(($P['mode']?? 'chat'),['native','chat','opencode-cli'],true)) {
    $messagesIn = [];
    foreach($messages as $m) {
        if(! is_array($m)|| empty($m['role']))continue;
        $row = ['role' => $m['role'],'content' => isset($m['content'])&& is_string($m['content'])? $m['content']: ''];
        if(($m['role']?? '')=== 'assistant' && ! empty($m['reasoning_details'])&& is_array($m['reasoning_details'])) {
            $row['reasoning_details']= $m['reasoning_details'];
        }$messagesIn[]= $row;
    }$userText = '';
    for($i = count($messagesIn)- 1;
    $i >= 0;
    $i --) {
        if($messagesIn[$i]['role']=== 'user') {
            $userText = $messagesIn[$i]['content'];
            array_splice($messagesIn,$i,1);
            break;
        }
    }$maxTok = (int)($_POST['max_tokens']?? 0);
    $maxTokCap = (int)($config['AI_MAX_TOKEN']?? 8192);
    if($maxTokCap <= 0)$maxTokCap = 8192;
    if($maxTok > $maxTokCap)$maxTok = $maxTokCap;
    if($maxTok < 0)$maxTok = 0;
    $toolsOn = ($_POST['tools']?? '1')!== '0';
    $allowSessionIn = (($_POST['approval_session']?? '0')=== '1');
    $cliKa = trim((string)($_POST['ka_id']?? ''));
    if($cliKa === '')$cliKa = 'ka'.date('YmdHis').substr(md5((string)microtime(true)),0,6);
    emit(['type' => 'run_started','run_id' => 'local_'.$cliKa,'ka_id' => $cliKa]);
    $GLOBALS['_sse_spool'] = sse_spool_path('local_'.$cliKa);
    $GLOBALS['_spool_buf'] = '';
    $GLOBALS['_spool_usage'] = null;
    $GLOBALS['_spool_flush'] = 0;
    sse_spool_flush('running');
    emit(['type' => 'status','phase' => 'thinking']);
    if($routing !== 'fixed') {
        $chainNames = [];
        foreach($providerChain as $pcid) {
            $chainNames[]= (string)($PROVIDERS['providers'][$pcid]['name']?? $pcid);
        }emit(['type' => 'terminal','kind' => 'info','line' => '🔀 routing: '.$routing.' · aktif: '.($P['name']?? $providerId).(count($chainNames)? ' · cadangan: '.implode(', ',$chainNames): ' (gak ada cadangan)')]);
    }
    if(($P['mode']?? '')=== 'opencode-cli') {
        $cliThreadId = trim((string)($_POST['thread_id']?? $_POST['ka_id']?? ''));
        if($cliThreadId === '')$cliThreadId = 'fresh_'.debz_generate_opencode_id('');
        $attachFiles = [];
        if(! empty($_FILES['images']))$attachFiles = debz_chat_images_save($_FILES['images']);
        applog('CHAT','opencode-cli start',['provider' => $providerId,'thread' => $cliThreadId,'model' => (string)($P['model']?? ''),'msgs' => count($messagesIn),'user_len' => strlen($userText),'files' => count($attachFiles)]);
        native_agent_run_opencode_cli($P,$messagesIn,$maxTok,$userText,$cliThreadId,$toolsOn,$allowSessionIn,$attachFiles);
        exit;
    }
    if(($P['mode']?? '')=== 'native') {
        applog('CHAT','native agent start',['provider' => $providerId,'routing' => $routing,'chain' => count($providerChain),'tools' => $toolsOn? 1: 0,'msgs' => count($messagesIn),'user_len' => strlen($userText)]);
        native_agent_run($P,$messagesIn,$maxTok,$userText,$toolsOn,$providerChain,$PROVIDERS,$allowSessionIn);
        exit;
    }else {
        $sysChat = [['role' => 'system','content' => "Anda adalah Debz AI, Polyglot Principal Software Engineer, Senior Enterprise Architect, dan Expert Code Reviewer yang menguasai seluruh ekosistem pemrograman (JavaScript/TypeScript, Python, Go, Rust, Java, C++, C#, PHP, HTML, CSS, Ruby, SQL, serta berbagai framework modern). Anda memberikan jawaban dengan ketepatan analisis tingkat tinggi sekelas Gemini Pro dan GPT-4o.\n\nSecara otomatis, Anda wajib menyesuaikan diri berdasarkan bahasa pemrograman yang saya berikan dan mematuhi aturan berikut:\n\n1. BAHASA GAUL & GAYA SANTUN (WAJIB)\n   - Balas dengan bahasa Indonesia gaul, santai, dan akrab — kayak ngobrol sama temen sesama developer. Wajar pakai istilah kekinian (gas, mantap, gacor, dll) asal konteksnya pas.\n   - TETAP padat & langsung ke inti teknis: tanpa basa-basi, gak perlu pembuka kayak \"Tentu, ini kodenya...\" atau penutup \"Semoga membantu!\".\n\n2. ADAPTIF TERHADAP EKOSISTEM BAHASA (ECOSYSTEM-SPECIFIC BEST PRACTICES)\n   Jika kode menggunakan:\n   - TypeScript/JavaScript: Patuhi ESM, Strict Mode, Functional Programming, asinkronus yang bersih (async/await), dan minimalisasi dependensi npm.\n   - Python: Terapkan PEP 8, Type Hinting, struktur efisien (list comprehension/generator), dan penanganan memori yang tepat.\n   - Go: Terapkan idiomatic Go, penanganan error eksplisit (if err != nil), efisiensi goroutine/channel, dan zero-allocation jika memungkinkan.\n   - Rust: Patuhi aturan kepemilikan (ownership/borrowing), hindari 'unsafe' dan 'unwrap' tanpa penanganan, serta optimalkan manajemen memori.\n   - Java/C#: Patuhi SOLID principles, OOP yang bersih, penanganan eksepsi yang tepat, dan design patterns standar industri.\n   - C/C++: Prioritaskan manajemen memori yang aman (hindari memory leaks/buffer overflow), efisiensi pointer, dan optimasi kompiler.\n   - SQL: Terapkan optimasi indeks, hindari N+1 query, cegah SQL Injection dengan prepared statements, dan perhatikan efisiensi JOIN.\n\n3. STRUKTUR RESPONS (WAJIB)\n   - ANALISIS SINGKAT: Maksimal 2-3 kalimat di awal tentang pendekatan logika atau akar masalah (root cause) jika itu sebuah bug.\n   - BLOK KODE (PRODUCTION-READY): Tulis kode yang utuh, bersih, aman, memiliki error handling yang kuat, dan siap pakai di lingkungan produksi. Berikan komentar singkat pada baris yang kompleks.\n   - REKOMENDASI LANJUTAN: Gunakan poin-poin singkat hanya untuk menjelaskan kompleksitas algoritma (Big-O), celah keamanan yang dihindari, atau opsi optimasi skala besar.\n\n4. SIKAP REVIEWS & KOREKSI CRITICAL\n   Jika pendekatan atau arsitektur kode yang saya berikan suboptimal, rentan bug, atau tidak aman, koreksi saya secara langsung dan tunjukkan letak kesalahannya beserta solusi alternatif yang lebih efisien.\n\n5. ZERO PASSIVE (WAJIB) — DILARANG balas ack kosong/pasif kayak \"Siap, gue masih wait\", \"belum ngedit apa-apa\", \"kalau ada kode/path di-patch kirim\", \"gue diem\", dsb. Saat user nyebut path file + keluhan, itu perintah ACTION: baca file-nya, telusuri root cause, kasih diagnosa + patch langsung (backup dulu, show diff). Kalau user cek hidup (\"MASIH LA GUA JALAN?\"), jawab langsung status kerjaannya."]];
        $histChat = $sysChat;
        foreach($messagesIn as $m) {
            if($m['role']=== 'system') {
                $histChat[0]['content'].= "\n".$m['content'];
                continue;
            }
            if($m['content']=== '')continue;
            $histChat[]= ['role' => $m['role']=== 'assistant'? 'assistant': 'user','content' => $m['content']];
        }$r = native_chat_once($P['base_url'],$P['api_key']?? '',$P['model'],$histChat,[],$maxTok,(isset($P['extra'])&& is_array($P['extra']))? $P['extra']:[]);
        if($r['error']!== '' && $r['content']=== '' && ! empty($providerChain)) {
            foreach($providerChain as $fcid) {
                $fp = $PROVIDERS['providers'][$fcid]?? null;
                if(! $fp)continue;
                emit(['type' => 'terminal','kind' => 'info','line' => '🔄 FAILOVER: '.trunc((string)$r['error'],60).' → pindah ke "'.(string)($fp['name']?? $fcid).'"']);
                applog('CHAT','failover → '.$fcid,['err' => substr((string)$r['error'],0,200)]);
                $r = native_chat_once($fp['base_url'],$fp['api_key']?? '',$fp['model'],$histChat,[],$maxTok,(isset($fp['extra'])&& is_array($fp['extra']))? $fp['extra']:[]);
                if($r['error']=== '') {
                    $providerId = $fcid;
                    break;
                }
            }
        }applog('CHAT','chat mode done',['provider' => $providerId,'routing' => $routing,'err' => $r['error']?: '-','content_len' => strlen($r['content']?? ''),'finish' => $r['finish_reason']?: '-','usage' => $r['usage']?? null]);
        if($r['error']!== '') {
            emit(['choices' =>[['delta' =>['content' => '⚠️ **Provider error:** '.$r['error']]]]]);
        }else {
            if($r['content']!== '')emit(['choices' =>[['delta' =>['content' => $r['content']]]]]);
            if(! empty($r['usage'])) {
                $u = $r['usage'];
                emit(['type' => 'usage','input_tokens' => $u['prompt_tokens']?? 0,'output_tokens' => $u['completion_tokens']?? 0,'total_tokens' => $u['total_tokens']?? 0]);
            }
        }
    }
    global $emittedAnything,$doneSent;
    if(! isset($doneSent)|| ! $doneSent) {
        if(empty($emittedAnything)) {
            if(function_exists('applog'))applog('API','EMPTY_STREAM safety net triggered',['reason' => 'no content emitted before emitDone']);
            $fallbackMsg = "\n\n⚠️ **Stream kosong**: provider tidak mengirim konten apapun. Bisa jadi limit tersembunyi. Coba pake VPN dulu, atau coba ganti ke provider gratis / berbayar lainnya lalu ketik lanjut.\n";
            emit(['choices' =>[['delta' =>['content' => $fallbackMsg]]]]);
        }
    }emitDone();
    exit;
}@ ini_set('output_buffering','off');
@ ini_set('zlib.output_compression','Off');
@ ini_set('implicit_flush',true);
if(function_exists('apache_setenv')) {
    @ apache_setenv('no-gzip','1');
}while(ob_get_level()> 0) {
    @ ob_end_flush();
}flush();
set_time_limit(0);
function sse_spool_path(string $runId): string {
    return sys_get_temp_dir().'/c0n73xt_spool_'.md5($runId).'.json';
}
function sse_spool_flush(string $status): void {
    $sp = (string)($GLOBALS['_sse_spool'] ?? '');
    if($sp === '')return;
    $GLOBALS['_spool_flush'] = time();
    @file_put_contents($sp,json_encode(['status' => $status,'output' => (string)($GLOBALS['_spool_buf'] ?? ''),'usage' => $GLOBALS['_spool_usage'] ?? null,'updated' => time()],JSON_UNESCAPED_UNICODE),LOCK_EX);
}
function emit($obj) {
    echo 'data: '.json_encode($obj,JSON_UNESCAPED_UNICODE)."\n\n";
    if(function_exists('ob_flush'))@ob_flush();
    flush();
    $GLOBALS['_last_emit'] = time();
    if(!empty($GLOBALS['_sse_spool'])) {
        $d = $obj['choices'][0]['delta']['content'] ?? '';
        if(is_string($d) && $d !== '') {
            $GLOBALS['_spool_buf'] = ($GLOBALS['_spool_buf'] ?? '').$d;
            if(strlen($GLOBALS['_spool_buf']) > 500000)$GLOBALS['_spool_buf'] = substr($GLOBALS['_spool_buf'],0,500000);
        }
        if(($obj['type'] ?? '') === 'usage')$GLOBALS['_spool_usage'] = $obj;
        if(time() - (int)($GLOBALS['_spool_flush'] ?? 0) >= 2)sse_spool_flush('running');
    }
}
function emitDone() {
    if(!empty($GLOBALS['_sse_spool']))sse_spool_flush('completed');
    echo "data: [DONE]\n\n";
    if(function_exists('ob_flush'))@ob_flush();
    flush();
}
function trunc($s,$n) {
    $s = (string)$s;
    if(function_exists('mb_substr'))return mb_substr($s,0,$n);
    return substr($s,0,$n);
}
function toolDetailFromArgs($argsRaw) {
    $a = json_decode((string)$argsRaw,true);
    if(! is_array($a))return '';
    foreach(['path','file','query','command','pattern','url','name','skill','search']as $k) {
        if(! empty($a[$k])&& is_string($a[$k]))return trunc($a[$k],64);
    }
    foreach($a as $v) {
        if(is_string($v)&& $v !== '')return trunc($v,64);
    }
    return '';
}
function summarizeToolOutput($textRaw) {
    $raw = (string)$textRaw;
    $t = json_decode($raw,true);
    if(is_array($t)) {
        if(isset($t['total_lines']))return $t['total_lines'].' baris';
        if(isset($t['total_count']))return $t['total_count'].' hasil';
        if(isset($t['bytes_written']))return $t['bytes_written'].' bytes';
        if(isset($t['exit_code']))return 'exit '.$t['exit_code'];
        if(isset($t['status'])&& is_string($t['status']))return $t['status'];
    }
    if(function_exists('native_oc_strip_annotations'))$raw = native_oc_strip_annotations($raw);
    $flat = trim(preg_replace('/\s+/',' ',$raw));
    return $flat === ''? 'ok': trunc($flat,64);
}$baseEndpoint = rtrim(trim($config['AI_ENDPOINT']?? 'http://127.0.0.1:20128/v1/chat/completions'),'/');
$baseApi = preg_replace('#/chat/completions$#','',$baseEndpoint);
if($baseApi === $baseEndpoint)$baseApi = preg_replace('#/responses$#','',$baseEndpoint);
$baseApi = rtrim($baseApi,'/');
$hasImages = false;
if(! empty($_FILES['images'])&& is_array($_FILES['images']['name'])) {
    foreach($_FILES['images']['name']as $idx => $name) {
        if($_FILES['images']['error'][$idx]=== UPLOAD_ERR_OK) {
            $hasImages = true;
            break;
        }
    }
}$instructions = '';
$conversation_history = [];
$userMessage = '';
$lastUserText = '';
foreach($messages as $m) {
    if(! is_array($m)|| empty($m['role']))continue;
    $role = $m['role'];
    $content = isset($m['content'])&& is_string($m['content'])? $m['content']: '';
    if($role === 'system') {
        $instructions .= ($instructions !== ''? "\n": '').$content;
        continue;
    }$content = preg_replace('/<div style="display: flex; gap: 6px;[\s\S]*<\/div>\s*$/u','',$content);
    $content = trim($content);
    if($content === '')continue;
    if($role === 'user')$lastUserText = $content;
    $conversation_history[]= ['role' => $role,'content' => $content];
}
if($lastUserText !== '') {
    $userMessage = $lastUserText;
    array_pop($conversation_history);
}
if(! $hasImages && $userMessage !== '') {
    $runsPayload = ['input' => $userMessage,'conversation_history' => $conversation_history,];
    if($instructions !== '')$runsPayload['instructions']= $instructions;
    if(! providers_need_curl()) {
        if(! headers_sent())header('Content-Type: text/event-stream; charset=utf-8');
        emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Gateway lama butuh php-curl** yang tidak aktif di HP (rootfs lama). Update APK atau pakai provider mode opencode-cli.\n"]]]]);
        emitDone();
        exit;
    }
    $ch = curl_init($baseApi.'/runs');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Authorization: Bearer '.$apiKey,'Content-Type: application/json']);
    curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($runsPayload,JSON_UNESCAPED_UNICODE));
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,30);
    curl_setopt($ch,CURLOPT_TIMEOUT,300);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    curl_setopt($ch,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_1_1);
    curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,0);
    $runsJson = curl_exec($ch);
    $runsHttp = curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $runsErr = curl_error($ch);
    if($runsJson === false || $runsHttp >= 400) {
        $errMsg = $runsErr !== ''? $runsErr: 'HTTP '.$runsHttp;
        if($runsJson !== false) {
            $dec = json_decode($runsJson,true);
            if(isset($dec['error']['message']))$errMsg = $dec['error']['message'];
        }$hasImages = true;
        $runId = null;
    }else {
        $runsDec = json_decode($runsJson,true);
        $runId = is_array($runsDec)&& isset($runsDec['run_id'])? $runsDec['run_id']: null;
        if($runId === null) {
            $hasImages = true;
        }
    }
    if($runId !== null) {
        $kaFile = sys_get_temp_dir().'/c0n73xt_ka_'.md5((string)($_POST['ka_id']?? $runId));
        @ touch($kaFile);
        emit(['type' => 'run_started','run_id' => $runId,'ka_id' => (string)($_POST['ka_id']?? '')]);
        $ch = curl_init($baseApi.'/runs/'.rawurlencode($runId).'/events');
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch,CURLOPT_HTTPHEADER,['Authorization: Bearer '.$apiKey,'Accept: text/event-stream']);
        curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,30);
        // TIMEOUT 0 = unlimited.-loop di bawah udah yang jaga batas 30 mnt,
        // clientIsGone() (ka 150 dtk), dan approval 15 mnt. TIMEOUT 300
        // bunuh stream di menit kelima,.emitDone() kepanggil, frontend
        // nge-doneReceived=true -> resume di-skip -> "stream berhenti
        // sebelum selesai" tanpa warning. Low-speed guard tetap nyisa
        // biar socket upstream beneran mati gak nge-hang 30 mnt.
        curl_setopt($ch,CURLOPT_TIMEOUT,0);
        curl_setopt($ch,CURLOPT_LOW_SPEED_LIMIT,1);
        // Deep websearch di dalam gateway bisa sunyi 60-90 dtk tanpa SSE
        // (bukan socket mati). 120 dtk kepotong pas tool lagi dalem.
        // 300 dtk = toleran deep, socket beneran mati tetap dibunuh,
        // batas 30 mnt + ka 150 dtk tetap yang pegang.
        curl_setopt($ch,CURLOPT_LOW_SPEED_TIME,300);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
        curl_setopt($ch,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_1_1);
        curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,0);
        $buffer = '';
        $emittedAnything = false;
        $doneSent = false;
        $textStarted = false;
        $approvalPendingSince = 0;
        $runStartedAt = time();
        @ignore_user_abort(true);
        function clientIsGone() {
            global $kaFile;
            if($kaFile !== null) {
                clearstatcache(true,$kaFile);
                if(is_file($kaFile)) {
                    return(time()- (int)@ filemtime($kaFile))> 150;
                }
            }
            return connection_aborted()=== 1;
        }
        function finishRunStream($ch,$mh,$reason = '') {
            global $kaFile,$doneSent;
            if($kaFile !== null && is_file($kaFile))@ unlink($kaFile);
            if($reason !== '') {
                emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ ".$reason]]]]);
            }
            if(! $doneSent)emitDone();
            curl_multi_remove_handle($mh,$ch);
            curl_multi_close($mh);
            exit;
        }
        function translateRunEvent($obj) {
            global $emittedAnything,$doneSent,$textStarted,$approvalPendingSince;
            $emittedAnything = true;
            $ev = isset($obj['event'])? $obj['event']: '';
            if($ev === 'tool.started') {
                emit(['type' => 'tool','phase' => 'start','id' => 'run_tool_'.(isset($obj['timestamp'])? (string)$obj['timestamp']: uniqid()),'name' => isset($obj['tool'])? $obj['tool']: 'tool','detail' => isset($obj['preview'])? trunc($obj['preview'],64): '']);
            }elseif($ev === 'tool.completed') {
                $dur = isset($obj['duration'])? $obj['duration']: 0;
                emit(['type' => 'tool','phase' => 'result','id' => 'run_tool_done','name' => isset($obj['tool'])? $obj['tool']: 'tool','summary' =>(isset($obj['error'])&& $obj['error']? 'error': 'ok').' · '.$dur.'s']);
            }elseif($ev === 'reasoning.available') {
                emit(['type' => 'status','phase' => 'thinking']);
            }elseif($ev === 'message.delta') {
                if(! $textStarted) {
                    $textStarted = true;
                    emit(['type' => 'status','phase' => 'writing']);
                }$d = isset($obj['delta'])? $obj['delta']: '';
                if($d !== '')emit(['choices' =>[['delta' =>['content' => $d]]]]);
            }elseif($ev === 'approval.request') {
                $approvalPendingSince = time();
                emit(['type' => 'approval','run_id' => isset($obj['run_id'])? $obj['run_id']: '','command' => isset($obj['command'])? $obj['command']: '','reason' => isset($obj['reason'])? $obj['reason']:(isset($obj['description'])? $obj['description']: ''),'choices' => isset($obj['choices'])&& is_array($obj['choices'])? $obj['choices']:['once','session','always','deny'],'tool' => isset($obj['tool'])? $obj['tool']: '']);
            }elseif($ev === 'approval.responded') {
                $approvalPendingSince = 0;
                emit(['type' => 'approval_done','choice' => isset($obj['choice'])? $obj['choice']: '']);
            }elseif($ev === 'run.completed') {
                $usage = isset($obj['usage'])&& is_array($obj['usage'])? $obj['usage']:[];
                emit(['type' => 'usage','input_tokens' => isset($usage['input_tokens'])? $usage['input_tokens']: 0,'output_tokens' => isset($usage['output_tokens'])? $usage['output_tokens']: 0,'total_tokens' => isset($usage['total_tokens'])? $usage['total_tokens']: 0]);
                $doneSent = true;
                emitDone();
            }elseif($ev === 'run.failed') {
                emit(['choices' =>[['delta' =>['content' => '⚠️ Run gagal: '.(isset($obj['error'])? $obj['error']: 'unknown')]]]]);
                $doneSent = true;
                emitDone();
            }elseif($ev === 'run.cancelled') {
                emit(['choices' =>[['delta' =>['content' => '⚠️ Run dibatalkan.']]]]);
                $doneSent = true;
                emitDone();
            }
        }curl_setopt($ch,CURLOPT_WRITEFUNCTION,function($curl,$data) {
            global $buffer;
            $buffer .= $data;
            while(($pos = strpos($buffer,"\n\n"))!== false) {
                $block = substr($buffer,0,$pos);
                $buffer = substr($buffer,$pos + 2);
                $dataJson = '';
                foreach(explode("\n",$block)as $ln) {
                    $ln = rtrim($ln,"\r");
                    if(strncmp($ln,'data:',5)=== 0)$dataJson .= trim(substr($ln,5));
                }
                if($dataJson === '' || $dataJson === '[DONE]')continue;
                $obj = json_decode($dataJson,true);
                if(is_array($obj))translateRunEvent($obj);
            }
            return strlen($data);
        });
        $mh = curl_multi_init();
        curl_multi_add_handle($mh,$ch);
        $lastKa = time();
        $running = 0;
        do {
            curl_multi_exec($mh,$running);
            if($running > 0) {
                $sel = @ curl_multi_select($mh,1);
                if($sel === - 1)usleep(100000);
            }
            if(clientIsGone()) {
                finishRunStream($ch,$mh);
            }
            if($approvalPendingSince > 0 &&(time()- $approvalPendingSince)> 900) {
                finishRunStream($ch,$mh,'Approval gak dijawab 15 menit — run dibatalkan.');
            }
            if((time()- $runStartedAt)> 1800) {
                finishRunStream($ch,$mh,'Run melebihi 30 menit — dibatalkan biar server tetep sehat.');
            }
            if($running > 0 && ! $doneSent &&(time()- $lastKa)>= 10) {
                $lastKa = time();
                echo ": ka\n\n";
                if(function_exists('ob_flush'))@ob_flush();
                flush();
            }
        }while($running > 0);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_multi_remove_handle($mh,$ch);
        curl_multi_close($mh);
        $rest = trim($buffer);
        if($rest !== '') {
            foreach(explode("\n",$rest)as $ln) {
                $ln = trim($ln);
                if(strncmp($ln,'data:',5)=== 0) {
                    $obj = json_decode(trim(substr($ln,5)),true);
                    if(is_array($obj))translateRunEvent($obj);
                }
            }
        }
        if(isset($kaFile)&& is_file($kaFile))@ unlink($kaFile);
        if($curlErrno !== 0) {
            emit(['choices' =>[['delta' =>['content' => '⚠️ **System Error:** stream run gagal - '.$curlError]]]]);
        }
        // Socket mati di tengah jalan JANGAN emitDone: frontend cuma nge-resume
        // kalau stream berakhir tanpa [DONE]. Paksa DONE di sini = jawaban
        // dipotong diam-diam, sisa teks ilang.
        if(! $doneSent && ! ($curlErrno !== 0 && $emittedAnything))emitDone();
        exit;
    }
}$input = [];
foreach($conversation_history as $hm) {
    $input[]= ['type' => 'message','role' => $hm['role'],'content' =>[($hm['role']=== 'user'?['type' => 'input_text','text' => $hm['content']]:['type' => 'output_text','text' => $hm['content']])]];
}
if($userMessage !== '') {
    $input[]= ['type' => 'message','role' => 'user','content' =>[['type' => 'input_text','text' => $userMessage]]];
}
if(! empty($_FILES['images'])&& is_array($_FILES['images']['name'])) {
    $lastUserIdx = - 1;
    foreach($input as $i => $item) {
        if(isset($item['role'])&& $item['role']=== 'user')$lastUserIdx = $i;
    }
    if($lastUserIdx === - 1) {
        $input[]= ['type' => 'message','role' => 'user','content' =>[]];
        $lastUserIdx = count($input)- 1;
    }
    foreach($_FILES['images']['name']as $idx => $name) {
        if($_FILES['images']['error'][$idx]!== UPLOAD_ERR_OK)continue;
        $tmpName = $_FILES['images']['tmp_name'][$idx];
        $fileType = mime_content_type($tmpName);
        if(strpos($fileType,'image/')=== 0) {
            $imgData = base64_encode(file_get_contents($tmpName));
            $input[$lastUserIdx]['content'][]= ['type' => 'input_image','image_url' => 'data:'.$fileType.';base64,'.$imgData];
        }
    }
}
if(empty($input)) {
    emit(['choices' =>[['delta' =>['content' => '⚠️ Tidak ada pesan yang bisa diproses.']]]]);
    emitDone();
    exit;
}$payload = ['model' => $model,'input' => $input,'stream' => true];
if($instructions !== '')$payload['instructions']= $instructions;
$buffer = '';
$emittedAnything = false;
$doneSent = false;
$endedCalls = [];
$resultsSent = [];
function processUpstreamBlock($block) {
    global $emittedAnything,$doneSent,$endedCalls,$resultsSent;
    $dataJson = '';
    $lines = explode("\n",$block);
    foreach($lines as $ln) {
        $ln = rtrim($ln,"\r");
        if(strncmp($ln,'data:',5)=== 0) {
            $dataJson .= trim(substr($ln,5));
        }elseif($ln !== '' && $ln[0]=== ':') {
            echo ": ka\n\n";
            if(function_exists('ob_flush'))@ob_flush();
            flush();
        }
    }
    if($dataJson === '' || $dataJson === '[DONE]')return;
    $obj = json_decode($dataJson,true);
    if(! is_array($obj))return;
    $emittedAnything = true;
    $type = isset($obj['type'])? $obj['type']: '';
    $item = isset($obj['item'])&& is_array($obj['item'])? $obj['item']: null;
    if($type === 'response.created') {
        emit(['type' => 'status','phase' => 'thinking']);
    }elseif($type === 'response.output_item.added' && $item) {
        $itype = isset($item['type'])? $item['type']: '';
        if($itype === 'function_call') {
            $callId = isset($item['call_id'])? $item['call_id']: 'idx_'.(isset($obj['output_index'])? $obj['output_index']: rand());
            emit(['type' => 'tool','phase' => 'start','id' => $callId,'name' => isset($item['name'])? $item['name']: 'tool','detail' => toolDetailFromArgs(isset($item['arguments'])? $item['arguments']: '')]);
        }elseif($itype === 'function_call_output') {
            $callId = isset($item['call_id'])? $item['call_id']: '';
            if($callId === '' || ! in_array($callId,$resultsSent)) {
                if($callId !== '')$resultsSent[]= $callId;
                $txt = '';
                if(isset($item['output'])&& is_array($item['output'])) {
                    foreach($item['output']as $op) {
                        if(isset($op['text']))$txt .= $op['text'];
                    }
                }emit(['type' => 'tool','phase' => 'result','id' => $callId,'summary' => summarizeToolOutput($txt)]);
            }
        }elseif($itype === 'reasoning') {
            emit(['type' => 'status','phase' => 'thinking']);
        }elseif($itype === 'message') {
            emit(['type' => 'status','phase' => 'writing']);
        }
    }elseif($type === 'response.output_item.done' && $item) {
        $itype = isset($item['type'])? $item['type']: '';
        if($itype === 'function_call') {
            $callId = isset($item['call_id'])? $item['call_id']: '';
            if($callId !== '' && ! in_array($callId,$endedCalls)) {
                $endedCalls[]= $callId;
                emit(['type' => 'tool','phase' => 'end','id' => $callId,'name' => isset($item['name'])? $item['name']: 'tool']);
            }
        }elseif($itype === 'function_call_output') {
            $callId = isset($item['call_id'])? $item['call_id']: '';
            if($callId !== '' && ! in_array($callId,$resultsSent)) {
                $resultsSent[]= $callId;
                $txt = '';
                if(isset($item['output'])&& is_array($item['output'])) {
                    foreach($item['output']as $op) {
                        if(isset($op['text']))$txt .= $op['text'];
                    }
                }emit(['type' => 'tool','phase' => 'result','id' => $callId,'summary' => summarizeToolOutput($txt)]);
            }
        }
    }elseif($type === 'response.output_text.delta') {
        $d = isset($obj['delta'])? $obj['delta']: '';
        if($d !== '') {
            emit(['choices' =>[['delta' =>['content' => $d]]]]);
        }
    }elseif(substr($type,-6) === '.delta' && isset($obj['delta']) && is_string($obj['delta']) && $obj['delta'] !== '') {
        // Event delta generik (reasoning_summary_text.delta, function_call_arguments.delta, dll): teruskan sebagai konten agar stream tidak terlihat mati.
        emit(['choices' =>[['delta' =>['content' => $obj['delta']]]]]);
    }elseif(substr($type,-5) === '.done') {
        // Event .done generik (output_text.done, reasoning_summary_text.done, dll): bukan akhir stream — abaikan, tunggu response.completed.
    }elseif($type === 'response.completed') {
        $usage = isset($obj['response']['usage'])&& is_array($obj['response']['usage'])? $obj['response']['usage']:[];
        emit(['type' => 'usage','input_tokens' => isset($usage['input_tokens'])? $usage['input_tokens']: 0,'output_tokens' => isset($usage['output_tokens'])? $usage['output_tokens']: 0,'total_tokens' => isset($usage['total_tokens'])? $usage['total_tokens']: 0]);
        $doneSent = true;
        emitDone();
    }elseif($type === 'response.failed' || $type === 'response.incomplete' || $type === 'error') {
        $msg = '⚠️ Response gagal.';
        if(isset($obj['response']['error']['message']))$msg = '⚠️ '.$obj['response']['error']['message'];
        elseif(isset($obj['message']))$msg = '⚠️ '.$obj['message'];
        emit(['choices' =>[['delta' =>['content' => $msg]]]]);
        $doneSent = true;
        emitDone();
    }
}$ch = curl_init($responsesEndpoint);
curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
curl_setopt($ch,CURLOPT_HTTPHEADER,['Authorization: Bearer '.$apiKey,'Content-Type: application/json','Accept: text/event-stream']);
curl_setopt($ch,CURLOPT_POST,true);
curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE));
curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,30);
// Unlimited, sama kayak jalur /runs/events di atas: batas 30 mnt + ka 150 dtk
// yang pegang. 300 dtk = stream kepotong pas lagi panjang.
curl_setopt($ch,CURLOPT_TIMEOUT,0);
curl_setopt($ch,CURLOPT_LOW_SPEED_LIMIT,1);
// Deep tool bisa sunyi 60-90 dtk: 120 kepotong, 300 toleran (lihat /runs di atas).
curl_setopt($ch,CURLOPT_LOW_SPEED_TIME,300);
curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
curl_setopt($ch,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_1_1);
curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,0);
curl_setopt($ch,CURLOPT_WRITEFUNCTION,function($curl,$data) {
    global $buffer,$emittedAnything;
    if(! $emittedAnything) {
        $trimmed = ltrim((string)$data);
        if($trimmed !== '' && $trimmed[0]=== '{' && strpos($trimmed,'"error"')!== false) {
            $decoded = json_decode($trimmed,true);
            $msg = isset($decoded['error']['message'])? $decoded['error']['message']: $trimmed;
            emit(['choices' =>[['delta' =>['content' => '⚠️ Gateway error: '.$msg]]]]);
            $emittedAnything = true;
            return strlen($data);
        }
    }$buffer .= $data;
    while(($pos = strpos($buffer,"\n\n"))!== false) {
        $block = substr($buffer,0,$pos);
        $buffer = substr($buffer,$pos + 2);
        processUpstreamBlock($block);
    }
    return strlen($data);
});
$mh = curl_multi_init();
curl_multi_add_handle($mh,$ch);
$kaFile = sys_get_temp_dir().'/c0n73xt_ka_'.md5((string)($_POST['ka_id']?? 'fallback'));
if(! empty($_POST['ka_id']))@ touch($kaFile);
else $kaFile = null;
$lastKa = time();
$runStartedAt = time();
$running = 0;
function finishFallbackStream($ch,$mh,$reason = '') {
    global $kaFile,$doneSent;
    if($kaFile !== null && is_file($kaFile)) {
        clearstatcache(true,$kaFile);
        @ unlink($kaFile);
    }
    if($reason !== '') {
        emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ ".$reason]]]]);
    }
    if(! $doneSent)emitDone();
    curl_multi_remove_handle($mh,$ch);
    curl_multi_close($mh);
    exit;
}
function fallbackClientIsGone() {
    global $kaFile;
    if($kaFile !== null) {
        clearstatcache(true,$kaFile);
        if(is_file($kaFile)) {
            return(time()- (int)@ filemtime($kaFile))> 150;
        }
    }
    return connection_aborted()=== 1;
}do {
    curl_multi_exec($mh,$running);
    if($running > 0) {
        $sel = @ curl_multi_select($mh,1);
        if($sel === - 1)usleep(100000);
    }@ignore_user_abort(true);
    if(fallbackClientIsGone()) {
        finishFallbackStream($ch,$mh);
    }
    if((time()- $runStartedAt)> 1800) {
        finishFallbackStream($ch,$mh,'Run melebihi 30 menit — dibatalkan biar server tetep sehat.');
    }
    if($running > 0 && ! $doneSent &&(time()- $lastKa)>= 10) {
        $lastKa = time();
        echo ": ka\n\n";
        if(function_exists('ob_flush'))@ob_flush();
        flush();
    }
}while($running > 0);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
curl_multi_remove_handle($mh,$ch);
curl_multi_close($mh);
if($kaFile !== null && is_file($kaFile)) {
    clearstatcache(true,$kaFile);
    @ unlink($kaFile);
}$rest = trim($buffer);
if($rest !== '') {
    processUpstreamBlock($rest);
}
if($curlErrno !== 0) {
    emit(['choices' =>[['delta' =>['content' => '⚠️ **System Error:** cURL gagal - '.$curlError]]]]);
}
if(! $doneSent && ! ($curlErrno !== 0 && $emittedAnything)) {
    emitDone();
}
