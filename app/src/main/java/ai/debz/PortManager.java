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
}
