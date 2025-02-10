<?php
/**
 * This file is part of the kis-cli package.
 *
 * (c) Ole Loots <ole@monochrom.net>
 *
 * For the full license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace Mono\KisCLI\Utils;

use Symfony\Component\Console\Output\OutputInterface;

class Command {

    public static function exec(string $cmd, array $args = [], array &$output, string $pwd = null, OutputInterface $outputInterface = null) {
		
		$exitcode = 0;
		$olddir = null;
		$cmd .= " ";

		$i = 0;
		foreach ($args as $arg) {
			$cmd .= escapeshellarg($arg);
			$cmd .= " ";
			$i++;
		}

        if ($outputInterface) {
            $outputInterface->writeln("executing: $cmd", OutputInterface::VERBOSITY_VERBOSE);
        }

		if($pwd != null) {
			$olddir = getcwd();
			chdir($pwd);
		}	

		exec($cmd, $output, $exitcode);

		if ($olddir != null) {
			chdir($olddir);
		}

		return $exitcode;
	}

	

}