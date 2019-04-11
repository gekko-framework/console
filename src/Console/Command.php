<?php
/*
 * (c) Leonardo Brugnara
 *
 * Full copyright and license information in LICENSE file.
 */

namespace Gekko\Console;

abstract class Command implements ICommand
{
    public function onInit(ConsoleContext $ctx) : void
    {
    }

    public function run(ConsoleContext $ctx) : int
    {
        return 0;
    }

    public function onFinish(ConsoleContext $ctx) : void
    {
    }
}
