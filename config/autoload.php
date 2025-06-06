<?php
// config/autoload.php
spl_autoload_register(function ($class) {
    $paths = [
        __DIR__ . '/../models/',
        __DIR__ . '/../controllers/',
        __DIR__ . '/../utils/',
        __DIR__ . '/../config/',
        __DIR__ . '/../api/'
    ];
    
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// config/config.php
class Config {
    const DB_HOST = 'localhost';
    const DB_NAME = 'blackjack_numeric';
    const DB_USER = 'root';
    const DB_PASS = '';
    
    const SESSION_TIMEOUT = 3600; // 1 hora
    const LOG_LEVEL = 'DEBUG';
    const CACHE_ENABLED = true;
}

// index.php - Archivo principal
<?php
require_once 'config/autoload.php';
require_once 'config/config.php';

session_start();

// Routing simple
$action = $_GET['action'] ?? 'game';

switch ($action) {
    case 'api':
        $controller = new BlackjackController();
        $controller->handleAdvancedRequest();
        break;
    case 'numeric':
        $api = new NumericAnalysisAPI();
        $api->handleRequest();
        break;
    case 'stats':
        $api = new StatisticsAPI();
        $api->handleRequest();
        break;
    default:
        // Mostrar interfaz de juego
        include 'views/game.html';
        break;
}
?>