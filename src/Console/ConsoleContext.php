<?php
/*
 * (c) Leonardo Brugnara
 *
 * Full copyright and license information in LICENSE file.
 */

namespace Gekko\Console;

use \Gekko\Env;
use \Gekko\Config\{ ConfigProvider, IConfigProvider };
use \Gekko\DependencyInjection\DependencyInjector;

class ConsoleContext
{
    /**
     * Dependency Injector class used to resolve dependencies
     * 
     * @var \Gekko\DependencyInjection\IDependencyInjector
     */
    protected $injector;

    /**
     * Configuration provider
     * 
     * @var \Gekko\Config\IConfigProvider
     */
    protected $configProvider;


    /**
     * Registered console applications
     * 
     * @var string[]
     */
    protected $apps;

    /**
     * Default console application
     * 
     * @var string[]
     */
    protected $defaultApp;

    /**
     * Arguments count
     * 
     * @var int
     */
    protected $argc;

    /**
     * Arguments
     * 
     * @var string[]
     */
    protected $argv;

    public function __construct(int $argc, array $argv)
    {
        $this->argc = $argc;
        $this->argv = $argv;
        $this->apps = [];

        $this->injector = new DependencyInjector();

        $configPath = Env::toLocalPath(Env::get("config.path") ?? "config");

        $this->configProvider = new ConfigProvider(Env::get("config.driver") ?? "php", Env::get("config.env"), $configPath);
        
        // Register the config provider
        $this->injector->getContainer()->add(IConfigProvider::class, [ "reference" => $this->configProvider ]);

        // Configure the console applications
        $consoleConfig =  $this->configProvider->getConfig("console");

        if ($consoleConfig->has("bin"))
        {
            $apps = $consoleConfig->get("bin");

            if (!empty($apps))
            {
                foreach ($apps as $appName => $appClass)
                    $this->apps[$appName] = $appClass;
            }
        }

        if ($consoleConfig->has("default"))
        {
            $this->defaultApp = $consoleConfig->get("default");
        }
    }

    public function getRootDirectory() : string
    {
        return Env::getRootDirectory();
    }

    public function toLocalPath(string $path) : string
    {
        return Env::toLocalPath($path);
    }

    public function getConfigProvider() : IConfigProvider
    {
        return $this->configProvider;
    }

    public function register(string $appName, string $appClass, bool $default = false)
    {
        $this->apps[$appName] = $appClass;
        
        if ($default)
            $this->defaultApp = $appName;
    }

    public function run() : int
    {
        $appName = null;
        if ($this->argc == 1)
        {
            if (empty($this->defaultApp))
            {
                $apps = \implode("|", \Gekko\Collections\Collection::of($this->apps)->select(function ($appClass, $appName) { return $appName; })->toArray());
                echo "Usage: {$this->argv[0]} ({$apps})";
                return -1;
            }
            $appName = $this->defaultApp;
        }
        else
        {
            $appName = $this->argv[1];
        }
        
        $app = $this->resolve($appName);

        return $this->execute($app);
    }

    public function getArgumentsCount() : int
    {
        return $this->argc;
    }

    public function getArguments() : array
    {
        return $this->argv;
    }

    private function resolve(string $appName) : ICommand
    {
        if (!isset($this->apps[$appName]))
            throw new \Exception("{$appName}: command not found");

        return $this->injector->make($this->apps[$appName]);
    }

    private function execute(ICommand $app) : int 
    {
        $status = -1;
        try
        {
            $app->onInit($this);
            $status = $app->run($this);
        }
        catch (\Exception $ex)
        {
            $code = $ex->getCode();
            $status = \is_int($code) ? $code : PHP_INT_MIN;
        }
        finally
        {
            $app->onFinish($this);
        }
        return $status;
    }
}
