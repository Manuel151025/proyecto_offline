<?php

/** Cliente HTTP con cookies propias: cada instancia es un navegador distinto. */
final class Cliente
{
    private string $cookies;

    public function __construct()
    {
        $this->cookies = (string)tempnam(sys_get_temp_dir(), 'cookies');
    }

    public function __destruct()
    {
        @unlink($this->cookies);
    }

    /**
     * @param array<string, string>|string|null $cuerpo arreglo = formulario; texto = JSON
     * @param list<string> $cabeceras
     * @return array{codigo: int, cuerpo: string, ubicacion: string}
     */
    public function pedir(string $metodo, string $ruta, array|string|null $cuerpo = null, array $cabeceras = []): array
    {
        $c = curl_init(Entorno::url($ruta));
        curl_setopt_array($c, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => $this->cookies,
            CURLOPT_COOKIEJAR => $this->cookies,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        if (is_array($cuerpo)) {
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($cuerpo));
        } elseif (is_string($cuerpo)) {
            curl_setopt($c, CURLOPT_POSTFIELDS, $cuerpo);
            $cabeceras[] = 'Content-Type: application/json';
        }
        curl_setopt($c, CURLOPT_HTTPHEADER, $cabeceras);
        $respuesta = (string)curl_exec($c);
        $resultado = [
            'codigo' => (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE),
            'cuerpo' => $respuesta,
            'ubicacion' => (string)curl_getinfo($c, CURLINFO_REDIRECT_URL),
        ];
        curl_close($c);
        return $resultado;
    }

    /** @return array{codigo: int, cuerpo: string, ubicacion: string} */
    public function get(string $ruta): array
    {
        return $this->pedir('GET', $ruta);
    }

    /**
     * POST de formulario al panel con el token CSRF de la página indicada.
     *
     * @param array<string, string> $campos
     * @return array{codigo: int, cuerpo: string, ubicacion: string}
     */
    public function enviarPanel(array $campos, string $paginaCsrf = 'index.php', ?string $destino = null): array
    {
        $campos['csrf'] = $this->csrf($paginaCsrf);
        return $this->pedir('POST', 'api/admin/' . ($destino ?? 'index.php'), $campos);
    }

    public function csrf(string $pagina = 'index.php'): string
    {
        $html = $this->get('api/admin/' . $pagina)['cuerpo'];
        preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
        return $m[1] ?? '';
    }

    public function entrarPanel(string $documento, string $clave): void
    {
        $r = $this->enviarPanel(['action' => 'login', 'numero_documento' => $documento, 'password' => $clave]);
        if ($r['codigo'] !== 303) {
            throw new RuntimeException("No se pudo entrar al panel con $documento (HTTP {$r['codigo']})");
        }
    }
}
