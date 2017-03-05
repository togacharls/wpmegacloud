var desc_img = window.location.protocol + "//" + window.location.hostname + "/wp-content/plugins/wp_mega/img/desc.gif";
var asc_img = window.location.protocol + "//" + window.location.hostname + "/wp-content/plugins/wp_mega/img/asc.gif";
var current_url = window.location.pathname;

Element.prototype.remove = function() {
    this.parentElement.removeChild(this);
}

var WPMEGACLOUD = {
    //Esta variable se utilizará para determinar si se mostrará o no el Loading...
    load: false,
    init: function(){
        
        if(typeof WP_URL !== 'string'){
            console.log("WP_URL not defined!");
        }else{
            WPMEGACLOUD.nodes_url = WP_URL + "/wp-json/wpmegacloud/nodes";
            WPMEGACLOUD.options_url = WP_URL + "/wp-json/wpmegacloud/options";
            WPMEGACLOUD.session_url = WP_URL + "/wp-json/wpmegacloud/session";
        }
        
        WPMEGACLOUD.load_folders();
        var wpmega_button = document.getElementById('wpmega_button');
        if(wpmega_button === null){
            return;
        }
        wpmega_button.addEventListener("click", function(){
            WPMEGACLOUD.loading(true);
        });
        
        var wpmega_searcher_button = document.getElementById('wpmega_searcher_button');
        var wpmega_searcher = document.getElementById("wpmega_searcher_input");
        
        if(wpmega_searcher_button !== null){
            wpmega_searcher_button.addEventListener("click", function(){
                WPMEGACLOUD.search(wpmega_searcher.value);
            });
            wpmega_searcher.addEventListener("keydown", function(event){
                if(event.which === 13){
                    WPMEGACLOUD.search(wpmega_searcher.value);
                }
            });
        }
        
        WPMEGACLOUD.tabs();
        
        WPMEGACLOUD.onChangeUploader();
        WPMEGACLOUD.onCheckRootFolder();
        WPMEGACLOUD.onCheckTrashBin();
        WPMEGACLOUD.onCheckRemoveLocal();
        WPMEGACLOUD.onCheckRemoveMEGA();
        WPMEGACLOUD.onCheckAllowExternalFiles();
        WPMEGACLOUD.onRemove();
    },
    
    load_folders: function(){
        var folder_nodes = document.getElementsByClassName("wpmc_folder_node");
        for(var key in folder_nodes){
            var folder = folder_nodes[key];
            if(typeof folder === "object"){
                var imgs = folder.getElementsByClassName("wpmc_folder_lock");
                var img =imgs[0];
                if(typeof img === "object"){
                    img.addEventListener("click", function(){
                        var ul = this.closest("ul");
                        if(this.getAttribute("src").indexOf(asc_img) > -1){
                            WPMEGACLOUD.show_children(ul);
                            this.setAttribute("src", desc_img);
                        }else if(this.getAttribute("src").indexOf(desc_img) > -1){
                            WPMEGACLOUD.hide_children(ul);
                            this.setAttribute("src", asc_img);
                        }
                    });
                }
            }
        }
    },
    //Gestiona las pestañas del back
    tabs: function(){
        
        var tab_disc = document.getElementById("tab_disc");
        var tab_config = document.getElementById("tab_config");
        
        var nav_tab_disc = document.getElementById("nav-tab-disc");
        var nav_tab_config = document.getElementById("nav-tab-config");
        
        if(current_url.indexOf("#disc") > -1){
            tab_disc.classList.remove("wpmc_hide");
        }else if(current_url.indexOf("#config") > -1){
            tab_disc.classList.add("wpmc_hide");
            tab_config.classList.remove("wpmc_hide");
        }
        
        nav_tab_disc.addEventListener("click", function(){
            this.classList.add("nav-tab-active");
            nav_tab_config.classList.remove("nav-tab-active");
            tab_disc.classList.remove("wpmc_hide");
            tab_config.classList.add("wpmc_hide");
            //Se recargan los nodos
            var data = {
                wpmega_reload_nodes: "yes"
            };
            
            function tabsCallback(response){
                console.log("Response: " + response);
                response = JSON.parse(response);
                
                var div = document.getElementById("wpmc_nodes");
                if(div !== null ){
                    if(response.code === "OK"){
                        div.innerHTML = response.data;
                    }else{
                        alert(response.message);
                    }
                    WPMEGACLOUD.init();
                }else{
                    console.log("No se ha encontrado DIV#wpmc_nodes");
                }
                WPMEGACLOUD.load = false;
                //WPMEGACLOUD.loading(WPMEGACLOUD.load);
                setTimeout(function(){
                    WPMEGACLOUD.loading(WPMEGACLOUD.load);
                }, 1000)
            };

            /*WPMEGACLOUD.load = true;
            setTimeout(function(){
                WPMEGACLOUD.loading(WPMEGACLOUD.load);
            }, 1000);*/

            var requestParams = {
                url: WPMEGACLOUD.nodes_url,
                method: "POST",
                params: data,
                callback: tabsCallback
            };
            WPMEGACLOUD.request(requestParams);
            //TG.request(WPMEGACLOUD.nodes_url, data, callback);
        });
        
        nav_tab_config.addEventListener("click", function(){
            this.classList.add("nav-tab-active");
            nav_tab_disc.classList.remove("nav-tab-active");
            tab_disc.classList.add("wpmc_hide");
            tab_config.classList.remove("wpmc_hide");
        });
    },

    //TODO cambiar por "show_children(node_id)" 
    //Hace visibles los elementos de un "ul"
    show_children: function(parent){
        var elements = parent.getElementsByTagName("li");
        for(var i in elements){
            var element = elements[i];
            if(typeof element === "object"){
                if(element.getAttribute("parent") === parent.parentElement.id){
                    element.classList.remove("wpmc_hide");
                }
            }
        }
    },
    //TODO cambiar por "hide_children(node_id)" 
    //Oculta los elementos de un "ul"
    hide_children: function(parent){
        var elements = parent.getElementsByTagName("li");
        for(var i in elements){
            var element = elements[i];
            if(typeof element === "object"){
                if(element.getAttribute("parent") === parent.parentElement.id){
                    element.classList.add("wpmc_hide");
                }
            }
        }
    },
    
    //Muestra los parientes de un nodo con id "node_id" así como el mismo nodo
    show_parents: function(node_id){
        var div = document.getElementById("wpmc_nodes");
        var node = document.getElementById(node_id);
        var elements = [node];
        var parent = node.closest("li");
        
        for(var i = 0; i < 20; i++){
            parent = parent.closest("li");
        }
        
        for(var i in elements){
            var element = elements[i];
            element.classList.remove("wpmc_hide");
        }
    },

    //Oculta todos los nodos
    hide_nodes: function(){
        var rootNode = document.getElementsByClassName("root_node")[0];
        var elements = rootNode.getElementsByTagName("li");
        
        for(var i in elements){
            var element = elements[i];
            if(typeof element === "object"){
                if(element.getAttribute("parent") !== rootNode.id){
                    element.classList.add("wpmc_hide");
                }
                var imgs = element.getElementsByTagName("img");
                for(var j = 0; j < imgs.length; j++){
                    if(typeof imgs[j] !== "undefined" && imgs[j].getAttribute("src") === desc_img){
                        imgs[j].setAttribute("src", asc_img);
                    }
                }
            }
        }
    },
    
    //Deja visibles los nodos que concuerdan con la búsqueda y sus padres
    search: function(request){
        var root_node = document.getElementById('wpmc_nodes');
        var nodes = root_node.getElementsByTagName('li');
        
        WPMEGACLOUD.hide_nodes();
        
        if(request === ""){
            return ;
        }
        
        for(var key in nodes){
            var node = nodes[key];
            if(typeof node === "object"){
                
                var innerText = node.innerText.toLowerCase();
                request = request.toLowerCase();
                
                if(innerText.indexOf(request) > -1){
                    WPMEGACLOUD.show_parents(node.id);
                }
            }
        }
    },
    
    //Cambia el valor de WPMEGA_ROOT_NODE_OPTION en función de si está o no seleccionado el checkbox o no
    onCheckRootFolder: function(){
        var checkbox_root_folder = document.getElementById("wpmega_checkbox_root_folder");
        checkbox_root_folder.addEventListener("click", function(){
            var data = {
                wpmega_root_folder_visibility_option: (checkbox_root_folder.checked? "yes":"no")
            };

            /*WPMEGACLOUD.load = true;
            setTimeout(function(){
                WPMEGACLOUD.loading(WPMEGACLOUD.load);
            }, 1000);*/
            var paramsRequest = {
                url: WPMEGACLOUD.options_url,
                method: "POST",
                params: data,
                callback: WPMEGACLOUD.callback
            };
            WPMEGACLOUD.request(paramsRequest);
            //TG.request(WPMEGACLOUD.options_url, data, WPMEGACLOUD.callback);
        });
    },
    
    //Cambia el valor de WPMEGA_TRASH_BIN_OPTION en función de si está o no seleccionado el checkbox o no
    onCheckTrashBin: function(){
        var checkbox_trash_bin= document.getElementById("wpmega_checkbox_trash_bin");
        checkbox_trash_bin.addEventListener("click", function(){
            var data = {
                wpmega_trash_bin_visibility_option: (checkbox_trash_bin.checked? "yes":"no")
            };
            /*WPMEGACLOUD.load = true;
            setTimeout(function(){
                WPMEGACLOUD.loading(WPMEGACLOUD.load);
            }, 1000);*/
            var requestParams = {
                url: WPMEGACLOUD.options_url,
                method: "POST",
                params: data,
                callback: WPMEGACLOUD.callback
            };
            WPMEGACLOUD.request(requestParams);
            //TG.request(WPMEGACLOUD.options_url, data, WPMEGACLOUD.callback);
        });
    },
    
    //Cambia el valor de WPMEGA_UPLOAD_OPTION en función del checkbox que esté seleccionado
    onChangeUploader: function(){
        var radioButtons = document.getElementsByName("wpmega_upload");
        for(var i=0; i < radioButtons.length; i++){
            radioButtons[i].addEventListener("click", function(){
                var data = {
                    wpmega_upload_option: this.value
                };
                /*WPMEGACLOUD.load = true;
                setTimeout(function(){
                    WPMEGACLOUD.loading(WPMEGACLOUD.load);
                }, 1000);*/
                var requestParams = {
                    url: WPMEGACLOUD.options_url,
                    method: "POST",
                    params: data,
                    callback: WPMEGACLOUD.callback
                };
                WPMEGACLOUD.request(requestParams);
                //TG.request(WPMEGACLOUD.options_url, data, WPMEGACLOUD.callback);
            });
        }
    },
    
    //Cambia el valor de WPMEGA_REMOVE_LOCAL_FILES_OPTION en función del checkbox que esté seleccionado
    onCheckRemoveLocal: function(){        
        var checkbox_remove_local_files= document.getElementById("wpmega_remove_local_files");
        checkbox_remove_local_files.addEventListener("click", function(){
            var data = {
                wpmega_remove_local_files_option: (checkbox_remove_local_files.checked? "yes":"no")
            };
            /*WPMEGACLOUD.load = true;
            setTimeout(function(){
                WPMEGACLOUD.loading(WPMEGACLOUD.load);
            }, 1000);*/
            var requestParams = {
                url: WPMEGACLOUD.options_url,
                method: "POST",
                params: data,
                callback: WPMEGACLOUD.callback
            };
            WPMEGACLOUD.request(requestParams);
            //TG.request(WPMEGACLOUD.options_url, data, WPMEGACLOUD.callback);
        });
    },
    
    //Cambia el valor de WPMEGA_REMOVE_MEGA_FILES_OPTION en función del checkbox que esté seleccionado
    onCheckRemoveMEGA: function(){        
        var checkbox_remove_mega_files= document.getElementById("wpmega_remove_mega_files");
        checkbox_remove_mega_files.addEventListener("click", function(){
            var checked = checkbox_remove_mega_files.checked;
            var data = {
                wpmega_remove_mega_files_option: (checked? "yes":"no")
            };

            /*WPMEGACLOUD.load = true;
            setTimeout(function(){
                WPMEGACLOUD.loading(WPMEGACLOUD.load);
            }, 1000);*/
            var requestParams = {
                url: WPMEGACLOUD.options_url,
                method: "POST",
                params: data,
                callback: WPMEGACLOUD.callback
            };
            WPMEGACLOUD.request(requestParams);
            //WPMEGACLOUD.loading(true);
            //TG.request(WPMEGACLOUD.options_url, data, WPMEGACLOUD.callback);

            if(checked){
                WPMEGACLOUD.update_contenedores(true);
            }else{
                WPMEGACLOUD.update_contenedores(false);
            }
        });
    },
    
    onCheckAllowExternalFiles: function(){
        var checkbox_remove_mega_files= document.getElementById("wpmega_allow_no_wp_mega_files_option");
        checkbox_remove_mega_files.addEventListener("click", function(){
            var checked = checkbox_remove_mega_files.checked;
            var data = {
                wpmega_allow_no_wp_mega_files_option: (checked? "yes":"no")  
            };
            /*WPMEGACLOUD.load = true;
            setTimeout(function(){
                WPMEGACLOUD.loading(WPMEGACLOUD.load);
            }, 1000);*/
            var requestParams = {
                url: WPMEGACLOUD.options_url,
                method: "POST",
                params: data,
                callback: WPMEGACLOUD.callback
            };
            WPMEGACLOUD.request(requestParams);
            //WPMEGACLOUD.loading(true);
            //TG.request(WPMEGACLOUD.options_url, data, WPMEGACLOUD.callback);
        });
    },
    
    //Define los listeners para eliminar ficheros/directorios al pulsar sobre un icono de basura.
    onRemove: function(){
        var trashes = document.getElementsByClassName("wpmega_trash");
        for(var i=0; i < trashes.length; i++){
            trashes[i].addEventListener("click", function(){
                var confirmation = window.confirm("Estás apunto de eliminar este fichero/directorio, ¿estás seguro?");
                if(confirmation){
                    var index = this.id.indexOf("_");
                    var node_id = this.id.substr(index + 1);
                    var data = {
                        wpmn_r: node_id
                    };
                    
                    var trash = this;
                    function removeNodeCallback(response){
                        response = JSON.parse(response);
                        if(response.code === "OK" ){
                            //Se elimina el LI del DOM
                            var li_found = false;
                            var auxElement = trash.parentElement;
                            //console.log("Fichero eliminado correctamente");
                            do{
                                if(auxElement.tagName === "LI"){
                                    auxElement.remove();
                                    //console.log("Elemento encontrado");
                                    li_found = true;
                                }else{
                                    auxElement = auxElement.parentElement;
                                }
                            }while(!li_found);
                        }else{
                            console.log(response);
                        }
                    };
                    //TG.request(WPMEGACLOUD.nodes_url, data, removeNodeCallback);
                    /*WPMEGACLOUD.load = true;
                    setTimeout(function(){
                        WPMEGACLOUD.loading(WPMEGACLOUD.load);
                    }, 1000);*/
                    var requestParams = {
                        url: WPMEGACLOUD.nodes_url,
                        method: "DELETE",
                        params: data,
                        callback: removeNodeCallback()
                    };
                    WPMEGACLOUD.request(requestParams);
                }
            });
        }
    },
    
    /*
     * @param {boolean} show_them
     * @returns {undefined}
     */
    update_contenedores: function(show_them){
        if(typeof show_them === "undefined"){
            show_them = true;
        }
        var contenedores = document.getElementsByClassName("wpmega_trash");
        for(var i = 0; i < contenedores.length; i++){
            if(show_them){
                contenedores[i].classList.remove("hidden");
            }else if(!contenedores[i].classList.contains("hidden")){
                contenedores[i].classList.add("hidden");
            }
        }
    },
    
    /*
     * Callback para la mayoría de las peticiones al servidor 
     */
    callback: function (response) {
        WPMEGACLOUD.load = false;
        setTimeout(function () {
            WPMEGACLOUD.loading(WPMEGACLOUD.load);
        }, 1000);
        if(this.status !== 200){
            console.log(response);
        }
    },

    //Hace una petición AJAX para actualizar la sesión activa en MEGA
    updateSession: function(){
        var user = document.getElementById("wpmc_user").value;
        var password = document.getElementById("wpmc_password").value;
        var sessionParams = {
            url: WPMEGACLOUD.session_url,
            method: "POST",
            params: {
                user: user,
                password: password
            },
            callback: WPMEGACLOUD.callback
        };
        WPMEGACLOUD.request(sessionParams);
    },

    /*
     * Muestra/oculta un loading en función del parámetro "show"
     * @param show = Boolean
     */
    loading: function(show){
        if(show){
            var html = "\
                    <div id='alpuntodesal-loading' class='loading hide'>\
                        <div id='floatingCirclesG'>\n\
                            <div class='f_circleG' id='frotateG_01'></div>\n\
                            <div class='f_circleG' id='frotateG_02'></div>\n\
                            <div class='f_circleG' id='frotateG_03'></div>\n\
                            <div class='f_circleG' id='frotateG_04'></div>\n\
                            <div class='f_circleG' id='frotateG_05'></div>\n\
                            <div class='f_circleG' id='frotateG_06'></div>\n\
                            <div class='f_circleG' id='frotateG_07'></div>\n\
                            <div class='f_circleG' id='frotateG_08'></div>\n\
                        </div>\n\
                    </div>";

            var css = "<style>\
                .loading{position: absolute;top: 0px;left: 0px;z-index: 999;width: 100%;height: 100%;padding-top: 25%;background: rgba(0, 0, 0, 0.5);}\
                #floatingCirclesG{position:relative;width:125px;height:125px;margin:auto;transform:scale(0.6);-o-transform:scale(0.6);-ms-transform:scale(0.6);-webkit-transform:scale(0.6);-moz-transform:scale(0.6);}\
                .f_circleG{\
                    position:absolute;\
                    background-color:rgb(255,255,255);\
                    height:22px;\
                    width:22px;\
                    border-radius:12px;\
                    -o-border-radius:12px;\
                    -ms-border-radius:12px;\
                    -webkit-border-radius:12px;\
                    -moz-border-radius:12px;\
                    animation-name:f_fadeG;\
                    -o-animation-name:f_fadeG;\
                    -ms-animation-name:f_fadeG;\
                    -webkit-animation-name:f_fadeG;\
                    -moz-animation-name:f_fadeG;\
                    animation-duration:1.2s;\
                    -o-animation-duration:1.2s;\
                    -ms-animation-duration:1.2s;\
                    -webkit-animation-duration:1.2s;\
                    -moz-animation-duration:1.2s;\
                    animation-iteration-count:infinite;\
                    -o-animation-iteration-count:infinite;\
                    -ms-animation-iteration-count:infinite;\
                    -webkit-animation-iteration-count:infinite;\
                    -moz-animation-iteration-count:infinite;\
                    animation-direction:normal;\
                    -o-animation-direction:normal;\
                    -ms-animation-direction:normal;\
                    -webkit-animation-direction:normal;\
                    -moz-animation-direction:normal;}\
                #frotateG_01{\
                    left:0;top:51px;animation-delay:0.45s;\
                    -o-animation-delay:0.45s;\
                    -ms-animation-delay:0.45s;\
                    -webkit-animation-delay:0.45s;\
                    -moz-animation-delay:0.45s;}\
                #frotateG_02{\
                    left:15px;top:15px;\
                    animation-delay:0.6s;\
                    -o-animation-delay:0.6s;\
                    -ms-animation-delay:0.6s;\
                    -webkit-animation-delay:0.6s;\
                    -moz-animation-delay:0.6s;}\
                #frotateG_03{\
                    left:51px;top:0;\
                    animation-delay:0.75s;\
                    -o-animation-delay:0.75s;\
                    -ms-animation-delay:0.75s;\
                    -webkit-animation-delay:0.75s;\
                    -moz-animation-delay:0.75s;}\
                #frotateG_04{\
                    right:15px;top:15px;\
                    animation-delay:0.9s;\
                    -o-animation-delay:0.9s;\
                    -ms-animation-delay:0.9s;\
                    -webkit-animation-delay:0.9s;\
                    -moz-animation-delay:0.9s;}\
                #frotateG_05{\
                    right:0;top:51px;\
                    animation-delay:1.05s;\
                    -o-animation-delay:1.05s;\
                    -ms-animation-delay:1.05s;\
                    -webkit-animation-delay:1.05s;\
                    -moz-animation-delay:1.05s;}\
                #frotateG_06{\
                    right:15px;bottom:15px;\
                    animation-delay:1.2s;\
                    -o-animation-delay:1.2s;\
                    -ms-animation-delay:1.2s;\
                    -webkit-animation-delay:1.2s;\
                    -moz-animation-delay:1.2s;}\
                #frotateG_07{\
                    left:51px;bottom:0;\
                    animation-delay:1.35s;\
                    -o-animation-delay:1.35s;\
                    -ms-animation-delay:1.35s;\
                    -webkit-animation-delay:1.35s;\
                    -moz-animation-delay:1.35s;}\
                #frotateG_08{\
                    left:15px;bottom:15px;\
                    animation-delay:1.5s;\
                    -o-animation-delay:1.5s;\
                    -ms-animation-delay:1.5s;\
                    -webkit-animation-delay:1.5s;\
                    -moz-animation-delay:1.5s;}\
                @keyframes f_fadeG{0%{background-color:rgb(0,0,0);}100%{background-color:rgb(255,255,255);}}\
                @-o-keyframes f_fadeG{0%{background-color:rgb(0,0,0);}100%{background-color:rgb(255,255,255);}}\
                @-ms-keyframes f_fadeG{0%{background-color:rgb(0,0,0);}100%{background-color:rgb(255,255,255);}}\
                @-webkit-keyframes f_fadeG{0%{background-color:rgb(0,0,0);}100%{background-color:rgb(255,255,255);}}\
                @-moz-keyframes f_fadeG{0%{background-color:rgb(0,0,0);}100%{background-color:rgb(255,255,255);}}</style>";

            document.head.insertAdjacentHTML("beforeend", css);
            document.body.insertAdjacentHTML("afterbegin", html);

            var showLoadingTimer = setTimeout(function(){
                var loading = document.getElementById('alpuntodesal-loading');
                loading.className.replace('hide', '');
                clearTimeout(showLoadingTimer);
            }, 800);

        }else{
            var loading = document.getElementById('alpuntodesal-loading');
            if(loading != null){
                loading.parentElement.removeChild(loading);
            }
        }
    },

    /*
     * Lleva a cabo una petición AJAX
     * @params: {
     *      url: STRING,
     *      method: STRING,
     *      data: OBJECT
     *      callback: function
     * }
     */
    request: function (params){
        if(params.method != "GET" && params.method != "POST"
            && params.method != "PUT" && params.method != "DELETE"){
            console.log("Request params.method corrupt!");
            return false;
        }

        WPMEGACLOUD.load = true;
        setTimeout(function(){
            WPMEGACLOUD.loading(WPMEGACLOUD.load);
        }, 1000);

        var req = new XMLHttpRequest();
        req.open(params.method, params.url, true);

        if(typeof params.callback != "function"){
            req.onload = function (aEvt) {
                if(req.status !== 200){
                    console.log(req.responseText);
                }
                console.log(req.responseText);
            };
        }else{
            //req.onload = callback;
            req.onload = function (aEvt) {
                if(req.status !== 200){
                    console.log(req.responseText);
                }
                params.callback(this.responseText);
            };
        }

        req.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        var tmpArray = [];
        for(var key in params.params){
            tmpArray.push(key+"="+params.params[key]);
        }
        req.send(tmpArray.join("&"));
    }

};

window.addEventListener("load", function(){ 
    WPMEGACLOUD.init();
}, false);

