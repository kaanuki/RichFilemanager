<?php

namespace RFM\Storage\Local;

use RFM\Facade\Log;
use RFM\Storage\BaseStorage;
use RFM\Storage\StorageInterface;
use RFM\Storage\StorageTrait;

/**
 *	Local storage class.
 *
 *	@license	MIT License
 *	@author		Pavel Solomienko <https://github.com/servocoder/>
 *	@copyright	Authors
 */

class Storage extends BaseStorage implements StorageInterface
{
    use IdentityTrait;
    use StorageTrait;

	protected $doc_root;
	protected $path_to_files;
	protected $dynamic_fileroot;

	public function __construct($config = [])
    {
		parent::__construct($config);

		$fileRoot = $this->config('options.fileRoot');
		if ($fileRoot !== false) {
			// takes $_SERVER['DOCUMENT_ROOT'] as files root; "fileRoot" is a suffix
			if($this->config('options.serverRoot') === true) {
				$this->doc_root = $_SERVER['DOCUMENT_ROOT'];
				$this->path_to_files = $_SERVER['DOCUMENT_ROOT'] . '/' . $fileRoot;
			}
			// takes "fileRoot" as files root; "fileRoot" is a full server path
			else {
				$this->doc_root = $fileRoot;
				$this->path_to_files = $fileRoot;
			}
		} else {
            // default storage folder in case of default RFM structure
			$this->doc_root = $_SERVER['DOCUMENT_ROOT'];
			$this->path_to_files = dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME']))) . '/userfiles';
		}

		// normalize slashes in paths
        $this->doc_root = $this->cleanPath($this->doc_root);
		$this->path_to_files = $this->cleanPath($this->path_to_files);
        $this->dynamic_fileroot = $this->subtractPath($this->path_to_files, $this->doc_root);

		Log::info('$this->path_to_files: "' . $this->path_to_files . '"');
		Log::info('$this->doc_root: "' . $this->doc_root . '"');
		Log::info('$this->dynamic_fileroot: "' . $this->dynamic_fileroot . '"');
	}

    /**
     * @inheritdoc
     */
	public function setRoot($path, $mkdir = false)
    {
		if($this->config('options.serverRoot') === true) {
			$this->dynamic_fileroot = $path;
			$this->path_to_files = $this->cleanPath($this->doc_root . '/' . $path);
		} else {
			$this->path_to_files = $this->cleanPath($path);
		}

		Log::info('Overwritten with setRoot() method:');
		Log::info('$this->path_to_files: "' . $this->path_to_files . '"');
		Log::info('$this->dynamic_fileroot: "' . $this->dynamic_fileroot . '"');

		if($mkdir && !file_exists($this->path_to_files)) {
			mkdir($this->path_to_files, 0755, true);
			Log::info('creating "' . $this->path_to_files . '" folder through mkdir()');
		}
	}

    /**
     * @inheritdoc
     */
    public function getRoot()
    {
        return $this->path_to_files;
    }

    /**
     * @inheritdoc
     */
    public function getDynamicRoot()
    {
        return $this->dynamic_fileroot;
    }

	/**
     * Initiate uploader instance and handle uploads.
     *
	 * @param ItemModel $model
	 * @return UploadHandler
	 */
	public function initUploader($model)
	{
        //'images_only' => $this->config('upload.imagesOnly') || (isset($this->refParams['type']) && strtolower($this->refParams['type']) === 'images'),

		return new UploadHandler([
			'model' => $model,
		]);
	}

	/**
	 * Create a zip file from source to destination.
     *
	 * @param  	string $source Source path for zip
	 * @param  	string $destination Destination path for zip
	 * @param  	boolean $includeFolder If true includes the source folder also
	 * @return 	boolean
	 * @link	http://stackoverflow.com/questions/17584869/zip-main-folder-with-sub-folder-inside
	 */
	public function zipFile($source, $destination, $includeFolder = false)
	{
		if (!extension_loaded('zip') || !file_exists($source)) {
			return false;
		}

		$zip = new \ZipArchive();
		if (!$zip->open($destination, \ZipArchive::CREATE)) {
			return false;
		}

		$source = str_replace('\\', '/', realpath($source));
		$folder = $includeFolder ? basename($source) . '/' : '';

		if (is_dir($source) === true) {
			// add file to prevent empty archive error on download
			$zip->addFromString('fm.txt', "This archive has been generated by Rich Filemanager : https://github.com/servocoder/RichFilemanager/");

			$files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ($files as $file) {
				$file = str_replace('\\', '/', realpath($file));

				if (is_dir($file) === true) {
					$path = str_replace($source . '/', '', $file . '/');
					$zip->addEmptyDir($folder . $path);
				} else if (is_file($file) === true) {
					$path = str_replace($source . '/', '', $file);
					$zip->addFile($file, $folder . $path);
				}
			}
		} else if (is_file($source) === true) {
			$zip->addFile($source, $folder . basename($source));
		}

		return $zip->close();
	}

    /**
     * Delete folder recursive.
     *
     * @param string $dir
     * @param bool $deleteRootToo
     */
    public function unlinkRecursive($dir, $deleteRootToo = true)
    {
		if(!$dh = @opendir($dir)) {
			return;
		}
		while (false !== ($obj = readdir($dh))) {
			if($obj == '.' || $obj == '..') {
				continue;
			}

			if (!@unlink($dir . '/' . $obj)) {
				$this->unlinkRecursive($dir.'/'.$obj, true);
			}
		}
		closedir($dh);

		if ($deleteRootToo) {
			@rmdir($dir);
		}

		return;
	}

	/**
	 * Format timestamp string
	 * @param string $timestamp
	 * @return string
	 */
	public function formatDate($timestamp)
	{
		return date($this->config('options.dateFormat'), $timestamp);
	}

	/**
	 * Return summary info for specified folder.
     *
	 * @param string $dir
	 * @param array $result
	 * @return array
	 */
	public function getDirSummary($dir, &$result = ['size' => 0, 'files' => 0, 'folders' => 0])
	{
		// suppress permission denied and other errors
		$files = @scandir($dir);
		if($files === false) {
			return $result;
		}

		foreach($files as $file) {
			if($file == "." || $file == "..") {
				continue;
			}
			$path = $dir . $file;
            $is_dir = is_dir($path);
            $relative_path = $this->getRelativePath($path) . ($is_dir ? '/' : '');
            $is_allowed_path = $this->has_read_permission($path) && $this->is_unrestricted($relative_path);

            if ($is_dir && $is_allowed_path) {
                $result['folders']++;
                $this->getDirSummary($path . '/', $result);
            }
            if (!$is_dir && $is_allowed_path) {
                $result['files']++;
                $result['size'] += filesize($path);
            }
		}

		return $result;
	}

	/**
	 * Calculate total size of all files.
     *
	 * @return mixed
	 */
	public function getRootTotalSize()
	{
		$path = rtrim($this->path_to_files, '/') . '/';
		$result = $this->getDirSummary($path);
		return $result['size'];
	}

	/**
	 * Create thumbnail from the original image.
     *
	 * @param ItemModel $modelImage
	 * @param ItemModel $modelThumb
	 */
    public function createThumbnail($modelImage, $modelThumb)
    {
        $valid = !$this->config('read_only');
        $valid = $valid && $this->has_read_permission($modelImage->pathAbsolute);

        // parent
        $modelTarget = $modelThumb->parent();

        if (!$modelTarget->isExists) {
            // Check that the thumbnail sub-dir can be created, because it
            // does not yet exist. So we check the parent dir:
            $valid = $valid && $this->has_write_permission(dirname($modelTarget->pathAbsolute));
        } else {
            // Check that the thumbnail sub-dir, which exists, is writable:
            $valid = $valid && $this->has_write_permission($modelTarget->pathAbsolute);
        }

        if ($valid && $this->config('images.thumbnail.enabled') === true) {
            Log::info('generating thumbnail "' . $modelThumb->pathAbsolute . '"');

            // create folder if it does not exist
            if ($modelTarget->isExists) {
                mkdir($modelTarget->pathAbsolute, 0755, true);
            }

            $this->initUploader($modelImage->parent())
                ->create_thumbnail_image(basename($modelImage->pathAbsolute));
        }
    }

    /**
     * Return full path to item.
     *
     * @param string $path - relative path
     * @return string
     */
    public function getFullPath($path)
    {
        return $this->cleanPath($this->path_to_files . '/' . $path);
    }

    /**
     * Return path without document root.
     *
     * @param string $path - absolute path
     * @return mixed
     */
    public function getDynamicPath($path)
    {
        // empty string makes FM to use connector path for preview instead of absolute path
        // COMMENTED: due to it prevents to build absolute URL when "serverRoot" is "false" and "fileRoot" is provided
        // as well as "previewUrl" value in the JSON configuration file is set to the correct URL
//        if(empty($this->dynamic_fileroot)) {
//            return '';
//        }
        $path = $this->dynamic_fileroot . '/' . $this->getRelativePath($path);
        return $this->cleanPath($path);
    }

    /**
     * Return path without "path_to_files"
     *
     * @param string $path - absolute path
     * @return mixed
     */
    public function getRelativePath($path)
    {
        return $this->subtractPath($path, $this->path_to_files);
    }

    /**
     * Check whether the folder is root.
     *
     * @param string $path - absolute path
     * @return bool
     */
    public function is_root_folder($path)
    {
        return rtrim($this->path_to_files, '/') == rtrim($path, '/');
    }

    /**
     * Subtracts subpath from the fullpath.
     *
     * @param string $fullPath
     * @param string $subPath
     * @return string
     */
    public function subtractPath($fullPath, $subPath)
    {
        $position = strrpos($fullPath, $subPath);
        if($position === 0) {
            $path = substr($fullPath, strlen($subPath));
            return $path ? $this->cleanPath('/' . $path) : '';
        }
        return '';
    }

    /**
     * Clean path string to remove multiple slashes, etc.
     *
     * @param string $string
     * @return string
     */
    public function cleanPath($string)
    {
        // replace backslashes (windows separators)
        $string = str_replace("\\", "/", $string);
        // remove multiple slashes
        $string = preg_replace('#/+#', '/', $string);

        return $string;
    }

    /**
     * Check whether path is valid by comparing paths.
     *
     * @param string $path - absolute path
     * @return bool
     */
    public function is_valid_path($path)
    {
        $rp_substr = substr(realpath($path) . DS, 0, strlen(realpath($this->path_to_files))) . DS;
        $rp_files = realpath($this->path_to_files) . DS;

        // handle better symlinks & network path
        $pattern = ['/\\\\+/', '/\/+/'];
        $replacement = ['\\\\', '/'];
        $rp_substr = preg_replace($pattern, $replacement, $rp_substr);
        $rp_files = preg_replace($pattern, $replacement, $rp_files);
        $match = ($rp_substr === $rp_files);

        if(!$match) {
            Log::info('Invalid path "' . $path . '"');
            Log::info('real path: "' . $rp_substr . '"');
            Log::info('path to files: "' . $rp_files . '"');
        }
        return $match;
    }

    /**
     * Check the extensions blacklist for path.
     *
     * @param string $path - relative path
     * @return bool
     */
    public function is_allowed_extension($path)
    {
        // check the extension (for files):
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $extension_restrictions = $this->config('security.extensions.restrictions');

        if ($this->config('security.extensions.ignorecase')) {
            $extension = strtolower($extension);
            $extension_restrictions = array_map('strtolower', $extension_restrictions);
        }

        if($this->config('security.extensions.policy') === 'ALLOW_LIST') {
            if(!in_array($extension, $extension_restrictions)) {
                // Not in the allowed list, so it's restricted.
                return false;
            }
        }
        else if($this->config('security.extensions.policy') === 'DISALLOW_LIST') {
            if(in_array($extension, $extension_restrictions)) {
                // It's in the disallowed list, so it's restricted.
                return false;
            }
        }
        else {
            // Invalid config option for 'policy'. Deny everything for safety.
            return false;
        }

        // Nothing restricted this path, so it is allowed.
        return true;
    }

    /**
     * Check the patterns blacklist for path.
     *
     * @param string $path - relative path
     * @return bool
     */
    public function is_allowed_path($path)
    {
        // check the relative path against the glob patterns:
        $basename = pathinfo($path, PATHINFO_BASENAME);
        $basename_restrictions = $this->config('security.patterns.restrictions');

        if ($this->config('security.patterns.ignorecase')) {
            $basename = strtolower($basename);
            $basename_restrictions = array_map('strtolower', $basename_restrictions);
        }

        // (check for a match before applying the restriction logic)
        $match_was_found = false;
        foreach ($basename_restrictions as $pattern) {
            if (fnmatch($pattern, $basename)) {
                $match_was_found = true;
                break;  // Done.
            }
        }

        if($this->config('security.patterns.policy') === 'ALLOW_LIST') {
            if(!$match_was_found) {
                // The $basename did not match the allowed pattern list, so it's restricted:
                return false;
            }
        }
        else if($this->config('security.patterns.policy') === 'DISALLOW_LIST') {
            if($match_was_found) {
                // The $basename matched the disallowed pattern list, so it's restricted:
                return false;
            }
        }
        else {
            // Invalid config option for 'policy'. Deny everything for safety.
            return false;
        }

        // Nothing is restricting access to this item, so it is allowed.
        return true;
    }

    /**
     * Verify if item has read permission, without exiting if not.
     *
     * @param string $path - absolute path
     * @return bool
     */
    public function has_read_permission($path)
    {
        // Check system permission (O.S./filesystem/NAS)
        if ($this->has_system_read_permission($path) === false) {
            return false;
        }

        // Check the user's Auth API callback:
        if (fm_has_read_permission($path) === false) {
            return false;
        }

        // Nothing is restricting access to this item, so it is readable
        return true;
    }

    /**
     * Verify if item has write permission, without exiting if not.
     *
     * @param string $path - absolute path
     * @return bool
     */
    public function has_write_permission($path)
    {
        // Does the path already exist?
        if (!file_exists($path)) {
            // It does not exist (yet). Check to see if we could write to this
            // path, by seeing if we can write new entries into its parent dir.
            $parent_dir = pathinfo($path, PATHINFO_DIRNAME);
            return $this->has_write_permission($parent_dir);
        }

        //
        // The item (file or dir) does exist, so check its permissions:
        //

        // Check system permission (O.S./filesystem/NAS)
        if ($this->has_system_write_permission($path) === false) {
            return false;
        }

        // Check the global read_only config flag:
        if ($this->config('security.read_only') !== false) {
            return false;
        }

        // Check the user's Auth API callback:
        if (fm_has_write_permission($path) === false) {
            return false;
        }

        // Nothing is restricting access to this item, so it is writable
        return true;
    }

    /**
     * Verify if system read permission is granted.
     *
     * @param string $path - absolute path
     * @return bool
     */
    public function has_system_read_permission($path)
    {
        return is_readable($path);
    }

    /**
     * Verify if system write permission is granted.
     *
     * @param string $path - absolute path
     * @return bool
     */
    public function has_system_write_permission($path)
    {
        // In order to create an entry in a POSIX dir, it must have
        // both `-w-` write and `--x` execute permissions.
        //
        // NOTE: Windows PHP doesn't support standard POSIX permissions.
        if (is_dir($path) && !(app()->php_os_is_windows())) {
            return (is_writable($path) && is_executable($path));
        }

        return is_writable($path);
    }

    /**
     * Defines real size of file.
     * Based on https://github.com/jkuchar/BigFileTools project by Jan Kuchar
     *
     * @param string $path - absolute path
     * @return int|string
     * @throws \Exception
     */
    public function get_real_filesize($path)
    {
        // This should work for large files on 64bit platforms and for small files everywhere
        $fp = fopen($path, "rb");
        if (!$fp) {
            throw new \Exception("Cannot open specified file for reading.");
        }
        $flockResult = flock($fp, LOCK_SH);
        $seekResult = fseek($fp, 0, SEEK_END);
        $position = ftell($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if(!($flockResult === false || $seekResult !== 0 || $position === false)) {
            return sprintf("%u", $position);
        }

        // Try to define file size via CURL if installed
        if (function_exists("curl_init")) {
            $ch = curl_init("file://" . rawurlencode($path));
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $data = curl_exec($ch);
            curl_close($ch);
            if ($data !== false && preg_match('/Content-Length: (\d+)/', $data, $matches)) {
                return $matches[1];
            }
        }

        return filesize($path);
    }
}
