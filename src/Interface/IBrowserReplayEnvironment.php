<?php
/**
 * This file is part of the kis-cli package.
 *
 * (c) Ole Loots <ole@monochrom.net>
 *
 * For the full license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace Mono\KisCLI\Interface;

interface IBrowserReplayEnvironment {

    public function setEnvParam(string $name, string $value) : self;
    public function setEnvParams(array $params) : self;
    public function gotoURL(string $url, array $params, bool $waitForDocumentReady = true) : self;
    public function replayJSONFile(string $scriptPath, array $params=[], int $delayFactor=1, int $delayMs = 500) : mixed;
    public function replayJSON(string|array $jsonStr, array $params=[], int $delayFactor=1, int $delayMs = 500) : mixed;
    public function initSession(array $params, string $baseFolder, ?string $baseUrl, ?array $credentials);
    public function getJsVar(string $name) : mixed ;
    //public function downloadFile(string $url, string $targetPath = null);
    public function setEnableScreenshots(bool $enableScreenshots) : self; 
    public function closeSession();
    
}