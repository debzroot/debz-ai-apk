package ai.debz;

public final class OtaManager {
    public static final String RELEASE_REPO = "debzroot/debz-ai-apk";
    public static final String ROLLING_TAG = "ci-latest";
    public static final String RELEASE_URL =
        "https://github.com/debzroot/debz-ai-apk/releases/tag/ci-latest";
    public static final String BUNDLE_VERSION = "0.2.0-run";
    private static final long CHECK_INTERVAL_MS = 6 * 60 * 60 * 1000L;
    private static final int NOTIF_ID = 1002;
    private static final String CHANNEL = "debz_update";

    private OtaManager() {}

    // TODO: download zip bundle, verifikasi SHA256, swap atomik + rollback.
    // versi APK beneran (0.2.0-r<run> dari CI), bukan konstanta — biar
    // ketahuan build mana yang kepasang di HP tanpa tebak-tebakan.
    public static String currentVersion(android.content.Context ctx) {
        try {
            String v = ctx.getPackageManager()
                .getPackageInfo(ctx.getPackageName(), 0).versionName;
            if (v != null && !v.isEmpty()) return v;
        } catch (Exception ignored) {}
        return BUNDLE_VERSION;
    }

    // Cek rilisan rolling ke GitHub API. Return note update kalau ada yang
    // lebih baru dari install (sekali notif per rilisan), else null.
    // Throttle 6 jam. Aman dipanggil dari background thread.
    public static String checkForUpdate(android.content.Context ctx) {
        try {
            long now = System.currentTimeMillis();
            if (now - DebzConfig.lastUpdateCheck(ctx) < CHECK_INTERVAL_MS) {
                return DebzConfig.updateAvailable(ctx)
                    ? DebzConfig.updateNote(ctx) : null;
            }
            DebzConfig.setLastUpdateCheck(ctx, now);
            String json = httpGet("https://api.github.com/repos/"
                + RELEASE_REPO + "/releases/tags/" + ROLLING_TAG);
            String updated = extractJsonString(json, "updated_at");
            if (updated == null || updated.isEmpty()) {
                updated = extractJsonString(json, "published_at");
            }
            if (updated == null || updated.isEmpty()) return null;
            long installed = 0;
            try {
                installed = ctx.getPackageManager()
                    .getPackageInfo(ctx.getPackageName(), 0).lastUpdateTime;
            } catch (Exception ignored) {}
            if (parseIso8601(updated) > installed
                    && !updated.equals(DebzConfig.lastNotifiedCi(ctx))) {
                String day = updated.length() >= 10 ? updated.substring(0, 10) : updated;
                String note = ROLLING_TAG + " " + day;
                DebzConfig.setUpdateAvailable(ctx, true);
                DebzConfig.setUpdateNote(ctx, note);
                DebzConfig.setLastNotifiedCi(ctx, updated);
                notifyUpdate(ctx, note);
                return note;
            }
            return DebzConfig.updateAvailable(ctx)
                ? DebzConfig.updateNote(ctx) : null;
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "ota check gagal: " + e);
            return null;
        }
    }

    private static void notifyUpdate(android.content.Context ctx, String note) {
        try {
            android.app.NotificationManager nm =
                (android.app.NotificationManager) ctx.getSystemService(
                    android.content.Context.NOTIFICATION_SERVICE);
            if (nm == null) return;
            if (android.os.Build.VERSION.SDK_INT
                    >= android.os.Build.VERSION_CODES.O) {
                android.app.NotificationChannel ch =
                    new android.app.NotificationChannel(CHANNEL, "Update Debz AI",
                        android.app.NotificationManager.IMPORTANCE_DEFAULT);
                nm.createNotificationChannel(ch);
            }
            android.content.Intent it = new android.content.Intent(
                android.content.Intent.ACTION_VIEW,
                android.net.Uri.parse(RELEASE_URL));
            int flags = android.app.PendingIntent.FLAG_UPDATE_CURRENT
                | android.app.PendingIntent.FLAG_IMMUTABLE;
            android.app.PendingIntent pi = android.app.PendingIntent.getActivity(
                ctx, 0, it, flags);
            android.app.Notification.Builder b;
            if (android.os.Build.VERSION.SDK_INT
                    >= android.os.Build.VERSION_CODES.O) {
                b = new android.app.Notification.Builder(ctx, CHANNEL);
            } else {
                b = new android.app.Notification.Builder(ctx);
            }
            b.setContentTitle("Update Debz AI tersedia")
                .setContentText(note + " — ketuk buat unduh")
                .setSmallIcon(android.R.drawable.ic_dialog_info)
                .setContentIntent(pi)
                .setAutoCancel(true);
            nm.notify(NOTIF_ID, b.build());
            android.util.Log.i("DebzAI", "notif update: " + note);
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "notif update gagal: " + e);
        }
    }

    private static String httpGet(String url) throws Exception {
        java.net.HttpURLConnection c =
            (java.net.HttpURLConnection) new java.net.URL(url).openConnection();
        c.setConnectTimeout(8000);
        c.setReadTimeout(10000);
        c.setRequestProperty("User-Agent", "DebzAI-APK");
        c.setRequestProperty("Accept", "application/vnd.github+json");
        try {
            java.io.InputStream in = c.getInputStream();
            try {
                java.io.ByteArrayOutputStream buf =
                    new java.io.ByteArrayOutputStream();
                byte[] tmp = new byte[8192];
                int n, total = 0;
                while ((n = in.read(tmp)) > 0 && total < 65536) {
                    buf.write(tmp, 0, n);
                    total += n;
                }
                return new String(buf.toByteArray(),
                    java.nio.charset.StandardCharsets.UTF_8);
            } finally {
                try {
                    in.close();
                } catch (Exception ignored) {}
            }
        } finally {
            c.disconnect();
        }
    }

    private static String extractJsonString(String json, String key) {
        if (json == null) return null;
        java.util.regex.Matcher m = java.util.regex.Pattern.compile(
            "\"" + key + "\"\\s*:\\s*\"([^\"]*)\"").matcher(json);
        return m.find() ? m.group(1) : null;
    }

    private static long parseIso8601(String s) {
        try {
            java.text.SimpleDateFormat f = new java.text.SimpleDateFormat(
                "yyyy-MM-dd'T'HH:mm:ss'Z'", java.util.Locale.US);
            f.setTimeZone(java.util.TimeZone.getTimeZone("UTC"));
            java.util.Date d = f.parse(s);
            return d != null ? d.getTime() : 0;
        } catch (Exception e) {
            return 0;
        }
    }
}
