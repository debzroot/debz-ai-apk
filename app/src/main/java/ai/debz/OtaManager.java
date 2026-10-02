package ai.debz;

public final class OtaManager {
    public static final String RELEASE_REPO = "debzroot/debz-ai-apk";
    public static final String ROLLING_TAG = "ci-latest";
    public static final String RELEASE_URL =
        "https://github.com/debzroot/debz-ai-apk/releases/tag/ci-latest";
    public static final String BUNDLE_VERSION = "0.2.0-run";
    // Aksi intent: ketuk notif/banner -> MainActivity mulai unduh+install.
    public static final String ACTION_UPDATE = "ai.debz.UPDATE";
    public static final String ACTION_INSTALL_RESULT = "ai.debz.INSTALL_RESULT";
    private static final long CHECK_INTERVAL_MS = 6 * 60 * 60 * 1000L;
    private static final int NOTIF_ID = 1002;
    private static final String CHANNEL = "debz_update";

    // Status unduh/install (dibaca MainActivity + WebUI via bridge).
    public static volatile boolean downloading = false;
    public static volatile int progressPct = 0;
    public static volatile String lastError = "";

    private OtaManager() {}

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
                + RELEASE_REPO + "/releases/tags/" + ROLLING_TAG, 262144);
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
            long releaseTime = parseIso8601(updated);
            if (releaseTime <= installed) {
                // Sudah di versi terbaru (atau lebih baru): bersihkan banner
                // basi biar user tidak ditawari update yang sama abadi.
                if (DebzConfig.updateAvailable(ctx)) {
                    DebzConfig.setUpdateAvailable(ctx, false);
                    DebzConfig.setUpdateNote(ctx, "");
                }
                return null;
            }
            if (!updated.equals(DebzConfig.lastNotifiedCi(ctx))) {
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

    // TAP-TO-UPDATE: unduh APK rilisan lalu lempar ke PackageInstaller
    // sistem. User cukup ketuk -> konfirmasi "Install" sistem -> update
    // menimpa TANPA uninstall (tanda tangan stabil) -> workspace utuh.
    // Callback jalan di thread background; MainActivity yang toast-kan.
    public interface Listener {
        void onProgress(int pct);
        void onDone(String msg);
        void onError(String msg);
    }

    public static synchronized void downloadAndInstall(
            final android.content.Context appCtx, final Listener li) {
        if (downloading) {
            if (li != null) li.onError("Unduhan update sudah jalan");
            return;
        }
        downloading = true;
        progressPct = 0;
        lastError = "";
        new Thread(() -> {
            try {
                // 1. Izin install unknown apps (wajib sekali, sideload).
                try {
                    if (!appCtx.getPackageManager().canRequestPackageInstalls()) {
                        android.content.Intent it = new android.content.Intent(
                            android.provider.Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                            android.net.Uri.parse("package:" + appCtx.getPackageName()));
                        it.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK);
                        appCtx.startActivity(it);
                        throw new Exception("Izinkan \"Install unknown apps\", lalu ketuk update lagi.");
                    }
                } catch (Exception e) {
                    // canRequestPackageInstalls butuh API 26+; minSdk kita 26
                    // jadi ini hampir tak terjadi — kalau terjadi, lanjut aja.
                    if (e.getMessage() != null
                            && e.getMessage().contains("Izinkan")) throw e;
                }
                // 2. Cari URL APK di rilisan.
                String json = httpGet("https://api.github.com/repos/"
                    + RELEASE_REPO + "/releases/tags/" + ROLLING_TAG, 262144);
                String apkUrl = extractApkUrl(json);
                if (apkUrl == null || apkUrl.isEmpty()) {
                    // Fallback lama: buka halaman rilisan di browser.
                    android.content.Intent it = new android.content.Intent(
                        android.content.Intent.ACTION_VIEW,
                        android.net.Uri.parse(RELEASE_URL));
                    it.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK);
                    appCtx.startActivity(it);
                    throw new Exception("APK tidak ketemu di rilisan — dibuka di browser.");
                }
                // 3. Unduh ke storage app (aman, auto-hapus setelah install).
                java.io.File dir = appCtx.getExternalFilesDir("updates");
                if (dir == null) dir = appCtx.getCacheDir();
                dir.mkdirs();
                java.io.File apk = new java.io.File(dir, "debz-update.apk");
                downloadFile(apkUrl, apk, li);
                if (apk.length() < 50L * 1024 * 1024) {
                    throw new Exception("APK korup (kecil: " + apk.length() + " byte)");
                }
                // 4. Sesi PackageInstaller -> UI konfirmasi sistem.
                commitSession(appCtx, apk);
                apk.deleteOnExit();
                downloading = false;
                progressPct = 100;
                if (li != null) li.onDone("Lanjutkan di layar Install sistem.");
            } catch (Exception e) {
                downloading = false;
                lastError = String.valueOf(e.getMessage());
                android.util.Log.w("DebzAI", "tap-update gagal: " + e);
                if (li != null) li.onError(lastError);
            }
        }).start();
    }

    private static void downloadFile(String url, java.io.File dst, Listener li)
            throws Exception {
        java.net.HttpURLConnection c = null;
        try {
            c = (java.net.HttpURLConnection) new java.net.URL(url).openConnection();
            c.setConnectTimeout(15000);
            c.setReadTimeout(30000);
            c.setRequestProperty("User-Agent", "DebzAI-APK");
            c.setInstanceFollowRedirects(true);
            int code = c.getResponseCode();
            if (code < 200 || code >= 300) throw new Exception("unduh HTTP " + code);
            long total = c.getContentLengthLong();
            try (java.io.InputStream in = c.getInputStream();
                 java.io.OutputStream out = new java.io.FileOutputStream(dst)) {
                byte[] buf = new byte[65536];
                int n;
                long got = 0;
                int last = -1;
                while ((n = in.read(buf)) > 0) {
                    out.write(buf, 0, n);
                    got += n;
                    if (total > 0) {
                        int pct = (int) (got * 90 / total);
                        if (pct != last) {
                            last = pct;
                            progressPct = pct;
                            if (li != null) li.onProgress(pct);
                        }
                    }
                }
            }
        } finally {
            if (c != null) c.disconnect();
        }
    }

    private static void commitSession(android.content.Context ctx, java.io.File apk)
            throws Exception {
        android.content.pm.PackageInstaller installer =
            ctx.getPackageManager().getPackageInstaller();
        android.content.pm.PackageInstaller.SessionParams params =
            new android.content.pm.PackageInstaller.SessionParams(
                android.content.pm.PackageInstaller.SessionParams.MODE_FULL_INSTALL);
        int sid = installer.createSession(params);
        try (android.content.pm.PackageInstaller.Session s = installer.openSession(sid);
             java.io.InputStream in = new java.io.FileInputStream(apk);
             java.io.OutputStream out = s.openWrite("package", 0, -1)) {
            byte[] buf = new byte[65536];
            int n;
            while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
            s.fsync(out);
            android.content.Intent cb = new android.content.Intent(ctx, MainActivity.class);
            cb.setAction(ACTION_INSTALL_RESULT);
            int flags = android.app.PendingIntent.FLAG_UPDATE_CURRENT
                | android.app.PendingIntent.FLAG_IMMUTABLE;
            android.app.PendingIntent pi = android.app.PendingIntent.getActivity(
                ctx, sid, cb, flags);
            s.commit(pi.getIntentSender());
            progressPct = 95;
        }
    }

    // Cari browser_download_url asset *.apk di JSON rilisan GitHub.
    static String extractApkUrl(String json) {
        if (json == null) return null;
        try {
            java.util.regex.Matcher m = java.util.regex.Pattern.compile(
                "\"name\"\\s*:\\s*\"([^\"]*)\"[^\\}]*?\"browser_download_url\"\\s*:\\s*\"([^\"]+)\"",
                java.util.regex.Pattern.DOTALL).matcher(json);
            String first = null;
            while (m.find()) {
                String name = m.group(1);
                String url = m.group(2);
                if (first == null) first = url;
                if (name != null && name.endsWith(".apk")) return url;
            }
            return first;
        } catch (Exception e) {
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
            // Ketuk = buka app + langsung mulai update otomatis.
            android.content.Intent it = new android.content.Intent(
                ctx, MainActivity.class);
            it.setAction(ACTION_UPDATE);
            it.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK
                | android.content.Intent.FLAG_ACTIVITY_CLEAR_TOP);
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
                .setContentText(note + " — ketuk buat update otomatis")
                .setSmallIcon(android.R.drawable.ic_dialog_info)
                .setContentIntent(pi)
                .setAutoCancel(true);
            nm.notify(NOTIF_ID, b.build());
            android.util.Log.i("DebzAI", "notif update: " + note);
        } catch (Exception e) {
            android.util.Log.w("DebzAI", "notif update gagal: " + e);
        }
    }

    private static String httpGet(String url, int maxBytes) throws Exception {
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
                while ((n = in.read(tmp)) > 0 && total < maxBytes) {
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
