package ai.debz;

import android.content.Context;
import android.content.SharedPreferences;

public final class DebzConfig {
    private static final String PREFS = "debz_prefs";
    private static final String KEY_OFFSET = "port_offset";
    private static final String KEY_WEB = "port_web";
    private static final String KEY_API = "port_api";
    private static final String KEY_TOOLS = "port_tools";
    private static final String KEY_ROOT = "mode_root";
    private static final String KEY_TOKEN = "gh_token";
    private static final String KEY_STATUS = "stack_status";
    private static final String KEY_LOGIN_FAIL_UNTIL = "autologin_fail_until";
    private static final String KEY_ROOTFS_URL = "rootfs_url";
    private static final String KEY_PERM_ASKED = "file_perm_asked";
    private static final String KEY_UPDATE_AVAILABLE = "update_available";
    private static final String KEY_UPDATE_NOTE = "update_note";
    private static final String KEY_LAST_UPDATE_CHECK = "last_update_check";
    private static final String KEY_LAST_NOTIFIED_CI = "last_notified_ci";
    private static final String KEY_ABRIDGE = "port_abridge";
    private static final String KEY_ABTOKEN = "abridge_token";

    private DebzConfig() {}

    private static SharedPreferences prefs(Context ctx) {
        return ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public static int portOffset(Context ctx) {
        return prefs(ctx).getInt(KEY_OFFSET, 0);
    }

    public static void setPortOffset(Context ctx, int offset) {
        prefs(ctx).edit().putInt(KEY_OFFSET, offset).apply();
    }

    public static int webPort(Context ctx) {
        return prefs(ctx).getInt(KEY_WEB, -1);
    }

    public static int apiPort(Context ctx) {
        return prefs(ctx).getInt(KEY_API, -1);
    }

    public static void setPorts(Context ctx, int web, int api, int tools) {
        prefs(ctx).edit().putInt(KEY_WEB, web).putInt(KEY_API, api)
            .putInt(KEY_TOOLS, tools).apply();
    }

    public static int toolsPort(Context ctx) {
        return prefs(ctx).getInt(KEY_TOOLS, -1);
    }

    public static boolean rootMode(Context ctx) {
        return prefs(ctx).getBoolean(KEY_ROOT, false);
    }

    public static void setRootMode(Context ctx, boolean root) {
        prefs(ctx).edit().putBoolean(KEY_ROOT, root).apply();
    }

    public static String token(Context ctx) {
        return prefs(ctx).getString(KEY_TOKEN, "");
    }

    public static void setToken(Context ctx, String token) {
        prefs(ctx).edit().putString(KEY_TOKEN, token).apply();
    }

    public static String rootfsUrl(Context ctx) {
        return prefs(ctx).getString(KEY_ROOTFS_URL, "");
    }

    public static void setRootfsUrl(Context ctx, String url) {
        prefs(ctx).edit().putString(KEY_ROOTFS_URL, url).apply();
    }

    public static String status(Context ctx) {
        return prefs(ctx).getString(KEY_STATUS, "idle");
    }

    public static void setStatus(Context ctx, String status) {
        prefs(ctx).edit().putString(KEY_STATUS, status).apply();
    }

    public static boolean permAsked(Context ctx) {
        return prefs(ctx).getBoolean(KEY_PERM_ASKED, false);
    }
    public static void setPermAsked(Context ctx) {
        prefs(ctx).edit().putBoolean(KEY_PERM_ASKED, true).apply();
    }

    // auto-login gagal -> jangan hammer (backend lockout 5x -> 429 15 mnt).
    public static long loginFailUntil(Context ctx) {
        return prefs(ctx).getLong(KEY_LOGIN_FAIL_UNTIL, 0);
    }

    public static void setLoginFailUntil(Context ctx, long until) {
        prefs(ctx).edit().putLong(KEY_LOGIN_FAIL_UNTIL, until).apply();
    }

    // OTA rolling ci-latest: flag update + throttle cek 6 jam.
    public static boolean updateAvailable(Context ctx) {
        return prefs(ctx).getBoolean(KEY_UPDATE_AVAILABLE, false);
    }

    public static void setUpdateAvailable(Context ctx, boolean v) {
        prefs(ctx).edit().putBoolean(KEY_UPDATE_AVAILABLE, v).apply();
    }

    public static String updateNote(Context ctx) {
        return prefs(ctx).getString(KEY_UPDATE_NOTE, "");
    }

    public static void setUpdateNote(Context ctx, String note) {
        prefs(ctx).edit().putString(KEY_UPDATE_NOTE, note != null ? note : "").apply();
    }

    public static long lastUpdateCheck(Context ctx) {
        return prefs(ctx).getLong(KEY_LAST_UPDATE_CHECK, 0);
    }

    public static void setLastUpdateCheck(Context ctx, long t) {
        prefs(ctx).edit().putLong(KEY_LAST_UPDATE_CHECK, t).apply();
    }

    public static String lastNotifiedCi(Context ctx) {
        return prefs(ctx).getString(KEY_LAST_NOTIFIED_CI, "");
    }

    public static void setLastNotifiedCi(Context ctx, String v) {
        prefs(ctx).edit().putString(KEY_LAST_NOTIFIED_CI, v != null ? v : "").apply();
    }

    // Android bridge (eksekusi perintah sisi Android/root buat agent).
    public static int abridgePort(Context ctx) {
        return prefs(ctx).getInt(KEY_ABRIDGE, -1);
    }

    public static void setAbridgePort(Context ctx, int port) {
        prefs(ctx).edit().putInt(KEY_ABRIDGE, port).apply();
    }

    public static String abridgeToken(Context ctx) {
        String t = prefs(ctx).getString(KEY_ABTOKEN, "");
        if (t == null || t.isEmpty()) {
            t = java.util.UUID.randomUUID().toString();
            prefs(ctx).edit().putString(KEY_ABTOKEN, t).apply();
        }
        return t;
    }
}
