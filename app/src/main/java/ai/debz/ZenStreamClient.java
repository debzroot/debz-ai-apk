package ai.debz;

import android.content.Context;
import android.content.Intent;
import android.net.wifi.WifiManager;
import android.os.Build;
import android.os.PowerManager;
import android.webkit.WebView;
import java.io.BufferedReader;
import java.io.File;
import java.io.FileReader;
import java.io.FileWriter;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;
import org.json.JSONObject;

// Zen native SSE client: HTTP dibaca di thread Java (bukan WebView),
// jadi stream selamat dari web.onPause(), throttle timer, dan Doze.
// Tanpa dep baru (HttpURLConnection + org.json bawaan Android).
public final class ZenStreamClient {
    private ZenStreamClient() {}

    private static final ConcurrentHashMap<String, Job> JOBS = new ConcurrentHashMap<>();
    private static PowerManager.WakeLock wl = null;
    private static WifiManager.WifiLock wifiLock = null;

    private static final class Job {
        volatile HttpURLConnection conn;
        volatile Thread thread;
        volatile boolean cancelled;
        volatile WebView web;
    }

    public static boolean isRunning(String key) {
        return JOBS.containsKey(key);
    }

    public static void cancel(String key) {
        Job j = JOBS.remove(key);
        if (j != null) {
            j.cancelled = true;
            try {
                if (j.conn != null) j.conn.disconnect();
            } catch (Exception ignored) {}
            try {
                if (j.thread != null) j.thread.interrupt();
            } catch (Exception ignored) {}
            // STOP FIX: notify JS biar fake reader native ke-unblock (nEnded=true),
            // redundan dgn signal listener di JS tapi aman bila listener blm kepasang.
            try {
                if (j.web != null) {
                    final WebView w = j.web;
                    final String k = key != null ? key : "";
                    w.post(() -> {
                        try {
                            w.evaluateJavascript(
                                "window.__zenNativeOnDone(" + JSONObject.quote(k) + ",false);",
                                null);
                        } catch (Exception ignored) {}
                    });
                }
            } catch (Exception ignored) {}
        }
        try {
            Context appCtx = null;
            try {
                if (j != null && j.web != null) appCtx = j.web.getContext().getApplicationContext();
            } catch (Exception ignored) {}
            releaseLocksIfIdle(appCtx);
        } catch (Exception ignored) {}
    }

    public static String replay(Context ctx, String key) {
        try {
            File f = logFile(ctx, key);
            if (!f.isFile()) return "";
            StringBuilder sb = new StringBuilder();
            try (BufferedReader r = new BufferedReader(new FileReader(f))) {
                String ln;
                while ((ln = r.readLine()) != null) {
                    if (sb.length() + ln.length() > 200000) break;
                    sb.append(ln).append('\n');
                }
            }
            return sb.toString();
        } catch (Exception e) {
            return "";
        }
    }

    private static File logFile(Context ctx, String key) {
        String safe = key.replaceAll("[^A-Za-z0-9_-]", "_");
        if (safe.length() > 64) safe = safe.substring(0, 64);
        return new File(ctx.getFilesDir(), "zen_" + safe + ".log");
    }

    private static void post(final WebView web, final String js) {
        try {
            web.post(() -> {
                try {
                    web.evaluateJavascript(js, null);
                } catch (Exception ignored) {}
            });
        } catch (Exception ignored) {}
    }

    private static String q(String s) {
        return JSONObject.quote(s != null ? s : "");
    }

    private static void acquireLocks(Context ctx) {
        try {
            if (wl == null) {
                PowerManager pm = (PowerManager) ctx.getSystemService(Context.POWER_SERVICE);
                if (pm != null) {
                    wl = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "debz:zen");
                    wl.acquire(30 * 60 * 1000L);
                }
            }
        } catch (Exception ignored) {}
        try {
            if (wifiLock == null) {
                WifiManager wm = (WifiManager) ctx.getApplicationContext()
                    .getSystemService(Context.WIFI_SERVICE);
                if (wm != null) {
                    wifiLock = wm.createWifiLock(
                        WifiManager.WIFI_MODE_FULL_HIGH_PERF, "debz:zen");
                    wifiLock.acquire();
                }
            }
        } catch (Exception ignored) {}
    }

    private static void releaseLocksIfIdle(Context ctx) {
        if (!JOBS.isEmpty()) return;
        try {
            if (wl != null && wl.isHeld()) wl.release();
        } catch (Exception ignored) {}
        wl = null;
        try {
            if (wifiLock != null && wifiLock.isHeld()) wifiLock.release();
        } catch (Exception ignored) {}
        wifiLock = null;
        if (ctx != null) {
            try {
                ctx.stopService(new Intent(ctx, ZenStreamService.class));
            } catch (Exception ignored) {}
        }
    }

    // payloadJson: {messages, prompt, max_tokens, tools, approval_session,
    // provider_id, ka_id, thread_id}. Text-only v1 (images => fallback WebView).
    public static String start(final Context ctx, final WebView web,
                               final String streamKey, final String url,
                               final String payloadJson) {
        final String key = (streamKey != null && !streamKey.isEmpty())
            ? streamKey : ("zen" + UUID.randomUUID().toString().replace("-", ""));
        cancel(key);
        acquireLocks(ctx.getApplicationContext());
        try {
            Intent svc = new Intent(ctx.getApplicationContext(), ZenStreamService.class);
            svc.putExtra(ZenStreamService.EXTRA_STREAM_KEY, key);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                ctx.getApplicationContext().startForegroundService(svc);
            } else {
                ctx.getApplicationContext().startService(svc);
            }
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "zen svc skip: " + e);
        }
        final Job job = new Job();
        job.web = web;
        JOBS.put(key, job);
        final Context appCtx = ctx.getApplicationContext();
        job.thread = new Thread(() -> runStream(appCtx, web, key, url, payloadJson, job));
        job.thread.setDaemon(true);
        job.thread.start();
        return key;
    }

    private static void runStream(Context appCtx, WebView web, String key,
                                  String url, String payloadJson, Job job) {
        boolean cleanDone = false;
        File log = logFile(appCtx, key);
        try {
            if (log.isFile()) log.delete();
        } catch (Exception ignored) {}
        FileWriter logW = null;
        try {
            logW = new FileWriter(log, true);
        } catch (Exception ignored) {}
        HttpURLConnection c = null;
        try {
            JSONObject p = new JSONObject(payloadJson != null ? payloadJson : "{}");
            String boundary = "----debzzen" + UUID.randomUUID().toString().replace("-", "");
            c = (HttpURLConnection) new URL(url).openConnection();
            job.conn = c;
            c.setConnectTimeout(30000);
            c.setReadTimeout(0);
            c.setDoOutput(true);
            c.setRequestMethod("POST");
            c.setRequestProperty("Accept", "text/event-stream");
            c.setRequestProperty("Cache-Control", "no-cache");
            c.setRequestProperty("Connection", "keep-alive");
            c.setRequestProperty("Content-Type", "multipart/form-data; boundary=" + boundary);
            c.setInstanceFollowRedirects(true);
            try (OutputStream o = c.getOutputStream()) {
                writeField(o, boundary, "messages", p.optString("messages", "[]"));
                writeField(o, boundary, "prompt", p.optString("prompt", ""));
                writeField(o, boundary, "max_tokens", p.optString("max_tokens", "8192"));
                writeField(o, boundary, "tools", p.optString("tools", "1"));
                writeField(o, boundary, "approval_session", p.optString("approval_session", "0"));
                if (p.has("provider_id")) writeField(o, boundary, "provider_id", p.optString("provider_id", ""));
                if (p.has("ka_id")) writeField(o, boundary, "ka_id", p.optString("ka_id", ""));
                if (p.has("thread_id")) writeField(o, boundary, "thread_id", p.optString("thread_id", ""));
                if (p.has("stop")) writeField(o, boundary, "stop", p.optString("stop", ""));
                o.write(("--" + boundary + "--\r\n").getBytes(StandardCharsets.UTF_8));
            }
            int code = c.getResponseCode();
            if (code < 200 || code >= 300) {
                String msg = "HTTP " + code;
                try (InputStream err = c.getErrorStream()) {
                    if (err != null) {
                        byte[] buf = new byte[2048];
                        int n = err.read(buf);
                        if (n > 0) msg += " " + new String(buf, 0, n, StandardCharsets.UTF_8);
                    }
                } catch (Exception ignored) {}
                post(web, "window.__zenNativeOnError(" + q(key) + "," + q(msg) + ");");
                return;
            }
            try (InputStream in = c.getInputStream();
                 BufferedReader r = new BufferedReader(
                     new InputStreamReader(in, StandardCharsets.UTF_8))) {
                String line;
                while (!job.cancelled && (line = r.readLine()) != null) {
                    String raw = line;
                    if (logW != null) {
                        try {
                            logW.write(raw + "\n");
                        } catch (Exception ignored) {}
                    }
                    String t = raw.trim();
                    if (t.isEmpty()) continue;
                    // Heartbeat diterusin (bukan di-drop): JS update lastByteTs
                    // tiap reader.read() resolve, jadi idle watchdog tau koneksi
                    // hidup. Tanpa ini stream local_ dikira mati suri >120s.
                    if (t.equals(": ka")) {
                        post(web, "window.__zenNativeOnEvent(" + q(key) + "," + q(raw) + ");");
                        continue;
                    }
                    if (t.contains("[DONE]")) cleanDone = true;
                    post(web, "window.__zenNativeOnEvent(" + q(key) + "," + q(raw) + ");");
                    if (cleanDone) break;
                }
            }
            if (logW != null) {
                try {
                    logW.flush();
                } catch (Exception ignored) {}
            }
            if (!job.cancelled) {
                post(web, "window.__zenNativeOnDone(" + q(key) + "," + (cleanDone ? "true" : "false") + ");");
            }
        } catch (Exception e) {
            if (!job.cancelled) {
                android.util.Log.w("DebzAI", "zen stream err: " + e);
                post(web, "window.__zenNativeOnError(" + q(key) + "," + q(String.valueOf(e.getMessage())) + ");");
            }
        } finally {
            try {
                if (logW != null) logW.close();
            } catch (Exception ignored) {}
            try {
                if (c != null) c.disconnect();
            } catch (Exception ignored) {}
            JOBS.remove(key);
            releaseLocksIfIdle(appCtx);
        }
    }

    private static void writeField(OutputStream o, String boundary,
                                   String name, String value) throws Exception {
        String h = "--" + boundary + "\r\n"
            + "Content-Disposition: form-data; name=\"" + name + "\"\r\n\r\n";
        o.write(h.getBytes(StandardCharsets.UTF_8));
        o.write((value != null ? value : "").getBytes(StandardCharsets.UTF_8));
        o.write("\r\n".getBytes(StandardCharsets.UTF_8));
    }
}
