package ai.debz;

import java.io.File;
import java.util.concurrent.TimeUnit;

public final class RootDetector {
    private RootDetector() {}

    public static boolean suExists() {
        String[] paths = {
            "/system/bin/su", "/system/xbin/su",
            "/sbin/su", "/su/bin/su",
            "/magisk/.core/bin/su", "/data/adb/magisk/busybox"
        };
        for (String p : paths) {
            if (new File(p).exists()) return true;
        }
        String path = System.getenv("PATH");
        if (path != null) {
            for (String dir : path.split(":")) {
                if (new File(dir, "su").exists()) return true;
            }
        }
        return false;
    }

    public static boolean suWorks() {
        if (!suExists()) return false;
        try {
            Process p = new ProcessBuilder("su", "-c", "id").start();
            boolean done = p.waitFor(5, TimeUnit.SECONDS);
            return done && p.exitValue() == 0;
        } catch (Exception e) {
            return false;
        }
    }
}
