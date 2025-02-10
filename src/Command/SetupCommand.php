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

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/***
 * Upload TLS certs to Hosteurope 
 */
#[AsCommand(
    name: 'setup',
    description: "Ask a few questions (username, password, acme.sh script location) and write it to the config file",
    hidden: false,
)]
class SetupCommand extends HostEuropeBaseCommand
{	
	/**
	 * 
	 * @param LoggerInterface $logger 
	 * @return void 
	 * @throws InvalidArgumentException 
	 */
	public function __construct(protected LoggerInterface $logger)
	{
		parent::__construct($logger);
	}

	protected function configure(): void
	{
		parent::configure();

	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$result = Command::SUCCESS;

		// HINT: we do not execute parent::execute, because that will try to read the config file, which probably doesn't exist
		// $result = parent::execute($input, $output);
	
		$this->input = $input;
		$this->output = $output;

		$helper = $this->getHelper('question');
		if (file_exists($this->getConfigFile())) {
			$question = new ConfirmationQuestion('<question>Config file already exists, continue? [yes/NO]</question> ', false);
			$answer = $helper->ask($input, $output, $question);
			if ($answer == false) {
				return Command::SUCCESS;
			}
		}

		/**
		 * Example Config: 
		 * 
		 * {
		 *	"username": "some-user",
		 *	"password": "some-password",
		 *	"acme_script": "/home/username/.acme.sh/acme.sh"
		 * }
		 * 
		 */

		$config = [];
		
		$question = new Question('<question>Hosteurope/KIS username:</question> ', null);
		$config['username'] = $helper->ask($input, $output, $question);

		$question = new Question('<question>Hosteurope/KIS password:</question> ', null);
		$config['password'] = $helper->ask($input, $output, $question);

		$question = new Question('<question>acme.sh script location (full path!):</question> ', getenv("HOME")."/.acme.sh/acme.sh");
		$config['acme_script'] = $helper->ask($input, $output, $question);
		
		if (!file_exists($config['acme_script'])) {
			$output->writeln('<error>Invalid acme.sh script location!</error>');
		}

		$jsonConfig = json_encode($config, JSON_PRETTY_PRINT);

		$bytes = file_put_contents($this->getConfigFile(), $jsonConfig);
		if ($bytes !== false) {
			$output->writeln("<info>Written ".$bytes." bytes to config file: ".$this->getConfigFile()."</info>");
		} else {
			$output->writeln('<error>Error writing config file ('.$this->getConfigFile().')!</error>');
			$result = Command::FAILURE;
		}
		
		return $result;

	}


}
