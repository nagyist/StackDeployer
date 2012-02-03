<?php
/*
BE SURE TO RENAME THIS TO DEPLOY-CONFIG.PHP (LOWERCASE) AFTER YOU HAVE CONFIGURED IT!
*/

$config = array();

/*
These two variables are used in the appcast.xml creation 
*/
$config['url']   = 'http://weaveraddons.com/';
$config['title'] = 'WeaverAddons.com';

/*
For compatibility with Workman's appcast.php v2 script (due for release late january 2012). Make sure it lines up with the secret key you have in Workman's script.
If you're not using the v2 script, feel free to ignore.
*/
$config['secret'] = 'my-s3cr3t-k3y';

/*
You may wish to change this time zone to your own.. For a list, go here: http://php.net/manual/en/timezones.php
*/
$config['timezone'] = 'Europe/Brussels';

/*
The folder where you want appcasts and/or DMGs to go to. Defaults to an "output/" folder in the same directory as deploy.php
If using a relative path, it will always be relative to the folder where deploy.php is. 
If you want the files on your desktop, use ~/Desktop/output/ (for example)
*/
$config['output_folder'] = 'output/';

/*
If set to manual, you will have to configure the files and directories manually at the bottom of this file.
If set to workman-v1, the script will be compatible with the appcast.php from Workman released in October 2011.
If set to workman-v2, the script will be compatible with the appcast.php from Workman released in January 2012.
*/
$config['files_and_directories'] = 'workman-v1';

/*
set to your liking:
  - appcast = true will create the necessary appcast files (xml, release notes, zipped stack)
  - upload = true will upload via FTP
  - purgecloud = true will clear your cloudflare cache (if you have it)
  - purgelocal = true will delete the locally generated files
  - createdmg = true will create a DMG file automatically
  - appcast = true will create the appcast XML file
  - releasenotes = true will create the releasenotes HTML file
  - verbose = true will output full file path of each file uploaded.
  - dmg = create a DMG also
*/	
$config['options'] = array('appcast'	  => true,
						   'upload'       => true, 
						   'purgecloud'   => false, 
						   'purgelocal'   => false, 
						   'appcast'      => true, 
						   'releasenotes' => true, 
						   'verbose'	  => false, 
						   'dmg'	      => true);

/*
If you have cloudflare, you can have this set up so that it will clear the cloudflare cache after upload. 
Options are: 
  - zone
  - email
  - token
(Available at cloudflare.com)
*/  
$config['cloudflare'] = array('zone'  => '', 
							  'email' => '', 
							  'token' => '');
							
/*
Options are: 
  - server (domain)
  - server_port (defaults to 21)
  - username
  - password
  - public_directory (the path to your public directory / public_html - can be empty too)
*/
$config['ftp'] = array('server' 		  => '', 
					   'username'		  => '', 
					   'password'		  => '', 
					   'public_directory' => '');

/*
If using dropbox instead of FTP for your appcasts:
Options are:
	- email
	- password
*/
$config['dropbox'] = array('email'    => '', 
 						   'password' => '');

/*
Automatic DMG creation, fill in your configuration settings below. (Window width/height, position, and so on).
Icon size can be at moast 128 (pixels).
For files/folders that need to be at a specific position, add them to the "icons" array.
You can add icon position even for items that are not in a specific stack DMG. These will just be ignored.
One special icon is already added to the array, this is the stack file itself. Feel free to change it's positioning.
Paths are relative to dmg/contents/ (always included in each stack DMG) or dmg/conditional/ (dependent on the stack, make a new folder with the stack name and put files in there)

For example:

dmg/contents/Support.webloc -- This bookmark is always included
dmg/conditional/StackName/readme.txt -- This readme file is only included in the DMG for the StackName stack.

If adding these files to the icon array, you would set the path to "Support.webloc" and "readme.txt", full paths don't work here.

You can also have multiple DMG configurations. Just add to the DMG array.
*/

$config['dmg'] = array();

$config['dmg']['default'] = array('format'				 => 'default',
								  'window_width' 		 => 400, 
					   			  'window_height'	     => 400, 
					   			  'window_pos_x' 		 => 200, 
					   			  'window_pos_y'  	   	 => 200, 
								  //background goes in lib/always/extras or lib/conditional/*/extras/  - if file is in both locations, the conditional one will overwrite.
					   			  'background'    		 => 'background.png',
					 			  'volume_name'   		 => ':title: Stack',
					   			  'icon_size'     		 => 96,   
								  //Add files to the icons array that need special positioning. If the file does not need to be positioned, you need not place it in the array. It will be added automatically.
					   			  'icons' 				 => array(array('path' => ':stackfile:', 'pos_x' => 120, 'pos_y' => 120)));
	
/*
Filestorm has extra configuration settings for the installer, and a license agreement upon mounting the DMG.
To call this you need to add "-g advanced" to the command line.

Filestorm won't work on Lion. I informed the company, they confirmed the issue, but they do not care to fix it.
*/
$config['dmg']['advanced'] = array('format'				 => 'filestorm',
								   'window_width' 		 => 400, 
					   			   'window_height'	     => 400, 
					   			   'window_pos_x' 		 => 200, 
					   			   'window_pos_y'  	   	 => 200, 
								   //background goes in lib/always/extras or lib/conditional/*/extras/ - if file is in both locations, the conditional one will overwrite.
					   			   'background'    		 => 'background.png',
					 			   'volume_name'   		 => ':title: Stack',
					   			   'icon_size'     		 => 96,   
								   //Add files to the icons array that need special positioning. If the file does not need to be positioned, you need not place it in the array. It will be added automatically.
					   			   'icons' 				 => array(array('path' => ':stackfile:', 'pos_x' => 120, 'pos_y' => 120)),
								   'installer_title' 	 => ':title: Stack Installation',
								   //installer icon goes in lib/always/extras or lib/conditional/*/extras/ - if file is in both locations, the conditional one will overwrite.
					 			   'installer_icon' 	 => 'icon.png', 
								   //installer background goes in lib/always/extras or lib/conditional/*/extras/ - if file is in both locations, the conditional one will overwrite.
					 			   'installer_background' => 'installer_background.png', 
								   'installer_files'	  => array(),
								    //license agreement goes in lib/always/extras or lib/conditional/*/extras/ - if file is in both locations, the conditional one will overwrite.
								   'license_agreement' 	 => 'license.txt');

//$config['dmg']['custom'] = array();

/*
These next settings are only necessary if you set files_and_directories to "manual". 

Set this to your directories for the release notes, appcast XML, and update files (zips). Relative to root http://domain.com/..
Several variables are available:
- :stack: - The stack filename
- :lstack: - Lowercase version of the stack filename
- :uid: - The stack UID (probably only used by myself, so feel free to ignore)
- :v: - The short version nr of the stack
- :version: - The long version nr of the stack
- :api: - The min Api version specified in the stack. 
- :api1: - The min API version specified in the stack, made specifically for compatibility with Workman's appcast.php (v1 released in October 2011)
- :api2: - The min API version specified in the stack, made specifically for compatibility with Workman's appcast.php (v2 released on January 2012)
- :secret: - Also for compatibility with Workman's appcast.php v2 script. This generates an md5 of the stack name + your secet keyphrase. 

If you're using Joe Workman's appcast.php v1 script, you will want to set this to the following:

- release_directory = appcasts:api1:/:stack:/
- appcast_directory = appcasts:api1:/:stack:/
- update_directory  = appcasts:api1:/:stack:/

Note: Workman will release an update for his appcast script pretty soon. An extra secret will be added to the product folder, to combat piracy.

When that version is released, change your configuration to:

- release_directory = appcasts:api2:/:stack:_:secret:/
- appcast_directory = appcasts:api2:/:stack:_:secret:/
- update_directory  = appcasts:api2:/:stack:_:secret:/

More info about :api:, :api1:, :api2:

If your stack is compatible with both stacks 1 and 2, you can use this variable to upload to two different directories simultaneously.

Workman's script has two different appcast directories, one for Stacks v1 updates, and another for Stacks v2 updates.

In his script, they are named as follows: 

If stacks v2: "appcasts-3/" (3 is the API version, different from the Stack plugin version)
If stacks v2: "appcasts/" (no number)  (Note: in the v2 script this will be named "appcasts-2/")

Since this behaves differently from the normal :api: (which always outputs the api veresion), I created two special variables :api1: and :api2:

This will output -"3" if the min API version is 3, "-4" if the min API version if 4 (does not yet exist as of this moment), and otherwise nothing in v1 or "-2" in v2 of appcast.php.

The normal :api: will always output the min API nr.

If you are using either of these variables, the script will look at the Info.plist to see if the stack is compatible with Stacks v1 only, Stacks 2 only, or both.

Based on that, it will then write to 1 or both of these directories simultaneously.

If you are not using these variables, it will write to just 1 directory.
*/
$config['directories'] = array('release_directory' => 'appcasts:api1:/:stack:/', 
							   'appcast_directory' => 'appcasts:api1:/:stack:/',
							   'update_directory'  => 'appcasts:api1:/:stack:/');

/*
These next settings are only necessary if you set files_and_directories to "manual". 

These options determine the filenames of the release.html and appcast.xml - if you want a static name, simply type it, 
for example 'release_file' => 'release_notes.html'
Several variables are available:
- :stack: - The stack filename
- :lstack: - Lowercase version of the stack filename
- :uid: - The stack UID (probably only used by myself, so feel free to ignore)
- :v: - The short version nr of the stack
- :version: - The long version nr of the stack

If you're using Joe Workman's appcast.php, you may want to set this to the following:
- release_file = notes.html
- appcast_file = appcast.xml
- update_file  = :stack:.zip
*/
$config['files'] = array('release_file' => 'notes.html', 
					     'appcast_file' => 'appcast.xml', 
					     'update_file'  => ':stack:.zip');


/*
This is for my internal use, probably won't be used by many others. Safe to ignore.
*/
$config['use_stack_uid'] = false;
?>