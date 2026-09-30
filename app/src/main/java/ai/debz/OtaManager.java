package ai.debz;

public final class OtaManager {
    public static final String RELEASE_REPO = "debzroot/debz-ai-apk";
    public static final String BUNDLE_VERSION = "0.2.0-run";

    private OtaManager() {}

    // TODO: check() bandingkan BUNDLE_VERSION ke GitHub Releases terbaru,
    // download zip bundle, verifikasi SHA256, swap atomik + rollback.
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
}
