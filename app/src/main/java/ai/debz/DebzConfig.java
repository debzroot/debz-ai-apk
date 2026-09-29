package ai.debz;

import android.content.Context;
import android.content.SharedPreferences;

public final class DebzConfig {
    private static final String PREFS = "debz_prefs";
    private static final String KEY_OFFSET = "port_offset";
    private static final String KEY_WEB = "port_web";
    private static final String KEY_API = "port_api";
    private static final String KEY_ROOT = "mode_root";

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
}
