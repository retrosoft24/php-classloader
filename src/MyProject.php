<?php

include_once('retro-classloader.php');
// created file vendor/classloader.ini for variables 
// created file vendor/classloader.inc for dependencies

use RetroSoft\TestDependency\TestDependencyClass; // In zip file
use AnotherTest\TestDependency2\TestDependency2Class; // In dir structure

echo "It is my project file\n";

$main = new ClassTest1();

$testDep = new TestDependencyClass(); 

// class loaded correctly

$testDep->testMethod();

TestDependencyClass::testStaticMethod();

$testDep2 = new TestDependency2Class();

echo "$MyVar\n"; // see classloader.ini file

class ClassTest1 {

}
