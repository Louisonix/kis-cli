# Hosteurope KIS-CLI

Hosteurope (KIS) commandline interface for fully automated (wildcard) certificate renewal

## About 

Hosteurope does not support automated renewal of wildcard TLS certificates. The only automated option left is via .well-known web directory, however that method does not support wildcard domains. Also, hosteurope doesn't offer any kind of DNS API to automatically set DNS records (which is required for ACME DNS challenge).

This tool solves the problem by acting as a client for the KIS Web-Interface of hosteurope, so you don't have to manually renew and upload your wildcard let's encrypt certificates to hosteurope. It also takes care of issuing certs via acme.sh, updating the DNS records accordingly and verifiying the required DNS challenge. 

The package also provides some usefull commands to access Hosteurope via commandline. For example there is a command to manipulate Hosteurope DNS entries.

## Requirements: 

- Only tested under Linux 
- PHP > 8.2
- acme.sh (setup and configured for your needs, so that acme.sh is ready to generate your certficates in manual mode without the initial configuration)
- nslookup (for DNS verfication)
- sslscan (to fetch information about certification expiration)
- composer

## Setup 


### Installation

Unpack [xxRELEASE_NAMExx](xxRELEASE_FILExx) somewhere on your system. After that, run: 

`composer install` 

This should install the required PHP dependencies. After successfull installation, a setup script is executed which will ask a few questions about your Host-Europe Account. This also includes the username and password for the KIS admin area. 

Then you must install the __*geckodriver*__ for symfony panther, this is done with the following command: 

```./vendor/bin/bdi detect drivers```

If it does install the chrome-driver instead of the geckodriver, you are lost. 
Check https://symfony.com/doc/current/testing/end_to_end.html to see instructions how to manually install geckodriver into the drivers directory. 


## Test the Installation first 

Run the following command to make sure this tool can access KIS with the supplied credentials (resources/host-europe/config.json) and your system setup: 

`./bin/console kis:list-webpacks`

## Certificate renew

To issue a new renewable certificate, run the follwing commands: 

```
# Generate DNS Challenges and upload the answers to KIS (required to generate a fresh certificate): 
./bin/console acme:init -d "domain-one.com,*.domain-one.com,domain-two.de,*.domain-two.de,domain-three.net,*.domain-three.net"
```

```
# Verify DNS challenges and generate a new certificate: 
./bin/console acme:verify
```

```
# Upload certificate: 
./bin/console upload --webpack-id=1231234 --vhosts="*"
```

### Running a Cronjob which puts it all together

Setup cronjob.sh by configuring your webpack-id in that file. Test renewal by executing **cronjob.sh**.

If it works well, you can add cronjob.sh as an regular cronjob. 

#### ___Important___

**Beware**: do not run the cronjob more often than once in a month. Otherwise acme.sh will use cached results, which may yield unexpected results. Also, it may take several hours until you environment recognizes the new DNS entries (because of some DNS cached). The tool will refuse to invoke acme.sh as long as it can not verify all DNS challenges locally. If it does not verify right after you created the challenges - try it on the next day. 

## How does it work? 

This tool acts as a web-client via PHP Panther library to replay JSON scripts. These scripts can be found in the __./resources/host-europe__ folder. 

## Warranty 

This tool comes with absolutly no warranty! Altough this tool serves my purposes well, it may misbehaves with your setup. 
