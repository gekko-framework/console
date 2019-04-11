<?php
/*
 * (c) Leonardo Brugnara
 *
 * Full copyright and license information in LICENSE file.
 */

namespace Gekko\Console;

use \Gekko\DependencyInjection\IDependencyInjector;

interface ICommand
{
    function onInit(ConsoleContext $ctx) : void;
    function run(ConsoleContext $ctx) : int;
    function onFinish(ConsoleContext $ctx) : void;
}
