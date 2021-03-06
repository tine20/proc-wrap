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

namespace Tine20\ProcWrap;

class CmdTest extends \PHPUnit\Framework\TestCase
{
    public function testReturnValue(): void
    {
        $cmd = (new Cmd('echo "a"; exit 1'))->exec();

        $this->assertFalse($cmd->isRunning());
        $this->assertSame('a', rtrim($cmd->getStdOut()), 'stdErr: ' . $cmd->getStdErr());
        $this->assertSame(1, $cmd->getExitCode());
        $this->assertLessThan(100, $cmd->getTotalExecutionTimeMilli());
        $this->assertLessThan(50, $cmd->getExecutionTimeMilli());
    }

    public function testEchoA(): void
    {
        $cmd = (new Cmd(['echo', 'a']))->exec();

        $this->assertFalse($cmd->isRunning());
        $this->assertSame('a', rtrim($cmd->getStdOut()));
        $this->assertSame(0, $cmd->getExitCode());
        $this->assertLessThan(100, $cmd->getTotalExecutionTimeMilli());
        $this->assertLessThan(50, $cmd->getExecutionTimeMilli());
    }

    public function testBackgroundEchoA(): void
    {
        $cmd = (new Cmd('echo "a"'))->runInBackground()->exec();
        while ($cmd->poll()) ;

        $this->assertSame(0, $cmd->getExitCode());
        $this->assertSame('a', rtrim($cmd->getStdOut()));
        $this->assertLessThan(100, $cmd->getTotalExecutionTimeMilli());
        $this->assertLessThan(50, $cmd->getExecutionTimeMilli());
    }

    public function testTimeout(): void
    {
        $start = microtime(true);
        $cmd = (new Cmd('sleep 1 && echo "a"'))->setTimeoutInMilliSeconds(10)->exec();
        $duration = (int)((microtime(true) - $start) * 1000);

        $this->assertSame('', rtrim($cmd->getStdOut()));
        $this->assertSame(SIGKILL, $cmd->getExitCode());
        $this->assertLessThanOrEqual($duration, $cmd->getExecutionTimeMilli());
        $this->assertLessThan(100, $duration);
    }

    public function testStdInStdOut(): void
    {
        ($cmd = new Cmd('echo "OK" && read REPLY && echo $REPLY && read REPLY && echo $REPLY'))
            ->setTimeoutInSeconds(1)
            ->setIODescriptor(
                (new PipeDescriptor(Cmd::STDOUT, 'w', new PipeStreamDelegatorClosure(false, function($data, $chunk) use ($cmd) {
                    static $expect = null;
                    $data .= ltrim($chunk);
                    if (null !== $expect && strpos($data, $expect) === 0) {
                        fwrite($cmd->getIODescriptor(Cmd::STDIN)->getStream(), 'done' . PHP_EOL); /** @phpstan-ignore-line */
                        $data = substr($data, 4);
                    } elseif (strpos($data, 'OK') === 0) {
                        fwrite($cmd->getIODescriptor(Cmd::STDIN)->getStream(), ($expect = 'abc') . PHP_EOL); /** @phpstan-ignore-line */
                        $data = substr($data, 3);
                    }
                    return $data;
                })))
            )->exec();

        $this->assertSame('done', rtrim($cmd->getStdOut()));
        $this->assertSame(0, $cmd->getExitCode());
        $this->assertLessThan(300, $cmd->getTotalExecutionTimeMilli());
        $this->assertLessThan(200, $cmd->getExecutionTimeMilli());
    }

    public function testStdInStdOutBackground(): void
    {
        ($cmd = new Cmd('echo "OK" && read REPLY && echo $REPLY && read REPLY && echo $REPLY'))
            ->runInBackground()
            ->setTimeoutInSeconds(1)
            ->setIODescriptor(
                (new PipeDescriptor(Cmd::STDOUT, 'w', new PipeStreamDelegatorClosure(false, function($data, $chunk) use ($cmd) {
                    static $expect = null;
                    $data .= ltrim($chunk);
                    if (null !== $expect && strpos($data, $expect) === 0) {
                        fwrite($cmd->getIODescriptor(Cmd::STDIN)->getStream(), 'done' . PHP_EOL); /** @phpstan-ignore-line */
                        $data = substr($data, 4);
                    } elseif (strpos($data, 'OK') === 0) {
                        fwrite($cmd->getIODescriptor(Cmd::STDIN)->getStream(), ($expect = 'abc') . PHP_EOL); /** @phpstan-ignore-line */
                        $data = substr($data, 3);
                    }
                    return $data;
                })))
            )->exec();
        while ($cmd->poll()) ;

        $this->assertSame('done', rtrim($cmd->getStdOut()));
        $this->assertSame(0, $cmd->getExitCode());
        $this->assertLessThan(300, $cmd->getTotalExecutionTimeMilli());
        $this->assertLessThan(200, $cmd->getExecutionTimeMilli());
    }

    public function testCmdGrpStdInStdOut(): void
    {
        $group = new CmdGroup();
        $group->attachCmd(
            ($cmd = new Cmd('echo "OK" && read REPLY && echo $REPLY && read REPLY && echo $REPLY'))
                ->setTimeoutInSeconds(1)
                ->setIODescriptor(
                    (new PipeDescriptor(Cmd::STDOUT, 'w', new PipeStreamDelegatorClosure(false, function($data, $chunk) use ($cmd) {
                        static $expect = null;
                        $data .= ltrim($chunk);
                        if (null !== $expect && strpos($data, $expect) === 0) {
                            fwrite($cmd->getIODescriptor(Cmd::STDIN)->getStream(), 'done' . PHP_EOL); /** @phpstan-ignore-line */
                            $data = substr($data, 4);
                        } elseif (strpos($data, 'OK') === 0) {
                            fwrite($cmd->getIODescriptor(Cmd::STDIN)->getStream(), ($expect = 'abc') . PHP_EOL); /** @phpstan-ignore-line */
                            $data = substr($data, 3);
                        }
                        return $data;
                    })))
                )
        );
        $group->attachCmd(
            ($cmd1 = new Cmd('echo "OK" && read REPLY && echo $REPLY && read REPLY && echo $REPLY'))
                ->setTimeoutInSeconds(1)
                ->setIODescriptor(
                    (new PipeDescriptor(Cmd::STDOUT, 'w', new PipeStreamDelegatorClosure(false, function($data, $chunk) use ($cmd1) {
                        static $expect = null;
                        $data .= ltrim($chunk);
                        if (null !== $expect && strpos($data, $expect) === 0) {
                            fwrite($cmd1->getIODescriptor(Cmd::STDIN)->getStream(), 'done1' . PHP_EOL); /** @phpstan-ignore-line */
                            $data = substr($data, 4);
                        } elseif (strpos($data, 'OK') === 0) {
                            fwrite($cmd1->getIODescriptor(Cmd::STDIN)->getStream(), ($expect = 'abc') . PHP_EOL); /** @phpstan-ignore-line */
                            $data = substr($data, 3);
                        }
                        return $data;
                    })))
                )
        );

        $group->exec();

        try {
            $exitCode = $cmd->getExitCode();
        } catch (\Throwable $t) {
            //$this->assertFalse($cmd->poll());
            //$exitCode = $cmd->getExitCode();
            $this->fail($t->getMessage() . $cmd->getStdOut() . PHP_EOL . $cmd->getStdErr());
        }
        $this->assertSame(0, $exitCode, $cmd->getStdOut() . PHP_EOL . $cmd->getStdErr());
        try {
            $exitCode = $cmd1->getExitCode();
        } catch (\Throwable $t) {
            $this->fail($t->getMessage() . $cmd1->getStdOut() . PHP_EOL . $cmd1->getStdErr());
        }
        $this->assertSame(0, $exitCode, $cmd1->getStdOut() . PHP_EOL . $cmd1->getStdErr());

        $this->assertSame('done', rtrim($cmd->getStdOut()));
        $this->assertLessThan(300, $cmd->getTotalExecutionTimeMilli());
        $this->assertLessThan(200, $cmd->getExecutionTimeMilli());
        $this->assertSame('done1', rtrim($cmd1->getStdOut()));
        $this->assertLessThan(300, $cmd1->getTotalExecutionTimeMilli());
        $this->assertLessThan(200, $cmd1->getExecutionTimeMilli());
    }
}
