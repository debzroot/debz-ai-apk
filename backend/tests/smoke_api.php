<?php // Smoke test API layer: providers, logger, sessions_idx, health cache.
$fail = 0;
function check($name,$cond,$detail='') {
    global $fail;
    echo ($cond ? 'PASS ' : 'FAIL ').$name.($cond ? '' : ' — '.$detail)."\n";
    if(!$cond) $fail++;
}
require_once __DIR__.'/../providers.php';
require_once __DIR__.'/../logger.php';
$d = providers_load();
check('providers load', is_array($d) && !empty($d['providers']), json_encode(array_keys($d)));
check('routing valid', in_array(providers_routing($d),['fixed','roundrobin','failover'],true));
$m = providers_mask($d);
$hasKey = false;
foreach(($m['providers'] ?? []) as $p) { if(!empty($p['has_key']) || ($p['mode'] ?? '') === 'opencode-cli') { $hasKey = true; break; } }
check('provider usable', $hasKey);
check('logger rotate fn', function_exists('applog_rotate'));
$bePy = (string)@file_get_contents(__DIR__.'/../backend.py');
$agPhp = (string)@file_get_contents(__DIR__.'/../agent.php');
check('sqlite WAL code', strpos($bePy,'journal_mode=WAL') !== false && strpos($agPhp,'journal_mode=WAL') !== false);
$sessPy = (string)@file_get_contents(__DIR__.'/../scripts/sessions_index.py');
check('sessions_idx code', strpos($bePy,'sessions_idx') !== false && strpos($sessPy,'sessions_idx') !== false);
try {
    $db = new PDO('sqlite:'.__DIR__.'/../notes.db');
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL;");
    $mode = $db->query('PRAGMA journal_mode;')->fetchColumn();
    check('sqlite WAL live', strtolower((string)$mode) === 'wal', 'mode='.$mode);
} catch(Throwable $e) { check('sqlite open', false, $e->getMessage()); }
check('heartbeat script', is_file(__DIR__.'/../scripts/provider_heartbeat.sh'));
check('c0n73xt retry', strpos((string)@file_get_contents(__DIR__.'/../c0n73xt.js'),'fetchChatWithBackoff') !== false);
check('retry max 5', strpos($agPhp,"AI_NET_RETRY',5") !== false);
echo $fail ? "\n$fail gagal\n" : "\nSEMUA LOLOS\n";
exit($fail ? 1 : 0);
