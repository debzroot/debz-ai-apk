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

    public static Map<String, String> envFor(int web, int api, int fpm) {
        Map<String, String> env = new HashMap<>();
        env.put("PORT_WEB", String.valueOf(web));
        env.put("PORT_API", String.valueOf(api));
        env.put("PORT_FPM", String.valueOf(fpm));
        env.put("START_OPENCODE", "1");
        return env;
    }

    public static String start(Context ctx, File rootfs,
                               Map<String, String> env) throws Exception {
        firstBoot(ctx, rootfs, env);
        Process p = ProotManager.exec(ctx, rootfs, env, "/opt/debz/start-stack.sh");
        String out = drain(p.getInputStream());
        p.waitFor();
        return out;
    }

    public static String stop(Context ctx, File rootfs) throws Exception {
        Process p = ProotManager.exec(ctx, rootfs, null, "/opt/debz/stop-stack.sh");
        String out = drain(p.getInputStream());
        p.waitFor();
        return out;
    }

    public static boolean healthy(String url) {
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
        drain(p.getInputStream());
        p.waitFor();
    }

    private static String drain(InputStream in) throws Exception {
        ByteArrayOutputStream buf = new ByteArrayOutputStream();
        byte[] tmp = new byte[8192];
        int n;
        while ((n = in.read(tmp)) > 0) buf.write(tmp, 0, n);
        return new String(buf.toByteArray(), StandardCharsets.UTF_8);
    }
}
