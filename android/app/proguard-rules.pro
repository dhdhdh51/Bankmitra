# LRMS release shrinking rules.

# Keep the app's model classes referenced reflectively by ViewBinding-generated
# code (ViewBinding itself is fine, but be explicit about our own entry points).
-keep class com.lrms.recovery.data.model.** { *; }

# org.json is part of the Android platform - nothing to keep.

# WorkManager instantiates Workers by class name from the database.
-keep class * extends androidx.work.ListenableWorker { public <init>(...); }

# Keep the custom SignaturePadView (inflated from XML by name).
-keep class com.lrms.recovery.ui.widget.** { public <init>(...); }

# Play Services / Maps ship their own consumer rules.
-dontwarn org.jetbrains.annotations.**
