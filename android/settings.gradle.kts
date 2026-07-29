// =====================================================================
// LRMS Android - Gradle settings
// ---------------------------------------------------------------------
// Version note: the plugin and dependency versions used across this build
// were checked against Google's Maven repository and Maven Central rather
// than copied from a tutorial. See android/README.md for the matrix.
// =====================================================================

pluginManagement {
    repositories {
        google {
            content {
                includeGroupByRegex("com\\.android.*")
                includeGroupByRegex("com\\.google.*")
                includeGroupByRegex("androidx.*")
            }
        }
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    // Fail loudly if a module declares its own repositories - that is how
    // builds silently start pulling from unexpected places.
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "LRMS"
include(":app")
