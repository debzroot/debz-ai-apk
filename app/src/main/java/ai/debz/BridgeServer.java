package ai.debz;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetAddress;
import java.net.ServerSocket;
import java.net.Socket;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.atomic.AtomicBoolean;

/**
 * BridgeServer — HTTP mini di 127.0.0.1 buat agent AI pegang HP Android.
 *
 * Backend (proot) cuma lihat dunia Linux-nya sendiri; perintah Android
 * (pm, dumpsys, settings, svc, input, am, getprop ...) + root (su) cuma
 * bisa dari sisi Java. Server ini jembatannya: token auth, loopback only.
 *
 * POST /exec {token, command, timeout} -> {ok, exit, out, err, root}
 * GET  /ping -> {ok, root}
 */
public final class BridgeServer {
    private static final AtomicBoolean RUNNING = new AtomicBoolean(false);
    private static final int MAX_OUT = 131072;

    private BridgeServer() {}

    public static int start(android.content.Context ctx, int port) {
        if (!RUNNING.compareAndSet(false, true)) return -1;
        try {
            ServerSocket ss = new ServerSocket(
                port, 4, InetAddress.getByName("127.0.0.1"));
            final int bound = ss.getLocalPort();
            Thread t = new Thread(() -> loop(ctx.getApplicationContext(), ss));
            t.setDaemon(true);
            t.start();
            android.util.Log.i("DebzAI", "android bridge up 127.0.0.1:" + bound);
            return bound;
        } catch (Exception e) {
            RUNNING.set(false);
            android.util.Log.w("DebzAI", "bridge gagal start: " + e);
            return -1;
        }
    }

    private static void loop(android.content.Context ctx, ServerSocket ss) {
        while (RUNNING.get()) {
            try {
                Socket s = ss.accept();
                s.setSoTimeout(10000);
                handle(ctx, s);
                try {
                    s.close();
                } catch (Exception ignored) {}
            } catch (Exception e) {
                if (RUNNING.get()) {
                    android.util.Log.w("DebzAI", "bridge accept: " + e);
                }
            }
        }
        try {
            ss.close();
        } catch (Exception ignored) {}
    }

    private static void handle(android.content.Context ctx, Socket s) {
        try {
            InputStream in = s.getInputStream();
            OutputStream out = s.getOutputStream();
            ByteArrayOutputStream head = new ByteArrayOutputStream();
            int b, prev = -1, prev2 = -1;
            // baca header sampai \r\n\r\n (maks 64KB)
            while (head.size() < 65536 && (b = in.read()) != -1) {
                head.write(b);
                if (prev2 == '\r' && prev == '\n' && b == '\n') break;
                // deteksi \r\n\r\n sederhana
                if (head.size() >= 4) {
                    byte[] h = head.toByteArray();
                    int n = h.length;
                    if (h[n - 4] == '\r' && h[n - 3] == '\n'
                            && h[n - 2] == '\r' && h[n - 1] == '\n') break;
                }
                prev2 = prev;
                prev = b;
            }
            String hs = new String(head.toByteArray(), StandardCharsets.UTF_8);
            String[] lines = hs.split("\r\n");
            if (lines.length == 0) {
                reply(out, 400, "{\"ok\":false,\"error\":\"bad request\"}");
                return;
            }
            String[] rl = lines[0].split(" ");
            String method = rl.length > 0 ? rl[0] : "";
            String path = rl.length > 1 ? rl[1] : "";
            int len = 0;
            for (String ln : lines) {
                if (ln.toLowerCase().startsWith("content-length:")) {
                    try {
                        len = Integer.parseInt(ln.substring(15).trim());
                    } catch (Exception ignored) {}
                }
            }
            if (len < 0 || len > 1048576) len = 0;
            byte[] body = new byte[len];
            int off = 0;
            while (off < len) {
                int n = in.read(body, off, len - off);
                if (n < 0) break;
                off += n;
            }
            String bs = new String(body, 0, off, StandardCharsets.UTF_8);

            if ("GET".equals(method) && path.startsWith("/ping")) {
                reply(out, 200, "{\"ok\":true,\"root\":"
                    + RootDetector.suWorks() + "}");
                return;
            }
            if (!"POST".equals(method) || !path.startsWith("/exec")) {
                reply(out, 404, "{\"ok\":false,\"error\":\"unknown\"}");
                return;
            }
            String token = extractJson(bs, "token");
            if (token == null || !constantEq(token, DebzConfig.abridgeToken(ctx))) {
                reply(out, 403, "{\"ok\":false,\"error\":\"invalid token\"}");
                return;
            }
            String command = extractJson(bs, "command");
            if (command == null || command.trim().isEmpty()) {
                reply(out, 400, "{\"ok\":false,\"error\":\"command kosong\"}");
                return;
            }
            int timeout = 60;
            try {
                timeout = Integer.parseInt(
                    extractJsonDef(bs, "timeout", "60"));
                if (timeout < 1) timeout = 1;
                if (timeout > 300) timeout = 300;
            } catch (Exception ignored) {}
            android.util.Log.i("DebzAI",
                "bridge exec" + (command.length() > 120
                    ? command.substring(0, 120) : command));
            boolean root = RootDetector.suWorks();
            String[] cmd = root
                ? new String[]{"/system/bin/su", "-c", command}
                : new String[]{"sh", "-c", command};
            // fallback path su bila /system/bin/su tak ada tapi suWorks via PATH
            Process p;
            try {
                p = new ProcessBuilder(cmd).redirectErrorStream(false).start();
            } catch (Exception e) {
                if (root) {
                    p = new ProcessBuilder(
                        new String[]{"su", "-c", command}).start();
                } else {
                    throw e;
                }
            }
            boolean done = p.waitFor(timeout,
                java.util.concurrent.TimeUnit.SECONDS);
            String so = readCapped(p.getInputStream());
            String se = readCapped(p.getErrorStream());
            int exit = -1;
            if (done) {
                try {
                    exit = p.exitValue();
                } catch (Exception ignored) {}
            } else {
                p.destroyForcibly();
            }
            reply(out, 200, "{\"ok\":" + (done && exit == 0) + ",\"exit\":"
                + exit + ",\"root\":" + root + ",\"out\":" + jq(so)
                + ",\"err\":" + jq(se) + "}");
        } catch (Exception e) {
            try {
                reply(s.getOutputStream(),
                    500, "{\"ok\":false,\"error\":\"bridge: "
                        + esc(e.toString()) + "\"}");
            } catch (Exception ignored) {}
        }
    }

    private static String readCapped(InputStream in) {
        try {
            ByteArrayOutputStream buf = new ByteArrayOutputStream();
            byte[] tmp = new byte[8192];
            int n, total = 0;
            while ((n = in.read(tmp)) > 0 && total < MAX_OUT) {
                buf.write(tmp, 0, n);
                total += n;
            }
            return new String(buf.toByteArray(), StandardCharsets.UTF_8);
        } catch (Exception e) {
            return "";
        }
    }

    private static void reply(OutputStream out, int code, String json)
            throws Exception {
        byte[] b = json.getBytes(StandardCharsets.UTF_8);
        String h = "HTTP/1.0 " + code + (code == 200 ? " OK" : " ERR") + "\r\n"
            + "Content-Type: application/json\r\n"
            + "Content-Length: " + b.length + "\r\n"
            + "Connection: close\r\n\r\n";
        out.write(h.getBytes(StandardCharsets.UTF_8));
        out.write(b);
        out.flush();
    }

    private static String extractJson(String json, String key) {
        if (json == null) return null;
        java.util.regex.Matcher m = java.util.regex.Pattern.compile(
            "\"" + key + "\"\\s*:\\s*\"((?:[^\"\\\\]|\\\\.)*)\"")
            .matcher(json);
        if (!m.find()) {
            // angka polos
            java.util.regex.Matcher m2 = java.util.regex.Pattern.compile(
                "\"" + key + "\"\\s*:\\s*(\\d+)").matcher(json);
            return m2.find() ? m2.group(1) : null;
        }
        return m.group(1).replace("\\\"", "\"").replace("\\\\", "\\")
            .replace("\\n", "\n");
    }

    private static String extractJsonDef(String json, String key,
                                         String def) {
        String v = extractJson(json, key);
        return v != null ? v : def;
    }

    private static boolean constantEq(String a, String c) {
        if (a == null || c == null || a.length() != c.length()) return false;
        int r = 0;
        for (int i = 0; i < a.length(); i++) r |= a.charAt(i) ^ c.charAt(i);
        return r == 0;
    }

    private static String jq(String s) {
        if (s == null) return "\"\"";
        return "\"" + s.replace("\\", "\\\\").replace("\"", "\\\"")
            .replace("\n", "\\n").replace("\r", "\\r")
            .replace("\t", "\\t") + "\"";
    }

    private static String esc(String s) {
        return s.replace("\"", "'").replace("\n", " ");
    }
}
