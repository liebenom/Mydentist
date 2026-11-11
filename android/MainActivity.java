package com.mydentist.app;

import android.annotation.SuppressLint;
import android.content.Intent;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Bundle;
import android.view.View;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;

import androidx.activity.OnBackPressedCallback;
import androidx.appcompat.app.AppCompatActivity;

/**
 * MyDentist WebView container.
 * The app only displays the hosted web system; all data stays on the server.
 */
public class MainActivity extends AppCompatActivity {

    // CHANGE THIS to the address of the hosted system.
    // Emulator -> XAMPP on the same PC: http://10.0.2.2/mydentist/
    // Phone on the same Wi-Fi:          http://<PC IP address>/mydentist/   (e.g. http://192.168.1.5/mydentist/)
    private static final String START_URL = "http://10.0.2.2/mydentist/";

    private WebView webView;
    private ProgressBar progress;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);
        webView = findViewById(R.id.webview);
        progress = findViewById(R.id.progress);

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);      // booking wizard and dashboard use JavaScript
        s.setDomStorageEnabled(true);
        s.setLoadWithOverviewMode(true);
        s.setUseWideViewPort(true);

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                String scheme = uri.getScheme();
                // tel:, mailto: and other sites open in their own apps
                if (!"http".equals(scheme) && !"https".equals(scheme)
                        || !uri.getHost().equals(Uri.parse(START_URL).getHost())) {
                    startActivity(new Intent(Intent.ACTION_VIEW, uri));
                    return true;
                }
                return false;
            }

            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                progress.setVisibility(View.VISIBLE);
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                progress.setVisibility(View.GONE);
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) {
                    view.loadData("<html><body style='font-family:sans-serif;text-align:center;padding:40px;color:#16252d'>"
                            + "<h2>No connection</h2><p>MyDentist needs an internet connection.<br>Please check your connection and try again.</p>"
                            + "<button onclick='history.back()' style='background:#0e9aa7;color:#fff;border:0;padding:12px 20px;border-radius:8px'>Retry</button>"
                            + "</body></html>", "text/html", "UTF-8");
                }
            }
        });

        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                if (webView.canGoBack()) webView.goBack();
                else finish();
            }
        });

        if (savedInstanceState != null) webView.restoreState(savedInstanceState);
        else webView.loadUrl(START_URL);
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        webView.saveState(outState);
    }
}
