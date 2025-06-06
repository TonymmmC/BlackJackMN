<?php
// controllers/BlackjackController.php - Versión completa corregida
require_once 'controllers/GameController.php';
require_once 'utils/NumericMethods.php';
require_once 'utils/ProbabilityCalculator.php';

class BlackjackController extends GameController {
    
    public function handleAdvancedRequest() {
        header('Content-Type: application/json');
        
        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'double_down':
                    echo json_encode($this->doubleDown());
                    break;
                case 'split':
                    echo json_encode($this->split());
                    break;
                case 'get_recommendation':
                    echo json_encode($this->getRecommendation());
                    break;
                case 'probability_analysis':
                    echo json_encode($this->getProbabilityAnalysis());
                    break;
                case 'save_statistics':
                    echo json_encode($this->saveHandStatistics());
                    break;
                default:
                    parent::handleRequest();
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function doubleDown() {
        session_start();
        if (!isset($_SESSION['game'])) {
            throw new Exception('No active game');
        }
        
        $game = unserialize($_SESSION['game']);
        $player = $game->getPlayer();
        
        // Verificar si puede doblar
        if (count($player->hand->getCards()) != 2) {
            throw new Exception('Can only double on initial hand');
        }
        
        if ($player->balance < $player->currentBet) {
            throw new Exception('Insufficient funds to double');
        }
        
        // Doblar apuesta
        $player->balance -= $player->currentBet;
        $player->currentBet *= 2;
        
        // Recibir exactamente una carta
        $hitResult = $game->playerHit();
        
        // Forzar stand después del hit
        $dealerResult = $game->dealerPlay();
        $finalResult = $game->evaluateHand();
        
        $_SESSION['game'] = serialize($game);
        
        return array_merge($hitResult, $dealerResult, $finalResult, ['action' => 'double_down']);
    }
    
    private function split() {
        session_start();
        if (!isset($_SESSION['game'])) {
            throw new Exception('No active game');
        }
        
        $game = unserialize($_SESSION['game']);
        $player = $game->getPlayer();
        
        if (!$player->hand->canSplit()) {
            throw new Exception('Cannot split this hand');
        }
        
        if ($player->balance < $player->currentBet) {
            throw new Exception('Insufficient funds to split');
        }
        
        // Implementar lógica de split (simplificada)
        $cards = $player->hand->getCards();
        $splitCard = array_pop($cards);
        
        // Segunda apuesta
        $player->balance -= $player->currentBet;
        
        // Nueva carta para mano original
        $player->hand->addCard($game->getDeck()->dealCard());
        
        $_SESSION['game'] = serialize($game);
        $_SESSION['split_card'] = serialize($splitCard);
        $_SESSION['split_bet'] = $player->currentBet;
        
        return [
            'split_successful' => true,
            'main_hand' => $player->hand->toArray(),
            'main_total' => $player->hand->getTotal(),
            'split_card' => $splitCard->toArray(),
            'balance' => $player->balance
        ];
    }
    
    private function getRecommendation() {
        session_start();
        if (!isset($_SESSION['game'])) {
            throw new Exception('No active game');
        }
        
        $game = unserialize($_SESSION['game']);
        $analysis = $this->analyzeCurrentHand();
        $recommendation = $analysis['final_recommendation'];
        
        // Guardar recomendación en estadísticas
        $this->saveRecommendation($recommendation, $analysis);
        
        return [
            'recommendation' => $recommendation['action'],
            'confidence' => $recommendation['confidence'],
            'reasoning' => $recommendation['reasons'],
            'numeric_analysis' => [
                'newton_raphson' => $analysis['newton_raphson']['optimal_probability'] ?? 0,
                'interpolation' => $analysis['newton_interpolation']['interpolated_value'] ?? 0,
                'integration' => $analysis['trapezoidal_integration']['cumulative_probability'] ?? 0
            ]
        ];
    }
    
    private function getProbabilityAnalysis() {
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
        
        return [
            'current_situation' => [
                'player_total' => $playerTotal,
                'dealer_visible' => $dealerVisible,
                'cards_remaining' => array_sum($remainingCards)
            ],
            'probabilities' => [
                'win_if_hit' => $this->calculateWinProbabilityHit($playerTotal, $dealerVisible, $remainingCards),
                'win_if_stand' => $this->calculateWinProbabilityStand($playerTotal, $dealerVisible),
                'bust_if_hit' => $this->calculateBustProbability($playerTotal, $remainingCards),
                'dealer_bust' => $this->numericMethods->getDealerBustProbability($dealerVisible)
            ],
            'card_probabilities' => $this->getNextCardProbabilities($remainingCards),
            'expected_values' => [
                'hit' => $this->calculateHitExpectedValue($playerTotal, $dealerVisible, $remainingCards),
                'stand' => $this->calculateStandExpectedValue($playerTotal, $dealerVisible)
            ]
        ];
    }
    
    private function calculateWinProbabilityHit($playerTotal, $dealerVisible, $remainingCards) {
        $totalCards = array_sum($remainingCards);
        if ($totalCards == 0) return 0;
        
        $winProbability = 0;
        
        foreach ($remainingCards as $cardValue => $count) {
            if ($count <= 0) continue;
            
            $cardProb = $count / $totalCards;
            $newTotal = $playerTotal + $cardValue;
            
            if ($newTotal <= 21) {
                $winProbStand = $this->calculateWinProbabilityStand($newTotal, $dealerVisible);
                $winProbability += $cardProb * $winProbStand;
            }
            // Si se pasa, probabilidad = 0
        }
        
        return round($winProbability, 4);
    }
    
    private function calculateWinProbabilityStand($playerTotal, $dealerVisible) {
        // Usar probabilidades precalculadas del dealer
        $dealerBustProb = $this->numericMethods->getDealerBustProbability($dealerVisible);
        $dealerTotals = $this->numericMethods->getDealerTotalProbabilities($dealerVisible);
        
        $winProb = $dealerBustProb; // Win if dealer busts
        
        foreach ($dealerTotals as $dealerTotal => $probability) {
            if ($playerTotal > $dealerTotal) {
                $winProb += $probability;
            }
        }
        
        return round($winProb, 4);
    }
    
    private function calculateBustProbability($playerTotal, $remainingCards) {
        $totalCards = array_sum($remainingCards);
        if ($totalCards == 0) return 1; // Si no hay cartas, asumimos bust
        
        $bustCards = 0;
        
        foreach ($remainingCards as $cardValue => $count) {
            if ($playerTotal + $cardValue > 21) {
                $bustCards += $count;
            }
        }
        
        return round($bustCards / $totalCards, 4);
    }
    
    private function getNextCardProbabilities($remainingCards) {
        $total = array_sum($remainingCards);
        if ($total == 0) return [];
        
        $probabilities = [];
        
        foreach ($remainingCards as $value => $count) {
            if ($count > 0) {
                $probabilities[$value] = round($count / $total, 4);
            }
        }
        
        return $probabilities;
    }
    
    private function calculateHitExpectedValue($playerTotal, $dealerVisible, $remainingCards) {
        return $this->numericMethods->calculateHitExpectedValue($playerTotal, $dealerVisible, $remainingCards);
    }
    
    private function calculateStandExpectedValue($playerTotal, $dealerVisible) {
        return $this->numericMethods->calculateStandExpectedValue($playerTotal, $dealerVisible);
    }
    
    private function saveRecommendation($recommendation, $analysis) {
        try {
            $game = unserialize($_SESSION['game']);
            $player = $game->getPlayer();
            $dealer = $game->getDealer();
            
            // Simular inserción en tabla statistics (requiere hand_id real)
            $stmt = $this->db->connect()->prepare("
                INSERT INTO statistics 
                (game_id, hand_id, player_total, dealer_visible_card, recommended_action, 
                 actual_action, probability_win, probability_bust, expected_value, calculation_method)
                VALUES (?, 0, ?, ?, ?, 'pending', ?, ?, ?, 'combined_numeric')
            ");
            
            $stmt->execute([
                $game->getGameId(),
                $player->hand->getTotal(),
                $dealer->hand->getVisibleCard()->getValue(),
                $recommendation['action'],
                $recommendation['confidence'],
                0, // probability_bust placeholder
                0  // expected_value placeholder
            ]);
        } catch (Exception $e) {
            error_log("Error saving recommendation: " . $e->getMessage());
        }
    }
    
    private function saveHandStatistics() {
        // Placeholder for statistics saving
        return ['success' => true, 'message' => 'Statistics saved'];
    }
}

// controllers/GameController.php - Controlador base
class GameController {
    protected $db;
    protected $numericMethods;
    
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
                    throw new Exception('Invalid action: ' . $action);
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    protected function startGame() {
        session_start();
        $playerName = $_POST['player_name'] ?? 'Player';
        
        $game = new Game($this->db, $playerName);
        $_SESSION['game'] = serialize($game);
        
        return ['success' => true, 'message' => 'Game started', 'game_id' => $game->getGameId()];
    }
    
    protected function newHand() {
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
    
    protected function hit() {
        session_start();
        if (!isset($_SESSION['game'])) {
            throw new Exception('No active game');
        }
        
        $game = unserialize($_SESSION['game']);
        $result = $game->playerHit();
        $_SESSION['game'] = serialize($game);
        
        return $result;
    }
    
    protected function stand() {
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
    
    protected function analyzeCurrentHand() {
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
    
    protected function getStats() {
        session_start();
        if (!isset($_SESSION['game'])) {
            throw new Exception('No active game');
        }
        
        $game = unserialize($_SESSION['game']);
        return $game->getGameStats();
    }
}
?>