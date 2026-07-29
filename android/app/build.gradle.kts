import java.util.Properties

// =====================================================================
// LRMS Android - app module
// =====================================================================

plugins {
    id("com.android.application")

    // ---------------------------------------------------------------------
    // OPTIONAL: Firebase Cloud Messaging (push notifications)
    // ---------------------------------------------------------------------
    // The app builds and runs WITHOUT Firebase. Push is an optional module,
    // exactly as the project specification requires.
    //
    // To enable it later:
    //   1. Create a Firebase project and add an Android app with the
    //      package name  com.lrms.recovery
    //   2. Download google-services.json into  android/app/
    //   3. Uncomment the line below AND the two dependencies marked
    //      "FIREBASE" further down in this file.
    //   4. Uncomment the FirebaseMessagingService entry in AndroidManifest.xml
    //   5. Paste the service-account JSON into the admin panel at
    //      Settings -> Firebase (Push). The server needs it to send.
    //
    // id("com.google.gms.google-services") version "4.5.0"
}

// ---------------------------------------------------------------------
// Release signing.
// keystore.properties is created by the CI workflow when the repository
// secrets are present. When it is absent (a normal local build, or CI
// without secrets) we simply do not configure a release signing config,
// and the workflow builds a debug APK instead.
// ---------------------------------------------------------------------
val keystorePropertiesFile = rootProject.file("keystore.properties")
val keystoreProperties = Properties()
val hasKeystore = keystorePropertiesFile.exists()
if (hasKeystore) {
    keystorePropertiesFile.inputStream().use { keystoreProperties.load(it) }
}

android {
    namespace = "com.lrms.recovery"

    // API 36 is the newest level supported by AGP 9.3 that is also a stable
    // published platform. Verified against the SDK repository.
    compileSdk = 37

    defaultConfig {
        applicationId = "com.lrms.recovery"

        // minSdk 24 (Android 7.0) covers the low-cost handsets BC agents use
        // while still giving us modern APIs.
        minSdk = 24
        targetSdk = 36

        // The GitHub Actions workflow reads versionName out of this file to
        // name the APK, so keep the literal on one line.
        versionCode = 1
        versionName = "1.0.0"

        vectorDrawables.useSupportLibrary = true

        // The server URL is NOT hardcoded: the user types it on the login
        // screen and it is stored in preferences. This placeholder only
        // pre-fills the field for convenience and may stay empty.
        buildConfigField("String", "DEFAULT_SERVER_URL", "\"\"")

        // Every API path is versioned so a future /api/v2 cannot break
        // already-installed copies of this app.
        buildConfigField("String", "API_PATH", "\"/api/v1\"")

        // The debug build type appends "-debug" to VERSION_NAME, which would be
        // sent to the server and compared against min_app_version. This field
        // keeps the clean marketing version for the X-App-Version header.
        buildConfigField("String", "APP_VERSION_NAME", "\"1.0.0\"")
    }

    signingConfigs {
        if (hasKeystore) {
            create("release") {
                storeFile = rootProject.file(keystoreProperties.getProperty("storeFile", "app/keystore.jks"))
                storePassword = keystoreProperties.getProperty("storePassword")
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        debug {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
            isMinifyEnabled = false
        }

        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            // Only attach a signing config when one actually exists.
            signingConfig = if (hasKeystore) signingConfigs.getByName("release") else null
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlin {
        compilerOptions {
            jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
        }
    }

    buildFeatures {
        viewBinding = true
        buildConfig = true
    }

    packaging {
        resources.excludes += setOf(
            "META-INF/AL2.0",
            "META-INF/LGPL2.1",
            "META-INF/*.kotlin_module"
        )
    }

    lint {
        // A lint warning must never break the APK build in CI.
        abortOnError = false
        checkReleaseBuilds = false
    }
}

dependencies {
    // --- AndroidX / Material -----------------------------------------
    implementation("androidx.core:core-ktx:1.19.0")
    implementation("androidx.appcompat:appcompat:1.7.1")
    implementation("com.google.android.material:material:1.14.0")
    implementation("androidx.constraintlayout:constraintlayout:2.2.1")
    implementation("androidx.activity:activity-ktx:1.13.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.11.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-ktx:2.11.0")
    implementation("androidx.recyclerview:recyclerview:1.4.0")
    implementation("androidx.swiperefreshlayout:swiperefreshlayout:1.2.0")

    // --- Background work: offline sync queue + GPS ping batching -------
    implementation("androidx.work:work-runtime-ktx:2.11.2")

    // --- Token storage ------------------------------------------------
    // EncryptedSharedPreferences, with a plain-preferences fallback in
    // Prefs.kt for devices whose keystore is broken.
    implementation("androidx.security:security-crypto:1.1.0")

    // --- Location ------------------------------------------------------
    implementation("com.google.android.gms:play-services-location:21.4.0")

    // --- Maps ----------------------------------------------------------
    // The API key is fetched from the server at runtime (config.maps_api_key)
    // and is NOT compiled into the APK.
    implementation("com.google.android.gms:play-services-maps:20.0.0")

    // --- FIREBASE (optional, disabled by default) ----------------------
    // implementation(platform("com.google.firebase:firebase-bom:34.16.0"))
    // implementation("com.google.firebase:firebase-messaging")

    // NOTE: there is deliberately no Retrofit / OkHttp / Gson / Room here.
    // Networking uses HttpURLConnection + org.json and the offline queue uses
    // SQLiteOpenHelper, both part of the Android platform. That keeps the APK
    // small and removes whole classes of dependency-resolution build failures.
}
