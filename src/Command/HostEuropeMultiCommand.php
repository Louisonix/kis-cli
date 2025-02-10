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
use Symfony\Component\Console\Input\InputOption;


/**
 * 
 * DEPRECATED 
 * 
 * A tool utilizing PHP panther to automate interaction with the HostEurope HTML Web-Interface.
 *
 * This command allows you to perform DNS record operations and cert upload to the HostEurope service.
 * 
 * The command supports the following actions:
 * 
 * - list-contracts: List all contracts and their associated domains.
 * - list-webpacks: List all webpacks and their associated domains.
 * - list-dns-records: List all DNS records for a specific domain.
 * - delete-dns-record: Delete a specific DNS record by pattern.
 * - upload-cert: Upload a certificate to a specific SSL endpoint.
 * 
 * @package Mono\KisCLI\Command
 * @author Ole Loots
 * @version 1.0
 * @deprecated
 */
#[AsCommand(
	name: 'kis:multi',
	description: 'Edit DNS entries via Host Europe Login-Area (KIS).',
	hidden: false,
)]
class HostEuropeMultiCommand extends HostEuropeBaseCommand
{

	
 
	public function __construct(protected LoggerInterface $logger)
	{
		parent::__construct($logger);
	}

	protected function configure(): void
	{
		parent::configure();

		// TODO: die ganzen optionen als einzelne Commands -> für die Relevanten Befehle umgesetzt 
		$this->addOption('list-dns-records', 'l', InputOption::VALUE_NONE, 'List the DNS records configured in KIS');
		$this->addOption('list-contracts', null, InputOption::VALUE_NONE, 'List contracs (and coresponding domains)');
		$this->addOption('list-webpacks', null, InputOption::VALUE_NONE, 'List webpacks (+ associated domains)');
		$this->addOption('list-endpoints', null, InputOption::VALUE_NONE, 'List vhosts');
		$this->addOption('list-challenge-dns-records', null, InputOption::VALUE_NONE, 'List acme challenge DNS records (DNS cache involved, invokes nslookup)');
		$this->addOption('confirm-pending-challenges', null, InputOption::VALUE_NONE, 'List pending acme challenges and check if actual DNS record value matches');
		$this->addOption('delete-acme-challenge-dns-records', null, InputOption::VALUE_REQUIRED, 'Delete a specific DNS record (by pattern).\nExample: -r "TXT/_acme-challenge.my-domain.de/VALUE", multiple patterns can be specified, seperated by comma.');
		$this->addOption('delete-dns-record', 'r', InputOption::VALUE_REQUIRED, 'Delete a specific DNS record (by pattern).\nExample: -r "TXT/_acme-challenge.my-domain.de/VALUE", multiple patterns can be specified, seperated by comma.');
		$this->addOption('add-dns-record', 'a', InputOption::VALUE_REQUIRED, 'Add DNS record .\nExample: -a "TXT/HOST/VALUE,TXT/HOST/VALUE2"');
		
		$this->addOption('acme-issue', null, InputOption::VALUE_NONE, 'Request DNS Challenge Records for the domains provied by --domains (specify wildcard domains as required)');
		$this->addOption('acme-set-dns-records', null, InputOption::VALUE_NONE, 'Setup DNS Records (requires successfull acme-issue)');
		$this->addOption('acme-renew', null, InputOption::VALUE_NONE, 'Renew certificates with acme.sh');
		$this->addOption('upload', 'u', InputOption::VALUE_NONE, 'Upload generated/existing certifitcates');
		$this->addOption('default', null, InputOption::VALUE_NONE, 'Upload certificates to default (global) vhost');
		
		$this->addOption('staging', null, InputOption::VALUE_NONE, 'use staging flag for acme.sh');
		$this->addOption('contract', null, InputOption::VALUE_REQUIRED, 'Specify the contract id which is used for cert upload');
		$this->addOption('webpack-id', null, InputOption::VALUE_REQUIRED, 'Specify the contract id which is used for cert upload');
		$this->addOption('vhosts', null, InputOption::VALUE_OPTIONAL, 'Specify vhosts ids used for cert upload by comma separated list, example: default,1234,1235. Default: * (any vhost found)', '*');
	}



	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$result = parent::execute($input, $output);

		if ($result != Command::SUCCESS) {
			print_r($result);
			return $result;
		}

		try {

			

			if ($input->getOption('domains')) {
				$domains = explode(',', $input->getOption('domains'));
			} else {
				// subfunctions must make sure that $domains is set before running actions
				// TODO: how to ensure that? Maybe read domains at this early point? 
				$domains = null;
			}

			if ($input->getOption('upload')) {

				if ($input->getOption('contract') == false) {
					$output->writeln("Error: contract id is required for cert upload.");
					return Command::FAILURE;
				}

				if ($input->getOption('vhosts') == false) {
					$output->writeln("Error: vhosts ids required for cert upload.");
					return Command::FAILURE;
				}
			}

			if ($input->getOption('acme-renew')) {

				if ($input->getOption('domains') == false) {
					$output->writeln("Error: domains must be specified.");
					return Command::FAILURE;
				}

				$result = $this->acmeRenew(5);
			}

			else if ($input->getOption('acme-issue')) {
				if ($input->getOption('domains') == false) {
					// hier alle Domains aus KIS auslesen
					$domains = $this->fetchDomainsFromKis();
				}
				return $this->acmeIssue($domains);
			}

			else if ($input->getOption('acme-set-dns-records')) {
				$result = $this->acmeChallengeSetDNSRecords();
			}
			

			else if ($input->getOption('upload')) {

				// TODO: in eigene Funktion auslagern
				// TODO: auch manuelles angeben des keys/certs ermöglichen 
				$renewResult = explode("\n", file_get_contents($this->getAcmeShFolder() . 'acme.sh.log'));
				$renewResult = $this->parseAcmeRenewOutput($input, $output, $renewResult);

				if (isset($renewResult['fullchain']) && isset($renewResult['key'])) {

					$output->writeln("Certficates available");
					$output->writeln(print_r($renewResult, true), OutputInterface::VERBOSITY_NORMAL);

					if ($input->getOption('staging') == false) {
						$output->writeln('Uploading Certificate ...');

						// loop: schauen, für welche domains ein vhost existiert
						// upload für match -> vhost id merken
						// solange, bis alle domains durch sind
						// upload aber nur durchführen, wenn vhost-id noch nicht in der liste ist

						$inVhostsAsArray = explode(",", $input->getOption('vhosts'));
						$this->initKisSession($output, $input);
						$endpointsTab = $this->listSSLEndpoints($input, $output, $input->getOption('webpack-id')); 
						$vhostsDone = [];
						foreach ($endpointsTab as $ep) {
							if (count($ep['domains']) == 1 && $ep['domains'][0] == '- keine Domains zugeordnet -') {
								continue;
							}
							if (!empty($ep['vid']) && $ep['vid'] == 'default') {
								if (true == $input->getOption('default') || in_array('*', $inVhostsAsArray)) {
									$output->writeln("Uploading cert to default vhost ...");
									$tmpResult = $this->uploadCert($input, $output, $ep, $renewResult['fullchain'], $renewResult['key']);
									if (strpos($tmpResult, 'Die Dateien wurden erfolgreich hochgeladen.') === false) {
										$output->writeln("Unexpected upload result! Expecting upload failure!", OutputInterface::VERBOSITY_NORMAL);
									} else {
										$output->writeln("Upload success. Waiting for redirect...");
										// wait until redirect happens
										$this->getClient()->replayJSON([
											"steps" => [
												[
													"type" => "customStep",
													"name" => "waitForUrl",
													"url" => "https://kis.hosteurope.de/administration/webhosting/",
													"timeout" => 30
												]
											]
										]);
										$output->writeln("done");
										$vhostsDone[] = $ep['vid'];
									}
									
									//print_r($tmpResult);
									// TODO: check upload success 
									
								}
							}
							if(!empty($ep['vid']) && intval($ep['vid']) > 0) {
								// TODO: populate domains array when not passed as commandline option
								foreach ($domains as $domain) {
									if(in_array($domain, $ep['domains']) && !in_array($ep['vid'], $vhostsDone)) {
										$output->writeln("Uploading cert to vhost " . $ep['vid'] . " ... ");
										$tmpResult = $this->uploadCert($input, $output, $ep, $renewResult['fullchain'], $renewResult['key']);
										if (strpos($tmpResult, 'Die Dateien wurden erfolgreich hochgeladen.') === false) {
											$output->writeln("Unexpected upload result! Expecting upload failure!", OutputInterface::VERBOSITY_NORMAL);											
										} else {
											$output->writeln("Upload success. Waiting for redirect...");
											// wait until redirect happens
											$this->getClient()->replayJSON([
												"steps" => [
													[
														"type" => "customStep",
														"name" => "waitForUrl",
														"url" => "https://kis.hosteurope.de/administration/webhosting/",
														"timeout" => 30
													]
												]
											]);
											$output->writeln("done");
											$vhostsDone[] = $ep['vid'];
										}										
										// TODO: check upload success 
									}
								}
							}
						}					
					}
				}
			}

			// First, exectute non domains based commands... 
			else if ($input->getOption('list-contracts')) {
				$this->initKisSession($output, $input);

				$contracts = $this->listContracts($input, $output);

				$this->printTable($contracts, 'Contracts:');
			}

			else if ($input->getOption('list-endpoints')) {
				$this->initKisSession($output, $input);
				$contractIds = [];
				if($input->getOption('contract')) {
					$contractIds[] = $input->getOption('contract');
				} else {
					$contracts = $this->listWebpacks($input, $output);
					foreach ($contracts as $contract) {
						$contractIds[] = $contract['contract_id'];
					}
				}

				foreach ($contractIds as $cid) {
					$endpoints = $this->listSSLEndpoints($input, $output, $cid);
					$this->printTable($endpoints, 'Endpoints for webpack ' . $cid . ':');
				}
			}
			else if ($input->getOption('list-webpacks')) {
				$this->initKisSession($output, $input);
				$contracts = $this->listWebpacks($input, $output);

				$this->printTable($contracts, 'Webpacks:');
			} 
			else if ($input->getOption('list-challenge-dns-records')) {
				if ($domains == null) {
					$this->initKisSession();
					$domains = $this->fetchDomainsFromKIS();
				} 
				$dnsRecords = $this->queryAcmeChallengeDNSRecords($domains);
				$this->printTable($dnsRecords, 'ACME Challenge DNS Records:');
			}			
			else if ($input->getOption('confirm-pending-challenges')) {
				$pendingChallenges = $this->getPendingChallenges();
				$dnsRecords = $this->queryAcmeChallengeDNSRecords($pendingChallenges['domains']);

				if ($pendingChallenges && is_array($pendingChallenges['issues']) && count($pendingChallenges['issues']) > 0) {
					$this->verifyDNSChallengeRecords($pendingChallenges['domains'], $pendingChallenges['issues'], true);
					$this->printTable($pendingChallenges['issues'], 'Checked Challenges:');
				}
			}
			else {
				
				/* 
					This else tree is used for all the remaining commands,
				   	which make use of the domain input option. It calls the commands for each entry 
				   	in the domain array. If the domains array is not supllied by the user, it is autocompleted by
				   	the values provided by the KIS backend.
				*/

				if ($input->getOption('domains') == false) {
					// TODO: hier alle Domains auslesen
					$this->initKisSession($output, $input);
					$webpacks = $this->listWebpacks($input, $output);
					$domains = [];
					foreach ($webpacks as $webpack) {
						if (is_array($webpack['domains'])) {
							foreach ($webpack['domains'] as $domain) {
								$domains[] = $domain;
							}
						}
					}
				}


				if ($input->getOption('delete-acme-challenge-dns-records')) {
					$this->deleteACMEChallengeDNSRecords($domains);
				}

				foreach ($domains as $domain) {

					if ($input->getOption('delete-dns-record')) {
						$this->initKisSession($output, $input);
						// TODO: mit sternchen funktioniert noch nicht, liefert immer failed zurück. 
						if(strpos($input->getOption('delete-dns-record'), $domain) !== false || strpos($input->getOption('delete-dns-record'), '*') !== false) {
							$dnsTab = [];
							$this->deleteDnsRecord($input, $output, $domain, $input->getOption('delete-dns-record'), $dnsTab);
						}					
					}

					if ($input->getOption('add-dns-record')) {
						$this->initKisSession($output, $input);
						$this->addDnsRecord($input, $output, $domain, $input->getOption('add-dns-record'));
					}

					if ($input->getOption('list-dns-records')) {
						$this->initKisSession($output, $input);
						$dnsTab = $this->listDnsRecords($input, $output, $domain);
						// TODO: hier gibt es noch einen bug, spalten sind verrutscht. 
						$this->printDnsRecordTable($input, $output, $domain, $dnsTab);
						//$this->printTable($dnsTab, 'DNS Records for '.$domain.':', true, 50);
					}
				}
			}
		} catch (Exception $ex) {
			print_r($ex);
		} finally {
			if (isset($this->client)) {
				$this->getClient()->closeSession();
			}
		}

		// this method must return an integer number with the "exit status code"
		// of the command. You can also use these constants to make code more readable

		// return this if there was no problem running the command
		// (it's equivalent to returning int(0))
		return $result;

		// or return this if some error happened during the execution
		// (it's equivalent to returning int(1))
		// return Command::FAILURE;

		// or return this to indicate incorrect command usage; e.g. invalid options
		// or missing arguments (it's equivalent to returning int(2))
		// return Command::INVALID
	}

}
