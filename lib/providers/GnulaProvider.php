<?php
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/../utils.php';

class GnulaProvider implements ProviderInterface
{
    private string $host = 'https://www2.gnula.one';

    public function getId(): string
    {
        return 'gnula';
    }

    public function getName(): string
    {
        return 'Gnula (Películas HD)';
    }

    public function getType(): string
    {
        return 'streaming';
    }

    public function isEnabled(): bool
    {
        global $PROVIDERS_CONFIG;
        return $PROVIDERS_CONFIG['gnula']['enabled'] ?? true;
    }

    private function normalizeString(string $str): string
    {
        $str = mb_strtolower($str, 'UTF-8');
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        $str = preg_replace('/[^a-z0-9]/', '', $str);
        return trim($str);
    }

    public function searchMovie(string $title, ?string $year = null, ?int $tmdb_id = null): array
    {
        $sources = [];
        $searchUrl = $this->host . '/?s=' . urlencode($title);

        $html = http_get($searchUrl, [
            'timeout' => 8,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
            ]
        ]);

        if (!$html) {
            return [];
        }

        // Extraer tabla de resultados de búsqueda
        $searchTable = '';
        if (preg_match('/<strong>Busqueda:[^<]*<\/strong>.*?<table[^>]*>(.*?)<\/table>/is', $html, $mTable)) {
            $searchTable = $mTable[1];
        } else {
            $searchTable = $html;
        }

        preg_match_all('/<a[^>]+href="([^"]*\/movie\/[^"]+)"[^>]*>\s*<img[^>]+(?:title|alt)="([^"]+)"/is', $searchTable, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $targetNorm = $this->normalizeString($title);
        $candidateUrl = null;
        $matchedTitle = $title;

        for ($i = 0; $i < count($matches[1]); $i++) {
            $mUrl = $matches[1][$i];
            $mRawTitle = $matches[2][$i];

            // Extraer año de la entrada si existe
            $entryYear = null;
            if (preg_match('/\b(19\d\d|20\d\d)\b/', $mRawTitle, $mYr)) {
                $entryYear = $mYr[1];
            }

            // Limpiar título de metadatos de calidad o año
            $cleanTitle = preg_replace('/\s*\(\d{4}\).*$/', '', $mRawTitle);
            $cleanTitle = preg_replace('/\[.*?\]/', '', $cleanTitle);
            $cleanTitle = trim($cleanTitle);

            $entryNorm = $this->normalizeString($cleanTitle);

            $isMatch = false;
            if ($entryNorm === $targetNorm || strpos($entryNorm, $targetNorm) !== false || strpos($targetNorm, $entryNorm) !== false) {
                $isMatch = true;
            } else {
                similar_text($entryNorm, $targetNorm, $percent);
                if ($percent >= 70) {
                    $isMatch = true;
                }
            }

            if ($isMatch) {
                if ($year && $entryYear && abs((int)$year - (int)$entryYear) > 1) {
                    continue; // Año no coincide
                }
                $candidateUrl = $mUrl;
                $matchedTitle = $cleanTitle;
                break;
            }
        }

        if (!$candidateUrl) {
            return [];
        }

        // Obtener página de la película
        $movieHtml = http_get($candidateUrl, [
            'timeout' => 8,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        if (!$movieHtml) {
            return [];
        }

        // Extraer bloques de opciones e idioma
        // Ejemplo: <em>opción 1, Latino, HD</em> ... <div class="contenedor_tab">...
        $parts = preg_split('/<em>([^<]*?opci[^<]*?)<\/em>/iu', $movieHtml, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (count($parts) > 1) {
            for ($i = 1; $i < count($parts); $i += 2) {
                $header = strtolower($parts[$i]);
                $content = $parts[$i + 1] ?? '';
                // Limitar al contenido antes de Reportar
                $content = preg_split('/<strong>Reportar/i', $content)[0];

                $lang = 'Latino';
                $langCode = 'lat';
                if (strpos($header, 'castellano') !== false || strpos($header, 'esp') !== false) {
                    $lang = 'Castellano';
                    $langCode = 'cast';
                } elseif (strpos($header, 'sub') !== false || strpos($header, 'vose') !== false) {
                    $lang = 'Subtitulado';
                    $langCode = 'sub';
                }

                $quality = 'HD';
                if (strpos($header, '1080') !== false) {
                    $quality = '1080p';
                } elseif (strpos($header, '720') !== false) {
                    $quality = '720p';
                }

                preg_match_all('/(?:data-lazy-src|src)="([^"]+)"/i', $content, $ifrs);
                $urls = array_unique(array_filter($ifrs[1] ?? [], fn($u) => $u !== 'about:blank' && stripos($u, 'facebook') === false));

                foreach ($urls as $u) {
                    $this->extractSourcesFromUrl($u, $lang, $langCode, $quality, $matchedTitle, $sources);
                }
            }
        } else {
            // Fallback genérico si no hay etiquetas <em>opción
            preg_match_all('/(?:data-lazy-src|src)="([^"]+)"/i', $movieHtml, $ifrs);
            $urls = array_unique(array_filter($ifrs[1] ?? [], fn($u) => $u !== 'about:blank' && stripos($u, 'facebook') === false));
            foreach ($urls as $u) {
                $this->extractSourcesFromUrl($u, 'Latino', 'lat', 'HD', $matchedTitle, $sources);
            }
        }

        return $sources;
    }

    private function extractSourcesFromUrl(string $u, string $lang, string $langCode, string $quality, string $title, array &$sources): void
    {
        if (stripos($u, 'links.cuevana.ac') !== false) {
            $lHtml = http_get($u, [
                'timeout' => 5,
                'headers' => [
                    'Referer' => $this->host . '/',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);

            if ($lHtml) {
                preg_match_all('/go_to_player\([\'"]([^\'"]+)[\'"]\)(?:.*?<p>([^<]+)<\/p>)?/is', $lHtml, $gtp);
                for ($k = 0; $k < count($gtp[1]); $k++) {
                    $pUrl = $gtp[1][$k];
                    $desc = strtolower($gtp[2][$k] ?? '');
                    $pLang = $lang;
                    $pLangCode = $langCode;
                    if (strpos($desc, 'castellano') !== false) {
                        $pLang = 'Castellano';
                        $pLangCode = 'cast';
                    } elseif (strpos($desc, 'sub') !== false || strpos($desc, 'vose') !== false) {
                        $pLang = 'Subtitulado';
                        $pLangCode = 'sub';
                    } elseif (strpos($desc, 'latino') !== false) {
                        $pLang = 'Latino';
                        $pLangCode = 'lat';
                    }

                    // Convertir /f/ a /e/ para reproducción directa en iframe
                    if (preg_match('#/(?:f|watch_video\.php\?v=)/([a-zA-Z0-9_\-\+/=]+)#i', $pUrl, $mCode)) {
                        $pUrl = "https://player.cuevana.ac/e/{$mCode[1]}";
                    }

                    $sources[] = [
                        'provider' => $this->getId(),
                        'provider_name' => $this->getName(),
                        'title' => $title,
                        'server' => 'Waaw',
                        'language' => $pLang,
                        'lang' => $pLangCode,
                        'quality' => 'HD',
                        'type' => 'streaming',
                        'url' => $pUrl,
                        'size' => null
                    ];
                }
            }
        } else {
            $server = 'Stream';
            if (stripos($u, 'uqload') !== false) $server = 'Uqload';
            elseif (stripos($u, 'streamz') !== false) $server = 'Streamz';
            elseif (stripos($u, 'dood') !== false) $server = 'Doodstream';
            elseif (stripos($u, 'player.cuevana') !== false || stripos($u, 'waaw.to') !== false) {
                $server = 'Waaw';
                if (preg_match('#/(?:f|watch_video\.php\?v=)/([a-zA-Z0-9_\-\+/=]+)#i', $u, $mCode)) {
                    $u = "https://player.cuevana.ac/e/{$mCode[1]}";
                }
            }

            $sources[] = [
                'provider' => $this->getId(),
                'provider_name' => $this->getName(),
                'title' => $title,
                'server' => $server,
                'language' => $lang,
                'lang' => $langCode,
                'quality' => $quality,
                'type' => 'streaming',
                'url' => $u,
                'size' => null
            ];
        }
    }

    public function searchSeries(string $title, int $season, int $episode, ?int $tmdb_id = null): array
    {
        // Gnula es un catálogo enfocado únicamente a películas (según canal Balandro gnulatv y catálogo oficial)
        return [];
    }
}

