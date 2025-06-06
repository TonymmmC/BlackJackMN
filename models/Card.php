<?php
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
?>