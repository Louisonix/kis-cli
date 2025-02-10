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
 * Upload TLS certs to Hosteurope 
 */
#[AsCommand(
    name: 'kis:add-dns-record',
    description: "Add DNS records to domains",
    hidden: false,
)]
class AddDNSRecordCommand extends HostEuropeBaseCommand
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

		$this->addOption('add-dns-record', 'a', InputOption::VALUE_REQUIRED, 'Add DNS record .\nExample: -a "TXT/HOST/VALUE,TXT/HOST/VALUE2"');

	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{

		$result = parent::execute($input, $output);

		if ($result != Command::SUCCESS) {
			print_r($result);
			return $result;
		}

		if (empty($input->getOption('domains'))) {
			$output->writeln("You must specify at least one domain!");
			return Command::INVALID;
		}

		$domains = explode(",", $input->getOption('domains'));

		foreach ($domains as $domain) {
			try {
				$this->addDnsRecord($input, $output, $domain, $input->getOption('add-dns-record'));
			} catch(Exception $ex) {
				$result = Command::FAILURE;
				$output->writeln($ex);
			}
		}		
		
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
