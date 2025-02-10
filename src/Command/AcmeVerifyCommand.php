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
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;


/***
 * Generate new DNS challenges via acme.sh and set _acme-challenge DNS records for the domains provided (including wildcard domains) 
 */
#[AsCommand(
    name: 'acme:verify',
    description: "Verify pending DNS challenges (will generate new certificates ready for upload on success)",
    hidden: false,
)]
class AcmeVerifyCommand extends HostEuropeBaseCommand
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

		$this->addOption('staging', null, InputOption::VALUE_NONE, 'Use staging flag for acme.sh');
		$this->addOption('delay', 'w', InputOption::VALUE_REQUIRED, 'Wait for X seconds before querying DNS records', 30);
				
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{

		$result = parent::execute($input, $output);

		if ($result != Command::SUCCESS) {
			print_r($result);
			return $result;
		}

		if (!file_exists($this->getAcmeShAccountConfigFile())) {
			throw new Exception("acme.sh account is not setup!");
		}

		$result = $this->acmeRenew(intval($input->getOption('delay')), true);

		return $result;

		
		// this method must return an integer number with the "exit status code"
		// of the command. You can also use these constants to make code more readable

		// return this if there was no problem running the command
		// (it's equivalent to returning int(0))
		//return Command::SUCCESS;

		// or return this if some error happened during the execution
		// (it's equivalent to returning int(1))
		// return Command::FAILURE;

		// or return this to indicate incorrect command usage; e.g. invalid options
		// or missing arguments (it's equivalent to returning int(2))
		// return Command::INVALID
	}


}
