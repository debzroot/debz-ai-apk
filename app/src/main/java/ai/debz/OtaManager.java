package ai.debz;

public final class OtaManager {
    public static final String RELEASE_REPO = "debzroot/debz-ai-apk";
    public static final String BUNDLE_VERSION = "0.2.0-run";

    private OtaManager() {}

    // TODO: check() bandingkan BUNDLE_VERSION ke GitHub Releases terbaru,
    // download zip bundle, verifikasi SHA256, swap atomik + rollback.
    public static String currentVersion() {
        return BUNDLE_VERSION;
    }
}
