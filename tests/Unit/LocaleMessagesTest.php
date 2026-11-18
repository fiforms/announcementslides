<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * vue-i18n compiles messages lazily, so a bad one (a bare "@", stray "{" ...)
 * only blows up when a page renders it, blanking the screen. Compile them all.
 */
class LocaleMessagesTest extends TestCase
{
    public function test_all_locale_messages_compile(): void
    {
        $root = dirname(__DIR__, 2);

        if (! is_dir($root.'/node_modules/@intlify/message-compiler')) {
            $this->markTestSkipped('node_modules not installed (run npm install).');
        }

        $process = new Process(['node', 'scripts/check-locales.mjs'], $root);
        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->markTestSkipped('node is not available: '.$e->getMessage());
        }

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }
}
