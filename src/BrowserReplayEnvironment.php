<?php
/**
 * 
 * This file is part of the kis-cli package.
 *
 * (c) Ole Loots <ole@monochrom.net>
 *
 * For the full license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 */


namespace Mono\KisCLI;

use Exception;
use Facebook\WebDriver\Exception\Internal\IOException;
use Facebook\WebDriver\Exception\NoSuchAlertException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Mono\KisCLI\Interface\IBrowserReplayEnvironment;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Panther\Client;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Error\RuntimeError;
use Twig\Loader\FilesystemLoader;

/**
 * Run multiple instances in parallel? #185
 * https://github.com/symfony/panther/issues/185
 */

/**
 *  @package Mono\LibReplay
 *  
 */

/**
 * 
 * An automatable (scriptable) Browser with benefits (and bugs, of course)
 * This class can replay JSON recordings which can be recorded with chrome-like browsers (check the developer tools)
 * Usage of the class is documented in detail in the somewhat outdatet file lib-replay-README.md. 
 */
class BrowserReplayEnvironment implements IBrowserReplayEnvironment
{

    /**
     * 
     * @var null|Client
     */

    protected ?Client $panther = null;
    protected array $records;
    protected array $scripts;
    protected LoggerInterface $logger;
    protected bool $enableScreenshots = false;
    protected array $storedParams;
    protected Environment $twig;

    /**
     * 
     * @param array $params Persitent parameters to be injected into the browser context (can be modified and extended during replaying)
     * @param string $baseFolder base folder for recordings and scripts
     * @param LoggerInterface $logger Some PSR-3 compatible logger
     * @param null|string $baseUrl $baseUrl base URL (do not navigate to other URL's)
     * @param null|array $credentials basic auth credentials
     * @return void 
     * @throws IOException 
     * @throws RuntimeException 
     * @throws Exception 
     */
    function __construct(protected array $envParams, protected string $baseFolder, LoggerInterface $logger, protected ?string $baseUrl = null, ?array $credentials = null, protected array $ioCallbacks)
    {
        $this->logger = $logger;
        $this->initSession($envParams, $baseFolder, $baseUrl, $credentials);
        return $this;
    }

    function __destruct()
    {
        $this->closeSession();
    }


    /**
     * 
     * @param array $params Persitent parameters to be injected into the browser context (can be modified and extended during replaying)
     * @param string $baseFolder base folder for recordings and scripts
     * @param null|string $baseUrl base URL (do not navigate to other URL's)
     * @param null|array $credentials basic auth credentials
     * @return $this 
     * @throws IOException 
     * @throws RuntimeException 
     * @throws Exception 
     */
    public function initSession(array $envParams, string $baseFolder, ?string $baseUrl, ?array $credentials)
    {

        if ($this->panther) {
            $this->closeSession();
        }
        
        $this->twig = new Environment(new FilesystemLoader($this->baseFolder));
        $this->setRecords([]);
        $this->setScripts([
            'sjcl.js' => __DIR__ . '/javascript/sjcl.js',
            'interface.js' => __DIR__ . '/javascript/interface.js'
        ]);
        $this->loadRecordings($this->baseFolder);
        $this->loadScripts($this->baseFolder);
        $this->storedParams = $this->loadJsVarsStore();
        
        $this->logger->info("Creating new session... \n");
        // Define desired capabilities
        $capabilities = DesiredCapabilities::firefox();
        $capabilities->setCapability('moz:firefoxOptions', [
            'prefs' => [
                'network.cookie.cookieBehavior' => 0,
                'network.cookie.lifetimePolicy' => 2, // Keep cookies until firefox is closed
                'network.cookie.allow_external' => true,
                'network.cookie.alwaysAcceptSessionCookies' => true, // Accept session cookies
                'network.cookie.thirdparty.sessionOnly' => true
            ]
        ]);

        $this->panther = Client::createFirefoxClient(null, null, ['capapilities' => $capabilities]);

        $this->panther->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1200, 900));

        // TODO: handle basic auth credentials here
        // TODO: goto base url? 
        $this->injectJsInterface($envParams);

        return $this;
    }

    public function getJsVar(string $name) : mixed {
        $var = $this->panther->executeScript("if (typeof {$name} == undefined) { return null; } else { return window.{$name}; }");
        return $var;
    }

    private function getJsParam(string $name) : mixed {
        $var = $this->panther->executeScript("return window._wd_params[{$name}];");
        return $var;
    }

    private function getStoredParamsFromJsContext() {
        $var = $this->executeJavaScript("if (window._wd_get_store) { return window._wd_get_store() } else {return null; }");
        return $var;
    }

    private function getJsParams() : mixed {
        $params = $this->panther->executeScript("return window._wd_params;");
        $this->logger->debug("received JS params: ". json_encode($params));
        return $params;
    }

    private function setJsParam(string $name, string $value) : void {
        $this->panther->executeScript("window._wd_params[{$name}] = ".json_encode($value).";");
    }

    private function injectJsInterface(array $params = null) : void
    {
        
        // hier pro datei abfangen, ob die datei bereits injected ist (via sha1 prüfsumme)
        foreach ($this->getScripts() as $script => $path) {
            $this->logger->debug("injecting script: " . $path);
            if (true === $this->panther->executeScript("return window._wd_include_".sha1($path, false).";")) {
                continue;
            }
            $this->panther->executeScript(file_get_contents($path));

            // sha1 prüfsumme des scripts speichern, damit kann kontrolliert werden, ob ein script bereits injected wurde
            $this->panther->executeScript("window._wd_include_".sha1($path, false)." = true;");
        }

        //$this->logger->debug("injecting env params: " . json_encode($this->getEnvParams()));
        //$this->injectJsVar('_wd_env', $this->getEnvParams());

        if ($params != null) {
            $this->logger->debug("injecting runtime params: " . json_encode($params));
            $this->injectJsVar('_wd_params', $params);
        }        

        $store = $this->storedParams; 
        $this->logger->debug("injecting store: " . json_encode($store));
        //$this->injectJsVar('_wd_store', $store);
        $this->executeJavaScript("window._wd_set_store(".json_encode($store).");");
    }

    private function loadJsVarsStore() : object|array {

        $store = [];
        $storeFile = $this->baseFolder . '/_wd_store.db.json';

        $this->logger->info("restoring variables from storage ".$storeFile."... \n");

        if (file_exists($storeFile)) {
            $storeRaw = file_get_contents($this->baseFolder . '/_wd_store.db.json');
            $this->logger->info("Loaded ".strlen($storeRaw)." bytes from store");
            if(mb_strlen($storeRaw) > 0) {
                $store = json_decode($storeRaw, true);
                if ($store == null) {
                    $store = [];
                }

                /*
                if(!is_array($store)) {
                    $this->logger->warning("Dropping invalid store content!");
                }
                */
            } else {
                $store = [];
            }
            
        }

        return $store;
    }

    private function saveJsVarsStore() : int|false {

        // save persisten javasript variables _wd_param speichern
        
        $params = $this->storedParams;

        if ($params) {
            $paramsEncoded = json_encode($params);
            $this->logger->info("Saving JS var store (".strlen($paramsEncoded)." bytes)... \n");
            $result = file_put_contents($this->baseFolder . '/_wd_store.db.json', $paramsEncoded);
            return $result;
        } else {
            $this->logger->info("NOT SAVING store: it's empty!\n");
        }

        return -1;
    }
    
    private function injectJsVar(string $name, mixed $value) : void {

        $this->logger->debug("injecting param {$name}: (" . mb_strlen(json_encode($value)) . " bytes)");

        $this->panther->executeScript("window.".$name." = ".json_encode($value).";");
    }

    public function executeJavaScript(string $script) : mixed
    {
        return $this->panther->executeScript($script);
    }

    public function waitForDocumentReady() {
        
        $this->logger->debug("waiting for document ready ... ");
        
        
        $this->panther->wait(10 * 1000)->until(function(WebDriver $driver){
            $result = (bool)$this->panther->executeScript('return (document.readyState === "complete")');
            return $result;
        });

        $this->logger->debug("document ready ... ");
    }

    public function gotoURL(string $url, array $params, bool $waitForDocumentReady = true) : self
    {

        if (!$this->panther) {
            throw new Exception("No session initialized");
        }

        $this->logger->info('navigating to: ' . $url . '...');
        $this->panther->request('GET', $url);

        if ($waitForDocumentReady) {
            $this->logger->debug('waiting for document ready state...');
            $this->panther->wait(10 * 1000)->until(function(WebDriver $driver){
                $result = (bool)$this->panther->executeScript('return (document.readyState === "complete")');
                return $result;
            });
            $this->logger->debug('done waiting for document ready state.');
        }

        $this->injectJsInterface($params);

        return $this;
    }

    /**
     * 
     * @param string $scriptName 
     * @param array $params 
     * @param int $delayFactor 
     * @param int $delayMs 
     * @return mixed 
     * @throws Exception 
     * @throws LoaderError 
     * @throws SyntaxError 
     * @throws RuntimeError 
     */
    public function replayJSONFile(string $scriptName, array $params = [], int $delayFactor = 1, $delayMs = 500) : mixed
    {

        // TODO: als param einen error handler übergeben, der z.B. eine standard fehlermeldung für das script ausgibt.
        // Häufig liegen fehler an falschen eingabe parameter ... den user darauf hinweisen. 

        $script = $this->loadRecord($scriptName, $params);
        return $this->replayJSON($script, $params, $delayFactor, $delayMs);
    }

    public function replayJSON(string|array $json, array $params=[], int $delayFactor = 1, int $delayMs = 500) : mixed
    {
        $result = null; 
        // Parse the JSON recording:
        if (is_string($json)) {
            $recording = json_decode($json, true);
        } else {
            $recording = $json;
        }

        $client = $this->panther;

        // Execute each step in the recording
        $recordTitle = (array_key_exists('title', $recording) ? $recording['title'] : 'unnamed');
        $this->logger->info('replaying: '  . $recordTitle . '...');
        // TOOD: befehle implementieren: while, replay, inject_js
        $i = 1;
        $stepResult = null;
        foreach ($recording['steps'] as $step) {
            $stepResult = null;
            $this->injectJsInterface($params);
            $this->logger->info("step: " . $step['type'] . json_encode($step));
            $this->debugScreenshot($i."_".$step['type']);
            try {
                switch ($step['type']) {

                    case 'setViewport':
                        $client->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension($step['width'], $step['height']));
                        /*if (array_key_exists('isLandscape', $step) && $step['isLandscape'] === true) {
                            $client->manage()->window()->setScreenOrientation('LANDSCAPE');
                        } else {
                            $client->manage()->window()->setScreenOrientation('PORTRAIT');
                        }*/
                        break;

                    case 'navigate':
                        $this->gotoURL($step['url'], $params);
                        break;

                    case 'click':
                        $this->findElementBySelectors($step['selectors'])->click();
                        break;

                    case 'change':
                        $this->logger->debug("sending value: " . $step['value']);
                        if(empty($step['value'])) {
                            $this->findElementBySelectors($step['selectors'])->clear();
                        } else {
                            $this->findElementBySelectors($step['selectors'])->clear();
                            $this->findElementBySelectors($step['selectors'])->sendKeys($this->resolveParamValue($step['value']));
                        }
                        break;

                    case 'keyDown':
                        $this->logger->debug("sending key: " . $step['key']);
                        $client->getKeyboard()->sendKeys($step['key']);
                        break;

                    case 'focus':
                        throw new Exception("focus not implemented yet");
                        break;

                    case 'waitForVisibility': 
                        $step['visible'] = true;
                    case 'waitForElement': 
                        $timeout = 30;
                        if(array_key_exists('timeout', $step)) {
                            if ($step['timeout'] > 0) {
                                $timeout = ((int)$step['timeout']/1000);
                            } else {
                                $timeout = -1;
                            }
                        }
                        if (array_key_exists('visible', $step) && $step['visible'] === true) {
                            $this->panther->waitForVisibility($step['selectors'][0], $timeout);
                        } else {
                            $this->panther->waitFor($step['selectors'][0], $timeout);
                        }
                        break;

                    case 'waitForExpression': 
                        $timeout = 30;
                        if(array_key_exists('timeout', $step)) {
                            if ($step['timeout'] > 0) {
                                $timeout = ((int)$step['timeout']/1000);
                            } else {
                                $timeout = -1;
                            }
                        }
                        $loopCount = 0;
                        $this->panther->wait($timeout)->until(function(WebDriver $driver) use ($step, $loopCount) {
                            $myresult = (bool)$this->panther->executeScript('let _wd_loop_count='.$loopCount.'; return ('.$step['expression'].')');
                            $loopCount++;
                            return $myresult;
                        });
                        
                        break;

                    case 'customStep':
                        // in diesem Zusammenhang evtl. interessant: vendor/php-webdriver/webdriver/lib/Remote/HttpCommandExecutor.php
                        $this->logger->debug("executing custom step: " . $step['name']);
                        switch ($step['name']) {

                            case 'js':
                                if (array_key_exists('parameters', $step) && array_key_exists('script', $step['parameters'])) {
                                    // Sauberer als verwendung von target!
                                    $client->executeScript($step['parameters']['script']);
                                }
                                else if (array_key_exists('source', $step) ) {
                                    $client->executeScript($step['source']);
                                } 
                                else {
                                    $client->executeScript($step['target']);
                                }
                                break;

                            case 'clear':
                                $this->findElementBySelectors([$step['target']])->clear();
                                break;
                            
                            case 'sleep':
                                sleep($step['target']);
                                break;

                            case 'echo':
                                $ctxPrefix = 'echo:';
                                if (array_key_exists('title', $step)) {
                                    $ctxPrefix .= $step['title'];
                                }
                                if (array_key_exists('source', $step) ) {
                                    $echoContent = $client->executeScript($step['source']);
                                }
                                else {
                                    $echoContent = $client->executeScript($step['target']);
                                }
                                if (!is_string($echoContent)) {
                                    $this->ioCallbacks['out']($ctxPrefix, print_r($echoContent, true));
                                } else {
                                    $this->ioCallbacks['out']($ctxPrefix, $echoContent);
                                }
                                break;

                            case 'replay':
                                $this->replayJSONFile($step['target'], $params, $delayFactor, $delayMs);
                                break;

                            case 'waitForVisibility':
                                $this->panther->waitForVisibility($step['target']);
                                $this->logger->debug($step['target']." became visible ... ");
                                break;

                            case 'waitForDocumentReady':
                                $this->waitForDocumentReady();
                                break;

                            case 'waitForRedirect':
                                $url = trim($step['url']);
                                $timeout = PHP_INT_MAX;
                                if (array_key_exists('timeout', $step)) {
                                    $timeout = intval($step['timeout']);
                                }
                                
                                $this->logger->info("Waiting for URL: " . $url . " ... ");
                                do {
                                    sleep(1);
                                    $timeout--;
                                    if ($timeout <= 0 && $client->getCurrentURL() != $url) {
                                        throw new TimeoutException('WaitForUrl Timeout', []);
                                    }
                                }
                                while ($client->getCurrentURL() != $url);

                                break;

                            case 'while':
                                $condition = $step['target'];                                
                                $steps = null;
                                $js = null;
                                if(array_key_exists('steps', $step['parameters'])) {
                                    $steps = (is_string($step['parameters']['steps'])) ? array($step['parameters']['steps']) : $step['parameters']['steps'];
                                }
                                if(array_key_exists('javascript', $step['parameters'])) {
                                    $js = $step['parameters']['javascript'];
                                }

                                $loopCount = 0;
                                while ($this->evaluateWhileConditionTarget($condition, $loopCount)) {
                                    // TODO: add option to wait for document ready state on each step? 
                                    if ($js != null) {
                                        $this->logger->debug("executing js: " . $js);
                                        $this->panther->executeScript($js);
                                    }
                                    if ($steps != null) {
                                        foreach ($steps as $step) {
                                            if (is_string($step)) {
                                                $this->replayJSONFile($step, $params, $delayFactor, $delayMs);
                                            } else {
                                                $this->replayJSON($step, $params, $delayFactor, $delayMs);
                                            }
                                        }
                                    }     
                                    $loopCount++;                               
                                }
                                break;
                        }
                        break;

                    case 'assert':
                        // TODO: hier auch JS ermöglichen? 
                        $element = $this->findElementBySelectors($step['selectors']);
                        if ($element->getText() !== $step['text']) {
                            throw new Exception("Assertion failed. Expected text: {$step['text']}, Actual text: {$element->getText()}");
                        }
                        break;

                    default:
                        echo "Unsupported step type: {$step['type']}\n";
                        break;
                }

                // Check asserted events after executing each steps
                if (isset($step['assertedEvents'])) {
                    foreach ($step['assertedEvents'] as $event) {
                        if ($event['type'] === 'navigation') {
                            $currentUrl = $client->getCurrentURL();
                            $currentTitle = $client->getTitle();
                            if (!empty($event['url']) && $currentUrl !== $event['url']) {
                                // XXX: this can also happen, when the browser processes a redirect... script shall use waitForRedirect then 
                                throw new Exception("Navigation assertion failed. Expected URL: {$event['url']}, Actual URL: $currentUrl");
                            }
                            if (!empty($event['title']) && $currentTitle !== $event['title']) {
                                throw new Exception("Navigation assertion failed. Expected title: {$event['title']}, Actual title: $currentTitle");
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
                throw $e;
            }


            if ($delayFactor > 0) {
                if ($delayMs * $delayFactor < 1000000) {
                    usleep($delayMs * $delayFactor  * 1000);
                } else {
                    sleep(intval($delayMs * $delayFactor / 1000000));
                }
            }
            
            $newParams = $this->getJsParams(); 
            if($newParams != null) {
                $params = array_merge($params, $this->getJsParams());
            }

            // update current view on stored params: 
            try{
                $storedParams = $this->getStoredParamsFromJsContext();
                if($storedParams != null) {
                    $this->storedParams = array_merge($this->storedParams, $storedParams);
                }
            } catch(Exception $ex) {
                $this->logger->error("Failed to resolve store! WebDriverException: " . $ex->getMessage());
            }
            
            
            $this->injectJsInterface($params);
            try{
                $stepResult = $this->panther->executeScript('return window._wd_get_result();');
                $result = $stepResult;
            } catch(Exception $ex) {
                $this->logger->error("Failed to resolve result! WebDriverException: " . $ex->getMessage());
            }

            $type = (array_key_exists('type', $step) ? $step['type'] : 'unknown');
            
            $this->debugScreenshot($i."_".$type."_after");
            
            $i++;
        }

        $this->logger->debug('params after replay: ' . json_encode($this->getJsParams()));

        return $result;
    }

    public function downloadFile(string $url, string $targetPath = null)
    {
        $this->gotoURL($url, [], false);

        // any better options?
        $this->panther->waitFor(3);
        
        $result = $this->panther->executeScript("return document.body.innerText;");

        if ($targetPath) {
            file_put_contents($targetPath, $result);
        }

        return $result;
    }

    // XXX: this does not work
    public function downloadFileAsync(string $url, string $targetPath = null)
    {
        $result = $this->panther->executeScript(
            "var _wd_tmp_download_result = null;" . 
            "await window._wd_fetch_url (null, null).then(result => {_wd_tmp_download_result=result;});" .
            "return _wd_tmp_download_result;"
        );

        if ($targetPath) {
            file_put_contents($targetPath, $result);
        }

        return $result;
    }

    private function drainJsConsole() {

        // XXX: does not work with firefox (geckodriver) yet?
        return; 
        // Access the console messages
        $logs = $this->panther->getWebDriver()->manage()->getLog('browser');
        foreach ($logs as $log) {
            echo $this->logger->debug("browser console " . $log);
        }
    }

    private function findElementBySelectors(array $selectors) : ?WebDriverElement {
        
        $xpathSelector = null;
        $cssSelector = null;
        $result = null;

        foreach ($selectors as $selector) {
            $selector = (is_array($selector) ? $selector[0] : $selector);
            if (strpos($selector, 'xpath/') === 0) {
                $xpathSelector = substr($selector, strlen('xpath/'));
            } 
            else if (strpos($selector, 'pierce/') === 0) {
                // discard?
                //$cssSelector = substr($selector, strlen('pierce/'));
            } 
            else if (strpos($selector, '#') === 0) {
                // discard
            } 
            else if (strpos($selector, 'aria/') === 0) {
                // discard
            } 
            else if (strpos($selector, 'text/') === 0) {
                // discard
            } 
            else {
                $cssSelector = $selector;
            }
        }

        if ($xpathSelector) {
            $this->logger->debug("searching by xpath: " . $xpathSelector);
            $result = $this->panther->findElement(WebDriverBy::xpath($xpathSelector));
        }
        else if ($cssSelector && !$result) {
            $this->logger->debug("searching by css selector: " . $cssSelector);
            $result = $this->panther->findElement(WebDriverBy::cssSelector($cssSelector));
        } 
         else {
            throw new Exception("No valid selector found in: " . implode(', ', $selectors));
        }

        return $result;
    }

    private function evaluateWhileConditionTarget(string $condition, int $loopCount = 0) : int|bool
    {

        if (is_string($condition) && strpos($condition, 'javascript:') === 0) {
            $condition = substr($condition, strlen('javascript:'));
            $result = $this->panther->executeScript( 'let _wd_loop_count=' . $loopCount . '; ' . $condition);
            $this->logger->debug('evaluateWhileConditionTarget: ' . $condition . " => "  . (int)$result);
            return $result;
        }

        return false;
    }

    private function resolveParamValue($value) : mixed
    {

        if (is_string($value) && strpos($value, 'javascript:') === 0) {
            $value = substr($value, strlen('javascript:'));
            return $this->panther->executeScript($value);
        } 
        else if (is_string($value) && strpos($value, 'param:') === 0) {
            $paramName = substr($value, strlen('param:'));
            return $this->getEnvParam($paramName);
        }
        else if (is_string($value) && strpos($value, 'jsparam:') === 0) {
            $paramName = substr($value, strlen('jsparam:'));
            return $this->getJsParam($paramName);
        } 
        else if (is_string($value) && strpos($value, 'env:') === 0) {
            $paramName = substr($value, strlen('env:'));
            return getenv($paramName);
        } 
        else if (is_string($value) && strpos($value, 'file:') === 0) {
            $paramName = substr($value, strlen('file:'));
            return file_get_contents($paramName);
        }

        return $value;
    }

    private function loadRecord(string $scriptName, array $params = null) : mixed
    {

        $result = null;

        if (array_key_exists($scriptName, $this->records)) {
            // if $scriptName ends with '.json.twig': 
            if (substr($scriptName, -10) === '.json.twig') {

                if ($params == null) {
                    $params = [];
                }

                $params = array_merge($params, [
                    'env' => $this->getEnvParams(),
                    'store' => $this->getStoredParamsFromJsContext(),
                    'jsparams' => $this->getJsParams()
                ]);

                $json = $this->twig->render($scriptName, $params);
                $result = json_decode($json, true);
            }
            else {
                $result = json_decode(file_get_contents($this->records[$scriptName]), true);
            }

            return $result;
        }

        throw new Exception("tried to load unknown replay script: $scriptName");
    }

    /**
     * Closes the current session.
     *
     * This function is responsible for closing the current session in the replay environment.
     * It performs any necessary cleanup tasks and releases any resources associated with the session.
     * Also the variable store is saved, so it can be restored in the next session.
     *
     * @return void
     */
    public function closeSession()
    {
        if ($this->panther) {
            $this->saveJsVarsStore();
            $this->logger->info("Closing session... \n");
            $this->panther->quit(true);
            $this->panther = null;
        }
    }

    public function loadRecordings(string $fromPath) : self
    {
        $records = [];
        $tmpRecords = glob($fromPath . '/*.json');

        foreach ($tmpRecords as $record) {
            $records[basename($record)] = $record;
        }

        $tmpRecords = glob($fromPath . '/*.json.twig');

        foreach ($tmpRecords as $record) {
            $records[basename($record)] = $record;
        }

        $this->records = array_merge($this->records, $records);

        $this->logger->debug("Loaded ".count($records)." recordings from $fromPath (sum: ".count($this->records).")\n");

        return $this;
    }

    public function loadScripts(string $fromPath) : self
    {
        $scripts = [];
        $tmpScripts = glob($fromPath . '/*.js');

        foreach ($tmpScripts as $script) {
            // Skip configuration files and persistence.db.json
            if (basename($script) === 'config.json' || basename($script) === '_config.json') {
                continue;
            }
            else if (basename($script) === '_wd_store.db.json') {
                continue;
            } 
            else {
                $scripts[basename($script)] = $script;
            }
        }

        $this->scripts = array_merge($this->scripts, $scripts);

        $this->logger->debug("Loaded ".count($scripts)." scripts from $fromPath (sum: ".count($this->scripts).")\n");

        return $this;
    }

    public function debugScreenshot(string $key = null) : self
    {

        if (!$this->enableScreenshots) {
            return $this;
        }

        if ($key) {
            $snapName = './snapshots/'.time() . '_' . $key . '.png';
        } else {
            $snapName = './snapshots/'.time() . '_debug.png';
        }        

        try {
            $this->panther->switchTo()->alert()->accept();
        } catch (NoSuchAlertException $e) {
            // no alert present
        }

        $this->panther->takeScreenshot($snapName);

        return $this;
    }

    

    /**
     * Set the value of records
     *
     * @return  self
     */
    public function setRecords(array $records) : self
    {
        $this->records = $records;

        return $this;
    }

    /**
     * Get the value of records
     */
    public function getRecords() : array
    {
        return $this->records;
    }

    /**
     * Get the value of scripts
     */ 
    public function getScripts() : array
    {
        return $this->scripts;
    }

    /**
     * Set the value of scripts
     *
     * @return  self
     */ 
    public function setScripts(array $scripts) : self
    {
        $this->scripts = $scripts;

        return $this;
    }

    /**
     * Get the value of enableScreenshots
     */ 
    public function getEnableScreenshots() : bool
    {
        return $this->enableScreenshots;
    }

    /**
     * Set the value of enableScreenshots
     *
     * @return  self
     */ 
    public function setEnableScreenshots(bool $enableScreenshots) : self
    {
        $this->enableScreenshots = $enableScreenshots;

        return $this;
    }

    /**
     * Get the value of readOnlyParams
     */ 
    public function getEnvParams() : array
    {
        return $this->envParams;
    }

    public function getEnvParam($name) : mixed
    {
        if (array_key_exists($name, $this->envParams)) {
            return $this->envParams[$name];
        } 

        return null;
    }

    /**
     * Set the value of readOnlyParams
     *
     * @return  self
     */ 
    public function setEnvParams(array $envParams) : self
    {
        $this->envParams = $envParams;

        return $this;
    }

    /**
     * Set the value of readOnlyParams
     *
     * @return  self
     */ 
    public function setEnvParam(string $name, string $value) : self
    {
        $this->envParams[$name] = $value;

        return $this;
    }
}
