<?php
// models/Game.php
require_once 'models/Card.php';
require_once 'models/Deck.php';
require_once 'models/Hand.php';
require_once 'models/Player.php';

class Game {
    private $db;
    private $gameId;
    private $player;
    private $dealer;
    private $deck;
    private $gameData;

    public function __construct(Database $database, $playerName) {
        $this->db = $database->connect();
        $this->player = new Player($playerName);
        $this->dealer = new Player("Dealer");
        $this->dealer->hand = new Hand(true);
        $this->deck = new Deck();
        $this->createGame();
    }

    private function createGame() {
        $stmt = $this->db->prepare("
            INSERT INTO games (player_name, initial_balance, current_balance) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $this->player->name, 
            $this->player->balance, 
            $this->player->balance
        ]);
        $this->gameId = $this->db->lastInsertId();
    }

    public function startNewHand($betAmount) {
        $this->player->placeBet($betAmount);
        $this->player->resetHand();
        $this->dealer->resetHand();

        // Repartir cartas iniciales
        $this->player->hand->addCard($this->deck->dealCard());
        $this->dealer->hand->addCard($this->deck->dealCard());
        $this->player->hand->addCard($this->deck->dealCard());
        $this->dealer->hand->addCard($this->deck->dealCard());

        return [
            'player_cards' => $this->player->hand->toArray(),
            'dealer_visible' => $this->dealer->hand->getVisibleCard()->toArray(),
            'player_total' => $this->player->hand->getTotal(),
            'can_double' => true,
            'can_split' => $this->player->hand->canSplit()
        ];
    }

    public function playerHit() {
        $card = $this->deck->dealCard();
        $this->player->hand->addCard($card);
        return [
            'card' => $card->toArray(),
            'total' => $this->player->hand->getTotal(),
            'is_bust' => $this->player->hand->isBust()
        ];
    }

    public function dealerPlay() {
        while ($this->dealer->hand->getTotal() < 17) {
            $this->dealer->hand->addCard($this->deck->dealCard());
        }
        return [
            'cards' => $this->dealer->hand->toArray(),
            'total' => $this->dealer->hand->getTotal(),
            'is_bust' => $this->dealer->hand->isBust()
        ];
    }

    public function evaluateHand() {
        $playerTotal = $this->player->hand->getTotal();
        $dealerTotal = $this->dealer->hand->getTotal();
        $playerBlackjack = $this->player->hand->isBlackjack();
        $dealerBlackjack = $this->dealer->hand->isBlackjack();

        $result = '';
        $multiplier = 0;

        if ($this->player->hand->isBust()) {
            $result = 'loss';
        } elseif ($this->dealer->hand->isBust()) {
            $result = 'win';
            $multiplier = 1;
        } elseif ($playerBlackjack && !$dealerBlackjack) {
            $result = 'blackjack';
            $multiplier = 1.5;
        } elseif ($dealerBlackjack && !$playerBlackjack) {
            $result = 'loss';
        } elseif ($playerTotal > $dealerTotal) {
            $result = 'win';
            $multiplier = 1;
        } elseif ($playerTotal < $dealerTotal) {
            $result = 'loss';
        } else {
            $result = 'draw';
            $this->player->pushBet();
        }

        if ($result === 'win' || $result === 'blackjack') {
            $winnings = $this->player->winBet($multiplier);
        } elseif ($result === 'loss') {
            $this->player->loseBet();
        }

        $this->saveHand($result);
        $this->updateGameStats();

        return [
            'result' => $result,
            'player_total' => $playerTotal,
            'dealer_total' => $dealerTotal,
            'balance' => $this->player->balance
        ];
    }

    private function saveHand($result) {
        $stmt = $this->db->prepare("
            INSERT INTO hands (game_id, hand_number, bet_amount, player_cards, dealer_cards, 
                             player_total, dealer_total, result, profit_loss) 
            VALUES (?, (SELECT COALESCE(MAX(hand_number), 0) + 1 FROM hands h WHERE h.game_id = ?), 
                   ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $profitLoss = 0;
        if ($result === 'win') $profitLoss = $this->player->currentBet;
        elseif ($result === 'blackjack') $profitLoss = $this->player->currentBet * 1.5;
        elseif ($result === 'loss') $profitLoss = -$this->player->currentBet;

        $stmt->execute([
            $this->gameId, $this->gameId, $this->player->currentBet,
            json_encode($this->player->hand->toArray()),
            json_encode($this->dealer->hand->toArray()),
            $this->player->hand->getTotal(),
            $this->dealer->hand->getTotal(),
            $result, $profitLoss
        ]);
    }

    private function updateGameStats() {
        $stmt = $this->db->prepare("
            UPDATE games SET 
                total_hands = (SELECT COUNT(*) FROM hands WHERE game_id = ?),
                wins = (SELECT COUNT(*) FROM hands WHERE game_id = ? AND result IN ('win', 'blackjack')),
                losses = (SELECT COUNT(*) FROM hands WHERE game_id = ? AND result = 'loss'),
                draws = (SELECT COUNT(*) FROM hands WHERE game_id = ? AND result = 'draw'),
                current_balance = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $this->gameId, $this->gameId, $this->gameId, $this->gameId,
            $this->player->balance, $this->gameId
        ]);
    }

    public function getGameStats() {
        $stmt = $this->db->prepare("SELECT * FROM game_summary WHERE id = ?");
        $stmt->execute([$this->gameId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getPlayer() {
        return $this->player;
    }

    public function getDealer() {
        return $this->dealer;
    }

    public function getDeck() {
        return $this->deck;
    }

    public function getGameId() {
        return $this->gameId;
    }
}
?>