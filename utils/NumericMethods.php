<?php
// utils/NumericMethods.php
class NumericMethods {
    private $db;
    
    public function __construct($database = null) {
        $this->db = $database;
    }

    /**
     * Newton-Raphson para calcular probabilidad óptima de hit/stand
     * Busca el punto donde la derivada del valor esperado es 0
     */
    public function newtonRaphsonOptimalProbability($playerTotal, $dealerVisible, $remainingCards, $tolerance = 0.0001, $maxIterations = 100) {
        $startTime = microtime(true);
        $steps = [];
        
        // Valor inicial: probabilidad aproximada basada en estrategia básica
        $x = $this->getBasicStrategyProbability($playerTotal, $dealerVisible);
        
        for ($i = 0; $i < $maxIterations; $i++) {
            // f(x) = valor esperado de hit - valor esperado de stand
            $f = $this->expectedValueDifference($x, $playerTotal, $dealerVisible, $remainingCards);
            
            // f'(x) = derivada del valor esperado
            $fprime = $this->expectedValueDerivative($x, $playerTotal, $dealerVisible, $remainingCards);
            
            if (abs($fprime) < $tolerance) {
                break;
            }
            
            $xNew = $x - ($f / $fprime);
            
            $steps[] = [
                'iteration' => $i + 1,
                'x' => $x,
                'f_x' => $f,
                'fprime_x' => $fprime,
                'x_new' => $xNew
            ];
            
            if (abs($xNew - $x) < $tolerance) {
                break;
            }
            
            $x = max(0, min(1, $xNew)); // Mantener probabilidad entre 0 y 1
        }
        
        $executionTime = (microtime(true) - $startTime) * 1000;
        
        $result = [
            'optimal_probability' => $x,
            'recommendation' => $x > 0.5 ? 'hit' : 'stand',
            'iterations' => $i + 1,
            'execution_time_ms' => $executionTime,
            'steps' => $steps
        ];
        
        return $result;
    }

    /**
     * Interpolación de Newton para estimar probabilidades de cartas restantes
     */
    public function newtonInterpolation($knownProbabilities, $targetValue) {
        $startTime = microtime(true);
        $n = count($knownProbabilities);
        
        if ($n < 2) {
            throw new Exception("Se necesitan al menos 2 puntos para interpolación");
        }
        
        // Crear tabla de diferencias divididas
        $dividedDifferences = [];
        for ($i = 0; $i < $n; $i++) {
            $dividedDifferences[$i] = [$knownProbabilities[$i]['y']];
        }
        
        // Calcular diferencias divididas
        for ($j = 1; $j < $n; $j++) {
            for ($i = 0; $i < $n - $j; $i++) {
                $x_i = $knownProbabilities[$i]['x'];
                $x_ij = $knownProbabilities[$i + $j]['x'];
                
                $dividedDifferences[$i][$j] = 
                    ($dividedDifferences[$i + 1][$j - 1] - $dividedDifferences[$i][$j - 1]) / 
                    ($x_ij - $x_i);
            }
        }
        
        // Evaluar polinomio en targetValue
        $result = $dividedDifferences[0][0];
        $product = 1;
        
        for ($i = 1; $i < $n; $i++) {
            $product *= ($targetValue - $knownProbabilities[$i - 1]['x']);
            $result += $dividedDifferences[0][$i] * $product;
        }
        
        $executionTime = (microtime(true) - $startTime) * 1000;
        
        return [
            'interpolated_value' => $result,
            'divided_differences' => $dividedDifferences,
            'execution_time_ms' => $executionTime
        ];
    }

    /**
     * Integración trapezoidal para probabilidades acumuladas
     */
    public function trapezoidalIntegration($playerTotal, $dealerVisible, $a = 0, $b = 1, $intervals = 100) {
        $startTime = microtime(true);
        
        $h = ($b - $a) / $intervals;
        $sum = 0;
        
        // Regla del trapecio
        for ($i = 0; $i <= $intervals; $i++) {
            $x = $a + $i * $h;
            $fx = $this->probabilityDensityFunction($x, $playerTotal, $dealerVisible);
            
            if ($i === 0 || $i === $intervals) {
                $sum += $fx;
            } else {
                $sum += 2 * $fx;
            }
        }
        
        $integral = ($h / 2) * $sum;
        $executionTime = (microtime(true) - $startTime) * 1000;
        
        return [
            'cumulative_probability' => $integral,
            'intervals' => $intervals,
            'step_size' => $h,
            'execution_time_ms' => $executionTime
        ];
    }

    /**
     * Análisis completo de una mano usando todos los métodos numéricos
     */
    public function analyzeHand($playerTotal, $dealerVisible, $remainingCards, $handId = null) {
        $analysis = [];
        
        // Newton-Raphson para probabilidad óptima
        $analysis['newton_raphson'] = $this->newtonRaphsonOptimalProbability(
            $playerTotal, $dealerVisible, $remainingCards
        );
        
        // Interpolación para estimar probabilidades de cartas específicas
        $cardProbabilities = $this->calculateCardProbabilities($remainingCards);
        $analysis['newton_interpolation'] = $this->newtonInterpolation(
            $cardProbabilities, $playerTotal
        );
        
        // Integración trapezoidal para probabilidades acumuladas
        $analysis['trapezoidal_integration'] = $this->trapezoidalIntegration(
            $playerTotal, $dealerVisible
        );
        
        // Calcular recomendación final
        $analysis['final_recommendation'] = $this->getFinalRecommendation($analysis);
        
        // Guardar cálculos en BD si se proporciona handId
        if ($handId && $this->db) {
            $this->saveCalculations($handId, $analysis);
        }
        
        return $analysis;
    }

    // Métodos auxiliares privados
    
    private function expectedValueDifference($prob, $playerTotal, $dealerVisible, $remainingCards) {
        $hitValue = $this->calculateHitExpectedValue($playerTotal, $dealerVisible, $remainingCards);
        $standValue = $this->calculateStandExpectedValue($playerTotal, $dealerVisible);
        return $hitValue - $standValue;
    }
    
    private function expectedValueDerivative($prob, $playerTotal, $dealerVisible, $remainingCards) {
        $epsilon = 0.001;
        $f1 = $this->expectedValueDifference($prob + $epsilon, $playerTotal, $dealerVisible, $remainingCards);
        $f2 = $this->expectedValueDifference($prob - $epsilon, $playerTotal, $dealerVisible, $remainingCards);
        return ($f1 - $f2) / (2 * $epsilon);
    }
    
    private function calculateHitExpectedValue($playerTotal, $dealerVisible, $remainingCards) {
        $expectedValue = 0;
        $totalCards = array_sum($remainingCards);
        
        foreach ($remainingCards as $cardValue => $count) {
            $probability = $count / $totalCards;
            $newTotal = $playerTotal + $cardValue;
            
            if ($newTotal > 21) {
                $expectedValue += $probability * (-1); // Bust = -1
            } else {
                // Recursivamente calcular valor de la nueva situación
                $expectedValue += $probability * $this->calculateStandExpectedValue($newTotal, $dealerVisible);
            }
        }
        
        return $expectedValue;
    }
    
    private function calculateStandExpectedValue($playerTotal, $dealerVisible) {
        // Probabilidades precalculadas del dealer basadas en carta visible
        $dealerBustProb = $this->getDealerBustProbability($dealerVisible);
        $dealerTotals = $this->getDealerTotalProbabilities($dealerVisible);
        
        $expectedValue = 0;
        
        // Si dealer se pasa
        $expectedValue += $dealerBustProb * 1;
        
        // Si dealer termina con total específico
        foreach ($dealerTotals as $dealerTotal => $probability) {
            if ($playerTotal > $dealerTotal) {
                $expectedValue += $probability * 1; // Win
            } elseif ($playerTotal < $dealerTotal) {
                $expectedValue += $probability * (-1); // Loss
            }
            // Draw = 0, no afecta al valor esperado
        }
        
        return $expectedValue;
    }
    
    private function getBasicStrategyProbability($playerTotal, $dealerVisible) {
        // Estrategia básica simplificada para valor inicial
        $strategy = [
            // [playerTotal => [dealerVisible => probability_of_hit]]
            5 => [2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            6 => [2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            7 => [2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            8 => [2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            9 => [2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            10 => [2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            11 => [2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            12 => [2 => 1, 3 => 1, 4 => 0, 5 => 0, 6 => 0, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            13 => [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            14 => [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            15 => [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            16 => [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 1, 8 => 1, 9 => 1, 10 => 1, 11 => 1],
            17 => [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0],
            18 => [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0],
            19 => [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0],
            20 => [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0],
            21 => [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0]
        ];
        
        return $strategy[$playerTotal][$dealerVisible] ?? 0.5;
    }
    
    private function getDealerBustProbability($dealerVisible) {
        // Probabilidades de bust del dealer según carta visible
        $bustProbs = [
            2 => 0.35, 3 => 0.37, 4 => 0.40, 5 => 0.42, 6 => 0.42,
            7 => 0.26, 8 => 0.24, 9 => 0.23, 10 => 0.21, 11 => 0.11
        ];
        return $bustProbs[$dealerVisible] ?? 0.25;
    }
    
    private function getDealerTotalProbabilities($dealerVisible) {
        // Probabilidades de totales finales del dealer según carta visible
        $probabilities = [
            2 => [17 => 0.14, 18 => 0.13, 19 => 0.13, 20 => 0.13, 21 => 0.12],
            3 => [17 => 0.14, 18 => 0.13, 19 => 0.13, 20 => 0.12, 21 => 0.11],
            4 => [17 => 0.14, 18 => 0.13, 19 => 0.12, 20 => 0.12, 21 => 0.09],
            5 => [17 => 0.14, 18 => 0.12, 19 => 0.12, 20 => 0.11, 21 => 0.09],
            6 => [17 => 0.17, 18 => 0.11, 19 => 0.11, 20 => 0.10, 21 => 0.09],
            7 => [17 => 0.37, 18 => 0.14, 19 => 0.08, 20 => 0.08, 21 => 0.07],
            8 => [17 => 0.13, 18 => 0.36, 19 => 0.13, 20 => 0.07, 21 => 0.07],
            9 => [17 => 0.12, 18 => 0.12, 19 => 0.35, 20 => 0.12, 21 => 0.06],
            10 => [17 => 0.12, 18 => 0.12, 19 => 0.12, 20 => 0.34, 21 => 0.09],
            11 => [17 => 0.13, 18 => 0.12, 19 => 0.12, 20 => 0.12, 21 => 0.40]
        ];
        return $probabilities[$dealerVisible] ?? [];
    }
    
    private function calculateCardProbabilities($remainingCards) {
        $total = array_sum($remainingCards);
        $probabilities = [];
        
        foreach ($remainingCards as $value => $count) {
            $probabilities[] = [
                'x' => $value,
                'y' => $count / $total
            ];
        }
        
        return $probabilities;
    }
    
    private function probabilityDensityFunction($x, $playerTotal, $dealerVisible) {
        // Función de densidad de probabilidad para integración
        // Simula la distribución de probabilidad de ganar según la situación
        $optimalPoint = $this->getBasicStrategyProbability($playerTotal, $dealerVisible);
        $variance = 0.1;
        
        // Distribución normal centrada en el punto óptimo
        return (1 / sqrt(2 * M_PI * $variance)) * 
               exp(-pow($x - $optimalPoint, 2) / (2 * $variance));
    }
    
    private function getFinalRecommendation($analysis) {
        $newtonRecommendation = $analysis['newton_raphson']['recommendation'];
        $optimalProb = $analysis['newton_raphson']['optimal_probability'];
        $cumulativeProb = $analysis['trapezoidal_integration']['cumulative_probability'];
        
        // Combinar recomendaciones con pesos
        $hitWeight = 0;
        
        if ($newtonRecommendation === 'hit') $hitWeight += 0.5;
        if ($optimalProb > 0.6) $hitWeight += 0.3;
        if ($cumulativeProb > 0.5) $hitWeight += 0.2;
        
        $recommendation = $hitWeight > 0.5 ? 'hit' : 'stand';
        $confidence = abs($hitWeight - 0.5) * 2; // Normalizar a 0-1
        
        return [
            'action' => $recommendation,
            'confidence' => round($confidence, 3),
            'hit_weight' => $hitWeight,
            'reasons' => [
                'newton_raphson' => $newtonRecommendation,
                'optimal_probability' => $optimalProb,
                'cumulative_probability' => $cumulativeProb
            ]
        ];
    }
    
    private function saveCalculations($handId, $analysis) {
        foreach ($analysis as $method => $data) {
            if (in_array($method, ['newton_raphson', 'newton_interpolation', 'trapezoidal_integration'])) {
                $stmt = $this->db->prepare("
                    INSERT INTO numeric_calculations 
                    (hand_id, method_used, input_parameters, calculation_steps, result_value, execution_time_ms)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                $resultValue = 0;
                switch ($method) {
                    case 'newton_raphson':
                        $resultValue = $data['optimal_probability'];
                        break;
                    case 'newton_interpolation':
                        $resultValue = $data['interpolated_value'];
                        break;
                    case 'trapezoidal_integration':
                        $resultValue = $data['cumulative_probability'];
                        break;
                }
                
                $stmt->execute([
                    $handId,
                    $method,
                    json_encode($data),
                    json_encode($data['steps'] ?? []),
                    $resultValue,
                    $data['execution_time_ms']
                ]);
            }
        }
    }
    
    private function calculateHitExpectedValue($playerTotal, $dealerVisible, $remainingCards) {
        $expectedValue = 0;
        $totalCards = array_sum($remainingCards);
        
        foreach ($remainingCards as $cardValue => $count) {
            $probability = $count / $totalCards;
            $newTotal = $playerTotal + $cardValue;
            
            if ($newTotal > 21) {
                $expectedValue += $probability * (-1); // Bust = -1
            } else {
                // Recursivamente calcular valor de la nueva situación
                $expectedValue += $probability * $this->calculateStandExpectedValue($newTotal, $dealerVisible);
            }
        }
        
        return $expectedValue;
    }
    
    private function calculateStandExpectedValue($playerTotal, $dealerVisible) {
        // Probabilidades precalculadas del dealer basadas en carta visible
        $dealerBustProb = $this->getDealerBustProbability($dealerVisible);
        $dealerTotals = $this->getDealerTotalProbabilities($dealerVisible);
        
        $expectedValue = 0;
        
        // Si dealer se pasa
        $expectedValue += $dealerBustProb * 1;
        
        // Si dealer termina con total específico
        foreach ($dealerTotals as $dealerTotal => $probability) {
            if ($playerTotal > $dealerTotal) {
                $expectedValue += $probability * 1; // Win
            } elseif ($playerTotal < $dealerTotal) {
                $expectedValue += $probability * (-1); // Loss
            }
            // Draw = 0, no afecta al valor esperado
        }
        
        return $expectedValue;
    }
    public function getRemainingCardsFromDeck($usedCards) {
        // Inicializar contador de cartas
        $remaining = [
            1 => 4, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 
            8 => 4, 9 => 4, 10 => 16, 11 => 4 // 10, J, Q, K = 16 cartas de valor 10
        ];
        
        // Restar cartas usadas
        foreach ($usedCards as $card) {
            $value = $card->getValue();
            if ($value >= 10) $value = 10; // J, Q, K = 10
            if ($card->isAce()) $value = 11; // As como 11 para cálculos
            
            if (isset($remaining[$value])) {
                $remaining[$value]--;
            }
        }
        
        return $remaining;
    }
}

// controllers/GameController.php
class GameController {
    private $db;
    private $numericMethods;
    
    public function __construct() {
        $this->db = new Database();
        $this->numericMethods = new NumericMethods($this->db->connect());
    }
    
    public function handleRequest() {
        header('Content-Type: application/json');
        
        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'start_game':
                    echo json_encode($this->startGame());
                    break;
                case 'new_hand':
                    echo json_encode($this->newHand());
                    break;
                case 'hit':
                    echo json_encode($this->hit());
                    break;
                case 'stand':
                    echo json_encode($this->stand());
                    break;
                case 'analyze':
                    echo json_encode($this->analyzeCurrentHand());
                    break;
                case 'stats':
                    echo json_encode($this->getStats());
                    break;
                default:
                    throw new Exception('Invalid action');
            }
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function startGame() {
        session_start();
        $playerName = $_POST['player_name'] ?? 'Player';
        
        $game = new Game($this->db, $playerName);
        $_SESSION['game'] = serialize($game);
        
        return ['success' => true, 'message' => 'Game started'];
    }
    
    private function newHand() {
        session_start();
        if (!isset($_SESSION['game'])) {
            throw new Exception('No active game');
        }
        
        $game = unserialize($_SESSION['game']);
        $betAmount = floatval($_POST['bet_amount'] ?? 10);
        
        $handData = $game->startNewHand($betAmount);
        $_SESSION['game'] = serialize($game);
        
        return $handData;
    }
    
    private function hit() {
        session_start();
        if (!isset($_SESSION['game'])) {
            throw new Exception('No active game');
        }
        
        $game = unserialize($_SESSION['game']);
        $result = $game->playerHit();
        $_SESSION['game'] = serialize($game);
        
        return $result;
    }
    
    private function stand() {
        session_start();
        if (!isset($_SESSION['game'])) {
            throw new Exception('No active game');
        }
        
        $game = unserialize($_SESSION['game']);
        $dealerResult = $game->dealerPlay();
        $finalResult = $game->evaluateHand();
        $_SESSION['game'] = serialize($game);
        
        return array_merge($dealerResult, $finalResult);
    }
    
    private function analyzeCurrentHand() {
        session_start();
        if (!isset($_SESSION['game'])) {
            throw new Exception('No active game');
        }
        
        $game = unserialize($_SESSION['game']);
        $player = $game->getPlayer();
        $dealer = $game->getDealer();
        
        $playerTotal = $player->hand->getTotal();
        $dealerVisible = $dealer->hand->getVisibleCard()->getValue();
        $remainingCards = $this->numericMethods->getRemainingCardsFromDeck($game->getDeck()->getUsedCards());
        
        $analysis = $this->numericMethods->analyzeHand($playerTotal, $dealerVisible, $remainingCards);
        
        return $analysis;
    }
    
    private function getStats() {
        session_start();
        if (!isset($_SESSION['game'])) {
            throw new Exception('No active game');
        }
        
        $game = unserialize($_SESSION['game']);
        return $game->getGameStats();
    }
}
?>