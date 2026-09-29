package ai.debz;

import android.app.Activity;
import android.content.Intent;
import android.os.Build;
import android.os.Bundle;
import android.view.ViewGroup;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.LinearLayout;

public class MainActivity extends Activity {
    private WebView web;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        Intent svc = new Intent(this, BootstrapService.class);
        svc.putExtra(BootstrapService.EXTRA_OFFSET, DebzConfig.portOffset(this));
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(svc);
        } else {
            startService(svc);
        }

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);

        Button termBtn = new Button(this);
        termBtn.setText("Terminal");
        termBtn.setOnClickListener(v ->
            startActivity(new Intent(this, TerminalActivity.class)));

        Button reloadBtn = new Button(this);
        reloadBtn.setText("Reload");
        reloadBtn.setOnClickListener(v -> loadBackend());

        web = new WebView(this);
        WebSettings ws = web.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        web.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedError(WebView view, WebResourceRequest request,
                                        WebResourceError error) {
                view.loadData("<h3>Backend belum siap.</h3>"
                    + "<p>Tunggu BootstrapService selesai, lalu tap Reload.</p>",
                    "text/html", "utf-8");
            }
        });

        root.addView(termBtn,
            new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT));
        root.addView(reloadBtn,
            new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT));
        root.addView(web,
            new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));

        setContentView(root);
        web.postDelayed(this::loadBackend, 3000);
    }

    private void loadBackend() {
        int port = DebzConfig.webPort(this);
        if (port <= 0) port = 8091 + DebzConfig.portOffset(this);
        web.loadUrl("http://127.0.0.1:" + port + "/");
    }

    @Override
    protected void onDestroy() {
        if (web != null) web.destroy();
        super.onDestroy();
    }
}
