# Stack Deployer Configuration

## File System Placement

Place the StackDeployer folder in /Users/Username/

The full path should then be: "/Users/Username/StackDeployer/"

## Configuration

Configure everything to your liking in deploy-config-sample.php - Once you have done so, rename the file to "deploy-config.php".

If you need your directories or filenames to have special variables in them, such as the version nr, you can do so via these template variables:
   
:stack: - will be replaced by the stack filename
:lstack: - will be replaced by the lowercase version of the stack filename
:uid: - the stack UID (probably only used by myself, feel free to ignore)
:v: - the short version nr of the stack
:version: - the long version nr of the stack.
:api: - the min api version specified in the stack
:apii: - the min api version specified in the stack, made specifically for compatibility with Workman's script

So if you want your appcast.xml to be named "StackName_1.0.1.xml", you will do the following in deploy-config.php:

	'appcast_file' => ':stack:_:v:.xml'

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

	- v
  
Verbose mode. This will output all filenames as they are being uploaded to FTP or dropbox.

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

### Example usage:

	php deploy.php -d /Users/username/Desktop -i -v --nopurgelocal
  
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

## AppleScript

An applescript is included. You can drag stacks on this script and it will automatically open the Terminal window for seeing the output.
	
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