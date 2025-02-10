#!/bin/sh

DOMAINS=domain-domain1.de,*.domain-domain1.de,domain-domain2.de,*.domain-domain2.de,domain-domain3.de,*.domain-domain3.de
WEBPACK_ID=12341234

echo "Issuing certs for domains: $DOMAINS"
echo "Webpack ID: $WEBPACK_ID"

# Generate DNS Challenges and upload the answers to KIS (required to generate a fresh certificate):
./bin/console acme:init -d "$DOMAINS"

# Verify DNS challenges and generate a new certificate: 
./bin/console acme:verify

# Upload certificate: 
./bin/console kis:upload --webpack-id=$WEBPACK_ID --vhosts="*"