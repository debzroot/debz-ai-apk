package ai.debz;

import android.content.Context;
import android.net.ConnectivityManager;
import android.net.LinkProperties;
import android.net.Network;
import android.os.Build;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetAddress;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.zip.ZipEntry;
import java.util.zip.ZipFile;

public final class ProotManager {
    private ProotManager() {}

    public static File binary(Context ctx) throws Exception {
        File f = new File(ctx.getApplicationInfo().nativeLibraryDir, "libproot.so");
        if (f.exists()) {
            f.setExecutable(true);
            return f;
        }
        // installer tidak mengekstrak .so (extractNativeLibs=false):
        // ambil dari APK sendiri ke app-private, exec dari sana.
        String abi = Build.SUPPORTED_ABIS.length > 0
            ? Build.SUPPORTED_ABIS[0] : "arm64-v8a";
        File out = new File(ctx.getFilesDir(), "bin/libproot.so");
        ZipFile apk = new ZipFile(ctx.getApplicationInfo().sourceDir);
        try {
            ZipEntry e = apk.getEntry("lib/" + abi + "/libproot.so");
            if (e == null) throw new Exception("libproot.so tidak ada di APK");
            if (!out.exists() || out.length() != e.getSize()) {
                out.getParentFile().mkdirs();
                try (InputStream in = apk.getInputStream(e);
                     OutputStream o = new FileOutputStream(out)) {
                    byte[] buf = new byte[65536];
                    int n;
                    while ((n = in.read(buf)) > 0) o.write(buf, 0, n);
                }
            }
        } finally {
            apk.close();
        }
        out.setExecutable(true);
        return out;
    }

    public static List<String> baseArgs(Context ctx, File rootfs) throws Exception {
        List<String> a = new ArrayList<>();
        a.add(binary(ctx).getAbsolutePath());
        a.add("-r");
        a.add(rootfs.getAbsolutePath());
        a.add("-0");
        a.add("-b"); a.add("/proc:/proc");
        a.add("-b"); a.add("/sys:/sys");
        // /dev/null wajib ada (redirect shell + nginx emerg tanpa ini)
        a.add("-b"); a.add("/dev:/dev");
        // localhost di dalam proot: rootfs ubuntu-base minim, /etc/hosts
        // belum tentu ada -> bind dari Android biar "localhost" resolve.
        File sysHosts = new File("/system/etc/hosts");
        if (sysHosts.isFile()) {
            a.add("-b"); a.add("/system/etc/hosts:/etc/hosts");
        }
        File sd = new File("/sdcard");
        if (sd.exists()) {
            a.add("-b"); a.add("/sdcard:/mnt/sdcard");
        }
        return a;
    }

    // DNS DI DALAM PROOT MATI kalau file ini tidak ada: Android tidak pakai
    // /etc/resolv.conf (DNS lewat netd), sedangkan glibc di rootfs cuma baca
    // file itu. Akibat: getaddrinfo gagal semua -> CLI/preflight/proxy
    // bisu ("Temporary failure in name resolution"). Tulis tiap exec dari
    // DNS beneran device (LinkProperties), fallback publik.
    public static void ensureResolvConf(Context ctx, File rootfs) {
        try {
            File f = new File(rootfs, "etc/resolv.conf");
            File parent = f.getParentFile();
            if (parent != null) parent.mkdirs();
            StringBuilder sb = new StringBuilder();
            for (String dns : deviceDns(ctx)) {
                sb.append("nameserver ").append(dns).append('\n');
            }
            sb.append("options timeout:2 attempts:2\n");
            try (OutputStream o = new FileOutputStream(f, false)) {
                o.write(sb.toString().getBytes(java.nio.charset.StandardCharsets.UTF_8));
            }
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "resolv.conf gagal: " + e);
        }
    }

    private static List<String> deviceDns(Context ctx) {
        List<String> out = new ArrayList<>();
        try {
            ConnectivityManager cm = (ConnectivityManager)
                ctx.getSystemService(Context.CONNECTIVITY_SERVICE);
            if (cm != null) {
                for (Network n : cm.getAllNetworks()) {
                    LinkProperties lp = cm.getLinkProperties(n);
                    if (lp == null) continue;
                    for (InetAddress a : lp.getDnsServers()) {
                        String ip = a.getHostAddress();
                        if (ip != null && !ip.isEmpty() && !out.contains(ip)) out.add(ip);
                    }
                }
            }
        } catch (Exception ignored) {}
        if (out.isEmpty()) {
            out.add("8.8.8.8");
            out.add("1.1.1.1");
        }
        return out;
    }

    public static Process exec(Context ctx, File rootfs,
                               Map<String, String> env, String... cmd) throws Exception {
        if (env == null) env = StackSupervisor.baseEnv();
        // DNS dulu, sebelum proot jalan: tanpa ini semua hostname gagal.
        ensureResolvConf(ctx, rootfs);
        List<String> a = baseArgs(ctx, rootfs);
        a.add("/bin/sh");
        a.add("-c");
        a.add(String.join(" ", cmd));
        ProcessBuilder pb = new ProcessBuilder(a);
        pb.directory(rootfs);
        if (env != null) pb.environment().putAll(env);
        // dependensi proot (libtalloc, libandroid-shmem, libtermux-exec)
        // tinggal di files/bin, bukan di path standar linker — kasih tahu
        // lewat LD_LIBRARY_PATH
        File libDir = new File(ctx.getFilesDir(), "bin");
        ensureNativeLibs(ctx, libDir);
        // proot Termux butuh loader eksternal; default-nya path Termux
        // (/data/data/com.termux/.../loader) yang tak ada di HP user ->
        // semua execve ENOENT. Loader dibundle di assets, arahkan kesini.
        ensureLoader(ctx, libDir);
        File loader = new File(libDir, "loader");
        if (loader.exists()) {
            loader.setExecutable(true);
            pb.environment().put("PROOT_LOADER", loader.getAbsolutePath());
        }
        File loader32 = new File(libDir, "loader32");
        if (loader32.exists()) {
            loader32.setExecutable(true);
            pb.environment().put("PROOT_LOADER_32", loader32.getAbsolutePath());
        }
        String oldLp = pb.environment().get("LD_LIBRARY_PATH");
        pb.environment().put("LD_LIBRARY_PATH", libDir.getAbsolutePath()
            + (oldLp != null && !oldLp.isEmpty() ? ":" + oldLp : ""));
        // binary proot dikompilasi untuk Termux: TMPDIR default-nya
        // /data/data/com.termux/... yang tak ada di HP ini -> arahkan ke
        // app-private biar glue rootfs bisa kebentuk
        File tmpDir = new File(ctx.getFilesDir(), "proot-tmp");
        tmpDir.mkdirs();
        pb.environment().put("PROOT_TMP_DIR", tmpDir.getAbsolutePath());
        pb.redirectErrorStream(true);
        return pb.start();
    }

    // loader proot (lihat atas): dari assets ke files/bin, sekali aja
    private static void ensureLoader(Context ctx, File libDir) throws Exception {
        String[] assets = ctx.getAssets().list("");
        if (assets == null) return;
        for (String name : assets) {
            if (!"loader".equals(name) && !"loader32".equals(name)) continue;
            File out = new File(libDir, name);
            // file milik uid lama (install -k/reinstall) tak bisa dieksekusi
            // -> buang, salin ulang sebagai uid sekarang.
            if (out.exists()) {
                if (out.canWrite()) {
                    out.setExecutable(true);
                    continue;
                }
                out.delete();
            }
            libDir.mkdirs();
            try (InputStream in = ctx.getAssets().open(name);
                 OutputStream o = new FileOutputStream(out)) {
                byte[] buf = new byte[65536];
                int n;
                while ((n = in.read(buf)) > 0) o.write(buf, 0, n);
            } catch (Exception ignored) {}
            out.setExecutable(true);
        }
    }

    // semua lib*.so* di assets (dependensi proot) diekstrak sekali ke bin
    private static void ensureNativeLibs(Context ctx, File libDir) throws Exception {
        String[] assets = ctx.getAssets().list("");
        if (assets == null) return;
        for (String name : assets) {
            if (name == null || !name.startsWith("lib")) continue;
            if (!name.endsWith(".so") && !name.contains(".so.")) continue;
            File out = new File(libDir, name);
            if (out.exists()) {
                if (out.canWrite()) continue;
                out.delete();
            }
            libDir.mkdirs();
            try (InputStream in = ctx.getAssets().open(name);
                 OutputStream o = new FileOutputStream(out)) {
                byte[] buf = new byte[65536];
                int n;
                while ((n = in.read(buf)) > 0) o.write(buf, 0, n);
            }
        }
    }
}
