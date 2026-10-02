package ai.debz;

import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.provider.Settings;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.webkit.JavascriptInterface;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

public class MainActivity extends Activity {
    private static final int REQ_STORAGE = 41;
    private static final int REQ_FILE = 42;
    private static final long POLL_MS = 2000;

    private WebView web;
    // Pending <input type="file"> dari webui. Tanpa onShowFileChooser di
    // WebChromeClient, WebView cuma diem: tap tombol upload ga nge-trigger
    // picker sama sekali, jadi userikir tombolnya rusak.
    private ValueCallback<Uri[]> fileChooser;
    private LinearLayout splash;
    private ProgressBar splashBar;
    private TextView splashStage;
    private WebView autoWeb;
    private Handler poll;
    private boolean webLoaded;
    private boolean webError;
    private int webRetries;
    // kapan stage kerja terakhir dimulai (elapsedRealtime). Splash wajib
    // nunjukin detik berjalan — bar indeterminate yang diem = user kira
    // hang ("efeknya udah di ujung"). Detik yang jalan = bukti hidup.
    private long bootT0;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        Intent svc = new Intent(this, BootstrapService.class);
        svc.putExtra(BootstrapService.EXTRA_OFFSET, DebzConfig.portOffset(this));
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(svc);
        } else {
            startService(svc);
        }

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);

        // splash minimalis: progress loading doang, tanpa tombol apapun.
        // 100% = langsung buka chat, tanpa tap reload manual.
        splash = new LinearLayout(this);
        splash.setOrientation(LinearLayout.VERTICAL);
        splash.setGravity(android.view.Gravity.CENTER);
        int splashPad = dp(32);
        splash.setPadding(splashPad, splashPad, splashPad, splashPad);
        TextView splashTitle = new TextView(this);
        splashTitle.setText("\uD83D\uDC7E Debz AI");
        splashTitle.setTextSize(28);
        splashTitle.setGravity(android.view.Gravity.CENTER);
        splashStage = new TextView(this);
        splashStage.setTextSize(14);
        splashStage.setGravity(android.view.Gravity.CENTER);
        splashStage.setPadding(0, dp(8), 0, dp(16));
        splashBar = new ProgressBar(this, null,
            android.R.attr.progressBarStyleHorizontal);
        splashBar.setMax(100);
        splashBar.setLayoutParams(new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT));
        splash.addView(splashTitle);
        splash.addView(splashStage);
        splash.addView(splashBar);

        web = new WebView(this);
        web.addJavascriptInterface(new AndroidBridge(), "DebzAndroid");
        // WAJIB: tanpa WebChromeClient, JS confirm()/alert() mati total
        // (return false diam-diam) -> hapus session/clear/compact ga bisa.
        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView view,
                                             ValueCallback<Uri[]> callback,
                                             FileChooserParams params) {
                if (fileChooser != null) fileChooser.onReceiveValue(null);
                fileChooser = callback;
                try {
                    Intent pick = new Intent(params.createIntent());
                    pick.addCategory(Intent.CATEGORY_OPENABLE);
                    pick.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
                    startActivityForResult(
                        Intent.createChooser(pick, "Pilih gambar"),
                        REQ_FILE);
                    return true;
                } catch (Exception e) {
                    fileChooser = null;
                    android.util.Log.w("DebzAI", "file chooser gagal: " + e);
                    Toast.makeText(MainActivity.this,
                        "Gak ada app picker file", Toast.LENGTH_SHORT).show();
                    return false;
                }
            }

            @Override
            public boolean onJsConfirm(android.webkit.WebView view, String url,
                                       String message, android.webkit.JsResult result) {
                new android.app.AlertDialog.Builder(MainActivity.this)
                    .setMessage(message)
                    .setPositiveButton("Ya", (d, w) -> result.confirm())
                    .setNegativeButton("Batal", (d, w) -> result.cancel())
                    .setOnCancelListener(d -> result.cancel())
                    .show();
                return true;
            }

            @Override
            public boolean onJsAlert(android.webkit.WebView view, String url,
                                     String message, android.webkit.JsResult result) {
                new android.app.AlertDialog.Builder(MainActivity.this)
                    .setMessage(message)
                    .setPositiveButton("OK", (d, w) -> result.confirm())
                    .setOnCancelListener(d -> result.cancel())
                    .show();
                return true;
            }

            @Override
            public boolean onConsoleMessage(android.webkit.ConsoleMessage msg) {
                android.util.Log.i("DebzAI-Web",
                    msg.message() + " (" + msg.sourceId() + ":" + msg.lineNumber() + ")");
                return true;
            }
        });
        WebSettings ws = web.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        ws.setCacheMode(WebSettings.LOAD_NO_CACHE);
        ws.setJavaScriptCanOpenWindowsAutomatically(false);
        web.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedError(WebView view, WebResourceRequest request,
                                        WebResourceError error) {
                // subresource (favicon/img) gagal = JANGAN timpuk seluruh
                // chat. Cuma main frame yang boleh ganti halaman + retry.
                if (request != null && !request.isForMainFrame()) return;
                webError = true;
                view.loadData("<h3>Backend belum siap.</h3>"
                    + "<p>Status: " + DebzConfig.status(MainActivity.this) + "</p>"
                    + "<p>" + hintFor(DebzConfig.status(MainActivity.this)) + "</p>"
                    + "<p>Mencoba ulang otomatis\u2026</p>",
                    "text/html", "utf-8");
            }
        });

        root.addView(splash, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        web.setVisibility(View.GONE);
        root.addView(web, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        // WebView otomasi 1px buat CUA/browser via CDP (cdp_chrome.py):
        // debugging aktif -> socket webview_devtools_remote_<pid>, bisa
        // diakses python proot (uid sama) tanpa root/Chrome eksternal.
        WebView.setWebContentsDebuggingEnabled(true);
        autoWeb = new WebView(this);
        autoWeb.getSettings().setJavaScriptEnabled(true);
        autoWeb.getSettings().setDomStorageEnabled(true);
        autoWeb.setWebViewClient(new WebViewClient() {
        });
        root.addView(autoWeb, new LinearLayout.LayoutParams(dp(1), dp(1)));
        autoWeb.loadData("<html><body>debz-auto</body></html>", "text/html", "utf-8");
        setContentView(root);

        // izin storage diminta sekali via alur sistem (dialog/settings),
        // tanpa tombol di splash. Stack tetap naik walau ditolak (sdcard
        // optional, cuma buat bind /mnt/sdcard).
        if (!fileAccessOk() && !DebzConfig.permAsked(this)) {
            DebzConfig.setPermAsked(this);
            askFileAccess();
        }
        askBatteryExemptionOnce();
        poll = new Handler(Looper.getMainLooper());
        poll.post(poller);
        // Ketuk notif update -> langsung mulai tap-to-update.
        handleUpdateIntent(getIntent());
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode,
                                    Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQ_FILE) return;
        ValueCallback<Uri[]> cb = fileChooser;
        fileChooser = null;
        if (cb == null) return;
        if (resultCode != Activity.RESULT_OK || data == null) {
            cb.onReceiveValue(null);
            return;
        }
        // Multi-select: user bisa pilih beberapa gambar sekaligus.
        java.util.ArrayList<Uri> picked = new java.util.ArrayList<>();
        if (data.getClipData() != null) {
            android.content.ClipData clip = data.getClipData();
            for (int i = 0; i < clip.getItemCount(); i++) {
                Uri u = clip.getItemAt(i).getUri();
                if (u != null) picked.add(u);
            }
        } else if (data.getData() != null) {
            picked.add(data.getData());
        }
        cb.onReceiveValue(picked.isEmpty()
            ? null : picked.toArray(new Uri[0]));
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        handleUpdateIntent(intent);
    }

    // Intent ACTION_UPDATE (notif/banner) -> unduh + install otomatis.
    // Intent ACTION_INSTALL_RESULT (PackageInstaller) -> toast hasil.
    private void handleUpdateIntent(Intent intent) {
        if (intent == null || intent.getAction() == null) return;
        String a = intent.getAction();
        if (OtaManager.ACTION_INSTALL_RESULT.equals(a)) {
            int st = intent.getIntExtra(
                android.content.pm.PackageInstaller.EXTRA_STATUS,
                android.content.pm.PackageInstaller.STATUS_FAILURE);
            String msg = intent.getStringExtra(
                android.content.pm.PackageInstaller.EXTRA_STATUS_MESSAGE);
            if (st == android.content.pm.PackageInstaller.STATUS_SUCCESS) {
                Toast.makeText(this, "Update terpasang.", Toast.LENGTH_LONG).show();
            } else {
                Toast.makeText(this, "Install gagal: " + msg, Toast.LENGTH_LONG).show();
            }
            setIntent(new Intent());
            return;
        }
        if (!OtaManager.ACTION_UPDATE.equals(a)) return;
        setIntent(new Intent());
        startTapUpdate();
    }

    private void startTapUpdate() {
        if (!DebzConfig.updateAvailable(this) && !OtaManager.downloading) {
            Toast.makeText(this, "Tidak ada update.", Toast.LENGTH_SHORT).show();
            return;
        }
        Toast.makeText(this, "Mengunduh update…", Toast.LENGTH_SHORT).show();
        final android.content.Context appCtx = getApplicationContext();
        OtaManager.downloadAndInstall(appCtx, new OtaManager.Listener() {
            @Override public void onProgress(int pct) { /* poll via updateState */ }
            @Override public void onDone(final String msg) {
                runOnUiThread(() -> {
                    try {
                        if (!isFinishing()) Toast.makeText(MainActivity.this,
                            "⬆ " + msg, Toast.LENGTH_LONG).show();
                    } catch (Exception ignored) {}
                });
            }
            @Override public void onError(final String msg) {
                runOnUiThread(() -> {
                    try {
                        if (!isFinishing()) Toast.makeText(MainActivity.this,
                            "Update gagal: " + msg, Toast.LENGTH_LONG).show();
                    } catch (Exception ignored) {}
                });
            }
        });
    }

    private final Runnable poller = new Runnable() {
        @Override
        public void run() {
            updateStatus();
            poll.postDelayed(this, POLL_MS);
        }
    };

    @Override
    protected void onResume() {
        super.onResume();
        try { if (web != null) web.onResume(); } catch (Exception ignored) {}
        updateStatus();
    }

    @Override
    protected void onPause() {
        try { if (web != null) web.onPause(); } catch (Exception ignored) {}
        super.onPause();
    }

    @Override
    protected void onDestroy() {
        if (poll != null) poll.removeCallbacks(poller);
        if (web != null) web.destroy();
        super.onDestroy();
    }

    private void updateStatus() {
        String raw = DebzConfig.status(this);
        int pct = progressOf(raw);
        boolean up = "up".equals(raw);
        boolean working = !(up || "idle".equals(stageOf(raw)));
        if (working && bootT0 == 0) bootT0 = SystemClock.elapsedRealtime();
        if (!working) bootT0 = 0;
        String label = humanize(raw);
        if (working && bootT0 > 0) {
            label += " (" + ((SystemClock.elapsedRealtime() - bootT0) / 1000) + " dtk)";
        }
        splashStage.setText(label);
        if (pct >= 0) {
            splashBar.setIndeterminate(false);
            splashBar.setProgress(pct);
        } else {
            // stage tanpa persen (starting-stack dsb): animasi jalan biar
            // bar ga keliatan mentok palsu di angka lama ("100% tipu-tipu").
            splashBar.setIndeterminate(true);
        }
        // belum up = splash loading; up = langsung chat, tanpa reload manual.
        splash.setVisibility(up ? View.GONE : View.VISIBLE);
        if (up && web.getVisibility() != View.VISIBLE) {
            web.setVisibility(View.VISIBLE);
        } else if (!up && web.getVisibility() == View.VISIBLE) {
            web.setVisibility(View.GONE);
        }
        // load sekali saat up; kalau main frame error: retry otomatis di
        // poll berikutnya (maks 5x). Tanpa ini user WAJIB close-reopen
        // manual tiap load pertama race dengan nginx.
        if ("up".equals(raw) && (!webLoaded || (webError && webRetries < 5))) {
            webLoaded = true;
            webError = false;
            webRetries++;
            loadBackend();
        }
        if (!"up".equals(raw)) {
            webRetries = 0;
        }
    }

    // dipanggil dari sidebar web (JS): buka terminal native + info device.
    private class AndroidBridge {
        @JavascriptInterface
        public void openTerminal() {
            runOnUiThread(() ->
                startActivity(new Intent(MainActivity.this, TerminalActivity.class)));
        }

        @JavascriptInterface
        public String getInfo() {
            int off = DebzConfig.portOffset(MainActivity.this);
            int webPort = DebzConfig.webPort(MainActivity.this);
            if (webPort <= 0) webPort = 8091 + off;
            int apiPort = DebzConfig.apiPort(MainActivity.this);
            if (apiPort <= 0) apiPort = 8092 + off;
            int toolsPort = DebzConfig.toolsPort(MainActivity.this);
            if (toolsPort <= 0) toolsPort = 9191 + off;
            String upd = "";
            try {
                if (DebzConfig.updateAvailable(MainActivity.this)) {
                    upd = DebzConfig.updateNote(MainActivity.this);
                }
            } catch (Exception ignored) {}
            return "{\"v\":\"" + OtaManager.currentVersion(MainActivity.this) + "\""
                + ",\"root\":" + DebzConfig.rootMode(MainActivity.this)
                + ",\"web\":" + webPort + ",\"api\":" + apiPort
                + ",\"tools\":" + toolsPort
                + ",\"update\":\"" + upd.replace("\"", "") + "\"}";
        }

        // Dipanggil tombol "Update" di WebUI: mulai tap-to-update.
        @JavascriptInterface
        public String startUpdate() {
            runOnUiThread(() -> startTapUpdate());
            return "started";
        }

        // Poll status unduh: downloading|pct|error.
        @JavascriptInterface
        public String updateState() {
            return "{\"downloading\":" + OtaManager.downloading
                + ",\"pct\":" + OtaManager.progressPct
                + ",\"error\":\"" + OtaManager.lastError.replace("\"", "") + "\"}";
        }

        // Dipanggil tombol "Backup" di WebUI: sinkron Workspaces/notes.db
        // ke /sdcard/debz-backup/ sekarang juga (lolos uninstall).
        // Return path backup / "" kalau gagal (lihat logcat).
        @JavascriptInterface
        public String backupNow() {
            try {
                String p = RootfsManager.backupNow(MainActivity.this);
                return p != null ? p : "";
            } catch (Exception e) {
                return "";
            }
        }

        // Dipanggil JS saat stream mulai/selesai: tahan CPU+WiFi biar
        // Doze gak matiin SSE pas tool lama (websearch 30-60 dtk).
        @JavascriptInterface
        public void keepAwake(boolean on) {
            runOnUiThread(() -> {
                try {
                    if (on) {
                        android.os.PowerManager pm = (android.os.PowerManager)
                            getSystemService(android.content.Context.POWER_SERVICE);
                        if (pm != null && chatWl == null) {
                            chatWl = pm.newWakeLock(
                                android.os.PowerManager.PARTIAL_WAKE_LOCK, "debz:chat");
                            chatWl.acquire(10 * 60 * 1000L);
                        }
                        android.net.wifi.WifiManager wm = (android.net.wifi.WifiManager)
                            getApplicationContext().getSystemService(android.content.Context.WIFI_SERVICE);
                        if (wm != null && chatWifi == null) {
                            chatWifi = wm.createWifiLock(
                                android.net.wifi.WifiManager.WIFI_MODE_FULL_HIGH_PERF, "debz:chat");
                            chatWifi.acquire();
                        }
                    } else {
                        if (chatWl != null) {
                            try { if (chatWl.isHeld()) chatWl.release(); } catch (Exception ignored) {}
                            chatWl = null;
                        }
                        if (chatWifi != null) {
                            try { if (chatWifi.isHeld()) chatWifi.release(); } catch (Exception ignored) {}
                            chatWifi = null;
                        }
                    }
                } catch (Exception e) {
                    android.util.Log.w("DebzAI", "keepAwake skip: " + e);
                }
            });
        }

        // Zen native SSE: HTTP dibaca di thread Java (ZenStreamClient),
        // JS cuma nerima line via __zenNativeOnEvent/Done/Error.
        @JavascriptInterface
        public String zenStart(String streamKey, String url, String payloadJson) {
            try {
                keepAwake(true);
                return ZenStreamClient.start(
                    MainActivity.this, web, streamKey, url, payloadJson);
            } catch (Exception e) {
                return "";
            }
        }

        @JavascriptInterface
        public void zenCancel(String streamKey) {
            try {
                ZenStreamClient.cancel(streamKey != null ? streamKey : "");
                if (!isFinishing()) keepAwake(false);
            } catch (Exception ignored) {}
        }

        @JavascriptInterface
        public String zenReplay(String streamKey) {
            try {
                return ZenStreamClient.replay(
                    MainActivity.this, streamKey != null ? streamKey : "");
            } catch (Exception e) {
                return "";
            }
        }
    }
    private android.os.PowerManager.WakeLock chatWl = null;
    private android.net.wifi.WifiManager.WifiLock chatWifi = null;

    private void loadBackend() {
        int port = DebzConfig.webPort(this);
        if (port <= 0) port = 8091 + DebzConfig.portOffset(this);
        final String base = "http://127.0.0.1:" + port + "/";
        final android.content.Context appCtx = getApplicationContext();
        // auto-login: backend localhost + password default 1337 (sama kayak
        // README, ikut kebundle di APK). Target = buka app langsung chat,
        // tanpa ketik password. SATU percobaan per buka; gagal = fallback ke
        // load biasa (ketik manual). Fail-flag 15 mnt biar tidak hammer
        // (backend lockout 5x salah -> 429).
        new Thread(() -> {
            try {
                autoLogin(appCtx, base);
            } catch (Exception e) {
                android.util.Log.w("DebzAI", "auto-login skip: " + e);
            }
            // OTA rolling: cek rilisan sekali per buka (throttle 6 jam di
            // dalam). Ada update = notif sistem + toast di chat.
            try {
                final String upd = OtaManager.checkForUpdate(appCtx);
                if (upd != null && !upd.isEmpty()) {
                    runOnUiThread(() -> {
                        try {
                            if (!isFinishing()) Toast.makeText(MainActivity.this,
                                "⬆ Update tersedia: " + upd, Toast.LENGTH_LONG).show();
                        } catch (Exception ignored) {}
                    });
                }
            } catch (Exception e) {
                android.util.Log.w("DebzAI", "ota check skip: " + e);
            }
            runOnUiThread(() -> {
                try {
                    if (web != null && !isFinishing()) web.loadUrl(base);
                } catch (Exception ignored) {}
            });
        }).start();
    }

    private static volatile boolean cookieHooked;

    private static void autoLogin(android.content.Context ctx, String base) throws Exception {
        if (System.currentTimeMillis() < DebzConfig.loginFailUntil(ctx)) return;
        if (!cookieHooked) {
            cookieHooked = true;
            try {
                java.net.CookieHandler.setDefault(new java.net.CookieManager());
            } catch (Exception ignored) {}
        }
        String html = httpGet(base);
        if (!html.contains("auth_password")) return;
        java.util.regex.Matcher m = java.util.regex.Pattern.compile(
            "name=\"csrf_token\"\\s+value=\"([^\"]+)\"").matcher(html);
        if (!m.find()) {
            DebzConfig.setLoginFailUntil(ctx, System.currentTimeMillis() + 15 * 60 * 1000L);
            return;
        }
        String after = httpPost(base, "auth_password=1337&csrf_token="
            + java.net.URLEncoder.encode(m.group(1), "UTF-8"));
        if (after.contains("auth_password")) {
            DebzConfig.setLoginFailUntil(ctx, System.currentTimeMillis() + 15 * 60 * 1000L);
            return;
        }
        DebzConfig.setLoginFailUntil(ctx, 0);
        android.webkit.CookieManager wm = android.webkit.CookieManager.getInstance();
        wm.setAcceptCookie(true);
        java.net.CookieHandler h = java.net.CookieHandler.getDefault();
        if (h instanceof java.net.CookieManager) {
            for (java.net.HttpCookie c :
                    ((java.net.CookieManager) h).getCookieStore().getCookies()) {
                wm.setCookie(base, c.toString());
            }
        }
        wm.flush();
    }

    private static String httpGet(String url) throws Exception {
        java.net.HttpURLConnection c =
            (java.net.HttpURLConnection) new java.net.URL(url).openConnection();
        c.setConnectTimeout(5000);
        c.setReadTimeout(8000);
        try {
            return readCapped(c);
        } finally {
            c.disconnect();
        }
    }

    private static String httpPost(String url, String body) throws Exception {
        byte[] b = body.getBytes(java.nio.charset.StandardCharsets.UTF_8);
        java.net.HttpURLConnection c =
            (java.net.HttpURLConnection) new java.net.URL(url).openConnection();
        c.setConnectTimeout(5000);
        c.setReadTimeout(8000);
        c.setRequestMethod("POST");
        c.setDoOutput(true);
        c.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");
        c.setRequestProperty("Content-Length", String.valueOf(b.length));
        try (java.io.OutputStream o = c.getOutputStream()) {
            o.write(b);
        }
        try {
            return readCapped(c);
        } finally {
            c.disconnect();
        }
    }

    private static String readCapped(java.net.HttpURLConnection c) throws Exception {
        try (java.io.InputStream in = c.getInputStream()) {
            java.io.ByteArrayOutputStream buf = new java.io.ByteArrayOutputStream();
            byte[] tmp = new byte[8192];
            int n, total = 0;
            while ((n = in.read(tmp)) > 0 && total < 65536) {
                buf.write(tmp, 0, n);
                total += n;
            }
            return new String(buf.toByteArray(), java.nio.charset.StandardCharsets.UTF_8);
        }
    }

    private boolean fileAccessOk() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            return Environment.isExternalStorageManager();
        }
        return checkSelfPermission(
            android.Manifest.permission.READ_EXTERNAL_STORAGE)
            == PackageManager.PERMISSION_GRANTED;
    }

    private void askFileAccess() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            try {
                startActivity(new Intent(
                    Settings.ACTION_MANAGE_APP_ALL_FILES_ACCESS_PERMISSION,
                    Uri.parse("package:" + getPackageName())));
            } catch (Exception e) {
                toast("buka Settings > Apps > Debz AI > akses file manual");
            }
            return;
        }
        requestPermissions(new String[]{
            android.Manifest.permission.READ_EXTERNAL_STORAGE,
            android.Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQ_STORAGE);
    }

    private void askBatteryExemptionOnce() {
        try {
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return;
            android.content.SharedPreferences sp = getSharedPreferences("debz", MODE_PRIVATE);
            if (sp.getBoolean("batt_asked", false)) return;
            android.os.PowerManager pm = (android.os.PowerManager)
                getSystemService(android.content.Context.POWER_SERVICE);
            if (pm != null && pm.isIgnoringBatteryOptimizations(getPackageName())) {
                sp.edit().putBoolean("batt_asked", true).apply();
                return;
            }
            sp.edit().putBoolean("batt_asked", true).apply();
            try {
                startActivity(new Intent(
                    Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                    Uri.parse("package:" + getPackageName())));
            } catch (Exception ignored) {}
        } catch (Exception ignored) {}
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions,
                                           int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        updateStatus();
    }

    private void toast(final String msg) {
        runOnUiThread(() -> Toast.makeText(MainActivity.this, msg,
            Toast.LENGTH_SHORT).show());
    }

    private int dp(int v) {
        return (int) (v * getResources().getDisplayMetrics().density);
    }

    private static int progressOf(String raw) {
        int i = raw.lastIndexOf(':');
        if (i < 0) return -1;
        try {
            int p = Integer.parseInt(raw.substring(i + 1));
            return (p >= 0 && p <= 100) ? p : -1;
        } catch (NumberFormatException e) {
            return -1;
        }
    }

    private static String stageOf(String raw) {
        int i = raw.indexOf(':');
        return i < 0 ? raw : raw.substring(0, i);
    }

    private static String humanize(String raw) {
        switch (stageOf(raw)) {
            case "idle": return "Siap";
            case "booting": return "Menyalakan service\u2026";
            case "firstboot": return "Install dependensi Python\u2026";
            case "extract-rootfs": return "Menyalin rootfs dari APK\u2026";
            case "download-rootfs": return "Mengunduh rootfs\u2026";
            case "download": return "Mengunduh\u2026";
            case "copy": return "Menyalin\u2026";
            case "verify": return "Verifikasi SHA256\u2026";
            case "extract": return "Ekstrak rootfs\u2026";
            case "starting-stack": return "Menyalakan stack\u2026";
            case "retry-stack": return "Mencoba ulang otomatis\u2026";
            case "up": return "Online, backend jalan";
            case "stack-fail": return "Stack gagal start (3x coba)";
            case "no-rootfs": return "Rootfs tidak ada di APK";
            case "error": return "Error: " + raw.substring("error:".length());
            default: return raw;
        }
    }

    private static String hintFor(String raw) {
        switch (stageOf(raw)) {
            case "extract-rootfs":
            case "download-rootfs":
            case "copy":
            case "verify":
            case "extract":
                return "Rootfs 411MB lagi disiapkan (2-5 menit di HP kentang), jangan tutup app. Progress ada di atas.";
            case "starting-stack":
            case "retry-stack":
            case "booting":
                return "Stack lagi naik, tunggu sebentar. Detik di atas bukti proses jalan, bukan hang.";
            case "stack-fail":
                return "Sudah dicoba ulang 3x otomatis. Buka Terminal di sidebar, kirim isi stack-last.log ke developer. Jangan tutup-buka berulang, hasilnya sama.";
            case "no-rootfs":
                return "APK tidak membawa rootfs. Install ulang dari artifact debz-ai-apk yang benar.";
            default:
                if (raw.startsWith("error:")) return "Catat pesannya, kirim ke developer.";
                return "Tunggu BootstrapService selesai.";
        }
    }
}
