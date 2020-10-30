#!/usr/bin/env php
<?php
declare(strict_types=1);

new class {
    private float $min = -30.0;
    private float $max = -5.0;
    private float $range = 0.0;
    private int $width = 32;
    private int $smooth = 8;
    private string $ffplay_format = 'pulse';
    private string $ffplay_input = '0';
    private array $bars = [];

    private function usage(int $exit_code): void {
        echo "Usage: {$_SERVER['PHP_SELF']} <options>\n\n" .
             "Options:\n" .
             "  -h, --help              Show this help\n" .
             "  -m, --min=<lvl>         Set min level\n" .
             "  -x, --max=<lvl>         Set max level\n" .
             "  -w, --width=<w>         Set display width\n" .
             "  -s, --smooth=<n>        Set smoothing factor\n" .
             "  -f, --ffplay-format=<f> Set ffplay -f flag\n" .
             "  -i, --ffplay-input=<i>  Set ffplay -i flag\n";
        exit($exit_code);
    }

    public function __construct() {
        $opt = getopt('hm:x:w:s:f:i:', [
            'help',
            'min:',
            'max:',
            'width:',
            'smooth:',
            'ffplay-format:',
            'ffplay-input:',
        ]);
        if (isset($opt['help']) || isset($opt['h'])) {
            $this->usage(0);
        }
        $this->min           = (float)($opt['min']   ?? $opt['m'] ?? $this->min);
        $this->max           = (float)($opt['max']   ?? $opt['x'] ?? $this->max);
        $this->width         = (int)($opt['width']   ?? $opt['w'] ?? $this->width);
        $this->smooth        = (int)($opt['smooth']  ?? $opt['s'] ?? $this->smooth);
        $this->ffplay_format = $opt['ffplay-format'] ?? $opt['f'] ?? $this->ffplay_format;
        $this->ffplay_input  = $opt['ffplay-input']  ?? $opt['i'] ?? $this->ffplay_input;
        $this->buildDrawingChars();
        $this->setTermAttributes();
        $this->calcLevelRange();
        $this->run();
    }

    private function run() {
        // Run ffplay command
        $cmd = sprintf('ffplay -f %s -i %s ', $this->ffplay_format, $this->ffplay_input) .
            '-af astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level ' .
            '-volume 0 -nodisp 2>&1';
        $ffplay = popen($cmd, 'r');
        if (!$ffplay) {
            exit(1);
        }
        register_shutdown_function(function() use ($ffplay) {
            pclose($ffplay);
        });

        // Save cursor
        echo "\e7";

        // Read levels
        $levels = [];
        $levels_i = 0;
        while (($line = fgets($ffplay)) !== false) {
            // Match level output
            $m = [];
            if (!preg_match('/(?<=Overall.RMS_level=)[-0-9inf.]+/', $line, $m)) {
                continue;
            }
            $level = $m[0];

            // Clamp level to min/max
            if ($level === '-inf') {
                $level = $this->min;
            }
            $level = max(min((float)$level, $this->max), $this->min);

            // Get level as percentage of min/max
            $level_pct = ($level - $this->min) / $this->range;

            // Store last values in circ buffer
            $levels[$levels_i] = $level_pct;
            $levels_i = ($levels_i + 1) % $this->smooth;

            // Apply smoothing
            $level_smoothed = array_sum($levels) / $this->smooth;

            // Draw levels
            echo "\e8\e[J" . // Restore cursor and clear
                $this->getBarChars($level_smoothed) . // Draw bars
                "|"; // Draw max
        }
    }

    private function getBarChars(float $level): string {
        $res = $this->nbars * $this->width;

        $nthbar = (int)($res * $level);
        $s = '';
        $i = 0;
        while ($nthbar > $this->nbars) {
            $s .= $this->bars[$this->nbars - 1];
            $nthbar -= $this->nbars;
            $i++;
        }
        if ($nthbar < $this->nbars) {
            $s .= $this->bars[$nthbar];
            $i++;
        }
        $i = $this->width - $i;
        while ($i--) {
            $s .= ' ';
        }
        return $s;
    }

    private function setTermAttributes(): void {
        $init_attrs = shell_exec('stty -g');
        register_shutdown_function(function() use ($init_attrs) {
            shell_exec("stty {$init_attrs}");
        });
        shell_exec('stty -echo cbreak');
    }

    private function buildDrawingChars(): void {
        $bars = [' '];
        for ($x = 0x8f; $x >= 0x88; $x--) {
            $bars[] = "\xe2\x96" . chr($x);
        }
        $this->bars = $bars;
        $this->nbars = count($bars);
    }

    private function calcLevelRange(): void {
        $this->range = $this->max - $this->min;
        if ($this->range <= 0) {
            fwrite(STDERR, "Negative level range?!\n");
            $this->usage(1);
        }
    }
};
