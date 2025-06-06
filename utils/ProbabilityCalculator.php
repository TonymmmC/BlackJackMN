<?php
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
        
        $decksRemaining = max(1, (52 - $cardsDealt) / 52);
        $trueCount = $count / $decksRemaining;
        
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
        
        $winProbability = $wins / $iterations;
        
        return [
            'win_probability' => $winProbability,
            'iterations' => $iterations,
            'confidence_interval' => self::calculateConfidenceInterval($wins, $iterations)
        ];
    }
    
    private static function simulateHand($playerTotal, $dealerVisible, $action) {
        // Simplified simulation
        $deck = [
            1 => 4, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4, 10 => 16, 11 => 4
        ];
        
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
        if ($totalCards == 0) return 10; // Fallback
        
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
        if ($trials == 0) return ['lower' => 0, 'upper' => 0];
        
        $p = $successes / $trials;
        $z = 1.96; // 95% confidence
        $margin = $z * sqrt(($p * (1 - $p)) / $trials);
        
        return [
            'lower' => max(0, round($p - $margin, 4)),
            'upper' => min(1, round($p + $margin, 4))
        ];
    }
    
    public static function calculateBasicStrategy($playerTotal, $dealerVisible) {
        // Tabla de estrategia básica simplificada
        $strategy = [
            // Hard totals
            8 => ['action' => 'hit', 'confidence' => 1.0],
            9 => ['action' => $dealerVisible >= 3 && $dealerVisible <= 6 ? 'double' : 'hit', 'confidence' => 0.9],
            10 => ['action' => $dealerVisible >= 2 && $dealerVisible <= 9 ? 'double' : 'hit', 'confidence' => 0.95],
            11 => ['action' => 'double', 'confidence' => 0.98],
            12 => ['action' => $dealerVisible >= 4 && $dealerVisible <= 6 ? 'stand' : 'hit', 'confidence' => 0.7],
            13 => ['action' => $dealerVisible >= 2 && $dealerVisible <= 6 ? 'stand' : 'hit', 'confidence' => 0.8],
            14 => ['action' => $dealerVisible >= 2 && $dealerVisible <= 6 ? 'stand' : 'hit', 'confidence' => 0.8],
            15 => ['action' => $dealerVisible >= 2 && $dealerVisible <= 6 ? 'stand' : 'hit', 'confidence' => 0.75],
            16 => ['action' => $dealerVisible >= 2 && $dealerVisible <= 6 ? 'stand' : 'hit', 'confidence' => 0.7],
            17 => ['action' => 'stand', 'confidence' => 1.0],
            18 => ['action' => 'stand', 'confidence' => 1.0],
            19 => ['action' => 'stand', 'confidence' => 1.0],
            20 => ['action' => 'stand', 'confidence' => 1.0],
            21 => ['action' => 'stand', 'confidence' => 1.0]
        ];
        
        if ($playerTotal <= 8) {
            return ['action' => 'hit', 'confidence' => 1.0];
        }
        
        return $strategy[$playerTotal] ?? ['action' => 'stand', 'confidence' => 0.5];
    }
}
?>