package ai.debz;

import android.content.Context;
import java.io.File;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

public final class ProotManager {
    private ProotManager() {}

    public static File binary(Context ctx) {
        File f = new File(ctx.getApplicationInfo().nativeLibraryDir, "libproot.so");
        if (f.exists()) f.setExecutable(true);
        return f;
    }

    public static List<String> baseArgs(Context ctx, File rootfs) {
        List<String> a = new ArrayList<>();
        a.add(binary(ctx).getAbsolutePath());
        a.add("-r");
        a.add(rootfs.getAbsolutePath());
        a.add("-0");
        a.add("-b"); a.add("/proc:/proc");
        a.add("-b"); a.add("/sys:/sys");
        File sd = new File("/sdcard");
        if (sd.exists()) {
            a.add("-b"); a.add("/sdcard:/mnt/sdcard");
        }
        return a;
    }

    public static Process exec(Context ctx, File rootfs,
                               Map<String, String> env, String... cmd) throws Exception {
        List<String> a = baseArgs(ctx, rootfs);
        a.add("/bin/sh");
        a.add("-c");
        a.add(String.join(" ", cmd));
        ProcessBuilder pb = new ProcessBuilder(a);
        pb.directory(rootfs);
        if (env != null) pb.environment().putAll(env);
        pb.redirectErrorStream(true);
        return pb.start();
    }
}
