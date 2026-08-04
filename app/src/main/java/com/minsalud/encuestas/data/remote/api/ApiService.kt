package com.minsalud.encuestas.data.remote.api

import com.minsalud.encuestas.data.remote.dto.LoginRequestDto
import com.minsalud.encuestas.data.remote.dto.LoginResponseDto
import com.minsalud.encuestas.data.remote.dto.MunicipioDto
import com.minsalud.encuestas.data.remote.dto.SyncRequestDto
import com.minsalud.encuestas.data.remote.dto.SyncResponseDto
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST

interface ApiService {
    @POST("api/auth/login.php")
    suspend fun login(@Body credenciales: LoginRequestDto): Response<LoginResponseDto>

    @POST("api/personas/sync.php")
    suspend fun syncData(@Body payload: SyncRequestDto): Response<SyncResponseDto>

    @GET("api/municipios/index.php")
    suspend fun getMunicipios(): Response<List<MunicipioDto>>
}
