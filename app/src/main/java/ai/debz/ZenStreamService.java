package ai.debz;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;

// Foreground holder buat stream Zen native: selama HTTP SSE jalan di
// ZenStreamClient, service ini nongol di notif biar Android gak kill proses.
public class ZenStreamService extends Service {
    public static final String EXTRA_STREAM_KEY = "ai.debz.EXTRA_STREAM_KEY";
    private static final int NOTIF_ID = 1002;
    private static final String CHANNEL = "debz_zen";

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        startForeground(NOTIF_ID, buildNotif());
        return START_NOT_STICKY;
    }

    private Notification buildNotif() {
        NotificationManager nm = getSystemService(NotificationManager.class);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && nm != null) {
            NotificationChannel ch = new NotificationChannel(
                CHANNEL, "Debz AI Zen stream", NotificationManager.IMPORTANCE_LOW);
            nm.createNotificationChannel(ch);
        }
        Notification.Builder b;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            b = new Notification.Builder(this, CHANNEL);
        } else {
            b = new Notification.Builder(this);
        }
        return b.setContentTitle("Debz AI")
            .setContentText("Zen lagi mikir…")
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .build();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
