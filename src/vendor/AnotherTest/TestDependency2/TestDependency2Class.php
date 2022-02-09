<?php

namespace AnotherTest\TestDependency2;

class TestDependency2Class {

	public function __construct() {
		echo "TestDependency2Class constructor called\n";
	}

	public function testMethod() {
		echo "TestDependency2Class testMethod() called\n";
	}

	public static function testStaticMethod() {
		echo "TestDependency2Class testStaticMethod() called\n";
	}

}
