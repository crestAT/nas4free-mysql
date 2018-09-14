<?php
/* 
    mysql-config.php

	MySQL: Copyright (c) 2000, 2018, Oracle and/or its affiliates. All rights reserved.
	Oracle is a registered trademark of Oracle Corporation and/or its
	affiliates. Other names may be trademarks of their respective
	owners.
	
	mysqlinit add-on: Copyright (c) 2018 Jos� Rivera (JoseMR)
	All rights reserved.

    Copyright (c) 2017 - 2018 Andreas Schmidhuber
    All rights reserved.

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
$pidfile = "/var/tmp/mysql.sock.lock";
$logfile = "{$configuration['rootfolder']}/{$configName}_ext.log";
$logUpgrade = "{$configuration['rootfolder']}/{$configName}_upgrade.log";
$mySqlConfFile = "{$configuration['rootfolder']}/mysql/usr/local/etc/mysql/my.cnf";
$productVersion = exec("{$configuration['rootfolder']}/mysqlinit -v | awk '/-server/ {print $2}'");
$appName = "{$configuration['appname']} ".gettext("Server");			// for gettext
$clientDir = "adminer";													// alt: mywebsql  
// -----------------------------------------------------------------------

// Retrieve WebUI URL
$ipaddr = get_ipaddr($config['interfaces']['lan']['if']);
$url = htmlspecialchars("{$config['websrv']['protocol']}://{$ipaddr}:{$config['websrv']['port']}/{$clientDir}");
$urlWebUI = "<a href='{$url}' target='_blank'>{$url}</a>";

function get_process_info() {
    global $pidfile;
    if (exec("ps acx | grep -f $pidfile")) { $state = '<a style=" background-color: #00ff00; ">&nbsp;&nbsp;<b>'.gettext("running").'</b>&nbsp;&nbsp;</a>'; }
    else { $state = '<a style=" background-color: #ff0000; ">&nbsp;&nbsp;<b>'.gettext("stopped").'</b>&nbsp;&nbsp;</a>'; }
	return ($state);
}

function get_process_pid() {
    global $pidfile;
    exec("cat $pidfile", $state);
	return ($state[0]);
}

if (is_ajax()) {
	$procinfo['info'] = get_process_info();
	$procinfo['pid'] = get_process_pid();
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
		    $configuration['dbClient'] = isset($_POST['dbClient']);
			if (($configuration['port'] == "") || ($configuration['listen'] == ""))		// catch input errors
				$input_errors[] = sprintf(gettext("Parameter %s and/or %s must not be empty!"), gettext("Port"), gettext("IP Address"));
			else {
				$errorDbClient = " ";
				if ($configuration['dbClient'] && !is_file("{$config['websrv']['documentroot']}/{$clientDir}/index.php")) {	// install db administration client
				    #$return_val = mwexec("tar -xf {$configuration['rootfolder']}/mywebsql-3.7.zip -C {$config['websrv']['documentroot']}", true);
				    $return_val = mwexec("cp -R {$configuration['rootfolder']}/{$clientDir} {$config['websrv']['documentroot']}", true);
				    if ($return_val != 0) $input_errors[] = gettext("SQL Database Client")." ".gettext("Installation failed!");
				    else {
					    chown("{$config['websrv']['documentroot']}/{$clientDir}", "www");
					    chmod("{$config['websrv']['documentroot']}/{$clientDir}", 0775);
						$errorDbClient = "<br />".gettext("SQL Database Client")." ".gettext("has been installed.")."<br />";	
					}
				}
				// write mySQL config file
				$mySqlconf = fopen($mySqlConfFile, "w");
				fwrite($mySqlconf, "# WARNING: THIS IS AN AUTOMATICALLY CREATED FILE, DO NOT CHANGE THE CONTENT!\n");
				fwrite($mySqlconf, "[client]\nport = {$configuration['port']}\n\n");
				fwrite($mySqlconf, "[mysqld]\nport = {$configuration['port']}\n");
				fwrite($mySqlconf, "bind-address = {$configuration['listen']}\n");		
				fwrite($mySqlconf, str_replace("\r", "", $configuration['auxparam']));
				#fwrite($mySqlconf, "default_authentication_plugin = mysql_native_password\n");		// set in default params
				fclose($mySqlconf);
		        chmod($mySqlConfFile, 0644);

				$savemsg .= get_std_save_message(ext_save_config($configFile, $configuration)).$errorDbClient;	
			}
		}
	    else $savemsg .= get_std_save_message(ext_save_config($configFile, $configuration))." ";
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
		if (empty($input_errors)) {
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
	}

	if ((isset($_POST['restart']) && $_POST['restart']) || ((isset($_POST['save']) && $_POST['save']) && $configuration['enable'])) {
		if (empty($input_errors)) {
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

}

$pconfig['enable'] = $configuration['enable'];
$pconfig['port'] = !empty($configuration['port']) ? $configuration['port'] : "3306";
$pconfig['listen'] = !empty($configuration['listen']) ? $configuration['listen'] : "127.0.0.1";
$pconfig['auxparam'] = isset($configuration['auxparam']) ? $configuration['auxparam'] : "default_authentication_plugin = mysql_native_password\r\n";
$pconfig['backupPath'] = !empty($configuration['backupPath']) ? $configuration['backupPath'] : "{$configuration['rootfolder']}/backup";
$pconfig['dbClient'] = isset($configuration['dbClient']) ? $configuration['dbClient'] : true;

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
	document.iform.backupPath.disabled = endis;
	document.iform.backupPathbrowsebtn.disabled = endis;
	document.iform.dbClient.disabled = endis;
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
				html_titleline(gettext("Status TEST 9"));
            	html_text("installation_directory", gettext("Installation directory"), sprintf(gettext("The extension is installed in %s"), $configuration['rootfolder']));
				html_text("productversion", "{$appName} ".gettext("Version"), $productVersion, false);
			?>
            <tr>
				<td class="vncell"><?=gettext("Status");?></td>
				<td class="vtable"><span name="procinfo" id="procinfo"><?=get_process_info()?></span>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;PID:&nbsp;<span name="procinfo_pid" id="procinfo_pid"><?=get_process_pid()?></span></td>
            </tr>
            <?php
				html_separator();
				html_titleline_checkbox("enable", $configuration['appname'], $pconfig['enable'], gettext("Enable"), "enable_change(false)");
				html_inputbox("port", gettext("Port"), $pconfig['port'], sprintf(gettext("Port to listen on. Only dynamic or private ports can be used (from %d through %d). Default port is %d."), 1025, 65535, 3306), true, 5);
            	html_inputbox("listen", gettext("IP Address"), $pconfig['listen'], sprintf(gettext("IP address to listen on. Use 0.0.0.0 for all host IPs. Default is %s."), "127.0.0.1"), true, 25);
				html_textarea("auxparam", gettext("Additional Parameters"), $pconfig['auxparam'], sprintf(gettext("These parameters are added to the %s section of the %s configuration."), "[mysqld]", $configuration['appname']), false, 65, 3, false, false);
				html_filechooser("backupPath", gettext("Backup directory"), $pconfig['backupPath'], gettext("Directory to store archive.tar files of the mysqldata folder."), $pconfig['backupPath'], true, 60);
				html_checkbox("dbClient", gettext("SQL Database Client"), $pconfig['dbClient'], gettext("Use database administration client."));
				if ($configuration['dbClient']) html_text("url", gettext("SQL Database Client")." ".gettext("URL"), $urlWebUI, false);
			?>
        </table>
        <div id="submit">
			<input id="save" name="save" type="submit" class="formbtn" value="<?=gettext("Save");?>"/>
			<input name="start" type="submit" class="formbtn" title="<?=sprintf(gettext("Start %s"), $appName);?>" value="<?=gettext("Start");?>" />
			<input name="stop" type="submit" class="formbtn" title="<?=sprintf(gettext("Stop %s"), $appName);?>" value="<?=gettext("Stop");?>" />
			<input name="restart" type="submit" class="formbtn" title="<?=sprintf(gettext("Restart %s"), $appName);?>" value="<?=gettext("Restart");?>" />
			<input name="upgrade" type="submit" class="formbtn" title="<?=sprintf(gettext("Upgrade %s Packages"), $appName);?>" value="<?=gettext("Upgrade");?>" />
			<input name="backup" type="submit" class="formbtn" title="<?=sprintf(gettext("Backup %s Folder"), "MySQLdata");?>" value="<?=gettext("Backup");?>" />
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
