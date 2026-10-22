import domJSON from './domJSON.js';
import textHighlighter from './TextHighlighter.js';

export default class sosView {

    //file-directory tree vars
    dataCols  = ['perms','owner','group','size','date','time', 'name'];
    userInfo  = null;      //this document owner
    caseID    = null;      //this document case
    caseID2   = null;      //second case for file compare
    vaultID   = null;      //this document vault
    dirID     = null;      //this document directry
    sme             = 0;     //shared mode enabled (0=owner, 1=shared, 2=shared vault already open)
    csrfToken       = null;
    isImpersonating = false; //set true by sidebar when admin is impersonating a user

    //sos browser variables
    leafs = 0;
    selectedNodes = [];
    selectedPaths = [];
    openSections = [];
    directoryContents = null;
    trajectoryIDS = [];
    canDownload = false;
    canCompare = true;
    breadcrumbs = [];

    //file search variables
    nextFileLocked = false;
    nodesFound = [];
    nFindex = 0;
    dataList   = [];

    //file browser variables
    wrapLines = true;

    //search box variables
    searchIndex=-1;
    matches = [];
    findHltr = null;

    //notes variables
    fileMetaData;
    contentsOffset;
    dragOffsetX = 0;
    dragOffsetY = 0;

    //highlight variables
    highlightEnabled;

    //fileCompare
    mode = 'full';
    leftText = '';
    rightText = '';

    /*
    fileMetaData: {
        "id": 4,
        "vault_id": 2,
        "dir_id": 14,
        "file_id": 1423,
        "title": "netstat_-W_-neopa 2024-01-18 15:10:35",
        "status": "PRIVATE",
        "locked": 0,
        "owner": 2,
        "group": 2,
        "perms": "750",
        "plan_id": "0",
        "role_id": 2,
        "expire": "2026-03-26 18:05:04",
        "url": "https://svault.com/sosShared/asdajdad",
        "created_at": "2024-04-25T07:34:32.000000Z",
        "updated_at": "2024-04-25T07:35:04.000000Z",
    }
    */

///////////////////////////////////////////////////////////////////////////////////////////////////////////
// SosReportBrowser
///////////////////////////////////////////////////////////////////////////////////////////////////////////

    bookmarkProcessor(name, path) {
        path = (path === '/') ? '' : path;
        const node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'name', name, 'path', path);

        // Bookmarks are case-independent, so a bookmarked file may not exist in
        // the case currently open. Notify and bail out instead of dereferencing
        // a null node.
        if(!node) {
            new FilamentNotification().title('File not found: ' + name).icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
            return;
        }

        window.sosViewer.openPath2File(node.id, true);
    }

    growDirectory(data, id, cid, vid, did, mode, cid2 = '', csrft = ''){
        if(csrft) {
            window.sosViewer.csrfToken = csrft;
        }

        if(cid) {
            window.sosViewer.caseID = cid;
            sessionStorage.setItem('cid', cid);
        }

        if(cid2) {
            window.sosViewer.caseID2 = cid2;
            sessionStorage.setItem('cid2', cid2);
        }

        if(vid) {
            window.sosViewer.vaultID = vid;
            sessionStorage.setItem('vid', vid);
        }

        if(did) {
            window.sosViewer.dirID = did;
            sessionStorage.setItem('did', did);
        }

        if(window.sosViewer.userInfo == null) {
            window.sosViewer.getUserInfo();
        }

        if(mode == null) {
            mode = 'full';
        }
        window.sosViewer.mode = mode;

        const cdataDecoded = atob(data.replace(/==/,''));
        const contents = JSON.parse(cdataDecoded);

        if(mode == 'full') {
            window.sosViewer.dataCols  = ['perms','owner','group','size','date','time','name'];
        } else {
            window.sosViewer.dataCols  = ['size','date','time','name'];
        }

        // add SOS Browser tab to the tabControl structure
        window.sosViewer.addTab(document.title);

        //catch enter key for file searches
        document.getElementById('searchFileTerm').addEventListener('keypress', event => {
            if (event.key === "Enter") {
                event.preventDefault();
                window.sosViewer.toggleSearchFile(event);
            }
        });

        window.sosViewer.directoryContents = contents ;

        if(contents.nodes && contents.nodes.length > 0){
            document.getElementById(id).innerHTML = window.sosViewer.growBranch(contents.nodes, 0, mode);

            //open the root node
            const nodes = document.getElementsByClassName('summaryIcon-99999999');
            if(nodes) {
                Array.from(nodes).forEach( (target, index) => {
                    if((mode == 'full' && index == 0) || (mode == 'compareRight' && index == 0)) {
                        target.click();
                        return;
                    }
                })
            }

            window.dispatchEvent(new CustomEvent('case-selection-done'));
        }
    }

    growBranch(contents, level = 0, mode){
        const withCheck = false;
        let html='';
        if(contents){
            if(contents.constructor === Array){
                contents.forEach( node => {
                    if(node.nodes){
                        node.nodes.sort(window.sosViewer.sortByName).sort(window.sosViewer.sortByType);

                        let bgCommon = 'bg-zinc-100/50 dark:bg-zinc-800/50';
                        let iconCommon = 'text-primary-700 hover:text-primary-400';

                        let bgDiff = 'bg-warning-100/50 dark:bg-warning-800/50';
                        let iconDiff = 'text-warinng-700 hover:text-warinng-400';

                        let bgMiss = 'bg-danger-100/50 dark:bg-danger-800/50';
                        let iconMiss = 'text-danger-700 hover:text-danger-400';

                        let bgFound = 'bg-success-100/50 dark:bg-success-800/50';
                        let iconFound = 'text-success-700 hover:text-success-400';

                        let background = bgCommon;
                        let iconcolor  = iconCommon;

                        if(node.__status) {
                            switch(node.__status) {
                                case 'different':
                                    background = bgDiff;
                                    iconcolor = iconDiff;
                                break;
                                case 'missing_right':
                                    background = (mode == 'compareRight') ? bgMiss : bgFound;
                                    iconcolor  = (mode == 'compareRight') ? iconMiss : iconFound;
                                break;
                                case 'missing_left':
                                    background = (mode == 'compareRight') ? bgFound : bgMiss;
                                    iconcolor  = (mode == 'compareRight') ? iconFound : iconMiss;
                                break;
                                default:
                                    background = bgCommon;
                                    iconcolor  = iconCommon;
                                break;
                            }
                        }

                        // add a header...
                        if(level == 0) {
                            html += '<header class="h-12 flex flex-row justify-between py-2 w-auto min-w-[90%] max-w-full dark:border-zinc-500 text-gray-800 dark:text-gray-100 ">';

                                html += '<div class="w-6"></div>';
                                html += '<table class="pl-2 justify-self-stretch table-auto border-separate border-spacing-2 ">';
                                    html += '<thead">';
                                        html += '<tr>';

                                            if(withCheck) {
                                                html += '<td><input type=checkbox class="mr-2" id="chk_box_all"></td>';
                                            }

                                            window.sosViewer.dataCols.forEach( key => {
                                                let width = (key == "Name") ? "flex flex-row justify-start pl-8 w-80 max-w-96 justify-self-stretch" : "w-24";
                                                html += '<th class="' + width + ' truncate" >' + key + '</th>';
                                            });
                                        html += '</tr>';
                                    html += '</thead>';
                                html += '</table>';

                                html += '<div class="flex grow w-auto"></div>';

                                html += '<table class="justify-self-end table-auto w-16 mr-4">';
                                    html += '<thead>';
                                        html += '<tr>';
                                            html += '<th>Actions</th>';
                                        html += '</tr>';
                                    html += '</thead>';
                                html += '</table>';
                            html += '</header>';
                        }

                        let dataDiv = '';

                        // folder icon
                        dataDiv += '<i id="summaryIcon-' + node.id + '" ';
                        dataDiv += 'class="font-normal ' + iconcolor + ' ph-duotone ph-folder-plus text-2xl mt-4 summaryIcon-' + node.id + ' " ';
                            if(level < 3) {
                                dataDiv += 'x-tooltip.raw="Open Folder" ';
                            }

                            dataDiv += 'onclick=window.sosViewer.toggleSummary(event,"' + mode + '") ';
                        dataDiv += '></i>';

                        // row
                        dataDiv += '<div class="flex flex-row justify-between py-3 w-full min-w-[90%] max-w-full border-b-1 border-gray-100 dark:border-gray-500 text-gray-800 dark:text-gray-100">';

                            dataDiv += '<table class="pl-2 justify-self-stretch table-auto border-separate border-spacing-2">';
                                dataDiv += '<tr id="label-' + node.id + '" >';
                                    if(withCheck) {
                                        dataDiv += '<td><input type=checkbox class="mr-2" id="chk_box_' + node.id + '" ></td>';
                                    }

                                    window.sosViewer.dataCols.forEach( key => {
                                        let field  = (key == "size") ? window.sosViewer.toHuman(node[key]) : node[key];
                                        if (key == "name" && node['id'] == '99999999') {
                                            field = '/';
                                        }
                                        let width = (key == "name") ? "w-80 max-w-96 justify-self-stretch font-semibold" : "w-24 font-light";
                                        dataDiv += '<td class="' + width + ' pointer-events-none truncate" >' + field + '</td>';
                                    });

                                dataDiv += '</tr>';
                            dataDiv += '</table>';

                            if(level > 0) {
                                dataDiv += '<table class="justify-self-end table-auto w-16 mr-1">';

                                    dataDiv += '<tr>';
                                        // add to fileList icon
                                        dataDiv += '<td>';
                                            dataDiv += '<div class="w-6"> </div>';
                                            /*
                                            //bad idea to add directories to file lists
                                            dataDiv += '<i id="fileListIcon-' + node.id + '" ';
                                                dataDiv += 'class="font-normal text-primary-700 hover:text-primary-400 ph-duotone ph-list-heart text-2xl" ';
                                                if(level < 3) {
                                                    dataDiv += 'x-tooltip.raw="Add to File List" ';
                                                }
                                                dataDiv += 'onclick=window.sosViewer.addFileList(event) ';
                                            dataDiv += '></i>';
                                            */
                                        dataDiv += '</td>';

                                        // add to bookmark icon
                                        dataDiv += '<td><i id="bookmarkIcon-' + node.id + '" ';
                                        dataDiv += 'class="font-normal ' + iconcolor + ' ph-duotone ph-bookmarks text-2xl" ';
                                        if(level < 3) {
                                            dataDiv += 'x-tooltip.raw="Add to Bookmarks" ';
                                        }
                                        dataDiv += 'onclick=window.sosViewer.addBookmark(event) ';
                                        dataDiv += '></i></td>';
                                    dataDiv += '</tr>';
                                dataDiv += '</table>';
                            }

                        dataDiv += '</div>';

                        html += '<ul class="w-auto min-w-[90%] max-w-full border-0 border-l-1 border-gray-200 dark:border-zinc-500 rounded-lg ">';
                            html += '<details class="w-auto min-w-[90%] max-w-full rounded-lg">';
                                html += '<summary open=false id="' + node.id + '" ';
                                html += 'class="flex ml-2 mr-0 my-0 py-0 px-2 pr-0 w-[99%] max-w-full text-primary-700 hover:text-primary-400 ' + background + ' rounded-lg border-0 border-warning-400" >';

                                    html += dataDiv;

                                html += '</summary>';
                                html += '<div class="w-auto min-w-[90%] max-w-full ml-6">';
                                    html += window.sosViewer.growBranch(node.nodes, level+1, mode);
                                html += '</div>';
                            html += '</details>';
                        html += '</ul>';
                    }else{
                        html += window.sosViewer.growLeaf(node, level+1, withCheck, mode);
                    }
                });
            }
        }
        return(html);
    }

    growLeaf(data, level, withCheck, mode){
        let dataDiv = '';

        // background color definitions
        let bgCommmonLight = 'bg-zinc-200/50 dark:bg-zinc-700/50 hover:bg-zinc-300/90';
        let bgCommmonDark  = 'bg-zinc-300/50 dark:bg-zinc-600/70 hover:bg-zinc-500/70';
        let iconCommon = 'text-zinc-900 dark:text-zinc-100 hover:text-zinc-500 hover:dark:text-zinc-700';

        let bgDiffLight='bg-warning-100/70 hover:bg-warning-100/40 dark:bg-warning-200/80 hover:dark:bg-warning-200/40';
        let bgDiffDark='bg-warning-300/70 hover:bg-warning-300/40 dark:bg-warning-300/80 hover:dark:bg-warning-300/40';
        let iconDiff = 'text-warning-700 dark:text-warning-700 hover:text-warning-500 hover:dark:text-warning-400';

        let bgMissLight = 'bg-danger-200/50 hover:bg-danger-300/90 dark:bg-danger-500/50 hover:dark:bg-danger-200/80';
        let bgMissDark  = 'bg-danger-300/50 dark:bg-danger-400/70 hover:bg-danger-500/70';
        let iconMiss = 'text-danger-900 dark:text-danger-200 hover:text-danger-500 hover:dark:text-danger-700';

        let bgFoundLight = 'bg-success-200/50 dark:bg-success-700/50 hover:bg-success-300/90';
        let bgFoundDark  = 'bg-success-300/50 dark:bg-success-600/70 hover:bg-success-500/70';
        let iconFound = 'text-success-900 dark:text-success-200 hover:text-success-500 hover:dark:text-success-700';

        let backgroundLight = bgCommmonLight;
        let backgroundDark  = bgCommmonDark;
        let iconcolor = iconCommon ;

        if(data.__status) {
            switch(data.__status) {
                case 'different':
                    backgroundLight = bgDiffLight;
                    backgroundDark  = bgDiffDark;
                    iconcolor = iconDiff;
                break;
                case 'missing_right':
                    backgroundLight = (mode == 'compareRight') ? bgMissLight : bgFoundLight;
                    backgroundDark  = (mode == 'compareRight') ? bgMissDark : bgFoundDark;
                    iconcolor       = (mode == 'compareRight') ? iconMiss : iconFound;
                break;
                case 'missing_left':
                    backgroundLight = (mode == 'compareRight') ? bgFoundLight : bgMissLight;
                    backgroundDark  = (mode == 'compareRight') ? bgFoundDark : bgMissDark
                    iconcolor       = (mode == 'compareRight') ? iconFound : iconMiss
                break;
                default:
                    backgroundLight = bgCommmonLight;
                    backgroundDark  = bgCommmonDark;
                    iconcolor = iconCommon ;
                break;
            }
        }

        dataDiv += '<div class="flex flex-row justify-between h-10 w-full min-w-[93%] max-w-full border-b-1 border-gray-100 dark:border-gray-500">';

            dataDiv += '<table class="justify-self-stretch table-auto border-separate border-spacing-2">';
                dataDiv += '<tr id="' + data.id + '" data-serial="' + window.sosViewer.leafs++ + '" data-path="' + data.path + data.name + '" data.id="' + data.id + '" >';

                    // file icon
                    dataDiv += '<td><i class="ph-duotone ph-file text-2xl font-light mr-2 ' + iconcolor + ' " ';

                    if(level < 4) {
                        dataDiv += 'x-tooltip.raw="Show File Contents" ';
                    }

                    if(mode == 'full') {
                        dataDiv += 'onclick=window.sosViewer.sosGetFileContents(' + data.id + ') ';
                    } else {
                        dataDiv += 'onclick=window.sosViewer.sosGetFileCompare(' + data.id + ') ';
                    }

                    dataDiv += ' ></i></td>';

                    if(withCheck) {
                        dataDiv += '<td><input type=checkbox class="ml-1 mr-2" id="chk_box_' + data.id + '" ></td>';
                    }

                    window.sosViewer.dataCols.forEach( key => {
                        let field = (key == 'size') ? window.sosViewer.toHuman(data[key]) : data[key];
                        let color = (data['size'] == 0) ? " text-warning-400 " : "";
                        let tooltip = (mode != 'full' && key == 'name') ? 'x-tooltip.raw="' + field + '" ' : '';
                        let width;
                        if(mode == 'full') {
                            width = (key == 'name') ? 'w-80 max-w-96 justify-self-stretch font-semibold' : 'w-24 font-light';
                        } else {
                            width = (key == 'name') ? 'w-40 max-w-40 flex-1 truncate font-semibold' : 'w-24 font-light';
                        }

                        if(key == "name") {
                            let click;
                            if(mode == 'full') {
                                click = 'onclick=window.sosViewer.sosGetFileContents(' + data.id + ') ';
                            } else {
                                click = 'onclick=window.sosViewer.sosGetFileCompare(' + data.id + ') ';
                            }
                            field = '<div ' + click + ' ' + tooltip + '>' + field + '</div>';
                            dataDiv += '<td class="' + width + color + ' truncate" >' + field + '</td>';
                        } else {
                            dataDiv += '<td class="' + width + color + ' pointer-events-none truncate" >' + field + '</td>';
                        }
                    });

                dataDiv += '</tr>';
            dataDiv += '</table>';

            dataDiv += '<table class="justify-self-end table-auto w-24">';

                // download icon
                if(data['size'] > 0 && window.sosViewer.canDownload){
                    dataDiv += '<td><i id="downloadIcon-' + data.id + '" ';
                    dataDiv += 'class="font-normal ' + iconcolor + ' ph-duotone ph-download-simple text-2xl" ';
                    if(level < 5) {
                        dataDiv += 'x-tooltip.raw="Download File" ';
                    }
                    dataDiv += 'onclick=window.sosViewer.downloadFile(event) ';
                    dataDiv += '></i></td>';
                }

                // compare icon
                if(window.sosViewer.canCompare && mode == 'full') {
                    dataDiv += '<td><i id="compareIcon-' + data.id + '" ';
                    dataDiv += 'class="font-normal ' + iconcolor + ' ph-duotone ph-git-diff text-2xl" ';
                    if(level < 5) {
                        dataDiv += 'x-tooltip.raw="Compare File" ';
                    }
                    dataDiv += 'onclick=window.sosViewer.sosGetFileCompare(' + data.id + ') ';
                    dataDiv += '></i></td>';
                }

                // add to fileList icon
                dataDiv += '<td><i id="fileListIcon-' + data.id + '" ';
                dataDiv += 'class="font-normal ' + iconcolor + ' ph-duotone ph-list-heart text-2xl" ';
                if(level < 5) {
                    dataDiv += 'x-tooltip.raw="Add to File List" ';
                }
                dataDiv += 'onclick=window.sosViewer.addFileList(event) ';
                dataDiv += '></i></td>';

                // add to bookmark icon
                dataDiv += '<td><i id="bookmarkIcon-' + data.id + '" ';
                dataDiv += 'class="font-normal ' + iconcolor + ' ph-duotone ph-bookmarks text-2xl" ';
                if(level < 5) {
                    dataDiv += 'x-tooltip.raw="Add to Bookmarks" ';
                }
                dataDiv += 'onclick=window.sosViewer.addBookmark(event) ';
                dataDiv += '></i></td>';

            dataDiv += '</table>';

        dataDiv += '</div>';

        let trclass = (window.sosViewer.leafs % 2) ? backgroundLight : backgroundDark;

        let html = '<li id="li-' + data.id + '" data-path="' + data.path + data.name + '" data-id='+ data.id + ' ';
        html += 'class="border-0 border-warning-400 rounded-lg flex m-1 py-1 ' + trclass + ' "';
        if(withCheck) {
            html += 'onclick=window.sosViewer.selectFile(event) ';
        }
        html += ' >';
            html += dataDiv;
        html += '</li>';

        return(html);
    }

    filelistRefresh() {
        const elem = document.getElementById('fileListRefresh');
        if(elem) {
            elem.click();
        }
    }

    checkIfPopupsAllowed() {
        // Attempt to open a 1x1 invisible window
        var testPop = window.open('', '_blank', 'width=1,height=1');

        if (!testPop || testPop.closed || typeof testPop.closed == 'undefined') {
            // Popups are BLOCKED
            new FilamentNotification().title('To use File Lists, please allow pop-ups on this page.')
                .icon('phosphor-bell-ringing-duotone')
                .iconColor('warning')
                .persistent()
                .send();

            return false;
        } else {
            // Popups are ALLOWED
            testPop.close(); // Close the test window immediately
            return true;
        }
    }

    filelistProcessor(bookmarks) {

        const encoded = atob(bookmarks.replace(/==/,''));
        const files = JSON.parse(encoded);
        const wait = 3000;
        let n = 0;
        files.forEach( (record) => {
            if(record) {
                setTimeout(function() {
                    window.sosViewer.bookmarkProcessor(record.name, record.path);
                }, wait * n++);
            }
        });
    }

    addFileList(ev){
        ev.stopPropagation();
        ev.preventDefault();

        const id = ev.target.id.replace(/..*-/, '');
        let node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'id', id);

        if(!node) {
            new FilamentNotification().title('File missing...').icon('phosphor-bell-ringing-duotone').iconColor('error').send()
            return;
        }

        if(!node.name) {
            new FilamentNotification().title('Name missing...').icon('phosphor-bell-ringing-duotone').iconColor('error').send()
            return;
        }

        const path = (node.path === '' ? '/' : node.path);

        const icon = (node.type == 'd' ? 'phosphor-folder-duotone' : 'phosphor-file-duotone');

        //add the new bookmark
        const data = {
            'name': node.name,
            'fullpath': path,
            'filetype': node.type,
            'icon': icon
        };

        window.dispatchEvent(new CustomEvent('livewire:add-filelist', {detail: data}));
    }

    addBookmark(ev){
        ev.stopPropagation();
        ev.preventDefault();

        const id = ev.target.id.replace(/..*-/, '');
        let node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'id', id);

        if(!node) {
            new FilamentNotification().title('File missing...').icon('phosphor-bell-ringing-duotone').iconColor('error').send()
            return;
        }

        if(!node.name) {
            new FilamentNotification().title('Name missing...').icon('phosphor-bell-ringing-duotone').iconColor('error').send()
            return;
        }

        const path = (node.path === '' ? '/' : node.path);

        const icon = (node.type == 'd' ? 'phosphor-folder-duotone' : 'phosphor-file-duotone');

        //add the new bookmark
        const data = {
            'name': node.name,
            'fullpath': path,
            'filetype': node.type,
            'icon': icon
        };

        window.dispatchEvent(new CustomEvent('livewire:add-bookmark', {detail: data}));
    }

    downloadFile(ev){
        let node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'id', ev.target.id.replace(/downloadIcon-/,''));
        let uri  = location.pathname + '/api/v1/snaresupport/download?';
        uri += 'filename=/' + node.path + '/' + node.name;

        let link = document.createElement("a");
        link.setAttribute('download', node.name + '.txt');
        link.href = uri;
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    sortByType(node0, node1){
        if(!node0 || !node1) {
            return -1;
        }
        if(!node0.type || !node1.type) {
            return -1;
        }
        //directories first
        if(node0.type == 'd' && node1.type != 'd'){
            return(-1);
        }else if(node0.type != 'd' && node1.type == 'd'){
            return(1);
        }else{
            return(0);
        }
    }

    sortByName(node0, node1){
        if(!node0 || !node1) {
            return -1;
        }
        if(!node0.name || !node1.name) {
            return -1;
        }
        let n=50;
        let str0 = node0.name.substring(0, n);
        let str1 = node1.name.substring(0, n);
        return ( ( str0 == str1 ) ? 0 : (( str0 > str1 ) ? 1 : -1 ));
    }

    sortByImportant(node0, node1){
        if(node1.name.match(/SnareArchive/)){
            return(-1);
        }else if(node0.name.match(/Snare.*/)){
            return(-1);
        }else if(node1.name.match(/Snare.*/)){
            return(1);
        }else{
            return(0);
        }
    }

    toggleSummary(ev, mode){

        if(!ev.target.parentNode.id.match(/^\d{1,8}$/)) {
            ev.preventDefault();
            return;
        }

        // if symlink to a dir change the tree
        const node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'id', ev.target.parentNode.id);
        if(node.type == 'l' && node.realtype == 'd' && node.realpath) {
            const dirs = node.realpath.split('/');
            const name = dirs.pop();
            const path = dirs.join('/') + '/';
            const realnode = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'name', name, 'path', path);
            new FilamentNotification().title('Symnlink, switching to real path...').icon('phosphor-bell-ringing-duotone').iconColor('info').send();

            //need to close all open dirs first til root
            let id;
            while(id = window.sosViewer.breadcrumbs.pop()) {
                const target = document.getElementById('summaryIcon-' + id);
                if(target) {
                    target.click();
                }
            }

            window.sosViewer.openPath2File(realnode.id);
            return;
        }

        //open close summary-details tags on the tree
        if(!ev.target.parentNode.id){
            //prevents summary from been clicked
            ev.preventDefault();
            return;
        }else if(ev.target.id.match(/^chk_box_.*/)){
            //click on checkbox
            let summary = ev.target.parentNode.parentNode.parentNode;
            let details = summary.parentNode;
            if(!details.open && ev.target.checked){
                //show all selected files by opening details
                summary.className = 'ph-folder-open';
                summary.open=true;
                details.open=true;
            }
            selectFilesBelow(ev);
        }else{
            //click details arrow toggles open/close
            window.sosViewer.doToggleSummary(ev.target, mode);

            if(ev.target.parentNode.open){
                window.sosViewer.openSections.push(ev.target.parentNode.id);
            }else{
                let i=window.sosViewer.openSections.lastIndexOf(ev.target.parentNode.id);
                if(i > -1){
                    window.sosViewer.selectedNodes.splice(i,1);
                    window.sosViewer.selectedPaths.splice(i,1);
                    window.sosViewer.openSections.splice(i,1);
                }
            }
        }
    }

    doToggleSummary(target, mode){
        //click on summary icon toggles details open/close

        const elements = document.getElementsByClassName(target.id);
        const nodes = (mode == 'compareLeft') ? Array.from(elements) : Array.from(elements).reverse();
        if(nodes) {
            Array.from(nodes).forEach( (target, index) => {

                if(!target.parentNode.open) {
                    target.classList.replace('ph-folder-plus', 'ph-folder-open');
                    target.removeAttribute('x-tooltip.raw');
                    target.setAttribute('x-tooltip.raw','Close Folder');
                    target.parentNode.open = true;
                    if(index == 1) {
                        target.parentNode.parentNode.setAttribute('open');
                    }
                } else if(target.parentNode.open) {
                    target.classList.replace('ph-folder-open', 'ph-folder-plus');
                    target.removeAttribute('x-tooltip.raw');
                    target.setAttribute('x-tooltip.raw','Open Folder');
                    target.parentNode.open = false;
                    if(index == 1) {
                        target.parentNode.parentNode.removeAttribute('open');
                    }
                }

                if(index == 0 && target.parentNode.id != '99999999') {
                    if(target.parentNode.open) {
                        window.sosViewer.breadcrumbs.push(target.parentNode.id);
                    } else {
                        //need to close all open dirs til parent
                        if(window.sosViewer.breadcrumbs.indexOf(target.parentNode.id) != -1) {
                            let id;
                            while(id = window.sosViewer.breadcrumbs.pop()) {
                                if(id == target.parentNode.id) {
                                    break;
                                }

                                const elems = document.getElementsByClassName(target.id);
                                Array.from(elems).forEach( elem => {
                                    if(elem) {
                                        elem.click();
                                    }
                                });
                            }
                        }
                    }
                    window.sosViewer.populateBreadCrumbs(mode);
                }
            });
        }
    }

    clearBreadCrumbs() {
        document.getElementById('breadcrumbs').innerHTML = '';
        window.sosViewer.breadcrumbs = [];
    }

    populateBreadCrumbs(mode) {
        let innerHTML = '';
        window.sosViewer.breadcrumbs.forEach( id => {
            const node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'id', id);
            innerHTML += '<div id="bread-' + node.id + '" ';
            innerHTML += 'class="mr-1 hover:text-primary-400" ';
            innerHTML += 'onclick="window.sosViewer.closeDir(event,\'' + mode + '\')" ';
            innerHTML += '>';
            innerHTML +=  node.name;
            innerHTML += '<i class="ph-bold ph-caret-right"></i>';
            innerHTML += '</div>';
        });
        document.getElementById('breadcrumbs').innerHTML = innerHTML;
    }

    toggleSearchFile(ev) {
        // when enter in the search for a file entry is hit (searchFileTerm), this function gets executed
        let pathpart = null;
        let filename = ev.target.value;

        const wrapper = document.getElementById('searchBox');
        if(wrapper) {
            wrapper.classList.replace('border-1','border-0');
            wrapper.classList.replace('ring-0','ring-2');
        }

        if(ev.type == 'change') {
            //the change event happens only when a selection is made from the search input datalist
            //a selection looks like this: "123) file: /proc/9445/limits (8.1KB)"
            //we need to extract the filename and the path from the selection

            const temp = ev.target.value.replace(/^\d{1,5}\) (dir|file|link): \//, '');
            const parts = temp.split('/');
            filename = parts.pop().replace(/ \(..*\)$/, '');
            pathpart = parts.join('/');
            pathpart += '/';
        }

        if(filename && ((ev.type != 'change' && !window.sosViewer.nodesFound.length) || (ev.type == 'change' && window.sosViewer.nodesFound.length))) {
            //if there is no previous result and event is not change
            //if there is a previous result and event is change

            //some validation and sanitazion
            if(!filename.match(/^[0-9a-zA-Z_\[\]\-\.\+\*\\\/]{1,100}$/)) {
                new FilamentNotification().title('Invalid file name: ' + filename).icon('phosphor-bell-ringing-duotone').iconColor('waring').send()
                document.getElementById('searchFileTerm').value = '';
                return false;
            }

            //make the * at the beginning work as a metachar
            let expandedValue = filename.match(/^\*/) ? '.' : '';
            expandedValue += filename;

            //split on /
            let filepart = expandedValue;
            if(filename.match(/^.*\/.*$/)) {
                const temp = expandedValue.replace(/^\//, '');
                const parts = temp.split('/');
                filepart = parts.pop();
                if(!pathpart) {
                    pathpart = parts.join('/');
                    pathpart += '/';
                }
            }

            // remove highliting of previous selected search result file
            if(window.sosViewer.nodesFound[window.sosViewer.nFindex]) {
                const id = window.sosViewer.nodesFound[window.sosViewer.nFindex].id;
                const elem = document.getElementById('li-' + id);
                if(elem) {
                    elem.classList.replace('border-2', 'border-0');
                }
            }

            //initialize the search variables
            window.sosViewer.nodesFound = [];
            window.sosViewer.nFindex = 0;

            if(pathpart) {
                window.sosViewer.find_all_nodes_by_attr(window.sosViewer.directoryContents.nodes, 'name', filepart, 'path', pathpart);
            } else {
                window.sosViewer.find_all_nodes_by_attr(window.sosViewer.directoryContents.nodes, 'name', filepart);
            }

            if(window.sosViewer.nodesFound.length > 0 && window.sosViewer.nodesFound[window.sosViewer.nFindex]) {
                window.sosViewer.showFileFound(window.sosViewer.nodesFound[window.sosViewer.nFindex], window.sosViewer.nFindex, window.sosViewer.nodesFound.length, ev.type);
            } else {
                new FilamentNotification().title('no file ' + filename + ' found').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
                document.getElementById('searchFileTerm').value = '';
                return false;
            }
        }
    }

    toggleRingFile(ev) {
        const wrapper = document.getElementById('searchBoxFile');
        if(wrapper) {
            wrapper.classList.replace('ring-2','ring-0');
            wrapper.classList.replace('border-0','border-1');
        }
    }

    clearSearchFile() {
        document.getElementById('searchFileTerm').value = '';
        document.getElementById('matchesFile').innerHTML = '';
        document.getElementById('filesFound').innerHTML = '';
        window.sosViewer.nodesFound    = [];
        window.sosViewer.dataList      = [];
        window.sosViewer.nFindex       = 0;
        window.sosViewer.nextFileLocked = false;
    }

    searchNextFile() {
        if(!window.sosViewer.nextFileLocked) {
            window.sosViewer.nextFileLocked = true;
            if(window.sosViewer.nFindex == window.sosViewer.nodesFound.length - 1) {
                window.sosViewer.nFindex = -1;
            }

            // remove highliting of previous selected search result file
            const id = window.sosViewer.nodesFound[window.sosViewer.nFindex].id;
            const elem = document.getElementById('li-' + id);
            if(elem) {
                elem.classList.replace('border-2', 'border-0');
            }

            window.sosViewer.nFindex++;
            if(window.sosViewer.nodesFound.length > 0 && window.sosViewer.nodesFound[window.sosViewer.nFindex]) {
                window.sosViewer.showFileFound(window.sosViewer.nodesFound[window.sosViewer.nFindex], window.sosViewer.nFindex, window.sosViewer.nodesFound.length, 'click');
            } else {
                new FilamentNotification().title('File not found').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
                return false;
            }
        }
    }

    searchPrevFile() {
        if(!window.sosViewer.nextFileLocked) {
            window.sosViewer.nextFileLocked = true;
            if(window.sosViewer.nFindex == 0) {
                window.sosViewer.nFindex = window.sosViewer.nodesFound.length;
            }

            // remove highliting of previous selected search result file
            const id = window.sosViewer.nodesFound[window.sosViewer.nFindex].id;
            const elem = document.getElementById('li-' + id);
            if(elem) {
                elem.classList.replace('border-2', 'border-0');
            }

            window.sosViewer.nFindex--;
            if(window.sosViewer.nodesFound.length > 0 && window.sosViewer.nodesFound[window.sosViewer.nFindex]) {
                window.sosViewer.showFileFound(window.sosViewer.nodesFound[window.sosViewer.nFindex], window.sosViewer.nFindex, window.sosViewer.nodesFound.length, 'click');
            } else {
                new FilamentNotification().title('File not found').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
                return false;
            }
        }
    }

    showFileFound(realnode, index, max, type = null) {
        if(realnode) {
            if(type && type != 'change') {
                //this is a first search (click or enter) not a selection from the datalist
                //so using the serach results create the datalist for search input
                //only create a datalist if the search results has more than one element
                if(window.sosViewer.dataList.length == 0 && max > 1) {
                    let html = '';
                    window.sosViewer.nodesFound.forEach((file, i) => {
                        window.sosViewer.dataList.push(file);
                        let type = '';
                        switch(file.type) {
                            case '-':
                                type = 'file: /';
                            break;
                            case 'l':
                                type = 'link: /';
                            break;
                            case 'd':
                                type = 'dir: /';
                            break;
                            default:
                                type = 'file: /';
                            break;

                        }
                        html += '<option value="';
                        html += (i + 1) + ') ' + type + file.path + file.name;
                        html += ' (' + window.sosViewer.toHuman(file.size) + ')';
                        html += '"></option>';
                    });
                    document.getElementById('filesFound').innerHTML = html;
                }
            } else {
                //this is a selection (click) from the datalist
                if(window.sosViewer.dataList.length > 0) {
                    index = 0;
                    window.sosViewer.dataList.forEach((file, i) => {
                        if(file.path == realnode.path && file.name == realnode.name && file.type == realnode.type) {
                            index = i;
                            return;
                        }
                    });
                    max = window.sosViewer.dataList.length;

                    // remove highliting of previous selected search result file
                    const id = window.sosViewer.nodesFound[window.sosViewer.nFindex].id;
                    const elem = document.getElementById('li-' + id);
                    if(elem) {
                        elem.classList.replace('border-2', 'border-0');
                    }

                    //to make the prev and next buttons work
                    window.sosViewer.nFindex = index;
                    window.sosViewer.nodesFound = window.sosViewer.dataList;
                }
            }

            window.sosViewer.openPath2File(realnode.id, false);

            //clear the search term so the datalist is visible
            document.getElementById('searchFileTerm').value = '';

            //the files found legend
            document.getElementById('matchesFile').innerHTML = (index + 1) + ' / ' + max + ' files found';

            return;
        } else {
            new FilamentNotification().title('File not found').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
            return false;
        }
    }

    closeDir(ev, mode) {
        const id = ev.target.id.replace(/bread-/,'');
        let i = 0;
        while(window.sosViewer.breadcrumbs.indexOf(id) != -1 && i < 30) {
            const cid = window.sosViewer.breadcrumbs[window.sosViewer.breadcrumbs.length - 1];
            if(cid) {
                const target = document.getElementById('summaryIcon-' + cid);
                if(target) {
                    target.click();
                }
            }
            if(window.sosViewer.breadcrumbs.length == 1){
                //scroll to top
                const id = (mode == 'full') ? 'root' : 'root1';
                const root = document.getElementById(id);
                if(root) {
                    root.scrollTop = 0;
                    root.focus();
                }
            }
            i++;
        }
    }

    selectFile(ev){
        if(!ev.target.id){
            //prevents div from been clicked
            ev.preventDefault();
            return;
        }

        if(ev.type === 'click'){
            if(!ev.target.selected){
                ev.target.classList.replace('border-0', 'border-2');
                setTimeout(function() {
                    ev.target.classList.replace('border-2', 'border-0');
                }, 10000);
                if(window.sosViewer.selectedNodes.lastIndexOf(ev.target.getAttribute('data-id'))===-1){
                    window.sosViewer.selectedNodes.push(ev.target.getAttribute('data-id'));
                    window.sosViewer.selectedPaths.push(ev.target.getAttribute('data-path'));
                }
            }else{
                ev.target.className = ((ev.target.getAttribute('data-serial') % 2) == 0) ? 'row0' : 'row1';
                var i=window.sosViewer.selectedNodes.lastIndexOf(ev.target.getAttribute('data-id'));
                if(i > -1){
                    window.sosViewer.selectedNodes.splice(i,1);
                    window.sosViewer.selectedPaths.splice(i,1);
                }
            }
            ev.target.selected = !ev.target.selected;
            window.sosViewer.fileSelectorStatus();
        }
    }

    selectFilesBelow(ev, selected){
        //toggle recursive selection
        let summary;
        if(ev.target && ev.target.id.match(/^chk_box_.+/)){
            summary = ev.target.parentNode.parentNode.parentNode;
            selected = ev.target.checked;
        }else{
            summary = ev;
        }
        let details = summary.parentNode;
        let labels;

        let litags = Array.from(details.getElementsByTagName('li'));
        litags.forEach( element => {
            if(selected){
                element.selected=true;
                if(window.sosViewer.selectedNodes.indexOf(element.getAttribute('data-id'))==-1){
                    window.sosViewer.selectedNodes.push(element.getAttribute('data-id'));
                    window.sosViewer.selectedPaths.push(element.getAttribute('data-path'));
                }
                element.classList.replace('border-0', 'border-2');
                setTimeout(function() {
                    element.classList.replace('border-2', 'border-0');
                }, 10000);
            }else{
                element.selected=false;
                let j=window.sosViewer.selectedNodes.indexOf(element.getAttribute('data-id'));
                if(j > -1){
                    window.sosViewer.selectedNodes.splice(j,1);
                    window.sosViewer.selectedPaths.splice(j,1);
                }
                //change the class of the label not the li
                labels = Array.from(element.getElementsByTagName('label'));
                labels.forEach( label => {
                    label.className = ((label.getAttribute('data-serial') % 2) == 0) ? 'row0' : 'row1';
                    label.selected=false;
                });
            }
        });

        let tags = Array.from(details.getElementsByTagName('input'));
        tags.forEach( element => {
            if(selected){
                element.checked=true;
            }else{
                element.checked=false;
            }
        });

        window.sosViewer.fileSelectorStatus();
        return;
    }

    fileSelectorStatus(){
        //add size here
        let messg='';
        let dataUnit='';
        dataUnit = ' Files selected. ';
        if(window.sosViewer.selectedNodes.length > 0){
            messg += window.sosViewer.selectedNodes.length + dataUnit;

            //display the size
            let bksize = 0;
            if(window.sosViewer.directoryContents){
                window.sosViewer.selectedNodes.forEach( id => {
                    let node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'id', id);
                    bksize += parseInt(node.size);
                });
                messg += '(' + window.sosViewer.toHuman(bksize) + ')';
            }
        }else{
            messg += 'No' + dataUnit;
        }
        let d = document.getElementById('fileSelectorStatus');
        if(d) d.innerHTML=messg;
    }

    find_all_nodes_by_attr(tree, attr1, value1, attr2 = null, value2 = null) {
        let found = null;
        if(tree && tree.constructor === Array) {
            for(let i=0; i < tree.length; i++) {
                let node = tree[i];
                if(attr2 === null && value2 === null) {
                    const regex1 = new RegExp('^' + value1 + '$');
                    if(node.nodes && !node[attr1].match(regex1)) {
                        window.sosViewer.trajectoryIDS.push(node.id);
                        found = window.sosViewer.find_all_nodes_by_attr(node.nodes, attr1, value1);
                        if(!found) {
                            window.sosViewer.trajectoryIDS.pop();
                        } else {
                            window.sosViewer.trajectoryIDS = [];
                        }
                    }else if(node[attr1].match(regex1)) {
                        node.trajectory = window.sosViewer.trajectoryIDS;
                        found = node;
                        window.sosViewer.nodesFound.push(found);
                    }
                } else {
                    const regex1 = new RegExp('^' + value1 + '$');
                    const regex2 = new RegExp('^' + value2 + '$');
                    if(node.nodes && (!node[attr1].match(regex1) || !node[attr2].match(regex2))) {
                        window.sosViewer.trajectoryIDS.push(node.id);
                        found = window.sosViewer.find_all_nodes_by_attr(node.nodes, attr1, value1, attr2, value2);
                        if(!found) {
                            window.sosViewer.trajectoryIDS.pop();
                        } else {
                            window.sosViewer.trajectoryIDS = [];
                        }
                    }else if(node[attr1].match(regex1) && node[attr2].match(regex2)) {
                        node.trajectory = window.sosViewer.trajectoryIDS;
                        found = node;
                        window.sosViewer.nodesFound.push(found);
                    }
                }
            }
            return(window.sosViewer.nodesFound);
        }
    }

    find_node_by_attr(tree, attr1, value1, attr2 = null, value2 = null){
        let found = null;
        if(tree && tree.constructor === Array){
            for(let i=0; i < tree.length; i++){
                let node = tree[i];
                if(attr2 === null && value2 === null){
                    if(node.nodes && node[attr1] != value1){
                        window.sosViewer.trajectoryIDS.push(node.id);
                        found = window.sosViewer.find_node_by_attr(node.nodes, attr1, value1);
                        if(found){
                            break;
                        }else{
                            window.sosViewer.trajectoryIDS.pop();
                        }
                    }else if(node[attr1] == value1){
                        node.trajectory = window.sosViewer.trajectoryIDS;
                        window.sosViewer.trajectoryIDS = [];
                        found = node;
                        break;
                    }
                }else{
                    if(node.nodes && (node[attr1] != value1 || node[attr2] != value2)){
                        window.sosViewer.trajectoryIDS.push(node.id);
                        found = window.sosViewer.find_node_by_attr(node.nodes, attr1, value1, attr2, value2);
                        if(found){
                            break;
                        }else{
                            window.sosViewer.trajectoryIDS.pop();
                        }
                    }else if(node[attr1] == value1 && node[attr2] == value2){
                        node.trajectory = window.sosViewer.trajectoryIDS;
                        window.sosViewer.trajectoryIDS = [];
                        found = node;
                        break;
                    }
                }
            }
            return(found);
        }
    }

    openPath2File(id, doOpenFile = true){
        //const mode = window.sosViewer.mode;
        const mode = window.sosViewer.mode;
        //open the tree to the file specified by id
        const fileElement = document.getElementById(id);
        const node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'id', id);
        if(window.sosViewer.checkTab('V' + node.id)) {
            new FilamentNotification().title('File is already open').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
            return;
        }

        const directoryContents = window.sosViewer.directoryContents;

        if(node.type == 'l' && node.realtype == 'f' && node.realpath) {
            const dirs = node.realpath.split('/');
            const name = dirs.pop();
            const path = dirs.join('/') + '/';
            const realnode = window.sosViewer.find_node_by_attr(directoryContents.nodes, 'name', name, 'path', path);
            window.sosViewer.openPath2File(realnode.id, doOpenFile);
            return;
        }


        // highlight the file
        if(fileElement && node){
            if(node.type == '-' ){
                const elem = document.getElementById('li-' + id);
                if(elem) {
                    elem.classList.replace('border-0', 'border-2');
                    setTimeout(function() {
                        elem.classList.replace('border-2', 'border-0');
                    }, 10000);
                }
            }
            if(node.type == 'd' ){
                //summary
                const elem = document.getElementById(id);
                if(elem) {
                    elem.classList.replace('border-0', 'border-2');
                    setTimeout(function() {
                        elem.classList.replace('border-2', 'border-0');
                    }, 10000);
                }
            }
        }

        const tout = 400;

        //first determine if the file is in a directory that is alredy open
        let trajectory = node.trajectory;
        if(trajectory[0].match('99999999')) {
            trajectory.shift();
        }

        if(JSON.stringify(trajectory) !== JSON.stringify(window.sosViewer.breadcrumbs)) {

            if(node.trajectory){
                let i = 1;

                //need to close all open dirs til parent
                let id;
                while(id = window.sosViewer.breadcrumbs.pop()) {
                    const icon = document.getElementById('summaryIcon-' + id);
                    if(icon) {
                        icon.click();
                    }
                }

                //scroll to top
                const root = document.getElementById('root');
                root.scrollTop = 0;

                //now its ok to start opening dirs
                node.trajectory.forEach( dirid => {
                    setTimeout(function(){
                        //open the dir
                        const summary = document.getElementById(dirid);
                        if(summary) {
                            summary.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                            const icon = document.getElementById('summaryIcon-' + dirid);
                            if(icon && dirid != '99999999') {
                                icon.click();
                            }
                        }
                    }, tout * i);
                    i++;
                });

                // open the last one
                if(i > node.trajectory.length){
                    setTimeout(function(){
                        i++;
                        fileElement.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                        setTimeout(function(){
                            if(node && node.type == 'd'){
                                const icon = document.getElementById('summaryIcon-' + node.id);
                                if(icon && node.id != '99999999') {
                                    icon.click();
                                }
                            }

                            if(doOpenFile) {
                                if(node && node.type == '-'){
                                    if(mode == 'full') {
                                        window.sosViewer.sosGetFileContents(node.id);
                                    } else {
                                        window.sosViewer.sosGetFileCompare(node.id);
                                    }
                                }

                                if(node && node.type == 'l' && node.realtype == 'f'){
                                    if(mode == 'full') {
                                        window.sosViewer.sosGetFileContents(node.id);
                                    } else {
                                        window.sosViewer.sosGetFileCompare(node.id);
                                    }
                                }
                            }

                            // this prevents opening several searched files at the same time
                            window.sosViewer.nextFileLocked = false;

                        }, tout);
                    }, tout * i);
                }
            }
        } else {
            setTimeout(function(){
                fileElement.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                setTimeout(function(){
                    if(doOpenFile) {
                        if(node && node.type == '-'){
                            if(mode == 'full') {
                                window.sosViewer.sosGetFileContents(id);
                            } else {
                                window.sosViewer.sosGetFileCompare(id);
                            }
                        }

                        if(node && node.type == 'l' && node.realtype == 'f'){
                            if(mode == 'full') {
                                window.sosViewer.sosGetFileContents(id);
                            } else {
                                window.sosViewer.sosGetFileCompare(id);
                            }
                        }
                    }

                    // this prevents opening several searched files at the same time
                    window.sosViewer.nextFileLocked = false;

                }, tout);
            }, tout);
        }
    }

    sosGetFileContents(id){
        //GET selected file contents
        let node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'id', id);

        if(!node){
            new FilamentNotification().title('File not found').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
            return;
        }

        if(node.size == 0){
            new FilamentNotification().title('File is empty').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
            return;
        }

        if(node.type == 'l' && node.realtype == 'f' && node.realpath) {
            const fullpath = node.realpath.split('/');
            const name = fullpath.pop();
            const path = fullpath.join('/') + '/';
            node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'name', name, 'path', path);
        }

        let url = '/filebrowser/' + window.sosViewer.caseID + '/' + node.id;
        if (window.sosViewer.sme > 0) {
            url += '?sme=' + window.sosViewer.sme + '&vid=' + window.sosViewer.vaultID + '&did=' + window.sosViewer.dirID;
        }
        if(!window.sosViewer.checkTab('V' + node.id)) {
            new FilamentNotification().title('Retrieveing file contents').icon('phosphor-bell-ringing-duotone').iconColor('success').send()
            window.open(url, '_blank');
        } else {
            new FilamentNotification().title('File is already open').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
        }
    }

    sosGetFileCompare(id){
        //GET selected file contents
        let node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'id', id);

        if(!node){
            new FilamentNotification().title('File not found').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
            return;
        }

        if(node.size == 0){
            new FilamentNotification().title('File is empty').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
            return;
        }

        if(node.type == 'l' && node.realtype == 'f' && node.realpath) {
            const fullpath = node.realpath.split('/');
            const name = fullpath.pop();
            const path = fullpath.join('/') + '/';
            node = window.sosViewer.find_node_by_attr(window.sosViewer.directoryContents.nodes, 'name', name, 'path', path);
        }

        const toolName = 'FileCompare';
        let url = '/sosTool/' + window.sosViewer.vaultID + '/' + window.sosViewer.dirID + '/' + toolName + '/' + window.sosViewer.caseID + '/' + node.id;

        if(window.sosViewer.caseID2) {
            url += '?cid2=' + window.sosViewer.caseID2;
        }

        if(!window.sosViewer.checkTab('C' + node.id)) {
            new FilamentNotification().title('Comparing file contents').icon('phosphor-bell-ringing-duotone').iconColor('success').send()
            window.open(url, '_blank');
        } else {
            new FilamentNotification().title('File compare for this file is already open').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
        }
    }

    sosTool(event,toolName){
        event.preventDefault();
        let url = '/sosTool/' + window.sosViewer.vaultID + '/' + window.sosViewer.dirID + '/' + toolName + '/' + window.sosViewer.caseID;
        if(!window.sosViewer.checkTab(toolName)) {
            new FilamentNotification().title('Executing ' + toolName + ' tool...').icon('phosphor-bell-ringing-duotone').iconColor('info').send()
            window.open(url, '_blank');
        } else {
            new FilamentNotification().title(toolName + 'is already open').icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
        }
    }

    toHuman(size){
        let units = ['bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        let l = 0, n = parseInt(size, 10) || 0;
        while(n >= 1024 && ++l){
            n = n/1024;
        }
        return(n.toFixed(n < 10 && l > 0 ? 1 : 0) + ' ' + units[l]);
    }

    colapseHeaderDir(data) {
        // hack to adjust the size of the header to fit the current view size
        const elem = document.getElementById(data.id);
        if(elem) {
            let prev, next;
            if(data.id == 'root') {
                prev = data.collapsed ? 'mt-40' : 'mt-20';
                next = data.collapsed ? 'mt-20' : 'mt-40';
            }
            elem.classList.replace(prev, next);
        }
        return;
    }

    fixFileControlsSize() {
        // wire:ignore.self on the header prevents Livewire from removing this style.
        const tool = document.getElementById('file-controls-content');
        const main = document.getElementById('logfile1');

        if (!tool) {
            return;
        }

        // Derive the width from the sidebar state (localStorage) rather than
        // measuring getBoundingClientRect().left: #mainApp animates its
        // padding-left over 300ms and 'sidebar-toggled' fires before that class
        // swap, so a live measurement reads the old, mid-animation offset.
        // Sidebar 256px/96px expanded/collapsed + 20px inner padding + 20px
        // right margin = 296px / 136px. 100vw keeps the right border fixed on
        // window resize without re-running this.
        const collapsed = localStorage.getItem('sidebarColapsed') === 'true';
        const w = collapsed ? 'calc(100vw - 136px)' : 'calc(100vw - 296px)';

        tool.style.width = w;
        if (main) {
            main.style.width = w;
        }
    }

///////////////////////////////////////////////////////////////////////////////////////////////////////////
// FileBrowser
///////////////////////////////////////////////////////////////////////////////////////////////////////////

    getFileContents(contents, metadata, eid, fid, sme = false, ) {

        if(window.sosViewer.userInfo == null) {
            window.sosViewer.getUserInfo();
        }

        // disapear the loading legend
        const loading = document.getElementById('loading');
        if(loading) {
            loading.classList.add('hidden');
        }

        //catch enter key for in document searches
        const search = document.getElementById('searchTerm');
        if(search) {
           search.addEventListener('keypress', event => {
                if (event.key === "Enter") {
                    event.preventDefault();
                    window.sosViewer.toggleSearch(event);
                }
            });
        }

        //read file meta data info (see line 28)
        if(metadata) {
            const encoded = atob(metadata.replace(/==/,''));
            if(encoded) {
                window.sosViewer.fileMetaData = JSON.parse(encoded);
            }
        }

        if(window.sosViewer.fileMetaData) {
            const nameParts = window.sosViewer.fileMetaData.name.split('_');
            document.title = 'SOS Viewer ' + nameParts[0];
        }

        // add this File Content tab to the tabControl structure
        window.sosViewer.addTab('V' + fid.toString());

        //apply contents
        if(contents != null) {
            const pre = document.getElementById(eid);
            if(pre) {
                pre.innerHTML = atob(contents.replace(/==/,''));
            }

            //apply line numbers
            const lines = window.sosViewer.fileMetaData.lines;
            let numbers = '';
            for(let i = 1; i <= lines; i++) {
                numbers += '<span>' + i + '</span>';
            }
            const linu = document.getElementById('linu1');
            if(linu) {
                linu.innerHTML = numbers;
            }

            //floating horizontal scroll
            /*
            document.addEventListener('scroll', (e) => {
                //trick to find the single Filament container that has overflow-y-scroll and scrollbar-hidden
                console.log('Scrolled:', e.target);
            }, true);
            */

            const container = document.getElementById(eid);
            const scrollBar = document.getElementById('float-scroll1');
            const target    = document.getElementsByClassName('scrollbar-hidden')[0];
            const containerSize = container.getBoundingClientRect();

            if(containerSize.width > 1000) {
                scrollBar.classList.replace('hidden', 'flex');

                scrollBar.addEventListener('scroll', () => {
                    target.scrollLeft = scrollBar.scrollLeft;
                });

                target.addEventListener('scroll', () => {
                    scrollBar.scrollLeft = target.scrollLeft;
                });
            } else {
                scrollBar.classList.replace('flex','hidden');
                container.style.width = '100.0rem'
            }

            if(window.sosViewer.fileMetaData.isTable == "0") {
                window.dispatchEvent(new CustomEvent('done-loading'));
            }
        }

        //apply notes and highligts stored in db
        if(window.sosViewer.fileMetaData.acetate) {
            const annotationContent = domJSON.toDOM(window.sosViewer.fileMetaData.acetate);

            if(!annotationContent) {
                return;
            }

            //count how many notes
            const notesCount = annotationContent.firstChild.getElementsByClassName('note').length;

            if(notesCount) {
                //update the notes notification badge
                const data = {
                    notes: notesCount
                }
                window.dispatchEvent(new CustomEvent('livewire:note-count', { detail: data }));
            }


            //count how many highlights
            let annotationPre = null;
            annotationContent.firstChild.childNodes.forEach( elem => {
                if(elem.id == eid) {
                    annotationPre = elem;
                    return;
                }
            });

            let hasHighlights = 0;
            const matches = annotationPre.innerHTML.match(/..*highlighted..*_/gm);
            if(matches) {
                hasHighlights = matches.length;
            }

            if( hasHighlights > 0 || notesCount > 0) {
                if(notesCount > 0) {
                    const notes = Array.from(annotationContent.firstChild.getElementsByClassName('delnote'));
                    notes.forEach( element => {
                        if(sme > 0){
                            //if sme != 0 disable the delete icon on all notes
                            element.style.color = '#374151'; //gray-700
                            element.style.pointerEvents = 'none';
                        }
                    });
                }

                if(sme > 0 && hasHighlights > 0) {
                    //if sme != 0 disable highlight function
                    document.getElementById('highlightIcon').disabled = true;
                }

                const acetate = document.getElementById('acetate1');
                acetate.parentNode.replaceChild(annotationContent, acetate);
                setTimeout(() => {
                    window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'load-more' } }));
                }, 500);
            }
        }
    }

    toggleLineNumbers() {
        let target = document.getElementById('linu1');
        if(target) {
            if(target.classList.contains('flex')) {
                target.classList.replace('flex', 'hidden');
            } else {
                target.classList.replace('hidden', 'flex');
            }
        }
    }

    toggleHighlight() {
        if(window.sosViewer.fileMetaData.chunked == "1") {
            //we do not save annotation for large files due to space and performance
            let message = `Sorry for the inconvenience but highlight won't work for files larger than `;
            message += window.sosViewer.toHuman(window.sosViewer.fileMetaData.tooBig) + '.';
            new FilamentNotification().title(message).icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
            return;
        }

        const pre = document.getElementById('pre1');

        window.sosViewer.highlightEnabled = !window.sosViewer.highlightEnabled ? true : false;
        if(window.sosViewer.highlightEnabled) {

            const isDark = document.documentElement.classList.contains('dark');

            //--amber-200
            const color = '#fde68a';

            //enable text higligther
            const options = {
                color: color,
                highlightedClass: 'text-zinc-800',
                contextClass: 'highlighter-context',
                onRemoveHighlight: function(range) {
                    window.sosViewer.saveAnnotations();
                    return true;
                },
                onBeforeHighlight: function(range) { return true; },
                onAfterHighlight:  function(range, normalizedHighlights, timestamp) {
                    window.sosViewer.saveAnnotations();
                    return true;
                }
            };
            let hltr = new TextHighlighter(pre, options);

        } else {
            //disable text higligther
            let hltr = new TextHighlighter(pre);
            hltr.removeHighlights(pre);
            hltr.destroy();
            hltr = null;

            window.sosViewer.saveAnnotations();
        }

    }

    toggleSearch(ev) {
        // when enter in the search entry is hit (searchTerm), this function gets executed
        const value = ev.target.value;
        const caseSensitive = false;
        const pre = document.getElementById('pre1');

        // clear old highlights
        if (window.sosViewer.findHltr) {
            window.sosViewer.findHltr.removeHighlights(pre);
            window.sosViewer.findHltr.destroy();
            window.sosViewer.findHltr = null;
            window.sosViewer.matches = [];
            document.getElementById('matches').textContent = '';
        }

        if (value) {
            const color = '#b5d37d';
            const options = {
                color: color,
                highlightedClass: 'text-zinc-800',
                contextClass: 'highlighter-context',
                onRemoveHighlight: function(range) { return true; },
                onBeforeHighlight: function(range) { return true; },
                onAfterHighlight:  function(range, normalizedHighlights, timestamp) { return true; }
            };

            window.sosViewer.findHltr = new TextHighlighter(pre, options);
            window.sosViewer.findHltr.find(value, caseSensitive);
            window.sosViewer.findHltr.doHighlight(true);
            window.sosViewer.updateMatchesCounter();

            // collect matches
            window.sosViewer.matches = pre.querySelectorAll('[data-highlighted=true]');
            window.sosViewer.searchIndex = -1;

            window.scrollTo(window.scrollX, window.scrollY);
            window.sosViewer.searchNext();
        }

    }

    toggleRing(ev) {
        const wrapper = document.getElementById('searchBox');
        if(wrapper) {
            wrapper.classList.replace('ring-2','ring-0');
            wrapper.classList.replace('border-0','border-1');
        }
    }

    clearSearch() {
        document.getElementById('searchTerm').value = '';
        window.sosViewer.searchIndex = -1;

        const pre = document.getElementById('pre1');
        if(window.sosViewer.findHltr) {
            window.sosViewer.findHltr.removeHighlights(pre);
            window.sosViewer.findHltr.destroy();
            window.sosViewer.findHltr = null;
        }

        window.sosViewer.matches = [];
        document.getElementById('matches').textContent = '';
    }

    searchNext() {
        if (!window.sosViewer.matches || window.sosViewer.matches.length === 0) return;

        // move index
        window.sosViewer.searchIndex =
            (window.sosViewer.searchIndex + 1) % window.sosViewer.matches.length;

        // scroll into view
        const el = window.sosViewer.matches[window.sosViewer.searchIndex];
        el.scrollIntoView({behavior:"smooth", block:"center"});

        // add a focus style
        window.sosViewer.matches.forEach(m => m.classList.remove('current-highlight'));
        el.classList.add('current-highlight');

        window.sosViewer.updateMatchesCounter();
    }

    searchPrev() {
        if (!window.sosViewer.matches || window.sosViewer.matches.length === 0) return;

        // move index backwards
        window.sosViewer.searchIndex =
            (window.sosViewer.searchIndex - 1 + window.sosViewer.matches.length) % window.sosViewer.matches.length;

        const el = window.sosViewer.matches[window.sosViewer.searchIndex];
        el.scrollIntoView({behavior:"smooth", block:"center"});

        // add a focus style
        window.sosViewer.matches.forEach(m => m.classList.remove('current-highlight'));
        el.classList.add('current-highlight');

        window.sosViewer.updateMatchesCounter();
    }

    updateMatchesCounter() {
        const matchesDiv = document.getElementById('matches');
        if (!matchesDiv) return;

        if (!window.sosViewer.matches || window.sosViewer.matches.length === 0) {
            matchesDiv.textContent = 'No matches';
        } else {
            const current = window.sosViewer.searchIndex >= 0
                ? window.sosViewer.searchIndex + 1
                : 0;
            matchesDiv.textContent = `${current} of ${window.sosViewer.matches.length} results`;
        }
    }

    downloadFileByIds(pdf = null) {
        let uri  = '/api/download/';
        uri +=  window.sosViewer.fileMetaData.vault_id + '/' + window.sosViewer.fileMetaData.dir_id + '/' + window.sosViewer.fileMetaData.file_id;

        const extension = pdf ? 'pdf' : 'txt';
        const name = window.sosViewer.fileMetaData.title.replace(/ ..*$/, '');
        const filename = name + '.' + extension;

        let link = document.createElement("a");
        link.setAttribute('download', name);
        link.setAttribute('filename', filename);
        link.href = uri;
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    toggleRaw(data) {
        // when rawMode is one, it means tho switch from table mode to raw mode
        const rawMode = data[0].rawMode;
        const class1 = rawMode ? 'hidden' : 'flex';
        const class2 = rawMode ? 'flex' : 'hidden';

        const raw = document.getElementById('rawFile');
        const table = document.getElementById('fileTable');
        const scrollbar = document.getElementById('float-scroll1');

        //hide the table and show raw
        raw.classList.replace(class1, class2);
        table.classList.replace(class2, class1);

        //hide horizontal scroll bar
        scrollbar.classList.replace(class1, class2);

        if(window.sosViewer.userInfo == null) {
            window.sosViewer.getUserInfo();
        }

        window.sosViewer.fixFileControlsSize();

        return;
    }

    colapseHeaderFile(data) {
        // hack to adjust the size of the header to fit the current view size
        const elem = document.getElementById(data.id);
        if(elem) {
            let prev, next;
            if(data.id == 'logfile1') {
                prev = data.collapsed ? 'mt-70' : 'mt-32';
                next = data.collapsed ? 'mt-32' : 'mt-70';
            }
            elem.classList.replace(prev, next);
        }
        return;
    }

    addNote() {
        if(window.sosViewer.fileMetaData.chunked == "1") {
            //we do not save annotation for large files due to space and performance
            let message = `Sorry for the inconvenience but notes cannot be added to files larger than `;
            message += window.sosViewer.toHuman(window.sosViewer.fileMetaData.tooBig) + '.';
            new FilamentNotification().title(message).icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
            return;
        }

        const contents = document.getElementById('contents1');
        const acetate = document.getElementById('acetate1');
        const notes = Array.from(acetate.getElementsByClassName('note'));
        const scroll = document.documentElement.scrollTop;
        const i = notes.length + 1;

        if(!window.sosViewer.contentsOffset) {
            window.sosViewer.contentsOffset = JSON.parse(JSON.stringify(acetate.getBoundingClientRect()));
        }

        let y = scroll;
        y += (notes.length * 80);

        let x = (window.sosViewer.contentsOffset.width - window.sosViewer.contentsOffset.left)*3/5 ;
        x -= (notes.length * 80);

        let hdate = new Date();
        const date = hdate.toISOString().split('T')[0];
        const time = hdate.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'});

        let id  = 'note' + i;

        let note  = '<div id="' + id + '" draggable=true ';
        note += 'ondragstart="window.sosViewer.dragNote(event)" ';
        note += 'style="top:' + y + 'px;left:' + x + 'px;" ';
        note += 'class="note absolute cursor-grab shadow-lg transform transition-all ease-in-out duration-50 ';
        note += 'flex-col justify-start items-center resize ';
        note += 'text-base text-zinc-700 bg-warning-100/85 border-1 border-zinc-300 dark:border-zinc-500 rounded-lg" >';

            note += '<input id="vid_'  + id + '" class="hidden" value="' + window.sosViewer.fileMetaData.vault_id + '" >';
            note += '<input id="did_'  + id + '" class="hidden" value="' + window.sosViewer.fileMetaData.dir_id + '" >';
            note += '<input id="fid_'  + id + '" class="hidden" value="' + window.sosViewer.fileMetaData.file_id + '" >';
            note += '<input id="cid_'  + id + '" class="hidden" value="' + window.sosViewer.caseID + '" >';
            note += '<input id="uid_'  + id + '" class="hidden" value="' + window.sosViewer.fileMetaData.owner + '" >';
            note += '<input id="nid_'  + id + '" class="hidden" value="' + id + '" >';
            note += '<input id="date_' + id + '" class="hidden" value="' + date + '" >';
            note += '<input id="time_' + id + '" class="hidden" value="' + time + '" >';
            note += '<input id="name_' + id + '" class="hidden" value="' + window.sosViewer.fileMetaData.title + '" >';
            note += '<input id="offset_' + id + '" class="hidden" value="adios" >';

            let name = '';
            let avatar = '';
            if(sessionStorage.getItem('name')) {
                name = sessionStorage.getItem('name');
            }
            if(sessionStorage.getItem('avatar')) {
                avatar = sessionStorage.getItem('avatar');
            }

            // avatar
            let avCompo = '';

            if(name && avatar) {
                avCompo += '<div class="flex items-center justify-center bg-transparent">';
                avCompo += '<label class="mr-2" >' + name + '</label>';
                avCompo += '<img class="w-8 h-8 rounded-full" src="/storage/' + avatar + '" alt="' + name + '">';
                avCompo += '</div>';
            }

            note += '<header id="noteHeader' + i + '" ';
            note += 'class="flex flex-row justify-between items-center pointer-event-none resize-x ';
            note += 'p-2 bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-100 border-1 border-zinc-200 dark:border-zinc-500 ">';
                note += '<i id="notePin_' + id + '" class="ph-duotone ph-push-pin text-lg float-start ml-2" onclick="window.sosViewer.togglePinNote(event)">';
                note += '</i>';
                note += '<label><strong>' + id  + '</strong></label>';

                note += '<div class="flex justify-between gap-2">';
                note += '<i id="noteHandle_' + id + '" class="ph-duotone ph-hand text-lg float-start ml-2" ></i>';
                note += '<i class="delnote ph-duotone ph-trash text-lg float-end mr-2 text-danger-700 hover:text-danger-900 dark:hover:text-danger-500"';
                    note += 'onclick="window.sosViewer.deleteNote(event)" >';
                note += '</i>';
                note += '</div>';
            note += '</header>';

            note += '<div id="noteSubHeader' + i + '" ';
                note += 'class="flex flex-row justify-between items-center pointer-event-none resize-x ';
                note += 'bg-zinc-100 dark:bg-zinc-800 ';
                note += 'border-b-1 border-zinc-200 dark:border-zinc-500 ';
                note += 'p-2 text-sm h-10 dark:text-zinc-100 " >';

                note += avCompo;

                note += '<label class="pl-4" >' + date + ' ' + time  + '</label>';
            note += '</div>';

            note += '<textarea id="noteText' + i + '" ';
                note += 'class="p-2 bg-transparent resize ring-primary-700 w-full" ';
                note += 'onclick="window.sosViewer.saveAnnotations()" ';
                note += 'onchange="window.sosViewer.saveAnnotations()" ';
                note += 'placeholder="Write your note here..." >';
            note += '</textarea>';

        note += '</div>';

        const template = document.createElement('template');
        template.innerHTML = note;
        acetate.prepend(template.content.children[0]);

        //update the notes notification badge
        const data = {
            notes: i
        }
        window.dispatchEvent(new CustomEvent('livewire:note-count', {
            detail: data
        }));
    }

    dragNote(ev) {
        //ondragstart drag from contents

        if(ev.target.getAttribute('pinned')) {
            return;
        }

        const rect = ev.target.getBoundingClientRect();
        window.sosViewer.dragOffsetX = ev.clientX - rect.left;
        window.sosViewer.dragOffsetY = ev.clientY - rect.top;

        ev.dataTransfer.setData("text/plain", ev.target.id);
    }

    moveNote(ev) {
        //ondragover
        ev.preventDefault();
    }

    dropNote(ev) {
        //ondrop drop on acetate
        ev.preventDefault();

        const id = ev.dataTransfer.getData("text/plain");
        const note = document.getElementById(id);

        if(!note || note.getAttribute('pinned')) {
            return;
        }

        const acetate = document.getElementById('acetate1');
        const rect = acetate.getBoundingClientRect();

        const x = ev.clientX - rect.left - window.sosViewer.dragOffsetX;
        const y = ev.clientY - rect.top  - window.sosViewer.dragOffsetY;

        note.style.left = x + 'px';
        note.style.top  = y + 'px';

        window.sosViewer.saveAnnotations();
    }

    deleteNote(ev) {
        ev.stopPropagation()
        const id = event.target.parentNode.parentNode.parentNode.id;

        ev.target.parentNode.parentNode.parentNode.remove();

        //update the notes notification badge
        const contents = document.getElementById('contents1');
        const notes = Array.from(contents.getElementsByClassName('note'));
        const i = notes.length;
        const elem = document.getElementById('note-count');

        //update the notes notification badge
        const data = {
            notes: i
        }
        window.dispatchEvent(new CustomEvent('livewire:note-count', {
            detail: data
        }));

        window.sosViewer.saveAnnotations();
    }

    togglePinNote(ev) {
        ev.stopPropagation()

        const id = ev.target.parentNode.id.replace(/Header/,'');

        if(id) {
            const note = document.getElementById(id);
            if(note) {
                const handle = document.getElementById('noteHandle_' + id);
                const pin = document.getElementById('notePin_' + id);

                const pinned = note.getAttribute('pinned');

                if(handle) {
                    if(pinned) {
                        handle.classList.remove('hidden');
                        if(note.classList.contains('cursor-pointer')) {
                            note.classList.replace('cursor-pointer', 'cursor-grab');
                        }
                        if(pin.classList.contains('text-primary-700')) {
                            pin.classList.remove('text-primary-700');
                        }
                        note.removeAttribute('pinned');
                    } else {
                        handle.classList.add('hidden');
                        if(note.classList.contains('cursor-grab')) {
                            note.classList.replace('cursor-grab', 'cursor-pointer');
                        }
                        if(!pin.classList.contains('text-primary-700')) {
                            pin.classList.add('text-primary-700');
                        }
                        note.setAttribute('pinned', true);
                    }
                }
            }
        }
    }

    saveAnnotations() {
        if(window.sosViewer.fileMetaData.chunked == "1") {
            //we do not save annotation for large files due to space and performance
            let message = `Sorry for the inconvenience but annotations won't work for files larger than `;
            message += window.sosViewer.toHuman(window.sosViewer.fileMetaData.tooBig) + '.';
            new FilamentNotification().title(message).icon('phosphor-bell-ringing-duotone').iconColor('warning').send()
            return;
        }

        //transparent area
        const acetate = document.getElementById('acetate1');
        const contents = document.getElementById('contents1');

        //count how many notes
        let notesCount = contents.getElementsByClassName('note').length;

        //count how many highlights
        let hasHighlights = 0;
        const matches = acetate.innerHTML.match(/..*highlighted..*/gm);
        if(matches) {
            hasHighlights = matches.length;
        }

        let annotations = null;
        if( hasHighlights > 0 || notesCount > 0) {
            annotations = JSON.stringify(domJSON.toJSON(acetate));
        }

        const data = {
            acetate:    annotations,
            title:      window.sosViewer.fileMetaData.title,
            locked:     window.sosViewer.fileMetaData.locked ? 'true' : 'false',
            status:     window.sosViewer.fileMetaData.status ? window.sosViewer.fileMetaData.status : 'PRIVATE',
        }

        window.dispatchEvent(new CustomEvent('livewire:save-annotations', { detail: data }));
    }

    getUserInfo() {
        if(window.sosViewer.csrfToken) {
            const options = {
                method:  'get',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': window.sosViewer.csrfToken,
                },
            };

            let url = '/api/userInfo';

            fetch(url, options).then((response) => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return (response.json());
                }).then((data) => {
                    window.sosViewer.userInfo = data;
                    sessionStorage.setItem('uid', data.id);
                    sessionStorage.setItem('name', data.name);
                    sessionStorage.setItem('avatar', data.avatar);
                    return;
                }).catch(error => {
                    throw new Error(`setAnnotations error! ${error}`);
                });
        }
    }

    fetchLogChunk(args) {
        const data = args[0];
        if(data.contents) {
            const contents = atob(data.contents.replace(/==/,''));
            document.getElementById('pre1').innerHTML += contents;

            const oldLines = window.sosViewer.fileMetaData.lines + 1;

            window.sosViewer.fileMetaData.offset += window.sosViewer.fileMetaData.chunkSize;
            const matches = contents.match(/\n/gm);
            window.sosViewer.fileMetaData.lines += matches.length;

            //apply line numbers
            const lines = window.sosViewer.fileMetaData.lines;
            let numbers = '';
            for(let i = oldLines; i <= lines; i++) {
                numbers += '<span>' + i + '</span>';
            }
            const linu = document.getElementById('linu1');
            if(linu) {
                linu.innerHTML += numbers;
            }
        }
        window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'load-more' } }));
    }

    toggleAllCpus(ev) {
        //this function is only used in the Top tool

        //reset rotate icon
        const icon = document.getElementById('allCpus');
        if(icon) {
            if(icon.classList.contains('rotate-90')) {
                icon.classList.replace('rotate-90', 'rotate-0');
            } else {
                icon.classList.replace('rotate-0', 'rotate-90');
            }
        }

        //show/hide cpus rows
        const table = document.getElementById('cpuTable');
        if(table) {
            const rows = Array.from(table.getElementsByTagName('tr'));
            rows.forEach((row, index) => {
                if(index != 0) {
                    if(row.classList.contains('hidden')) {
                        row.classList.remove('hidden');
                    } else {
                        row.classList.add('hidden');
                    }
                }
            });
        }
    }

    renderDiff(containerId, leftText = '', rightText = '') {
        const isDark = localStorage.getItem('theme') == 'dark';
        const colorScheme = isDark ? 'dark' : 'light';

        // element where the diff is going to be rendered
        const elem = document.getElementById(containerId);
        elem.innerHTML = '';

        if(leftText != '') {
            window.sosViewer.leftText = leftText;
        }

        if(rightText != '') {
            window.sosViewer.rightText = rightText;
        }

        const diff = window.Diff.createTwoFilesPatch(
            "Left File",
            "Right File",
            window.sosViewer.leftText ?? '',
            window.sosViewer.rightText ?? ''
        );

        const configuration = {
            drawFileList: false,
            fileListToggle: true,
            fileListStartVisible: false,
            fileContentToggle: true,
            matching: 'none',
            outputFormat: 'side-by-side',
            synchronisedScroll: true,
            highlight: true,
            renderNothingWhenEmpty: false,
            colorScheme: colorScheme,
            matching: 'lines',
        };

        const diffHtml = new window.Diff2HtmlUI(elem, diff, configuration);
        elem.innerHTML = '';
        diffHtml.draw();
        diffHtml.highlightCode();

        //fix the side panels width and floating scroll bar
        const scrollBar = document.getElementById('float-scroll1');

        const elements = document.getElementsByClassName('d2h-file-side-diff');
        Array.from(elements).forEach( (side, idx) => {
            side.setAttribute('id', 'side' + idx);
            side.addEventListener('scroll', window.sosViewer.fileCompareSynchSchroll);
            side.classList.remove('d2h-file-side-diff');
            side.classList.add(
                'compareSide',
                'flex',
                'flex-1',
                'w-auto',
                'min-w-[40vw]',
                'max-w-[50vw]',
                'overflow-x-hidden',
                'overflow-x-none'
            );
        });
        scrollBar.addEventListener('scroll', window.sosViewer.fileCompareSynchSchroll);

        const stickyInner = document.getElementById('stickyInner');
        const container = document.getElementById('logfile1');
        const containerSize = container.getBoundingClientRect();
        if(containerSize.width > 1000) {
            scrollBar.classList.replace('hidden', 'flex');
        } else {
            scrollBar.classList.replace('flex','hidden');
        }
        stickyInner.style.width = container.style.width;
        window.sosViewer.fixFileControlsSize();
        window.dispatchEvent(new CustomEvent('done-loading'));
        const statusBlock = document.getElementById('statusBlock');
        statusBlock.classList.replace('flex', 'hidden');
        setTimeout(() => {
            window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'load-more' } }));
        }, 500);
    }

    fileCompareSynchSchroll(e){
        const real0 = document.getElementById('side0');
        const real1 = document.getElementById('side1');
        const container = document.getElementById('logfile1');
        const scrollBar = document.getElementById('float-scroll1');

        if (real0 && e.target === real0) {
            scrollBar.scrollLeft = real0.scrollLeft;
        } else if (real1 && e.target === real1) {
            scrollBar.scrollLeft = real1.scrollLeft;
        } else {
            real0.scrollLeft = scrollBar.scrollLeft;
            real1.scrollLeft = scrollBar.scrollLeft;
            container.scrollLeft = scrollBar.scrollLeft;
        }
    }

    fileCompareSynchWidths(){
        // Recompute the header / #logfile1 width first so the sidebar-toggled,
        // load and resize listeners all pick up the current sidebar state
        // (fixFileControlsSize reads it from localStorage); then mirror the
        // resulting width onto the sticky bottom scrollbar.
        window.sosViewer.fixFileControlsSize();
        const stickyInner = document.getElementById('stickyInner');
        const container = document.getElementById('logfile1');
        const elements = document.getElementsByClassName('compareSide');
        Array.from(elements).forEach( side => {
            stickyInner.style.width = container.style.width;
        });
    }

    trimCustomer(name, maxLength) {
        //trim a customer name nicely to MaxLength for the UI to render
        let customer = '';
        let flag = 0;

        if(!name){
            return(customer);
        }

        const customerParts = name.split(' ');
        customerParts.forEach((part) => {
            if(!flag && (customer.length + part.length <= maxLength)) {
                customer += part + ' ';
            } else {
                flag++;
            }
        });
        return(customer);
    }

    checkTab(tool) {
        // check if the given tab is already open or exists in tabControl structure
        let tabControl = localStorage.getItem('TabControl');
        if(tabControl === null || tabControl === 'NaN' || !tabControl) {
            return false;
        } else {
            tabControl = JSON.parse(tabControl);
        }
        const i = tabControl.indexOf(tool);
        return !(i === -1)
    }

    addTab(tool) {
        // add this to tab to tabControl structure
        let tabControl = localStorage.getItem('TabControl');
        if(tabControl === null || tabControl === 'NaN' || !tabControl) {
            tabControl = [];
        } else {
            tabControl = JSON.parse(tabControl);
        }
        const i = tabControl.indexOf(tool);
        if(i === -1) {
            tabControl.push(tool);
            localStorage.setItem('TabControl', JSON.stringify(tabControl));

            // remove this tab from the tabControl structure when closed
            addEventListener('beforeunload', event => {window.sosViewer.delTab(tool)});
            addEventListener('unload', event => {window.sosViewer.delTab(tool)});
            addEventListener('storage', event => { if (event.key === 'close') { window.close(); } });
        }
    }

    delTab(tool) {
        // remove this to tab to tabControl structure
        let tabControl = localStorage.getItem('TabControl');
        if(tabControl === null || tabControl === 'NaN' || !tabControl) {
            return;
        } else {
            tabControl = JSON.parse(tabControl);
        }
        const i = tabControl.indexOf(tool);
        if(i !== -1) {
            tabControl.splice(i, 1);
            localStorage.setItem('TabControl', JSON.stringify(tabControl));

            /*
            // remove this tab from the tabControl structure when closed
            removeEventListener('beforeunload', event => {window.sosViewer.delTab(tool)});
            removeaddEventListener('unload', event => {window.sosViewer.delTab(tool)});
            */
        }
    }

    closeTabs(ev) {
        localStorage.setItem('close', Date.now());
        sessionStorage.setItem('rateShown', 'false');
        setTimeout(() => {localStorage.removeItem('vaultState')},5000);

        const type = 'warning';
        const title = 'Your vault is been closed!';

        new FilamentNotification()
        .persistent()
        .title(title)
        .icon('phosphor-bell-ringing-duotone')
        .iconColor(type)
        .send();

    }

    deleteReport(data) {
        const cdataDecoded = atob(data.replace(/==/,''));
        const cdata= JSON.parse(cdataDecoded);
        const url = '/api/deleteReport/' + cdata.vid + '/' + cdata.did + '/' + cdata.cid;
        const options = {
            body:    JSON.stringify([]),
            method:  'delete',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': window.sosViewer.csrfToken,
            },
        };
        fetch(url, options).then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return (response.json());
            }).then(data => {
                document.getElementById('report').innerHTML='';
                new FilamentNotification().title('Report deleted').icon('phosphor-bell-ringing-duotone').iconColor('success').send()
                return;
            }).catch(error => {
                throw new Error(`deleteReport error! ${error}`);
            });
    }

    generateReport(data) {
        const cdataDecoded = atob(data.replace(/==/,''));
        const cdata= JSON.parse(cdataDecoded);
        const url = '/api/generateReport/' + cdata.vid + '/' + cdata.did + '/' + cdata.cid;
        const options = {
            method:  'get',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': window.sosViewer.csrfToken,
            },
        };
        fetch(url, options).then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return (response.json());
            }).then(data => {
                new FilamentNotification().title('Report is ready').icon('phosphor-bell-ringing-duotone').iconColor('success').send()
                setTimeout(() => {location.reload()}, 1000);
                return;
            }).catch(error => {
                throw new Error(`deleteReport error! ${error}`);
            });
    }

    copyReport(data) {
        let cdataDecoded = atob(data.replace(/==/,''));
        cdataDecoded = cdataDecoded.replace(/---/gm,'');
        cdataDecoded = cdataDecoded.replace(/- /gm,'');
        cdataDecoded = cdataDecoded.replace(/^\s*\n/gm,'');
        cdataDecoded = cdataDecoded.replace(/\*\*\*/g,'');
        navigator.clipboard.writeText(cdataDecoded);
    }

    printReport(id) {
        const settings = `
            toolbar=yes, location=no, directories=yes, menubar=yes, scrollbars=yes,
            width=650, height=600, left=100, top=25
        `;
        let html = `
        <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
        <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
        <head>
            <meta charset="utf-8">
            <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
            <title>System Report</title>
            <meta name="viewport" content="width=device-width">
            <style type="text/css">
                @import url(http://fonts.googleapis.com/css?family=Droid+Sans);
                body {
                    margin:0px;
                    font-family:'Droid Sans', sans-serif;
                    font-size:16px;
                    color:#555;
                    line-height: 25px;
                    background: right top / 25% no-repeat url("/storage/themes/March2025/printLogo.png"), rgba(255, 255, 255, 0.8);
                }
                a{color:#000;text-decoration:none;}
            </style>
        </head>
            <body onLoad="self.print()" onafterprint="window.close()">
        `;
         html += document.getElementById(id).innerHTML.replace(/=====*/g,'');
         html += `
            </body>
        </html>
        `;

        let docprint=window.open('sos-vault', 'System Report', settings);
        docprint.document.open();
        docprint.document.write(html);
        docprint.document.close();
        docprint.focus();
    }

    vaultMonitor(csrfToken) {
        if(csrfToken) {
            window.sosViewer.csrfToken = csrfToken;
        }

        //continously whatch for the state of the vault
        const timer = setInterval( () => {
            let type = 'success';
            let message = '';

            const options = {
                body:    '{}',
                method:  'post',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': window.sosViewer.csrfToken,
                },
            };

            let url = '/api/vaultState';
            return fetch(url, options).then((response) => {
                    if (!response.ok) {
                        // 419 session expired or other error.
                        // Skip logout redirect when an admin is impersonating a user —
                        // redirecting to /logout would wipe the impersonation session.
                        if(timer) {
                            clearInterval(timer);
                        }
                        if(!window.sosViewer.isImpersonating) {
                            setTimeout(() => {window.location = '/logout'}, 1000);
                        }
                        throw new Error(`HTTP Status: ${response.status}`);
                        return;
                    }
                    return (response.json());
                }).then((data) => {
                    if(!data.open) {
                        if(!sessionStorage.getItem('vaultState') || sessionStorage.getItem('vaultState') == 'open') {
                            type = 'danger';
                            const title = 'Your vault was closed unexpectedly!';
                            message = 'Any further interaction will fail. Try logging in again please.';

                            new FilamentNotification()
                            .persistent()
                            .title(title)
                            .body(message)
                            .icon('phosphor-bell-ringing-duotone')
                            .iconColor(type)
                            .send();

                            sessionStorage.setItem('vaultState', 'closed');
                            if(window.location.href.match(/dashboard$/)) {
                                setTimeout(() => {
                                    document.getElementById('closedVaultuploadToggleSpinner').click();
                                    setTimeout(() => {window.location = '/dashboard'}, 1000);
                                }, 6000);
                            }
                        }
                    } else if(sessionStorage.getItem('vaultState') == 'closed') {
                        type = 'success';
                        message = 'Your vault is back to normal open state.';

                        new FilamentNotification()
                        .title(message)
                        .icon('phosphor-bell-ringing-duotone')
                        .iconColor(type)
                        .send();

                        sessionStorage.setItem('vaultState', 'open');
                        if(window.location.href.match(/dashboard$/)) {
                            setTimeout(() => {window.location = '/dashboard'}, 6000);
                        }
                    }
                }).catch((error) => {
                    console.error('Fetch error:', error);
                });
        }, 5000);
    }

///////////////////////////////////////////////////////////////////////////////////////////////////////////
// Summary tool
///////////////////////////////////////////////////////////////////////////////////////////////////////////

    summaryBadgeChart(conf, type){
        const cdataDecoded = atob(conf.replace(/==/,''));
        const chart = JSON.parse(cdataDecoded);
        if(chart) {
            if(typeof ApexCharts !== 'undefined') {
                //fix series
                let series = chart.series;

                //fix formatters
                if(type == "host") {
                    const cores = chart.cores;
                    chart.plotOptions.radialBar.barLabels.formatter = ((seriesName, opts) => {return seriesName + ':  ' + parseFloat(opts.w.globals.series[opts.seriesIndex]/cores).toFixed(2)});
                    chart.yaxis.labels.formatter = (x => {return parseFloat(x/cores).toFixed(2) + ' %'});
                    //apply title
                    //document.getElementById('title').innerHTML += ' for ' + value.tableData1['os version'];

                }

                if(type == "memory") {
                    chart.series = series.map((value) => {
                        const data = value.data.map((num) => {return parseFloat(num)});
                        const entry = {
                            name: value.name,
                            data: data
                        };
                        return entry
                    });
                    chart.plotOptions.bar.dataLabels.total.formatter =  (x =>{return window.sosViewer.toHuman(x)});
                    chart.xaxis.labels.formatter = (x =>{return window.sosViewer.toHuman(x)});
                    chart.tooltip.y.formatter    = (x =>{return window.sosViewer.toHuman(x)});
                }

                if(type == "disk") {
                    chart.series = series.map((value) => {
                        const data = value.data.map((num) => {return parseFloat(num)});
                        const entry = {
                            name: value.name,
                            data: data
                        };
                        return entry
                    });
                    chart.plotOptions.bar.dataLabels.total.formatter =  (x =>{return x});
                    chart.xaxis.labels.formatter = (x =>{return x});
                    chart.tooltip.y.formatter    = (x =>{return x + "%"});
                }

                if(type == "procs") {
                    chart.series = series.map((key) => {
                        const data = key.data.map((num) => {return parseFloat(num)});
                        const entry = {
                            name: key.name,
                            data: data
                        };
                        return entry
                    });
                    chart.plotOptions.bar.dataLabels.total.formatter = (x =>{return window.sosViewer.toHuman(x)});
                    chart.xaxis.labels.formatter = (x =>{return x});
                    chart.tooltip.y.formatter    = (x =>{return window.sosViewer.toHuman(x)});
                }

                if(type == "conn") {
                    chart.dataLabels.formatter   = (x =>{return x});
                    chart.tooltip.y.formatter    = (x =>{return x});
                }

                if(type == "tcpip") {
                    chart.dataLabels.formatter   = (x =>{return x});
                    chart.tooltip.y.formatter    = (x =>{return window.sosViewer.toHuman(x)});
                }

                if(type == "files") {
                    chart.series = series.map((key) => {
                        const data = key.data.map((num) => {return parseFloat(num)});
                        const entry = {
                            name: key.name,
                            data: data
                        };
                        return entry
                    });
                    chart.plotOptions.bar.dataLabels.total.formatter = (x =>{return x});
                    chart.xaxis.labels.formatter = (x =>{return x});
                    chart.tooltip.y.formatter    = (x =>{return x});
                }

                if(type == "errors") {
                    chart.series = series.map((key) => {
                        const data = key.data.map((num) => {return parseFloat(num)});
                        const entry = {
                            name: key.name,
                            data: data
                        };
                        return entry
                    });
                    chart.plotOptions.bar.dataLabels.total.formatter = (x =>{return x});
                    chart.xaxis.labels.formatter = (x =>{return x});
                    chart.tooltip.y.formatter    = (x =>{return x});
                }

                if(type == "firewall") {
                    chart.dataLabels.formatter   = (x =>{return x});
                    chart.tooltip.y.formatter    = (x =>{return x});
                }

                if(type == "systemd") {
                    chart.series = series.map((key) => {
                        const data = key.data.map((num) => {return parseFloat(num)});
                        const entry = {
                            name: key.name,
                            data: data
                        };
                        return entry
                    });
                    chart.plotOptions.bar.dataLabels.total.formatter = (x =>{return x});
                    chart.xaxis.labels.formatter = (x =>{return x});
                    chart.tooltip.y.formatter    = (x =>{return x});
                }

                const canvas = document.getElementById(type + '_canvas');
                new ApexCharts(canvas, chart).render();
            }
        }
    }

}

