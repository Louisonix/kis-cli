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

use DivisionByZeroError;
use ArithmeticError;

use Exception;
use stdClass;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Mono\KisCLI\Utils\Xml;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Mono\KisCLI\Interface\IBrowserReplayEnvironment;
use Mono\KisCLI\BrowserReplayEnvironment;
use Mono\KisCLI\Utils\Command as UtilsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;

/**
 * Base Class for all the commands that want to interact with KIS (hosteurope web interface)
 * @package Mono\KisCLI\Command
 */
#[AsCommand(
    name: 'base-command',
    description: 'none',
    hidden: true
)]
class HostEuropeBaseCommand extends Command
{

	protected mixed $config = null;
	protected bool $fakeExec = false;
	protected InputInterface $input;
	protected OutputInterface $output;
	protected IBrowserReplayEnvironment $client;

	/**
	 * Indicates wheter Login into KIS was successfull.\
	 * A value of true also indicates that the underlying panther session was created successfully. 
	 * @var bool
	 */
	protected bool $kisLoginSuccess = false;

	public const RECORD_TYPE_TO_VALUE_MAP = [
		'A' => '0',
		'AAAA' => '28',
		'CNAME' => '10',
		'TXT' => '11',
		'NS-Delegate' => '127',
	];


	public function __construct(protected LoggerInterface $logger)
	{
		parent::__construct($this->getDefaultName());
	}

	protected function configure(): void
	{
		$this->addOption('domains', 'd', InputOption::VALUE_REQUIRED, 'Specify domains by comma separated list');
		$this->addOption('screenshots', 's', InputOption::VALUE_NONE, 'Take screenshots of each json step');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$this->input = $input;
		$this->output = $output;

		$config = $this->loadConfig();

		return Command::SUCCESS;
	}


	/** @return string  */
	protected function getRootDir() : string
	{
		// alternative way to get current file 
		/*
			$file = (new \ReflectionObject($this))->getFileName();
			$kernelLoader = $loader->getResolver()->resolve($file, 'php');
			$kernelLoader->setCurrentDir(\dirname($file));
		*/

		$absPath = __DIR__ . "/../../";
		$absPath = realpath($absPath);
		//echo "please double this path (realpath): $absPath\n";
		return $absPath;
	}

	/** @return string  */
	protected function getTmpDir() : string {

		$rootDir = $this->getRootDir();

		$tmpDir = $rootDir . '/var/tmp/';

		if(!file_exists($tmpDir)) {
			mkdir($tmpDir);
		}

		return $tmpDir;
	}

	protected function locateFile(string $path) : string
	{

		$result = null;

		if (stripos($path, "/") == 0) {
			// absolute path   
			$result = $path;
		} else {
			$result = $this->getRootDir() . $path;
		}

		return $result;
	}

	protected function writeln($message, $options = OutputInterface::VERBOSITY_NORMAL)
	{
		$this->output->writeln($message, $options);
	}

	protected function exec(string $cmd, array $args = [], array &$output, string $pwd = null) {
		
		return UtilsCommand::exec($cmd, $args, $output, $pwd, $this->output);
	}

	/**
	 * 
	 * @param OutputInterface $output 
	 * @param array $tab 
	 * @param null|string $title 
	 * @param bool $withPadding 
	 * @param int $maxColWidth maximum number of character per column  
	 * @return void 
	 * @throws InvalidArgumentException 
	 * @throws DivisionByZeroError 
	 * @throws ArithmeticError 
	 */
	protected function printTable(array $tab, ?string $title, $withPadding = true, $maxColWidth = -1)
	{
		$output = $this->output;

		$keys = [];
		foreach ($tab as &$row) {
			foreach ($row as $key => &$value) {
				// add key to headers, if not present: 
				if (!in_array($key, $keys)) {
					if($maxColWidth > 0) {
						$keys[] = substr($key, 0, $maxColWidth);
					} else {
						$keys[] = $key;
					}
					
				}
				
				if(is_array($value)) {
					$value = implode("\n", $value);
				}

				if($maxColWidth > 0) {
					// wrap long lines: 
					if (strlen($value) > $maxColWidth) {
						$value = wordwrap($value, $maxColWidth, "\n", true);
					}
				}
			}
		}

		$table = new Table($output);
		$table->setHeaders($keys);
		
		// for some reason, the product column is always empty when rendered,
		// probably because the array holds refrences. Cloning the array fixes the problem. 
		$tab = unserialize(serialize($tab));
		$table->setRows($tab);

		if ($withPadding) {
			$output->writeln("");
		}

		if ($title) {
			$output->writeln($title);
		}

		$table->render();

		if ($withPadding) {
			$output->writeln("");
		}

	}

	/**
	 * 
	 * @param InputInterface $input 
	 * @param OutputInterface $output 
	 * @param string $basedir 
	 * @param null|string $initScript 
	 * @param null|array $params 
	 * @return void 
	 * @throws InvalidArgumentException 
	 */
	protected function initPantherSession(InputInterface $input, OutputInterface $output, string $basedir, ?string $initScript = null, ?array $params = null, array $ioCallbacks) : ?string {
		
		$initScriptResult = "";
		$urlContext = null;

		if ($params == null || !is_array($params)) {
			$params = [];
		}
		
		$configfile = $basedir . '/config.json';

		if (file_exists($configfile)) {
			$config = json_decode(file_get_contents($configfile), true);
			$params['config'] = $config;
		}

		//$this->client = $this->BrowserReplayEnvironmentFactory->createBrowserReplayEnvironment($params, $basedir, $urlContext, null);
		$this->client = new BrowserReplayEnvironment($params, $basedir, $this->logger, $urlContext, null, $ioCallbacks);
		$this->getClient(false)->setEnableScreenshots((bool)$input->getOption('screenshots'));


		if ($initScript) {
			$initScriptResult = $this->getClient(false)->replayJSONFile($initScript, $params);
		}

		return $initScriptResult;
	}

	protected function getClient(bool $init = true) {

		if ($init == true) {
			$this->initKisSession($this->output, $this->input);
		}

		return $this->client;
	}

	protected function getConfigFile() {
		
		$configfile = './resources/host-europe/config.json';

		return $configfile;
		
	}

	protected function loadConfig() {

		$configfile = './resources/host-europe/config.json';
		$config = json_decode(file_get_contents($configfile), true);

		if ($config === null) {
			throw new \RuntimeException('Failed to parse config.json!');
		}

		$this->config = $config;		

		return $this->config;
	}

	protected function getConfig() : mixed {
		return $this->config;
	}



	/**
	 * Initializes the KIS login session via symfony panther.
	 *
	 * @param OutputInterface $output The output interface.
	 * @param InputInterface $input The input interface.
	 * @param array $config The configuration array.
	 * @return void
	 */
	protected function initKisSession(OutputInterface $output = null, InputInterface $input = null)
	{
		$basedir = dirname(realpath(__DIR__ . '/../../resources/host-europe/login.json.twig'));
		$loginScript = 'login.json.twig';
		$params = [];

		if ($input == null) {
			$input = $this->input;
		}

		if ($output == null) {
			$output = $this->output;
		}

		if ($this->kisLoginSuccess == false) {
			
			// init panther session and execute init (login) script
			$loginResult = self::initPantherSession($input, $output, $basedir, $loginScript, $params, [
				'out' => function($context, $data) {
					$this->output->writeln($context . ' => ' . $data, Output::VERBOSITY_VERBOSE);
				}
			]);

			//  check if login was successful, by storing content of #mainMenu .fl_customerInfo
			//       in result and checking if it contains the username and "angemeldet als"
			if (strpos((string)$loginResult, 'Sie sind angemeldet') !== false) {
				$this->kisLoginSuccess = true;
				
			} else {
			}
		}
	}

	/**
	 * Fetch domains from KIS backend
	 * 
	 * @return mixed 
	 * @throws InvalidArgumentException 
	 */
	protected function fetchDomainsFromKIS() {
		
		$domains = [];

		$this->initKisSession($this->output, $this->input);

		$domainsRaw = $this->getClient()->replayJSONFile('list-domains.json');

		if (!empty($domainsRaw)) {
			$domainsDecoded = json_decode($domainsRaw, JSON_OBJECT_AS_ARRAY);
			foreach ($domainsDecoded as $d) {
				$domains[] = trim($d['domain']);
			}
		}


		return $domains;
	}

	protected function getDomainOptions(){

		$domains = null;

		if ($this->input->getOption('domains') == false) {
			// hier alle Domains aus KIS auslesen
			$domains = $this->fetchDomainsFromKis();
		} 
		else {
			$domains = explode(',', $this->input->getOption('domains'));
		}

		return $domains; 
	}
	


	/**
	 * 
	 * @param array $domains 
	 * @return 0|1 0 on success, 1 on error \
	 * 			0 means all acme challenge DNS records could be deleted for all the supplied domains,\
	 * 			1 means one or more acme-challenge DNS records couldn't be removed. 
	 * @throws InvalidArgumentException 
	 */
	protected function deleteACMEChallengeDNSRecords(array $domains) {

		$output = $this->output;
		$input  = $this->input; 
		$result = Command::SUCCESS;

		$domains = $this->removeWildcardDomains($domains);

		if (count($domains) > 0) {
			$this->initKisSession($output, $input);

			foreach ($domains as $domain) {

			
				$filter = "TXT/_acme-challenge." . $domain;
				$error = $this->deleteDnsRecord($input, $output, $domain, $filter);
				if ($error) {
					$result = Command::FAILURE;
				}
			}
		}

		
		return $result;
	}

	protected function getAcmeShFolder() : string {

		$config = $this->getConfig();

		$acmeFolder = dirname(realpath($config['acme_script']));
		$acmeFolder .= '/';

		return $acmeFolder;
	}

	protected function getAcmeShAccountConfigFile() {

		$folder = $this->getAcmeShFolder();
		$configfile = 'account.conf';

		return $folder.$configfile;
	}

	protected function getAcmeShAccountConfig() {

		$result = null;
		$configfile = $this->getAcmeShAccountConfigFile();

		if (!file_exists($configfile)) {
			throw new Exception("acme.sh config file (".$configfile.") does not exist!");
		}

		$result = file_get_contents($configfile);

		return $result;				
	}

	protected function isWildcardDomain(string $domain) {
		return strpos($domain, '*.') !== false;
	}

	protected function wildcardDomainToRealDomain(string $domain) {
		return substr($domain, 2);
	}

	protected function getDomainValidity(string $domain) {

		if ($this->isWildcardDomain($domain)) {
			$domain = $this->wildcardDomainToRealDomain($domain);
		}

		$output = [];
		$exitcode = $this->exec('sslscan',
			[
				"--no-cipher-details",
				"--no-ciphersuites",
				"--no-compression",
				"--no-fallback",
				"--no-groups",
				"--no-heartbleed",
				"--no-renegotiation",
				"--tls13",
				"--xml=-",
				$domain		  
			],
			$output
		);

		if ($exitcode != 0) {
			throw new Exception("sslscan failed with exit code " . $exitcode);
		}

		$result = XML::xmlToArray(implode("\n", $output));
		
		if(!isset($result['ssltest'])) {
			throw new Exception("Unexpected XML returned by sslscan");
		}
		if(!isset($result['@attributes'])) {
			throw new Exception("Unexpected XML returned by sslscan");
		}
		if($result['@attributes']['title'] != 'SSLScan Results') {
			throw new Exception("Unexpected XML returned by sslscan");
		}

		$result = array_merge($result['ssltest']['certificates']['certificate'], $result['ssltest']['@attributes']);
		$expiresInDays = Carbon::now()->diffInDays(Carbon::parse($result['not-valid-after'][0]));
		$result['expiresInDays'] = $expiresInDays;
		$result['expired'] = $result['expired'][0];

		return $result;
	}

	protected function reduceDomainsToExpireSoon(array $domains)
	{
		$domainsExpireSoon = [];

		$alreadyScanned = [];
		foreach($domains as $domain) {
			
			if ($this->isWildcardDomain($domain)) {
				$chkDomain = $this->wildcardDomainToRealDomain($domain);
			} else {
				$chkDomain = $domain;
			}

			if (!in_array($chkDomain, $alreadyScanned)) {
				$validity = $this->getDomainValidity($domain);
				$alreadyScanned[] = $domain;

				if ($validity['expiresInDays'] <= 30 || $validity['expired'] == 'true') {
					$domainsExpireSoon[] = $domain;
				}
			}
		}

		return $domainsExpireSoon;
	}

	protected function removeWildcardDomains(array $domains)
	{
		$resultDomains = [];

		foreach($domains as $domain) {
			
			if ($this->isWildcardDomain($domain)) {
				$chkDomain = $this->wildcardDomainToRealDomain($domain);
			} else {
				$chkDomain = $domain;
			}

			$resultDomains[] = $chkDomain;
		}

		$resultDomains = array_unique($resultDomains);

		return $resultDomains;
	}

	protected function getPendingChallengesFile() {

		$file = $this->getTmpDir() . "pending-challenges.json";

		return $file;
	}

	/**
	 * Get contents of the challenges (cached to filesystem)
	 * @return mixed 
	 */
	protected function getPendingChallenges() {

		$pendingChallengesFile = $this->getPendingChallengesFile();
		
		if (file_exists($pendingChallengesFile)) {
			$jsonContent = file_get_contents($pendingChallengesFile);
			$result = json_decode($jsonContent, JSON_OBJECT_AS_ARRAY);
			return $result;
		}

		return null;
	}

	protected function getPendingUploadsInfoFile() {

		$file = $this->getTmpDir() . "pending-uploads.json";

		return $file;
	}

	protected function getWebpacksCacheFile() {

		$kernel = $this->getApplication()->getKernel();
        $result = $kernel->getContainer()->getParameter('kernel.cache_dir');
		$result .= '/webpacks.cache.ser';

		return $result;
	}

	/**
	 * Get info about cert ready for upload
	 * @return mixed 
	 */
	protected function getPendingUploadsInfo() {

		$infoFile = $this->getPendingUploadsInfoFile();
		
		if (file_exists($infoFile)) {
			$jsonContent = file_get_contents($infoFile);
			$result = json_decode($jsonContent, JSON_OBJECT_AS_ARRAY);
			return $result;
		}

		return null;
	}

	protected function isValidCertUploadInfo(?array $uploadInfo) {

		$keyfile = false;
		$fullchain = false;

		if ($uploadInfo == null || !is_array($uploadInfo)) {
			return false;
		}

		if (!array_key_exists('key', $uploadInfo)) {
			return false;
		} else {
			$keyfile = $uploadInfo['key'];
		}

		if (!array_key_exists('fullchain', $uploadInfo)) {
			return false;
		} else {
			$fullchain = $uploadInfo['fullchain'];
		}

		if (!file_exists($keyfile)) {
			return false;
		}

		if (!file_exists($fullchain)) {
			return false;
		}

		return true;
	}

	/**
	 * Build shell command to generate acme-challenge values
	 * 
	 * @param InputInterface $input 
	 * @param array $domains 
	 * @return array 
	 * @throws InvalidArgumentException 
	 */
	private function buildAcmeIssueCommand(InputInterface $input, array $domains) {

		// 1.  
		$args = ['--issue'];
		foreach ($domains as $domain) {
			$args[] = '-d';
			$args[] = $domain;
		}
		$args[] = '--dns';
		$args[] = '--yes-I-know-dns-manual-mode-enough-go-ahead-please';
		$args[] = '--force';
		if ($input->getOption('staging')) {
			$args[] = '--staging';
			$args[] = '--debug';
		}

		return $args;
	}

	/**
	 * Generate ACME Challenge values required for generating certificates
	 * 
	 * @param OutputInterface $output 
	 * @param InputInterface $input 
	 * @param array $config 
	 * @param array $domains 
	 * @return 0|1|void 
	 * @throws Exception 
	 * @throws InvalidFormatException 
	 * @throws LogicException 
	 * @throws InvalidArgumentException 
	 */
	protected function acmeIssue(array $domains, bool $continue = false, bool $wipe = true)
	{

		// It seems, --issue automatically switches to --renew, when all challenges are cached / still verified
		
		/**
		 * https://community.letsencrypt.org/t/will-renewal-always-require-new-dns-acme-challenge-txt/102820
		 * 
		 * Will renewal always require new DNS acme-challenge TXT?
		 * 
		 * General answer: Yes. If you want to create a new certificate
		 * (a renewed certificate is a new certificate with the same domain name and the same method),
		 * you have to create a new order -> new random value -> new DNS TXT entry.
		 * Special answer: If you use the same account and the same system (test or productive system), 
		 * valid challenges are cached 30 days. So you don't need a new TXT entry.
		 */


		// Generate challenges:
		$output = $this->output;
		$input = $this->input;
		$config = $this->config;
		$cmdResult = Command::FAILURE;
		$acmeFolder = dirname(realpath($config['acme_script']));
		$acmeScript = realpath($config['acme_script']);
		$answer = true;

		//  check each domain if it is not soon to be expired, if so, remove it from the list:		
		$chkDomains = $this->reduceDomainsToExpireSoon($domains);

		if (count($chkDomains) < 1 ) {
			$output->writeln("None of the provided domain(s) will expire soon (< 30 days).");
			
			/**
			 * @var Symfony\Component\Console\Helper\QuestionHelper
			 */
			$helper = $this->getHelper('question');
			$question = new ConfirmationQuestion('Do you want to proceed anyway? (yes/NO): ', false);
		
			// Ask the user for input and wait for a yes/no response
			if ($this->input->getOption('no-interaction') == false) {
				$answer = $helper->ask($input, $output, $question);
			} else {
				$answer = true;
			}
		
			if ($answer) {
				// User answered "yes"
				$output->writeln('Proceeding anyway...');
			} else {
				// User answered "no"
				$cmdResult = Command::INVALID;
				$output->writeln('Cancelled.');
			}
		} 
		
		if ($answer) {

			if ($wipe == true) {
				$output->writeln("Deleting old DNS Challenges for domains: " . join(", ", $domains) . "...");
				$this->deleteACMEChallengeDNSRecords($domains);
			}

			if ($continue == false) {
				$output->writeln("Cleaning acme.sh.log ");
				file_put_contents($acmeFolder.'/acme.sh.log', LOCK_EX);
				$output->writeln("Issuing DNS Challenges with " . $acmeScript . " for domains: " . join(", ", $domains) . "...");
				$args = $this->buildAcmeIssueCommand($input, $domains);
				$output->writeln("acme.sh command: " . join(" ", $args));
			}	
			
			$acmeResult = [];
			
			/**
			 * Exceute ACME Script, which generates acme-challenges
			*/
			if ($this->fakeExec || $continue == true) {
				$exitCode = 0;	
				$acmeResult = explode("\n",file_get_contents($acmeFolder.'/acme.sh.log'));
			} else {
				$exitCode = $this->exec($acmeScript, $args, $acmeResult, $acmeFolder);

				// While debugging, I found out, $acmeResult did not contain the whole output
				// therefore, we get that output from the logs: 
				//$acmeResult = explode("\n",file_get_contents($acmeFolder.'/acme.sh.log'));
			}
	
			/**
			 * Parse ACME Script output
			*/
	
			// 1 seems to be an result, which may indicate that no challenges were issued, but the records where not updated, so continue in that case, too: 
			// acme.sh always returns an error in dns manual mode (because the DNS Entries are not updated?) 
			if ($exitCode > 1) {
				$output->writeln("Error: acme.sh failed with exit code " . $exitCode . ' (check .acme.sh.log for more details)');
				$cmdResult = Command::FAILURE;
			} 
			else if (is_array($acmeResult) && count($acmeResult) > 0) {
				
				$output->writeln('Parsing ACME output:', OutputInterface::VERBOSITY_VERY_VERBOSE);
				$output->writeln($acmeResult, OutputInterface::VERBOSITY_VERY_VERBOSE);
				$parsed = $this->parseAcmeIssueOutput($input, $output, $acmeResult);
	
				if (count($parsed['issues']) == 0) {
					$output->writeln("No acme-challenge records were proivided by acme.sh output!", OutputInterface::VERBOSITY_QUIET);
				} 
				else {

					$this->printTable($parsed['issues'], 'ACME challenges now pending:');
					//$output->writeln(print_r($parsed, true));
	
					// Es is wohl sinnvoll, dieses Ergebnis zwischenzuspeichern, so dass der Vorgang dann zu einem späteren Zeitpunkt wieder aufgenommen werden kann
					// dann müsste nicht immer wieder Let's Encrypt bemüht werden (z.B. beim Debuggen / Fehlern relevant)
					// aber wann soll die Datei gelöscht werden? 
					try {
						// anreichern mit den infos: verified ja/nein? 
						// und der info, für welche domains
						$pendingChallengesCacheFile = $this->getPendingChallengesFile();
						$parsed['valid-for-domains'] = $domains;
						$parsed['staging'] = $input->getOption('staging');
						file_put_contents($pendingChallengesCacheFile, json_encode($parsed, JSON_PRETTY_PRINT));
						$cmdResult = Command::SUCCESS;
					} catch(Exception $ex) {
						$this->writeln("Failed to store pending challenges to: " . $pendingChallengesCacheFile);
					}	
				}
			}
		}

		return $cmdResult;
	}


	protected function acmeChallengeSetDNSRecords($challenges = null) {

		$input = $this->input;
		$output = $this->output;
		$errors = 0;
		$result = Command::FAILURE;

		if ($challenges == null) {
			$challenges = $this->getPendingChallenges();
		}

		// add new acme-challenge values as DNS TXT records:
		foreach ($challenges['issues'] as $issue) {
			$this->initKisSession();
			$this->addDnsRecord($input, $output, $issue['domain'], 'TXT/' . $issue['subdomain'] . '/' . $issue['txtValue'].'/600');
		}

		
		foreach ($challenges['domains'] as $domain) {

			$dnsTab = $this->listDnsRecords($input, $output, $domain);

			foreach ($challenges['issues'] as $issue) {
				if ($issue['domain'] == $domain) {
					
					$filtered = $this->filterDnsRecordList($dnsTab, ['TXT', $issue['subdomain'] . '.' . $issue['domain'], $issue['txtValue']]);
					
					if (count($filtered) == 1) {
						$output->writeln("Success adding new DNS record: " . join(", ", $filtered[0]));
					} else {
						$output->writeln("Failed to add DNS record: " . $issue['subdomain'] . " with value " . $issue['txtValue']);
						$errors++;
					}
				}
			}
		}

		if ($errors > 0) {
			$output->writeln("Error: one or more acme challenges could not be added.");
			$result = Command::FAILURE;
		} else {
			$output->writeln("All acme challenges were successfully added to DNS records.");
			$result = Command::SUCCESS;
		}

		return $result;
	}

	/**
	 * Verify pending DNS challenges locally (optional) and by invoking acme.sh - this will generate new TLS certificates on success.
	 * 
	 * @param int $delay 
	 * @param bool $verifyLocal 
	 * @return 1|0 Returns Command::FAILURE on error, Command::SUCCESS on success
	 * @throws InvalidArgumentException 
	 * @throws DivisionByZeroError 
	 * @throws ArithmeticError 
	 */
	protected function acmeRenew($delay = 0, bool $verifyLocal = true) 
	{
		$input = $this->input;
		$output = $this->output;
		$config = $this->config;
		$acmeFolder = dirname(realpath($config['acme_script']));
		$acmeScript = realpath($config['acme_script']);
		$result = Command::FAILURE;

		// first, verify that the challenges are set correct: 
		$pendingChallenges = $this->getPendingChallenges();
		//$dnsRecords = $this->queryAcmeChallengeDNSRecords($pendingChallenges['domains']);

		if ($pendingChallenges && is_array($pendingChallenges['issues']) && count($pendingChallenges['issues']) > 0) {

			if ($delay > 0) {
				$output->writeln("Waiting ".$delay ." seconds for DNS entries to be become populated before verfifcation...");
				sleep($delay);
			}

			if ($verifyLocal == false) {
				$allValid = true;
			} else {
				$allValid = $this->verifyDNSChallengeRecords($pendingChallenges['domains'], $pendingChallenges['issues'], true);
			}

			if (false == $allValid) {
				$output->writeln("Error: one or more acme challenges can not be verfied locally. Refusing to continue.");
				$this->printTable($pendingChallenges['issues'], 'Pending challenges:'); 
			} 
			else {

				$output->writeln("Cleaning acme.sh.log "); 
				file_put_contents($acmeFolder.'/acme.sh.log', LOCK_EX);

				$domains = $pendingChallenges['valid-for-domains'];
				$output->writeln("Generating certificates with acme.sh --renew.");
				$args = ['--renew'];
				foreach ($domains as $domain) {
					$args[] = '-d';
					$args[] = $domain;
				}
				$args[] = '--yes-I-know-dns-manual-mode-enough-go-ahead-please';
				$args[] = '--force';
				if ($input->getOption('staging')) {
					$args[] = '--staging';
					$args[] = '--debug';
				}

				$renewResult = [];

				if ($this->fakeExec) {
					$exitCode = 0;
					$renewResult = explode("\n", file_get_contents($this->getAcmeShFolder() . 'acme.sh.log'));
				} else {
					$exitCode = $this->exec($acmeScript, $args, $renewResult, $acmeFolder);
				}

				if ($exitCode == 0) {

					$renewResult = $this->parseAcmeRenewOutput($input, $output, $renewResult);

					if (isset($renewResult['fullchain']) && isset($renewResult['key'])) {

						$pendingUploadInfoFile = $this->getPendingUploadsInfoFile();
						file_put_contents($pendingUploadInfoFile, json_encode($renewResult, JSON_PRETTY_PRINT));

						$output->writeln("Successfully created certificates with acme.sh.");
						$output->writeln(print_r($renewResult, true), OutputInterface::VERBOSITY_NORMAL);
						$result = Command::SUCCESS;
					} 
				}
				else {
					$output->writeln("Error: acme.sh failed with exit code " . $exitCode);
					$result = Command::FAILURE;
				}
			}
		}

		return $result;
	}

	/**
	 * 
	 * @param array $domains 
	 * @param array &$challenges 
	 * @param bool $pretty 
	 * @return bool returns true when all challeng values were found in DNS answers
	 */
	protected function verifyDNSChallengeRecords(array $domains, array &$challenges, $pretty = false) {

		$allValid = true;

		// first, reset verifiable flag for all challenges: 
		foreach ($challenges as &$issue) {
			$issue['verifiable'] = false;
		}

		// mark challenge as verifiable when the DNS records can be found: 
		foreach ($domains as $domain) {
			foreach ($challenges as &$issue) {
				if ($issue['domain'] == $domain) {
					$records = $this->queryAcmeChallengeDNSRecords($domain);
					foreach ($records as $r) { 
						if ($r['value'] == $issue['txtValue']) {
							$issue['verifiable'] = true;
						}
					}
				}
			}
		}

		// compute the overall result: 
		foreach ($challenges as $chkIssue) {
			if ($chkIssue['verifiable'] === false) {
				$allValid = false;
			}
		}

		if ($pretty == true) {
			foreach ($challenges as &$issue) {
				$issue['verifiable'] = ($issue['verifiable'] == true) ? '✓' : '✗' ;
			}
		}		

		return $allValid;
	}

	protected function parseAcmeRenewOutput(InputInterface $input, OutputInterface $output, array $acmeOutput)
	{
		$result = [];
		$lines = $acmeOutput;
		$i = 0;

		$output->writeln('Parsing ACME renew output:', OutputInterface::VERBOSITY_VERY_VERBOSE);
		$output->writeln($acmeOutput, OutputInterface::VERBOSITY_VERY_VERBOSE);

		foreach ($lines as $line) {
			$line = trim($line);
			if (!empty($line)) {
				if (strpos($line, 'Your cert key is in ') !== false) {
					$parts = explode('  ', $line);
					$file = $parts[1];
					$result['folder'] = dirname($file);
					$result['key'] = $file;
				} else if (strpos($line, 'the full chain certs is there:') !== false) {
					$parts = explode('  ', $line);
					$file = $parts[1];
					$result['folder'] = dirname($file);
					$result['fullchain'] = $file;
				}
			}
			$i++;
		}

		$output->writeln("Available certs:", OutputInterface::VERBOSITY_VERBOSE);
		$output->writeln(print_r($result, true), OutputInterface::VERBOSITY_VERBOSE);

		return $result;
	}

	protected function parseAcmeIssueOutput(InputInterface $input, OutputInterface $output, array $acmeOutput, array $forDomains = array())
	{
		$result = ['issues' => [], 'domains' => []];
		$i = 0;
		$lines = $acmeOutput;
		foreach ($lines as $line) {
			$line = trim($line);
			if (!empty($line)) {
				if (strpos($line, 'Add the following TXT record:') !== false) {
					$domain = explode("'", $lines[$i + 1])[1];
					$txtValue = explode("'", $lines[$i + 2])[1];
					$rootDomain = explode(".", $domain, 2)[1];
					$subdomain = '_acme-challenge';
					if (explode(".", $domain)[0] == $subdomain) {

						$isNotDuplicate = true;
						foreach($result['issues'] as $issues) {
							if ($issues['txtValue'] == $txtValue) {
								$isNotDuplicate = false;
							}
						}
						if (!$isNotDuplicate) {
							$output->writeln("Ignoring duplicate txtValue: " . $txtValue);
							continue;
						}
						$result['issues'][] = ['domain' => $rootDomain, 'txtValue' => $txtValue, 'subdomain' => $subdomain];
						if (!in_array($rootDomain, $result['domains'])) {
							$result['domains'][] = $rootDomain;
						}
					} else {
						$output->writeln("Ignoring invalid domain: " . $domain);
					}
				}
				foreach ($forDomains as $domainToCheck) {
					$searchString = '{domain} is already verified, skip dns-01';
					$searchString = str_replace('{domain}', $domainToCheck, $searchString);
					if (strpos($line, $searchString, 0) !== false) {
						// Get the last element of the array
						$rootDomain = end(explode(".", $domainToCheck));
						if (!in_array($rootDomain, $result['domains'])) {
							$result['domains'][] = $rootDomain;
						}
						if (!in_array($domainToCheck, $result['verified_domains'])) {
							$result['verified_domains'][] = ['domain' =>  $domainToCheck, 'rootDomain' => $rootDomain];
						}
					}
				}
			}
			$i++;
		}

		return $result;
	}

	protected function addDnsRecord(InputInterface $input, OutputInterface $output, $domain, $dnsRecord = null)
	{
		$dnsTab = array();

		if ($dnsRecord == null) {
			$patternRaw = $input->getOption('add-dns-record');
		} else {
			$patternRaw = $dnsRecord;
		}

		$entries = explode(",", $patternRaw);
		foreach ($entries as $entryRaw) {
			// format: TXT:HOST:VALUE
			$dnsRecord = explode("/", $entryRaw);
			if (count($dnsRecord) == 4) {
				$dnsRecord['ttl'] = $dnsRecord[3];
			}
			$dnsRecord['type'] = self::RECORD_TYPE_TO_VALUE_MAP[$dnsRecord[0]];
			$dnsRecord['host'] = $dnsRecord[1];
			$dnsRecord['value'] = $dnsRecord[2];
			$script = 'add-dns-record.json.twig';
			$params = [
				'hostadd' => $dnsRecord['host'],
				'record' => $dnsRecord['type'],
				'pointeradd' => $dnsRecord['value'],
				'domain' => $domain,
			];
			$output->writeln("Adding DNS record: " . join(", ", $params));
			$newDnsTab = $this->getClient()->replayJSONFile($script, $params);
			$dnsTab = $newDnsTab;

			$newEntry = $this->filterDnsRecordList($dnsTab, ['TXT', $dnsRecord['host'] . '.' . $domain, $dnsRecord['value']]);
			if (count($newEntry) == 1) {
				$newEntry = $newEntry[0];
				$output->writeln("Successfully added DNS record: " . join(", ", $newEntry));
				if (isset($dnsRecord['ttl'])) {
					// set ttl
					$script = 'set-dns-record-ttl.json.twig';
					$params = [
						'ttl' => $dnsRecord['ttl'],
						'pointer' => $dnsRecord['value'],
						'domain' => $domain,
						'hostid' => $newEntry['hostid']
					];
					$output->writeln("Updating TTL: " . join(", ", $dnsRecord));
					$newDnsTab = $this->getClient()->replayJSONFile($script, $params);
					$dnsTab = $newDnsTab;
					$updatedEntry = $this->filterDnsRecordList($dnsTab, ['TXT', $dnsRecord['host'] . '.' . $domain, $dnsRecord['value']]);
					$output->writeln("Actual TTL: ". $updatedEntry[0]['ttl']);
				}
			}
		}

		return $dnsTab;
	}

	/**
	 * 
	 * @param InputInterface $input 
	 * @param OutputInterface $output 
	 * @param mixed $domain 
	 * @return bool true on error, false on success
	 * @throws InvalidArgumentException 
	 */
	protected function deleteDnsRecord(InputInterface $input, OutputInterface $output, $domain, $dnsRecordPattern = null)
	{

		// TODO: evtl. abfangen wenn ungültige domain übergeben wurde, läuft aber auch so fehlerfrei
		$deleteSuccess = true;
		$dnsTab = $this->listDnsRecords($input, $output, $domain);

		if ($dnsRecordPattern == null) {
			// macht nur sinn, wenn manuell über kommandozeile aufgerufen (d.h. nicht per acme-renew)
			$patternRaw = $input->getOption('delete-dns-record');
		} else {
			$patternRaw = $dnsRecordPattern;
		}

		$filters = explode(",", $patternRaw);
		foreach ($filters as $filterRaw) {
			$filter = explode("/", $filterRaw);

			// DNS Record Liste filtern, so dass nur die zu löschenden Einträge gelistet sind: 
			$dnsTabFiltered = $this->filterDnsRecordList($dnsTab, $filter);
			if (count($dnsTabFiltered) > 0) {
				foreach ($dnsTabFiltered as $dnsRecord) {
					if (!empty($dnsRecord['hostid'])) {
						$params = [
							'record' => self::RECORD_TYPE_TO_VALUE_MAP[$dnsRecord['type']],
							'pointer' => $dnsRecord['value'],
							'domain' => $domain,
							'hostid' => $dnsRecord['hostid'],
						];
						$output->writeln("Deleting DNS record: " . join(", ", $params));
						$newDnsTab = $this->getClient()->replayJSONFile('delete-dns-record.json.twig', $params);
						$dnsTab = $newDnsTab;
						$deleteSuccess = false;

						// Check if dns tab does not contain the specified record anymore:
						if (is_array($dnsTab)) {
							$deleteSuccess = true;
							foreach ($dnsTab as $dnsTabEntry) {
								if($dnsTabEntry['domain'] == $dnsRecord['domain']
									&& $dnsTabEntry['value'] == $dnsRecord['value']
									&& $dnsTabEntry['type'] == $dnsRecord['type']) {
										$deleteSuccess = false;
										$output->writeln("Failed to delete DNS record:\n" . print_r($dnsRecord, true));
									}
							}
						}
						
					}
				}
			}
		}

		return !$deleteSuccess;
	}


	protected function listSSLEndpoints(InputInterface $input, OutputInterface $output, $webpackId = null)
	{
		$script = 'list-ssl-endpoints.json.twig';
		$params = [
			'webpack_id' => $webpackId,
		];
		$endpoints = $this->getClient()->replayJSONFile($script, $params);

		foreach ($endpoints as &$ep) {
			if (in_array('(global)', $ep['domains']) == true) {
				$ep['global'] = true; 
			} else {
				$ep['global'] = false;
			}
		}
		//print_r($endpoints);
		return $endpoints;
	}

	/**
	 * @param InputInterface $input 
	 * @param OutputInterface $output 
	 * @param stdClass $sslEndpoint The vhost ID and webpack id
	 * @param string $fullchain 
	 * @param string $key 
	 * @param null|string $password 
	 * @param null|string $cafile 
	 * @return string 
	 */
	protected function uploadCert(InputInterface $input, OutputInterface $output, array $sslEndpoint, string $fullchain, string $key, ?string $password = null, ?string $cafile = null) : string
	{
		$script = 'upload-cert.json.twig';
		$params = [
			'vhost_id' => $sslEndpoint['vid'],
			'webpack_id' => $sslEndpoint['wpid'],
			'cert_fs_path' => $fullchain,
			'key_fs_path' => $key
		];

		$result = (string)$this->getClient()->replayJSONFile($script, $params);

		// nach dem upload erscheint ein div mit dem text: "Die Dateien wurden hochgeladen. ..." 
		// dies zurückgeben um den upload zu kontrollieren zu können: 

		return $result;
	}

	/**
	 * Reads the HostEurope webpacks contained in your contracts and returns a list of webpacks with their associated domains.
	 * @param InputInterface $input 
	 * @param OutputInterface $output 
	 * @return array 
	 */
	protected function listWebpacks(InputInterface $input, OutputInterface $output, bool $disableCache = false)
	{
		$webpacks = [];
		$script = 'list-webpacks.json';
		$params = [];
		$cachefile = $this->getWebpacksCacheFile();

		// 60 * 60 * 24 * 30 = 2592000 (30 Tage)
		if ($disableCache == false && file_exists($cachefile) && filemtime($cachefile) > time() - 2592000) {
			$webpacks = unserialize(file_get_contents($cachefile));
		}

		if ($webpacks == null || count($webpacks) < 1) {
			$webpacks = $this->getClient()->replayJSONFile($script, $params);
			if ($webpacks != null && is_array($webpacks) && count($webpacks) > 0) {
				file_put_contents($cachefile, serialize($webpacks));
			}
		}		

		// reduce to contracts with an id:
		$tmpPacks = [];
		foreach ($webpacks as $row) {
			$row['contract_id'] = trim(explode("\n", $row['contract_id'])[0]);
			if (!empty($row['contract_id'])) {
				$tmpPacks[] = $row;
			}
		}

		// filter out columns:
		$webpacks = [];
		foreach ($tmpPacks as $webpackRow) {
			$webpackRow = array_filter($webpackRow, function ($value, $key) {
				$validColumns = ['contract_id', 'product', 'domains', 'index'];
				if (in_array($key, $validColumns)) {
					return true;
				} else {
					return false;
				}
			}, ARRAY_FILTER_USE_BOTH);
			$webpacks[] = $webpackRow;
		}

		foreach ($webpacks as &$webpack) {
			$webpack['domains'] = explode("\n", $webpack['domains']);
			$webpack['domains'] = array_map('trim', $webpack['domains']);
			$webpack['domains'] = array_filter($webpack['domains'], function ($value) {
				return !empty($value);
			});
		}

		return $webpacks;
	}

	/**
	 * Reads the HostEurope contracts and returns a list of contracts with their associated domains.
	 * @param InputInterface $input 
	 * @param OutputInterface $output 
	 * @return array 
	 */
	protected function listContracts(InputInterface $input, OutputInterface $output)
	{
		$validContracts = [];
		$script = 'list-contracts.json';
		$params = [];

		$contracts = $this->getClient()->replayJSONFile($script, $params);


		// reduce to contracts with an id:
		$tmpContracts = [];
		foreach ($contracts as $row) {
			if (!empty($row['contract_id'])) {
				$tmpContracts[] = $row;
			}
		}

		// filter out columns:
		foreach ($tmpContracts as $contractRow) {
			$contractRow = array_filter($contractRow, function ($value, $key) {
				$validColumns = ['billing_amount', 'billing_interval', 'changeable_until', 'checkbox', 'contract_id', 'contract_term', 'index', 'ipv4', 'mailbox', 'product'];
				if (in_array($key, $validColumns)) {
					return true;
				} else {
					return false;
				}
			}, ARRAY_FILTER_USE_BOTH);
			$validContracts[] = $contractRow;
		}

		return $validContracts;
	}

	protected function queryAcmeChallengeDNSRecords(array|string $domain) {

		if (is_array($domain)) {
			$txtRecords = [];
			foreach ($domain as $d) {
				$nslookupResult = [];
				$this->exec('nslookup', ['-type=TXT', '_acme-challenge.'.$d], $nslookupResult, $this->getAcmeShFolder());
				$results = $this->parseNsLookupOutput($nslookupResult);
				foreach ($results as $r) {
					$txtRecords[] = $r;
				}
			}
		} 
		else {
			$nslookupResult = [];
			$this->exec('nslookup', ['-type=TXT', '_acme-challenge.'.$domain], $nslookupResult, $this->getAcmeShFolder());
			$txtRecords = $this->parseNsLookupOutput($nslookupResult);
		}		

		return $txtRecords;
	}

	protected function parseNsLookupOutput($lines) {

		$state = null;
		$address = "";
		$na_answers = [];
		
		for($i=0; $i<count($lines); $i++) {
			if($lines[$i] == 'Non-authoritative answer:') {
				$state = 'non-authoritative-answer';
			}

			if ($state == null && strpos($lines[$i], 'Address:') === 0) {
				$address = explode("\t", $lines[$i])[1];
			}

			if ($state == 'non-authoritative-answer' && strpos($lines[$i], '_acme-challenge') === 0) {
				$answer = explode("=", $lines[$i]);
				$answer['domain'] = explode("\t", $answer[0])[0];
				$answer['type'] = explode("\t", $answer[0])[1];
				$answer['from'] = $address;
				$answer['value'] = substr($answer[1], 2, -1);
				unset($answer[0]);
				unset($answer[1]);
				$na_answers[] = $answer;
			}
		}

		return $na_answers;
	}

	/**
	 * Lists the DNS records for a specific domain.
	 * 
	 * @param InputInterface $input 
	 * @param OutputInterface $output 
	 * @param mixed $domain 
	 * @return array 
	 */
	protected function listDnsRecords(InputInterface $input, OutputInterface $output, $domain)
	{
		$script = 'list-dns-records.json.twig';
		$params = [
			'domain' => $domain,
		];

		$dnsTab = $this->getClient()->replayJSONFile($script, $params);

		$i = 0;
		$dnsTabNormalized = [];
		foreach ($dnsTab as $record) {
			$record['index'] = $i;

			if (!array_key_exists('hostid', $record)) {
				$record['hostid'] = null;
			}

			if (!array_key_exists('ttl', $record)) {
				$record['ttl'] = null;
			}

			$normRecord = [
				'index' => $record['index'],
				'domain' => $record['domain'],
				'type' => $record['type'],
				'ttl' => $record['ttl'],
				'value' => $record['value'],
				'hostid' => $record['hostid']
			];
			$dnsTabNormalized[] = $normRecord;
			$i++;
		}

		return $dnsTabNormalized;
	}

	protected function printDnsRecordTable(InputInterface $input, OutputInterface $output, $domain, $dnsTab)
	{

		$this->printTable($dnsTab, 'DNS records for ' . strtoupper($domain) . ':', true, 50);
	}

	/**
	 * Filters out DNS records by a given filter condition.
	 * 
	 * @param mixed $dnsTab return value of listDnsRecords
	 * @param array $filter [0] => TYPE, [1] = DOMAIN, [2] => VALUE (optional)
	 * @return array the filtered list 
	 */
	protected function filterDnsRecordList($dnsTab, array $filter)
	{

		$result = array();

		foreach ($dnsTab as &$row) {
			// check if row matches the filter condition
			if (count($filter) == 2) {
				if ($row['type'] == $filter[0]) {
					if ($row['domain'] == $filter[1]) {
						$result[] = $row;
					}
					if ($filter[1][-1] == '*') {
						if (strpos($row['domain'], substr($filter[1], 0, -1)) === 0) {
							$result[] = $row;
						}
					}
				}
			} else if (count($filter) == 3) {
				if ($row['type'] == $filter[0] && $row['domain'] == $filter[1] && $row['value'] == $filter[2]) {
					$result[] = $row;
				}
			}
		}

		return $result;
	}
}
