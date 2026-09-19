<?php

/**
 * Establece un manejador de errores y excepciones para los endpoints de la API.
 * Captura todos los errores de PHP y los formatea como una respuesta JSON coherente
 * en lugar de mostrar HTML, lo que evita errores de sintaxis en el cliente.
 */

function api_error_handler($severity, $message, $file, $line) {
    // No lanzar excepción para errores que no son graves
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
}

function api_exception_handler($exception) {
    if (!headers_sent()) {
        http_response_code(500); // Error interno del servidor
        header('Content-Type: application/json');
    }

    $response = [
        'status' => 'error',
        'message' => 'Ocurrió un error interno en el servidor.'
    ];

    // En modo de desarrollo, añade más detalles al error para depuración
    if (ini_get('display_errors')) {
        $response['details'] = [
            'error_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }

    echo json_encode($response);
    exit;
}

set_error_handler('api_error_handler');
set_exception_handler('api_exception_handler');