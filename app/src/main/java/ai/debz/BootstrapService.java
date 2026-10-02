package ai.debz;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;
import java.io.File;
import java.io.FileWriter;

public class BootstrapService extends Service {
    public static final String EXTRA_OFFSET = "ai.debz.EXTRA_OFFSET";
    private static final int NOTIF_ID = 1001;
    private static final String CHANNEL = "debz_backend";
    // cegah boot ganda numpuk (buka-tutup app cepat = proot pip dobel).
    private static final java.util.concurrent.atomic.AtomicBoolean BOOTING =
        new java.util.concurrent.atomic.AtomicBoolean(false);

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        startForeground(NOTIF_ID, buildNotif());
        int offset = intent != null ? intent.getIntExtra(EXTRA_OFFSET, 0) : 0;
        DebzConfig.setPortOffset(this, offset);
        new Thread(() -> boot(offset, this)).start();
        return START_STICKY;
    }

    private void boot(int offset, android.content.Context ctx) {
        if (!BOOTING.compareAndSet(false, true)) return;
        // WakeLock selama boot: CPU tidur + layar mati di tengah extract/
        // firstboot = stall. Timeout 15 mnt = pengaman mutlak.
        android.os.PowerManager.WakeLock wl = null;
        try {
            android.os.PowerManager pm = (android.os.PowerManager)
                getSystemService(android.content.Context.POWER_SERVICE);
            if (pm != null) {
                wl = pm.newWakeLock(
                    android.os.PowerManager.PARTIAL_WAKE_LOCK, "debz:boot");
                wl.acquire(15 * 60 * 1000L);
            }
        } catch (Exception ignored) {}
        try {
            bootInner(offset, ctx);
        } finally {
            BOOTING.set(false);
            try {
                if (wl != null && wl.isHeld()) wl.release();
            } catch (Exception ignored) {}
        }
    }

    private void bootInner(int offset, android.content.Context ctx) {
        // GATE IDEMPOTEN — fix "buka app = menyalakan stack lagi".
        // Kalau stack sudah hidup di port tersimpan: langsung "up", TANPA
        // exec proot, TANPA random port, TANPA start ulang.
        try {
            if (RootfsManager.ready(ctx) && stackAlive(ctx)) {
                if (watchdogAlive(ctx)) {
                    android.util.Log.i("DebzAI", "stack sudah up + sentinel ok, skip boot");
                    DebzConfig.setStatus(ctx, "up");
                    otaCheckAsync(ctx);
                    backupAsync(ctx);
                    startBridge(ctx);
                    return;
                }
                // stack nyala tapi yatim (watchdog mati, sisa kill lama):
                // jangan dibiarkan — jatuh ke boot penuh = restart bersih
                // + sentinel baru.
                android.util.Log.w("DebzAI", "stack up tapi orphan, restart bersih");
            }
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "gate cek gagal, lanjut boot penuh: " + e);
        }
        DebzConfig.setStatus(ctx, "booting");
        android.util.Log.i("DebzAI", "boot mulai offset=" + offset);
        try {
            RootfsManager.selfHeal(ctx);
            android.util.Log.i("DebzAI", "selfHeal ok, ready=" + RootfsManager.ready(ctx));
            boolean rooted = RootDetector.suWorks();
            DebzConfig.setRootMode(ctx, rooted);
            // PORT REUSE: port yang SAMA dipakai ulang. Random tiap buka app
            // (= bug lama) bikin .serve.json geser + stack lama jadi yatim.
            int web = reuseOrTake(ctx, DebzConfig.webPort(ctx), 8091 + offset);
            int api = reuseOrTake(ctx, DebzConfig.apiPort(ctx), 8092 + offset);
            int fpm = PortManager.takePreferred(9000 + offset);
            int tools = reuseOrTake(ctx, DebzConfig.toolsPort(ctx), 9191 + offset);
            DebzConfig.setPorts(ctx, web, api, tools);

            String url = DebzConfig.rootfsUrl(ctx);
            String sha = ""; // TODO: isi dari OTA manifest
            if (!RootfsManager.ready(ctx)) {
                if (RootfsManager.hasBundled(ctx)) {
                    DebzConfig.setStatus(ctx, "extract-rootfs");
                    RootfsManager.ensureFromAssets(ctx,
                        (stage, pct) -> DebzConfig.setStatus(ctx, stage + ":" + pct));
                } else if (!url.isEmpty()) {
                    DebzConfig.setStatus(ctx, "download-rootfs");
                    RootfsManager.ensure(ctx, url, sha, DebzConfig.token(ctx),
                        (stage, pct) -> DebzConfig.setStatus(ctx, stage + ":" + pct));
                }
            }
            if (RootfsManager.ready(ctx)) {
                // Bounded retry 3x: HP kentang kadang butuh 2x start (daemon
                // ke-OOM / race bind). App sembuh sendiri — user JANGAN
                // disuruh tutup-buka manual lagi.
                String out = "";
                boolean ok = false;
                for (int attempt = 1; attempt <= 3 && !ok; attempt++) {
                    if (attempt > 1) {
                        DebzConfig.setStatus(ctx, "retry-stack");
                        android.util.Log.i("DebzAI", "retry stack " + attempt + "/3");
                        try {
                            Thread.sleep(5000);
                        } catch (InterruptedException ie) {
                            break;
                        }
                        try {
                            StackSupervisor.stop(ctx, RootfsManager.dir(ctx));
                        } catch (Exception se) {
                            android.util.Log.w("DebzAI", "cleanup retry gagal: " + se);
                        }
                    } else {
                        DebzConfig.setStatus(ctx, "starting-stack");
                    }
                    android.util.Log.i("DebzAI", "stack start web=" + web + " api=" + api
                        + " try=" + attempt);
                    out = StackSupervisor.start(ctx, RootfsManager.dir(ctx),
                        StackSupervisor.envFor(web, api, fpm, tools));
                    ok = StackSupervisor.healthyRetry("http://127.0.0.1:" + web + "/", 10);
                }
                if (!ok) saveStackLog(ctx, out);
                android.util.Log.i("DebzAI", "stack akhir ok=" + ok);
                DebzConfig.setStatus(ctx, ok ? "up" : "stack-fail");
                if (ok) otaCheckAsync(ctx);
                if (ok) backupAsync(ctx);
                if (ok) startBridge(ctx);
            } else {
                DebzConfig.setStatus(ctx, "no-rootfs");
            }
        } catch (Exception e) {
            // class + message biar status kepaca ("FileSystemException: ..."),
            // stack trace penuh tetap ke logcat buat diagnosa lanjutan.
            android.util.Log.e("DebzAI", "boot gagal", e);
            DebzConfig.setStatus(ctx, "error:" + e.getClass().getSimpleName()
                + ": " + e.getMessage());
        }
    }

    // OTA rolling: cek rilisan di background (throttle 6 jam di dalam).
    // Ada update = notif sistem, sekali per rilisan. Gagal = diam.
    private static void otaCheckAsync(android.content.Context ctx) {
        try {
            final android.content.Context appCtx = ctx.getApplicationContext();
            new Thread(() -> {
                try {
                    OtaManager.checkForUpdate(appCtx);
                } catch (Exception e) {
                    android.util.Log.w("DebzAI", "ota check skip: " + e);
                }
            }).start();
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "ota thread skip: " + e);
        }
    }

    // BACKUP BERKALA: tiap boot sukses, sinkron Workspaces/notes.db/config
    // ke /sdcard/debz-backup/ (lolos uninstall). Best-effort di thread
    // sendiri biar tak hambat status "up". Tanpa ini backup cuma jalan
    // pas wipe epoch = uninstall manual tetap hilang semua.
    private static void backupAsync(android.content.Context ctx) {
        try {
            final android.content.Context appCtx = ctx.getApplicationContext();
            new Thread(() -> {
                try {
                    String p = RootfsManager.backupNow(appCtx);
                    if (p != null && !p.isEmpty()) {
                        android.util.Log.i("DebzAI", "backup berkala ok: " + p);
                    }
                } catch (Exception e) {
                    android.util.Log.w("DebzAI", "backup berkala skip: " + e);
                }
            }).start();
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "backup thread skip: " + e);
        }
    }

    // Android bridge: localhost HTTP buat agent eksekusi perintah sisi
    // Android/root (pm, dumpsys, settings, input ...). Marker dibaca backend.
    private static void startBridge(android.content.Context ctx) {
        try {
            int saved = DebzConfig.abridgePort(ctx);
            int port = saved > 0 ? saved : PortManager.takePreferred(
                8098 + DebzConfig.portOffset(ctx));
            int bound = BridgeServer.start(ctx, port);
            if (bound > 0) {
                DebzConfig.setAbridgePort(ctx, bound);
                writeBridgeMarker(ctx, bound);
            }
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "bridge skip: " + e);
        }
    }

    private static void writeBridgeMarker(android.content.Context ctx,
                                          int port) {
        try {
            java.io.File f = new java.io.File(
                RootfsManager.dir(ctx), "opt/debz/.android_bridge.json");
            if (f.getParentFile() != null) f.getParentFile().mkdirs();
            String json = "{\"port\":" + port + ",\"token\":\""
                + DebzConfig.abridgeToken(ctx) + "\",\"root\":"
                + RootDetector.suWorks() + "}";
            java.io.FileWriter w = new java.io.FileWriter(f, false);
            w.write(json);
            w.close();
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "bridge marker gagal: " + e);
        }
    }

    // output start stack terakhir, dibaca dari device saat stack-fail
    private static void saveStackLog(android.content.Context ctx, String out) {        try {
            FileWriter w = new FileWriter(new File(ctx.getFilesDir(), "stack-last.log"), false);
            w.write(out != null && !out.isEmpty() ? out : "(kosong)");
            w.close();
        } catch (Exception ignored) {}
    }

    // web + api dua-duanya jawab = stack beneran hidup (bukan port nyangkut).
    // api = `opencode serve` yang butuh auth: 401 pun dihitung hidup, yang
    // penting ada yang listen.
    private static boolean stackAlive(android.content.Context ctx) {
        int web = DebzConfig.webPort(ctx);
        if (web <= 0) return false;
        if (!StackSupervisor.healthyRetry("http://127.0.0.1:" + web + "/", 2)) return false;
        int api = DebzConfig.apiPort(ctx);
        if (api > 0 && !StackSupervisor.healthyRetry("http://127.0.0.1:" + api + "/", 1)) {
            return false;
        }
        return true;
    }

    // sentinel watchdog hidup? kernel sama, /proc kelihatan dari luar proot.
    private static boolean watchdogAlive(android.content.Context ctx) {
        try {
            java.io.File pidFile = new java.io.File(
                RootfsManager.dir(ctx), "opt/debz/logs/watchdog.pid");
            if (!pidFile.isFile()) return false;
            String s = new String(java.nio.file.Files.readAllBytes(
                pidFile.toPath()), java.nio.charset.StandardCharsets.UTF_8).trim();
            int pid = Integer.parseInt(s);
            if (pid <= 0) return false;
            if (!new java.io.File("/proc/" + pid).isDirectory()) return false;
            String cmd = new String(java.nio.file.Files.readAllBytes(
                new java.io.File("/proc/" + pid + "/cmdline").toPath()),
                java.nio.charset.StandardCharsets.UTF_8);
            return cmd.contains("watchdog");
        } catch (Exception e) {
            return false;
        }
    }

    // port tersimpan dipakai ulang. Kalau kepegang sisa stack mati: bersihkan
    // dulu, tunggu lepas, pakai LAGI port yang sama. Random cuma last resort
    // kalau port beneran dipakai app lain.
    private static int reuseOrTake(android.content.Context ctx, int saved, int preferred) {
        int want = saved > 0 ? saved : preferred;
        if (PortManager.canBind(want)) return want;
        try {
            if (RootfsManager.ready(ctx)) {
                StackSupervisor.stop(ctx, RootfsManager.dir(ctx));
            }
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "cleanup port " + want + " gagal: " + e);
        }
        for (int i = 0; i < 4 && !PortManager.canBind(want); i++) {
            try {
                Thread.sleep(700);
            } catch (InterruptedException ie) {
                break;
            }
        }
        if (PortManager.canBind(want)) {
            android.util.Log.i("DebzAI", "port " + want + " ke-free, reuse");
            return want;
        }
        android.util.Log.w("DebzAI", "port " + want + " macet, fallback random");
        return PortManager.takePreferred(preferred);
    }

    private Notification buildNotif() {        NotificationManager nm = getSystemService(NotificationManager.class);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && nm != null) {
            NotificationChannel ch = new NotificationChannel(
                CHANNEL, "Debz AI backend", NotificationManager.IMPORTANCE_LOW);
            nm.createNotificationChannel(ch);
        }
        Notification.Builder b;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            b = new Notification.Builder(this, CHANNEL);
        } else {
            b = new Notification.Builder(this);
        }
        return b.setContentTitle("Debz AI")
            .setContentText("Backend starting…")
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .build();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
