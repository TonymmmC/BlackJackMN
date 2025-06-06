<?php
// models/Hand.php
class Hand {
    private $cards = [];
    private $isDealer = false;

    public function __construct($isDealer = false) {
        $this->isDealer = $isDealer;
    }

    public function addCard(Card $card) {
        $this->cards[] = $card;
    }

    public function getCards() {
        return $this->cards;
    }

    public function getTotal() {
        $total = 0;
        $aces = 0;

        foreach ($this->cards as $card) {
            if ($card->isAce()) {
                $aces++;
                $total += 11;
            } else {
                $total += $card->getValue();
            }
        }

        // Ajustar ases si es necesario
        while ($total > 21 && $aces > 0) {
            $total -= 10;
            $aces--;
        }

        return $total;
    }

    public function isBust() {
        return $this->getTotal() > 21;
    }

    public function isBlackjack() {
        return count($this->cards) === 2 && $this->getTotal() === 21;
    }

    public function canSplit() {
        return count($this->cards) === 2 && 
               $this->cards[0]->getValue() === $this->cards[1]->getValue();
    }

    public function getVisibleCard() {
        return $this->isDealer && count($this->cards) > 0 ? $this->cards[0] : null;
    }

    public function toArray() {
        return array_map(function($card) {
            return $card->toArray();
        }, $this->cards);
    }

    public function clear() {
        $this->cards = [];
    }
}

// models/Player.php
class Player {
    public $name;
    public $balance;
    public $hand;
    public $currentBet;

    public function __construct($name, $balance = 1000) {
        $this->name = $name;
        $this->balance = $balance;
        $this->hand = new Hand();
        $this->currentBet = 0;
    }

    public function placeBet($amount) {
        if ($amount > $this->balance) {
            throw new Exception("Insufficient funds");
        }
        $this->currentBet = $amount;
        $this->balance -= $amount;
    }

    public function winBet($multiplier = 1) {
        $winnings = $this->currentBet * (1 + $multiplier);
        $this->balance += $winnings;
        return $winnings;
    }

    public function loseBet() {
        $loss = $this->currentBet;
        $this->currentBet = 0;
        return $loss;
    }

    public function pushBet() {
        $this->balance += $this->currentBet;
        $this->currentBet = 0;
    }

    public function resetHand() {
        $this->hand->clear();
        $this->currentBet = 0;
    }
}

// models/Statistics.php - Faltaba esta clase
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
                AVG(CASE WHEN result IN ('win', 'blackjack') THEN 1 ELSE 0 END) as win_rate,
                AVG(profit_loss) as avg_profit_per_hand,
                SUM(profit_loss) as total_profit
            FROM hands 
            WHERE game_id = ?
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getNumericMethodsPerformance($gameId) {
        $stmt = $this->db->prepare("
            SELECT 
                method_used,
                COUNT(*) as usage_count,
                AVG(execution_time_ms) as avg_execution_time,
                AVG(result_value) as avg_result_value
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
            WHERE game_id = ? AND actual_action != 'pending'
            GROUP BY recommended_action, actual_action
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function exportGameData($gameId) {
        $gameData = [];
        
        // Información del juego
        $stmt = $this->db->prepare("SELECT * FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
        $gameData['game_info'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Manos jugadas
        $stmt = $this->db->prepare("SELECT * FROM hands WHERE game_id = ? ORDER BY hand_number");
        $stmt->execute([$gameId]);
        $gameData['hands'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Estadísticas
        $stmt = $this->db->prepare("SELECT * FROM statistics WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $gameData['statistics'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cálculos numéricos
        $stmt = $this->db->prepare("
            SELECT nc.* FROM numeric_calculations nc
            JOIN hands h ON nc.hand_id = h.id
            WHERE h.game_id = ?
        ");
        $stmt->execute([$gameId]);
        $gameData['numeric_calculations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $gameData;
    }
    
    public function saveRecommendation($gameId, $handId, $playerTotal, $dealerVisible, $recommendation) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO statistics 
                (game_id, hand_id, player_total, dealer_visible_card, recommended_action, 
                 actual_action, probability_win, probability_bust, expected_value, calculation_method)
                VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $gameId,
                $handId,
                $playerTotal,
                $dealerVisible,
                $recommendation['action'],
                $recommendation['confidence'],
                0, // probability_bust placeholder
                0, // expected_value placeholder
                'combined_numeric'
            ]);
            
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log("Error saving recommendation: " . $e->getMessage());
            return false;
        }
    }
    
    public function updateActualAction($statisticId, $actualAction) {
        try {
            $stmt = $this->db->prepare("
                UPDATE statistics 
                SET actual_action = ? 
                WHERE id = ?
            ");
            $stmt->execute([$actualAction, $statisticId]);
            return true;
        } catch (Exception $e) {
            error_log("Error updating actual action: " . $e->getMessage());
            return false;
        }
    }
}

// models/Statics.php - Agregando contenido a la clase vacía
class Statics {
    
    /**
     * Tabla de estrategia básica de blackjack
     */
    public static function getBasicStrategyTable() {
        return [
            // Hard totals (sin ases)
            'hard' => [
                8 => ['2' => 'H', '3' => 'H', '4' => 'H', '5' => 'H', '6' => 'H', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                9 => ['2' => 'H', '3' => 'D', '4' => 'D', '5' => 'D', '6' => 'D', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                10 => ['2' => 'D', '3' => 'D', '4' => 'D', '5' => 'D', '6' => 'D', '7' => 'D', '8' => 'D', '9' => 'D', '10' => 'H', 'A' => 'H'],
                11 => ['2' => 'D', '3' => 'D', '4' => 'D', '5' => 'D', '6' => 'D', '7' => 'D', '8' => 'D', '9' => 'D', '10' => 'D', 'A' => 'H'],
                12 => ['2' => 'H', '3' => 'H', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                13 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                14 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                15 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                16 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                17 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'S', '8' => 'S', '9' => 'S', '10' => 'S', 'A' => 'S'],
                18 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'S', '8' => 'S', '9' => 'S', '10' => 'S', 'A' => 'S'],
                19 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'S', '8' => 'S', '9' => 'S', '10' => 'S', 'A' => 'S'],
                20 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'S', '8' => 'S', '9' => 'S', '10' => 'S', 'A' => 'S'],
                21 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'S', '8' => 'S', '9' => 'S', '10' => 'S', 'A' => 'S']
            ],
            // Soft totals (con as)
            'soft' => [
                13 => ['2' => 'H', '3' => 'H', '4' => 'H', '5' => 'D', '6' => 'D', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                14 => ['2' => 'H', '3' => 'H', '4' => 'H', '5' => 'D', '6' => 'D', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                15 => ['2' => 'H', '3' => 'H', '4' => 'D', '5' => 'D', '6' => 'D', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                16 => ['2' => 'H', '3' => 'H', '4' => 'D', '5' => 'D', '6' => 'D', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                17 => ['2' => 'H', '3' => 'D', '4' => 'D', '5' => 'D', '6' => 'D', '7' => 'H', '8' => 'H', '9' => 'H', '10' => 'H', 'A' => 'H'],
                18 => ['2' => 'S', '3' => 'D', '4' => 'D', '5' => 'D', '6' => 'D', '7' => 'S', '8' => 'S', '9' => 'H', '10' => 'H', 'A' => 'H'],
                19 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'S', '8' => 'S', '9' => 'S', '10' => 'S', 'A' => 'S'],
                20 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'S', '8' => 'S', '9' => 'S', '10' => 'S', 'A' => 'S'],
                21 => ['2' => 'S', '3' => 'S', '4' => 'S', '5' => 'S', '6' => 'S', '7' => 'S', '8' => 'S', '9' => 'S', '10' => 'S', 'A' => 'S']
            ]
        ];
    }
    
    /**
     * Probabilidades de bust del dealer según carta visible
     */
    public static function getDealerBustProbabilities() {
        return [
            2 => 0.3526,
            3 => 0.3745,
            4 => 0.4012,
            5 => 0.4283,
            6 => 0.4238,
            7 => 0.2618,
            8 => 0.2385,
            9 => 0.2307,
            10 => 0.2105,
            'A' => 0.1152
        ];
    }
    
    /**
     * Valores de las cartas para counting
     */
    public static function getCardCountingValues() {
        return [
            'hi_lo' => [
                2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1,
                7 => 0, 8 => 0, 9 => 0,
                10 => -1, 'J' => -1, 'Q' => -1, 'K' => -1, 'A' => -1
            ],
            'ko' => [
                2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1,
                8 => 0, 9 => 0,
                10 => -1, 'J' => -1, 'Q' => -1, 'K' => -1, 'A' => -1
            ]
        ];
    }
    
    /**
     * Obtener recomendación de estrategia básica
     */
    public static function getBasicStrategyRecommendation($playerTotal, $dealerCard, $hasAce = false) {
        $table = self::getBasicStrategyTable();
        $tableType = $hasAce ? 'soft' : 'hard';
        
        $dealerValue = is_numeric($dealerCard) ? $dealerCard : $dealerCard;
        
        if (isset($table[$tableType][$playerTotal][$dealerValue])) {
            $action = $table[$tableType][$playerTotal][$dealerValue];
            
            switch ($action) {
                case 'H': return 'hit';
                case 'S': return 'stand';
                case 'D': return 'double';
                case 'P': return 'split';
                default: return 'hit';
            }
        }
        
        return $playerTotal >= 17 ? 'stand' : 'hit';
    }
    
    /**
     * Constantes del juego
     */
    const BLACKJACK_PAYOUT = 1.5;
    const DEALER_STANDS_ON = 17;
    const DECK_SIZE = 52;
    const SUITS = ['hearts', 'diamonds', 'clubs', 'spades'];
    const RANKS = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
}
?>