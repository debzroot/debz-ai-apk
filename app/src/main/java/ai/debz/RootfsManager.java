package ai.debz;

import android.content.Context;
import java.io.BufferedInputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.io.RandomAccessFile;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.MessageDigest;
import org.apache.commons.compress.archivers.tar.TarArchiveEntry;
import org.apache.commons.compress.archivers.tar.TarArchiveInputStream;
import org.apache.commons.compress.compressors.gzip.GzipCompressorInputStream;

public final class RootfsManager {
    private RootfsManager() {}

    public interface Progress {
        void on(String stage, int percent);
    }

    public static File dir(Context ctx) {
        return new File(ctx.getFilesDir(), "rootfs");
    }

    public static File marker(Context ctx) {
        return new File(dir(ctx), ".ready");
    }

    public static boolean ready(Context ctx) {
        return marker(ctx).exists() && new File(dir(ctx), "opt/debz/start-stack.sh").exists();
    }

    public static boolean hasBundled(Context ctx) {
        try {
            for (String n : ctx.getAssets().list("")) {
                if ("rootfs-mini.tar.gz".equals(n)) return true;
            }
        } catch (Exception ignored) {}
        return false;
    }

    public static void ensureFromAssets(Context ctx, Progress cb) throws Exception {
        if (ready(ctx)) return;
        File d = dir(ctx);
        d.mkdirs();
        File tgz = new File(d, "rootfs-mini.tar.gz");
        try (InputStream in = ctx.getAssets().open("rootfs-mini.tar.gz");
             OutputStream out = new FileOutputStream(tgz)) {
            byte[] buf = new byte[65536];
            int n;
            while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
        }
        if (cb != null) cb.on("copy", 50);
        String sha = "";
        try (InputStream in = ctx.getAssets().open("SHA256SUMS")) {
            byte[] b = new byte[256];
            int n = in.read(b);
            if (n > 0) sha = new String(b, 0, n, "UTF-8").trim().split("\\s+")[0];
        } catch (Exception ignored) {}
        verify(tgz, sha, cb);
        extract(tgz, d, cb);
        if (!marker(ctx).createNewFile()) throw new Exception("marker gagal");
        tgz.delete();
        if (cb != null) cb.on("done", 100);
    }

    public static void ensure(Context ctx, String url, String sha256,
                              String token, Progress cb) throws Exception {
        if (ready(ctx)) return;
        File d = dir(ctx);
        d.mkdirs();
        File part = new File(d, "rootfs-mini.tar.gz.part");
        File tgz = new File(d, "rootfs-mini.tar.gz");
        download(url, token, part, tgz, cb);
        verify(tgz, sha256, cb);
        extract(tgz, d, cb);
        if (!marker(ctx).createNewFile()) throw new Exception("marker gagal");
        tgz.delete();
        if (cb != null) cb.on("done", 100);
    }

    private static void download(String url, String token, File part,
                                 File tgz, Progress cb) throws Exception {
        long done = part.exists() ? part.length() : 0;
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        if (token != null && !token.isEmpty()) {
            c.setRequestProperty("Authorization", "Bearer " + token);
        }
        if (done > 0) c.setRequestProperty("Range", "bytes=" + done + "-");
        c.connect();
        int code = c.getResponseCode();
        long total = c.getHeaderFieldLong("Content-Length", -1);
        if (code == 200 && done > 0) {
            done = 0;
            part.delete();
        } else if (code != 200 && code != 206) {
            throw new Exception("download HTTP " + code);
        }
        try (InputStream in = new BufferedInputStream(c.getInputStream());
             OutputStream out = new FileOutputStream(part, code == 206)) {
            byte[] buf = new byte[65536];
            int n;
            long got = done;
            int lastPct = -1;
            while ((n = in.read(buf)) > 0) {
                out.write(buf, 0, n);
                got += n;
                if (total > 0 && cb != null) {
                    int pct = (int) (got * 50 / (total + done));
                    if (pct != lastPct) {
                        lastPct = pct;
                        cb.on("download", Math.min(pct, 50));
                    }
                }
            }
        } finally {
            c.disconnect();
        }
        if (!part.renameTo(tgz)) throw new Exception("rename part gagal");
    }

    private static void verify(File tgz, String sha256, Progress cb) throws Exception {
        if (sha256 == null || sha256.isEmpty()) return;
        if (cb != null) cb.on("verify", 55);
        MessageDigest md = MessageDigest.getInstance("SHA-256");
        try (InputStream in = new BufferedInputStream(new FileInputStream(tgz))) {
            byte[] buf = new byte[65536];
            int n;
            while ((n = in.read(buf)) > 0) md.update(buf, 0, n);
        }
        StringBuilder sb = new StringBuilder();
        for (byte b : md.digest()) sb.append(String.format("%02x", b));
        if (!sb.toString().equalsIgnoreCase(sha256.trim())) {
            tgz.delete();
            throw new Exception("SHA256 tidak cocok");
        }
    }

    private static void extract(File tgz, File dest, Progress cb) throws Exception {
        if (cb != null) cb.on("extract", 60);
        long total = tgz.length();
        long read = 0;
        int lastPct = 60;
        try (InputStream fi = new BufferedInputStream(new FileInputStream(tgz));
             GzipCompressorInputStream gz = new GzipCompressorInputStream(fi);
             TarArchiveInputStream tar = new TarArchiveInputStream(gz)) {
            TarArchiveEntry e;
            while ((e = tar.getNextEntry()) != null) {
                File f = new File(dest, e.getName());
                if (!f.getCanonicalPath().startsWith(dest.getCanonicalPath())) {
                    throw new Exception("path traversal: " + e.getName());
                }
                if (e.isDirectory()) {
                    f.mkdirs();
                } else {
                    f.getParentFile().mkdirs();
                    try (OutputStream o = new FileOutputStream(f)) {
                        byte[] buf = new byte[65536];
                        int n;
                        while ((n = tar.read(buf)) > 0) o.write(buf, 0, n);
                    }
                    if ((e.getMode() & 0100) != 0) f.setExecutable(true);
                }
                read += e.getSize();
                if (cb != null) {
                    int pct = 60 + (int) (read * 35 / Math.max(total, 1));
                    if (pct != lastPct && pct <= 95) {
                        lastPct = pct;
                        cb.on("extract", pct);
                    }
                }
            }
        }
        // skrip stack harus executable
        new File(dest, "opt/debz/start-stack.sh").setExecutable(true);
        new File(dest, "opt/debz/stop-stack.sh").setExecutable(true);
    }

    // hapus RF lama (dipakai OTA/ganti versi)
    public static void wipe(Context ctx) {
        deleteRec(dir(ctx));
    }

    private static void deleteRec(File f) {
        if (f.isDirectory()) {
            File[] kids = f.listFiles();
            if (kids != null) for (File k : kids) deleteRec(k);
        }
        f.delete();
    }
}
