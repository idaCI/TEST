<?php

// PHPUNITが実行される場所がカレントディレクトリになるので要注意
require_once './VerificationClass.php';

class VerificationClassTest extends PHPUnit_Framework_TestCase {

	// TEST対象となるクラス
	protected $VerificationClass = '';

	// 初期設定でクラスをインスタンス化
	public function setUp() {
		$this->VerificationClass = new VerificationClass;
	}

	/**
	 * @test
	 */
	function 余剰計算の確認() {

		$result = '';

		// private関数のアクセス権を変更する
		$public_division = new ReflectionMethod('VerificationClass', 'division');
		$public_division->setAccessible(true);

		// 引数が無しの場合はfalseが返却されてくる設計
		$result = $public_division->invoke($this->VerificationClass);
		$this->assertFalse($result);

		// 引数が一つだけの場合もfalseが返却されてくる設計
		$result = $public_division->invoke($this->VerificationClass, 10);
		$this->assertFalse($result);

		// 第一引数が第二引数で割り切れる場合はtureが返却されてくる設計
		$result = $public_division->invoke($this->VerificationClass, 10, 5);
		$this->assertTrue($result);

		// 第一引数が第二引数で割り切れない場合はtureが返却されてくる設計
		$result = $public_division->invoke($this->VerificationClass, 3, 5);
		$this->assertFalse($result);
	}


	/**
	 * @test
	 */
	function 変数の型の確認() {
		$result = '';

		// 引数が数値型の場合は'integer'の文字列が返却されてくる設計
		$result = $this->VerificationClass->cheackType(1);
		$this->assertEquals('integer', $result);

		// 引数が文字列型の場合は'string'の文字列が返却されてくる設計
		$result = $this->VerificationClass->cheackType('TEST');
		$this->assertEquals('string', $result);

		// 引数が浮動型の場合は'float'の文字列が返却されてくる設計
		$result = $this->VerificationClass->cheackType(1.01);
		$this->assertEquals('float', $result);

		// 引数が配列型の場合は'array'の文字列が返却されてくる設計
		$result = $this->VerificationClass->cheackType(array());
		$this->assertEquals('array', $result);

		// 引数が上記のパターン以外は'other'の文字列が返却されてくる設計
		$result = $this->VerificationClass->cheackType(new stdClass);
		$this->assertEquals('other', $result);

	}

	/**
	 * @test
	 */
	function fizzbuzz出来るかの確認() {
		$result = '';

		// 引数が数値型以外の場合はfalseが返却されてくる設計
		$result = $this->VerificationClass->fizzbuzz('TEST');
		$this->assertFalse($result);

		$result = $this->VerificationClass->fizzbuzz(100);

		// 100の中で3で割り切れる数字が出現するのは33回
		$fizz_count = mb_substr_count($result, 'fizz');
		$this->assertEquals(33, $fizz_count);

		// 100の中で5で割り切れる数字が出現するのは20回
		$buzz_count = mb_substr_count($result, 'buzz');
		$this->assertEquals(20, $buzz_count);

		// 100の中で3で割り切れてかつ5で割り切れる数字が出現するのは7回
		$fizzbuzz_count = mb_substr_count($result, 'fizzbuzz');
		$this->assertEquals(6, $fizzbuzz_count);

	}
}

