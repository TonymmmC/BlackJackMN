<?php
// index.php - Archivo principal
require_once 'config/autoload.php';
require_once 'config/config.php';

session_start();

// Routing simple
$action = $_GET['action'] ?? 'game';

switch ($action) {
    case 'api':
        require_once 'controllers/BlackjackController.php';
        $controller = new BlackjackController();
        $controller->handleAdvancedRequest();
        break;
    case 'numeric':
        require_once 'api/index.php';
        $api = new NumericAnalysisAPI();
        $api->handleRequest();
        break;
    case 'stats':
        require_once 'api/index.php';
        $api = new StatisticsAPI();
        $api->handleRequest();
        break;
    default:
        // Mostrar interfaz de juego
        include 'views/game.html';
        break;
}
?>