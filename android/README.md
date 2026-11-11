# MyDentist Android App (WebView)

This is the optional mobile container described in the proposal. It shows the same
web system inside an Android app. It is **not** a separate native system: no data is
stored on the phone and it needs internet / network access to the server.

## Build it in Android Studio

1. **File > New > New Project > Empty Views Activity**
   - Name: `MyDentist`, Package name: `com.mydentist.app`, Language: **Java**, Minimum SDK: API 24.
2. Replace these files with the ones in this folder:
   - `MainActivity.java` → `app/src/main/java/com/mydentist/app/MainActivity.java`
   - `activity_main.xml` → `app/src/main/res/layout/activity_main.xml`
   - `AndroidManifest.xml` → `app/src/main/AndroidManifest.xml`
3. In `MainActivity.java`, set `START_URL`:
   - Android emulator + XAMPP on the same PC: `http://10.0.2.2/mydentist/`
   - Real phone on the same Wi-Fi: `http://<your PC's IP>/mydentist/` (find it with `ipconfig`).
     Allow Apache through the Windows Firewall if the phone can't connect.
4. Run ▶ on an emulator or phone. To make an installable file: **Build > Build App Bundle(s)/APK(s) > Build APK(s)**.

Tip: replace the launcher icon with **File > New > Image Asset** using `assets/img/logo.svg`.
