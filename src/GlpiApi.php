<?php
/**
 * GlpiApi — contrato común de acceso a GLPI, independiente de la versión.
 *
 * Define la superficie que la herramienta usa para leer/escribir en GLPI, sin
 * atarse a cómo se habla con el servidor. Hay back-ends intercambiables que la
 * implementan:
 *   - GlpiClientLegacy → API REST clásica (apirest.php, App-Token + Session-Token),
 *     la de GLPI 9/10.
 *   - GlpiClientV2     → API nueva de alto nivel de GLPI 11+ (OAuth2).
 *
 * La fachada GlpiClient elige el back-end (fijo o autodetectado) y delega acá,
 * de modo que sumar GLPI 12 sea agregar un back-end, no rehacer los consumidores.
 *
 * @license MIT
 */
interface GlpiApi
{
    /**
     * Abrir una sesión con las credenciales de servicio configuradas.
     * Devuelve un identificador de sesión/token opaco para el llamador.
     */
    public function initSession(): string;

    /**
     * Reutilizar una sesión ya abierta (p. ej. la del usuario logueado), sin
     * volver a autenticar. No se debe cerrar: pertenece a esa sesión viva.
     */
    public function useSession(string $token): void;

    /** Cerrar la sesión abierta con initSession() (best effort). */
    public function killSession(): void;

    /**
     * Traer TODAS las filas de un itemtype, resolviendo la paginación.
     * @return array<int,array<string,mixed>>
     */
    public function getAll(string $itemtype, array $params = [], int $page = 200): array;

    /**
     * Traer los sub-ítems de un padre (p. ej. las ProjectTask de un Project).
     * Devuelve [] cuando no hay relación o el back-end no la expone.
     * @return array<int,array<string,mixed>>
     */
    public function getSubItems(string $itemtype, int $id, string $subtype, array $params = []): array;

    /** Traer un ítem por id, o null si no existe. */
    public function getItem(string $itemtype, int $id, array $params = []): ?array;

    /**
     * Crear/actualizar/borrar un ítem. $input es el payload del ítem (sin el
     * envoltorio que cada back-end necesite). $id es obligatorio para update/delete.
     * @param 'POST'|'PUT'|'DELETE' $method
     * @return array{0:int,1:mixed}  [httpCode, cuerpoDecodificado]
     */
    public function write(string $itemtype, string $method, array $input = [], ?int $id = null): array;
}
