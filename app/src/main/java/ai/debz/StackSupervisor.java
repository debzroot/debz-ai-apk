package ai.debz;

import android.content.Context;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.HashMap;
import java.util.Map;

public final class StackSupervisor {
    private StackSupervisor() {}

    public static Map<String, String> baseEnv() {
        Map<String, String> env = new HashMap<>();
        // tool rootfs (mkdir/sed/pkill/php/...) — PATH bawaan app Android
        // (/system/bin/...) tak ada di proot -> command not found semua.
        env.put("PATH", "/usr/bin:/bin:/usr/sbin:/sbin");
        // HOME kosong = bun/opencode crash (uv_os_homedir ENOENT).
        env.put("HOME", "/root");
        return env;
    }

    public static Map<String, String> envFor(int web, int api, int fpm, int tools) {
        Map<String, String> env = baseEnv();
        env.put("PORT_WEB", String.valueOf(web));
        env.put("PORT_API", String.valueOf(api));
        env.put("PORT_FPM", String.valueOf(fpm));
        env.put("TOOLS_PORT", String.valueOf(tools));
        env.put("PORT_WEB", String.valueOf(web));
        env.put("PORT_API", String.valueOf(api));
        env.put("PORT_FPM", String.valueOf(fpm));
        env.put("START_OPENCODE", "1");
        // watchdog.sh bunuh stack saat pid ini hilang -> uninstall bersih total
        env.put("DEBZ_APP_PID", String.valueOf(android.os.Process.myPid()));
        return env;
    }

    public static String start(Context ctx, File rootfs,
                               Map<String, String> env) throws Exception {
        firstBoot(ctx, rootfs, env);
        Process p = ProotManager.exec(ctx, rootfs, env, "/opt/debz/start-stack.sh");
        // drain() tanpa timeout = gantung selamanya kalau child macet:
        // tunggu exit dulu (timeout), baru baca sisa output non-blocking.
        boolean done = p.waitFor(180, java.util.concurrent.TimeUnit.SECONDS);
        String out = drainAvailable(p.getInputStream());
        if (!done) p.destroyForcibly();
        return out;
    }

    public static String stop(Context ctx, File rootfs) throws Exception {
        Process p = ProotManager.exec(ctx, rootfs, baseEnv(), "/opt/debz/stop-stack.sh");
        String out = drain(p.getInputStream());
        p.waitFor();
        return out;
    }

    public static boolean healthy(String url) {
        return healthyRetry(url, 1);
    }

    // daemon butuh detik buat listen; cek sekali = stack-fail palsu.
    // Dipakai BootstrapService dengan retry biar status akurat.
    public static boolean healthyRetry(String url, int tries) {
        for (int i = 0; i < tries; i++) {
            if (ping(url)) return true;
            try {
                Thread.sleep(2000);
            } catch (InterruptedException ignored) {
                return false;
            }
        }
        return false;
    }

    private static boolean ping(String url) {
        HttpURLConnection c = null;
        try {
            c = (HttpURLConnection) new URL(url).openConnection();
            c.setConnectTimeout(3000);
            c.setReadTimeout(3000);
            int code = c.getResponseCode();
            return code >= 200 && code < 500;
        } catch (Exception e) {
            return false;
        } finally {
            if (c != null) c.disconnect();
        }
    }

    private static void firstBoot(Context ctx, File rootfs,
                                  Map<String, String> env) throws Exception {
        File done = new File(rootfs, "opt/debz/.pydeps-done");
        if (done.exists()) return;
        Process p = ProotManager.exec(ctx, rootfs, env, "/opt/debz/first-boot-pip.sh");
        boolean finished = p.waitFor(300, java.util.concurrent.TimeUnit.SECONDS);
        drainAvailable(p.getInputStream());
        if (!finished) p.destroyForcibly();
    }

    private static String drain(InputStream in) throws Exception {
        ByteArrayOutputStream buf = new ByteArrayOutputStream();
        byte[] tmp = new byte[8192];
        int n;
        while ((n = in.read(tmp)) > 0) buf.write(tmp, 0, n);
        return new String(buf.toByteArray(), StandardCharsets.UTF_8);
    }

    // baca output yang sudah tersedia TANPA blocking (dipakai setelah
    // waitFor-timeout; drain() biasa gantung kalau child belum exit).
    private static String drainAvailable(InputStream in) {
        try {
            ByteArrayOutputStream buf = new ByteArrayOutputStream();
            byte[] tmp = new byte[8192];
            long deadline = System.currentTimeMillis() + 3000;
            while (System.currentTimeMillis() < deadline) {
                int avail = in.available();
                if (avail <= 0) {
                    Thread.sleep(100);
                    continue;
                }
                int n = in.read(tmp, 0, Math.min(avail, tmp.length));
                if (n <= 0) break;
                buf.write(tmp, 0, n);
                deadline = System.currentTimeMillis() + 500;
            }
            return new String(buf.toByteArray(), StandardCharsets.UTF_8);
        } catch (Exception e) {
            return "";
        }
    }
}
