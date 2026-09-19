<?php

interface ResolverInterface
{
    /**
     * Identificador del resolver (ej. 'voe', 'cuevana_player', 'streamwish')
     */
    public function getId(): string;

    /**
     * Comprueba si este resolver puede procesar la URL dada
     */
    public function canResolve(string $url): bool;

    /**
     * Resuelve la URL intermedia a una URL de inserción limpia (embed)
     * y/o a una URL de flujo directo de video (.mp4, .m3u8)
     * 
     * Retorna array con:
     * [
     *   'server' => string,
     *   'embed_url' => string,
     *   'stream_url' => string|null,
     *   'quality' => string|null
     * ]
     */
    public function resolve(string $url): ?array;
}

