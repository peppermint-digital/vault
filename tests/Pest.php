<?php

use Peppermint\Vault\Tests\MysqlTestCase;
use Peppermint\Vault\Tests\QuellenTestCase;
use Peppermint\Vault\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Eigener Ordner, weil Pest je Ordner genau einen Testfall bindet.
uses(MysqlTestCase::class)->in('Mysql');

uses(QuellenTestCase::class)->in('Quellen');
