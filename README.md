# Stack Deployer Configuration

## What Does This Do?

This script will automatically zip your stacks, sign them, create the appcast XML, create the release notes HTML (if required), upload the files to your FTP server or dropbox account and creates a DMG.

It can process both single files and entire directories. 

## Sample Output

	imac:~ Test$ deploy -f /Users/Test/Desktop/Test.stack/ -v
	* Deploying stack "/Users/Test/Desktop/Test.stack/"...
  	-- Stack is compatible with Stacks 2 only.
  	-- Stack zipped: Test.zip (40230 bytes)
  	-- Signature generated: MC0CFFR6HAtwAdPNa5sIdSye7ZHsYnJIAhUAmeC2tpvKudPdR7YtQr4p\/9hnTSY=
  	-- Appcast XML generated: appcast.xml
  	-- Release notes HTML generated: Test.html
  	-- Uploading to DropBox (single appcast directory)...
  	-- Uploading "appcasts/Test/Test.zip" - OK
  	-- Uploading "appcasts/Test/appcast.xml" - OK
  	-- Uploading "appcasts/Test/Test.html" - OK
  	-- Uploaded successfully.
    -- Creating DMG...
    -- Done creating DMG.

## File System Placement

Place the files in "/Users/Username/StackDeployer/" (the files, not the folder from the download)

## Configuration

Configure everything to your liking in deploy-config-sample.php - documentation is inline. Once you have done so, rename the file to "deploy-config.php".

## Requisites for signing appcasts

Be sure to place your dsa_priv.pem file in the same directory ("/Users/Username/StackDeployer/")
  
This is needed to generate the appcast signature.

## Invoking the deployer from the command line:

	php deploy.php

This will look in the directory where deploy.php is for any stacks to deploy.

	php deploy.php -f /Users/username/Desktop/Stack.stack
  
This will deploy the specified stack.

	php deploy.php -d /Users/username/Desktop/

This will deploy all stacks in the specified directory.

## Optional command line parameters:

	-i

Interactive mode. Useful if processing a directory of stacks. The script will ask you which stacks to deploy. (yes/no)

	-v
  
Verbose mode. This will output all filenames as they are being uploaded to FTP or dropbox.
	
	-o

The output folder. If not specified, the value from the config file will be used instead.

	-g
	
The DMG group settings key. Useful if you have several different DMG layout requirements. Refers to the key in the config file.

	--noupload

Don't upload the generated files. 

	--nopurgecloud

Don't reset the cloudflare cache. Obviously only useful if you have cloudflare and are deploying to your server.

	--nopurgelocal

Don't remove the (locally) generated files after deployment.

	--noappcast

Don't generate the appcast.xml

	--noreleasenotes

Don't generate the releasenotes.html

	--nodmg
	
Don't generate the DMG.

### Example usage:

	php deploy.php -d /Users/username/Desktop -i -v -o ~/Desktop/Updates/ --noreleasenotes
	
This will process all the stacks found on the Desktop interactively (which means you have to confirm which stacks to update).  
Verbose mode is on, so you will see output of each file as it's being uploaded. 
The output folder is set to the Updates folder on your desktop. 
No release notes should be generated.
  
## Creating an alias to the PHP command

If you don't want to type the full PHP command all the time, the easiest way is to create an alias.

Open terminal, and navigate to your HOME folder. (/Users/Username)

Execute the following:

	pico .profile

Add the following text to the end of that file

	alias deploy='php /Users/Username/StackDeployer/deploy.php'

Taking care to adjust for your username.

Save by typing CMD+X and then confirm with Y.

Now, you'll be able to access the StackDeployer simply by typing "deploy" in the command line.

## DMG Creation

This script can optionally create DMG files of your updates too. 

You have two options; 

- a simple DMG - You can specify width/height, position, background image and so on. 

- A DMG with installer. This will, for example, automatically place the stacks in the correct rapidweaver library folder. This does however, require filestorm (http://www.mindvision.com/filestorm.asp).

**FILESTORM DOES NOT WORK, THEIR API HAS ISSUES WITH APPLESCRIPT. INFORMED FILESTORM, BUT THEY DO NOT CARE.**

To configure, set your options in the config file dmg array.

If you have files that need to be included aside from the stack, add them to:

	lib/dmg/always
	
These files will be added to all stacks deployed.

	lib/dmg/conditional
	
Add subfolders for each stack. The files in the folder that matches the stack filename will be added to the DMG. For example:

	TestName.stack

	lib/dmg/conditional/TestName/readme.txt
	
**The below does not apply as filestorm is not working.**

	Exclusive to the filestorm DMG creation, you can place files that need to be installed in the following folders: 

		lib/dmg/always/installer/
	
		lib/dmg/conditional/TestName/installer/
	
	There are some special filenames for files placed in the installer directory: 

		license.txt
	
	This will be shown before installation starts. The user has to accept before he can continue installation.

		readme.txt / readme.html

	This text file will be shown after the installation has happened.

		*.sh
	
	These shell scripts will be executed after the installation has happened.

		*.stack
	
	These stacks will also be installed.

	All other files are ignored, unless they are specified in the configuration file under $options['dmg']['installer_files'].
	
## AppleScript

An applescript is included. You can drag stacks on this script and it will automatically open the Terminal window for seeing the output.

The applescript can be placed anywhere, including the Desktop.
	
## Generating the release notes

Release notes can be generated in 3 ways:

- simply add your release notes.html to the same folder as the stack. Make sure it is named exactly as the stack.
  If the stack is named "xyz.stack", then the release notes HTML file should be named "xyz.html".

- add your release notes HTML inside the stack. 
  Location is "Contents/Resources/changelog.html"

- add your release notes as a plain text file inside the stack.
  Location is "Contents/Resources/changelog.txt"

This plain text changelog requires a specific format:

	30-Jan-2012 v1.0.1
 	+ New feature nr 1
 	+ New feature nr 2
	
	01-jan-2012 v1.0.0
 	* Initial release
	
This will convert automatically to the following HTML:
	
	<h2>Stack Name - v1.0.1</h2>
	<h3>Release Date: 2012-01-30</h3>
	<ol>
  	 <li><strong>New</strong>: New feature nr 1</li>
  	 <li><strong>New</strong>: New feature nr 2</>
	</ol>
	<h2>Stack Name - v1.0.0</h2>
	<h3>Release Date: 2012-01-01</h3>
	<ol>
  	 <li>Initial release</li>
	</ol>
	
The legend for the prefixes:
	
	* -> General (no prefix)
	# -> Bug Fix
	+ -> Addition
	^ -> Change
	- -> Removed
	! -> Note		
	
Generated release notes via the plain text option require a release note template file. A sample is included in the folder.
Feel free to make changes to it (release_notes_template.html)