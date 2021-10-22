<?php

declare(strict_types=1);

class AllTests
{
    /**
     * @return \PHPUnit\Framework\TestSuite<\PHPUnit\Framework\Test>
     */
    public static function suite(): \PHPUnit\Framework\TestSuite
    {
        $suite = new \PHPUnit\Framework\TestSuite('Tine 2.0 All Tests');

        $suite->addTestSuite(\Tine20\ProcWrap\CmdTest::class);

        return $suite;
    }
}
