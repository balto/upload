<?php
/**
 * Created by JetBrains PhpStorm.
 * User: balazs
 * Date: 9/14/13
 * Time: 12:39 PM
 * To change this template use File | Settings | File Templates.
 */

class ShellCommand
{
    /** Az $outputMode erteke ha a kimenet az stdout. */
    const OUTPUT_STDOUT = 1;
    /** Az $outputMode erteke ha a kimenet valtozo. */
    const OUTPUT_VAR    = 2;
    /** Az $outputMode erteke ha a kimenet file. */
    const OUTPUT_FILE   = 4;

    /** @var string   A futtatando parancs. */
    public $command = '';

    /** @var string   A futas kimenetet egybol STDOUT-ra kuldje. */
    private $outputMode = self::OUTPUT_VAR;

    /**
     * @var string   A log file eleresi utvonala. Csak akkor hasznaljuk, ha az {@link self::$outputMode}
     *               erteke {@link self::OUTPUT_FILE}.
     */
    private $logFile = '';

    /**
     * Futtatando program beallitasa.
     *
     * @param string $command   Futtatando program.
     *
     * @return Command
     */
    private function __construct($command)
    {
        $this->command = $command;
    }

    /**
     * Uj parancs kerese.
     *
     * @param string $command   Futtatando program.
     *
     * @return ShellCommand
     */
    public static function create($command)
    {
        return new self($command);
    }

    /**
     * Kimenet mod beallitasa.
     *
     * @param int    $mode      {@link ShellCommand::$mode} beallitasa
     * @param string $logFile   A kimeneti file elerese, ha a kimeneti mod file.
     *
     * @return ShellCommand   Sajat magaval ter viszza a metodus.
     */
    public function setOutputMode($mode, $logFile = '')
    {
        $this->outputMode = $mode;
        if ($this->outputMode & self::OUTPUT_FILE) {
            $this->logFile = $logFile;
        }
        return $this;
    }

    /**
     * Kimenet mod hozzaadasa.
     *
     * @param int    $mode      {@link ShellCommand::$mode} beallitasa
     * @param string $logFile   A kimeneti file elerese, ha a kimeneti mod file.
     *
     * @return ShellCommand
     */
    public function addOutputMode($mode, $logFile = '')
    {
        $this->outputMode |= $mode;
        if ($mode === self::OUTPUT_FILE) {
            $this->logFile = $logFile;
        }
        return $this;
    }

    /**
     * Uj parameter hozzaadasa a parancsunkhoz.
     *
     * @param string|null $option   Kapcsolo.
     * @param string|null $value    Ertek.
     *
     * @return ShellCommand
     */
    public function addParam($option, $value = null)
    {
        $this->command .= (isset($option) ? ' ' . $option : '')
            . (isset($value) ? ' ' . escapeshellarg($value) : '');
        return $this;
    }

    /**
     * Parancs futtatasa.
     *
     * @return Output   A futas kimenetele.
     */
    public function run()
    {
        $descriptorSpec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w')
        );

        $pipes = array();

        $originalCommand = $this->command;

        if ($this->outputMode & self::OUTPUT_FILE && $this->logFile) {
            // Az STDERR-t atiranyitjuk az STDOUT-ra, es azt begyujtjuk a megadott logFile-ba.
            $this->command .= ' 2>&1 | head -c 4000000 | tee -a ' . $this->logFile;
            file_put_contents(
                $this->logFile,
                PHP_EOL . str_repeat('*', 40) . PHP_EOL . 'Original command: ' . $originalCommand . PHP_EOL . PHP_EOL,
                FILE_APPEND);
        }

        $process = proc_open($this->command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';

        while (true) {
            $read = array();

            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }

            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if (!$read) {
                break;
            }

            $write = null;
            $ex = null;

            $ready = stream_select($read, $write, $ex, 2);

            if ($ready === false) {
                break;
            }

            foreach ($read as $r) {
                $s = fread($r, 1024);
                if ($this->outputMode & self::OUTPUT_VAR) {
                    $output .= $s;
                }
                if ($this->outputMode & self::OUTPUT_STDOUT) {
                    echo $s;
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_get_status($process);
        $exitCode = proc_close($process);

        $code = ($status['running'] ? $exitCode : $status['exitcode'] );

        return new Output($originalCommand, $output, $code);
    }
}

/**
 * Process futtatasanak eredmenye.
 *
 * @package Converter
 * @subpackage CLI
 */
class Output
{
    /** @var string       A futtatott parancs. */
    public $command = '';
    /** @var string       Program futasanak STDOUT eredmenye. */
    public $output = '';
    /** @var string|int   Program kilepo statusz kodja. */
    public $code = '';

    /**
     * Konstruktor.
     *
     * @param string $command   A futtatott parancs.
     * @param string $output    Program futasanak STDOUT eredmenye.
     * @param string $code      Program kilepo statusz kodja.
     */
    public function __construct($command = '', $output = '', $code = '')
    {
        $this->command = $command;
        $this->output  = $output;
        $this->code    = $code;
    }
}
