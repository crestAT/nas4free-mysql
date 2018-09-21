<?php
/* 
    mysql-config.php

    Copyright (c) 2017 - 2018 Andreas Schmidhuber
    All rights reserved.

	Mysqlinit addon: Copyright (c) 2018 José Rivera (JoseMR)
	All rights reserved.

	Portions of Plex Media Server Extension: Copyright (c) 2018 José Rivera (JoseMR)
	All rights reserved.

	MySQL: Copyright (c) 2000, 2018, Oracle and/or its affiliates. All rights reserved.
	Oracle is a registered trademark of Oracle Corporation and/or its
	affiliates. Other names may be trademarks of their respective
	owners.
	
    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice, this
       list of conditions and the following disclaimer.
    2. Redistributions in binary form must reproduce the above copyright notice,
       this list of conditions and the following disclaimer in the documentation
       and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
    ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
    WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
    DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
    ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
    (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
    LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
    ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
    (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
    SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/
require("auth.inc");
require("guiconfig.inc");

$configName = "mysql";
$configFile = "ext/{$configName}/{$configName}.conf";
require_once("ext/{$configName}/extension-lib.inc");

$domain = strtolower(get_product_name());
$localeOSDirectory = "/usr/local/share/locale";
$localeExtDirectory = "/usr/local/share/locale-{$configName}";
bindtextdomain($domain, $localeExtDirectory);

// Dummy standard message gettext calls for xgettext retrieval!!!
$dummy = gettext("The changes have been applied successfully.");
$dummy = gettext("The configuration has been changed.<br />You must apply the changes in order for them to take effect.");
$dummy = gettext("The following input errors were detected");

if (($configuration = ext_load_config($configFile)) === false) $input_errors[] = sprintf(gettext("Configuration file %s not found!"), "{$configName}.conf");
if (!isset($configuration['rootfolder']) && !is_dir($configuration['rootfolder'] )) $input_errors[] = gettext("Extension installed with fault");

$pgtitle = array(gettext("Extensions"), $configuration['appname']." ".$configuration['version'], gettext("Configuration"));

if (!isset($configuration) || !is_array($configuration)) $configuration = array();

// initialize variables --------------------------------------------------
$logfile = "{$configuration['rootfolder']}/{$configName}_ext.log";
$logBackupDate = "{$configuration['rootfolder']}/{$configName}_backup-date.txt";
$logUpgrade = "{$configuration['rootfolder']}/{$configName}_upgrade.log";
$mySqlConfFile = "{$configuration['rootfolder']}/mysql/usr/local/etc/mysql/my.cnf";		// /usr/local/etc/mysql/my.cnf on full
$productVersion = exec("{$configuration['rootfolder']}/mysqlinit -v | awk '/ Ver / {print $3}'");
$appName = "{$configuration['appname']} ".gettext("Server");			// for gettext
$clientDir = "adminer";													// alt: mywebsql
$backupFailedMsg = "\<font color='red'\>\<b\>".gettext("Last backup failed!")."\<\/b\>\<\/font\>";  
// -----------------------------------------------------------------------

// Retrieve WebUI URL
$ipaddr = get_ipaddr($config['interfaces']['lan']['if']);
$url = htmlspecialchars("{$config['websrv']['protocol']}://{$ipaddr}:{$config['websrv']['port']}/{$clientDir}");
$urlWebUI = "<a href='{$url}' target='_blank'>{$url}</a>";

// check if SQL client is installable / able to run
if (!isset($config['websrv']['enable']) || !is_dir($config['websrv']['documentroot'])) {
	$webServerReady = false;
	$webServerMsg = " &#8594; ".sprintf(gettext("The %s cannot be installed / used if the webserver is not properly set up and running!"), gettext("SQL Database Administration Client"));	
}
else {
	$webServerReady = true;
	$webServerMsg = "";
}

function get_backup_info() {
    global $logBackupDate;
	return exec("cat {$logBackupDate}");	
}

function get_process_info() {
    if (exec("pgrep mysqld")) { $state = '<a style=" background-color: #00ff00; ">&nbsp;&nbsp;<b>'.gettext("running").'</b>&nbsp;&nbsp;</a>'; }
    else { $state = '<a style=" background-color: #ff0000; ">&nbsp;&nbsp;<b>'.gettext("stopped").'</b>&nbsp;&nbsp;</a>'; }
	return ($state);
}

function get_process_pid() {
    exec("pgrep mysqld", $state);
	return ($state[0]);
}

if (is_ajax()) {
	$procinfo['info'] = get_process_info();
	$procinfo['pid'] = get_process_pid();
	$procinfo['backup'] = get_backup_info();
	render_ajax($procinfo);
}

if ($_POST) {
	$date = strftime('%c');												// for log output
	if (isset($_POST['save']) && $_POST['save']) {
	    unset($input_errors);
	    $configuration['enable'] = isset($_POST['enable']);
	    if (isset($_POST['enable'])) {
			$configuration['port'] = $_POST['port'];
			$configuration['listen'] = $_POST['listen'];
			$configuration['auxparam'] = $_POST['auxparam'];
			$configuration['backuppath'] = $_POST['backuppath'];
		    $configuration['dbclient'] = isset($_POST['dbclient']);
			if (($configuration['port'] == "") || ($configuration['listen'] == ""))		// catch input errors
				$input_errors[] = sprintf(gettext("Parameter %s and/or %s must not be empty!"), gettext("Port"), gettext("IP Address"));
			else {
				// db client installation
				$dbClientMsg = " ";
				if ($configuration['dbclient'] && $webServerReady && !is_file("{$config['websrv']['documentroot']}/{$clientDir}/index.php")) {	// install db administration client
				    #$return_val = mwexec("tar -xf {$configuration['rootfolder']}/mywebsql-3.7.zip -C {$config['websrv']['documentroot']}", true);
				    $return_val = mwexec("cp -R {$configuration['rootfolder']}/{$clientDir} {$config['websrv']['documentroot']}", true);
				    if ($return_val != 0) $input_errors[] = gettext("SQL Database Client")." ".gettext("Installation failed!");
				    else {
					    chown("{$config['websrv']['documentroot']}/{$clientDir}", "www");
					    chmod("{$config['websrv']['documentroot']}/{$clientDir}", 0775);
						$dbClientMsg = "<br />".gettext("SQL Database Client")." ".gettext("has been installed.");	
					}
				} elseif (is_file("{$config['websrv']['documentroot']}/{$clientDir}/index.php")) mwexec("rm -R {$config['websrv']['documentroot']}/{$clientDir}");  
				// write mySQL config file
				if (is_dir("{$configuration['rootfolder']}/mysql")) {	// on full: $mySqlConfFile = "/usr/local/etc/mysql/my.cnf";
					$mySqlconf = fopen($mySqlConfFile, "w");
					fwrite($mySqlconf, "# WARNING: THIS IS AN AUTOMATICALLY CREATED FILE, DO NOT CHANGE THE CONTENT!\n");
					fwrite($mySqlconf, "[client]\nport = {$configuration['port']}\n\n");
					fwrite($mySqlconf, "[mysqld]\nport = {$configuration['port']}\n");
					fwrite($mySqlconf, "bind-address = {$configuration['listen']}\n");
					fwrite($mySqlconf, str_replace("\r", "", $configuration['auxparam']));
					#fwrite($mySqlconf, "default_authentication_plugin = mysql_native_password\n");		// set in default params
					fclose($mySqlconf);
			        chmod($mySqlConfFile, 0644);
				}  

				$savemsg .= get_std_save_message(ext_save_config($configFile, $configuration)).$dbClientMsg."<br />";	
			}
		}
	    else $savemsg .= get_std_save_message(ext_save_config($configFile, $configuration))."<br />";
	}

	if (isset($_POST['start']) && $_POST['start']) {
		$return_val = mwexec("{$configuration['rootfolder']}/mysqlinit -s", true);
		if ($return_val == 0) {
			$savemsg .= sprintf(gettext("%s started successfully."), $appName);
			exec("echo '{$date}: {$configuration['appname']} successfully started' >> {$logfile}");
		}
		else {
			$input_errors[] = sprintf(gettext("%s startup failed."), $appName);
			exec("echo '{$date}: {$configuration['appname']} startup failed' >> {$logfile}");
		}
	}

	if ((isset($_POST['stop']) && $_POST['stop']) || ((isset($_POST['save']) && $_POST['save']) && !$configuration['enable'])) {
		$return_val = mwexec("{$configuration['rootfolder']}/mysqlinit -p", true);
		if ($return_val == 0) {
			$savemsg .= sprintf(gettext("%s stopped successfully."), $appName);
			exec("echo '{$date}: {$configuration['appname']} successfully stopped' >> {$logfile}");
		}
		else {
			$input_errors[] = sprintf(gettext("%s stop failed."), $appName);
			exec("echo '{$date}: {$configuration['appname']} stop failed' >> {$logfile}");
		}
	}

	if ((isset($_POST['restart']) && $_POST['restart']) || ((isset($_POST['save']) && $_POST['save']) && $configuration['enable'])) {
		$return_val = mwexec("{$configuration['rootfolder']}/mysqlinit -r", true);
		if ($return_val == 0) {
			$savemsg .= sprintf(gettext("%s restarted successfully."), $appName);
			exec("echo '{$date}: {$configuration['appname']} successfully restarted' >> {$logfile}");
		}
		else {
			$input_errors[] = sprintf(gettext("%s restart failed."), $appName);
			exec("echo '{$date}: {$configuration['appname']} restart failed' >> {$logfile}");
		}
	}

	if(isset($_POST['upgrade']) && $_POST['upgrade']):
		$cmd = sprintf('%1$s/mysqlinit -u > %2$s', $configuration['rootfolder'], $logUpgrade);
		$return_val = 0;
		$output = [];
		exec($cmd, $output, $return_val);
		if($return_val == 0):
			ob_start();
			include($logUpgrade);
			$ausgabe = ob_get_contents();
			ob_end_clean();
			$savemsg .= str_replace("\n", "<br />", $ausgabe);
		else:
			$input_errors[] = gettext('An error has occurred during upgrade process.');       
			$cmd = sprintf('echo %s: %s An error has occurred during upgrade process. >> %s', $date, $configuration['appname'], $logfile);
			exec($cmd);       
		endif;
	endif;

	if (isset($_POST['backup']) && $_POST['backup']) {
		$backup_script = fopen("{$configuration['rootfolder']}/{$configName}-backup.sh", "w");
		fwrite($backup_script, "#!/bin/sh"."\n# WARNING: THIS IS AN AUTOMATICALLY CREATED SCRIPT, DO NOT CHANGE THE CONTENT!\n");
		fwrite($backup_script, "# Command for cron backup usage: {$configuration['rootfolder']}/{$configName}-backup.sh\n");
		fwrite($backup_script, "logger {$appName} backup started"."\n");
		fwrite($backup_script, "tar -czf {$configuration['backuppath']}/mysqldata-`date +%Y-%m-%d-%H%M%S`.tar.gz -C {$configuration['rootfolder']} mysqldata"."\n");
		fwrite($backup_script, "if [ $? == 0 ]; then"."\n");
		fwrite($backup_script, "    logger {$appName} backup successfully finished"."\n");
		fwrite($backup_script, "    date > {$logBackupDate}"."\n");
		fwrite($backup_script, "else"."\n");
		fwrite($backup_script, "    logger {$appName} backup failed"."\n");
		fwrite($backup_script, "    echo {$backupFailedMsg} > {$logBackupDate}"."\n");
		fwrite($backup_script, "fi"."\n");
		fclose($backup_script);
		chmod("{$configuration['rootfolder']}/{$configName}-backup.sh", 0755);
		$savemsg .= sprintf(gettext("Command for cron backup usage: %s"), "{$configuration['rootfolder']}/{$configName}-backup.sh").".<br />";

		$return_val = mwexec("mkdir -p -m 777 {$configuration['backuppath']}", true);	// create dir if not exists and set mode to 777
		if (is_dir($configuration['backuppath'])) {
			$return_val = mwexec("nohup {$configuration['rootfolder']}/{$configName}-backup.sh >/dev/null 2>&1 &", true);
			if ($return_val == 0) {
				exec("echo ".gettext("Backup in progress!")." > {$logBackupDate}");
				$savemsg .= gettext("Backup process started in background, the output goes to the system log.");
				exec("echo '{$date}: Backup process started in background.' >> {$logfile}");
			} else $input_errors[] = gettext("Backup process could not start!");
		} else {
			$input_errors[] = gettext("Backup directory could not be created!");
			exec("echo '{$date}: Backup directory could not be created!' >> {$logfile}");
		}
	}
}

$pconfig['enable'] = $configuration['enable'];
$pconfig['port'] = !empty($configuration['port']) ? $configuration['port'] : "3306";
$pconfig['listen'] = !empty($configuration['listen']) ? $configuration['listen'] : "127.0.0.1";
$pconfig['auxparam'] = isset($configuration['auxparam']) ? $configuration['auxparam'] : "default_authentication_plugin = mysql_native_password\r\n";
$pconfig['backuppath'] = !empty($configuration['backuppath']) ? $configuration['backuppath'] : "{$configuration['rootfolder']}/backup";
$pconfig['dbclient'] = isset($configuration['dbclient']) ? $configuration['dbclient'] : true;

if (($message = ext_check_version("{$configuration['rootfolder']}/version_server.txt", "{$configName}", $configuration['version'], gettext("Maintenance"))) !== false) $savemsg .= $message;

bindtextdomain($domain, $localeOSDirectory);
include("fbegin.inc");
bindtextdomain($domain, $localeExtDirectory);
?>
<script type="text/javascript">//<![CDATA[
$(document).ready(function(){
	var gui = new GUI;
	gui.recall(0, 2000, '<?php echo $configName; ?>-config.php', null, function(data) { 
		$('#procinfo').html(data.info);
		$('#procinfo_pid').html(data.pid);
		$('#procinfo_backup').html(data.backup);
	});
});
//]]>
</script>
<script type="text/javascript">
<!--
function enable_change(enable_change) {
    var endis = !(document.iform.enable.checked || enable_change);
	document.iform.port.disabled = endis;
	document.iform.listen.disabled = endis;
	document.iform.auxparam.disabled = endis;
	document.iform.backuppath.disabled = endis;
	document.iform.backuppathbrowsebtn.disabled = endis;
	document.iform.dbclient.disabled = endis;
	document.iform.start.disabled = endis;
	document.iform.stop.disabled = endis;
	document.iform.restart.disabled = endis;
	document.iform.upgrade.disabled = endis;
	document.iform.backup.disabled = endis;
}
//-->
</script>
<form action="<?php echo $configName; ?>-config.php" method="post" name="iform" id="iform" onsubmit="spinner()">
    <table width="100%" border="0" cellpadding="0" cellspacing="0">
	<tr><td class="tabnavtbl">
		<ul id="tabnav">
			<li class="tabact"><a href="<?php echo $configName; ?>-config.php"><span><?=gettext("Configuration");?></span></a></li>
			<li class="tabinact"><a href="<?php echo $configName; ?>-update_extension.php"><span><?=gettext("Maintenance");?></span></a></li>
		</ul>
	</td></tr>
    <tr><td class="tabcont">
        <?php if (!empty($input_errors)) print_input_errors($input_errors);?>
        <?php if (!empty($savemsg)) print_info_box($savemsg);?>
        <table width="100%" border="0" cellpadding="6" cellspacing="0">
            <?php 
				html_titleline(gettext("Status"));
            	html_text("installation_directory", gettext("Installation directory"), sprintf(gettext("The extension is installed in %s"), $configuration['rootfolder']));
				html_text("productversion", "{$appName} ".gettext("Version"), $productVersion, false);
			?>
            <tr>
				<td class="vncell"><?=gettext("Status");?></td>
				<td class="vtable"><span id="procinfo"><?=get_process_info()?></span>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;PID:&nbsp;<span id="procinfo_pid"><?=get_process_pid()?></span></td>
            </tr>
            <tr>
				<td class="vncell"><?=gettext("Last Backup");?></td>
				<td class="vtable"><span id="procinfo_backup"><?=get_backup_info()?></span></td>
            </tr>
            <?php
				html_separator();
				html_titleline_checkbox("enable", $configuration['appname'], $pconfig['enable'], gettext("Enable"), "enable_change(false)");
				html_inputbox("port", gettext("Port"), $pconfig['port'], sprintf(gettext("Port to listen on. Only dynamic or private ports can be used (from %d through %d). Default port is %d."), 1025, 65535, 3306), true, 5);
            	html_inputbox("listen", gettext("IP Address"), $pconfig['listen'], sprintf(gettext("IP address to listen on. Use 0.0.0.0 for all host IPs. Default is %s."), "127.0.0.1"), true, 25);
				html_textarea("auxparam", gettext("Additional Parameters"), $pconfig['auxparam'], sprintf(gettext("These parameters are added to the %s section of the %s configuration."), "[mysqld]", $configuration['appname']), false, 65, 3, false, false);
				html_filechooser("backuppath", gettext("Backup Directory"), $pconfig['backuppath'], sprintf(gettext("Directory to store compressed archive files of the %s folder."), "mysqldata"), $pconfig['backuppath'], true, 60);
				html_checkbox("dbclient", gettext("SQL Database Administration Client"), $pconfig['dbclient'], gettext("Install the client.")." ".$webServerMsg);
				if ($configuration['dbclient'] && $webServerReady) html_text("url", "&#9493;&#9472;&#9472;&nbsp;".gettext("URL"), $urlWebUI, false);
			?>
        </table>
        <div id="remarks">
            <?php html_remark("note_user", gettext("Note"), sprintf(gettext("Default database administrator is set to %s with password %s."), "<b>mysqladmin</b>", "<b>mysqladmin</b>"));?>
        </div>
        <div id="submit">
			<input id="save" name="save" type="submit" class="formbtn" value="<?=gettext("Save");?>"/>
			<input name="start" type="submit" class="formbtn" title="<?=sprintf(gettext("Start %s"), $appName);?>" value="<?=gettext("Start");?>" />
			<input name="stop" type="submit" class="formbtn" title="<?=sprintf(gettext("Stop %s"), $appName);?>" value="<?=gettext("Stop");?>" />
			<input name="restart" type="submit" class="formbtn" title="<?=sprintf(gettext("Restart %s"), $appName);?>" value="<?=gettext("Restart");?>" />
			<input name="upgrade" type="submit" class="formbtn" title="<?=sprintf(gettext("Upgrade %s Packages"), $appName);?>" value="<?=gettext("Upgrade");?>" />
			<input name="backup" type="submit" class="formbtn" title="<?=sprintf(gettext("Backup %s Data Folder"), "{$configuration['appname']}");?>" value="<?=gettext("Backup");?>" />
        </div>
	</td></tr>
	</table>
	<?php include("formend.inc");?>
</form>
<script type="text/javascript">
<!--
enable_change(false);
//-->
</script>
<?php include("fend.inc");?>
