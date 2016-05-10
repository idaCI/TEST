<?php

class VerificationClass {

	private $integer;
	private $string;
	private $float;
	private $array;
	
	/**
	 * 変数の初期化だけ行う
	 */
	function __construct() {
		$this->integer = '';
		$this->string = '';
		$this->float = '';
		$this->array = array();
	}
	
	/**
	 * 余剰計算する
	 * $integer % $division の結果が0ならtrueを返すそれ以外ならfalseを返す
	 *
	 * @param integer $integer 余剰計算したい基の数値
	 * @param integer $division 割りたい数
	 *
	 * @return bool 結果が0ならture,それ以外はfalse
	 */
	private function division($integer = null, $division = null) {
		// validation : integer型で無い場合はfalseを返す
		if (!is_int($integer) || !is_int($division)) {
			return false;
		}
	
		if (($integer % $division) == 0) {
			return true;
		}
	
		return false;
	}
	
	
	/**
	 * 引数の型をチェックして型のtypeを文字列で返却する
	 * integer,string,float,arrayのみチェックその他はnullとして返却
	 *
	 * @param mixed $value 型のチェックをしたい値
	 * 
	 * @return string 型のtypeの文字列
	 */
	public function cheackType($value = null) {
		if (is_int($value) === true) {
			return 'integer';
		}
		if (is_string($value) === true) {
			return 'string';
		}
		if (is_float($value) === true) {
			return 'float';
		}
		if (is_array($value) === true) {
			return 'array';
		}
		return 'other';
	}
	
	/**
	 * fizzBuzzする関数
	 * 3で割り切れる場合はfiz
	 * 5で割り切れる場合あbuzz
	 * 両方で割り切れる場合はfiz buzz
	 *
	 * @param integer $value 型のチェックをしたい値
	 * 
	 * @return なし
	 */
	public function fizzBuzz($value = 30) {

		// validation : integer型で無い場合はfalseを返す
		if (!is_int($value)) {
			return false;
		}

		$fizzbuzz = '';
		for ($i=1; $i<=$value; $i++) {
			if ($this->division($i, 3) === true) {
				$fizzbuzz .= 'fizz';
			}
	
			if ($this->division($i, 5) === true) {
				$fizzbuzz .= 'buzz';
			}

			if (($this->division($i, 3) === false) && ($this->division($i, 5) === false)) {
				$fizzbuzz .= $i;
			}
			$fizzbuzz .= PHP_EOL;
		}
		return $fizzbuzz;
	}
}

