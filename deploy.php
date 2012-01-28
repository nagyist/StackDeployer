<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);
ini_set('memory_limit', '64M');

if (version_compare(PHP_VERSION, '5.0.0', '<')) {
	echo '  -- ERROR: PHP 5 is needed for this script to function correctly.' . "\r\n";
	die();
}
	
class StackDeployer {	
	private $config				= array();	
	private $info		        = array();

	private $ftp_connection     = false;
	private $created_ftp_dirs   = array();
	
	private $dropbox_connection = false;
	private $dropbox_cookies    = array();
	
	private $include_path       = '';
	
	public function run() {	
		if (!empty($_SERVER['PWD'])) {
			$this->include_path = $_SERVER['PWD'];
		} else {
			$this->include_path = getcwd();
		}
		
		$this->include_path = rtrim($this->include_path, '/') . '/';
				
		if (!file_exists($this->include_path . 'lib/deploy-config.php')) {
			echo '  -- ERROR: Please configure this script first. Open "' . $this->include_path . 'lib/deploy-config-sample.php" and follow instructions. Rename to deploy-config.php once done.' . "\r\n";
			die();
		}
		
		require $this->include_path . 'lib/deploy-config.php';
		
		$this->config = $config;
		
		if (!file_exists($this->include_path . 'lib/dsa_priv.pem')) {
			echo '  -- ERROR: dsa_priv.pem not found. Please add this to the "' . $this->include_path . '/lib" folder. Aborting.' . "\r\n";
			die();
		}
		
		if (!function_exists('shell_exec')) {
			echo '  -- ERROR: shell_exec function is not available on your system. This is necessary to generate the stack signature.' . "\r\n";
			die();
		}
		
		if (!function_exists('curl_exec')) {
			echo '  -- ERROR: curl_exec function is not available on your system. This is necessary to upload to dropbox and reset the cloudflare cache.' . "\r\n";
			die();
		}
		
		if (!extension_loaded('zip')) {
			echo '  -- ERROR: The PHP Zip extension is needed for this deploy script.' . "\r\n";
			die();
		}
		
		date_default_timezone_set($this->config['timezone']);
		
		$stacks = array();
		
		$options = getopt('f:d:o:g:iv', array('noupload', 'nopurgecloud', 'nopurgelocal', 'noappcast', 'noreleasenotes', 'nodmg', 'onlydmg'));

		if (!empty($options['f'])) {
			if (preg_match('/\.stack/i', $options['f'])) {
				$stack = trim($options['f']);
				
				if (!file_exists($stack)) {
					echo '  -- ERROR: ' . $stack . ' does not exist.' . "\r\n";
					die();
				}
				
				$stacks[] = $stack;
			} else {
				echo '  -- ERROR: Supplied filename is no stack (' . $options['f'] . ').' . "\r\n";
				die();
			}
		} else if (!empty($options['d'])) {
			$directory = trim($options['d']);
			if (file_exists($directory) && is_dir($directory)) {
				$stacks = glob(rtrim($directory, '/') . '/*.stack');
			} else {
				echo '  -- ERROR: Supplied directory does not exist.' . "\r\n";
				die();
			}
		} else {
			$stacks = glob('*.stack');
		}
		
		if (!empty($options['o'])) {
			$this->config['output_folder'] = trim($options['o']);
		}
			
		if (isset($options['i'])) {
			$handle_stacks = array();
			
			foreach ($stacks as $stack) {
				$m_time = date ("F d Y H:i:s", filemtime($stack));
				
				echo '* Deploy "' . $stack . '" - Last modified ' . $m_time . '? (yes/no)' . "\r\n" . ':';
				$value = trim(strtolower(fgets(STDIN)));
				
				if ($value == 'yes' || $value == 'y') {
					$handle_stacks[] = $stack;
				}
			}
			
			$stacks = $handle_stacks;
		}
	
		if (empty($stacks)) {
			echo '  -- ERROR: No stacks found. Please place stacks in ' . $_SERVER['HOME'] . '/StackDeployer/ or specify filenames/directory via the command line.' . "\r\n";
			die();
		}
	
		if (!empty($this->config['dropbox'])) {
			$this->config['options']['purgecloud'] = false;
		}
		
		if (isset($options['noupload'])) {
			$this->config['options']['upload']     = false;
			$this->config['options']['purgelocal'] = false;
		}
				
		if (isset($options['nopurgecloud'])) {
			$this->config['options']['purgecloud'] = false;
		}
		
		if (isset($options['nopurgelocal'])) {
			$this->config['options']['purgelocal'] = false;
		}
		
		if (isset($options['noappcast'])) {
			$this->config['options']['appcast'] = false;
		}
		
		if (isset($options['noreleasenotes'])) {
			$this->config['options']['releasenotes'] = false;
		}
		
		if (isset($options['v'])) {
			$this->config['options']['verbose'] = true;
		}
		
		if (isset($options['nodmg'])) {
			$this->config['options']['dmg'] = false;
		}
		
		if (isset($options['onlydmg'])) {
			$this->config['options']['dmg']     = true;
			$this->config['options']['appcast'] = false;
		}
		if (!empty($options['g'])) {
			$this->config['options']['dmg'] = true;
			$this->config['options']['dmg_group'] = trim($options['g']);
		} else if (empty($this->config['options']['dmg_group'])) {
			$this->config['options']['dmg_group'] = 'default';
		}
				
		if ($this->config['directories']['update_directory'][0] == '/' || $this->config['directories']['appcast_directory'][0] == '/' || $this->config['directories']['release_directory'][0] == '/') {
			echo '  -- ERROR: Directories for release notes, appcast and updates should be relative, not absolute (should not start with "/").' . "\r\n";
			die();
		}
		
		if (empty($this->config['directories']['update_directory']) || empty($this->config['directories']['appcast_directory']) || empty($this->config['directories']['release_directory'])) {
			echo '  -- ERROR: Not all directories have been specified.' . "\r\n";
			die();
		}	
		
		$this->config['output_folder'] = rtrim(trim($this->config['output_folder']), '/') . '/';
		
		if (empty($this->config['output_folder']) || $this->config['output_folder'] == '/' || (file_exists($this->config['output_folder']) && !is_dir($this->config['output_folder']))) {
			echo '  -- ERROR: Invalid output folder. Aborting.' . "\r\n";
			die();
		}
		
		if (!file_exists($this->config['output_folder'])) {
			mkdir($this->config['output_folder'], 0777, true);
		}
		
		$this->config['output_folder'] = str_replace('~', $_SERVER['HOME'], $this->config['output_folder']);
		
		//relative folder
		if ($this->config['output_folder'][0] != '/') {
			$this->config['output_folder'] = $this->include_path . $this->config['output_folder'];
		}
		
		$this->config['directories']['update_directory']  = rtrim(trim($this->config['directories']['update_directory']), '/') . '/';
		$this->config['directories']['appcast_directory'] = rtrim(trim($this->config['directories']['appcast_directory']), '/') . '/';
		$this->config['directories']['release_directory'] = rtrim(trim($this->config['directories']['release_directory']), '/') . '/';
						
		$cnt = count($stacks);
		$i   = 1;
				
		foreach ($stacks as $stack) {
			$success = $this->compile($stack);
						
			if ($i < $cnt) {
				echo "\r\n";
			}
			
			$i++;
		}
		
		if ($this->ftp_connection) {
			ftp_close($this->ftp_connection);
		}
	}

	private function compile($stack) {	
		echo '* Deploying stack "' . $stack . '"...' . "\r\n";
					
		if ($this->getInfo($stack)) {
			$success = true;
			
			$this->handleDirectoriesAndFiles();
			
			if ($this->config['options']['appcast']) {
				if (!$this->zip()) {
					return false;
				}

				$this->sign();
			
				if ($this->config['options']['appcast']) {
					$this->createAppcastXML();
				}
			
				if ($this->config['options']['releasenotes']) {
					$this->createReleaseNotes();
				}
						
				if ($this->config['options']['upload']) {
					$has_ftp = !empty($this->config['ftp']) && !empty($this->config['ftp']['server']) && !empty($this->config['ftp']['username']) && !empty($this->config['ftp']['password']);
					$has_dropbox = !empty($this->config['dropbox']) && !empty($this->config['dropbox']['email']) && !empty($this->config['dropbox']['password']);
				
					if ($has_ftp) {
						$success = $this->uploadFTP(); 
					}
					if ($has_dropbox) {
						$success = $this->uploadDropbox();
					} 
					if (!$has_ftp && !$has_dropbox) {
						echo '  -- ERROR: Please add your FTP or dropbox credentials to the config file (deploy-config.php).' . "\r\n";
						$this->config['options']['purgelocal'] = false;
						$success = false;
					}
				}
			
				if ($success && $this->config['options']['purgecloud']) {
					if (!empty($this->cloudflare)) {
						$this->purgeCloudflareCache();
					} else {
						echo '  -- ERROR: Please add your cloudflare settings to the config file (deploy-config.php).' . "\r\n";
					}
				}
			}
			
			if ($this->config['options']['dmg']) {				
				$this->createDMG($this->config['options']['dmg_group']);
			}
						
			if ($success && $this->config['options']['purgelocal']) {
				$this->purgeLocalCache();
			}
			
			return $success;
		} else {
			echo '  -- ERROR: Could not get all required information from the stack.' . "\r\n";
			return false;
		}
	}
	
	private function createDMG($group='default') {
		echo '  -- Creating DMG...' . "\r\n";
		
		$include_path = ($this->include_path ? $this->include_path : './');
		
		$dmg = $this->config['dmg'][$group];
		
		if (empty($dmg['format'])) {
			$dmg['format'] = 'default';
		}
		
		$dmg['format'] = strtolower($dmg['format']);
		
		if ($dmg['format'] != 'default' && $dmg['format'] != 'filestorm') {
			$dmg['format'] = 'default';
		}
		
		if (empty($dmg['format'])) {
			$dmg['format'] = 'default';
		} else {
			$dmg['format'] = strtolower($dmg['format']);
			
			if ($dmg['format'] != 'default' && $dmg['format'] != 'filestorm') {
				if (isset($dmg['installer_title']) || isset($dmg['installer_files'])) {
					$dmg['format'] = 'filestorm';
				} else {
					$dmg['format'] = 'default';
				}
			}
		}
		
		if (empty($dmg['window_width'])) {
			$dmg['window_width']  = 400;
		}
		
		if (empty($dmg['window_height'])) {	
			$dmg['window_height'] = 400;
		}
		
		if (empty($dmg['window_pos_x'])) {
			$dmg['window_pos_x'] = 200;
		}
		
		if (empty($dmg['window_pos_y'])) {
			$dmg['window_pos_y'] = 200;
		}
		
		if (empty($dmg['icon_size'])) {
			$dmg['icon_size'] = 128;
		}
		 
		$search  = array(':v:', ':version:', ':stack:', ':lstack:', ':stackfile:', ':title:');
		$replace = array($this->info['short_version'], $this->info['version'], $this->info['stack_name'], strtolower($this->info['stack_name']), $this->info['stack_filename'], $this->info['title']);
			
		$dmg['volume_name'] = str_ireplace($search, $replace, $dmg['volume_name']);
		
		$devices = shell_exec('df');
		
		if ($devices) {
			$devices = explode("\n", $devices);
			
			foreach ($devices as $device) {				
				$device = preg_split('/\s+/', $device, 6);
				
				if (!empty($device[5]) && preg_match('/^\/Volumes\/' . preg_quote($dmg['volume_name'], '/') . '\s*[0-9]*$/i', $device[5])) {
					shell_exec('hdiutil detach ' . trim($device[0]));
				}  
			}
		}
		
		if (file_exists($this->config['output_folder'] . 'rw.' . $this->info['stack_name'] . '.dmg')) {
			unlink($this->config['output_folder'] . 'rw.' . $this->info['stack_name'] . '.dmg');
		}
	
		if (file_exists($this->config['output_folder'] . $this->info['stack_name'] . '.dmg')) {
			unlink($this->config['output_folder'] . $this->info['stack_name'] . '.dmg');
		}
		
		if (empty($this->config['dmg'][$group])) {
			echo '  -- ERROR: Incorrect dmg settings group specified: "' . $group . '". Aborting DMG creation.' . "\r\n";
			return false;
		}
							
		if (!file_exists($include_path. 'lib/dmg/temp/')) {
			$suc = mkdir($include_path . 'lib/dmg/temp/', 0777);
		} else {
			$this->rrmdir($include_path . 'lib/dmg/temp/');
		}
				
		if (is_dir($include_path . 'lib/dmg/always/')) {
			$this->rcopy($include_path . 'lib/dmg/always/', $include_path . 'lib/dmg/temp/contents/');
		}
				
		if (is_dir($include_path . 'lib/dmg/conditional/' . $this->info['stack_name'])) {
			$this->rcopy($include_path . 'lib/dmg/conditional/' . $this->info['stack_name'], $include_path . 'lib/dmg/temp/contents/');
		} else if (is_dir($include_path . 'lib/dmg/conditional/' . strtolower($this->info['stack_name']))) {
			$this->rcopy($include_path . 'lib/dmg/conditional/' . strtolower($this->info['stack_name']), $include_path . 'lib/dmg/temp/contents/');
		}
		
		if (is_dir($include_path . 'lib/dmg/temp/contents/installer/')) {
			rename($include_path . 'lib/dmg/temp/contents/installer/', $include_path . 'lib/dmg/temp/installer/');
		} else {
			mkdir($include_path . 'lib/dmg/temp/installer/', 0777, true);
		}
		if (is_dir($include_path . 'lib/dmg/temp/contents/extras/')) {
			rename($include_path . 'lib/dmg/temp/contents/extras/', $include_path . 'lib/dmg/temp/extras/');
		} else {
			mkdir($include_path . 'lib/dmg/temp/extras/', 0777, true);
		}
		
		if ($dmg['format'] == 'default') {		
			$this->rcopy($this->info['stack_location'], $include_path . 'lib/dmg/temp/contents/' . $this->info['stack_filename']);
		} else {
			$this->rcopy($this->info['stack_location'], $include_path . 'lib/dmg/temp/installer/' . $this->info['stack_filename']);
		}
		
		$extras = array('background', 'installer_background', 'installer_icon', 'license_agreement');
		
		foreach ($extras as $extra) {
			if (!empty($dmg[$extra])) {
				if ($dmg[$extra][0] == '/' || $dmg[$extra][0] == '~') {
					if (!file_exists($dmg[$extra])) {
						$dmg[$extra] = '';
					}
				} else if (file_exists($include_path . 'lib/dmg/temp/extras/' . $dmg[$extra])) {
					$dmg[$extra] = $include_path . 'lib/dmg/temp/extras/' . $dmg[$extra];
				} else {
					$dmg[$extra] = '';
				}
			} else {
				$dmg[$extra] = '';
			}
		}
				
		$icons = array();
		
		if (!empty($dmg['icons'])) {
			foreach ($dmg['icons'] as $key => $icon) {
				$search  = array(':v:', ':version:', ':stack:', ':lstack:', ':stackfile:',':uid:', ':secret:');
				$replace = array($this->info['short_version'], $this->info['version'], $this->info['stack_name'], strtolower($this->info['stack_name']), $this->info['stack_filename'], $this->info['stack_uid'], md5($this->info['stack_name'] . $this->config['secret']));

				$icon['path'] = str_ireplace($search, $replace, $icon['path']);
				
				if (file_exists($include_path . 'lib/dmg/temp/contents/' . $icon['path'])) {
					$icons[] = $icon;
				} else if ($icon['path'] == ':installer:') {
					$dmg['installer_pos_x'] = $icon['pos_x'];
					$dmg['installer_pos_y'] = $icon['pos_y'];
				}
			}
		} 
							
		if ($dmg['format'] == 'default') {
			$command = ($this->include_path ? $this->include_path : './') . 'lib/create-dmg --window-pos ' . escapeshellcmd($dmg['window_pos_x']) . ' ' . escapeshellcmd($dmg['window_pos_y']) . ' --window-size ' . escapeshellcmd($dmg['window_width']) . ' ' . escapeshellcmd($dmg['window_height']) . (!empty($dmg['background']) ?  ' --background ' . escapeshellcmd($dmg['background']) : '') . ' --icon-size ' . escapeshellcmd($dmg['icon_size']) . ' --volname "' . escapeshellcmd($dmg['volume_name']) . '"';
		
			foreach ($icons as $icon) {
				$command .= ' --icon "' . escapeshellcmd($icon['path']) . '" ' . escapeshellcmd($icon['pos_x']) . ' ' . escapeshellcmd($icon['pos_y']);
			}
		
			$command .= ' ' . $this->config['output_folder'] . escapeshellcmd($this->info['stack_name']) . '.dmg "' . escapeshellcmd($include_path . 'lib/dmg/temp/contents/') . '"';
		
			$success = shell_exec($command);
		} else {
			/*
			-- set windowPosX to ' . $dmg['window_pos_x'] . '
			-- set windowPosY to ' . $dmg['window_pos_y'] . '
			-- set windowWidth to ' . $dmg['window_width'] . '
			-- set windowHeight to ' . $dmg['window_height'] . 
			-- (!empty($dmg['background']) ? 'set backgroundImage to "' . $dmg['background'] . '"' : 'set backgroundImage to ""') . '
			set iconSize to ' . $dmg['icon_size'] . '
			set volumeName to "' . $dmg['volume_name'] . '"
			set fileName to "' . $this->info['stack_name'] . '.dmg"
			*/
			
			$open_template = '/Users/Wesley/Desktop/test.fsproj';
	//		$open_template = $include_path . 'lib/dmg/template.fsproj';
			
			if (!file_exists($open_template)) {
				$open_template = false;
			}
			
	
			$script = '<<-EOF
				tell application "FileStorm"
					activate' . "\r\n";
			
			if ($open_template) {
				$script .= 'open POSIX file "' . $open_template . '"' . "\r\n";
			} else {
				$script .= 'make new document at before first document with properties {volume name:"' . $dmg['volume_name'] . '", disk image name:"' . $this->config['output_folder'] . $this->info['stack_name'] . '.dmg", width:' . $dmg['window_width'] . ', height:' . $dmg['window_height'] . ', window left position:' . $dmg['window_pos_x'] . ', window top position:' . $dmg['window_pos_y'] . (!empty($dmg['background']) ? ', background image path:"' . $dmg['background'] . '"' : '') . ', icon size:' . $dmg['icon_size'] . ', open volume:true}' . "\r\n";
			}

			$script .= "\r\n" . 'tell first document' . "\r\n";
			
			foreach ($icons as $icon) {
				$script .= 'make new file at before first file with properties {file path:"' . $include_path . 'lib/dmg/temp/' . $icon['path'] . '", left position:' . $icon['pos_x'] . ', top position:' . $icon['pos_y'] . '}' . "\r\n";
			}
			
			//add all other files that weren't specified in the icons array..
			if ($handle = opendir($include_path . 'lib/dmg/temp/contents/')) {
			    while (false !== ($entry = readdir($handle))) {
			        if ($entry != '.' && $entry != '..' && $entry[0] != '.' && strtolower($entry) != 'installer' && strtolower($entry) != 'extras') {
						$already_included = false;
						
						foreach ($icons as $icon) {
							if ($icon['path'] == $entry) {
								$already_included = true;
								break;
							}
						}
						
						if (!$already_included) {
							$script .= 'make new file at before first file with properties {file path:"' . $include_path . 'lib/dmg/temp/contents/' . $entry . '"}' . "\r\n";
						}
			        }
			    }
			    closedir($handle);
			}
			
			$dmg['installer']['files'] = array();
			
			if (is_dir($include_path . 'lib/dmg/temp/contents/installer/')) {
				if ($handle = opendir($dir)) {
			    	while (false !== ($entry = readdir($handle))) {
			        	if ($entry != '.' && $entry != '..' && $entry[0] != '.') {						
							$file = array('filename' => $entry, 'path' => $dir . $entry);

							$lowercase = strtolower($entry);

							if ($lowercase == 'readme.txt') {
								$file['type'] = 0; //README TXT
							} else if ($lowercase == 'readme.html') {
								$file['type'] = 0; 
							} else if ($lowercase == 'license.txt') {
								$file['type'] = 0;
							} else if (preg_match('/\.sh/i', $lowercase)) {
								$file['type'] = 0;
							} else if (preg_match('\/.stack/i', $lowercase)) {
								$file['type'] = 0;
								$file['install_path'] = '~/Library/Application Support/RapidWeaver/Stacks/';
							} else {
								echo '  -- ERROR: Unknown file: "' . $entry . '" - Ignoring.' . "\r\n";
								continue;
							}

							$dmg['installer']['files'][] = $file;
				    	}
					}	
					closedir($handle);
				}
			}
						
			if (!empty($dmg['license_agreement'])) {
				$script .= 'make new license agreement at before first license agreement with properties {language:"English", text file path:"' . $dmg['license_agreement'] . '"}' . "\r\n";
			}

			if (!empty($dmg['installer'])) {
				if (!$open_template) {
					$script .= 'make new installer at before first installer with properties {choose volume:false, requires bootable volume:false, requires admin:false, create uninstaller:false, requires authentication:false, installer name:"' . $dmg['volume_name'] . ' Installer"' . (!empty($dmg['installer_icon']) ? ', custom icon path:"' . $dmg['installer_icon'] . '"' : '') . (!empty($dmg['installer_background']) ? ', background path:"' . $dmg['installer_background'] . '"' : '') . (!empty($dmg['installer_pos_x']) ? ', left position:' . $dmg['installer_pos_x'] . ', top position: ' . $dmg['installer_pos_y'] : '') . '}' . "\r\n";
				} 
				
				$script .= 'tell first installer' . "\r\n";
				
				if ($open_template) {
					$script .= 'set the choose volume to false;
								set the requires bootable volume to false
								set the requires admin to false
								set the create uninstaller to false
								set the requires authentication to false
								set the volume name to "' . $dmg['volume_name'] . '"
								set the installer name to "' . $dmg['volume_name'] . ' Installer"' . 
								(!empty($dmg['installer_icon']) ? 'set the custom icon path:"' . $dmg['installer_icon'] . '"' : '') . 
								(!empty($dmg['installer_background']) ? 'set the background path:"' . $dmg['installer_background'] . '"' : '') . 
								(!empty($dmg['installer_pos_x']) ? 'set the left position:' . $dmg['installer_pos_x'] . "\r\n" . ' set the top position: ' . $dmg['installer_pos_y'] : '') . "\r\n";
				}
				
				foreach ($dmg['installer']['files'] as $file) {
					$script .= 'make new action at before first action with properties {action item file path:"' . $file['path'] . '"' . (!empty($file['install_path']) ? ', install path:"' . $file['install_path'] . '"' : '') . ', type:' . $file['type'] . '}' . "\r\n";
				}
				
				$script .= 'end tell' . "\r\n";
			}
			
			$tracker_file = '/Users/Wesley/Desktop/tracker.txt';

			$script .= '
						-- build image with replacing
						finalize image with rebuilding

						repeat while building
							delay 1
						end repeat
					end tell
					quit
				end tell
				
				set trackerFile to POSIX path of file "' . $tracker_file . '"

				try
					close access file trackerFile
				end try

				set openFile to open for access file trackerFile with write permission

				write "--DONE--" to file trackerFile
				close access file trackerFile
			EOF';
			
			echo $script;
								
			/*
			--exit script parameter (text) : This is the parameter to be passed to the shell script that gets run when the installer exits.
			-- exit script path (text) : This is the path to the shell script to be run when the installer exits.

			--launch script parameter (text) : This is the parameter to be passed to the shell script that gets run at launch time.
			--launch script path (text) : This is the path to the shell script to be run at launch time.
			*/
		
			shell_exec('osascript ' . $script);
			
			$start = time();

			$still_not_done = false;
			
			//check for tracker file... if not found in 20 seconds, then ask the user..
			while (!file_exists($tracker_file)) {
				sleep(1);
				
				$end = time();
				
				if ($end - $start > 20) {
					$still_not_done = true;
					break;
				}
			}
			
			if ($still_not_done) {
				do {
					echo '  -- QUESTION: Is DMG creation done? (yes/no)' . "\r\n";
					$value = trim(strtolower(fgets(STDIN)));
					if (!empty($value[0])) {
						$value = $value[0];
					} else {
						$value = 'n';
					}
				} while ($value != 'y');
			} else {
				unlink($tracker_file);
			}
		}
			
		$this->rrmdir($include_path . 'lib/dmg/temp/');
		
		$success = file_exists($this->config['output_folder'] . $this->info['stack_name'] . '.dmg');
		
		if ($success) {
			echo '  -- Done creating DMG.' . "\r\n";
		} else {
			echo '  -- ERROR: DMG creation failed.' . "\r\n";
		}
		
		return $success;
	}
	
	private function rrmdir($dir) {
		$dir = trim($dir);
		
		if (!$dir || $dir == '.' || $dir == '..' || $dir == '/' || substr_count($dir, '/') < 3)  {
			echo '  -- FAILURE: rrmdir.' . "\r\n";
			die();
		}
		
   		if (is_dir($dir)) {
     		$objects = scandir($dir);
     		foreach ($objects as $object) {
       			if ($object != '.' && $object != '..') {
         			if (filetype($dir . '/' . $object) == 'dir') {
						$this->rrmdir($dir . '/' . $object); 
					} else {
						unlink($dir . '/' . $object);
					}
       			}
     		}
     		reset($objects);
     		rmdir($dir);
   		}
 	}

	function rcopy($src, $dst, $empty=false) {
		if ($empty) {
			$this->rrmdir($dst);
		}
		if (is_dir($src)) {
			if (!is_dir($dst)) {
				mkdir($dst, 0777, true);
			}
			$files = scandir($src);
		    foreach ($files as $file) {
				if ($file != '.' && $file != '..') {
					$this->rcopy($src . '/' . $file, $dst . '/' . $file);
				}
			}
		} else if (file_exists($src)) {
			copy($src, $dst);
		}
	}
	
	private function handleDirectoriesAndFiles() {
		if (!file_exists($this->config['output_folder'])) {
			$success = mkdir($this->config['output_folder'], 0777, true);
		}
		
		if (!$this->config['options']['appcast']) {
			return;
		}
		
		if ($this->config['files_and_directories'] == 'workman-v1') {
			$this->config['directories'] = array('release_directory' => 'appcasts:api1:/:stack:/', 
												 'appcast_directory' => 'appcasts:api1:/:stack:/', 
												 'update_directory'  => 'appcasts:api1:/:stack:/');
			
			$this->config['files'] = array('release_file' => 'notes.html', 
						 			       'appcast_file' => 'appcast.xml', 
										   'update_file'  => ':stack:.zip');
										
		} else if ($this->config['files_and_directories'] == 'workman-v2') {
			$this->config['directories'] = array('release_directory' => 'appcasts:api2:/:stack:_:secret:/', 
												 'appcast_directory' => 'appcasts:api2:/:stack:_:secret:/', 
												 'update_directory'  => 'appcasts:api2:/:stack:_:secret:/');
												
			$this->config['files'] = array('release_file' => 'notes.html', 
						 			       'appcast_file' => 'appcast.xml', 
										   'update_file'  => ':stack:.zip');
		}
		
		$search  = array(':v:', ':version:', ':stack:', ':lstack:', ':uid:', ':secret:');
		$replace = array($this->info['short_version'], $this->info['version'], $this->info['stack_name'], strtolower($this->info['stack_name']), $this->info['stack_uid'], md5($this->info['stack_name'] . $this->config['secret']));
										
		$this->info['release_directory'] = str_ireplace($search, $replace, $this->config['directories']['release_directory']);
		$this->info['appcast_directory'] = str_ireplace($search, $replace, $this->config['directories']['appcast_directory']);
		$this->info['update_directory']  = str_ireplace($search, $replace, $this->config['directories']['update_directory']);
		
		if (count($this->info['compatibility']) == 1) {
			$this->info['release_directory'] = str_ireplace(':api:', $this->info['min_api'], $this->info['release_directory']);
			$this->info['appcast_directory'] = str_ireplace(':api:', $this->info['min_api'], $this->info['appcast_directory']);
			$this->info['update_directory'] = str_ireplace(':api:', $this->info['min_api'], $this->info['update_directory']);
			
			if ($this->info['min_api'] >= 3) {
				$this->info['release_directory'] = str_ireplace(':api1:', '-' . $this->info['min_api'], $this->info['release_directory']);
				$this->info['appcast_directory'] = str_ireplace(':api1:', '-' . $this->info['min_api'], $this->info['appcast_directory']);
				$this->info['update_directory'] = str_ireplace(':api1:', '-' . $this->info['min_api'], $this->info['update_directory']);
			} else {
				$this->info['release_directory'] = str_ireplace(':api1:', '', $this->info['release_directory']);
				$this->info['appcast_directory'] = str_ireplace(':api1:', '', $this->info['appcast_directory']);
				$this->info['update_directory'] = str_ireplace(':api1:', '', $this->info['update_directory']);
			}
			
			if ($this->info['min_api'] >= 3) {
				$this->info['release_directory'] = str_ireplace(':api2:', '-' . $this->info['min_api'], $this->info['release_directory']);
				$this->info['appcast_directory'] = str_ireplace(':api2:', '-' . $this->info['min_api'], $this->info['appcast_directory']);
				$this->info['update_directory'] = str_ireplace(':api2:', '-' . $this->info['min_api'], $this->info['update_directory']);
			} else {
				$this->info['release_directory'] = str_ireplace(':api2:', '-2', $this->info['release_directory']);
				$this->info['appcast_directory'] = str_ireplace(':api2:', '-2', $this->info['appcast_directory']);
				$this->info['update_directory'] = str_ireplace(':api2:', '-2', $this->info['update_directory']);
			}
		} else if (preg_match('/:api[12]?:/', $this->info['release_directory'] . $this->info['appcast_directory'] . $this->info['update_directory'])) {
			$release_directories = array();
			$appcast_directories = array();
			$update_directories  = array();
			
			for ($i=$this->info['min_api']; $i<=3; $i++) {
				$api_replace = $i;
				
				if ($i >= 3) {
					$api1_replace = '-' . $i;
					$api2_replace = '-' . $i;
				} else {
					$api1_replace = '';
					$api2_replace = '-2';
				}
				
				$release_directories[] = str_ireplace(array(':api:', ':api1:', ':api2:'), array($api_replace, $api1_replace, $api2_replace), $this->info['release_directory']);
				$appcast_directories[] = str_ireplace(array(':api:', ':api1:', ':api2:'), array($api_replace, $api1_replace, $api2_replace), $this->info['appcast_directory']);
				$update_directories[] = str_ireplace(array(':api:', ':api1:', ':api2:'), array($api_replace, $api1_replace, $api2_replace), $this->info['update_directory']);
			}
			
			$release_directories = array_unique($release_directories);
			$appcast_directories = array_unique($appcast_directories);
			$update_directories  = array_unique($update_directories);
			
			$this->info['release_directory'] = array_shift($release_directories);
			$this->info['appcast_directory'] = array_shift($appcast_directories);
			$this->info['update_directory']  = array_shift($update_directories);
			
			if (!empty($release_directories)) {
				$this->info['more_release_directories'] = $release_directories;
			}
			if (!empty($appcast_directories)) {
				$this->info['more_appcast_directories'] = $appcast_directories;
			}
			if (!empty($update_directories)) {
				$this->info['more_update_directories'] = $update_directories;
			}
		}
		
		$this->info['release_file'] = str_replace($search, $replace, $this->config['files']['release_file']);
		$this->info['appcast_file'] = str_replace($search, $replace, $this->config['files']['appcast_file']);
		$this->info['update_file'] = str_replace($search, $replace, $this->config['files']['update_file']);
				
		if (!file_exists($this->config['output_folder'] . $this->info['release_directory'])) {
			$success = mkdir($this->config['output_folder'] . $this->info['release_directory'], 0777, true);
		}
		
		if (!empty($this->info['more_release_directories'])) {
			foreach ($this->info['more_release_directories'] as $dir) {
				if (!file_exists($this->config['output_folder'] . $dir)) {
					mkdir($this->config['output_folder'] . $dir, 0777, true);
				}
			}
		}
		if (!file_exists($this->config['output_folder'] . $this->info['appcast_directory'])) {
			mkdir($this->config['output_folder'] . $this->info['appcast_directory'], 0777, true);
		}
		if (!empty($this->info['more_appcast_directories'])) {
			foreach ($this->info['more_appcast_directories'] as $dir) {
				if (!file_exists($this->config['output_folder'] . $dir)) {
					mkdir($this->config['output_folder'] . $dir, 0777, true);
				}
			}
		}
		if (!file_exists($this->config['output_folder'] . $this->info['update_directory'])) {
			mkdir($this->config['output_folder'] . $this->info['update_directory'], 0777, true);
		}
		if (!empty($this->info['more_update_directories'])) {
			foreach ($this->info['more_update_directories'] as $dir) {
				if (!file_exists($this->config['output_folder'] . $dir)) {
					mkdir($this->config['output_folder'] . $dir, 0777, true);
				}
			}
		}
	}
	
	private function getInfo($stack) {
		$this->info['stack_location']  = $stack;
		$this->info['stack_directory'] = rtrim(dirname($this->info['stack_location']), '/') . '/';
		$this->info['stack_filename']  = basename($stack);
		
		$contents = file_get_contents($this->info['stack_location'] . '/Contents/Info.plist');
		
		if (!$contents) {
			echo '  -- ERROR: Could not read Info.plist in stack.' . "\r\n";
			return false;
		}
				
		if (preg_match('/<key>CFBundleVersion<\/key>\s*<string>([0-9\.]+)<\/string>/isU', $contents, $match)) {
			$this->info['version'] = trim($match[1]);
		}
		
		if (preg_match('/<key>CFBundleShortVersionString<\/key>\s*<string>([0-9\.]+)<\/string>/isU', $contents, $match)) {
			$this->info['short_version'] = trim($match[1]);
		} else {
			$this->info['short_version'] = $this->info['version'];
		}
		
		if (preg_match('/<key>SUFeedURL<\/key>\s*<string>([^<]+)<\/string>/isU', $contents, $match)) {
			$this->info['feed_url'] = trim($match[1]);
		}
		
		if (preg_match('/<key>title<\/key>\s*<string>([^<]+)<\/string>/isU', $contents, $match)) {
			$this->info['title'] = trim($match[1]);
		}
		
		if (preg_match('/<key>infoURL<\/key>\s*<string>([^<]+)<\/string>/isU', $contents, $match)) {
			$this->info['url'] = trim($match[1]);
		}
		
		if (preg_match('/<key>minAPIVersion<\/key>\s*<string>([^<]+)<\/string>/isU', $contents, $match)) {
			$this->info['min_api'] = trim($match[1]);
		} else {
			$this->info['min_api'] = 0;
			echo '  -- WARNING: No minAPIVersion specified in stack Info.plist. Assuming this stack can be used in both Stacks 1 and 2.' . "\r\n";
		}
		
		if (preg_match('/<key>maxAPIVersion<\/key>\s*<string>([^<]+)<\/string>/isU', $contents, $match)) {
			$this->info['max_api'] = trim($match[1]);
		} else {
			$this->info['max_api'] = 0;
		}
		
		if ($this->info['min_api'] < 3) {
			if ($this->info['max_api'] && $this->info['max_api'] < 3) {
				$this->info['compatibility'] = array(1);
				echo '  -- Stack is compatible with Stacks 1 only.' . "\r\n";
			} else {
				$this->info['compatibility'] = array(1, 2);
				echo '  -- Stack is compatible with both Stacks 1 and 2.' . "\r\n";
			}
		} else {
			$this->info['compatibility'] = array(2);
			echo '  -- Stack is compatible with Stacks 2 only.' . "\r\n";
		}
		
		if (preg_match('/<key>SUPublicDSAKeyFile<\/key>\s*<string>([^<]+)<\/string>/isU', $contents, $match)) {
			$this->info['public_key'] = trim($match[1]);
			if (!file_exists($this->info['stack_location'] . '/Contents/Resources/' . $this->info['public_key'])) {
				echo '  -- ERROR: Public key ' . $this->info['public_key'] . ' does not exist in the stack (/Contents/Resources/' . $this->info['public_key'] . ').' . "\r\n";
				die();
			}
		} else {
			$this->info['public_key'] = false;
		}
		
		if (empty($this->info['version']) || empty($this->info['feed_url']) || empty($this->info['title']) || empty($this->info['url'])) {
			return false;
		}
		
		$this->info['stack_name'] = str_replace('.stack', '', basename($this->info['stack_location']));
	
		if ($this->config['use_stack_uid']) {
			if (preg_match('/\?uid\=(.*)$/isU', $this->info['feed_url'], $match)) {
				$this->info['stack_uid'] = trim($match[1]);
			} else {
				echo '  -- ERROR: Could not find stack UID.' . "\r\n";
				return false;
			}
		} else {
			$this->info['stack_uid'] = $this->info['stack_name'];
		}
					
		$this->info['pub_date'] = date('D, d M Y H:i:s O');
		
		return true;
	}
	
	private function zip() {
		$zip = new ZipArchive();
	
		if (!$zip->open($this->config['output_folder'] . $this->info['update_directory'] . $this->info['update_file'], ZIPARCHIVE::CREATE)) {
			echo '  -- ERROR: Could not create zip archive ' . $this->info['update_file'] . '.' . "\r\n";
			return false;
		}
				
		if (!is_dir($this->info['stack_location'])) {
			echo '  -- ERROR: Seems like the stack ' . $this->info['stack_location'] . ' is not a directory.' . "\r\n";
			return false;
		}
		
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->info['stack_location']));
		
		$start = false;
		
		$stack_position = strpos($this->info['stack_location'], $this->info['stack_name']);
		
		if (!$stack_position) {
			echo '  -- ERROR: Can\'t find stack name in stack location.' . "\r\n";
			die();
		}
		
		foreach ($files as $file) {			
			if (is_dir($file) === true) {
				$zip->addEmptyDir(substr($file, $stack_position) . '/');
			} else if (is_file($file) === true) {	
				$zip->addFromString(substr($file, $stack_position), file_get_contents($file));
			}
		}

		$success = $zip->close();
		
		$this->info['update_file_size'] = filesize($this->config['output_folder'] . $this->info['update_directory'] . $this->info['update_file']);
		
		if (!$this->info['update_file_size']) {
			echo '  -- Could not determine file size of zipped stack.' . "\r\n";
			return false;
		}
		
		if (!empty($this->info['more_update_directories'])) {
			foreach ($this->info['more_update_directories'] as $dir) {
				copy($this->config['output_folder'] . $this->info['update_directory'] . $this->info['update_file'], $this->config['output_folder'] . $dir . $this->info['update_file']);
			}
		}
		
		if ($success) {
			echo '  -- Stack zipped: ' . $this->info['update_file'] . ' (' . $this->info['update_file_size'] . ' bytes)' . "\r\n";
		} else {
			echo '  -- ERROR: Could not zip stack ' . $this->info['update_file'] . "\r\n";
		}
		
		return $success;
	}

	private function sign() {
		if (!$this->info['public_key']) {
			echo '  -- WARNING: This stack won\'t be signed.' . "\r\n";
			return false;
		}
		
		$this->info['signature'] = trim(shell_exec('openssl dgst -sha1 -binary < "' . $this->config['output_folder'] . $this->info['update_directory'] . $this->info['update_file'] . '" | openssl dgst -dss1 -sign "' . $this->include_path . 'lib/dsa_priv.pem" | openssl enc -base64 | sed s/\\\//\\\\\\\\\\\//g'));
		
		if (!$this->info['signature']) {
			echo '  -- ERROR: Could not generate stack signature.' . "\r\n";
			die();
		} else if (preg_match('/\s/', $this->info['signature'])) {
			echo '  -- ERROR: Invalid stack signature generated: ' . $this->info['signature'] . '.' . "\r\n";
			die();
		} else {
			echo '  -- Signature generated: ' . $this->info['signature'] . "\r\n";
		}
		
		return true;
	}

	private function createAppcastXML() {	
		$xml = '<?xml version="1.0" encoding="UTF-8"?>
		<rss xmlns:atom="http://www.w3.org/2005/Atom" xmlns:sparkle="http://www.andymatuschak.org/xml-namespaces/sparkle" version="2.0">
			<channel>
				<title>' . $this->info['title'] . ' Stack By ' . $this->config['title'] . '</title>
	    		<description>' . $this->info['title'] . ' Stack Updates</description>
	    		<link>' . $this->info['url'] . '</link>
	    		<language>en</language>
				<pubDate>' . $this->info['pub_date'] . '</pubDate>
	    		<atom:link type="application/rss+xml" href="' . $this->info['feed_url'] . '" rel="self"/>
	    		<item>
	      			<title>' . $this->info['title'] . ' Stack Version ' . $this->info['version'] . '</title>
	      			<sparkle:releaseNotesLink>' . $this->config['url'] . $this->info['release_directory'] . $this->info['release_file'] . '</sparkle:releaseNotesLink>
					<pubDate>' . $this->info['pub_date'] . '/pubDate>
	      			<guid isPermaLink="false">' . $this->info['title'] . ' Stack ' . $this->info['version'] . '</guid>
	            	<enclosure url="' . $this->config['url'] . $this->info['update_directory'] . $this->info['update_file'] . '" length="' . $this->info['update_file_size'] . '" type="application/zip" sparkle:version="' . $this->info['version'] . '" sparkle:shortVersionString="' . $this->info['short_version'] . '" sparkle:dsaSignature="' . $this->info['signature'] . '"/>
	    		</item>
			</channel>
		</rss>';
	
		//need to remove first because if switching case the case is not changed..
		if (file_exists($this->config['output_folder'] . $this->info['appcast_directory'] . $this->info['appcast_file'])) {
			unlink($this->config['output_folder'] . $this->info['appcast_directory'] . $this->info['appcast_file']);
		}
		
		$success = file_put_contents($this->config['output_folder'] . $this->info['appcast_directory'] . $this->info['appcast_file'], $xml);
		
		if ($success) {
			if (!empty($this->info['more_appcast_directories'])) {
				foreach ($this->info['more_appcast_directories'] as $dir) {
					copy($this->config['output_folder'] . $this->info['appcast_directory'] . $this->info['appcast_file'], $this->config['output_folder'] . $dir . $this->info['appcast_file']);
				}
			}
			
			echo '  -- Appcast XML generated: ' . $this->info['appcast_file'] . "\r\n";
		} else {
			echo '  -- ERROR: Could not generate appcast XML ' . $this->info['appcast_file'] . "\r\n";
		}
		
		return $success;
	}
	
	private function createReleaseNotes() {
		if (file_exists($this->config['output_folder'] . $this->info['release_directory'] . $this->info['release_file'])) {
			unlink($this->config['output_folder'] . $this->info['release_directory'] . $this->info['release_file']);
		}
		if (!file_exists($this->info['stack_location'] . '/Contents/Resources/changelog.txt')) {
			if (file_exists($this->info['stack_directory'] . $this->info['stack_name'] . '.html')) {
				$success = file_put_contents($this->config['output_folder'] . $this->info['release_directory'] . $this->info['release_file'], file_get_contents($this->info['stack_directory'] . $this->info['stack_name'] . '.html'));
				if ($success) {
					if (!empty($this->info['more_release_directories'])) {
						foreach ($this->info['more_release_directories'] as $dir) {
							copy($this->config['output_folder'] . $this->info['release_directory'] . $this->info['release_file'], $this->config['output_folder'] . $dir . $this->info['release_file']);
						}
					}
					echo '  -- Release notes HTML generated: Copied from ' . $this->info['stack_directory'] . $this->info['stack_name'] . '.html' . "\r\n";
					return true;
				} else {
					echo '  -- ERROR: Could not generate release notes HTML from ' . $this->info['stack_directory'] . $this->info['stack_name'] . '.html' . "\r\n";
					return false;
				}
			} else if (file_exists($this->info['stack_location'] . '/Contents/Resources/changelog.html')) {
				$success = file_put_contents($this->config['output_folder'] . $this->info['release_directory'] . $this->info['release_file'], file_get_contents($this->info['stack_location'] . '/Contents/Resources/changelog.html'));
				if ($success) {
					if (!empty($this->info['more_release_directories'])) {
						foreach ($this->info['more_release_directories'] as $dir) {
							copy($this->config['output_folder'] . $this->info['release_directory'] . $this->info['release_file'], $this->config['output_folder'] . $dir . $this->info['release_file']);
						}
 					}
					echo '  -- Release notes HTML generated: Copied from ' . $this->info['stack_location'] . '/Contents/Resources/changelog.html' . "\r\n";
					return true;
				} else {
					echo '  -- ERROR: Could not generate release notes HTML from ' . $this->info['stack_location'] . '/Contents/Resourcees/changelog.html' . "\r\n";
					return false;
				}
				
			} else {
				echo '  -- ERROR: No changelog.txt/html found in stack, nor ' . $this->info['stack_name'] . '.html in the same directory as the stack. Cannot create release notes.' . "\r\n";
				return false;
			}
		}
		
		$contents = file_get_contents($this->info['stack_location'] . '/Contents/Resources/changelog.txt');

		if (!$contents) {
			echo '  -- ERROR: changelog.txt in stack is empty. Cannot create release notes.' . "\r\n";
			return false;
		} else if (!file_exists($this->include_path . 'lib/release_notes_template.html')) {
			echo '  -- ERROR: lib/release_notes_template.html does not exist. This is necessary to generate the HTML file.' . "\r\n";
			return false;
		}
		
		$template = file_get_contents($this->include_path . 'lib/release_notes_template.html');
		
		$template = str_replace(':stack:', $this->info['stack_name'], $template);
				
		$lines = array();
		
		$symbols = array('list' => array('*','#','+','^','-','!'), 
						  '*' => '',
						  '#' => 'Bug Fix:',
						  '+' => '<strong>New</strong>:',
						  '^' => 'Changed:',
						  '-' => 'Removed:',
						  '!' => 'Note:');
					
		$previous_position = 0;
		
		$length = strlen($contents);
		
		for ($position=0; $position<$length; $position++) {
			if ($contents[$position] == "\n") {
				$line = substr($contents, $previous_position, $position-$previous_position);
				//a summary follows on from the previous line
				if (substr($line, 0, 3) == '   ') {
					$prev_line = count($lines)-1;
					$lines[$prev_line] .= substr($line, 3);
				} else {
					$line = trim($line, " \r\n");
					if ($line != '') {
						$lines[] = $line;
					}
				}
				
				$previous_position = $position + 1;
			}
		}
		
		$last_line = trim(substr($contents, $previous_position), " \r\n");
		
		if ($last_line) {
			$lines[] = $last_line;
		}

		$entry_open = false;
		$entry_number = 0;
		
		$entries = array();
		
		foreach ($lines as $line) {
			if (is_numeric($line[0])) {
				if ($entry_open) {
					$entry_open = false;
					$entry_number++;
				}
				
				$date = substr($line, 0, 11);	
				$line = str_replace($date . ' ', '', $line);
				$date = date('Y/m/d', strtotime($date));	
				$version = $line;
				$line = '';
				$entry_open = true;
				
				$entries[$entry_number] = array('meta' => array('date' => $date, 'version' => $version), 'changes' => array());				
			} else if (in_array($line[0], $symbols['list'])) {
				$type = $line[0];
				
				$line = substr($line, 2);
				
				$entries[$entry_number]['changes'][] = array('type' => $type, 'summary' => $line);
			}
		}
		
		$html = '';
				
		foreach ($entries as $entry) {
			$html .= '<h2>' . $this->info['title'] . ' - ' . $entry['meta']['version'] . '</h2>' . "\r\n";
			$html .= '<h3>Release Date</b>: ' . $entry['meta']['date'] . '</h3>' . "\r\n";
			$html .= '<ol>' . "\r\n";
			foreach ($entry['changes'] as $change) {
				
				$html .= '<li>' . $symbols[$change['type']] . ' ' . $change['summary'] . '</li>';
			}
			$html .= '</ol>';
		}
		
		$template = str_replace(':changelog:', $html, $template);
		
		$success = file_put_contents($this->config['output_folder'] . $this->info['release_directory'] . $this->info['release_file'], $template);
		
		if ($success) {
			if (!empty($this->info['more_release_directories'])) {
				foreach ($this->info['more_release_directories'] as $dir) {
					copy($this->config['output_folder'] . $this->info['release_directory'] . $this->info['release_file'], $this->config['output_folder'] . $dir . $this->info['release_file']);
				}
			}
			echo '  -- Release notes HTML generated: ' . $this->info['release_file'] . "\r\n";
		} else {
			echo '  -- ERROR: Could not generate release notes HTML ' . $this->info['release_file'] . "\r\n";
		}
		
		return $success;
	}
	
	private function uploadFTP() {
		echo '  -- Uploading via FTP' . (!empty($this->info['more_update_directories']) ? ' (multiple appcast directories)' : ' (single appcast directory)') . '...' . "\r\n";
		
		if (!$this->ftp_connection) {
			if (empty($this->config['ftp']['server_port'])) {
				$this->config['ftp']['server_port'] = 21;
			}
		
			$this->ftp_connection = ftp_connect($this->config['ftp']['server'], $this->config['ftp']['server_port']);
		
			if (!$this->ftp_connection) {
				echo '  -- ERROR: Could not connect to FTP server.' . "\r\n";
				return false;
			}
		
			$login = ftp_login($this->ftp_connection, $this->config['ftp']['username'], $this->config['ftp']['password']);
		
			if (!$login) {
				$this->ftp_connection = false;
				
				echo '  -- ERROR: Could not login to FTP server.' . "\r\n";
				return false;
			}
		}
		
		$todo = array();
				
		$todo[] = array('source' => $this->info['update_directory'] . $this->info['update_file'], 'destination' => $this->config['ftp']['public_directory'] . $this->info['update_directory'] . $this->info['update_file']);
		
		if ($this->config['options']['appcast']) {
			$todo[] = array('source' => $this->config['output_folder'] . $this->info['appcast_directory'] . $this->info['appcast_file'], 'destination' => $this->config['ftp']['public_directory'] . $this->info['appcast_directory'] . $this->info['appcast_file']);
		}
		
		if ($this->config['options']['releasenotes']) {
			$todo[] = array('source' => $this->config['output_folder'] . $this->info['release_directory'] . $this->info['release_file'], 'destination' => $this->config['ftp']['public_directory'] . $this->info['release_directory'] . $this->info['release_file']);
		}
		
		if (!empty($this->info['more_update_directories'])) {
			foreach ($this->info['more_update_directories'] as $dir) {
				$todo[] = array('source' => $this->config['output_folder'] . $dir . $this->info['update_file'], 'destination' => $this->config['ftp']['public_directory'] . $dir . $this->info['update_file']);
			}
		}
		
		if ($this->config['options']['appcast'] && !empty($this->info['more_appcast_directories'])) {
			foreach ($this->info['more_appcast_directories'] as $dir) {
				$todo[] = array('source' => $this->config['output_folder'] . $dir . $this->info['appcast_file'], 'destination' => $this->config['ftp']['public_directory'] . $dir . $this->info['appcast_file']);
			}
		}
		
		if ($this->config['options']['releasenotes'] && !empty($this->info['more_release_directories'])) {
			foreach ($this->info['more_release_directories'] as $dir) {
				$todo[] = array('source' => $this->config['output_folder'] . $dir . $this->info['release_file'], 'destination' => $this->config['ftp']['public_directory'] . $dir . $this->info['release_file']);
			}
		}
		
		$error = false;
		
		foreach ($todo as $file) {
			$directory = dirname($file['destination']);
			
			if (!in_array($directory, $this->created_ftp_dirs)) {
				$this->makeFTPDirectory($directory);
				$this->created_ftp_dirs[] = $directory;
			}

			if (preg_match('/\.zip/i', $file['source'])) {
				$mode = FTP_BINARY;
			} else {
				$mode = FTP_ASCII;
			}
			
			if (file_exists($file['source'])) {
				$success = ftp_put($this->ftp_connection, $file['destination'], $file['source'], $mode);
				
				if ($this->config['options']['verbose']) {
					echo '  -- Uploading "' . $file['source'] . '"' . ($success ? ' - OK' : ' - FAIL') . "\r\n";
				}
			} 
			
			if (!$success) {
				$error = true;
				echo '  -- ERROR: Could not upload ' . $file['source'] . ' to ' . $file['destination'] . '.' . "\r\n";
				break;
			}
		}
		
		if (!$error) {
			echo '  -- Uploaded successfully.' . "\r\n";
		}
				
		return !$error;
	}
	
	private function makeFTPDirectory($directory) {		
		$original_directory = ftp_pwd($this->ftp_connection);
		
		if (@ftp_chdir($this->ftp_connection, $directory)) {
			ftp_chdir($this->ftp_connection, $original_directory);
			return true;
		} else {
			if (@ftp_mkdir($this->ftp_connection, $directory)) {
				return true;
			}
			
			if (!$this->makeDirectory($this->ftp_connection, dirname($directory))) {
				return false;
			}
			
			return ftp_mkdir($this->ftp_connection, $directory);
		}
	}	
	
	private function uploadDropbox() {
		echo '  -- Uploading to DropBox' . (!empty($this->info['more_update_directories']) ? ' (multiple appcast directories)' : ' (single appcast directory)') . '...' . "\r\n";
		
		if (!$this->dropbox_connection) {
			$data = $this->request('https://www.dropbox.com/login');
		
			if (!preg_match('/<form [^>]*login[^>]*>.*?(<input [^>]*name="t" [^>]*value="(.*?)"[^>]*>).*?<\/form>/is', $data, $matches) || !isset($matches[2])) {
				echo '  -- ERROR: Cannot extract token from DropBox. Upload aborted.';
				return false;
			} else {
				$token = trim($matches[2]);
			}
             
        	$data = $this->request('https://www.dropbox.com/login', true, array('login_email' => $this->config['dropbox']['email'], 'login_password' => $this->config['dropbox']['password'], 't' => $token));
        
        	if (stripos($data, 'location: /home') === false) {
				echo '  -- ERROR: Could not log in to dropbox. Upload aborted.';
				return false;
			} else {
				$this->dropbox_connection = true;
			}
        }

		$todo = array();
		
		$todo[] = array('source' => $this->info['update_directory'] . $this->info['update_file'], 'destination' => $this->info['update_directory']);
		
		if ($this->config['options']['appcast']) {
			$todo[] = array('source' => $this->config['output_folder'] . $this->info['appcast_directory'] . $this->info['appcast_file'], 'destination' => $this->info['appcast_directory']);
		}
		
		if ($this->config['options']['releasenotes']) {
			$todo[] = array('source' => $this->config['output_folder'] . $this->info['release_directory'] . $this->info['release_file'], 'destination' => $this->info['release_directory']);
		}
		
		if (!empty($this->info['more_update_directories'])) {
			foreach ($this->info['more_update_directories'] as $dir) {
				$todo[] = array('source' => $this->config['output_folder'] . $dir . $this->info['update_file'], 'destination' => $this->config['ftp']['public_directory'] . $dir . $this->info['update_file']);
			}
		}
		
		if ($this->config['options']['appcast'] && !empty($this->info['more_appcast_directories'])) {
			foreach ($this->info['more_appcast_directories'] as $dir) {
				$todo[] = array('source' => $this->config['output_folder'] . $dir . $this->info['appcast_file'], 'destination' => $this->config['ftp']['public_directory'] . $dir . $this->info['appcast_file']);
			}
		}
		
		if ($this->config['options']['releasenotes'] && !empty($this->info['more_release_directories'])) {
			foreach ($this->info['more_release_directories'] as $dir) {
				$todo[] = array('source' => $this->config['output_folder'] . $dir . $this->info['release_file'], 'destination' => $this->config['ftp']['public_directory'] . $dir . $this->info['release_file']);
			}
		}

		$error = false;
		
		foreach ($todo as $file) {
			if (file_exists($file['source'])) {
				$data = $this->request('https://www.dropbox.com/home');
			
				if (!preg_match('/<form [^>]*https:\/\/dl\-web\.dropbox\.com\/upload[^>]*>.*?(<input [^>]*name="t" [^>]*value="(.*?)"[^>]*>).*?<\/form>/is', $data, $matches) || !isset($matches[2])) {
					$error = true;
					echo '  -- ERROR: Cannot extract token from DropBox. Upload aborted.';
					break;
				} else {
					$token = trim($matches[2]);
				}
			
				$data = $this->request('https://dl-web.dropbox.com/upload', true, array('plain'=>'yes', 'file'=> '@' . $file['source'], 'dest'=> $file['destination'], 't' => $token));
	        
				if (strpos($data, 'HTTP/1.1 302 FOUND') === false) {
					$error = true;
					if ($this->config['options']['verbose']) {
						echo '  -- Uploading "' . $file['source'] . '" - FAIL' . "\r\n";
					}
					echo '  -- ERROR: Upload to DropBox failed.' . "\r\n";
					break;
				} else if ($this->config['options']['verbose']) {
					echo '  -- Uploading "' . $file['source'] . '" - OK' . "\r\n";
				}
			}
		}
		
		if (!$error) {
			echo '  -- Uploaded successfully.' . "\r\n";
		}
		
		return !$error;
	}
	
	private function purgeCloudflareCache() {
		$contents = $this->request('https://www.cloudflare.com/api_json.html?a=fpurge_ts&z=' . $this->config['cloudflare']['zone'] . '&email=' . $this->config['cloudflare']['email'] . '&v=1&t=' . $this->config['cloudflare']['token']);
	
		if (!preg_match('/success/isU', $contents)) {
			echo '  -- ERROR: Could not purge cloudflare cache.' . "\r\n";
			return false;
		}
	}
	
	private function purgeLocalCache() {	
		$this->rrmdir($this->config['output_folder'] . $this->info['appcast_directory']);
		
		if (!empty($this->info['more_appcast_directories'])) {
			foreach ($this->info['more_appcast_directories'] as $dir) {
				$this->rrmdir($this->config['output_folder'] . $dir);
			}
		}
		
		$this->rrmdir($this->config['output_folder'] . $this->info['release_directory']);
		
		if (!empty($this->info['more_release_directories'])) {
			foreach ($this->info['more_release_directories'] as $dir) {
				$this->rrmdir($this->config['output_folder'] . $dir);
			}
		}
		
		$this->rrmdir($this->config['output_folder'] . $this->info['update_directory']);
				
		if (!empty($this->info['more_update_directories'])) {
			foreach ($this->info['more_update_directories'] as $dir) {
				$this->rrmdir($this->config['output_folder'] . $dir);
			}
		}
	}
	
	private function request($url, $post=false, $postData=array()) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);

		if (stripos($url, 'https')) {
        	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
		
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        
		if ($post) {
            curl_setopt($ch, CURLOPT_POST, $post);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

		if (stripos($url, 'dropbox')) {
			curl_setopt($ch, CURLOPT_HEADER, 1);
			
			if (!empty($this->dropbox_cookies)) {
        		$rawCookies = array();
        		foreach ($this->dropbox_cookies as $k=>$v) {
            		$rawCookies[] = "$k=$v";
				}
        		$rawCookies = implode(';', $rawCookies);
        		curl_setopt($ch, CURLOPT_COOKIE, $rawCookies);
        	}
		} else if (stripos($url, 'closure-compiler')) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));  
		}
		
        $data = curl_exec($ch);
        
		$this->http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($data === false) {
            throw new Exception('Cannot execute request: '.curl_error($ch));
        }

		if (stripos($url, 'dropbox')) {
        	preg_match_all('/Set-Cookie: ([^=]+)=(.*?);/i', $data, $matches, PREG_SET_ORDER);
        	foreach ($matches as $match) {
            	$this->dropbox_cookies[$match[1]] = $match[2];
        	}
		}
		
        curl_close($ch);
        
        return $data;
    }
    
}

$deployer = new StackDeployer();

$deployer->run();
?>