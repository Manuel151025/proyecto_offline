pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
// Permite que Gradle descargue el JDK 17 que exige AGP 8.2 si el equipo tiene
// otra versión instalada. Sin esto, compilar con JDK 21 falla en JdkImageTransform.
plugins {
    id("org.gradle.toolchains.foojay-resolver-convention") version "0.8.0"
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}
rootProject.name = "Encuestas Minsalud"
include(":app")
