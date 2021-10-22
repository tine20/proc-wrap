<?php
/*
 * This file is part of tine20/proc-wrap.
 *
 * (c) Metaways Infosystems GmbH (http://www.metaways.de)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
