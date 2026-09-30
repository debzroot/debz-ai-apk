package ai.debz;

import android.content.Context;
import java.io.BufferedInputStream;
import java.io.ByteArrayOutputStream;
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

    // naikkan tiap tarball berubah tak-kompatibel (backend baru, conf
    // baru): device wipe + extract ulang otomatis, tanpa pm clear.
    // 13 = php-curl/mbstring/sqlite3 aktif + save anti-fatal + empty-retry.
    private static final int ROOTFS_EPOCH = 13;

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

    // data milik install lama (uid beda, mode 0700) bikin EACCES abadi
    // di semua exec. tulis-cek gagal = tree sampah -> wipe; wipe gagal
    // juga = user wajib Clear storage (pesan jelas, bukan error samar).
    public static void selfHeal(Context ctx) throws Exception {
        File d = dir(ctx);
        if (writableDir(d)) return;
        if (ready(ctx)) {
            throw new Exception("rootfs tak bisa ditulis "
                + "(milik install lama?), Clear storage di Settings > Apps > Debz AI");
        }
        wipe(ctx);
        if (!writableDir(d)) {
            throw new Exception("rootfs tak bisa ditulis setelah wipe, "
                + "Clear storage di Settings > Apps > Debz AI");
        }
    }

    private static boolean writableDir(File d) {
        if (!d.exists()) return true;
        File t = new File(d, ".writetest");
        try {
            if (t.createNewFile()) {
                t.delete();
                return true;
            }
        } catch (Exception ignored) {}
        return false;
    }

    public static boolean hasBundled(Context ctx) {
        return bundledName(ctx) != null;
    }

    private static boolean epochOk(Context ctx) {
        try {
            java.util.Scanner s = new java.util.Scanner(new File(dir(ctx), ".epoch"));
            boolean ok = s.hasNextInt() && s.nextInt() == ROOTFS_EPOCH;
            s.close();
            return ok;
        } catch (Exception e) {
            return false;
        }
    }

    private static void writeEpoch(Context ctx) {
        try {
            java.io.FileWriter w = new java.io.FileWriter(new File(dir(ctx), ".epoch"), false);
            w.write(String.valueOf(ROOTFS_EPOCH));
            w.close();
        } catch (Exception ignored) {}
    }

    // nama file aktual di assets: rantai CI kadang menyimpan .tar polos
    // (bukan .tar.gz) — deteksi prefix biar dua-duanya jalan.
    public static String bundledName(Context ctx) {
        try {
            for (String n : ctx.getAssets().list("")) {
                if (n != null && n.startsWith("rootfs-mini.tar")) return n;
            }
        } catch (Exception ignored) {}
        return null;
    }

    public static void ensureFromAssets(Context ctx, Progress cb) throws Exception {
        healNested(ctx);
        if (ready(ctx) && !new File(dir(ctx), "bin/sh").exists()) {
            wipe(ctx);
        }
        // tarball baru (epoch beda) -> extract ulang biar backend/conf baru
        // kepasang; kalau nggak, device jalanin skrip lawas selamanya.
        if (ready(ctx) && !epochOk(ctx)) wipe(ctx);
        if (ready(ctx)) return;
        String asset = bundledName(ctx);
        if (asset == null) throw new Exception("rootfs tidak dibundle di APK");
        boolean gzipped = asset.endsWith(".gz");
        File d = dir(ctx);
        // tree tanpa marker = sisa extract gagal -> buang dulu, kalau nggak
        // file korup (dir nyasar di path link dsb) bikin extract gagal abadi.
        if (d.exists()) wipe(ctx);
        d.mkdirs();
        File pkg = new File(d, "rootfs-mini.pkg");
        // progress copy WAJIB byte-based 0-45%: copy asset 130MB+ tanpa
        // feedback = bar diem menit-menit, user kira hang (versi lama cuma
        // lapor "copy:50" sekali di akhir).
        long assetLen = -1;
        try (android.content.res.AssetFileDescriptor afd = ctx.getAssets().openFd(asset)) {
            assetLen = afd.getLength();
        } catch (Exception ignored) {}
        try (InputStream in = ctx.getAssets().open(asset);
             OutputStream out = new FileOutputStream(pkg)) {
            byte[] buf = new byte[65536];
            int n;
            long copied = 0;
            int lastPct = -1;
            while ((n = in.read(buf)) > 0) {
                out.write(buf, 0, n);
                if (assetLen > 0 && cb != null) {
                    copied += n;
                    int pct = (int) (copied * 45 / assetLen);
                    if (pct != lastPct && pct < 45) {
                        lastPct = pct;
                        cb.on("copy", pct);
                    }
                }
            }
        }
        if (cb != null) cb.on("copy", 50);
        String[] sum = readSums(ctx);
        if (sum != null && asset.equals(sum[1])) verify(pkg, sum[0], cb);
        extract(pkg, gzipped, d, cb);
        if (!marker(ctx).createNewFile()) throw new Exception("marker gagal");
        writeEpoch(ctx);
        pkg.delete();
        if (cb != null) cb.on("done", 100);
    }

    // tarball lama nyarang: skrip di opt/debz/opt-debz, bukan opt/debz.
    // Flatten (rename, milidetik) biar extract 411MB nggak keulang sia-sia.
    private static void healNested(Context ctx) {
        File outer = new File(dir(ctx), "opt/debz");
        File inner = new File(outer, "opt-debz");
        if (!inner.isDirectory() || new File(outer, "start-stack.sh").exists()) return;
        File[] kids = inner.listFiles();
        if (kids == null) return;
        for (File k : kids) {
            File dst = new File(outer, k.getName());
            if (dst.exists()) continue;
            if (k.renameTo(dst) && dst.getName().endsWith(".sh")) {
                dst.setExecutable(true);
            }
        }
        inner.delete();
    }

    // "hash  filename" ala sha256sum; null kalau tak terbaca
    private static String[] readSums(Context ctx) {
        try (InputStream in = ctx.getAssets().open("SHA256SUMS")) {
            ByteArrayOutputStream b = new ByteArrayOutputStream();
            byte[] buf = new byte[512];
            int n;
            while ((n = in.read(buf)) > 0) b.write(buf, 0, n);
            String[] p = b.toString("UTF-8").trim().split("\\s+");
            if (p.length >= 2) return new String[]{p[0], p[1]};
            if (p.length == 1) return new String[]{p[0], ""};
        } catch (Exception ignored) {}
        return null;
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
        extract(tgz, true, d, cb);
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

    private static void extract(File pkg, boolean gzipped, File dest, Progress cb) throws Exception {
        if (cb != null) cb.on("extract", 60);
        long total = pkg.length();
        long read = 0;
        int lastPct = 60;
        // link ditunda ke phase 2: GNU tar boleh menaruh hardlink SEBELUM
        // targetnya (uncompress -> gunzip); one-pass = createLink gagal.
        java.util.List<TarArchiveEntry> links = new java.util.ArrayList<>();
        InputStream fi = new BufferedInputStream(new FileInputStream(pkg));
        InputStream uncompressed = gzipped ? new GzipCompressorInputStream(fi) : fi;
        try (TarArchiveInputStream tar = new TarArchiveInputStream(uncompressed)) {
            TarArchiveEntry e;
            while ((e = tar.getNextEntry()) != null) {
                if (e.isSymbolicLink() || e.isLink()) {
                    links.add(e);
                    continue;
                }
                File f = new File(dest, e.getName());
                if (!f.getCanonicalPath().startsWith(dest.getCanonicalPath())) {
                    throw new Exception("path traversal: " + e.getName());
                }
                if (e.isDirectory()) {
                    if (f.exists() && !f.isDirectory()) f.delete();
                    f.mkdirs();
                } else {
                    f.getParentFile().mkdirs();
                    // dir nyasar di path file (sisa run korup) = gusur juga
                    if (f.isDirectory()
                        && !java.nio.file.Files.isSymbolicLink(f.toPath())) deleteRec(f);
                    try (OutputStream o = new FileOutputStream(f)) {
                        byte[] buf = new byte[65536];
                        int n;
                        while ((n = tar.read(buf)) > 0) o.write(buf, 0, n);
                    }
                    if ((e.getMode() & 0100) != 0) f.setExecutable(true);
                }
                read += e.getSize();
                if (cb != null) {
                    int pct = 60 + (int) (read * 30 / Math.max(total, 1));
                    if (pct != lastPct && pct <= 90) {
                        lastPct = pct;
                        cb.on("extract", pct);
                    }
                }
            }
        }
        // phase 2: symlink + hardlink. Satu link gagal != extract gagal;
        // kumpulin, lapor di akhir. Sisa run gagal (file/dir di path link
        // = korup) digusur dulu biar nggak EPERM abadi.
        java.util.List<String> linkFail = new java.util.ArrayList<>();
        int done = 0;
        for (TarArchiveEntry e : links) {
            try {
                makeLink(dest, e);
            } catch (Exception ex) {
                linkFail.add(e.getName() + ": " + ex.getClass().getSimpleName()
                    + " " + ex.getMessage());
            }
            done++;
            if (cb != null && !links.isEmpty()) {
                int pct = 90 + done * 5 / links.size();
                if (pct != lastPct) {
                    lastPct = pct;
                    cb.on("extract", pct);
                }
            }
        }
        // repair usrmerge yang dilibas .deb (bin/sbin/lib jadi dir
        // beneran): gabung isi ke usr/*, pasang ulang symlink. Tanpa ini
        // /bin/sh + /lib/ld-* nggak ada -> proot gagal start.
        repairMerges(dest);
        // skrip stack harus executable
        new File(dest, "opt/debz/start-stack.sh").setExecutable(true);
        new File(dest, "opt/debz/stop-stack.sh").setExecutable(true);
        new File(dest, "opt/debz/watchdog.sh").setExecutable(true);
        // canary: symlink usrmerge (bin/sh) + pasangan pemicu error user
        // (uncompress->gunzip) wajib ada; kalau nggak berarti tree korup.
        String[] canary = {"bin/sh", "usr/bin/gunzip", "usr/bin/uncompress",
            "opt/debz/start-stack.sh"};
        java.util.List<String> missing = new java.util.ArrayList<>();
        for (String c : canary) if (!new File(dest, c).exists()) missing.add(c);
        if (!missing.isEmpty() || !linkFail.isEmpty()) {
            throw new Exception("extract tak lengkap, hilang=" + missing
                + " link-gagal=" + linkFail.subList(0, Math.min(3, linkFail.size())));
        }
    }

    private static void makeLink(File dest, TarArchiveEntry e) throws Exception {
        File f = new File(dest, e.getName());
        if (!f.getCanonicalPath().startsWith(dest.getCanonicalPath())) {
            throw new Exception("path traversal: " + e.getName());
        }
        f.getParentFile().mkdirs();
        String pair = mergeTargetFor(e.getName());
        if (pair != null && f.isDirectory()
            && !java.nio.file.Files.isSymbolicLink(f.toPath())) {
            // entry symlink usrmerge (bin->usr/bin) nabrak dir beneran
            // penuh isi .deb: gabung dulu ke target, baru pasang symlink.
            mergeTree(f, new File(dest, pair));
            deleteRec(f);
        } else if (java.nio.file.Files.isSymbolicLink(f.toPath())) {
            f.delete();
        } else if (f.isDirectory()) {
            deleteRec(f);
        } else {
            f.delete();
        }
        if (e.isSymbolicLink()) {
            String ln = e.getLinkName();
            try {
                java.nio.file.Files.createSymbolicLink(
                    f.toPath(), java.nio.file.Paths.get(ln));
            } catch (Exception first) {
                // symlink diblokir -> salin isi kalau targetnya file biasa
                File r = f.getParentFile().toPath()
                    .resolve(ln).normalize().toFile();
                if (!r.isFile()) throw first;
                copyFile(r, f);
            }
        } else {
            java.nio.file.Path target =
                dest.toPath().resolve(e.getLinkName()).normalize();
            if (!target.startsWith(dest.toPath())) {
                throw new Exception("hardlink traversal: " + e.getName());
            }
            try {
                java.nio.file.Files.createLink(f.toPath(), target);
            } catch (Exception first) {
                // hardlink diblokir (EACCES di f2fs HP ini) -> salin isi,
                // setara fungsi buat rootfs (cuma nambah byte).
                if (!target.toFile().isFile()) throw first;
                copyFile(target.toFile(), f);
                if ((e.getMode() & 0100) != 0) f.setExecutable(true);
            }
        }
    }

    // pasangan usrmerge yang dilibas .deb jadi dir beneran (dpkg-deb -x
    // nimpa symlink bin/lib/sbin): kembalikan ke symlink biar /bin/sh +
    // ELF interpreter /lib/ld-* jalan di proot. null kalau bukan pasangan.
    private static String mergeTargetFor(String entryName) {
        String n = entryName.startsWith("./")
            ? entryName.substring(2) : entryName;
        if (n.equals("bin")) return "usr/bin";
        if (n.equals("sbin")) return "usr/sbin";
        if (n.equals("lib")) return "usr/lib";
        if (n.equals("lib64")) return "usr/lib";
        return null;
    }

    // sweep pasangan yang bahkan nggak ada entry symlink-nya di tarball
    // (dir beneran doang): gabung isi ke target, pasang symlink.
    private static void repairMerges(File dest) throws Exception {
        String[][] pairs = {{"bin", "usr/bin"}, {"sbin", "usr/sbin"},
            {"lib", "usr/lib"}, {"lib64", "usr/lib"}};
        for (String[] p : pairs) {
            File link = new File(dest, p[0]);
            File usp = new File(dest, p[1]);
            if (java.nio.file.Files.isSymbolicLink(link.toPath())) continue;
            if (!usp.isDirectory()) continue;
            if (link.isDirectory()
                && !java.nio.file.Files.isSymbolicLink(link.toPath())) {
                mergeTree(link, usp);
                deleteRec(link);
            } else if (!link.exists()) {
                // gelap total: bikin symlink-nya sekalian
            } else {
                continue;
            }
            try {
                java.nio.file.Files.createSymbolicLink(
                    link.toPath(), java.nio.file.Paths.get(p[1]));
            } catch (Exception ignored) {}
        }
    }

    // gabung isi src ke dst (deb menang ala dpkg overwrite), src dikosongkan
    private static void mergeTree(File src, File dst) throws Exception {
        File[] kids = src.listFiles();
        if (kids == null) return;
        for (File c : kids) {
            File t = new File(dst, c.getName());
            boolean cDir = c.isDirectory()
                && !java.nio.file.Files.isSymbolicLink(c.toPath());
            boolean tDir = t.isDirectory()
                && !java.nio.file.Files.isSymbolicLink(t.toPath());
            if (cDir && tDir) {
                mergeTree(c, t);
                continue;
            }
            if (t.exists()
                || java.nio.file.Files.isSymbolicLink(t.toPath())) deleteRec(t);
            java.nio.file.Files.move(c.toPath(), t.toPath());
        }
    }

    private static void copyFile(File src, File dst) throws Exception {
        try (InputStream in = new BufferedInputStream(new FileInputStream(src));
             OutputStream o = new FileOutputStream(dst)) {
            byte[] buf = new byte[65536];
            int n;
            while ((n = in.read(buf)) > 0) o.write(buf, 0, n);
        }
    }

    // hapus RF lama (dipakai OTA/ganti versi)
    public static void wipe(Context ctx) {
        deleteRec(dir(ctx));
    }

    private static void deleteRec(File f) {
        // symlink ke dir jangan di-follow, hapus linknya aja
        if (java.nio.file.Files.isSymbolicLink(f.toPath())) {
            f.delete();
            return;
        }
        if (f.isDirectory()) {
            File[] kids = f.listFiles();
            if (kids != null) for (File k : kids) deleteRec(k);
        }
        f.delete();
    }
}
