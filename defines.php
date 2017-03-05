<?php

//define("DB_TABLE", "wpmega");
define("PLUGIN_URL", WP_PLUGIN_URL."/wpmegacloud/");
define("WPMC_FOLDER_USER_ID", 0);
define("MEDIA_LIBRARY_URI", "/wp-admin/upload.php");

//Namespace & REST Routes
define("WPMC_NAMESPACE", "wpmegacloud");
//META_KEYS
define("WPMC_NODE_META_KEY", "wpmegacloud_node");
define("WPMC_USER_FOLDER_META_KEY", "wpmegacloud_user_folder");

//OPTIONS
define("WPMC_SESSION_OPTION", "wpmegacloud_session");
define("WPMC_MAIL_OPTION", "wpmegacloud_mail");
define("WPMC_ROOT_FOLDER_OPTION", "wpmegacloud_folder");
define("WPMC_ALLOW_NO_WPMC_FILES_OPTION", "wpmegacloud_allow_no_wp_mega_files");

//define("WPMEGA_REMOVE_OPTION", "wpmega_remove");
define("WPMC_ROOT_FOLDER_VISIBILITY_OPTION", "wpmegacloud_root_node_visible");
define("WPMC_TRASH_BIN_VISIBILITY_OPTION", "wpmegacloud_tras_bin_visible");
define("WPMC_UPLOAD_OPTION", "wpmegacloud_upload");
    define("WPMC_UPLOAD_ONLY_USERS", "0");
    define("WPMC_UPLOAD_ONLY_ADMIN", "1");
    define("WPMC_UPLOAD_BOTH", "2");
    define("WPMC_UPLOAD_NOBODY", "3");
define("WPMC_REMOVE_LOCAL_FILES_OPTION", "wpmegacloud_remove_local_files");
define("WPMC_REMOVE_MEGA_FILES_OPTION", "wpmegacloud_remove_mega_files");

//ERROR CODE
define("NO_ERROR", "0");
define("ERROR_NOT_ALLOWED", "1");
define("ERROR_NO_FILE_FOUND", "2");
define("ERROR_NO_POST_FOUND", "3");
define("ERROR_NO_FILE_REMOVED", "4");
define("ERROR_NO_FILE_UPLOADED", "5");


