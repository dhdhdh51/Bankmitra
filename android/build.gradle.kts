// =====================================================================
// LRMS Android - root build file
// ---------------------------------------------------------------------
// Android Gradle Plugin 9.x has BUILT-IN Kotlin support, so the
// org.jetbrains.kotlin.android plugin is deliberately NOT applied and no
// kotlin-gradle-plugin dependency is declared. Adding it back would
// conflict with AGP's own Kotlin integration.
// =====================================================================

plugins {
    id("com.android.application") version "9.3.1" apply false
}

// `./gradlew clean` from the root.
tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
