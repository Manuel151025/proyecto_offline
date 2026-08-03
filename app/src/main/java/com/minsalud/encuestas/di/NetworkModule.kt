package com.minsalud.encuestas.di

import com.google.gson.GsonBuilder
import com.minsalud.encuestas.BuildConfig
import com.minsalud.encuestas.data.local.prefs.SessionManager
import com.minsalud.encuestas.data.remote.api.ApiService
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit
import javax.inject.Qualifier
import javax.inject.Singleton

@Qualifier
@Retention(AnnotationRetention.BINARY)
annotation class AuthInterceptor

@Module
@InstallIn(SingletonComponent::class)
object NetworkModule {

    @Provides
    @Singleton
    fun provideLoggingInterceptor(): HttpLoggingInterceptor {
        return HttpLoggingInterceptor().apply {
            // En release no se vuelca el cuerpo: el login lleva la contraseña
            // en claro y las demás peticiones el token de sesión.
            level = if (BuildConfig.DEBUG) {
                HttpLoggingInterceptor.Level.BODY
            } else {
                HttpLoggingInterceptor.Level.NONE
            }
            redactHeader("Authorization")
        }
    }

    /**
     * Adjunta el token de sesión a cada petición saliente. El login se excluye
     * porque es justamente el endpoint que emite el token.
     */
    @Provides
    @Singleton
    @AuthInterceptor
    fun provideAuthInterceptor(sessionManager: SessionManager): Interceptor {
        return Interceptor { chain ->
            val original = chain.request()
            val token = sessionManager.token()

            val request = if (token != null && !original.url.encodedPath.endsWith("login.php")) {
                original.newBuilder().addHeader("Authorization", "Bearer $token").build()
            } else {
                original
            }

            chain.proceed(request)
        }
    }

    @Provides
    @Singleton
    fun provideOkHttpClient(
        loggingInterceptor: HttpLoggingInterceptor,
        @AuthInterceptor authInterceptor: Interceptor
    ): OkHttpClient {
        return OkHttpClient.Builder()
            .addInterceptor(authInterceptor)
            .addInterceptor(loggingInterceptor)
            .connectTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .build()
    }

    @Provides
    @Singleton
    fun provideRetrofit(okHttpClient: OkHttpClient): Retrofit {
        // serializeNulls: envía TODOS los campos (incluso null) para que el backend
        // no dispare warnings de "clave indefinida" que corromperían el JSON de respuesta.
        val gson = GsonBuilder().serializeNulls().create()
        return Retrofit.Builder()
            .baseUrl("https://encuestas.manuelcardenas.online/")
            .client(okHttpClient)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
    }

    @Provides
    @Singleton
    fun provideApiService(retrofit: Retrofit): ApiService {
        return retrofit.create(ApiService::class.java)
    }
}
