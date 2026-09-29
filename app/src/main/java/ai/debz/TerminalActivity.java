package ai.debz;

import android.app.Activity;
import android.os.Bundle;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;

public class TerminalActivity extends Activity {
    private TextView out;
    private EditText cmd;
    private ScrollView scroll;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);

        out = new TextView(this);
        out.setText("$ shell lokal siap (proot session + debz-term menyusul)\n");
        scroll = new ScrollView(this);
        scroll.addView(out);

        cmd = new EditText(this);
        cmd.setHint("ketik perintah, tap Run");

        Button run = new Button(this);
        run.setText("Run");
        run.setOnClickListener(v -> exec(cmd.getText().toString()));

        root.addView(scroll, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        root.addView(cmd, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT));
        root.addView(run, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT));

        setContentView(root);
    }

    private void exec(String line) {
        if (line.trim().isEmpty()) return;
        final boolean inProot = RootfsManager.ready(this);
        append((inProot ? "[proot] " : "") + "$ " + line + "\n");
        new Thread(() -> {
            try {
                Process p;
                if (inProot) {
                    // TODO: ganti ke sesi interaktif + debz-term default
                    p = ProotManager.exec(this, RootfsManager.dir(this),
                        null, line);
                } else {
                    p = new ProcessBuilder("/system/bin/sh", "-c", line)
                        .redirectErrorStream(true).start();
                }
                String res = readAll(p.getInputStream());
                p.waitFor();
                append(res + "\n[exit " + p.exitValue() + "]\n");
            } catch (Exception e) {
                append("error: " + e.getMessage() + "\n");
            }
        }).start();
    }

    private void append(String s) {
        runOnUiThread(() -> {
            out.append(s);
            scroll.post(() -> scroll.fullScroll(ScrollView.FOCUS_DOWN));
        });
    }

    private static String readAll(InputStream in) throws Exception {
        ByteArrayOutputStream buf = new ByteArrayOutputStream();
        byte[] tmp = new byte[4096];
        int n;
        while ((n = in.read(tmp)) > 0) buf.write(tmp, 0, n);
        return new String(buf.toByteArray(), StandardCharsets.UTF_8);
    }
}
