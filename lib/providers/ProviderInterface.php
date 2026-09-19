<?php

interface ProviderInterface
{
    /**
     * Identificador único del proveedor (ej. 'dontorrent', 'pelisplus', 'local_cdn')
     */
    public function getId(): string;

    /**
     * Nombre legible para el usuario
     */
    public function getName(): string;

    /**
     * Tipo principal de enlaces que ofrece: 'streaming', 'torrent', 'direct' o 'mixed'
     */
    public function getType(): string;

    /**
     * Si el proveedor está habilitado en la configuración
     */
    public function isEnabled(): bool;

    /**
     * Busca enlaces para una película
     * Retorna un array de fuentes con estructura:
     * [
     *   'provider' => string,
     *   'type' => 'streaming'|'torrent'|'direct',
     *   'title' => string,
     *   'server' => string (ej. 'Streamtape', 'Voe', 'Torrent', 'CDN'),
     *   'quality' => string (ej. '4K', '1080p', '720p', 'HD'),
     *   'language' => string (ej. 'Latino', 'Castellano', 'Subtitulado'),
     *   'url' => string (URL de video, embed o magnet:?...),
     *   'size' => string|null
     * ]
     */
    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array;

    /**
     * Busca enlaces para un episodio de una serie
     */
    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array;
}

