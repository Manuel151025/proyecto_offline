plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("com.google.devtools.ksp")
    id("com.google.dagger.hilt.android")
    jacoco
}

// El toolchain fija el JDK con el que se compila, para que la build sea
// reproducible en cualquier equipo y en el runner.
//
// Se mantiene en 17 por decisión, ya no por obligación: la restricción que lo
// exigía (AGP 8.2 fallaba en JdkImageTransform con JDK 21) desapareció al subir
// a AGP 8.13, y se comprobó que también compila con 21. Se deja en 17 porque es
// el objetivo de bytecode habitual en Android y no hay nada que ganar apuntando
// a 21 en una app con minSdk 24; subirlo añadiría riesgo en dispositivos
// antiguos que aquí no se puede probar.
java {
    toolchain {
        languageVersion.set(JavaLanguageVersion.of(17))
    }
}

android {
    namespace = "com.minsalud.encuestas"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.minsalud.encuestas"
        minSdk = 24
        targetSdk = 36
        versionCode = 1
        versionName = "1.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        vectorDrawables {
            useSupportLibrary = true
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
        debug {
            // Sin esto no se produce el archivo .exec y la tarea de cobertura
            // se ejecuta sin datos: "correcta" pero sin generar nada.
            enableUnitTestCoverage = true
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
        // Necesario para BuildConfig.DEBUG: en release no se registran los
        // cuerpos HTTP (contienen contraseñas y el token de sesión).
        buildConfig = true
    }

    testOptions {
        unitTests.isReturnDefaultValues = true
    }

    testCoverage {
        jacocoVersion = "0.8.11"
    }

    lint {
        // El CI falla ante ERRORES, no ante avisos. La mayoría de los avisos
        // actuales son "hay una versión más nueva de esta dependencia", que es
        // trabajo de Dependabot: convertirlos en fallo rompería la build cada
        // vez que alguien publique una versión, sin que el código empeore.
        abortOnError = true
        warningsAsErrors = false
        // El informe legible se publica como artefacto del job.
        textReport = true
        htmlReport = true
    }
}

ksp {
    arg("room.schemaLocation", "$projectDir/schemas")
}

dependencies {
    // Core
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("androidx.activity:activity-compose:1.9.3")
    
    // Compose
    implementation(platform("androidx.compose:compose-bom:2024.12.01"))
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-graphics")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.navigation:navigation-compose:2.8.5")
    
    // Room
    val roomVersion = "2.8.4"
    implementation("androidx.room:room-runtime:$roomVersion")
    implementation("androidx.room:room-ktx:$roomVersion")
    ksp("androidx.room:room-compiler:$roomVersion")
    
    // WorkManager
    implementation("androidx.work:work-runtime-ktx:2.10.0")
    
    // Retrofit & OkHttp
    implementation("com.squareup.retrofit2:retrofit:2.9.0")
    implementation("com.squareup.retrofit2:converter-gson:2.9.0")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")
    
    // Hilt
    implementation("com.google.dagger:hilt-android:2.57.2")
    ksp("com.google.dagger:hilt-android-compiler:2.57.2")
    implementation("androidx.hilt:hilt-navigation-compose:1.2.0")
    implementation("androidx.hilt:hilt-work:1.2.0")
    ksp("androidx.hilt:hilt-compiler:1.2.0")
    
    // Test
    testImplementation("junit:junit:4.13.2")
    testImplementation("io.mockk:mockk:1.13.9")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.7.3")
    testImplementation("com.squareup.retrofit2:retrofit:2.9.0")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.6.1")
    androidTestImplementation(platform("androidx.compose:compose-bom:2024.12.01"))
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    debugImplementation("androidx.compose.ui:ui-tooling")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
}

/**
 * Informe de cobertura de las pruebas unitarias.
 *
 * No falla la build por un umbral: poner un porcentaje mínimo empuja a escribir
 * pruebas que tocan líneas sin comprobar nada. El informe se publica como
 * artefacto del CI para poder mirar QUÉ quedó sin cubrir, que es lo útil.
 */
tasks.register<JacocoReport>("informeCobertura") {
    dependsOn("testDebugUnitTest")
    group = "verification"
    description = "Genera el informe de cobertura de las pruebas unitarias"

    reports {
        xml.required.set(true)
        html.required.set(true)
    }

    // Se excluye lo generado por Hilt, Room y Compose: medir cobertura de
    // código que nadie escribió a mano solo distorsiona el número.
    val excluidos = listOf(
        "**/R.class", "**/R$*.class", "**/BuildConfig.*", "**/Manifest*.*",
        "**/*_Hilt*.class", "**/hilt_aggregated_deps/**", "**/*_Factory.class",
        "**/*_MembersInjector.class", "**/Dagger*.class", "**/*Module_*.class",
        "**/*_Impl.class", "**/databinding/**", "**/ComposableSingletons*"
    )

    classDirectories.setFrom(
        fileTree("${layout.buildDirectory.get()}/tmp/kotlin-classes/debug") { exclude(excluidos) }
    )
    sourceDirectories.setFrom(files("src/main/java"))
    executionData.setFrom(
        fileTree(layout.buildDirectory.get()) { include("**/testDebugUnitTest.exec") }
    )
}
