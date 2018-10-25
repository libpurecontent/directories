<?php

# Class to create various directory manipulation -related static methods
# Version 1.1.4

# Licence: GPL
# (c) Martin Lucas-Smith, University of Cambridge
# More info: http://download.geog.cam.ac.uk/projects/directories/


#!# These functions need a good tidy-up - there is a lot of duplication of similar functions each with different quirks, some of which work recursively and others not; these should be all combined into a single super-function with options


# Ensure the pureContent framework is loaded and clean server globals
require_once ('pureContent.php');


# Define a class containing directory manipulation static methods
class directories
{
	# Function to parse and clean up a specified directory
	#!# This function is very grubby and should be tightened up for the actual use-case(s) and rewritten
	public static function parse ($directory)
	{
		# Replace any \ with / for both the input directory and the document root
		$directory = str_replace ('\\', '/', $directory);
		$documentRoot = str_replace ('\\', '/', $_SERVER['DOCUMENT_ROOT']);
		
		# On Windows, lower case both the document root and the directory
		if (strstr (PHP_OS, 'WIN')) {
			$documentRoot = strtolower ($documentRoot);
			$directory = strtolower ($directory);
		}
		
		# If instead the directory starts with a . replace this with the site root (the document root at the start is stripped off later)
		if (substr ($directory, 0, 1) == '.') {
			$directory = (str_replace ('\\', '/', getcwd ()) . substr ($directory, 1));
		} else {
			
			# If the directory neither starts with a . or the document root, prepend a / because the document root will require this
			#!# There is an issue on Windows where a different drive is specified, e.g. X:\
			$delimiter = '@';
			$startsWithDocumentRoot = (preg_match ($delimiter . '^' . addcslashes ($documentRoot, $delimiter) . $delimiter, $directory));
			if (!$startsWithDocumentRoot) {$directory = '/' . $directory;}
		}
		
		# If the directory begins with the document root, strip that off from the start
		$delimiter = '@';
		$startsWithDocumentRoot = (preg_match ($delimiter . '^' . addcslashes ($documentRoot, $delimiter) . $delimiter, $directory));
		if ($startsWithDocumentRoot) {$directory = substr ($directory, strlen ($documentRoot));}
		
		# Ensure the directory ends with a slash
		if (substr ($directory, -1) != '/') {$directory .= '/';}
		
		# Ensure that the directory exists
		if (!is_dir ($documentRoot . $directory)) {
			echo '<p>Error: the site administrator specified an invalid directory.</p>';
			return false;
		}
		
		# Return the result
		return $directory;
	}
	
	
	# Function to create a clickable trail of directory names
	public static function trail ()
	{
		# Get the current path
		$path = rawurldecode ($_SERVER['REQUEST_URI']);
		
		# Split the subdirectories into an array
		$subdirectories = explode ('/', $path);
		
		# Remove empty elements (the first and last)
		$first = 0;
		$last = (count ($subdirectories) - 1);
		unset ($subdirectories[$first], $subdirectories[$last]);
		
		# Assign the first key and then start an array of links
		$key = '/';
		$links[$key] = $_SERVER['SERVER_NAME'];
		
		# Map the subdirectories onto the array of links
		foreach ($subdirectories as $subdirectory) {
			$key = $key . $subdirectory . '/';
			$links[$key] = $subdirectory;
		}
		
		# Construct the link list
		$html = '';
		foreach ($links as $link => $name) {
			$html .= '<a href="' . str_replace (' ', '%20' , htmlspecialchars ($link)) . '">' . str_replace (' ', '&nbsp;' , htmlspecialchars ($name)) . '/</a>';
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to get the file list
	public static function getFileListing ($hiddenFiles = array ('.ht*'), $caseSensitiveMatching = true, $showOnly = array (), $sortByKey = 'name', $includeDirectories = true)
	{
		# Obtain the current directory, chopping off the query string
		$currentDirectory = $_SERVER['REQUEST_URI'];
		if ($_SERVER['QUERY_STRING'] != '') {$currentDirectory = substr ($currentDirectory, 0, (0 - (strlen ($_SERVER['QUERY_STRING']) + 1)));}
		$currentDirectory = rawurldecode ($currentDirectory);
		
		# Construct an array of files in the directory
		if (!$files = self::listFiles ($currentDirectory)) {
			return array ();
		}
		
		# Loop through each file
		foreach ($files as $file => $attributes) {
			
			# Remove directories if required
			if (!$includeDirectories) {
				if ($attributes['type'] == 'dir') {
					unset ($files[$file]);
				}
			}
			
			
			# If a list of areas is given, show only those allowed
			if ($showOnly) {
				if (!in_array ($currentDirectory . $file, $showOnly)) {
					unset ($files[$file]);
				}
			}
		}
		
		# Remove files which should be hidden
		$files = self::removeHiddenFiles ($hiddenFiles, $files, $currentDirectory, $caseSensitiveMatching);
		
		# Sort the list alphabetically
		if ($files) {
			switch ($sortByKey) {
				case 'name':
					$comparisonFunction = "return strcasecmp (\$a['{$sortByKey}'], \$b['{$sortByKey}']);";
					break;
				case 'time':
					$comparisonFunction = "return (\$a['{$sortByKey}'] < \$b['{$sortByKey}']);";
					break;
			}
			uasort ($files, create_function ('$a, $b', $comparisonFunction));
		}
		
		# Return the list
		return $files;
	}
	
	
	# Function to create a printed list of files
	public static function listing ($iconsDirectory = '/images/fileicons/', $iconsServerPath = '/websites/common/images/fileicons/', $hiddenFiles = array ('.ht*'), $caseSensitiveMatching = true, $trailingSlashVisible = true, $fileExtensionsVisible = true, $wildcardMatchesZeroCharacters = false, $showOnly = array (), $sortByKey = 'name', $directoriesOnly = false, $includeGallery = false)
	{
		# Get the file list
		$files = self::getFileListing ($hiddenFiles, $caseSensitiveMatching, $showOnly, $sortByKey);
		
		# If there are no documents, state this
		if (!$files) {
			return $html = '<p>There are no documents available here at present.</p>';
		}
		
		// $files = application::knatsort ($files);
		
		# Start an HTML list
		$html = "\n" . '<ul class="filelist">';
		
		# Import the array of icons
		$extensions = self::defineSupportedExtensions ();
		
		# Obtain the current directory, chopping off the query string
		$currentDirectory = $_SERVER['REQUEST_URI'];
		if ($_SERVER['QUERY_STRING'] != '') {$currentDirectory = substr ($currentDirectory, 0, (0 - (strlen ($_SERVER['QUERY_STRING']) + 1)));}
		$currentDirectory = rawurldecode ($currentDirectory);
		
		# Loop through the list of files
		foreach ($files as $file => $attributes) {
			
			# If required, skip if it's a directory
			if ($directoriesOnly && !$attributes['directory']) {continue;}
			
			# If not matching case sensitively, convert the extensions to lower case
			$extension = $attributes['extension'];
			if (!$caseSensitiveMatching) {$extension = strtolower ($attributes['extension']);}
			
			# Assemble the icon HTML for the list
			$iconFile = ((array_key_exists ($extension, $extensions)) ? $extensions[$extension] : (($attributes['directory']) ? $extensions['_folder'] : $extensions['_unknown']));
			$serverFile = ($iconsServerPath . $iconFile);
			if (file_exists ($serverFile)) {
				list ($width, $height, $type, $iconSizeHtml) = getimagesize ($serverFile);
			} else {
				$iconSizeHtml = '';
			}
			$titleHtml =  ((array_key_exists ($extension, $extensions)) ? ".{$attributes['extension']} file" : (($attributes['directory']) ? 'Folder' : 'Unknown file type'));
			$iconHtml = '<img src="' . $iconsDirectory . $iconFile . '" alt="' . $titleHtml . '" ' . $iconSizeHtml . ' />';
			
			# For a .url link file, open the file and get the contents of the line starting URL=
			if ($extension == 'url') {
				$linkFileContents = file_get_contents ($_SERVER['DOCUMENT_ROOT'] . $currentDirectory . $file);
				$lines = explode ("\n", $linkFileContents);
				foreach ($lines as $line) {
					if (preg_match ('/^URL=(.*)/', $line, $matches)) {
						$file = $matches[1];
						$link = str_replace ('%2f', '/', rawurldecode ($file));
					}
				}
			} else {
				$link = rawurlencode ($file);
			}
			
			# Add each file to the list, showing a trailing slash for directories if required
			$html .= "\n\t" . '<li><a class="noautoicon" href="' . $link . (($attributes['directory']) ? '/' . ($sortByKey == 'date' ? '?date' : '') : '') . '"' . ($extension == 'url' ? ' target="_blank"' : '') . ' title="' . $titleHtml . '">' . $iconHtml . ' ' . htmlspecialchars ($attributes['name'] . (($fileExtensionsVisible && ($extension != '')) ? '.' . $attributes['extension'] : '')) . (($attributes['directory'] && $trailingSlashVisible) ? '/' : '') . '</a>';
			if (!$attributes['directory']) {$html .= ' (' . date ('j/m/y', $attributes['time']) . ', ' . ($extension == 'url' ? 'Link' : self::fileSizeFormatted ($_SERVER['DOCUMENT_ROOT'] . $currentDirectory . $file)) . ')';}
			$html .= '</li>';
		}
		
		# Complete the list
		#!# Need to make this only appear if there are files as well as directories
		$html .= "\n</ul>" . '
		<ul class="filelistnotes">' . "
			<li>To <strong>open</strong> a file or directory, left-click (PC) or click (Mac) on its name.</li>
			<li>To <strong>save</strong> a file, right-click (PC) or control-click (Mac) on its name and select 'Save Target As...'.</li>
			<li>For docx/xlsx/pptx documents, you may need the free <a href=\"https://www.google.com/search?q=Microsoft+Office+Compatibility+Pack\" target=\"_blank\">Office Compatibility Pack</a> if you have an older version of MS Office</li>
		</ul>";
		
		# Show photo thumbnails if required
		if ($includeGallery) {
			require_once ('image.php');
			$html .= image::gallery (true, false, $size = 250);
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to obtain an array of file details from a directory
	public static function listFiles ($directory, $supportedFileTypes = array (), $directoryIsFromRoot = false, $skipUnreadableFiles = true, $skipZeroLengthFiles = false)
	{
		# Append the document root to the current directory (for the lifetime of this function only)
		if (!$directoryIsFromRoot) {$directory = $_SERVER['DOCUMENT_ROOT'] . $directory;}
		
		# Check the supplied directory is actually a directory
		if (!is_dir ($directory)) {return false;}
		
		# If a list of supported file types has been specified (i.e. only those listed should be included), ensure it is an array and then assign a flag for whether checks on supported file types should be run
		if (!is_array ($supportedFileTypes)) {
			$temporaryArray = $supportedFileTypes;
			unset ($supportedFileTypes);
			$supportedFileTypes[] = $temporaryArray;
		}
		$allowSupportedFileTypesOnly = (!empty ($supportedFileTypes));
		
		# Start an (initially empty) array of files
		$files = array ();
		
		# Open the directory, and read its contents
		if (!$handle = @opendir ($directory)) {
			#!# Throw an error here
		} else {
			
			# Loop through each file, excluding . and .., and assign an array of information for it
			while (($file = readdir ($handle)) !== false) {
				if (($file != '.') && ($file != '..')) {
					
					# First check what the extension is
					$extension = (strpos ($file, '.') !== false ? str_replace ('.', '', strrchr ($file, '.')) : '');
					
					# If a non-empty array of supported file types has been supplied, check whether the current file in the loop should be included
					require_once ('application.php');
					$supported = (($allowSupportedFileTypesOnly && (!application::iin_array ($extension, $supportedFileTypes))) ? false : true);
					
					# Skip a file if it is not readable
					if ($skipUnreadableFiles) {
						#!# Fails if directory has no trailing slash
						if (!is_readable ($directory . $file)) {
							#!# Need to have a reporting option
							// echo $directory . $file . "\n";
							continue;
						}
					}
					
					# Skip zero-length files if required
					if ($skipZeroLengthFiles) {
						if (!filesize ($directory . $file)) {
							continue;
						}
					}
					
					# Assign the file to the array if required
					if ($supported) {
						$files[$file] = array (
							'name' => (strstr ($file, '.') ? substr ($file, 0, strrpos ($file, '.')) : $file),
							'size' => filesize ($directory . $file),
							'time' => filemtime ($directory . $file),
							'type' => filetype ($directory . $file),
							#'owner' => fileowner ($directory . $file),
							#'group' => filegroup ($directory . $file),
							'directory' => (is_dir ($directory . $file)),
							'extension' => $extension,
						);
					}
				}
			}
			
			# Close the directory
			closedir ($handle);
		}
		
		# Return the file details array
		return $files;
	}
	
	
	# Function to remove files specified as hidden from a list of files
	public static function removeHiddenFiles ($hiddenFiles, $files, $currentDirectory, $caseSensitiveMatching = true)
	{
		# Start an array of cleaned files
		$cleanedFiles = array ();
		
		# Return if no files
		if (!$files) {return $cleanedFiles;}
		
		# Construct a list of files to remain hidden
		$osFiles = array ('recycler/', 'RECYCLER/', 'recycled/', '.AppleDouble/');
		$hiddenFiles = array_merge ($hiddenFiles, $osFiles);
		
		# Loop through each file in the supplied list
		foreach ($files as $originalFile => $attributes) {
			
			# Assume at the start that the file should not be hidden
			$hideFile = false;
			
			# For testing purposes, if the file is a directory, append a slash
			$file = (($attributes['directory']) ? $originalFile . '/' : $originalFile);
			
			# If case-insensitive matching is specified, lowercase the test file
			if (!$caseSensitiveMatching) {$file = strtolower ($file);}
			
			# Loop through the array of specified hidden files
			foreach ($hiddenFiles as $hiddenFile) {
				
				# If the hidden file starts with a slash, compare it against the full path of the specified file
				$testFile = ((substr ($hiddenFile, 0, 1) == '/') ? ($currentDirectory . $file) : $file);
				
				# If case-insensitive matching is specified, lowercase the hidden file file
				if (!$caseSensitiveMatching) {$hiddenFile = strtolower ($hiddenFile);}
				
				# Check for the hidden file being exactly the same as the tested file
				if ($hiddenFile == $testFile) {
					$hideFile = true;
				} else {
					
					# Check for a wildcard-ending hidden file being the same as the tested file
					if (substr ($hiddenFile, -1) == '*') {
						$delimiter = '@';
						if (preg_match ($delimiter . '^' . addcslashes (substr ($hiddenFile, 0, -1), $delimiter . 'i') . $delimiter, $testFile)) {
							$hideFile = true;
							
							# However, if the WILDCARD_MATCHES_ZERO_CHARACTERS flag is set, don't hide the file if the test file matches the the hidden file without the *
							#!# Code is broken here as $wildcardMatchesZeroCharacters does not exist
							if (($testFile == substr ($hiddenFile, 0, -1)) && $wildcardMatchesZeroCharacters) {
								$hideFile = false;
							}
						}
					
					# Check for a wildcard-starting hidden file being the same as the tested file
					} else if (substr ($hiddenFile, 0, 1) == '*') {
						$delimiter = '@';
						if (preg_match ($delimiter . addcslashes (substr ($hiddenFile, 1), $delimiter) . '$' . $delimiter . 'i', $testFile)) {
							$hideFile = true;
						}
					}
				}
			}
			
			# Add the file to the array of cleaned files
			if (!$hideFile) {
				$cleanedFiles[$originalFile] = $attributes;
			}
		}
		
		# Return the array of cleaned files
		return $cleanedFiles;
	}
	
	
	# List the supported file extensions and their associated icons
	public static function defineSupportedExtensions ()
	{
		return $extensions = array (
			'_folder' => 'folder.gif',
			'_unknown' => 'unknown.gif',
			'aam' => 'authorware.gif',
			'aas' => 'authorware.gif',
			'avi' => 'media.gif',
			'bmp' => 'bmp.gif',
			'clp' => 'clp.gif',
			'css' => 'notepad.gif',
			'csv' => 'excel.gif',
			'dat' => 'notepad.gif',
			'doc' => 'word.gif',
			'docx' => 'docx.gif',
			'dot' => 'wordtemplate.gif',
			'gif' => 'gif.gif',
			'eps' => 'psd.gif',
			'exe' => 'exe.gif',
			'hqx' => 'zip.gif',
			'htm' => 'html.gif',
			'html' => 'html.gif',
			'js' => 'js.gif',
			'jpg' => 'jpg.gif',
			'jpeg' => 'jpg.gif',
			'log' => 'notepad.gif',
			'lnk' => 'link.gif',
			'mdb' => 'mdb.gif',
			'mht' => 'html.gif',
			'mov' => 'quicktime.gif',
			'mpa' => 'media.gif',
			'mpeg' => 'media.gif',
			'mp4' => 'quicktime.jpg',
			'msg' => 'msg.gif',
			'mtw' => 'minitab.gif',
			'odb' => 'odb.gif',
			'odp' => 'odp.gif',
			'ods' => 'ods.gif',
			'odt' => 'odt.gif',
			'pdf' => 'pdf.gif',
			'pdx' => 'acrobatindex.gif',
			'png' => 'gif.gif',
			'ps' => 'ps.gif',
			'psd' => 'psd.gif',
			'pps' => 'ppt.gif',
			'ppt' => 'ppt.gif',
			'pptx' => 'pptx.gif',
			'pub' => 'publisher.gif',
			'qt' => 'quicktime.jpg',
			'rm' => 'real.gif',
			'rtf' => 'word.gif',
			'sav' => 'spss.gif',
			'tar' => 'zip.gif',
			'tif' => 'tif.gif',
			'tiff' => 'tif.gif',
			'ttf' => 'ttf.gif',
			'txt' => 'notepad.gif',
			'url' => 'html.gif',
			'wav' => 'wav.gif',
			'wbk' => 'word.gif',
			'wmf' => 'bmp.gif',
			'wmp' => 'media.gif',
			'wpd' => 'wordperfect.gif',
			'wri' => 'wri.gif',
			'xls' => 'excel.gif',
			'xlsx' => 'xlsx.gif',
			'xlt' => 'exceltemplate.gif',
			'zip' => 'zip.gif',
		);
	}
	
	
	# Wrapper function to create a formatted listing
	#!# Need to add inheritableExtensions support e.g. .html.old
	public static function listingWrapper ($iconsDirectory, $iconsServerPath, $hiddenFiles, $caseSensitiveMatching, $titleFile = '.title.txt', $directoriesOnly = false, $heading = 'h1', $includeGallery = false, $trailingSlashVisible = true)
	{
		# Get the contents of the title file
		$titleFile = $_SERVER['DOCUMENT_ROOT'] . $_SERVER['REQUEST_URI'] . $titleFile;
		if (file_exists ($titleFile)) {
			$contents = file_get_contents ($titleFile);
		}
		
		# Start the page
		$html  = '';
		if ($heading) {$html .= "\n\n" . "<{$heading}>" . (isSet ($contents) ? $contents : '&nbsp;') . "</{$heading}>";}
		$html .= "\n\n" . '<p><a href="../"><em>&lt; Go back</em></a></p>';
		
		# Show the directory listing
		$html .= self::listing ($iconsDirectory, $iconsServerPath, $hiddenFiles, $caseSensitiveMatching, $trailingSlashVisible, true, false, array (), 'name', $directoriesOnly, $includeGallery);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to get directory structure (but not contents)
	#!# This could be tidied up - there is code for excluding files (as distinct from directories), but these are never added in the first place
	public static function tree ($directory, $exclude = array ()/*, $onlyInclude = array ()*/)
	{
		# Make sure it's a directory
		if (!is_dir ($directory)) {return array ();}
		
		# Ensure supplied file lists are arrays
		require_once ('application.php');
		$exclude = application::ensureArray ($exclude);
		/*$onlyInclude = application::ensureArray ($onlyInclude);*/
		
		# Open the directory
		$contents = array ();
		if (!$directoryHandle = @opendir ($directory)) {
			#!# Throw an error here
		} else {
			
			# Loop through the directory
			while (($item = readdir ($directoryHandle)) !== false) {
				
				# Remove . and ..
				if (($item == '.') || ($item == '..')) {continue;}
				
				# Exclude files if necessary [if an include list is supplied, this will only remove directories]
				if (in_array ($item, $exclude)) {continue;}
				
				# If the item is an array, get its contents
				if (is_dir ($directory . $item)) {
					$contents[$item] = self::tree ($directory . $item . '/', $exclude/*, $onlyInclude*/);
					
				} /* else {
					
					# Limit files if necessary
					if (!empty ($onlyInclude)) {
						if (!in_array ($item, $onlyInclude)) {continue;}
					}
					
					# Add the file to the contents array
					$contents[] = $item;
				} */
			}
			
			# Sort the contents
			ksort ($contents);
			
			# Close the directory
			closedir ($directoryHandle);
		}
		
		# Return the contents
		return $contents;
	}
	
	
	# Function to flatten a directory structure
	public static function flatten ($directories, $startPoint = '/')
	{
		# Start a list of entries
		$entries = array ();
		
		# End if empty structure
		if (!is_array ($directories) || !$directories) {return array ();}
		
		# Loop through the directory structure
		foreach ($directories as $directory => $contents) {
			
			# Compile the entry
			$entry = $startPoint . $directory . '/';
			
			# Add the entry to the list of entries
			$entries[] = $entry;
			
			# If there are contents, recurse
			if ($contents) {
				$subdirectories = self::flatten ($contents, $entry);
				$entries = array_merge ($entries, $subdirectories);
			}
		}
		
		# Sort the entries
		sort ($entries);
		
		# Return the entries
		return $entries;
	}
	
	
	# Function to get a flattened file listing ($start is non-slash terminated)
	public static function flattenedFileListing ($start, $supportedFileTypes = array (), $includeRoot = true, $excludeFileTemplate = false, $excludeContentsRegexp = false, $regexpAfterStart = false)
	{
		# Get the directory structure
		$tree = self::tree ($start . '/');
		
		# Flatten the directory structure
		$directories = self::flatten ($tree);
		
		# Add the root path to the tree
		array_unshift ($directories, '/');
		
		# Get the files from each directory
		$listing = array ();
		foreach ($directories as $directory) {
			
			# Get the files in this directory, and skip if none in the directory
			if (!$files = self::listFiles ($start . $directory, $supportedFileTypes, true)) {continue;}
			
			# Add each file to the master list
			foreach ($files as $file => $attributes) {
				
				# Determine the location, and skip if its a directory
				if ($attributes['type'] == 'dir') {continue;}
				
				# Skip if there is a regexp which matches
				if ($regexpAfterStart) {
					$pregDelimeter = '@';
					if (!preg_match ($pregDelimeter . $regexpAfterStart . $pregDelimeter, $directory . $file)) {
						continue;
					}
				}
				
				# File content checking types
				if ($excludeContentsRegexp || ($excludeFileTemplate && ($attributes['size'] == strlen ($excludeFileTemplate)))) {
					
					# Attempt to open the file and get its contents
					$fileToCompare = $start . $directory . $file;
					if (is_readable ($fileToCompare)) {
						$fileToCompareContents = file_get_contents ($fileToCompare);
						
						# Regexp check
						if ($excludeContentsRegexp) {
							if (preg_match ("|({$excludeContentsRegexp})|DsiU", $fileToCompareContents, $matches)) {
								continue;
							}
						}
						
						# Exclude files of a particular size if necessary; check the size, then that it can be opened, then the contents for a match
						if ($excludeFileTemplate) {
							if ($attributes['size'] == strlen ($excludeFileTemplate)) {
								if (md5 ($fileToCompareContents) == md5 ($excludeFileTemplate)) {
									continue;
								}
							}
						}
					}
				}
				
				# Add the file to the master list. adding the root without a trailing slash
				$choppedStartDirectory = ((substr ($start, -1) == '/') ? substr ($start, 0, -1) : $start);
				$listing[] = ($includeRoot ? $choppedStartDirectory : '') . $directory . $file;
			}
		}
		
		# Sort the listing
		sort ($listing);
		
		# Return the listing
		return $listing;
	}
	
	
	# Function to turn an array like array ('/foo/*', '/bar/', '/foo/bar/*', '/file.html') into a flattened file listing, arranged as $location => $file
	public static function flattenedFileListingFromArray ($locations, $root, $supportedFileTypes = array (), $includeRoot = true, $excludeFileTemplate = false, $excludeContentsRegexp = false, $regexpAfterStart = false)
	{
		# Create a flattened list of files
		$allFiles = array ();
		foreach ($locations as $location) {
			
			# Add files from a tree
			if (substr ($location, -2) == '/*') {
				$directory = substr ($location, 0, -1);
				$files = self::flattenedFileListing ($root . $directory, $supportedFileTypes, $includeRoot = true, $excludeFileTemplate, $excludeContentsRegexp, $regexpAfterStart);
				$allFiles = array_merge ($allFiles, $files);
				
			# Add files in a non-tree directory
			} else if (substr ($location, -1) == '/') {
				$directory = substr ($location, 0, -1);
				$files = self::listFiles ($directory, $supportedFileTypes, $directoryIsFromRoot = true);
				$allFiles = array_merge ($allFiles, $files);
				
			# Add other files
			} else {
				$allFiles[] = $root . $location;
			}
		}
		
		# Rearrange to have as $directory => $location
		$files = array ();
		foreach ($allFiles as $index => $file) {
			$pregDelimeter = '@';
			$location = preg_replace ($pregDelimeter . '^' . $root . $pregDelimeter, '', $file);
			$files[$location] = $file;
		}
		
		# Sort the listing
		ksort ($files);
		
		# Return the list
		return $files;
	}
	
	
	# Function to get a list of the directories contained in a directory
	public static function listContainedDirectories ($directory, $exclude = array (), $requireMatch = false)
	{
		# Ensure the names to be excluded are an array
		require_once ('application.php');
		$exclude = application::ensureArray ($exclude);
		
		# Start an array to hold the results
		$results = array ();
		
		# Ensure the directory exists
		if (!is_dir ($directory)) {return $results;}
		
		# Open the directory
		$handler = opendir ($directory);
		
		# Add each directory to an array
		while ($file = readdir ($handler)) {
			
			# Avoid non-directories
			if (!is_dir ($directory . $file)) {continue;}
			if (($file == '.') || ($file == '..')) {continue;}
			
			# Perform a match if required
			if ($requireMatch) {
				$delimiter = '/';
				if (!preg_match ($delimiter . addcslashes ($requireMatch, $delimiter) . $delimiter, $file)) {continue;}
			}
			
			# Avoid areas to be excluded
			if (in_array ($file, $exclude)) {continue;}
			
			# Add the file to the list
			$results[] = $file;
		}
		
		# Close the directory
		closedir ($handler);
		
		# Sort the list alphabetically
		sort ($results);
		
		# Return the list
		return $results;
	}
	
	
	# Function to clean up the directory structure by removing empty directories
	public static function listEmptyDirectories ($start)
	{
		# Ensure the start point exists
		if (!is_dir ($start)) {return false;}
		
		# Get the directory structure
		$tree = self::tree ($start . '/');
		
		# Flatten the directory structure
		$directories = self::flatten ($tree);
		
		# Loop through each directory and create a list of empty directories
		$emptyDirectories = array ();
		foreach ($directories as $directory) {
			$serverDirectory = $start . $directory;
			if (!$files = self::listFiles ($serverDirectory, array (), $directoryIsFromRoot = true)) {
				$emptyDirectories[] = $serverDirectory;
			}
		}
		
		# Return the list of empty directories
		return $emptyDirectories;
	}
	
	
	# Function to delete empty directories
	public static function deleteEmptyDirectories ($start)
	{
		# Get the empty directories
		$emptyDirectories = self::listEmptyDirectories ($start);
		
		# Delete each directory
		$problemsFound = false;
		foreach ($emptyDirectories as $emptyDirectory) {
			
			# Attempt to delete the directory or flag that there was a problem
			if (!@rmdir ($emptyDirectory)) {
				$problemsFound[] = $emptyDirectory;
			}
		}
		
		# Return true for no problems
		return $problemsFound;
	}
	
	
	# Function to show all the news articles (html files) in a directory
	public static function showNewsArchive ($directory, $excludeFiles, $limit = false)
	{
		# Get the file listing
		$files = self::listFiles ($directory, 'html');
		
		# Remove hidden files
		$files = self::removeHiddenFiles ($excludeFiles, $files, $directory);
		
		# Sort the files
		ksort ($files);
		
		# Show in reverse order
		$files = array_reverse ($files);
		
		# Loop through each and show it
		$i = 0;
		foreach ($files as $file => $attributes) {
			include ($_SERVER['DOCUMENT_ROOT'] . $directory . $file);
			
			# Stop if a limit is specified and it is reached
			$i++;
			if ($limit && $i == $limit) {break;}
		}
	}
	
	
	# Create a function to get the file size
	public static function fileSizeFormatted ($file)
	{
		# Define size shortcuts
		$kb = 1024;       // Kilobyte
		$mb = 1024 * $kb; // Megabyte
		$gb = 1024 * $mb; // Gigabyte
		$tb = 1024 * $gb; // Terabyte
		
		# Get the file size
		#!# This will generate an error if the file is huge - not really fixable though
		$size = filesize ($file);
		
		# Give the appropriate measurement
		if ($size < $mb) {
			return round ($size/$kb, 1) . ' KB';
		} else if ($size < $gb) {
			return round ($size/$mb, 2) . ' MB';
		} else if ($size < $tb) {
			return round ($size/$gb, 2) . ' GB';
		} else {
			return round ($size/$tb, 2) . ' TB';
		}
	}
}

?>
