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
// PROXY-FREE BUILD: proxy pool/grabber dibuang total (UI tidak ada opsi proxy,
// semua request direct). Stub no-op dipertahankan agar call-site lama tetap jalan.
function debz_proxy_failover(string $proxy,string $reason = '',bool $blacklist = true): void {}
function debz_proxy_score_record(string $proxy,bool $success): void {}
function debz_proxy_sticky_ok(string $proxy): void {}
function debz_proxy_mark_used(string $proxy): void {}
function debz_proxy_blacklist_add(string $proxy): void {}
function debz_proxy_apply($ch,array $opts): string { $GLOBALS['_debz_last_proxy']=''; return ''; }
function termEmit(string $kind,string $text): void {
    $emojis = ['info' => 'ℹ️','think' => '🧠','tool' => '🛠️','ok' => '✅','error' => '🚨','limit' => '⚠️','proxy' => '🌐','retry' => '🔄','fail' => '🔀'];
    $icon = $emojis[$kind]?? '🔹';
    if(function_exists('emit')) {
        emit(['type' => 'terminal','kind' => $kind,'line' =>" $icon   $text "]);
    }
}
function debz_cli_proxy_pick(): array {
    // PROXY-FREE BUILD: seluruh jalur proxy dibuang total. Chat selalu direct.
    // Return kosong = direct, tanpa baca proxy_state, tanpa hold pool.
    return['',[]];
}
// Preflight konektivitas sebelum spawn CLI (mode direct, tanpa proxy).
// Di HP: kalau provider ga bisa dijangkau langsung, CLI gantung tanpa event
// error -> user lihat "ga ada balesan". Tangkap di sini, kasih pesan jelas.
// Hasil di-cache per host selama request (static) biar retry loop ga probe ulang.
// Kartu approval (card path) butuh binary curl (SSE + POST) + php-curl
// (buat session). Rootfs lama tak punya keduanya -> fatal/bisu. Cek sekali
// per request; kalau tak lengkap, pakai run path (terbukti jalan di HP).
function debz_card_deps_ok(): bool {
    static $ok = null;
    if($ok !== null)return $ok;
    if(! function_exists('curl_init')) {
        $ok = false;
        return $ok;
    }
    $bin = trim((string)@ shell_exec('command -v curl 2>/dev/null'));
    if($bin === '' || ! @ is_executable($bin)) {
        $ok = false;
        return $ok;
    }
    $ok = true;
    return $ok;
}
function debz_net_preflight(string $baseUrl) {
    static $cache = [];
    $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
    if($host === '')return true;
    if(array_key_exists($host, $cache))return $cache[$host];
    $scheme = strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME));
    $port = (int)parse_url($baseUrl, PHP_URL_PORT);
    if($port <= 0)$port = ($scheme === 'http')? 80: 443;
    $t0 = microtime(true);
    // Timeout 12 dtk: di HP (VPN/proxy operator) TCP connect saja bisa 5 dtk+.
    // Preflight harus lebih longgar dari koneksi asli (curl 8 dtk+), kalau
    // tidak = false negative "tidak bisa menjangkau" padahal engine tembus.
    $fp = @fsockopen($host, $port, $sec, $sem, 12);
    $dt = round((microtime(true) - $t0) * 1000);
    if(!is_resource($fp)) {
        $err = "HP tidak bisa menjangkau $host:$port langsung ($sem, {$dt}ms). Chat butuh internet ke provider ini — cek koneksi HP (data/WiFi) lalu coba lagi.";
        $cache[$host] = $err;
        return $err;
    }
    fclose($fp);
    $cache[$host] = true;
    return true;
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
    while(true) {
        $sendMsgs = $stripRD? native_strip_rd($messages): $messages;
        if($resumeOn && ($keptContent !== '' || $keptReasoning !== '')) {
            if($keptContent !== '')$sendMsgs[]= ['role' => 'assistant','content' => $keptContent];
            $sendMsgs[]= ['role' => 'user','content' => 'Lanjutkan tepat dari titik terputus, jangan mengulang dari awal.'];
        }
        $r = native_chat_once_raw($baseUrl,$apiKey,$model,$sendMsgs,$tools,$maxTokens,$opts);
        if($r['error']=== '' && $r['content']=== '' && empty($r['toolCalls'])&& $r['reasoning']=== '') {
            $r['error']= 'Blackhole: stream selesai tapi 0 tokens diterima';
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
        $isTransient = (stripos($errTxt,'cURL error')=== 0 || preg_match('/\bHTTP\s+5\d\d\b/i',$errTxt)|| stripos($errTxt,'HTTP 408')!== false || stripos($errTxt,'HTTP 429')!== false || stripos($errTxt,'timeout')!== false || stripos($errTxt,'timed out')!== false || stripos($errTxt,'temporar')!== false || stripos($errTxt,'Blackhole: stream')!== false);
        $is5xxViaProxy = preg_match('/\bHTTP\s+5\d\d\b/i',$errTxt)=== 1 && ! empty($GLOBALS['_debz_last_proxy']);
        $isProxyError = stripos($errTxt,'cURL error')=== 0 || stripos($errTxt,'timeout')!== false || stripos($errTxt,'timed out')!== false || stripos($errTxt,'HTTP 407')!== false || stripos($errTxt,'Failed to connect')!== false || stripos($errTxt,'Could not connect')!== false || stripos($errTxt,'Proxy CONNECT aborted')!== false || stripos($errTxt,'empty reply from server')!== false || stripos($errTxt,'Connection reset')!== false || stripos($errTxt,'Connection refused')!== false || stripos($errTxt,'Network is unreachable')!== false || stripos($errTxt,'Broken pipe')!== false || stripos($errTxt,'unexpected eof')!== false || stripos($errTxt,'Blackhole: stream')!== false || ! empty($is5xxViaProxy);
        $isSSLError = (stripos($errTxt,'cURL error')=== 0)&&(stripos($errTxt,'SSL')!== false || stripos($errTxt,'ssl')!== false || stripos($errTxt,'certificate')!== false || stripos($errTxt,'handshake')!== false)&& stripos($errTxt,'unexpected eof')=== false;
        $isRateLimit = stripos($errTxt,'HTTP 429')!== false;
        $isStall = ! empty($r['stall']);
        if(! $isTransient || $attempt >= $maxRetry) {
            if($resumeOn && ($keptContent !== '' || $keptReasoning !== '')) {
                $r['content'] = $keptContent.(string)$r['content'];
                $r['reasoning'] = $keptReasoning.(string)$r['reasoning'];
                if($r['error']!== '')$r['error'] .= ' (partial disambung dari percobaan sebelumnya)';
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
            if(function_exists('applog'))applog('NET','stall_retry',['attempt' => $attempt,'partial_content' => strlen((string)$r['content'])]);
        }elseif(($r['content']!== '' || $r['reasoning']!== '')&& ! $isProxyError) {
            return $r;
        }elseif(($r['content']!== '' || $r['reasoning']!== '')&& $isProxyError) {
            if($resumeOn) {
                $keptContent = native_trunc($keptContent.(string)$r['content'],4000);
                $keptReasoning = native_trunc($keptReasoning.(string)$r['reasoning'],4000);
                $pxOn = ! empty($GLOBALS['_debz_last_proxy']);
                termEmit('retry',"✂️ Stream terputus. Simpan partial (".strlen($keptContent)." chars), ".($pxOn? "putar proxy + lanjutkan...": "sambung lagi otomatis..."));
            }else {
                termEmit('retry',"✂️ Stream terputus. ".( ! empty($GLOBALS['_debz_last_proxy'])? "Membuang partial data dan putar proxy...": "Coba lagi dari awal..."));
            }
            $r['content']= '';
            $r['reasoning']= '';
            $r['toolCalls']= [];
        }$attempt ++;
        $backoff = min(1000 *(2 ** max(0,$attempt - 1)),12000);
        if($isRateLimit)$backoff = min(max($backoff,15000),30000);
        if($isRateLimit) {
            $GLOBALS['_debz_direct_429_until']= time()+ 600;
        }
        // PROXY-FREE: retry selalu direct, tanpa rotasi proxy.
        $opts['_proxyFail']= false;
        $opts['_useProxy']= true;
        termEmit('retry'," ⏳ Retry # $attempt / $maxRetry  dalam  ".($backoff / 1000)."s...");
        native_sleep_heartbeat($backoff);
    }
}
function native_chat_once_raw(string $baseUrl,string $apiKey,string $model,array $messages,array $tools,int $maxTokens,array $opts = []): array {
    $tReq0 = microtime(true);
    if(! function_exists('curl_init')) {
        return['content' => '','reasoning' => '','reasoningDetails' =>[],'toolCalls' =>[],'usage' => null,'finish_reason' => '','error' => 'PHP curl ext tidak aktif di HP (rootfs lama) — update APK atau pakai mode opencode-cli','http_code' => 0];
    }
    $url = rtrim($baseUrl,'/').'/chat/completions';
    $payload = ['model' => $model,'messages' => $messages,'stream' => true,'stream_options' =>['include_usage' => true],'stop' =>["</| DSML | invoke>","EOF","</| DSML | tool_calls>"]];
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
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER => false,CURLOPT_POST => true,CURLOPT_HTTPHEADER => $chatHeaders,CURLOPT_POSTFIELDS => $encoded,CURLOPT_CONNECTTIMEOUT => 12,CURLOPT_TIMEOUT => 0,CURLOPT_LOW_SPEED_LIMIT => 10,CURLOPT_LOW_SPEED_TIME => 20,CURLOPT_TCP_KEEPALIVE => 1,CURLOPT_TCP_KEEPIDLE => 10,CURLOPT_TCP_KEEPINTVL => 5,CURLOPT_SSL_VERIFYPEER => false,CURLOPT_SSL_VERIFYHOST => 0,CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,CURLOPT_ENCODING => "",CURLOPT_WRITEFUNCTION => $write]);
    if(! empty($opts['_sslFallback'])) {
        if(function_exists('termEmit'))termEmit('info',"🔒 SSL verify disabled (fallback mode)");
    }$px = '';
    // PROXY-FREE: direct selalu, tanpa pool/wait/hold.
    // Budget stream native = 30 mnt, sama kayak jalur opencode-card
    // (--max-time 1800) dan cap internal /runs/events. Default lama 120 dtk
    // itu sisa jaman proxy: agent loop bisa 128 iterasi dan tiap iterasi
    // mangsa provider yg mikir lama, jadi 120 dtk = hampir tiap turn
    // panjang mati di tengah (errno 28) -> "stream berhenti sebelum selesai".
    // Deteksi koneksi beneran mati tetappegang LOW_SPEED, jadi 30 mnt bukan
    // socket mati yg menggantung.
    curl_setopt($ch,CURLOPT_TIMEOUT,max(300,native_config_int('AI_PROXY_CURL_TIMEOUT',1800)));
    curl_setopt($ch,CURLOPT_LOW_SPEED_TIME,max(30,native_config_int('AI_PROXY_LOWSPEED_S',45)));$ok = curl_exec($ch);
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
    }    curl_close($ch);
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
                if(function_exists('applog'))applog('NET','midstream_stall',['bytes' => $dlBytes,'partial_content' => strlen((string)$result['content']),'err' => substr($result['error'],0,200)]);
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
    @ set_time_limit(0);
    @ ignore_user_abort(true);
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
                if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n".($isLimit? "⚠️ **Kena limit 429 nih, Coba pake VPN dulu, atau coba ganti ke provider gratis / berbayar lainnya lalu ketik lanjut**\n\n`": "⚠️ **Provider error:** ").$errTxt]]]]);
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
                if(in_array($toolName,['web_search','webfetch','http_request','browser','computer_use','shell','search'],true))native_keepalive_note($toolName.' lagi jalan (bisa 30-60 dtk)');
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
function native_agent_run_opencode_card(array $P,string $model,string $userText,string $openSession,string $mapFile,string $threadId,array $serveCfg,string $bin,bool $allowSessionIn = false,array $attachFiles = [],array $messagesIn = []): void {
    @ set_time_limit(0);
    @ ignore_user_abort(true);
    global $emittedAnything,$doneSent;
    if(function_exists('applog'))applog('OPENCODE_CARD','start',['model' => $model,'thread' => substr($threadId,0,40),'user_len' => strlen($userText),'files' => count($attachFiles)]);
    if(! debz_card_deps_ok()) {
        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Kartu approval butuh binary curl + php-curl** yang tak ada di rootfs lama. Update APK, atau nyalakan Tools (pakai jalur run langsung).\n"]]]]);
        if(function_exists('emitDone'))emitDone();
        return;
    }
    $ocTurns = oc_thread_bump($threadId,$openSession);
    if($ocTurns === 12)oc_emit_handoff($threadId,$ocTurns,'mulai penuh, auto-compact siap');
    elseif($ocTurns === 20)oc_emit_handoff($threadId,$ocTurns,'berat, rawan socket putus');
    elseif($ocTurns > 20 && ($ocTurns % 8)=== 0)oc_emit_handoff($threadId,$ocTurns,'overload');
    // AUTO-ROTATE card: serve session berat di-fresh-kan. Konteks kartu cuma
    // hidup di serve, jadi summary WAJIB ditempel ke pesan pertama.
    // Trigger: >=16 turn ATAU turn sebelumnya >=200k tokens. Thread UI tetap sama.
    $tokWhy = ($openSession !== '')? oc_need_rotate_by_tokens($threadId): '';
    if(($ocTurns >= 16 || $tokWhy !== '') && $openSession !== '') {
        $resumePack = oc_auto_handoff_summary($P,$messagesIn);
        if($mapFile !== '')@ unlink($mapFile);
        $openSession = '';
        if($resumePack !== '')$userText = $resumePack."\n\nPESAN BARU:\n".$userText;
        oc_emit_handoff($threadId,$ocTurns,'auto-rotate serve fresh'.($tokWhy !== ''? ' ('.$tokWhy.')': '').', konteks terakhir dibawa','handoff_rotated');
        if(function_exists('termEmit'))termEmit('info','🧬 Auto-handoff: serve session di-fresh-kan. Lanjut!');
        if(function_exists('applog'))applog('OPENCODE_CARD','auto_rotate',['thread' => substr($threadId,0,12),'turns' => $ocTurns,'tokens' => $tokWhy,'summary' => $resumePack !== ''? strlen($resumePack): 0]);
        $ocTurns = oc_thread_bump($threadId,'');
        oc_thread_note_usage($threadId,0,0);
    }
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
    $postDoneAt = 0;
    $cardDone = false;
    $cardOut = 0;
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
    }
    $t0 = time();
    $lastAct = time();
    $cardGoneAt = 0;
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
                            if($bool === 'text')$cardOut ++;
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
                $postDoneAt = time();
                @ fclose($postPipes[1]);
                @ fclose($postPipes[2]);
                $lastAct = time();
            }
        }
        // RACE FIX: POST curl kelar duluan sebelum serve mulai streaming SSE
        // (HP lambat). Jangan break saat sepi — kasih grace 15 dtk. Serve
        // normal nutup SSE sendiri saat selesai (break via sse exit).
        if($postDone && ! $drainedAny && (time()- $postDoneAt)>= 15) {
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
        // GRACE 60s: blip WebView jangan langsung bunuh kartu approval.
        if(function_exists('connection_aborted')&& @ connection_aborted()=== 1) {
            if($cardGoneAt === 0) {
                $cardGoneAt = time();
                if(function_exists('applog'))applog('OPENCODE_CARD','client_gone_grace',['grace' => 60]);
            } elseif((time()- $cardGoneAt)>= 60) {
                $cardDone = true;
                break;
            }
        } else {
            $cardGoneAt = 0;
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
    if($cardOut === 0) {
        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Serve tidak memancarkan jawaban** (SSE sepi). Coba kirim ulang — kalau berulang, nyalakan Tools (jalur run langsung).\n"]]]]);
        if(function_exists('applog'))applog('OPENCODE_CARD','empty_sse',['thread' => substr($threadId,0,40)]);
    }
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
function oc_thread_meta_file(string $threadId): string {
    return sys_get_temp_dir().'/c0n73xt_oc_meta_'.md5($threadId).'.json';
}
function oc_thread_bump(string $threadId,string $openSession): int {
    if($threadId === '')return 0;
    $f = oc_thread_meta_file($threadId);
    $m = is_file($f)? @ json_decode((string)@ file_get_contents($f),true): [];
    if(! is_array($m))$m = [];
    if(($m['sid']?? '')!== $openSession && $openSession !== '') {
        $m['turns'] = 0;
        $m['sid'] = $openSession;
    } elseif($openSession === '' && (($m['turns']?? 0) >= 16)) {
        // fresh sehabis auto-rotate: reset counter biar gak rotate tiap pesan
        $m['turns'] = 0;
        $m['sid'] = '';
    }
    $m['turns'] = ((int)($m['turns']?? 0))+ 1;
    $m['updated'] = date('c');
    if($openSession !== '')$m['sid'] = $openSession;
    @ file_put_contents($f,json_encode($m),LOCK_EX);
    return (int)$m['turns'];
}
// AUTO-ROTATE berbasis tokens: tiap turn yang >=200k total / >=150k single-step
// nyatet ke meta, turn BERIKUTNYA auto fresh-serve + summary. Halaman chat (threadId)
// tetap sama — konteks nyambung via ringkasan 4 pesan terakhir (compact, anti-jebol).
function oc_thread_note_usage(string $threadId,int $stepInMax,int $usageTotal): void {
    if($threadId === '')return;
    $f = oc_thread_meta_file($threadId);
    $m = is_file($f)? @ json_decode((string)@ file_get_contents($f),true): [];
    if(! is_array($m))$m = [];
    $m['last_step_in'] = $stepInMax;
    $m['last_total'] = $usageTotal;
    $m['updated'] = date('c');
    @ file_put_contents($f,json_encode($m),LOCK_EX);
}
function oc_thread_last_tokens(string $threadId): array {
    if($threadId === '')return [0,0];
    $m = is_file(oc_thread_meta_file($threadId))? @ json_decode((string)@ file_get_contents(oc_thread_meta_file($threadId)),true): [];
    if(! is_array($m))return [0,0];
    return [(int)($m['last_step_in']?? 0),(int)($m['last_total']?? 0)];
}
function oc_need_rotate_by_tokens(string $threadId): string {
    [$si,$tt] = oc_thread_last_tokens($threadId);
    if($si >= 150000)return 'single-step ≈'.number_format($si).' tokens';
    if($tt >= 200000)return 'total ≈'.number_format($tt).' tokens';
    return '';
}
function oc_resume_cmd(string $threadId,int $turns): string {
    $short = substr($threadId,0,8);
    return 'Lanjutin dari sesi '.$short.' ('.$turns.' pesan): baca HANDOFF terakhir + git status + git diff --stat dulu, terus kerjain sisa TODO tanpa ngulang yang udah beres.';
}
function oc_emit_handoff(string $threadId,int $turns,string $reason,string $type = 'handoff_reminder'): void {
    if(! function_exists('emit')|| $threadId === '')return;
    emit(['type' => $type,'thread_id' => $threadId,'turns' => $turns,'reason' => $reason,'resume_cmd' => oc_resume_cmd($threadId,$turns)]);
    if(function_exists('termEmit'))termEmit('warn','🧬 Sesi '.$turns.'x chat ('.$reason.') — auto-handoff jaga biar awet.');
}
function oc_auto_handoff_summary(array $P,array $messagesIn): string {
    // Bawa konteks terakhir sebagai teks (tanpa LLM tambahan): endpoint HTTP
    // zen geo-block dari HP (403), jadi ringkasan LLM tak bisa diandalkan.
    // Rotasi tetap nyambung via 4 pesan terakhir @500 chars (compact, anti-jebol).
    $take = array_slice($messagesIn,-4);
    $lines = [];
    foreach($take as $mH) {
        if(! is_array($mH))continue;
        $rH = ($mH['role']?? '')=== 'user'? 'LU': 'DEBZ';
        $cH = trim(strip_tags((string)($mH['content']?? '')));
        if($cH === '')continue;
        $lines[]= $rH.': '.mb_substr(preg_replace('/\s+/',' ',$cH),0,500);
    }
    if(! $lines)return '';
    return "Konteks sesi sebelumnya (sesi CLI di-fresh-kan, lanjutkan tanpa ngulang):\n".implode("\n",$lines)."\nBaca git status + git diff --stat dulu bila relevan, terus kerjain sisa TODO.";
}
function native_agent_run_opencode_cli(array $P,array $messagesIn,int $maxTokens,string $userText,string $threadId = '',bool $toolsOn = true,bool $allowSessionIn = false,array $attachFiles = []): void {
    @ set_time_limit(0);
    @ ignore_user_abort(true);
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
    $ocTurns = oc_thread_bump($threadId,$openSession);
    if($ocTurns === 12)oc_emit_handoff($threadId,$ocTurns,'mulai penuh, auto-compact siap');
    elseif($ocTurns === 20)oc_emit_handoff($threadId,$ocTurns,'berat, rawan socket putus');
    elseif($ocTurns > 20 && ($ocTurns % 8)=== 0)oc_emit_handoff($threadId,$ocTurns,'overload');
    // AUTO-ROTATE: sesi CLI berat (>=16 turn ATAU turn lalu >=200k tokens)
    // di-reset fresh + summary dibawa sebagai konteks. UI tetap sesi yang sama —
    // user tinggal lanjut, anti opencode error sesi panjang. Summary ditempel ke
    // userText (CLI cuma kirim userText, bukan messagesIn) + messagesIn.
    $tokWhyCli = ($openSession !== '')? oc_need_rotate_by_tokens($threadId): '';
    if(($ocTurns >= 16 || $tokWhyCli !== '') && $openSession !== '') {
        $resumePack = oc_auto_handoff_summary($P,$messagesIn);
        if($mapFile !== '')@ unlink($mapFile);
        $openSession = '';
        if($resumePack !== '') {
            $userText = $resumePack."\n\nPESAN BARU:\n".$userText;
            array_unshift($messagesIn,['role' => 'system','content' => $resumePack]);
        }
        oc_emit_handoff($threadId,$ocTurns,'auto-rotate sesi fresh'.($tokWhyCli !== ''? ' ('.$tokWhyCli.')': '').', konteks terakhir dibawa','handoff_rotated');
        if(function_exists('termEmit'))termEmit('info','🧬 Auto-handoff: sesi CLI di-fresh-kan, konteks penting dibawa. Lanjut!');
        if(function_exists('applog'))applog('OPENCODE_CLI','auto_rotate',['thread' => substr($threadId,0,12),'turns' => $ocTurns,'tokens' => $tokWhyCli,'summary' => $resumePack !== ''? strlen($resumePack): 0]);
        $ocTurns = oc_thread_bump($threadId,'');
        oc_thread_note_usage($threadId,0,0);
    }
    $userText = trim((string)$userText);
    if($userText === '') {
        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Pesan kosong** — tidak ada yang bisa diproses.\n"]]]]);
        if(function_exists('emitDone'))emitDone();
        return;
    }$maxProxyTry = 2; // 1x direct + 1x cadangan khusus empty-retry (engine dingin)
    $cliBaseEnv = getenv();
    $cliProxy = '';
    $capsSession = '';
    $usageIn = 0;
    $usageOut = 0;
    $usageTotal = 0;
    $stepInMax = 0;
    $emptyRetry = 0;
    for($proxyTry = 0;
    $proxyTry < $maxProxyTry;
    $proxyTry ++) {
        [$cliProxy,$cliProxyEnv]= debz_cli_proxy_pick();
        unset($cliProxyEnv); // PROXY-FREE: env proxy tidak dipakai, selalu direct.
        // PROXY-FREE: pick selalu direct (''). Hold pool dihapus total.
        // Mode direct. Kalau provider ga
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
                if(function_exists('applog'))applog('CLI','direct_mode',['base' => (string)($P['base_url']?? '')]);
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
        $serveCfg = null;
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
        if($serveCfg !== null && ! $toolsOn && count($attachFiles)=== 0 && debz_card_deps_ok()) {
            native_agent_run_opencode_card($P,$model,$userText,$openSession,$mapFile,$threadId,$serveCfg,$bin,$allowSessionIn,$attachFiles,$messagesIn);
            return;
        }
        if($serveCfg !== null && ! $toolsOn && count($attachFiles)=== 0) {
            if(function_exists('termEmit'))termEmit('warn','Binary curl tak ada di rootfs lama — kartu approval dilewat, pakai jalur run langsung. Update APK biar kartu jalan.');
            if(function_exists('applog'))applog('OPENCODE_CLI','card_fallback_run',['reason' => 'curl-bin/php-curl missing']);
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
        if($toolsOn || native_allow_all_get() || $allowSessionIn === true) {
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
        $lastBeat = time();
        $lastDataBeat = time();
        $t0 = time();
        $cliDeadline = $t0 + 900;
        $hardCap = $t0 + 1800;
        $thinkingSent = false;
        $cliProxyHang = false;
        $lastEventTs = time();
        $lastProgressTs = time();
        $cliTimedOut = false;
        $hangNotified = false;
        $loopDetected = false;
        $clientGone = false;
        $clientGoneAt = 0;
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
                    $stepCount ++;
                    $dispN = $stepCount;
                    if(! $thinkingSent) {
                        if(function_exists('emit'))emit(['type' => 'status','phase' => 'thinking','iter' => $dispN]);
                        $thinkingSent = true;
                    }
                    if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'info','line' => '🚚💨💨 Di Proses '.$dispN]);
                    if(function_exists('emit'))emit(['type' => 'step','n' => $dispN]);
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
                            if($stepIn >= 150000 && ! $hangNotified && function_exists('termEmit')) {
                                $hangNotified = true;
                                termEmit('warn','ℹ️ Konteks sesi besar (≈'.number_format($stepIn).' input tokens) — retry dipangkas.');
                            }
                        }
                    }$touchProgress();
                    break;
                    case 'error': $em = isset($ev['error'])&& is_array($ev['error'])?($ev['error']['message']?? 'opencode error'):((string)($ev['error']?? $part['error']?? 'opencode error'));
                    $emIsLimit = preg_match('/\b(429|rate limit|rate-limit|ratelimit|quota|too many requests)\b/i',$em)=== 1;
                    $touchProgress();
                    // ERROR di sesi panjang (>=12 turn): sesi CLI kemungkinan korup/
                    // overload. Putus map biar request BERIKUTNYA fresh + kasih tau user
                    // kirim ulang (konteks history terakhir tetap dibawa).
                    if($ocTurns >= 12 && $mapFile !== '' && is_file($mapFile)) {
                        @ unlink($mapFile);
                        oc_emit_handoff($threadId,$ocTurns,'error sesi panjang, map di-reset — kirim ulang pesanmu','handoff_rotated');
                        if(function_exists('applog'))applog('OPENCODE_CLI','error_rotate',['thread' => substr($threadId,0,12),'turns' => $ocTurns,'err' => substr($em,0,120)]);
                    }
                    if($emIsLimit) {
                        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Kena limit 429 nih, Coba pake VPN dulu, atau coba ganti ke provider gratis / berbayar lainnya lalu ketik lanjut**\n\n`".trunc((string)$em,300)."`\n"]]]]);
                        if(function_exists('termEmit'))termEmit('limit','Kena limit 429 — coba VPN dulu / ganti provider lalu ketik lanjut.');
                    } else {
                        if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **opencode error:** ".$em."\n"]]]]);
                    }
                    if(function_exists('emit'))emit(['type' => 'terminal','kind' => 'error','line' => '❌ opencode: '.trunc((string)$em,160)]);
                    $sawContent = true;
                    break;
                    default: break;
                }
            }
            // GRACE 90s: WebView/HP sering jeda sesaat (throttle/blip) hingga
            // connection_aborted()=1 padahal user masih nunggu. Jangan bunuh
            // opencode langsung — beri tenggang, tetap heartbeat, baru kill
            // bila masih putus sehabis grace. Sesi serve tetap persisten.
            if(function_exists('connection_aborted')&& @ connection_aborted()=== 1) {
                if($clientGoneAt === 0) {
                    $clientGoneAt = time();
                    if(function_exists('termEmit'))termEmit('warn','📡 Koneksi frontend kedip — agent tetap jalan 90 dtk (grace)...');
                    if(function_exists('applog'))applog('OPENCODE_CLI','client_gone_grace',['grace' => 90]);
                } elseif((time()- $clientGoneAt)>= 90) {
                    $clientGone = true;
                    @ proc_terminate($proc,9);
                    if(function_exists('applog'))applog('OPENCODE_CLI','client_gone',['terminate' => true,'after_grace' => 90]);
                    break;
                }
            } else {
                $clientGoneAt = 0;
            }
            if(! $status['running'])break;
            if((time()- $lastBeat)>= 10) {
                $lastBeat = time();
                native_heartbeat();
            }
            if((time()- $lastDataBeat)>= 45 && (time()- $lastProgressTs)>= 45) {
                $lastDataBeat = time();
                if(function_exists('emit'))emit(['type' => 'status','phase' => 'thinking']);
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
                if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => "\n\n⚠️ **Timeout CLI** — proses molor tanpa progres cukup, dihentikan.\n"]]]]);
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
            if(function_exists('oc_thread_note_usage'))oc_thread_note_usage($threadId,$stepInMax,$usageTotal);
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
        $effMaxTry = $maxProxyTry;
        if($stepInMax >= 250000) {
            $effMaxTry = max(2,(int)round($maxProxyTry * 300000 / $stepInMax));
            if(function_exists('applog'))applog('NET','cli_retry_cap',['ctx_tokens' => $stepInMax,'cap' => $effMaxTry,'full' => $maxProxyTry]);
        }
        if($stepInMax >= 250000 && ! $sawContent) {
            $msg = "\n\nℹ️ **Konteks sesi sangat besar (≈ ".number_format($stepInMax)." input tokens)** — payload kegedean, provider bisa gagal balas. Saran:\n- Bikin **sesi baru** buat lanjut tugas ringan.\n";
            if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => $msg]]]]);
            if(function_exists('termEmit'))termEmit('info','ℹ️ Konteks sesi sangat besar (≈'.number_format($stepInMax).' tokens) — bikin sesi baru buat tugas gede.');
            $sawContent = true;
        }
        $usageOut = max($usageOut,0);
        $usageIn = max($usageIn,0);
        if(function_exists('oc_thread_note_usage'))oc_thread_note_usage($threadId,$stepInMax,$usageTotal);
        if($usageTotal > 0 && function_exists('emit')) {
            emit(['type' => 'usage','input_tokens' => $usageIn,'output_tokens' => $usageOut,'total_tokens' => $usageTotal]);
        }
        if(! $sawContent && ! $cliTimedOut) {
            $err = trim($stderrBuf);
            $exitTxt = $exitCode === - 1? 'signal':('exit '.$exitCode);
            // TRANSIENT first-boot: CLI kadang exit 0 tanpa satu byte pun
            // (engine dingin). Retry sekali otomatis sebelum nyerah.
            if($err === '' && $exitCode === 0 && $emptyRetry < 1) {
                $emptyRetry ++;
                if(function_exists('termEmit'))termEmit('retry','Respon kosong (engine dingin?) — coba sekali lagi otomatis...');
                if(function_exists('applog'))applog('OPENCODE_CLI','empty_retry',['try' => $proxyTry + 1]);
                if(function_exists('native_sleep_heartbeat'))native_sleep_heartbeat(2000);
                else usleep(2000000);
                continue;
            }
            $errIsLimit = preg_match('/\b(429|rate limit|rate-limit|ratelimit|quota|too many requests)\b/i',$err)=== 1;
            if($errIsLimit) {
                $msg = "\n\n⚠️ **Kena limit 429 nih, Coba pake VPN dulu, atau coba ganti ke provider gratis / berbayar lainnya lalu ketik lanjut**\n";
                if($err !== '')$msg .= "\n`".substr($err,0,500)."`\n";
                if(function_exists('termEmit'))termEmit('limit','Kena limit 429 (stderr CLI) — coba VPN dulu / ganti provider lalu ketik lanjut.');
            } else {
                $msg = "\n\n⚠️ **Stream kosong** — opencode CLI tidak menghasilkan teks (".$exitTxt."). Bisa jadi limit tersembunyi / engine dingin. Coba pake VPN dulu, atau coba ganti ke provider gratis / berbayar lainnya lalu ketik lanjut.\n";
                if($err !== '')$msg .= "\n`".substr($err,0,500)."`\n";
            }
            if(function_exists('emit'))emit(['choices' =>[['delta' =>['content' => $msg]]]]);
            if(function_exists('applog'))applog('OPENCODE_CLI',$errIsLimit? 'empty/exit-429': 'empty/exit',['exit' => $exitCode,'stderr' => substr($err,0,300)]);
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
        if(function_exists('ob_flush'))@ob_flush();
        if(function_exists('flush'))flush();
    }
}
function native_keepalive_note(string $detail = ''): void {
    if(! function_exists('emit'))return;
    if($detail !== '')emit(['type' => 'tool','phase' => 'start','id' => 'hb_'.substr(md5((string)microtime(true)),0,8),'name' => 'kerja','detail' => $detail]);
    native_heartbeat();
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
    return[['type' => 'function','function' =>['name' => 'shell','description' => 'Jalankan command shell di server. Untuk cek sistem, install, git, network, dsb. Output dibatasi, kalau butuh file spesifik pakai tool lain.','parameters' =>['type' => 'object','properties' =>['command' =>['type' => 'string','description' => 'Command shell lengkap'],'cwd' =>['type' => 'string','description' => 'Working directory (default ~/debz-ai)']],'required' =>['command']]]],['type' => 'function','function' =>['name' => 'read_file','description' => 'Baca isi file teks.','parameters' =>['type' => 'object','properties' =>['path' =>['type' => 'string']],'required' =>['path']]]],['type' => 'function','function' =>['name' => 'write_file','description' => 'Tulis/replace isi file (buat file baru kalau belum ada).','parameters' =>['type' => 'object','properties' =>['path' =>['type' => 'string'],'content' =>['type' => 'string']],'required' =>['path','content']]]],['type' => 'function','function' =>['name' => 'list_dir','description' => 'List isi folder.','parameters' =>['type' => 'object','properties' =>['path' =>['type' => 'string']],'required' =>['path']]]],['type' => 'function','function' =>['name' => 'search','description' => 'Cari file by nama (regex) atau isi file. Return daftar path.','parameters' =>['type' => 'object','properties' =>['pattern' =>['type' => 'string','description' => 'Regex'],'path' =>['type' => 'string','description' => 'Folder search (default ~/debz-ai)'],'content' =>['type' => 'boolean','description' => 'true = cari di isi file, false = nama file']],'required' =>['pattern']]]],['type' => 'function','function' =>['name' => 'http_request','description' => 'Fetch URL / panggil API (GET/POST/PUT/DELETE). Buat riset web, akses API luar, cek status, dsb. Body dibatasi ~256KB.','parameters' =>['type' => 'object','properties' =>['url' =>['type' => 'string'],'method' =>['type' => 'string','enum' =>['GET','POST','PUT','DELETE'],'description' => 'default GET'],'headers' =>['type' => 'object','description' => 'header tambahan (opsional)'],'body' =>['type' => 'string','description' => 'request body (opsional)'],'timeout' =>['type' => 'integer','description' => 'detik, max 60']],'required' =>['url']]]],['type' => 'function','function' =>['name' => 'download_file','description' => 'Download file dari URL ke path lokal. Buat ambil gambar, APK, dataset, dll.','parameters' =>['type' => 'object','properties' =>['url' =>['type' => 'string'],'path' =>['type' => 'string','description' => 'path tujuan HARUS kasih nama file, misal ~/path/to/file.zip'],'max_mb' =>['type' => 'integer','description' => 'batas ukuran MB, default 200']],'required' =>['url','path']]]],['type' => 'function','function' =>['name' => 'db_query','description' => 'Query SQLite (SELECT/INSERT/UPDATE/DELETE). Buat nyimpen data terstruktur, kalender, tracking, dll.','parameters' =>['type' => 'object','properties' =>['db_path' =>['type' => 'string','description' => 'path file .db/.sqlite'],'sql' =>['type' => 'string'],'params' =>['type' => 'array','description' => 'parameter query (opsional)']],'required' =>['db_path','sql']]]],['type' => 'function','function' =>['name' => 'archive','description' => 'Buat atau ekstrak arsip zip/tar/tar.gz. action create (perlu files) atau extract (perlu target_dir).','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['create','extract']],'archive_path' =>['type' => 'string'],'files' =>['type' => 'array','description' => '(create) daftar path file/folder'],'target_dir' =>['type' => 'string','description' => '(extract) folder tujuan']],'required' =>['action','archive_path']]]],['type' => 'function','function' =>['name' => 'process_list','description' => 'List proses yang berjalan. Bisa difilter pakai pattern (nama/pid).','parameters' =>['type' => 'object','properties' =>['pattern' =>['type' => 'string','description' => 'filter substring (opsional)']]]]],['type' => 'function','function' =>['name' => 'process_kill','description' => 'Kill proses by pid atau nama (pattern). signal default 15 (SIGTERM), pilihan 1/2/9/15.','parameters' =>['type' => 'object','properties' =>['pid' =>['type' => 'integer'],'pattern' =>['type' => 'string'],'signal' =>['type' => 'integer']]]]],['type' => 'function','function' =>['name' => 'note','description' => 'Memori persisten AI ke notes.db. action: list/get/add/delete/search. Buat nyimpen fakta/keputusan antar sesi.','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['list','get','add','delete','search']],'key' =>['type' => 'string','description' => 'nama unik note (buat add/get/delete)'],'content' =>['type' => 'string','description' => 'isi note (buat add)'],'pattern' =>['type' => 'string','description' => 'keyword cari (buat search)']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'skill','description' => 'Kelola knowledge base skills (folder skills/): list, search by keyword, get isi SKILL.md, create skill baru dari prosedur reusable, delete, stats. Pake create buat nyimpen prosedur/learning yang bisa dipake lagi di tugas serupa.','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['list','search','get','create','delete','stats']],'name' =>['type' => 'string','description' => 'nama skill (buat get/create/delete)'],'category' =>['type' => 'string','description' => 'kategori folder (buat create, default custom)'],'content' =>['type' => 'string','description' => 'isi SKILL.md lengkap (buat create)'],'pattern' =>['type' => 'string','description' => 'keyword cari (buat search)']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'app_install','description' => 'Manajemen package via apk/pkg: search / install / remove / update / installed. Buat nambahin tools ke sistem.','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['search','install','remove','update','installed']],'package' =>['type' => 'string','description' => 'nama paket (buat search/install/remove)']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'computer_use','description' => 'Kendali komputer via layar virtual (CUA driver): lihat screenshot, klik, ketik, scroll, drag, buka URL di browser. Gunakan screenshot dulu buat liat layar, tentuin koordinat, baru aksi.','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['status','screenshot','open','launch','click','dblclick','rightclick','move','drag','type','key','scroll','meta'],'description' => 'aksi yang mau dijalankan; screenshot buat liat layar dulu'],'url' =>['type' => 'string','description' => '(open) URL yang dibuka di browser virtual'],'cmd' =>['type' => 'string','description' => '(launch) command program di layar virtual'],'x' =>['type' => 'integer','description' => '(click/dblclick/rightclick/move) koordinat X'],'y' =>['type' => 'integer','description' => '(click/dblclick/rightclick/move) koordinat Y'],'button' =>['type' => 'integer','description' => '(click/drag) 1=kiri, 2=tengah, 3=kanan; default 1'],'x1' =>['type' => 'integer','description' => '(drag) X awal'],'y1' =>['type' => 'integer','description' => '(drag) Y awal'],'x2' =>['type' => 'integer','description' => '(drag) X akhir'],'y2' =>['type' => 'integer','description' => '(drag) Y akhir'],'duration' =>['type' => 'number','description' => '(drag) durasi detik, default 0.3'],'text' =>['type' => 'string','description' => '(type) teks yang diketik'],'key' =>['type' => 'string','description' => '(key) hotkey, contoh: ctrl+c, Return, alt+Tab'],'dx' =>['type' => 'integer','description' => '(scroll) horizontal, negatif=kanan'],'dy' =>['type' => 'integer','description' => '(scroll) 1=turun 0, -1=naik; default 1'],'times' =>['type' => 'integer','description' => '(scroll) berapa kali scroll, max 20']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'browser','description' => 'Browser automation Playwright + Chromium headless (daemon persistent port 9333): buka URL, baca konten, klik elemen, isi form, screenshot, jalanin JS. Cocok buat test frontend & ambil data halaman. Command: goto|content|text|title|screenshot|click|type|press|wait|eval|close. State browser kebawa antar step (daemon), jadi bisa buka -> klik -> isi -> cek -> screenshot berurutan.','parameters' =>['type' => 'object','properties' =>['command' =>['type' => 'string','enum' =>['goto','content','text','title','screenshot','click','type','press','wait','eval','close'],'description' => 'aksi yang mau dijalankan'],'url' =>['type' => 'string','description' => '(goto) URL tujuan, contoh https://example.com'],'selector' =>['type' => 'string','description' => '(click/type) CSS selector elemen, contoh button.login atau input#search'],'text' =>['type' => 'string','description' => '(type) teks untuk diisi ke input'],'key' =>['type' => 'string','description' => '(press) tombol, contoh Enter / Tab / Backspace'],'path' =>['type' => 'string','description' => '(screenshot) path PNG tujuan, default ~/Workspaces/screenshots/browser_shot.png'],'js' =>['type' => 'string','description' => '(eval) kode JavaScript yang dijalankan di halaman'],'ms' =>['type' => 'integer','description' => '(wait) jeda milidetik, default 1000'],'index' =>['type' => 'integer','description' => '(click) index elemen kalau selector match banyak, default 0']],'required' =>['command']]]],['type' => 'function','function' =>['name' => 'web_search','description' => 'Cari informasi di web (mesin DuckDuckGo). Buat riset, verifikasi fakta, cek berita, cari dokumentasi. Return daftar judul+URL+snippet.','parameters' =>['type' => 'object','properties' =>['query' =>['type' => 'string','description' => 'kata kunci pencarian'],'max_results' =>['type' => 'integer','description' => 'jumlah hasil, 1-15, default 6'],'timeout' =>['type' => 'integer','description' => 'timeout detik, 5-40, default 15']],'required' =>['query']]]],['type' => 'function','function' =>['name' => 'backup','description' => 'Backup & restore data Debz AI (notes.db, providers, config, skills, dll). action: create (buat backup baru), list (daftar backup), restore (pulihkan dari backup), delete (hapus backup).','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['create','list','restore','delete'],'description' => 'aksi yang mau dijalankan'],'name' =>['type' => 'string','description' => 'nama backup (buat create/restore/delete), opsional utk create']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'scheduler','description' => 'Kelola job terjadwal di server: list (lihat semua job), add (tambah job baru dengan schedule interval:DETIK atau cron 5-field), remove (hapus job by id), toggle (aktif/nonaktif), run (jalankan job sekarang).','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['list','add','remove','toggle','run'],'description' => 'aksi yang mau dijalankan'],'command' =>['type' => 'string','description' => '(add) command shell yang dijalankan'],'schedule' =>['type' => 'string','description' => '(add) jadwal: interval:DETIK atau cron 5-field (min hour dom month dow)'],'name' =>['type' => 'string','description' => '(add) nama job (opsional)'],'id' =>['type' => 'string','description' => '(remove/toggle/run) id job'],'timeout' =>['type' => 'integer','description' => '(add) timeout detik, 5-600, default 120']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'rag_query','description' => 'Cari di knowledge base via embedding semantic (RAG). Actions: status (cek isi KB), ingest (tambah dokumen, path wajib di bawah /opt/rag/docs), query (cari chunk relevan, return teks + skor cosine). Pakai saat user tanya hal yang ada di dokumen/knowledge base internal.','parameters' =>['type' => 'object','properties' =>['action' =>['type' => 'string','enum' =>['status','ingest','query']],'text' =>['type' => 'string','description' => 'pertanyaan/teks (wajib utk query/embed)'],'path' =>['type' => 'string','description' => 'path dokumen/folder utk ingest (di bawah ~/Workspaces/rag_docs)'],'top' =>['type' => 'integer','description' => 'jumlah hasil, default 5']],'required' =>['action']]]],['type' => 'function','function' =>['name' => 'screenshot','description' => 'Ambil screenshot layar virtual (CUA/Xvfb). Return path + base64 + dimensi. Cocok buat lihat kondisi GUI sebelum aksi computer_use.','parameters' =>['type' => 'object','properties' => (object)[]]]],['type' => 'function','function' =>['name' => 'android','description' => 'Jalankan perintah di sisi ANDROID (bukan proot): pm, dumpsys, settings, getprop, svc, input tap/swipe, am, cmd. HP rooted = jalan sebagai root (su). Beda dengan shell yang cuma di Linux proot. Output dibatasi.','parameters' =>['type' => 'object','properties' =>['command' =>['type' => 'string','description' => 'Perintah Android lengkap, mis. pm list packages | grep debz'],'timeout' =>['type' => 'integer','description' => 'Batas detik (default 60, maks 300)']],'required' =>['command']]]],];
}
function native_tool_endpoint(string $name):? string {
    static $map = ['shell' => 'exec','android' => 'android','read_file' => 'fs_read','write_file' => 'fs_write','list_dir' => 'fs_list','search' => 'fs_search','http_request' => 'http','download_file' => 'download','db_query' => 'db','archive' => 'archive','process_list' => 'ps','process_kill' => 'kill','note' => 'note','skill' => 'skill','app_install' => 'pkg','computer_use' => 'cua','browser' => 'browser','web_search' => 'web_search','backup' => 'backup','scheduler' => 'scheduler','screenshot' => 'screenshot','rag_query' => 'rag',];
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
function native_tools_ports(): array {
    $ports = [];
    foreach(['TOOLS_PORT','BACKEND_PORT'] as $k) {
        $v = (int)(getenv($k) ?: 0);
        if($v > 0 && $v < 65536)$ports[] = $v;
    }
    $f = __DIR__.'/.ai-config.ini';
    if(is_file($f) && is_readable($f)) {
        foreach((array)@file($f,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string)$line);
            if(stripos($line,'AI_TOOLS_PORT') !== 0)continue;
            $p = strpos($line,'=');
            if($p === false)continue;
            $v = (int)trim(substr($line,$p + 1));
            if($v > 0 && $v < 65536)$ports[] = $v;
        }
    }
    foreach(['/opt/debz/.ports.json',__DIR__.'/.ports.json'] as $pf) {
        if(! is_file($pf) || ! is_readable($pf))continue;
        $raw = @file_get_contents($pf);
        if($raw === false)continue;
        $d = json_decode($raw,true);
        if(! is_array($d))continue;
        foreach(['tools','api'] as $k) {
            $v = (int)($d[$k] ?? 0);
            if($k === 'tools' && $v > 0 && $v < 65536)$ports[] = $v;
        }
    }
    foreach([35189,9191] as $v)$ports[] = $v;
    $ports = array_values(array_unique(array_filter($ports)));
    return $ports !== [] ? $ports : [9191];
}
function native_call_tool(string $endpoint,array $args,& $approvalInfo = null): array {
    $token = native_tools_token();
    $payload = array_merge(['token' => $token],$args);
    $encoded = json_encode($payload,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if($encoded === false)return[false,['error' => 'tool payload JSON encode gagal: '.json_last_error_msg()]];
    $path = '/api/'.ltrim($endpoint,'/');
    $raw = false; $http = 0; $err = ''; $errno = 0; $tried = [];
    foreach(native_tools_ports() as $port) {
        $tried[] = $port;
        $base = 'http://127.0.0.1:'.$port.$path;
        $ch = curl_init($base);
        if($ch === false)continue;
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER => true,CURLOPT_POST => true,CURLOPT_HTTPHEADER =>['Content-Type: application/json','Accept: application/json','Connection: keep-alive'],CURLOPT_POSTFIELDS => $encoded,CURLOPT_CONNECTTIMEOUT => 5,CURLOPT_TIMEOUT => 300,CURLOPT_SSL_VERIFYPEER => false,CURLOPT_SSL_VERIFYHOST => 0,CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
        $res = curl_exec($ch);
        $http = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if($res !== false) { $raw = $res; break; }
        if(! in_array($errno,[7,28],true)) { $raw = false; break; }
    }
    if($raw === false)return[false,['error' => 'tool server unreachable (ports '.implode(',',$tried).'): '.($err !== ''? $err: 'cURL errno '.$errno)]];
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
        case 'android': return '📱 '.(isset($args['command'])? native_trunc((string)$args['command'],60): '');
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
        case 'android': $body = (string)($out['out']?? $out['stdout']?? '');
        $stderr = (string)($out['err']?? $out['stderr']?? '');
        if($stderr !== '')$body .= ($body !== ''? "\n[stderr]\n": '').native_trunc($stderr,2000);
        if(isset($out['exit']))$body .= "\n[exit ".(int)$out['exit'].(! empty($out['root'])? ' · root': '')."]";
        if(trim($body)=== '')return null;
        return['kind' => 'exec','path' => '📱 '.native_trunc(preg_replace('/\s+/',' ',(string)($args['command']?? '?')),36),'text' => native_trunc($body,15000)];
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
