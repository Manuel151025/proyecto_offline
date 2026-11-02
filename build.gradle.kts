plugins {
    id("com.android.application") version "8.13.2" apply false
    id("org.jetbrains.kotlin.android") version "2.2.21" apply false
    // Desde Kotlin 2.0 el compilador de Compose se distribuye como plugin propio;
    // kotlinCompilerExtensionVersion dejó de existir.
    id("org.jetbrains.kotlin.plugin.compose") version "2.2.21" apply false
    id("com.google.dagger.hilt.android") version "2.60.1" apply false
    id("com.google.devtools.ksp") version "2.2.21-2.0.5" apply false
}
