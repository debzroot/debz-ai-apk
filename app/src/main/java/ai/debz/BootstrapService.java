package ai.debz;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;

public class BootstrapService extends Service {
    public static final String EXTRA_OFFSET = "ai.debz.EXTRA_OFFSET";
    private static final int NOTIF_ID = 1001;
    private static final String CHANNEL = "debz_backend";

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        startForeground(NOTIF_ID, buildNotif());
        int offset = intent != null ? intent.getIntExtra(EXTRA_OFFSET, 0) : 0;
        DebzConfig.setPortOffset(this, offset);
        new Thread(() -> boot(offset, this)).start();
        return START_STICKY;
    }

    private void boot(int offset, android.content.Context ctx) {
        boolean rooted = RootDetector.suWorks();
        DebzConfig.setRootMode(ctx, rooted);
        int web = PortManager.takePreferred(8091 + offset);
        int api = PortManager.takePreferred(8092 + offset);
        DebzConfig.setPorts(ctx, web, api);
        // TODO: start proot-mini stack (php-fpm + nginx + opencode + python backend)
        // lalu health-check + update notifikasi status.
    }

    private Notification buildNotif() {
        NotificationManager nm = getSystemService(NotificationManager.class);
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
