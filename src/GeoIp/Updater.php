<?php

namespace PragmaRX\Support\GeoIp;

class Updater
{
    const GEOLITE2_URL_BASE = 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City';

    protected $databaseFileGzipped;

    protected $databaseFile;

    protected $sha256File;

    protected $messages = [];

    /**
     * Add a message.
     *
     * @param $string
     */
    private function addMessage($string)
    {
        $this->messages[] = $string;
    }

    protected function databaseIsUpdated($geoDbFileUrl, $geoDbSha256Url)
    {
        $destinationGeoDbFile = $this->destinationPath . $this->getFileName($geoDbFileUrl);

        $this->sha256File = $this->getHTTPFile($geoDbSha256Url);

        if (! file_exists($destinationGeoDbFile)) {
            return false;
        }

        if ($updated = file_get_contents($this->sha256File) == hash_file('sha256', $destinationGeoDbFile)) {
            $this->addMessage('Database is already updated.');
        }

        return $updated;
    }

    /**
     * Download gzipped database, unzip and check sha256.
     *
     * @param $geoDbUrl
     * @return bool
     */
    protected function downloadGzipped($geoDbUrl)
    {
        if (! $this->databaseFileGzipped = $this->getHTTPFile($geoDbUrl)) {
            $this->addMessage("Unable to download file {$geoDbUrl} to {$this->destinationPath}.");

            return false;
        }

        $this->databaseFile = $this->destinationPath . $this->getFileName($geoDbUrl);

        if (! $this->sha256Match()) {
            return false;
        }

        return $this->dezipGzFile($this->databaseFileGzipped);
    }

    private function getDbFileName($geoDbUrl, $license_key)
    {
        $url = $geoDbUrl ?: static::GEOLITE2_URL_BASE . '&suffix=tar.gz';

        return $this->addLicenseKey($url, $license_key);
    }

    private function getSha256FileName($geoDbSha256Url, $license_key)
    {
        $url = $geoDbSha256Url ?: static::GEOLITE2_URL_BASE . '&suffix=tar.gz.sha256';

        return $this->addLicenseKey($url, $license_key);
    }

    private function addLicenseKey($url, $license_key)
    {
        return $url . '&license_key=' . $license_key;
    }

    /**
     * Get messages.
     *
     * @return mixed
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Make directory.
     *
     * @param $destinationPath
     * @return bool
     */
    protected function makeDir($destinationPath)
    {
        return file_exists($destinationPath) || mkdir($destinationPath, 0770, true);
    }

    /**
     * Compare SHA256s.
     *
     * @return bool
     */
    private function sha256Match()
    {
        $hash = explode('  ', file_get_contents($this->sha256File))[0];

        if (! $match = hash_file('sha256', $this->databaseFileGzipped) == $hash) {
            $this->addMessage("SHA256 is not matching for {$this->databaseFileGzipped} and {$this->sha256File}.");

            return false;
        }

        $this->addMessage("Database successfully downloaded to {$this->databaseFileGzipped}.");

        return true;
    }

    /**
     * Parse url for get file name.
     *
     * @param $filePath
     * @return mixed
     */
    protected function getSourceFileName($filePath)
    {
        $url = parse_url($filePath);
        parse_str($url['query'], $query);

        return "{$query['edition_id']}.{$query['suffix']}";
    }

    /**
     * Remove tar.gz from file name.
     *
     * @param $filePath
     * @return mixed
     */
    protected function getFileName($filePath)
    {
        return str_replace('tar.gz', 'mmdb', $this->getSourceFileName($filePath));
    }

    /**
     * Download and update GeoIp database.
     *
     * @param $destinationPath
     * @param null $geoDbUrl
     * @param null $geoDbSha256Url
     * @return bool
     */
    public function updateGeoIpFiles($destinationPath, $geoDbUrl = null, $geoDbSha256Url = null, $license_key = null)
    {
        $this->destinationPath = $destinationPath . DIRECTORY_SEPARATOR;

        if ($this->databaseIsUpdated($geoDbUrl = $this->getDbFileName($geoDbUrl, $license_key), $this->getSha256FileName($geoDbSha256Url, $license_key))) {
            return true;
        }

        if ($this->downloadGzipped($geoDbUrl)) {
            return true;
        }

        $this->addMessage("Unknown error downloading {$geoDbUrl}.");

        return false;
    }

    /**
     * Read url to file.
     *
     * @param $uri
     * @return bool|string
     */
    protected function getHTTPFile($uri)
    {
        set_time_limit(360);

        if (! $this->makeDir($this->destinationPath)) {
            return false;
        }

        $fileWriteName = $this->destinationPath . $this->getSourceFileName($uri);

        if (($fileRead = @fopen($uri,"rb")) === false || ($fileWrite = @fopen($fileWriteName, 'wb')) === false) {
            $this->addMessage("Unable to open {$uri} (read) or {$fileWriteName} (write).");

            return false;
        }

        while(! feof($fileRead))
        {
            $content = @fread($fileRead, 1024*16);

            $success = fwrite($fileWrite, $content);

            if ($success === false) {
                $this->addMessage("Error downloading file {$uri} to {$fileWriteName}.");

                return false;
            }
        }

        fclose($fileWrite);

        fclose($fileRead);

        return $fileWriteName;
    }

    /**
     * Extract tar.gz file.
     *
     * @param $filePath
     * @return bool|mixed
     */
    protected function dezipGzFile($filePath)
    {
        try {
            $p = new \PharData($filePath);
            $p->decompress();
        } catch (\Exception $e) {
            $this->addMessage("Unable to decompress tar.gz file {$filePath}.");

            return false;
        }

        $tar = str_replace('.gz', '', $filePath);

        try {
            $p = new \PharData($tar);
            $p->extractTo($this->destinationPath, null, true);

            $dirs = \File::directories($this->destinationPath);
        } catch (\Exception $e) {
            $this->addMessage("Unable to extract tar file {$filePath} to {$this->destinationPath}.");

            return false;
        }

        @unlink($tar);

        $out_file_name = basename($this->databaseFile);

        try {
            $from = $dirs[0] . DIRECTORY_SEPARATOR . basename($this->databaseFile);
            $to = $this->destinationPath . DIRECTORY_SEPARATOR . $out_file_name;

            copy($from, $to);
            \File::deleteDirectory($dirs[0]);
        } catch (\Exception $e) {
            $this->addMessage("Unable to copy mmdb file {$from} to {$to}.");

            return false;
        }

        return $out_file_name;
    }
}
