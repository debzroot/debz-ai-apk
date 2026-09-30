package ai.debz;

import java.io.IOException;
import java.net.ServerSocket;

public final class PortManager {
    private PortManager() {}

    public static int freePort() throws IOException {
        try (ServerSocket s = new ServerSocket(0)) {
            s.setReuseAddress(true);
            return s.getLocalPort();
        }
    }

    public static int takePreferred(int preferred) {
        try (ServerSocket s = new ServerSocket(preferred)) {
            s.setReuseAddress(true);
            return preferred;
        } catch (IOException e) {
            try {
                return freePort();
            } catch (IOException ex) {
                return -1;
            }
        }
    }

    // port yang SAMA harus dipakai ulang tiap boot — random tiap buka app
    // bikin .serve.json geser + stack lama jadi yatim. canBind = fondasi
    // "reuse dulu, random cuma last resort".
    public static boolean canBind(int port) {
        if (port <= 0) return false;
        try (ServerSocket s = new ServerSocket(port)) {
            s.setReuseAddress(true);
            return true;
        } catch (IOException e) {
            return false;
        }
    }
}
