<?php
// controllers/BlackjackController.php - Lógica completa de juego
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
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function doubleDown() {
        session_start();
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
                'newton_raphson' => $analysis['newton_raphson']['optimal_probability'],
                'interpolation' => $analysis['newton_interpolation']['interpolated_value'],
                'integration' => $analysis['trapezoidal_integration']['cumulative_probability']
            ]
        ];
    }
    
    private function getProbabilityAnalysis() {
        session_start();
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
        $winProbability = 0;
        
        foreach ($remainingCards as $cardValue => $count) {
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
        $probabilities = [];
        
        foreach ($remainingCards as $value => $count) {
            if ($count > 0) {
                $probabilities[$value] = round($count / $total, 4);
            }
        }
        
        return $probabilities;
    }
    
    private function saveRecommendation($recommendation, $analysis) {
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
    }
}

// utils/ProbabilityCalculator.php - Cálculos especializados
class ProbabilityCalculator {
    
    public static function calculateCardCounting($usedCards) {
        // Hi-Lo card counting system
        $count = 0;
        $cardsDealt = count($usedCards);
        
        foreach ($usedCards as $card) {
            $value = $card->getValue();
            
            if ($value >= 2 && $value <= 6) {
                $count += 1; // Low cards
            } elseif ($value >= 10 || $card->isAce()) {
                $count -= 1; // High cards
            }
            // 7, 8, 9 = 0
        }
        
        $trueCount = $cardsDealt > 0 ? $count / (52 - $cardsDealt) * 52 : 0;
        
        return [
            'running_count' => $count,
            'true_count' => round($trueCount, 2),
            'deck_penetration' => round($cardsDealt / 52, 2),
            'advantage' => $trueCount > 0 ? 'player' : 'house'
        ];
    }
    
    public static function calculateOptimalBet($balance, $trueCount, $baseBet = 10) {
        // Kelly criterion adaptation
        if ($trueCount <= 0) {
            return $baseBet;
        }
        
        $advantage = ($trueCount - 1) * 0.005; // ~0.5% per true count
        $winProbability = 0.49 + $advantage;
        $odds = 1; // Even money
        
        // Kelly fraction
        $kellyFraction = ($winProbability * (1 + $odds) - 1) / $odds;
        $kellyFraction = max(0, min($kellyFraction, 0.25)); // Cap at 25%
        
        $optimalBet = $balance * $kellyFraction;
        return max($baseBet, min($optimalBet, $balance * 0.1)); // Min base bet, max 10% of balance
    }
    
    public static function monteCarloSimulation($playerTotal, $dealerVisible, $action, $iterations = 10000) {
        $wins = 0;
        
        for ($i = 0; $i < $iterations; $i++) {
            $result = self::simulateHand($playerTotal, $dealerVisible, $action);
            if ($result['player_wins']) {
                $wins++;
            }
        }
        
        return [
            'win_probability' => $wins / $iterations,
            'iterations' => $iterations,
            'confidence_interval' => self::calculateConfidenceInterval($wins, $iterations)
        ];
    }
    
    private static function simulateHand($playerTotal, $dealerVisible, $action) {
        // Simplified simulation
        $deck = array_fill(1, 4, 1); // 4 of each value
        $deck[10] = 16; // 10, J, Q, K
        $deck[11] = 4; // Aces as 11
        
        // Player action
        if ($action === 'hit') {
            $card = self::drawRandomCard($deck);
            $playerTotal += $card;
            if ($playerTotal > 21) {
                return ['player_wins' => false, 'reason' => 'player_bust'];
            }
        }
        
        // Dealer play
        $dealerTotal = $dealerVisible;
        while ($dealerTotal < 17) {
            $card = self::drawRandomCard($deck);
            $dealerTotal += $card;
        }
        
        if ($dealerTotal > 21) {
            return ['player_wins' => true, 'reason' => 'dealer_bust'];
        }
        
        return [
            'player_wins' => $playerTotal > $dealerTotal,
            'reason' => $playerTotal > $dealerTotal ? 'higher_total' : 'lower_total'
        ];
    }
    
    private static function drawRandomCard($deck) {
        $totalCards = array_sum($deck);
        $random = mt_rand(1, $totalCards);
        
        $cumulative = 0;
        foreach ($deck as $value => $count) {
            $cumulative += $count;
            if ($random <= $cumulative) {
                return $value;
            }
        }
        return 10; // fallback
    }
    
    private static function calculateConfidenceInterval($successes, $trials, $confidence = 0.95) {
        $p = $successes / $trials;
        $z = 1.96; // 95% confidence
        $margin = $z * sqrt(($p * (1 - $p)) / $trials);
        
        return [
            'lower' => max(0, $p - $margin),
            'upper' => min(1, $p + $margin)
        ];
    }
}

// models/Statistics.php - Manejo de estadísticas
class Statistics {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    public function getPlayerPerformance($gameId) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_hands,
                SUM(CASE WHEN result IN ('win', 'blackjack') THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN result = 'loss' THEN 1 ELSE 0 END) as losses,
                SUM(CASE WHEN result = 'draw' THEN 1 ELSE 0 END) as draws,
                SUM(profit_loss) as total_profit,
                AVG(bet_amount) as avg_bet,
                MAX(profit_loss) as biggest_win,
                MIN(profit_loss) as biggest_loss
            FROM hands WHERE game_id = ?
        ");
        $stmt->execute([$gameId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($stats['total_hands'] > 0) {
            $stats['win_rate'] = round($stats['wins'] / $stats['total_hands'], 4);
            $stats['avg_profit_per_hand'] = round($stats['total_profit'] / $stats['total_hands'], 2);
        }
        
        return $stats;
    }
    
    public function getNumericMethodsPerformance($gameId) {
        $stmt = $this->db->prepare("
            SELECT 
                method_used,
                COUNT(*) as usage_count,
                AVG(result_value) as avg_result,
                AVG(execution_time_ms) as avg_execution_time,
                MIN(execution_time_ms) as min_time,
                MAX(execution_time_ms) as max_time
            FROM numeric_calculations nc
            JOIN hands h ON nc.hand_id = h.id
            WHERE h.game_id = ?
            GROUP BY method_used
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getRecommendationAccuracy($gameId) {
        $stmt = $this->db->prepare("
            SELECT 
                recommended_action,
                actual_action,
                COUNT(*) as frequency,
                AVG(probability_win) as avg_win_prob
            FROM statistics s
            JOIN hands h ON s.hand_id = h.id
            WHERE h.game_id = ? AND actual_action != 'pending'
            GROUP BY recommended_action, actual_action
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function exportGameData($gameId) {
        $game = $this->getGameSummary($gameId);
        $hands = $this->getHandHistory($gameId);
        $calculations = $this->getCalculationHistory($gameId);
        
        return [
            'game_summary' => $game,
            'hand_history' => $hands,
            'numeric_calculations' => $calculations,
            'export_timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    private function getGameSummary($gameId) {
        $stmt = $this->db->prepare("SELECT * FROM game_summary WHERE id = ?");
        $stmt->execute([$gameId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function getHandHistory($gameId) {
        $stmt = $this->db->prepare("SELECT * FROM hands WHERE game_id = ? ORDER BY hand_number");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getCalculationHistory($gameId) {
        $stmt = $this->db->prepare("
            SELECT nc.* FROM numeric_calculations nc
            JOIN hands h ON nc.hand_id = h.id
            WHERE h.game_id = ?
            ORDER BY nc.timestamp
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>