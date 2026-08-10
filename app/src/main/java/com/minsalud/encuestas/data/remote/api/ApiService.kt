package com.minsalud.encuestas.data.remote.api

import com.minsalud.encuestas.data.remote.dto.LoginRequestDto
import com.minsalud.encuestas.data.remote.dto.LoginResponseDto
import com.minsalud.encuestas.data.remote.dto.CambiosResponseDto
import com.minsalud.encuestas.data.remote.dto.MunicipioDto
import com.minsalud.encuestas.data.remote.dto.SyncRequestDto
import com.minsalud.encuestas.data.remote.dto.SyncResponseDto
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Query

interface ApiService {
    @POST("api/auth/login.php")
    suspend fun login(@Body credenciales: LoginRequestDto): Response<LoginResponseDto>

    /** Revoca el token actual. El interceptor lo adjunta automáticamente. */
    @POST("api/auth/logout.php")
    suspend fun logout(): Response<Unit>

    @POST("api/personas/sync.php")
    suspend fun syncData(@Body payload: SyncRequestDto): Response<SyncResponseDto>

    /** Descarga incremental: personas cambiadas tras la marca `desde`. */
    @GET("api/personas/cambios.php")
    suspend fun getCambios(
        @Query("desde") desde: Long,
        @Query("limite") limite: Int = 200
    ): Response<CambiosResponseDto>

    @GET("api/municipios/index.php")
    suspend fun getMunicipios(): Response<List<MunicipioDto>>
}
