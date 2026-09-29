package ai.debz;

import android.content.Context;
import android.content.SharedPreferences;

public final class DebzConfig {
    private static final String PREFS = "debz_prefs";
    private static final String KEY_OFFSET = "port_offset";
    private static final String KEY_WEB = "port_web";
    private static final String KEY_API = "port_api";
    private static final String KEY_ROOT = "mode_root";
    private static final String KEY_TOKEN = "gh_token";
    private static final String KEY_STATUS = "stack_status";
    private static final String KEY_ROOTFS_URL = "rootfs_url";
    private static final String KEY_PERM_ASKED = "file_perm_asked";

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

    public static void setPorts(Context ctx, int web, int api) {
        prefs(ctx).edit().putInt(KEY_WEB, web).putInt(KEY_API, api).apply();
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
}
