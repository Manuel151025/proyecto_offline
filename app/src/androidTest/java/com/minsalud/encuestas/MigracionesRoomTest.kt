package com.minsalud.encuestas

import androidx.room.testing.MigrationTestHelper
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.minsalud.encuestas.data.local.AppDatabase
import com.minsalud.encuestas.di.MIGRATION_1_2
import com.minsalud.encuestas.di.MIGRATION_2_3
import com.minsalud.encuestas.di.MIGRATION_3_4
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Migraciones de Room contra una base SQLite real.
 *
 * Es la prueba que más importa en una app offline: si una migración falla en
 * el teléfono de un encuestador, se pierde el trabajo de campo que aún no se
 * envió. Construye la base en la versión 1 con datos sin sincronizar, la lleva
 * a la versión actual y comprueba que nada se perdió.
 *
 * Necesita un dispositivo o emulador: ./gradlew connectedDebugAndroidTest
 */
@RunWith(AndroidJUnit4::class)
class MigracionesRoomTest {

    private val nombre = "migraciones-prueba"

    @get:Rule
    val helper = MigrationTestHelper(
        InstrumentationRegistry.getInstrumentation(),
        AppDatabase::class.java
    )

    @Test
    fun deLaVersion1ALaActualSinPerderTrabajoDeCampo() {
        helper.createDatabase(nombre, 1).apply {
            execSQL(
                "INSERT INTO personas (tipo_documento, numero_documento, nombres, apellidos, updated_at, device_id) " +
                    "VALUES ('CC', '1061702334', 'María Fernanda', 'Rojas Díaz', 1700000000000, 'android_prueba')"
            )
            execSQL(
                "INSERT INTO encuestas (id, tipo_documento, numero_documento, id_encuestador, fecha_encuesta, device_id, accion) " +
                    "VALUES ('enc-1', 'CC', '1061702334', 3, 1700000000000, 'android_prueba', 'CREACION')"
            )
            execSQL(
                "INSERT INTO cola_sincronizacion (id_cola, id_encuesta, payload, estado, intentos) " +
                    "VALUES (1, 'enc-1', '', 'PENDING', 0)"
            )
            close()
        }

        val migrada = helper.runMigrationsAndValidate(nombre, 4, true, MIGRATION_1_2, MIGRATION_2_3, MIGRATION_3_4)

        migrada.query("SELECT nombres FROM personas WHERE numero_documento = '1061702334'").use { c ->
            assertTrue("la persona sin enviar debe sobrevivir a la migración", c.moveToFirst())
            assertEquals("María Fernanda", c.getString(0))
        }
        migrada.query("SELECT estado FROM cola_sincronizacion WHERE id_encuesta = 'enc-1'").use { c ->
            assertTrue("la cola de envío debe sobrevivir", c.moveToFirst())
            assertEquals("PENDING", c.getString(0))
        }
        migrada.close()
    }
}
