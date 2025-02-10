/**
 * This file is part of the kis-cli package.
 *
 * (c) Ole Loots <ole@monochrom.net>
 *
 * For the full license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if(typeof window._wd_params === 'undefined') {
    window._wd_params = {};
}

if(typeof window._wd_env === 'undefined') {
    window._wd_env = {};
}

/**
 * Interface to read-only environment variables
 * @param {*} key 
 * @param {*} value 
*/

window._wd_get_env = function _wd_get_env(key) {
    return window._wd_env[key];
}

/**
 * Interface to dynamic parameters which are carried across page navigations
 * @param {*} key 
 * @param {*} value 
 */
window._wd_set_param = function _wd_set_param(key, value) {
    window._wd_params[key] = value;
}

window._wd_get_param = function _wd_get_param(key) {
    return window._wd_params[key];
}

window._wd_set_result = function _wd_set_result(result) {
    window._wd_params['_wd_result'] = result;
}

window._wd_get_result = function _wd_get_result() {
    return window._wd_params['_wd_result'];
}

window._wd_set_store = function _wd_set_store(store) {   
    window._wd_params['_wd_store'] = store;
}

window._wd_get_store = function _wd_get_store() {
    return window._wd_params['_wd_store'];
}

/**
 * Interace to persistent storage, these parameters are stored in 
 * @param {*} key 
 * @param {*} value 
 */
window._wd_store_set = function _wd_store_set(key, value) {

    $store = window._wd_get_store();
    $store[key] = value;
    window._wd_set_store($store);

    return (window._wd_store_get(key) == value);
    /*(if(typeof window._wd_store === 'undefined') {
        window._wd_store = [];
    }

    window._wd_store[key] = value;

    //throw new Error( key + " = " + typeof value);
    return (window._wd_store[key] == value);*/
};

window._wd_store_get = function _wd_store_get(key, defaultValue) {

    let store = window._wd_get_store();

    let val = store[key];

    if(val == undefined) {
        val = defaultValue;
    }
    /*
    if (store && store[key]) {
        store = store[key];
    } 
    */

    
    return val;
};

window._wd_find_element_by = function _wd_find_element_by(title, titleSelector, tagName) {

    if (!titleSelector) {
        titleSelector = '.titleline';
    }

    if (!tagName) {
        tagName = 'TABLE';
    }

    //let title = document.getElementsByClassName('titleline');
    let titles = document.querySelectorAll(titleSelector); 

    for (let i=0; i<titles.length; i++) {
        if (titles[i].innerText == title) {
            let maybeTheTable = titles[i].nextElementSibling;
            for (let x = 0; x<9 && maybeTheTable != null; x++) {
                if (maybeTheTable.tagName == tagName) {
                    return maybeTheTable;
                }
                maybeTheTable = maybeTheTable.nextElementSibling;
            }
            /*
            let maybeTheTable = titles[i].nextElementSibling;
            if (maybeTheTable.tagName == 'TABLE') {
                return maybeTheTable;
            }*/
        }
    }

    return null;
}

window._wd_table_to_array = function _wd_table_to_array(table, convertIndexToTitles, skipFirstRows, withCellReferences) {

    if (typeof table == 'string') {
        table = document.querySelector(table);
    }

    var dataArray = [];

    if (skipFirstRows == undefined || skipFirstRows == null) { 
        skipFirstRows = 0;
    }

    // Iterate over rows
    for (var i = skipFirstRows; i < table.rows.length; i++) {

        var row = table.rows[i];
        if (convertIndexToTitles) {
            var rowArray = {};
        } else {
            var rowArray = [];
        }
        

        // Iterate over cells
        for (var j = 0; j < row.cells.length; j++) {
            var cell = row.cells[j];
            var cellValue = null;
            
            var hasInput = cell.querySelector('input, select');
            if (hasInput && hasInput.tagName == 'INPUT') {
                cellValue = hasInput.value;
            } 
            else if (hasInput && hasInput.tagName == 'SELECT') {
                cellValue = hasInput.selectedOptions[0].label;
            } 
            else {
                cellValue = cell.innerText;
            }

            if (withCellReferences) {
                cellValue = ({cell: cell, cellIndex: j, valueParsed: cellValue});
            } 

            if (convertIndexToTitles) {
                if (convertIndexToTitles[j] != undefined) {
                    rowArray[convertIndexToTitles[j]] = cellValue
                } else {
                    rowArray[j] = cellValue;
                }
            } else {
                rowArray.push(cellValue);
            }            
        }

        // Push row array to data array
        dataArray.push(rowArray);
    }

    return dataArray;
}


// retrieve result via: var _wd_tmp_download_result = null; await window._wd_fetch_url (null, null).then(result => {_wd_tmp_download_result=result;}); return _wd_tmp_download_result;
window._wd_fetch_url = async function _wd_fetch_url_intern(url, options) {
    var _wd_download_as_text = null;
    await fetch(url)
    .then(response => response.text()) 
        .then(csvString => {
            _wd_download_as_text = csvString;
            //Split the csv into rows
            //const rows = csvString.split('\n');
            //for (row of rows) {
            ////Split the row into each of the comma separated values
            //    console.log(row.split(","));
            //}
        });

    return _wd_download_as_text;
};
