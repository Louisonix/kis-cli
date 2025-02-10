
window.hasImageElements = function() {
    var images = document.getElementsByTagName('img');
    return images.length > 0;
}

window.deleteNextImageElement = function() {
    var images = document.getElementsByTagName('img');
    if (images.length > 0) {
        images[0].remove();
    }
}

window.he_findDomainTable = function he_findDomainTable(cell) {

    var tables = document.querySelectorAll('table');
    
    for (let i=0; i<tables.length; i++) {
        if (tables[i].rows[0].cells[0].innerText.toUpperCase() == 'DOMAIN') {
            return tables[i];
        }
    }

    return null;
};

window.he_readWebpacksTable = function he_readWebpacksTable() {
    
        let webPackTable = document.querySelector('.fl_welcome > table');
    
        let result = window._wd_table_to_array(webPackTable, 
            {0: 'contract_id', 1: 'product', 3: 'domains'}, 1, false);
        
        if (result && result.length > 0) {
    
            for (let r=0; r<result.length; r++) {
                result[r]['index'] = r;
                if(result[r]['domains'] && result[r]['domains'].innerText) {
                    result[r]['domains'] = result[r]['domains'].innerText.split('\n');
                }
            }
        }
    
        return result;
    
}

window.he_readContractOverviewTable = function he_readContractOverviewTable() {

    let contractTable = document.querySelector("#contract-overview-save-changes-form > table");

    let result = window._wd_table_to_array(contractTable, 
        {0: 'checkbox', 1: 'contract_id', 3: 'product', 5: 'mailbox', 6: 'ipv4', 7: 'contract_term', 8: 'billing_interval', 9: 'billing_amount', 10: 'changeable_until'}, 1, false);
    
    if (result && result.length > 0) {

        for (let r=0; r<result.length; r++) {
            result[r]['index'] = r;
        }
    }

    return result;
}

window.he_readDnsRecordTable = function he_readDnsRecordTable() {
    
    let dnsTab = window._wd_find_element_by('DNS-Einträge');
    let result = window._wd_table_to_array(dnsTab, 
        {0: 'domain', 1: 'type', 2: 'value', 3: 'ttl'}, 1);

    if (result && result.length > 0) {

        for (let r=1; r<result.length; r++) {
            result[r-1]['index'] = r;
        }

        // remove last row (the add row):
        result.splice(result.length-1, 1);

        // remove the first row: 
        // result.splice(0, 1);

        for (let r=0; r<result.length; r++) {
            result[r]['domain'] = result[r]['domain'].split('\n')[0];

            try{
                result[r]['hostid'] = dnsTab.rows[result[r]['index']].querySelector('input[name="hostid"]').value;
            } catch(ex) {

            }
            
            // Weitere parsbare attribute die zur Verfügung stehen: 
            /* 
            <input type="hidden" name="mode" value="autodns">
            <input type="hidden" name="domain" value="some-domain.com">
            <input type="hidden" name="submode" value="edit">
            <input type="hidden" name="truemode" value="host">
            <input type="hidden" name="action" value="save_ttl">
            <input type="hidden" name="hostid" value="12345"> 
            */
        }
    }

    return result;
}

/**
 * Parses the domain table @ https://kis.hosteurope.de/administration/domainservices/index.php?menu=2&mode=autodns
 * @returns 
 */
window.he_parseDomainTable = function() {


    let dnsEntries = [];
    let table = window.he_findDomainTable();
    
    if (table) {
        for (let i=1; i<table.rows.length-1; i++) {
            try {
                let cell = table.rows[i].cells[0];
                let domain = cell.innerText.split("\n")[0];
                let pdns =  table.rows[i].cells[1].innerText;
                let sdns =  table.rows[i].cells[2].innerText;
                
                if (domain) {
                    
                    let entry = {
                        index: i,
                        domain: domain,
                        pdns: pdns,
                        sdns: sdns
                    };

                    dnsEntries.push(entry);
                }
            } catch(e) {
                //console.error(e);
            }
        }
    } else {
        throw new Error('DNS table was not found!');
    }

    window._wd_set_param('dnsEntries', dnsEntries);

    return dnsEntries;
}

window.he_readSSLEndpoints = function() {
    let result = [];
    let wpid = document.querySelector('form[name="vhosts"]').querySelectorAll('input[name="wp_id"]');
    let table = document.querySelector('form[name="vhosts"]').querySelectorAll('table')[1];
    if (table) {
        for (let i=1; i<table.rows.length; i++) {
            let row = table.rows[i];
            let domains = row.cells[1].innerText;
            if (row.querySelector && row.querySelector('.hiddenDomains')) {
                domains += row.querySelector('.hiddenDomains').innerText;
            }
            domains = domains.split('\n').map(d => d.trim());
            domains = domains.filter(d => d.length > 0);
            
            let links = row.cells[4].querySelectorAll('a');
            let vid = 'default';
            if (links.length > 0) {
               let link = links[0].href;
               let params = new URLSearchParams(link.split('?')[1]);
               
               if (params.has('v_id') && params.has('wp_id')) {
                vid = params.get('v_id');
               } 
               wpid = params.get('wp_id');
            }

            let item = {
                index: i,
                domains: domains,
                vid: vid,
                wpid: wpid
            };
            result.push(item);
        }
    }

    return result;
}


window.he_throwErrorOnFailedLogin = function() {
    
    if (document.querySelector('#mainMenu .fl_customerInfo') == null 
        || document.querySelector('#mainMenu .fl_customerInfo').innerText.indexOf('Sie sind angemeldet:') == -1) {
            throw new Error('Login failed');
    }

    return document.querySelector('#mainMenu .fl_customerInfo').innerText;
}

window.he_findDeleteDnsRecordLink = function() {
    
    var links = document.querySelectorAll('a.myButton');
    var yesLink = null;
    var result = "";

    result += document.location.href;
    for (let i=0; i<links.length; i++) {
        result += links[i].href + " (" + links[i].innerText + ")\n";
        if (links[i].innerText.trim().indexOf('ja') === 0) {
            yesLink = links[i];
        }
    }

    if (yesLink != null) {
        return yesLink.href;
    }

    return null;
}

window.he_followDeleteDnsRecordLink = function() {
    
    var links = document.querySelectorAll('a.myButton');
    var yesLink = null;

    for (let i=0; i<links.length; i++) {
        if (links[i].innerText.trim().indexOf('ja') == 0) {
            yesLink = links[i];
        }
    }

    if (yesLink != null) {
        document.location = yesLink.href;
    }
}