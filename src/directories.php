<?php

# Ensure the pureContent framework is loaded and clean server globals
require_once ('pureContent.php');


# Define a class containing directory manipulation static methods
class directories
{
	# Function to parse and clean up a specified directory
	function parse ($directory)
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
			if (!ereg (('^' . $documentRoot), $directory)) {$directory = '/' . $directory;}
		}
		
		# If the directory begins with the document root, strip that off from the start
		if (ereg (('^' . $documentRoot), $directory)) {$directory = substr ($directory, strlen ($documentRoot));}
		
		# Ensure the directory ends with a slash
		if (substr ($directory, -1) != '/') {$directory .= '/';}
		
		# Ensure that the directory exists
		if (!is_dir ($documentRoot . $directory)) {echo '<p>Error: the site administrator specified an invalid directory for the images.</p>'; return false;}
		
		# Return the result
		return $directory;
	}
	
	
	# Function to create a clickable trail of directory names
	function trail ()
	{
		# Replace double-slashes in the path each with a single slash
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
			$html .= '<a href="' . str_replace (' ', '%20' , htmlentities ($link)) . '">' . str_replace (' ', '&nbsp;' , htmlentities ($name)) . '/</a>';
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to create a printed list of files
	function listing ($iconsDirectory = '/images/fileicons/', $iconsServerPath = '/websites/common/images/fileicons/', $hiddenFiles = array ('.ht*', ), $caseSensitiveMatching = true, $trailingSlashVisible = true, $fileExtensionsVisible = true, $wildcardMatchesZeroCharacters = false)
	{
		# Obtain the current directory
		$currentDirectory = rawurldecode ($_SERVER['REQUEST_URI']);
		
		# Remove the query string
		#!# Really nasty? to be improved
		if ($_SERVER['QUERY_STRING'] != '') {$currentDirectory = substr ($currentDirectory, 0, (0 - (strlen ($_SERVER['QUERY_STRING']) + 1)));}
		
		# Construct an array of files in the directory
		$files = directories::listFiles ($currentDirectory);
		
		# Remove files which should be hidden
		$files = directories::removeHiddenFiles ($hiddenFiles, $files, $currentDirectory, $caseSensitiveMatching);
		
		# Sort the list alphabetically
		uasort ($files, array ('directories', 'compare'));
		
		# If there are no documents, state this
		if (count ($files) < 1) {
			return $html = '<p>There are no documents available here at present.</p>';
		}
		
		# Start an HTML list
		$html = "\n" . '<ul class="filelist">';
		
		# Import the array of icons
		$extensions = directories::defineSupportedExtensions ();
		
		# Loop through the list of files
		foreach ($files as $file => $attributes) {
			
			# If not matching case sensitively, convert the extensions to lower case
			if (!$caseSensitiveMatching) {$attributes['extension'] = strtolower ($attributes['extension']);}
			
			# Assemble the icon HTML for the list
			$iconFile = ((array_key_exists ($attributes['extension'], $extensions)) ? $extensions[$attributes['extension']] : (($attributes['directory']) ? $extensions['_folder'] : $extensions['_unknown']));
			$serverFile = ($iconsServerPath . $iconFile);
			if (file_exists ($serverFile)) {
				list ($width, $height, $type, $iconSizeHtml) = getimagesize ($serverFile);
			} else {
				$iconSizeHtml = '';
			}
			$titleHtml =  ((array_key_exists ($attributes['extension'], $extensions)) ? ".{$attributes['extension']} file" : (($attributes['directory']) ? 'Folder' : 'Unknown file type'));
			$iconHtml = '<img src="' . $iconsDirectory . $iconFile . '" alt="' . $titleHtml . '" ' . $iconSizeHtml . ' />';
			
			# Add each file to the list, showing a trailing slash for directories if required
			$html .= "\n\t" . '<li><a href="' . str_replace (array (' ', '#'), array ('%20', '%23') , htmlentities ($file)) . (($attributes['directory']) ? '/' : '') . '" title="' . $titleHtml . '">' . $iconHtml . ' ' . htmlentities ($attributes['name'] . (($fileExtensionsVisible && ($attributes['extension'] != '')) ? '.' . $attributes['extension'] : '')) . (($attributes['directory'] && $trailingSlashVisible) ? '/' : '') . '</a>' . (!$attributes['directory'] ? ' (' . date ('j/m/y', $attributes['time']) . ', ' . directories::fileSizeFormatted ($_SERVER['DOCUMENT_ROOT'] . $currentDirectory . $file) . ')' : '') . '</li>';
		}
		
		# Complete the list
		#!# Need to make this only appear if there are files as well as directories
		$html .= "\n</ul>" . '
		<ul class="filelistnotes">' . "
			<li>To <strong>open</strong> a file or directory, left-click (PC) or click (Mac) on its name.</li>
			<li>To <strong>save</strong> a file, right-click (PC) or control-click (Mac) on its name and select 'Save Target As...'.</li>
		</ul>";
		
		# Return the HTML
		return $html;
	}
	
	
	# Helper function used for uasort to compare an array case-insensitively
	function compare ($a, $b)
	{
		#!# Document what this does!
		return strcasecmp ($a['name'], $b['name']);
	}
	
	
	# Function to obtain an array of file details from a directory
	function listFiles ($directory, $supportedFileTypes = array ())
	{
		# Append the document root to the current directory (for the lifetime of this function only)
		$directory = $_SERVER['DOCUMENT_ROOT'] . $directory;
		
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
		if ($handle = opendir ($directory)) {
			
			# Loop through each file, excluding . and .., and assign an array of information for it
			while (($file = readdir ($handle)) !== false) {
				if (($file != '.') && ($file != '..')) {
					
					# First check what the extension is
					$extension = (strpos ($file, '.') !== false ? str_replace ('.', '', strrchr ($file, '.')) : '');
					
					# If a non-empty array of supported file types has been supplied, check whether the current file in the loop should be included
					require_once ('application.php');
					$supported = (($allowSupportedFileTypesOnly && (!application::iin_array ($extension, $supportedFileTypes))) ? false : true);
					
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
	function removeHiddenFiles ($hiddenFiles, $files, $currentDirectory, $caseSensitiveMatching = true)
	{
		# Start an array of cleaned files
		$cleanedFiles = array ();
		
		# Construct a list of files to remain hidden
		$osFiles = array ('recycler/', 'RECYCLER/', );
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
						if (eregi (('^' . substr ($hiddenFile, 0, -1)), $testFile)) {
							$hideFile = true;
							
							# However, if the WILDCARD_MATCHES_ZERO_CHARACTERS flag is set, don't hide the file if the test file matches the the hidden file without the *
							if (($testFile == substr ($hiddenFile, 0, -1)) && $wildcardMatchesZeroCharacters) {
								$hideFile = false;
							}
						}
					
					# Check for a wildcard-starting hidden file being the same as the tested file
					} else if (substr ($hiddenFile, 0, 1) == '*') {
						if (eregi ((substr ($hiddenFile, 1) . '$'), $testFile)) {
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
	function defineSupportedExtensions ()
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
			'mov' => 'quicktime.gif',
			'mpa' => 'media.gif',
			'mpeg' => 'media.gif',
			'msg' => 'msg.gif',
			'mtw' => 'minitab.gif',
			'pdf' => 'acrobat.gif',
			'pdx' => 'acrobatindex.gif',
			'png' => 'gif.gif',
			'ps' => 'ps.gif',
			'psd' => 'psd.gif',
			'ppt' => 'ppt.gif',
			'qt' => 'quicktime.jpg',
			'rtf' => 'word.gif',
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
			'xlt' => 'exceltemplate.gif',
			'zip' => 'zip.gif',
		);
	}
	
	
	# Wrapper function to create a formatted listing
	#!# Need to add inheritableExtensions support e.g. .html.old
	function listingWrapper ($iconsDirectory, $iconsServerPath, $hiddenFiles, $caseSensitiveMatching, $titleFile = '.title.txt')
	{
		# Get the contents of the title file
		$titleFile = $_SERVER['DOCUMENT_ROOT'] . $_SERVER['REQUEST_URI'] . $titleFile;
		if (file_exists ($titleFile)) {
			$contents = file_get_contents ($titleFile);
		}
		
		# Start the page
		$html  = "\n\n" . '<h1>' . (isSet ($contents) ? $contents : '&nbsp;') . '</h1>';
		$html .= "\n\n" . '<p><a href="../"><em>&lt; Go back</em></a></p>';
		
		# Show the directory listing
		$html .= directories::listing ($iconsDirectory, $iconsServerPath, $hiddenFiles, $caseSensitiveMatching);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to get directory structure (but not contents)
	function getRecursiveStructure ($directory, $exclude = array ()/*, $onlyInclude = array ()*/)
	{
		# Make sure it's a directory
		if (!is_dir ($directory)) {return false;}
		
		# Ensure supplied file lists are arrays
		require_once ('application.php');
		$exclude = application::ensureArray ($exclude);
		/*$onlyInclude = application::ensureArray ($onlyInclude);*/
		
		# Open the directory
		$contents = array ();
		if ($directoryHandle = opendir ($directory)) {
			
			# Loop through the directory
			while (($item = readdir ($directoryHandle)) !== false) {
				
				# Remove . and ..
				if (($item == '.') || ($item == '..')) {continue;}
				
				# Exclude files if necessary [if an include list is supplied, this will only remove directories]
				if (in_array ($item, $exclude)) {continue;}
				
				# If the item is an array, get its contents
				if (is_dir ($directory . $item)) {
					$contents[$item] = directories::getRecursiveStructure ($directory . $item . '/', $exclude/*, $onlyInclude*/);
					
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
	
	
	# Function to show all the news articles (html files) in a directory
	function showNewsArchive ($directory, $excludeFiles)
	{
		# Get the file listing
		$files = directories::listFiles ($directory, 'html');
		
		# Remove hidden files
		$files = directories::removeHiddenFiles ($excludeFiles, $files, $directory);
		
		# Show in reverse order
		$files = array_reverse ($files);
		
		# Loop through each and show it
		foreach ($files as $file => $attributes) {
			include ($_SERVER['DOCUMENT_ROOT'] . $directory . $file);
		}
	}
	
	
	# Create a function to get the file size
	function fileSizeFormatted ($file)
	{
		# Define size shortcuts
		$kb = 1024;       // Kilobyte
		$mb = 1024 * $kb; // Megabyte
		$gb = 1024 * $mb; // Gigabyte
		$tb = 1024 * $gb; // Terabyte
		
		# Get the file size
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
