<?php
session_start ();
$ip = $_SERVER [ 'REMOTE_ADDR' ];
$is_local = in_array ($ip,  [ '0.0.0.0',  '::',  '::1' ] ) || $_SERVER [ 'SERVER_NAME' ] === 'localhost' || strpos ($ip,  '192.168.' ) === 0 || strpos ($ip,  '10.' ) === 0;
if (isset ($_GET [ 'logout' ] ) )
{
    $_SESSION = [ ];
    session_destroy ();
    header ('Location: ' . strtok ($_SERVER [ 'REQUEST_URI' ],  '?' ) );
    exit;
}
define ('AUTH_PASSWORD',  '1337' );
$ip = $_SERVER [ 'REMOTE_ADDR' ] ?? '0.0.0.0';
$lock_file = sys_get_temp_dir () . '/lock_' . md5 ($ip ) . '.json';
$max_attempts = 5;
$lockout_time = 900;
$attempts = 0;
$last_attempt = 0;
$error = '';
if (file_exists ($lock_file ) )
{
    $data = json_decode (file_get_contents ($lock_file ),  true );
    $attempts = $data [ 'attempts' ] ?? 0;
    $last_attempt = $data [ 'last_attempt' ] ?? 0;
    if ($attempts >= $max_attempts && (time () - $last_attempt ) < $lockout_time )
    {
        http_response_code (429 );
        die ('<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Locked</title><style>body{background:#141517;color:#f87171;font-family:ui-monospace,Menlo,Consolas,monospace;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;text-align:center;}</style></head><body><div><h2>Akses Diblokir</h2><p>Terlalu banyak percobaan. Coba lagi nanti.</p></div></body></html>' );
    }
    elseif ((time () - $last_attempt ) >= $lockout_time )
    {
        $attempts = 0;
    }
}
if (empty ($_SESSION [ 'csrf_token' ] ) )
{
    $_SESSION [ 'csrf_token' ] = bin2hex (random_bytes (32 ) );
}
if ($_SERVER [ 'REQUEST_METHOD' ] === 'POST' && isset ($_POST [ 'auth_password' ],  $_POST [ 'csrf_token' ] ) )
{
    if (hash_equals ($_SESSION [ 'csrf_token' ],  $_POST [ 'csrf_token' ] ) )
    {
        if (hash_equals (hash ('sha256',  AUTH_PASSWORD ),  hash ('sha256',  $_POST [ 'auth_password' ] ) ) )
        {
            $_SESSION [ 'authenticated' ] = true;
            session_regenerate_id (true );
            if (file_exists ($lock_file ) ) unlink ($lock_file );
            header ('Location: ' . $_SERVER [ 'REQUEST_URI' ] );
            exit;
        }
        else
        {
            $attempts ++;
            file_put_contents ($lock_file,  json_encode ([ 'attempts' => $attempts,  'last_attempt' => time () ] ) );
            $error = 'Akses Ditolak.';
        }
    }
    else
    {
        $error = 'Sesi tidak valid.';
    }
}
if (empty ($_SESSION [ 'authenticated' ] ) )
{

?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>Restricted Access</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; padding: 0; height: 100vh; display: flex; justify-content: center; align-items: center; background: #141517; font-family: ui-monospace, 'SF Mono', 'Cascadia Mono', Menlo, Consolas, 'Roboto Mono', monospace; font-size: 14px; }
            .login-box { background: #1d1f24; border: 1px solid #454852; padding: 30px; border-radius: 4px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); width: 90%; max-width: 350px; text-align: center; }
            .login-box h2 { color: #4ade80; margin-top: 0; font-size: 13px; margin-bottom: 22px; letter-spacing: 2px; text-transform: uppercase; }
            .input-group { margin-bottom: 15px; }
            .input-group input { width: 100%; padding: 12px; background: #0f1012; border: 1px solid #454852; color: #e2e4e9; border-radius: 2px; font-family: inherit; font-size: 13px; outline: none; transition: 0.15s; text-align: center; }
            .input-group input:focus { border-color: #4ade80; box-shadow: 0 0 0 2px rgba(74, 222, 128, 0.18); }
            button { width: 100%; padding: 12px; background: rgba(74, 222, 128, 0.12); color: #4ade80; border: 1px solid #4ade80; border-radius: 2px; font-family: inherit; font-size: 13px; font-weight: 700; letter-spacing: 2px; cursor: pointer; transition: 0.15s; text-transform: uppercase; }
            button:hover { background: rgba(74, 222, 128, 0.2); box-shadow: 0 0 12px rgba(74, 222, 128, 0.25); }
            .error { color: #f87171; font-size: 12px; margin-bottom: 15px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>AUTHENTICATION REQUIRED</h2>
            <?php
    if ($error ) :

?>
<div class="error"><?=     htmlspecialchars ($error )

?>
</div><?php
    endif;

?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?=     htmlspecialchars ($_SESSION [ 'csrf_token' ] )

?>
">
                <div class="input-group">
                    <input type="password" name="auth_password" placeholder="Password" required autofocus>
                </div>
                <button type="submit">ENTER</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}
if (! defined ('BASE_URL' ) ) define ('BASE_URL',  '' );
if (! defined ('ROOT_PATH' ) ) define ('ROOT_PATH',  __DIR__ );
$page_title = $page_title ?? 'c0n73xt';
$page_desc = $page_desc ?? 'Priv8 c0n73xt';
$page_path = $page_path ?? '♾️ C0N73XT';

header ('Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
header ('Pragma: no-cache' );
header ('Expires: 0' );
function get_file_version ($path )
{
    
    $bases = [ ROOT_PATH,  dirname (ROOT_PATH ) ];
    foreach ($bases as $base )
    {
        $full_path = $base . $path;
        if (file_exists ($full_path ) ) return filemtime ($full_path );
    }
    return time ();
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars ($page_title )

?>
</title>
    <link rel="icon" type="image/png" sizes="192x192" href="<?= BASE_URL

?>
/appicons/icon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= BASE_URL

?>
/appicons/apple-touch-icon.png">
    <meta name="theme-color" content="#141517">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="c0n73xt">
    <link rel="manifest" href="<?= BASE_URL

?>
/manifest.json?v=<?= get_file_version ('/manifest.json' )

?>
">
    <link href="<?= BASE_URL

?>
/css/external/jetbrains-mono.css?v=<?= get_file_version ('/css/external/jetbrains-mono.css' )

?>
" rel="stylesheet">
    <link href="<?= BASE_URL

?>
/css/external/b3fd2d7bf9-atom-one-dark.min.css?v=<?= get_file_version ('/css/external/b3fd2d7bf9-atom-one-dark.min.css' )

?>
" rel="stylesheet">
    <link href="<?= BASE_URL

?>
/c0n73xt.css?v=<?= get_file_version ('/c0n73xt.css' )

?>
" rel="stylesheet">

<script>
// Theme anti-flicker: set attribute sebelum CSS render
(function(){
    try {
        if (localStorage.getItem('c0n73xt-theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    } catch(e) {}
})();
</script>
</head>
<body>

    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <aside class="session-sidebar" id="session-sidebar" aria-label="Daftar session chat">
        <div class="sidebar-header">
            <div class="sidebar-title-wrap">
                <span class="sidebar-logo">🗂️</span>
                <span class="sidebar-title">Sessions</span>
            </div>
            <button type="button" class="sidebar-close" id="sidebar-close" title="Tutup sidebar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <button type="button" class="new-chat-btn" id="new-chat-btn" title="Buat chat baru">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Chat Baru</span>
        </button>
        <div class="session-list" id="session-list"></div>
        <div class="sidebar-tools">
            <div class="sidebar-tools-title">⚡ Debz_AI Control</div>
            <div class="sidebar-tools-grid">
                <button type="button" id="tools-toggle" class="st-btn" title="Agent Tools ON/OFF (shell, file, search)"><span class="st-ico">⚡</span><span class="st-lbl">Tools</span></button>
                <button type="button" id="allowall-toggle" class="st-btn" title="Allow All — auto-approve perintah berbahaya"><span class="st-ico">🔓</span><span class="st-lbl">AllowAll</span></button>
                <button type="button" id="provider-btn" class="st-btn" title="Ganti Provider / Model"><span class="st-ico">🧠</span><span class="st-lbl">Provider</span></button>
                <button type="button" id="compact-btn" class="st-btn" title="Ringkas context jadi summary (hemat token)"><span class="st-ico">🗜️</span><span class="st-lbl">Compact</span></button>
                <button type="button" id="settings-btn" class="st-btn" title="Settings Provider"><span class="st-ico">⚙️</span><span class="st-lbl">Settings</span></button>
                <button type="button" id="proxy-btn" class="st-btn" title="Proxy — klik atur di Settings"><span class="st-ico">🕵️</span><span class="st-lbl">Proxy</span></button>
                <button type="button" id="export-btn" class="st-btn" title="Export Chat"><span class="st-ico">📤</span><span class="st-lbl">Export</span></button>
                <button type="button" id="clear-btn" class="st-btn" title="Bersihin Chat Session Ini"><span class="st-ico">🧹</span><span class="st-lbl">Clear</span></button>
                <button type="button" id="android-term-btn" class="st-btn" title="Terminal HP — shell proot" hidden><span class="st-ico">📟</span><span class="st-lbl">Terminal</span></button>
                <a href="?logout=1" class="st-btn logout-btn" title="Keluar / Logout"><span class="st-ico">🚪</span><span class="st-lbl">Keluar</span></a>
            </div>
            <div class="sidebar-status" id="sidebar-status">Debz AI · Siap ✨</div>
            <div class="sidebar-device" id="sidebar-device" hidden></div>
        </div>
        <div class="sidebar-footer">
            <span class="sidebar-count" id="session-count">0 sessions</span>
            <span class="sidebar-hint">geser kiri / ESC untuk tutup</span>
        </div>
    </aside>

    <div class="article-wrapper">
        <div class="chat-header">
            <div class="header-left">
                <button type="button" class="header-btn sidebar-toggle" id="sidebar-toggle" title="Sessions / Riwayat Chat">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
                </button>
                <span class="status-dot"></span>
                <span class="status-text"><strong>️Debz 👾</strong></span>
            </div>
            <div class="header-right" style="display: flex; gap: 8px; align-items: center; flex-shrink: 0;">
                <button type="button" class="header-btn install-btn" id="install-btn" title="Install sebagai App (PWA)" hidden>Install</button>
                <span id="proxy-badge" title="Proxy aktif yang dipakai chat sekarang — klik untuk atur" style="display:none;font-size:10px;font-weight:700;padding:4px 10px;border-radius:10px;border:1px solid #4ade80;color:#4ade80;background:rgba(74,222,128,0.12);white-space:nowrap;max-width:220px;overflow:hidden;text-overflow:ellipsis;cursor:pointer" onclick="(function(){var b=document.getElementById('proxy-btn');if(b)b.click();})()">🌐 -</span>
                <button type="button" class="header-btn theme-toggle" id="theme-toggle" title="Mode Gelap">🌙</button>

                <button type="button" class="header-btn pane-toggle" id="act-pane-toggle" title="Live Agent — show/hide jendela review">A_</button>
                <button type="button" class="header-btn term-toggle" id="term-btn" title="Terminal Live — lihat aktivitas agent real-time">&gt;_</button>
            </div>
        </div>



    <!-- Live Agent pane — jendela fixed: view CARI/BACA/EDIT/SHELL -->
    <div class="act-pane" id="act-pane" hidden>
        <div class="act-pane-head">
            <span class="act-pane-dot" id="act-pane-dot"></span>
            <span class="act-pane-title">Live Agent</span>
            <span class="act-pane-meta" id="act-pane-meta">idle</span>
            <button type="button" class="act-pane-btn" id="act-pane-close" title="Sembunyikan (nongol lagi pas ada review baru)">✕</button>
        </div>
        <div class="act-pane-body" id="act-pane-body"></div>
    </div>

        <!-- Dropdown provider/model -->
        <div class="provider-pop" id="provider-pop" hidden>
            <div class="provider-pop-title">Provider / Model</div>
            <div id="provider-list" class="provider-list"></div>
        </div>

        <!-- Panel settings provider -->
        <div class="settings-backdrop" id="settings-backdrop" hidden></div>
        <div class="settings-panel" id="settings-panel" hidden>
            <div class="settings-head">
                <span>⚙️ Provider Settings</span>
                <button type="button" id="settings-close" title="Tutup">✕</button>
            </div>
            <div class="settings-body">
                <div id="settings-providers"></div>
                <button type="button" class="settings-add" id="settings-add">+ Tambah Provider</button>
                <div id="mp-box" style="margin-top:14px;border-top:1px solid var(--ink);padding-top:10px;">
                    <div style="font-weight:700;margin-bottom:6px;">🌐 Proxy (HP)</div>
                    <div id="mp-auto-row" style="display:flex;gap:8px;align-items:center;font-size:12.5px;margin-bottom:8px;">
                        <label style="display:flex;gap:6px;align-items:center;cursor:pointer;">
                            <input type="checkbox" id="mp-auto" checked /> Auto (grabber refresh tiap 60 dtk)
                        </label>
                        <span id="mp-auto-status" style="font-size:12px;"></span>
                    </div>
                    <div style="font-size:11.5px;color:var(--txt-dim);line-height:1.5;margin-bottom:8px;">Auto mati + manual kosong = direct. Manual mengalahkan auto.</div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                        <select id="mp-type" style="flex:1;min-width:90px;">
                            <option value="http">http</option>
                            <option value="socks5">socks5</option>
                            <option value="socks4">socks4</option>
                        </select>
                        <input id="mp-host" placeholder="host / IP proxy manual" autocomplete="off" style="flex:2;min-width:130px;" />
                        <input id="mp-port" placeholder="port" inputmode="numeric" autocomplete="off" style="flex:1;min-width:70px;" />
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                        <input id="mp-user" placeholder="user (opsional)" autocomplete="off" style="flex:1;min-width:100px;" />
                        <input id="mp-pass" type="password" placeholder="pass (opsional)" autocomplete="off" style="flex:1;min-width:100px;" />
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                        <button type="button" id="mp-save">💾 Simpan &amp; Tes</button>
                        <button type="button" id="mp-clear">🗑️ Hapus manual</button>
                        <span id="mp-status" style="font-size:12px;"></span>
                    </div>
                </div>
                <script>
                (function(){
                    var box = document.getElementById('mp-box');
                    if (!box) return;
                    function $(id){ return document.getElementById(id); }
                    function paintManual(m){
                        var st = $('mp-status');
                        if (!m || m.set === false) {
                            st.style.color = '';
                            st.textContent = 'Manual: kosong';
                            return;
                        }
                        if (m.live) {
                            st.style.color = 'var(--green)';
                            st.textContent = '● Manual LIVE ' + m.type + '://' + m.host + ':' + m.port;
                        } else {
                            st.style.color = 'var(--red)';
                            st.textContent = '● Manual MATI (' + (m.probe || '?') + ')';
                        }
                    }
                    function paintAuto(a){
                        var st = $('mp-auto-status');
                        var cb = $('mp-auto');
                        if (!a) return;
                        cb.checked = !!a.on;
                        if (!a.on) {
                            st.style.color = '';
                            st.textContent = 'mati';
                        } else if (a.pool_n > 0) {
                            st.style.color = 'var(--green)';
                            st.textContent = '● ' + a.pool_n + ' live' + (a.age_s >= 0 ? ' (' + a.age_s + ' dtk lalu)' : '');
                        } else {
                            st.style.color = 'var(--red)';
                            st.textContent = '● pool kosong (grabber jalan?)';
                        }
                    }
                    function paint(d){
                        if (!d) return;
                        paintManual(d.manual);
                        paintAuto(d.auto);
                    }
                    function load(){
                        fetch('api.php?action=manual_proxy', {cache:'no-store'})
                            .then(function(r){ return r.json(); })
                            .then(function(d){
                                if (d && d.manual && d.manual.set) {
                                    $('mp-type').value = d.manual.type || 'http';
                                    $('mp-host').value = d.manual.host || '';
                                    $('mp-port').value = d.manual.port || '';
                                    $('mp-user').value = '';
                                    $('mp-pass').value = '';
                                    $('mp-pass').placeholder = d.manual.has_auth ? '******** (tersimpan)' : 'pass (opsional)';
                                }
                                paint(d);
                            })
                            .catch(function(){ $('mp-status').textContent = 'gagal baca status proxy'; });
                    }
                    $('mp-save').onclick = function(){
                        $('mp-status').textContent = 'mengetes…';
                        fetch('api.php?action=manual_proxy', {
                            method:'POST',
                            headers:{'Content-Type':'application/json'},
                            body: JSON.stringify({
                                type: $('mp-type').value,
                                host: $('mp-host').value,
                                port: $('mp-port').value,
                                user: $('mp-user').value,
                                pass: $('mp-pass').value
                            })
                        }).then(function(r){ return r.json(); })
                          .then(function(d){
                              if (!d.success) { $('mp-status').style.color = 'var(--red)'; $('mp-status').textContent = d.error || 'gagal simpan'; return; }
                              if (d.manual && d.manual.set) {
                                  $('mp-user').value = '';
                                  $('mp-pass').value = '';
                              }
                              paint(d);
                              if (window.showToast) window.showToast(d.message || 'proxy tersimpan');
                          })
                          .catch(function(){ $('mp-status').textContent = 'gagal simpan proxy'; });
                    };
                    $('mp-clear').onclick = function(){
                        fetch('api.php?action=manual_proxy', {
                            method:'POST',
                            headers:{'Content-Type':'application/json'},
                            body: JSON.stringify({op:'clear'})
                        }).then(function(r){ return r.json(); })
                          .then(function(d){
                              $('mp-host').value=''; $('mp-port').value=''; $('mp-user').value=''; $('mp-pass').value='';
                              $('mp-pass').placeholder = 'pass (opsional)';
                              paint(d);
                              if (window.showToast) window.showToast(d.message || 'manual dihapus');
                          })
                          .catch(function(){ $('mp-status').textContent = 'gagal hapus proxy'; });
                    };
                    $('mp-auto').onchange = function(){
                        fetch('api.php?action=manual_proxy', {
                            method:'POST',
                            headers:{'Content-Type':'application/json'},
                            body: JSON.stringify({op:'auto', on: $('mp-auto').checked})
                        }).then(function(r){ return r.json(); })
                          .then(function(d){ paint(d); })
                          .catch(function(){ load(); });
                    };
                    var sb = document.getElementById('settings-btn');
                    if (sb) sb.addEventListener('click', load);
                    load();
                    setInterval(function(){
                        var p = document.getElementById('settings-panel');
                        if (p && !p.hidden) load();
                    }, 15000);
                })();
                </script>
            </div>
        </div>

        <div class="chat-stream" id="chat-stream">
            <div class="chat-empty" id="chat-empty">
                <div class="empty-avatar">🤖</div>
                <div id="typing-container" class="typing-container"></div>
            </div>
        </div>

        <div class="progress-bar-wrap" id="progress-bar-wrap">
            <div class="progress-spinner" id="progress-spinner"></div>
            <span class="progress-emoji" id="progress-emoji">⏳</span>
            <span class="progress-label" id="progress-label">Proses...</span> 
        </div>

        <form class="chat-composer" id="composer" autocomplete="off">
     <!-- Tombol Scroll to Top & Bottom (Kiri-Tengah dengan Auto-Hide) -->
    <div class="scroll-nav-chat" id="scrollNavContainer">
        <button type="button" class="scroll-nav-btn" id="scrollTopBtn" title="Scroll ke Atas">^</button>
        <button type="button" class="scroll-nav-btn" id="scrollBottomBtn" title="Scroll ke Bawah">v</button>
    </div>
            <div id="attachment-preview" style="display: none; padding: 8px; gap: 8px; overflow-x: auto; align-items: center;"></div>
            <div class="composer-row"> 
                <button type="button" id="upload-btn" title="Upload File" style="background: transparent; border: none; color: #86868b; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 8px; border-radius: 50%; transition: 0.2s;">
                    <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2.2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                </button>
                <input type="file" id="file-input" multiple accept="image/*,.pdf,.txt" style="display: none;">
  
                <textarea id="message-input" placeholder="✏️️" rows="1"></textarea>
                <button type="submit" id="send-btn">
                    <span id="send-arrow">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 19V5M5 12l7-7 7 7"/>
                        </svg>
                    </span>
                    <span class="send-loader" id="send-loader" style="display:none"></span>
                </button>
            </div>
        </form>
    </div>




    <!-- Terminal Live Modal (Console >_) -->
        <div class="term-backdrop" id="term-backdrop" hidden></div>
        <div class="term-modal" id="term-modal" hidden role="dialog" aria-label="Terminal Live Agent">
            <div class="term-head">
                <span class="term-dot"></span>
                <span class="term-title">DEBZ·TERM</span>
                <span class="term-meta" id="term-meta">idle</span>
                <button type="button" class="term-btn" id="term-clear" title="Bersihin log">🧹</button>
                <button type="button" class="term-btn" id="term-close" title="Tutup">✕</button>
            </div>
            <div class="term-body" id="term-body"></div>
            	
            <div class="toast" id="toast"></div>

    <script src="<?= BASE_URL

?>
/js/external/a46e01eb6c-highlight.min.js?v=<?= get_file_version ('/js/external/a46e01eb6c-highlight.min.js' )

?>
"></script>
    <script src="<?= BASE_URL

?>
/c0n73xt.js?v=<?= get_file_version ('/c0n73xt.js' )

?>
"></script>

<script>
// APK/HP: tidak ada Deproxy :8766 maupun proxy_start.php (desktop-only).
// Tombol sidebar dialihkan ke section Proxy di Settings — yang beneran
// jalan (auto-grabber + manual). Tanpa ini tombol = mati total.
(function(){
    var b = document.getElementById('proxy-btn');
    if (!b) return;
    function setBtn(on){
        b.classList.toggle('on', !!on);
        b.title = on ? 'Proxy AKTIF — klik atur di Settings' : 'Proxy — klik atur di Settings';
    }
    window.DEBZ_setProxyBtn = setBtn;
    b.addEventListener('click', function(){
        var bd = document.getElementById('settings-backdrop');
        var p = document.getElementById('settings-panel');
        if (bd) bd.hidden = false;
        if (p) {
            p.hidden = false;
            var mp = document.getElementById('mp-box');
            if (mp && mp.scrollIntoView) {
                try { mp.scrollIntoView({block:'start'}); } catch(e) {}
            }
        }
        fetch('api.php?action=manual_proxy', {cache:'no-store'})
            .then(function(r){ return r.json(); })
            .then(function(d){
                var live = false;
                if (d) {
                    if (d.manual && d.manual.set && d.manual.live) live = true;
                    if (d.auto && d.auto.on && d.auto.pool_n > 0) live = true;
                }
                setBtn(live);
            })
            .catch(function(){});
    });
    fetch('api.php?action=manual_proxy', {cache:'no-store'})
        .then(function(r){ return r.json(); })
        .then(function(d){
            var live = false;
            if (d) {
                if (d.manual && d.manual.set && d.manual.live) live = true;
                if (d.auto && d.auto.on && d.auto.pool_n > 0) live = true;
            }
            setBtn(live);
        })
        .catch(function(){});
})();

</script>

<script>
// Dark / Light mode toggle — persist di localStorage
(function(){
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    var meta = document.querySelector('meta[name="theme-color"]');
    function apply(t){
        var dark = (t === 'dark');
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        btn.textContent = dark ? '☀️' : '🌙';
        btn.title = dark ? 'Mode Terang' : 'Mode Gelap';
        if (meta) meta.setAttribute('content', dark ? '#1B1915' : '#141517');
        try { localStorage.setItem('c0n73xt-theme', dark ? 'dark' : 'light'); } catch(e) {}
    }
    btn.addEventListener('click', function(){
        var cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        apply(cur === 'dark' ? 'light' : 'dark');
    });
    apply(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
})();
</script>
<script>
(function() {
    const chatStream = document.getElementById('chat-stream');
    const navContainer = document.getElementById('scrollNavContainer');
    const btnTop = document.getElementById('scrollTopBtn');
    const btnBottom = document.getElementById('scrollBottomBtn');
    let isAutoScrolling = false;
    let scrollTimeout = null;

    if (!chatStream || !navContainer || !btnTop || !btnBottom) return;

    // Fungsi memunculkan tombol saat scroll, lalu otomatis menyembunyikan diri setelah diam
    function handleScrollVisibility() {
        navContainer.classList.add('visible');

        clearTimeout(scrollTimeout);
        scrollTimeout = setTimeout(() => {
            if (!isAutoScrolling) {
                navContainer.classList.remove('visible');
            }
        }, 1800); // Tombol hilang setelah 1.8 detik tidak ada aktivitas scroll
    }

    function smoothScrollContainer(targetY) {
        isAutoScrolling = true;
        navContainer.classList.add('visible'); // Pastikan tetap muncul saat auto-scroll

        chatStream.scrollTo({
            top: targetY,
            behavior: 'smooth'
        });

        setTimeout(() => {
            isAutoScrolling = false;
            // Cek apakah setelah auto-scroll selesai, mouse/layar sedang tidak aktif
            scrollTimeout = setTimeout(() => {
                navContainer.classList.remove('visible');
            }, 1000);
        }, 1500);
    }

    // Event Klik Tombol
    btnTop.addEventListener('click', (e) => {
        e.stopPropagation();
        smoothScrollContainer(0);
    });

    btnBottom.addEventListener('click', (e) => {
        e.stopPropagation();
        smoothScrollContainer(chatStream.scrollHeight);
    });

    // Tap/klik di layar untuk stop auto-scroll sekaligus memicu visibilitas
    function stopAutoScroll() {
        if (isAutoScrolling) {
            chatStream.scrollTo({
                top: chatStream.scrollTop,
                behavior: 'auto'
            });
            isAutoScrolling = false;
        }
    }

    // Listener interaksi
    chatStream.addEventListener('scroll', handleScrollVisibility, { passive: true });
    chatStream.addEventListener('touchstart', () => {
        stopAutoScroll();
        handleScrollVisibility();
    }, { passive: true });
    
    chatStream.addEventListener('mousedown', () => {
        stopAutoScroll();
        handleScrollVisibility();
    });
    
    chatStream.addEventListener('wheel', () => {
        stopAutoScroll();
        handleScrollVisibility();
    }, { passive: true });
})();
</script>
</body>
</html>