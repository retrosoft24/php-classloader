Java-style Class Loader for PHP
====================
The lightweight and powerful tool in one file. It is NOT compatible with Composer and useful for production.

Features:
--------------------
- Load classes from zip-archives downloaded from GitHub or other sources without unpacking (using composer.json).
- Support composer json files and "vendor" directory with dependencies.
- "includes" directory support to include project files automatically.
- Detect project php-files in "classpath"/"lib" or "vendor" directories.
- Support simple setting system environment, ini_set and php $GLOBALS variables.
- File loading operators for variables: "@file:","@json:", "@php:", "@ini:", "@lines:" (example: .var=@file:readme.txt).
- More easy and faster than Composer but it does not download dependencies yet.

For working with zip-packed dependencies put it into "classpath"/"lib/vendor" or into the level up project main directory. Classes will be loaded without unpacking. Dependencies without resource files supported only.

Usage:
--------------------
It is one file only. Simple copy retro-classloader.php into your project directory or other. In the php-file write include_once("vendor/retro-classloader.php"); where was written  include_once("vendor/autoload.php");

That is all. So, you can use composer to add new dependencies to the project and this class loader for the production environment.

After first start, the class mapping file "retro-classloader.inc" will be generated and "retro-classloader.ini" for variables.

This class loader is intended for use with projects which have a lot of dependencies. Because it loads files from zip/jar-archives instead of unpacking it, like Composer does. The 5-10 zip-archives in the project is better than a big tree with many hundreds of unpacked php-files and directories.
Classes/files in "includes" directory (inside one of "classpath"/"lib"/"vendor" directories) is for autoloading.

Example of usage 
--------------------
Place "retro-classloader.php" into your project directory (or "classpath" and etc.):

    [vendor]
    retro-classloader.php
    MyProject.php  

In "vendor" directory:

    [AnotherTest]
    [includes] 
    autoload.php
    php-test-dependency-master.zip
    
File php-test-dependency-master.zip is made in GitHub download format.
Run ```php MyProject.php``` it has simple code:

```php
<?php    
include_once("retro-classloader.php");    
// created file vendor/retro-classloader.ini for variables 
// created file vendor/retro-classloader.inc for dependencies

use RetroSoft\TestDependency\TestDependencyClass; // In zip file
use AnotherTest\TestDependency2\TestDependency2Class; // In dir structure

echo "It is my project file\n";    

$main = new ClassTest1();
    
$testDep = new TestDependencyClass();
// class loaded correctly
$testDep->testMethod();    
TestDependencyClass::testStaticMethod();

$testDep2 = new TestDependency2Class();

echo "$MyVar\n"; // see retro-classloader.ini file

class ClassTest1 {

}

```
Results:

    >php.exe MyProject.php
    Test Include: test-include1.php
    Test Include: test-include2.php
    It is my project file
    TestDependencyClass constructor called
    TestDependencyClass testMethod() called
    TestDependencyClass testStaticMethod() called
    TestDependency2Class constructor called
    Text or something else...

MIT license. Copyright (c) 2022 Retro Soft, Inc. 
http://retrosoft.ru/

Author: Dmitry Nevstruev <braincoder@retrosoft.ru>
