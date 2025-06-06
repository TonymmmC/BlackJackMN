<?php
// api/index.php - API REST principal
require_once '../config/Database.php';
require_once '../models/Game.php';
require_once '../utils/NumericMethods.php';
require_once '../controllers/BlackjackController.php';
require_once '../utils/ProbabilityCalculator.php';
require_once '../models/Statistics.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$controller = new BlackjackController();
$controller->handleAdvancedRequest();

// api/numeric_analysis.php - Endpoint especializado para análisis numérico
class NumericAnalysisAPI {
    private $numericMethods;
    private $db;
    
    public function __construct() {
        $this->db = new Database();
        $this->numericMethods = new NumericMethods($this->db->connect());
    }
    
    public function handleRequest() {
        header('Content-Type: application/json');
        
        $method = $_SERVER['REQUEST_METHOD'];
        $endpoint = $_GET['endpoint'] ?? '';
        
        try {
            switch ($endpoint) {
                case 'newton_raphson':
                    echo json_encode($this->runNewtonRaphson());
                    break;
                case 'interpolation':
                    echo json_encode($this->runInterpolation());
                    break;
                case 'integration':
                    echo json_encode($this->runIntegration());
                    break;
                case 'monte_carlo':
                    echo json_encode($this->runMonteCarlo());
                    break;
                case 'full_analysis':
                    echo json_encode($this->runFullAnalysis());
                    break;
                case 'card_counting':
                    echo json_encode($this->getCardCountingInfo());
                    break;
                case 'optimal_bet':
                    echo json_encode($this->calculateOptimalBet());
                    break;
                default:
                    throw new Exception('Invalid endpoint');
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function runNewtonRaphson() {
        $playerTotal = intval($_POST['player_total'] ?? 15);
        $dealerVisible = intval($_POST['dealer_visible'] ?? 7);
        $remainingCards = json_decode($_POST['remaining_cards'] ?? '{}', true);
        
        if (empty($remainingCards)) {
            $remainingCards = [
                1 => 4, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4,
                8 => 4, 9 => 4, 10 => 16, 11 => 4
            ];
        }
        
        $result = $this->numericMethods->newtonRaphsonOptimalProbability(
            $playerTotal, $dealerVisible, $remainingCards
        );
        
        return [
            'method' => 'Newton-Raphson',
            'input' => [
                'player_total' => $playerTotal,
                'dealer_visible' => $dealerVisible,
                'remaining_cards' => $remainingCards
            ],
            'result' => $result,
            'interpretation' => [
                'action' => $result['recommendation'],
                'confidence' => $result['optimal_probability'],
                'mathematical_basis' => 'Encontró el punto donde la derivada del valor esperado es cero'
            ]
        ];
    }
    
    private function runInterpolation() {
        $knownPoints = json_decode($_POST['known_points'] ?? '[]', true);
        $targetValue = floatval($_POST['target_value'] ?? 15);
        
        if (empty($knownPoints)) {
            // Puntos de ejemplo: probabilidades de ganar según total del jugador
            $knownPoints = [
                ['x' => 12, 'y' => 0.31],
                ['x' => 15, 'y' => 0.38],
                ['x' => 18, 'y' => 0.67],
                ['x' => 20, 'y' => 0.85]
            ];
        }
        
        $result = $this->numericMethods->newtonInterpolation($knownPoints, $targetValue);
        
        return [
            'method' => 'Interpolación de Newton',
            'input' => [
                'known_points' => $knownPoints,
                'target_value' => $targetValue
            ],
            'result' => $result,
            'interpretation' => [
                'estimated_probability' => round($result['interpolated_value'], 4),
                'mathematical_basis' => 'Uso polinomio de Newton con diferencias divididas'
            ]
        ];
    }
    
    private function runIntegration() {
        $playerTotal = intval($_POST['player_total'] ?? 15);
        $dealerVisible = intval($_POST['dealer_visible'] ?? 7);
        $intervals = intval($_POST['intervals'] ?? 100);
        
        $result = $this->numericMethods->trapezoidalIntegration(
            $playerTotal, $dealerVisible, 0, 1, $intervals
        );
        
        return [
            'method' => 'Integración Trapezoidal',
            'input' => [
                'player_total' => $playerTotal,
                'dealer_visible' => $dealerVisible,
                'intervals' => $intervals
            ],
            'result' => $result,
            'interpretation' => [
                'area_under_curve' => round($result['cumulative_probability'], 4),
                'mathematical_basis' => 'Aproximó el área bajo la curva de densidad de probabilidad'
            ]
        ];
    }
    
    private function runMonteCarlo() {
        $playerTotal = intval($_POST['player_total'] ?? 15);
        $dealerVisible = intval($_POST['dealer_visible'] ?? 7);
        $action = $_POST['action'] ?? 'hit';
        $iterations = intval($_POST['iterations'] ?? 10000);
        
        $result = ProbabilityCalculator::monteCarloSimulation(
            $playerTotal, $dealerVisible, $action, $iterations
        );
        
        return [
            'method' => 'Simulación Monte Carlo',
            'input' => [
                'player_total' => $playerTotal,
                'dealer_visible' => $dealerVisible,
                'action' => $action,
                'iterations' => $iterations
            ],
            'result' => $result,
            'interpretation' => [
                'win_probability' => round($result['win_probability'], 4),
                'confidence_interval' => $result['confidence_interval'],
                'mathematical_basis' => "Simuló {$iterations} manos para estimar probabilidad empírica"
            ]
        ];
    }
    
    private function runFullAnalysis() {
        $playerTotal = intval($_POST['player_total'] ?? 15);
        $dealerVisible = intval($_POST['dealer_visible'] ?? 7);
        $remainingCards = json_decode($_POST['remaining_cards'] ?? '{}', true);
        
        if (empty($remainingCards)) {
            $remainingCards = [1 => 4, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4, 10 => 16, 11 => 4];
        }
        
        $analysis = $this->numericMethods->analyzeHand($playerTotal, $dealerVisible, $remainingCards);
        
        // Agregar Monte Carlo para comparación
        $monteCarloHit = ProbabilityCalculator::monteCarloSimulation($playerTotal, $dealerVisible, 'hit');
        $monteCarloStand = ProbabilityCalculator::monteCarloSimulation($playerTotal, $dealerVisible, 'stand');
        
        return [
            'comprehensive_analysis' => $analysis,
            'monte_carlo_verification' => [
                'hit' => $monteCarloHit,
                'stand' => $monteCarloStand
            ],
            'method_comparison' => [
                'newton_raphson_recommendation' => $analysis['newton_raphson']['recommendation'],
                'monte_carlo_recommendation' => $monteCarloHit['win_probability'] > $monteCarloStand['win_probability'] ? 'hit' : 'stand',
                'methods_agree' => ($analysis['newton_raphson']['recommendation'] === 'hit') === ($monteCarloHit['win_probability'] > $monteCarloStand['win_probability'])
            ]
        ];
    }
    
    private function getCardCountingInfo() {
        $usedCards = json_decode($_POST['used_cards'] ?? '[]', true);
        
        // Convertir array de cartas usadas a objetos Card para el cálculo
        $cardObjects = [];
        foreach ($usedCards as $cardData) {
            $card = new Card($cardData['suit'], $cardData['rank'], $cardData['value'], $cardData['altValue']);
            $cardObjects[] = $card;
        }
        
        $counting = ProbabilityCalculator::calculateCardCounting($cardObjects);
        
        return [
            'card_counting' => $counting,
            'recommendation' => [
                'bet_adjustment' => $counting['true_count'] > 1 ? 'increase' : 'maintain',
                'risk_level' => $counting['advantage'] === 'player' ? 'favorable' : 'unfavorable'
            ]
        ];
    }
    
    private function calculateOptimalBet() {
        $balance = floatval($_POST['balance'] ?? 1000);
        $trueCount = floatval($_POST['true_count'] ?? 0);
        $baseBet = floatval($_POST['base_bet'] ?? 10);
        
        $optimalBet = ProbabilityCalculator::calculateOptimalBet($balance, $trueCount, $baseBet);
        
        return [
            'current_balance' => $balance,
            'true_count' => $trueCount,
            'base_bet' => $baseBet,
            'optimal_bet' => round($optimalBet, 2),
            'bet_multiplier' => round($optimalBet / $baseBet, 2),
            'kelly_based' => true
        ];
    }
}

// api/statistics.php - Endpoint para estadísticas
class StatisticsAPI {
    private $db;
    private $stats;
    
    public function __construct() {
        $this->db = new Database();
        $this->stats = new Statistics($this->db->connect());
    }
    
    public function handleRequest() {
        header('Content-Type: application/json');
        
        $endpoint = $_GET['endpoint'] ?? '';
        $gameId = intval($_GET['game_id'] ?? 0);
        
        try {
            switch ($endpoint) {
                case 'performance':
                    echo json_encode($this->getPerformanceStats($gameId));
                    break;
                case 'numeric_methods':
                    echo json_encode($this->getNumericMethodsStats($gameId));
                    break;
                case 'recommendations':
                    echo json_encode($this->getRecommendationStats($gameId));
                    break;
                case 'export':
                    echo json_encode($this->exportGameData($gameId));
                    break;
                case 'live_stats':
                    echo json_encode($this->getLiveStats($gameId));
                    break;
                default:
                    throw new Exception('Invalid statistics endpoint');
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function getPerformanceStats($gameId) {
        $performance = $this->stats->getPlayerPerformance($gameId);
        
        return [
            'player_performance' => $performance,
            'performance_grade' => $this->calculatePerformanceGrade($performance),
            'recommendations' => $this->generatePerformanceRecommendations($performance)
        ];
    }
    
    private function getNumericMethodsStats($gameId) {
        $methodsPerf = $this->stats->getNumericMethodsPerformance($gameId);
        
        return [
            'methods_performance' => $methodsPerf,
            'efficiency_analysis' => $this->analyzeMethodEfficiency($methodsPerf),
            'accuracy_comparison' => $this->compareMethodAccuracy($methodsPerf)
        ];
    }
    
    private function getRecommendationStats($gameId) {
        $recommendations = $this->stats->getRecommendationAccuracy($gameId);
        
        return [
            'recommendation_accuracy' => $recommendations,
            'follow_rate' => $this->calculateFollowRate($recommendations),
            'success_when_followed' => $this->calculateSuccessRate($recommendations)
        ];
    }
    
    private function exportGameData($gameId) {
        return $this->stats->exportGameData($gameId);
    }
    
    private function getLiveStats($gameId) {
        return [
            'current_performance' => $this->stats->getPlayerPerformance($gameId),
            'recent_calculations' => $this->getRecentCalculations($gameId, 5),
            'session_summary' => $this->getSessionSummary($gameId),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function calculatePerformanceGrade($performance) {
        $winRate = $performance['win_rate'] ?? 0;
        $profitPerHand = $performance['avg_profit_per_hand'] ?? 0;
        
        if ($winRate >= 0.55 && $profitPerHand > 0) return 'A+';
        if ($winRate >= 0.50 && $profitPerHand >= 0) return 'A';
        if ($winRate >= 0.45) return 'B';
        if ($winRate >= 0.40) return 'C';
        return 'D';
    }
    
    private function generatePerformanceRecommendations($performance) {
        $recommendations = [];
        
        if ($performance['win_rate'] < 0.45) {
            $recommendations[] = "Consider following numeric recommendations more closely";
        }
        if ($performance['avg_profit_per_hand'] < 0) {
            $recommendations[] = "Review bet sizing strategy";
        }
        if ($performance['total_hands'] < 50) {
            $recommendations[] = "Play more hands for statistical significance";
        }
        
        return $recommendations;
    }
    
    private function analyzeMethodEfficiency($methodsPerf) {
        $efficiency = [];
        
        foreach ($methodsPerf as $method) {
            $efficiency[$method['method_used']] = [
                'avg_execution_time' => $method['avg_execution_time'],
                'usage_frequency' => $method['usage_count'],
                'efficiency_score' => $method['usage_count'] / $method['avg_execution_time']
            ];
        }
        
        return $efficiency;
    }
    
    private function compareMethodAccuracy($methodsPerf) {
        // Placeholder for method accuracy comparison
        return [
            'most_accurate' => 'newton_raphson',
            'fastest' => 'basic_strategy',
            'most_reliable' => 'combined_analysis'
        ];
    }
    
    private function calculateFollowRate($recommendations) {
        $total = 0;
        $followed = 0;
        
        foreach ($recommendations as $rec) {
            $total += $rec['frequency'];
            if ($rec['recommended_action'] === $rec['actual_action']) {
                $followed += $rec['frequency'];
            }
        }
        
        return $total > 0 ? round($followed / $total, 4) : 0;
    }
    
    private function calculateSuccessRate($recommendations) {
        // Calculate success rate when recommendations were followed
        $followed = array_filter($recommendations, function($rec) {
            return $rec['recommended_action'] === $rec['actual_action'];
        });
        
        $avgWinProb = 0;
        if (!empty($followed)) {
            $avgWinProb = array_sum(array_column($followed, 'avg_win_prob')) / count($followed);
        }
        
        return round($avgWinProb, 4);
    }
    
    private function getRecentCalculations($gameId, $limit) {
        $stmt = $this->db->connect()->prepare("
            SELECT nc.*, h.hand_number 
            FROM numeric_calculations nc
            JOIN hands h ON nc.hand_id = h.id
            WHERE h.game_id = ?
            ORDER BY nc.timestamp DESC
            LIMIT ?
        ");
        $stmt->execute([$gameId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getSessionSummary($gameId) {
        $stmt = $this->db->connect()->prepare("
            SELECT 
                MIN(start_time) as session_start,
                MAX(COALESCE(end_time, NOW())) as session_end,
                SUM(total_hands) as total_hands,
                SUM(current_balance - initial_balance) as net_profit
            FROM games 
            WHERE id = ?
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// utils/GameLogger.php - Sistema de logging avanzado
class GameLogger {
    private $db;
    private $logFile;
    
    public function __construct($database) {
        $this->db = $database;
        $this->logFile = '../logs/blackjack_' . date('Y-m-d') . '.log';
    }
    
    public function logGameAction($gameId, $action, $data, $numericData = null) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'game_id' => $gameId,
            'action' => $action,
            'data' => $data,
            'numeric_data' => $numericData
        ];
        
        // Log to file
        $this->writeToFile($logEntry);
        
        // Log to database for analytics
        $this->logToDatabase($logEntry);
    }
    
    private function writeToFile($entry) {
        $logLine = json_encode($entry) . "\n";
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    private function logToDatabase($entry) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO game_logs (game_id, action, data, numeric_data, timestamp)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $entry['game_id'],
                $entry['action'],
                json_encode($entry['data']),
                json_encode($entry['numeric_data']),
                $entry['timestamp']
            ]);
        } catch (Exception $e) {
            error_log("Failed to log to database: " . $e->getMessage());
        }
    }
    
    public function getGameLogs($gameId, $limit = 100) {
        $stmt = $this->db->prepare("
            SELECT * FROM game_logs 
            WHERE game_id = ? 
            ORDER BY timestamp DESC 
            LIMIT ?
        ");
        $stmt->execute([$gameId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// config/routes.php - Enrutamiento de API
class Router {
    private $routes = [];
    
    public function addRoute($path, $handler) {
        $this->routes[$path] = $handler;
    }
    
    public function handleRequest() {
        $path = $_GET['path'] ?? '/';
        
        if (isset($this->routes[$path])) {
            $handler = $this->routes[$path];
            if (is_callable($handler)) {
                call_user_func($handler);
            } elseif (class_exists($handler)) {
                $instance = new $handler();
                $instance->handleRequest();
            }
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Route not found']);
        }
    }
}

// Configurar rutas
$router = new Router();
$router->addRoute('/game', 'BlackjackController');
$router->addRoute('/numeric', 'NumericAnalysisAPI');
$router->addRoute('/stats', 'StatisticsAPI');

// tests/NumericMethodsTest.php - Pruebas unitarias básicas
class NumericMethodsTest {
    private $numericMethods;
    
    public function __construct() {
        $this->numericMethods = new NumericMethods();
    }
    
    public function runAllTests() {
        $results = [];
        
        $results['newton_raphson'] = $this->testNewtonRaphson();
        $results['interpolation'] = $this->testInterpolation();
        $results['integration'] = $this->testIntegration();
        $results['monte_carlo'] = $this->testMonteCarlo();
        
        return $results;
    }
    
    private function testNewtonRaphson() {
        try {
            $remainingCards = [1 => 4, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4, 10 => 16, 11 => 4];
            $result = $this->numericMethods->newtonRaphsonOptimalProbability(15, 7, $remainingCards);
            
            return [
                'passed' => isset($result['optimal_probability']) && $result['optimal_probability'] >= 0 && $result['optimal_probability'] <= 1,
                'result' => $result,
                'message' => 'Newton-Raphson test completed'
            ];
        } catch (Exception $e) {
            return ['passed' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function testInterpolation() {
        try {
            $knownPoints = [
                ['x' => 12, 'y' => 0.31],
                ['x' => 15, 'y' => 0.38],
                ['x' => 18, 'y' => 0.67]
            ];
            $result = $this->numericMethods->newtonInterpolation($knownPoints, 16);
            
            return [
                'passed' => isset($result['interpolated_value']),
                'result' => $result,
                'message' => 'Newton interpolation test completed'
            ];
        } catch (Exception $e) {
            return ['passed' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function testIntegration() {
        try {
            $result = $this->numericMethods->trapezoidalIntegration(15, 7);
            
            return [
                'passed' => isset($result['cumulative_probability']),
                'result' => $result,
                'message' => 'Trapezoidal integration test completed'
            ];
        } catch (Exception $e) {
            return ['passed' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function testMonteCarlo() {
        try {
            $result = ProbabilityCalculator::monteCarloSimulation(15, 7, 'hit', 1000);
            
            return [
                'passed' => isset($result['win_probability']) && $result['win_probability'] >= 0 && $result['win_probability'] <= 1,
                'result' => $result,
                'message' => 'Monte Carlo simulation test completed'
            ];
        } catch (Exception $e) {
            return ['passed' => false, 'error' => $e->getMessage()];
        }
    }
}

// Archivo principal de inicialización
// init.php
session_start();

// Autoload de clases
spl_autoload_register(function ($class) {
    $paths = [
        '../models/',
        '../controllers/',
        '../utils/',
        '../config/',
        '../api/',
        '../tests/'
    ];
    
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Configuración de error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

// Crear directorios necesarios
$dirs = ['../logs', '../exports', '../cache'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Configuración de timezone
date_default_timezone_set('America/La_Paz');
?>