<?php
	/*
		Class: BigTreeStorage
			Facilitates the storage, deletion, and replacement of files (whether local or cloud stored).
	*/

	class BigTreeStorage {

		public $AutoJPEG = false;
		public $DisabledFileError = false;
		public $DisabledFileExtensions = [
			"exe", "com", "bat", "rb", "py", "cgi", "pl",
			"sh", "asp", "aspx", "htaccess", "phar",
			"php", "php3", "php4", "php5", "phtml"
		];
		public $Service = "";
		public $Cloud = false;
		public $Settings;

		/*
			Constructor:
				Retrieves the current desired service and image processing availability.
		*/

		public function __construct($force_local = false) {
			global $cms;

			// Backwards compatibility
			$this->Settings = new stdClass;
			$settings_array = $cms->getSetting("bigtree-internal-storage");
			
			foreach ($settings_array as $key => $value) {
				$this->Settings->$key = $value;
			}

			if (!$force_local && !empty($this->Settings->Service)) {
				if ($this->Settings->Service == "s3" || $this->Settings->Service == "amazon") {
					$this->Cloud = new BigTreeCloudStorage("amazon");
				} elseif ($this->Settings->Service == "rackspace") {
					$this->Cloud = new BigTreeCloudStorage("rackspace");
				} elseif ($this->Settings->Service == "google") {
					$this->Cloud = new BigTreeCloudStorage("google");
				}
			}
		}

		/*
			Function: convertJPEG
				Internal function for turning PNGs uploaded into JPG
		*/

		protected function convertJPEG($file,$name) {
			global $bigtree;

			// Try to figure out what this file is
			list($iwidth,$iheight,$itype,$iattr) = @getimagesize($file);

			if (($this->AutoJPEG || !empty($bigtree["config"]["image_force_jpeg"])) && $itype == IMAGETYPE_PNG) {
				// See if this PNG has any alpha channels, if it does we're not doing a JPG conversion.
				$alpha = ord(@file_get_contents($file,null,null,25,1));
				if ($alpha != 4 && $alpha != 6) {
					// Convert the PNG to JPG
					$source = imagecreatefrompng($file);
					imagejpeg($source,$file,$bigtree["config"]["image_quality"]);
					imagedestroy($source);

					// If they originally uploaded a JPG we rotated into a PNG, we don't want to change the desired filename, but if they uploaded a PNG the new file should be JPG
					if (strtolower(substr($name,-3,3)) == "png") {
						$name = substr($name,0,-3)."jpg";
					}
				}
			}

			return $name;
		}

		/*
			Function: delete
				Deletes a file from the active storage service.

			Parameters:
				file_location - The URL of the file.
		*/

		public function delete($file_location) {
			// Make sure we're using IPLs so we don't get it confused with cloud
			$file_location = str_replace(array(STATIC_ROOT,WWW_ROOT),array("{staticroot}","{wwwroot}"),$file_location);

			// Cloud
			if (substr($file_location,0,4) == "http" || substr($file_location,0,2) == "//") {
				// Try to get the container and pointer
				$parts = explode("/",$file_location);
				$domain = $parts[2];
				$container = $parts[3];
				$pointer_parts = array_slice($parts,4);

				// If this bucket is behind a CloudFront distribution, invalidate the cache and delete the file from the currently active bucket
				if (!empty($this->Cloud->Settings["amazon"]["cloudfront_distribution"]) && $this->Cloud->Settings["amazon"]["cloudfront_domain"] == $domain) {
					$file_name = implode("/", array_slice($parts, 3));

					$this->Cloud->deleteFile($this->Settings->Container, $file_name);
					$this->Cloud->invalidateCache($file_name);

					SQL::query("DELETE FROM bigtree_caches WHERE `identifier` = 'org.bigtreecms.cloudfiles' AND `key` = ?", $file_name);

					return;
				}

				if (!empty($this->Settings->CDNDomain) && $this->Settings->CDNDomain == $domain) {
					$service = "amazon";
					$container = $this->Settings->Container;
					$pointer_parts = array_slice($parts, 3);
				} elseif (strpos($domain, "amazonaws.com") !== false) {
					$service = "amazon";

					if ($domain != "s3.amazonaws.com") {
						$domain_parts = explode(".", $domain);
						$container = $domain_parts[0];
						$pointer_parts = array_slice($parts, 3);
					}
				} elseif ($domain == "storage.googleapis.com") {
					$service = "google";
				} else {
					$service = "rackspace";
					// Need to figure out the actual container
					$container = false;
					$cloud = ($this->Settings->Service == $service) ? $this->Cloud : new BigTreeCloudStorage;

					foreach ($cloud->Settings["rackspace"]["container_cdn_urls"] as $c => $url) {
						if ($url == "http://$domain") {
							$container = $c;
						}
					}

					if (!$container) {
						return false;
					}

					$pointer_parts = array_slice($parts,3);
				}

				if ($this->Settings->Service == $service) {
					$pointer = implode("/",$pointer_parts);
					$this->Cloud->deleteFile($container,$pointer);

					if ($this->Settings->Container == $container) {
						sqlquery("DELETE FROM bigtree_caches WHERE `identifier` = 'org.bigtreecms.cloudfiles' AND `key` = '".sqlescape($pointer)."'");
					}
				} else {
					// We might have already made an instance for Rackspace
					$cloud = isset($cloud) ? $cloud : new BigTreeCloudStorage($service);
					$cloud->deleteFile($container,implode("/",$pointer_parts));
				}
			// Local
			} else {
				unlink(str_replace(array("{wwwroot}","{staticroot}"),SITE_ROOT,$file_location));
			}
		}

		/*
			Function: replace
				Stores a file to the current storage service and replaces any existing file with the same file_name.

			Parameters:
				local_file - The absolute path to the local file you wish to store.
				file_name - The file name at the storage end point.
				relative_path - The path (relative to SITE_ROOT or the bucket / container root) in which to store the file.
				remove_original - Whether to delete the local_file or not (defaults to true)
				force_local - Forces a local file replacement even if cloud storage is in use by default (defaults to false)

			Returns:
				The URL of the stored file.
		*/

		public function replace($local_file, $file_name, $relative_path, $remove_original = true, $force_local = false) {
			// Make sure there are no path exploits
			$file_name = BigTree::cleanFile($file_name);
			$extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

			// If the file name ends in a disabled extension, fail.
			if (in_array($extension, $this->DisabledFileExtensions)) {
				$this->DisabledFileError = true;
				unlink($local_file);

				return false;
			}

			// If we're auto converting images to JPG from PNG
			$file_name = $this->convertJPEG($local_file,$file_name);

			// Enforce trailing slash on relative_path
			$relative_path = $relative_path ? rtrim($relative_path,"/")."/" : "files/";

			if ($this->Cloud && !$force_local) {
				if ($relative_path === "/") {
					$relative_path = "";
				}
				
				$success = $this->Cloud->uploadFile($local_file,$this->Settings->Container,$relative_path.$file_name,true);

				if ($success) {
					BigTreeCMS::cachePut("org.bigtreecms.cloudfiles", $relative_path.$file_name, [
						"name" => $file_name,
						"path" => $relative_path.$file_name,
						"size" => filesize($local_file),
					]);
					BigTreeCMS::cacheDelete("org.bigtreecms.imagesize", $success);
				}

				if ($remove_original) {
					unlink($local_file);
				}

				// If this bucket is behind a CloudFront distribution, invalidate the cache and return the CloudFront domain
				if (!empty($this->Cloud->Settings["amazon"]["cloudfront_distribution"])) {
					$this->Cloud->invalidateCache($relative_path.$file_name);
					$protocol = $this->Cloud->Settings["amazon"]["cloudfront_ssl"] ? "https" : "http";

					return $protocol."://".$this->Cloud->Settings["amazon"]["cloudfront_domain"]."/".$relative_path.$file_name;
				}

				return $success;
			} else {
				if ($remove_original) {
					$success = BigTree::moveFile($local_file,SITE_ROOT.$relative_path.$file_name);
				} else {
					$success = BigTree::copyFile($local_file,SITE_ROOT.$relative_path.$file_name);
				}
				
				if ($success) {
					return "{staticroot}".$relative_path.$file_name;
				} else {
					return false;
				}
			}
		}

		/*
			Function: saveSettings
				Saves the object's settings back to the database.
		*/

		public function saveSettings() {
			BigTreeAdmin::updateInternalSettingValue("bigtree-internal-storage", get_object_vars($this->Settings));
		}

		/*
			Function: store
				Stores a file to the current storage service and finds a unique filename if collisions exist.

			Parameters:
				local_file - The absolute path to the local file you wish to store.
				file_name - The desired file name at the storage end point.
				relative_path - The path (relative to SITE_ROOT or the bucket / container root) in which to store the file.
				remove_original - Whether to delete the local_file or not.
				prefixes - A list of file prefixes that also need to be accounted for when checking file name availability.
				sanitize_file_name - Whether to sanitize a file name (defaults to true)

			Returns:
				The URL of the stored file.
		*/

		public function store($local_file, $file_name, $relative_path, $remove_original = true, $prefixes = [], $sanitize_file_name = true) {
			// Make sure there are no path exploits
			$file_name = BigTree::cleanFile($file_name);
			$extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

			// If the file name ends in a disabled extension, fail.
			if (in_array($extension, $this->DisabledFileExtensions)) {
				$this->DisabledFileError = true;
				unlink($local_file);

				return false;
			}

			// If we're auto converting images to JPG from PNG
			$file_name = $this->convertJPEG($local_file,$file_name);

			// Enforce trailing slashe on relative_path
			$relative_path = $relative_path ? rtrim($relative_path,"/")."/" : "files/";

			if ($this->Cloud) {
				// Clean up the file name
				global $cms;

				$parts = BigTree::pathInfo($file_name);
				$clean_name = $sanitize_file_name ? $cms->urlify($parts["filename"]) : $parts["filename"];

				if (strlen($clean_name) > 50) {
					$clean_name = substr($clean_name,0,50);
				}

				// Best case name
				$file_name = $clean_name.".".strtolower($parts["extension"]);
				$x = 2;

				// Make sure we have a unique name
				while (!$file_name || sqlrows(sqlquery("SELECT `timestamp` FROM bigtree_caches WHERE `identifier` = 'org.bigtreecms.cloudfiles' AND `key` = '".sqlescape($relative_path.$file_name)."'"))) {
					$file_name = $clean_name."-$x.".strtolower($parts["extension"]);
					$x++;

					// Check all the prefixes, make sure they don't exist either
					if (is_array($prefixes) && count($prefixes)) {
						$prefix_query = array();

						foreach ($prefixes as $prefix) {
							$prefix_query[] = "`key` = '".sqlescape($relative_path.$prefix.$file_name)."'";
						}

						if (sqlrows(sqlquery("SELECT `timestamp` FROM bigtree_caches WHERE identifier = 'org.bigtreecms.cloudfiles' AND (".implode(" OR ",$prefix_query).")"))) {
							$file_name = false;
						}
					}
				}

				// Upload it
				$success = $this->Cloud->uploadFile($local_file,$this->Settings->Container,$relative_path.$file_name,true);

				if ($success) {
					sqlquery("INSERT INTO bigtree_caches (`identifier`,`key`,`value`) VALUES ('org.bigtreecms.cloudfiles','".sqlescape($relative_path.$file_name)."','".sqlescape(json_encode(array("name" => $file_name,"path" => $relative_path.$file_name,"size" => filesize($local_file))))."')");
				}

				if ($remove_original) {
					unlink($local_file);
				}

				// If this bucket is behind a CloudFront distribution, invalidate the cache and return the CloudFront domain
				if (!empty($this->Cloud->Settings["amazon"]["cloudfront_distribution"])) {
					$this->Cloud->invalidateCache($relative_path.$file_name);
					$protocol = $this->Cloud->Settings["amazon"]["cloudfront_ssl"] ? "https" : "http";

					return $protocol."://".$this->Cloud->Settings["amazon"]["cloudfront_domain"]."/".$relative_path.$file_name;
				}

				return $success;
			} else {
				$safe_name = BigTree::getAvailableFileName(SITE_ROOT.$relative_path,$file_name,$prefixes);

				if ($remove_original) {
					$success = BigTree::moveFile($local_file,SITE_ROOT.$relative_path.$safe_name);
				} else {
					$success = BigTree::copyFile($local_file,SITE_ROOT.$relative_path.$safe_name);
				}

				if ($success) {
					return "{staticroot}".$relative_path.$safe_name;
				} else {
					return false;
				}
			}
		}
	}

	// Backwards compatibility
	class BigTreeUploadService extends BigTreeStorage {
		public function upload($local_file,$file_name,$relative_path,$remove_original = true) {
			return $this->store($local_file,$file_name,$relative_path,$remove_original);
		}
	}
