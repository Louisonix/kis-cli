<?php
/**
 * This file is part of the kis-cli package.
 *
 * (c) Ole Loots <ole@monochrom.net>
 *
 * For the full license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mono\KisCLI\Command;

use Exception;
use Mono\KisCLI\CommandHostEuropeBaseCommand;
use Facebook\WebDriver\Exception\WebDriverException;
use Mono\KisCLI\Interface\IBrowserReplayEnvironment;
use Mono\KisCLI\BrowserReplayEnvironment;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'replay',
    description: 'Replay a JSON file with panther.',
    hidden: false,
)]
class ReplayJSONCommand extends HostEuropeBaseCommand
{

	protected IBrowserReplayEnvironment $client;

	public function __construct(protected LoggerInterface $logger)
	{
		parent::__construct($logger);
	}

	protected function configure(): void
	{
		parent::configure();

		//$this->setName($this->getDefaultName());
		
		//echo "name: " . $this->getName() . "\n";

		$this->setDescription('Replay a JSON file with panther.');
		
		// TODO: add options for kt nr, pin, etc
		$this->addArgument('file', InputArgument::OPTIONAL, 'JSON file to replay');
		$this->addOption('url-context', 'uc', InputOption::VALUE_REQUIRED, 'URL context (optional, required by some scripts)');
		$this->addOption('list', 'l', InputOption::VALUE_NONE, 'List available scripts');
		
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{

		$result = parent::execute($input, $output);

		if ($result != Command::SUCCESS) {
			print_r($result);
			return $result;
		}

		if ($input->getArgument('file')) {
			$basedir = dirname(realpath($input->getArgument('file')));
			$script = basename(realpath($input->getArgument('file')));
			$params = [];

			if ($input->hasOption('url-context')) {
				$urlContext = $input->getOption('url-context');
			} else {
				$urlContext = null;
			}
			try {
				$this->initPantherSession($input, $output, $basedir, null, $urlContext);
				$data = $this->getClient()->replayJSONFile($script, $params);
				if($data) {
					echo json_encode($data, JSON_PRETTY_PRINT);
				}
			} finally {
				$this->getClient()->closeSession();
			}
		} 
		else if ($input->getOption('list')) {
			$basedir = $this->getRootDir() . "/examples";
			$this->output->writeln("List of available scripts:");
			$files = scandir($basedir);
			foreach ($files as $file) {
				if (is_dir($basedir.'/'.$file) && $file != '.' && $file != '..') {	
					$this->output->writeln($file);
				}
			}
		} else {
			$this->output->writeln("No file specified.");
		}
		// this method must return an integer number with the "exit status code"
		// of the command. You can also use these constants to make code more readable

		// return this if there was no problem running the command
		// (it's equivalent to returning int(0))
		return Command::SUCCESS;

		// or return this if some error happened during the execution
		// (it's equivalent to returning int(1))
		// return Command::FAILURE;

		// or return this to indicate incorrect command usage; e.g. invalid options
		// or missing arguments (it's equivalent to returning int(2))
		// return Command::INVALID
	}

	protected function testFunction(InputInterface $input, OutputInterface $output)
	{

	}


}
