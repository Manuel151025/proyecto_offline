package com.minsalud.encuestas.data.repository

import com.minsalud.encuestas.data.local.dao.ColaSincronizacionDao
import com.minsalud.encuestas.data.local.dao.EncuestaDao
import com.minsalud.encuestas.data.local.dao.PersonaDao
import com.minsalud.encuestas.data.local.entity.AccionEncuestaEntity
import com.minsalud.encuestas.data.local.entity.ColaSincronizacionEntity
import com.minsalud.encuestas.data.local.entity.EncuestaEntity
import com.minsalud.encuestas.data.local.entity.EstadoSyncEntity
import com.minsalud.encuestas.data.local.entity.PersonaEntity
import com.minsalud.encuestas.data.local.entity.TipoDocumentoEntity
import com.minsalud.encuestas.data.remote.api.ApiService
import com.minsalud.encuestas.data.remote.dto.SyncRequestDto
import com.minsalud.encuestas.data.remote.dto.SyncResponseDto
import com.minsalud.encuestas.domain.model.DomainError
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.mockk
import io.mockk.slot
import kotlinx.coroutines.test.runTest
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import retrofit2.Response
import java.io.IOException

/**
 * Sincronización por lotes.
 *
 * Lo que se protege aquí es la propiedad que motivó el cambio: la cantidad de
 * peticiones HTTP debe crecer con el número de LOTES, no con el número de
 * registros. Antes se enviaba uno por uno, y 200 encuestas de campo eran 200
 * viajes de ida y vuelta sobre una conexión rural.
 */
class SyncRepositoryImplTest {

    private lateinit var colaDao: ColaSincronizacionDao
    private lateinit var personaDao: PersonaDao
    private lateinit var encuestaDao: EncuestaDao
    private lateinit var apiService: ApiService
    private lateinit var repository: SyncRepositoryImpl

    @Before
    fun setUp() {
        colaDao = mockk(relaxed = true)
        personaDao = mockk(relaxed = true)
        encuestaDao = mockk(relaxed = true)
        apiService = mockk()
        repository = SyncRepositoryImpl(colaDao, personaDao, encuestaDao, apiService)
    }

    // --- Utilidades de datos ---

    private fun cola(id: Int, idEncuesta: String) = ColaSincronizacionEntity(
        idCola = id, idEncuesta = idEncuesta, payload = "",
        estado = EstadoSyncEntity.PENDING, intentos = 0, ultimoError = null
    )

    private fun encuesta(id: String, documento: String) = EncuestaEntity(
        id = id,
        tipoDocumento = TipoDocumentoEntity.CC,
        numeroDocumento = documento,
        idEncuestador = 1,
        fechaEncuesta = 1_700_000_000_000,
        fechaSincronizacion = null,
        deviceId = "dev-01",
        accion = AccionEncuestaEntity.CREACION
    )

    private fun persona(documento: String, updatedAt: Long = 1_000L) = PersonaEntity(
        tipoDocumento = TipoDocumentoEntity.CC,
        numeroDocumento = documento,
        nombres = "Nombre", apellidos = "Apellido",
        fechaNacimiento = null, telefono = null, email = null, direccion = null,
        vereda = null, eps = null, ocupacion = null, estrato = null,
        municipioCodigo = "05001",
        updatedAt = updatedAt, deviceId = "dev-01", deletedAt = null
    )

    /** Prepara la cola con [cantidad] elementos, cada uno con su persona propia. */
    private fun prepararCola(cantidad: Int) {
        val items = (1..cantidad).map { cola(it, "enc-$it") }
        coEvery { colaDao.getPendientes() } returns items
        coEvery { encuestaDao.getEncuesta(any()) } answers {
            val id = firstArg<String>()
            encuesta(id, "doc-${id.removePrefix("enc-")}")
        }
        coEvery { personaDao.getPersona(any(), any()) } answers { persona(secondArg()) }
    }

    private fun respuestaOk(idsConfirmados: List<String>) = Response.success(
        SyncResponseDto(success = true, message = null, processedEncuestas = idsConfirmados)
    )

    private fun respuestaError(codigo: Int) = Response.error<SyncResponseDto>(
        codigo, """{"success":false}""".toResponseBody("application/json".toMediaTypeOrNull())
    )

    // --- Pruebas ---

    @Test
    fun `250 registros se envian en 3 lotes, no en 250 peticiones`() = runTest {
        prepararCola(250)
        val enviados = mutableListOf<SyncRequestDto>()
        coEvery { apiService.syncData(capture(enviados)) } answers {
            respuestaOk(firstArg<SyncRequestDto>().encuestas.map { it.id })
        }

        repository.sincronizarPendientes()

        assertEquals(3, enviados.size)
        assertEquals(listOf(100, 100, 50), enviados.map { it.encuestas.size })
    }

    @Test
    fun `un lote que cabe entero viaja en una sola peticion`() = runTest {
        prepararCola(40)
        val enviado = slot<SyncRequestDto>()
        coEvery { apiService.syncData(capture(enviado)) } answers {
            respuestaOk(firstArg<SyncRequestDto>().encuestas.map { it.id })
        }

        repository.sincronizarPendientes()

        coVerify(exactly = 1) { apiService.syncData(any()) }
        assertEquals(40, enviado.captured.encuestas.size)
    }

    @Test
    fun `no envia la misma persona repetida aunque tenga varias encuestas`() = runTest {
        // Dos encuestas (creación y edición) sobre la MISMA persona.
        coEvery { colaDao.getPendientes() } returns listOf(cola(1, "enc-a"), cola(2, "enc-b"))
        coEvery { encuestaDao.getEncuesta("enc-a") } returns encuesta("enc-a", "1098765432")
        coEvery { encuestaDao.getEncuesta("enc-b") } returns encuesta("enc-b", "1098765432")
        coEvery { personaDao.getPersona(any(), any()) } returns persona("1098765432", updatedAt = 5_000L)

        val enviado = slot<SyncRequestDto>()
        coEvery { apiService.syncData(capture(enviado)) } returns respuestaOk(listOf("enc-a", "enc-b"))

        repository.sincronizarPendientes()

        assertEquals(1, enviado.captured.personas.size)
        assertEquals(2, enviado.captured.encuestas.size)
    }

    @Test
    fun `solo marca enviado lo que el servidor confirma`() = runTest {
        prepararCola(3)
        // El servidor procesa 2 de las 3.
        coEvery { apiService.syncData(any()) } returns respuestaOk(listOf("enc-1", "enc-3"))

        try { repository.sincronizarPendientes() } catch (_: DomainError) { }

        coVerify(exactly = 1) { colaDao.marcarEnviado(1) }
        coVerify(exactly = 1) { colaDao.marcarEnviado(3) }
        coVerify(exactly = 0) { colaDao.marcarEnviado(2) }
        coVerify(exactly = 1) { colaDao.incrementarIntento(2, any()) }
    }

    @Test
    fun `descarta los huerfanos sin gastar red`() = runTest {
        coEvery { colaDao.getPendientes() } returns listOf(cola(1, "enc-fantasma"))
        coEvery { encuestaDao.getEncuesta("enc-fantasma") } returns null

        repository.sincronizarPendientes()

        coVerify(exactly = 0) { apiService.syncData(any()) }
        coVerify(exactly = 1) { colaDao.marcarError(1, any()) }
    }

    @Test
    fun `un 4xx marca error todo el lote y no se reintenta`() = runTest {
        prepararCola(2)
        coEvery { apiService.syncData(any()) } returns respuestaError(401)

        val error = runCatching { repository.sincronizarPendientes() }.exceptionOrNull()

        assertTrue(error is DomainError.InvalidData)
        coVerify(exactly = 1) { colaDao.marcarError(1, any()) }
        coVerify(exactly = 1) { colaDao.marcarError(2, any()) }
    }

    @Test
    fun `un 5xx incrementa intentos para que la cola se reintente`() = runTest {
        prepararCola(2)
        coEvery { apiService.syncData(any()) } returns respuestaError(503)

        val error = runCatching { repository.sincronizarPendientes() }.exceptionOrNull()

        assertTrue(error is DomainError.NetworkError)
        coVerify(exactly = 1) { colaDao.incrementarIntento(1, any()) }
        coVerify(exactly = 0) { colaDao.marcarError(any(), any()) }
    }

    @Test
    fun `un fallo de red deja la cola intacta para reintentar`() = runTest {
        prepararCola(2)
        coEvery { apiService.syncData(any()) } throws IOException("sin señal")

        val error = runCatching { repository.sincronizarPendientes() }.exceptionOrNull()

        assertTrue(error is DomainError.NetworkError)
        coVerify(exactly = 0) { colaDao.marcarEnviado(any()) }
        coVerify(exactly = 2) { colaDao.incrementarIntento(any(), any()) }
    }

    @Test
    fun `sin pendientes no toca la red`() = runTest {
        coEvery { colaDao.getPendientes() } returns emptyList()

        repository.sincronizarPendientes()

        coVerify(exactly = 0) { apiService.syncData(any()) }
    }
}
