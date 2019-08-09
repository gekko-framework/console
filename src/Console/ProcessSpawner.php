<?php
/*
 * (c) Leonardo Brugnara
 *
 * Full copyright and license information in LICENSE file.
 */

namespace Gekko\Console;

trait ProcessSpawner
{
    /**
     * Returns true of the OS is a Windows-based OS
     *
     * @return boolean
     */
    public function isWindowsOs() : bool
    {
        return strcasecmp(substr(PHP_OS, 0, 3), 'WIN') == 0;
    }

    /**
     * Returns true of the OS is a Linux-based OS
     *
     * @return boolean
     */
    public function isLinuxOs() : bool
    {
        return strcasecmp(substr(PHP_OS, 0, 5), 'LINUX') == 0;
    }

    /**
     * Returns the path to the temporal directory for an specific UID
     *
     * @param ConsoleContext $ctx Console's context
     * @param string $uid Identifier of the process to be spawned
     * @return string Path of the temporal directory
     */
    public function getTempDir(ConsoleContext $ctx, string $uid) : string
    {
        return $ctx->toLocalPath("/.tmp/{$uid}");        
    }

    /**
     * Returns the path of the file that will contain the process ID
     *
     * @param ConsoleContext $ctx Console's context
     * @param string $uid Identifier of the process
     * @return string Path to the file
     */
    public function getPidFile(ConsoleContext $ctx, string $uid) : string
    {
        return $ctx->toLocalPath("/.tmp/{$uid}/{$uid}.pid");
    }

    /**
     * Returns the process' ID in the file *pid_file* if it exists
     * and is valid
     *
     * @param string $pid_file Path to the file containing the process ID
     * @return integer Process ID or -1 if the file does not exist or the content is not a number
     */
    public function getPid(string $pid_file) : int
    {
        if (!\file_exists($pid_file))
            return -1;

        $pid = \file_get_contents($pid_file);

        if (empty($pid))
            return -1;

        $pid = \str_replace("\r\n", "", $pid);
        $pid = \str_replace("\n", "", $pid);

        if (!\is_numeric($pid))
            return -1;

        return \intval($pid);
    }

    /**
     * Spawns a new process and keeps track of its PID
     *
     * @param ConsoleContext $ctx Console's context
     * @param string $uid Unique ID to identify the process to be spawned
     * @param string $executable Name of the process' executable file
     * @param string $args Arguments to be passed to the executable
     * @return integer A valid PID on success or a negative number on error
     */
    public function spawn(ConsoleContext $ctx, string $uid, string $executable, string $args) : int
    {
        // Temp folder for the process to be spawned
        $tmp_dir = $this->getTempDir($ctx, $uid);

        if (!\file_exists($tmp_dir))
            mkdir($tmp_dir, 0777, true);

        // PID file to store porcess' id
        $pid_file = $this->getPidFile($ctx, $uid);
        $pid = $this->getPid($pid_file);

        // Check if process is already running
        if ($pid > 0 && $this->isAlive($executable, $pid))
        {
            \fwrite(STDOUT, "{$uid} is already running (PID " . $pid .  ")\n");
            return -1;
        }

        if ($this->isWindowsOs())
        {
            pclose(popen("start \"{$uid}\" /MIN {$executable} {$args}", "r"));

            $fd = popen("tasklist /fi \"WindowTitle eq {$uid}\" /fo CSV /nh", "r");
            $output = stream_get_contents($fd);
            pclose($fd);

            $current_pids = [];
            $lines = explode("\n", $output);

            foreach ($lines as $line)
            {
                $columns = explode("\",\"", $line);
                
                if (isset($columns[1]))
                {
                    $current_pids[] = $columns[1];
                }
            }

            if (count($current_pids) != 1)
                return -1;

            $pid = \intval(array_pop($current_pids));

            if ($pid <= 0)
                return -1;

            \file_put_contents($pid_file, $pid);
        }
        else if ($this->isLinuxOs())
        {
            pclose(popen("sh -c 'echo $$ > {$pid_file}; exec {$executable} {$args}' &", "r"));
        }
        
        $pid = -1;
        $tries = 0;
        while ($tries++ < 10)
        {
            $pid = $this->getPid($pid_file);

            if ($pid > 0)
                break;
            
            \usleep(500000);
        }

        if ($tries >= 10)
            fwrite(STDERR, "Couldn't find the process PID, the PID file does not exist\n");

        if ($pid === -1 || !$this->isAlive($executable, $pid))
            return -1;

        return $pid;
    }

    /**
     * Returns true if the process with ID equals to PID exists and
     * it is an instance of the provided executable
     *
     * @param string $executable Process executable
     * @param integer $pid Process ID
     * @return boolean true if the process is running, false otherwise
     */
    public function isAlive(string $executable, int $pid) : bool
    {
        if ($this->isWindowsOs())
        {
            $output = \shell_exec("tasklist /fi \"PID eq $pid\" /FO CSV /nh");

            if (empty($output) || \strpos($output, "INFO: No tasks") !== false)
                return false;

            $processes = explode('\r\n', $output);
            foreach ($processes as $process) {
                $parts = explode(',', $process);
                if (\strpos($parts[0], $executable) === false)
                    return false;                
            }

            return true;
        }
        else
        {
            $pid_output = \shell_exec("ps -p {$pid} -o pid=");

            $pid_output = \trim($pid_output);
            $pid_output = \str_replace("\n", "", $pid_output);
            
            if (empty($pid_output) || \strpos($pid_output, \strval($pid)) === false)
                return false;
            
            $cmd_output = \shell_exec("ps -p {$pid} -o cmd=");
            
            if (\strpos($cmd_output, $executable) === false)
                return false;

            return true;
        }

        return false;
    }

    /**
     * If the process identified by its unique ID exists and is alive
     * this method kills the process.
     *
     * @param ConsoleContext $ctx Console's context
     * @param string $uid Process unique id
     * @return boolean true if the process is killed (or not running), false otherwise
     */
    public function kill(ConsoleContext $ctx, string $uid) : bool
    {
        $pid_file = $this->getPidFile($ctx, $uid);
        $pid = $this->getPid($pid_file);

        \unlink($pid_file);

        if ($pid <= 0)
            return true;

        if ($this->isWindowsOs())
        {
            pclose(popen("taskkill /F /pid {$pid}", "r"));
        }
        else if ($this->isLinuxOs())
        {
            pclose(popen("kill -9 {$pid}", "r"));
        }
        
        return true;
    }
}
