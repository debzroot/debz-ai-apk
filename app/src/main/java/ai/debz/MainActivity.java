package ai.debz;

import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.webkit.JavascriptInterface;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

public class MainActivity extends Activity {
    private static final int REQ_STORAGE = 41;
    private static final long POLL_MS = 2000;

    private WebView web;
    private TextView dot;
    private TextView statusLine;
    private TextView infoLine;
    private ProgressBar bar;
    private LinearLayout splash;
    private ProgressBar splashBar;
    private TextView splashStage;
    private Button permBtn;
    private Handler poll;
    private boolean webLoaded;

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
        int pad = dp(12);
        root.setPadding(pad, pad, pad, pad);

        LinearLayout head = new LinearLayout(this);
        head.setOrientation(LinearLayout.HORIZONTAL);
        TextView title = new TextView(this);
        title.setText("Debz AI " + OtaManager.currentVersion());
        title.setTextSize(18);
        title.setLayoutParams(new LinearLayout.LayoutParams(0,
            ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        dot = new TextView(this);
        dot.setText("\u25CF");
        dot.setTextSize(20);
        head.addView(title);
        head.addView(dot);

        statusLine = new TextView(this);
        statusLine.setTextSize(14);

        bar = new ProgressBar(this, null,
            android.R.attr.progressBarStyleHorizontal);
        bar.setMax(100);
        bar.setVisibility(View.GONE);

        // splash: tampil selama stack naik, web disembunyikan sampai up.
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

        infoLine = new TextView(this);
        infoLine.setTextSize(12);

        permBtn = new Button(this);
        permBtn.setText("Izinkan akses file");
        permBtn.setOnClickListener(v -> askFileAccess());

        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        Button reloadBtn = new Button(this);
        reloadBtn.setText("Reload");
        reloadBtn.setOnClickListener(v -> loadBackend());
        Button restartBtn = new Button(this);
        restartBtn.setText("Restart");
        restartBtn.setOnClickListener(v -> restartStack());
        for (Button b : new Button[]{reloadBtn, restartBtn}) {
            b.setLayoutParams(new LinearLayout.LayoutParams(0,
                ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
            row.addView(b);
        }

        web = new WebView(this);
        web.addJavascriptInterface(new AndroidBridge(), "DebzAndroid");
        WebSettings ws = web.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        web.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedError(WebView view, WebResourceRequest request,
                                        WebResourceError error) {
                view.loadData("<h3>Backend belum siap.</h3>"
                    + "<p>Status: " + DebzConfig.status(MainActivity.this) + "</p>"
                    + "<p>" + hintFor(DebzConfig.status(MainActivity.this)) + "</p>",
                    "text/html", "utf-8");
            }
        });

        root.addView(head);
        root.addView(statusLine);
        root.addView(bar);
        root.addView(infoLine);
        root.addView(permBtn);
        root.addView(row);
        root.addView(splash, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        web.setVisibility(View.GONE);
        root.addView(web, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        setContentView(root);

        if (!fileAccessOk() && Build.VERSION.SDK_INT < Build.VERSION_CODES.R
                && !DebzConfig.permAsked(this)) {
            DebzConfig.setPermAsked(this);
            askFileAccess();
        }
        poll = new Handler(Looper.getMainLooper());
        poll.post(poller);
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
        updateStatus();
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
        statusLine.setText(humanize(raw));
        dot.setTextColor(colorFor(raw));
        splashStage.setText(humanize(raw));
        if (pct >= 0) {
            splashBar.setProgress(pct);
        }
        // belum up = splash loading; up = langsung chat, tanpa reload manual.
        splash.setVisibility(up ? View.GONE : View.VISIBLE);
        if (up && web.getVisibility() != View.VISIBLE) {
            web.setVisibility(View.VISIBLE);
        } else if (!up && web.getVisibility() == View.VISIBLE) {
            web.setVisibility(View.GONE);
        }
        int webPort = DebzConfig.webPort(this);
        if (webPort <= 0) webPort = 8091 + DebzConfig.portOffset(this);
        int apiPort = DebzConfig.apiPort(this);
        if (apiPort <= 0) apiPort = 8092 + DebzConfig.portOffset(this);
        infoLine.setText("web :" + webPort + "  api :" + apiPort
            + "  mode :" + (DebzConfig.rootMode(this) ? "root" : "non-root")
            + "\nakses file :" + (fileAccessOk() ? "OK (/mnt/sdcard aktif)" : "belum"));
        permBtn.setVisibility(fileAccessOk() ? View.GONE : View.VISIBLE);
        if ("up".equals(raw) && !webLoaded) {
            webLoaded = true;
            loadBackend();
        }
    }

    // dipanggil dari sidebar web (JS): buka terminal native.
    private class AndroidBridge {
        @JavascriptInterface
        public void openTerminal() {
            runOnUiThread(() ->
                startActivity(new Intent(MainActivity.this, TerminalActivity.class)));
        }
    }

    private void loadBackend() {
        int port = DebzConfig.webPort(this);
        if (port <= 0) port = 8091 + DebzConfig.portOffset(this);
        web.loadUrl("http://127.0.0.1:" + port + "/");
    }

    private void restartStack() {
        new Thread(() -> {
            try {
                if (!RootfsManager.ready(this)) {
                    toast("rootfs belum siap");
                    return;
                }
                int webPort = DebzConfig.webPort(this);
                if (webPort <= 0) webPort = 8091 + DebzConfig.portOffset(this);
                int apiPort = DebzConfig.apiPort(this);
                if (apiPort <= 0) apiPort = 8092 + DebzConfig.portOffset(this);
                int fpm = 9000 + DebzConfig.portOffset(this);
                int tools = DebzConfig.toolsPort(this);
                if (tools <= 0) tools = 9191 + DebzConfig.portOffset(this);
                StackSupervisor.stop(this, RootfsManager.dir(this));
                StackSupervisor.start(this, RootfsManager.dir(this),
                    StackSupervisor.envFor(webPort, apiPort, fpm, tools));
                toast("stack direstart");
            } catch (Exception e) {
                toast("restart gagal: " + e.getMessage());
            }
            runOnUiThread(() -> {
                webLoaded = false;
                updateStatus();
            });
        }).start();
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
            case "extract-rootfs": return "Menyalin rootfs dari APK\u2026";
            case "download-rootfs": return "Mengunduh rootfs\u2026";
            case "download": return "Mengunduh\u2026";
            case "copy": return "Menyalin\u2026";
            case "verify": return "Verifikasi SHA256\u2026";
            case "extract": return "Ekstrak rootfs\u2026";
            case "starting-stack": return "Menyalakan stack\u2026";
            case "up": return "Online, backend jalan";
            case "stack-fail": return "Stack gagal start, tap Restart";
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
            case "booting":
                return "Stack lagi naik, tunggu sebentar lalu tap Reload.";
            case "stack-fail":
                return "Tap Restart di atas, kalau masih gagal cek Terminal.";
            case "no-rootfs":
                return "APK tidak membawa rootfs. Install ulang dari artifact debz-ai-apk yang benar.";
            default:
                if (raw.startsWith("error:")) return "Catat pesannya, kirim ke developer.";
                return "Tunggu BootstrapService selesai, lalu tap Reload.";
        }
    }

    private static int colorFor(String raw) {
        String s = stageOf(raw);
        if ("up".equals(s)) return Color.GREEN;
        if ("stack-fail".equals(s) || "no-rootfs".equals(s)
                || "error".equals(s)) return Color.RED;
        if ("idle".equals(s)) return Color.GRAY;
        return Color.YELLOW;
    }
}
