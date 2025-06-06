<?php
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

// models/Statics.php placeholder
class Statics {
    // This file was empty in the original documents
    // Add static methods as needed
}
?>