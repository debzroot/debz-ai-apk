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
        try {
            bootInner(offset, ctx);
        } finally {
            BOOTING.set(false);
        }
    }

    private void bootInner(int offset, android.content.Context ctx) {
        DebzConfig.setStatus(ctx, "booting");
        android.util.Log.i("DebzAI", "boot mulai offset=" + offset);
        try {
            RootfsManager.selfHeal(ctx);
            android.util.Log.i("DebzAI", "selfHeal ok, ready=" + RootfsManager.ready(ctx));
            boolean rooted = RootDetector.suWorks();
            DebzConfig.setRootMode(ctx, rooted);
            int web = PortManager.takePreferred(8091 + offset);
            int api = PortManager.takePreferred(8092 + offset);
            int fpm = PortManager.takePreferred(9000 + offset);
            int tools = PortManager.takePreferred(9191 + offset);
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
                DebzConfig.setStatus(ctx, "starting-stack");
                android.util.Log.i("DebzAI", "stack start web=" + web + " api=" + api);
                String out = StackSupervisor.start(ctx, RootfsManager.dir(ctx),
                    StackSupervisor.envFor(web, api, fpm, tools));
                boolean ok = StackSupervisor.healthyRetry("http://127.0.0.1:" + web + "/", 10);
                if (!ok) saveStackLog(ctx, out);
                android.util.Log.i("DebzAI", "stack akhir ok=" + ok);
                DebzConfig.setStatus(ctx, ok ? "up" : "stack-fail");
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

    // output start stack terakhir, dibaca dari device saat stack-fail
    private static void saveStackLog(android.content.Context ctx, String out) {
        try {
            FileWriter w = new FileWriter(new File(ctx.getFilesDir(), "stack-last.log"), false);
            w.write(out != null && !out.isEmpty() ? out : "(kosong)");
            w.close();
        } catch (Exception ignored) {}
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
