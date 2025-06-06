<?php
// config/Database.php
class Database {
    private $host = 'localhost';
    private $dbname = 'blackjack_numeric';
    private $username = 'root';
    private $password = '';
    private $pdo;

    public function connect() {
        if ($this->pdo === null) {
            try {
                $this->pdo = new PDO(
                    "mysql:host={$this->host};dbname={$this->dbname};charset=utf8",
                    $this->username,
                    $this->password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            } catch (PDOException $e) {
                throw new Exception("Connection failed: " . $e->getMessage());
            }
        }
        return $this->pdo;
    }
}

// models/Card.php
class Card {
    public $suit;
    public $rank;
    public $value;
    public $altValue;

    public function __construct($suit, $rank, $value, $altValue = null) {
        $this->suit = $suit;
        $this->rank = $rank;
        $this->value = $value;
        $this->altValue = $altValue;
    }

    public function isAce() {
        return $this->rank === 'A';
    }

    public function getValue($useAlt = false) {
        return ($useAlt && $this->altValue) ? $this->altValue : $this->value;
    }

    public function toArray() {
        return [
            'suit' => $this->suit,
            'rank' => $this->rank,
            'value' => $this->value,
            'altValue' => $this->altValue
        ];
    }
}

// models/Deck.php
class Deck {
    private $cards = [];
    private $usedCards = [];

    public function __construct() {
        $this->initialize();
        $this->shuffle();
    }

    private function initialize() {
        $suits = ['hearts', 'diamonds', 'clubs', 'spades'];
        $ranks = [
            ['A', 1, 11], ['2', 2], ['3', 3], ['4', 4], ['5', 5], ['6', 6],
            ['7', 7], ['8', 8], ['9', 9], ['10', 10], ['J', 10], ['Q', 10], ['K', 10]
        ];

        foreach ($suits as $suit) {
            foreach ($ranks as $rank) {
                $this->cards[] = new Card($suit, $rank[0], $rank[1], $rank[2] ?? null);
            }
        }
    }

    public function shuffle() {
        shuffle($this->cards);
    }

    public function dealCard() {
        if (empty($this->cards)) {
            throw new Exception("Deck is empty");
        }
        $card = array_pop($this->cards);
        $this->usedCards[] = $card;
        return $card;
    }

    public function getRemainingCards() {
        return count($this->cards);
    }

    public function getUsedCards() {
        return $this->usedCards;
    }

    public function reset() {
        $this->cards = array_merge($this->cards, $this->usedCards);
        $this->usedCards = [];
        $this->shuffle();
    }
}

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

// models/Game.php
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
        $this->player->hand->addCard($this->deck->dealCard());
        return [
            'card' => end($this->player->hand->getCards())->toArray(),
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

// Autoloader simple
spl_autoload_register(function ($class) {
    $paths = ['models/', 'config/', 'controllers/', 'utils/'];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
?>