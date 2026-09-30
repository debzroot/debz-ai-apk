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
                <button type="button" id="proxy-btn" class="st-btn" title="Proxy Manager — grab proxy fresh & route chat (bypass rate limit)"><span class="st-ico">🕵️</span><span class="st-lbl">Proxy</span></button>
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
                <span id="proxy-badge" title="Proxy aktif yang dipakai chat sekarang" style="display:none;font-size:10px;font-weight:700;padding:4px 10px;border-radius:10px;border:1px solid #4ade80;color:#4ade80;background:rgba(74,222,128,0.12);white-space:nowrap;max-width:220px;overflow:hidden;text-overflow:ellipsis;cursor:pointer" onclick="window.open('http://'+location.hostname+':8766/','_blank')">🌐 -</span>
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
// Proxy Manager — klik sidebar = HANYA nyalain service + buka page Deproxy.
// Stop/kill service (port + grabber) ada di tombol dalam page Deproxy.
(function(){
    var b = document.getElementById('proxy-btn');
    if (!b) return;
    function setBtn(on){
        b.classList.toggle('on', !!on);
        b.title = on
            ? 'Proxy Manager AKTIF — klik buka Deproxy (stop service ada di dalam page)'
            : 'Proxy Manager — nyalakan service & buka Deproxy';
    }
    window.DEBZ_setProxyBtn = setBtn;
    b.addEventListener('click', function(){
        var proxyPort = (window.DEBZ_PROXY_PORT || '8766');
        var base = 'http://' + location.hostname + ':' + proxyPort;
        function openDash(){ window.open(base + '/', '_blank'); }
        function tryHealth(retry){
            fetch(base + '/api/health', {method:'GET', cache:'no-store'})
                .then(function(r){ return r.ok; })
                .catch(function(){ return false; })
                .then(function(alive){
                    if (alive) { setBtn(true); openDash(); return; } // udah nyala → langsung buka
                    if (retry >= 4) { openDash(); return; } // fallback: buka saja
                    // minta backend hidupkan (aman: hanya localhost/LAN)
                    fetch('/proxy_start.php', {method:'POST'}).catch(function(){});
                    setTimeout(function(){ tryHealth(retry + 1); }, 900);
                });
        }
        tryHealth(0);
    });
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