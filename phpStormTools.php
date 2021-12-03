#!/usr/bin/env php
<?php
//This is a work in progress, but I find it useful

if (PHP_SAPI != "cli") {
    exit('Must be run from the CLI');
}
$command = $argv[1];
$data1 = $argv[2];
$data2 = '';
if (isset($argv[3])) $data2 = $argv[3];
$data3 = '';
if (isset($argv[4])) $data3 = $argv[4];
$phpStormTools = new phpStormTools();
$homeDir = $phpStormTools->home;
switch ($command) {
    case 'getExtFiles':
        $phpStormTools->getExtFiles($data1);
        break;
    case 'gitStatus':
        $phpStormTools->gitStatus($data1);
        break;
    case 'gitChange':
        $phpStormTools->gitChange($data1);
        break;
    case 'copyChangedFiles':
        $phpStormTools->copyChangedFiles($data1, $data2);
        break;
    case 'packageGIT':
        $phpStormTools->packageGIT($homeDir . $data1);
        break;
    case 'copy':
        $phpStormTools->copy($data1, $data2, $data3);
        break;
    default:
        echo "Command not recognised" . PHP_EOL;
        break;
}

class phpStormTools
{
    public $home;
    //The directory that contains your local copy of SugarCRM
    private $pathToLocalSugarCRM = "/Users/kenbrill/crm-sugar";
    //THe path on the server to that copy of SugarCRM
    private $pathToRemoteSugarCRM = "/var/www/sugarcrm";
    //An array of production servers
    private $serversRequiringConfirmation = ['web-prod', 'sdi-prod', 'web3-prod'];

    public function __construct()
    {
        $this->home = posix_getpwuid(getmyuid())['dir'];
    }

    /**
     * Copies all files with an .ext.php extension from the server to your local copy
     *
     * @param $destinationServer [name of server from .ssh/config eg ken-dev]
     */
    public function getExtFiles($destinationServer)
    {
        //Delete old files
        $remoteFiles = "ssh $destinationServer \"cd {$this->pathToRemoteSugarCRM} && find . -type f -name '*.ext.*'\"";
        exec($remoteFiles, $cpList, $retVal);
        $localFiles = "find {$this->pathToLocalSugarCRM} -type f -name '*.ext.*'";
        exec($localFiles, $rmList, $retVal);

        //fix all the paths so they are relative for the project folder
        array_walk($cpList, function (&$n) {
            $n = substr($n, 2);
        });
        array_walk($rmList, function (&$n) {
            $n = substr($n, strlen($this->pathToLocalSugarCRM) + 1);
        });

        //We find all files that exist locally but not remotely and delete them
        $filesThatNeedToBeDeleted = array_diff($rmList, $cpList);
        foreach ($filesThatNeedToBeDeleted as $fileName) {
            $fileName = $this->pathToLocalSugarCRM . '/' . $fileName;
            echo "rm {$fileName}" . PHP_EOL;
            unlink($fileName);
        }
        unset($rmList, $filesThatNeedToBeDeleted);

        //Get New Files
        $i = 0;
        foreach ($cpList as $path) {
            $dirName = dirname($this->pathToLocalSugarCRM . '/' . $path);
            if (!file_exists($dirName)) mkdir($dirName, 0777, true);
            $localFile = $this->pathToLocalSugarCRM . '/' . $path;
            if (!file_exists($localFile)) {
                $command1 = "scp {$destinationServer}:{$this->pathToRemoteSugarCRM}/{$path} {$this->pathToLocalSugarCRM}/{$path}";
                exec($command1, $output1, $retVal1);
                $numOfFiles = count($cpList);
                $percent = round((($i / $numOfFiles) * 100), 1);
                echo "({$i}/{$numOfFiles}) - {$percent}% - {$this->pathToLocalSugarCRM}/{$path}" . PHP_EOL;
            }
            $i++;
        }
        unset($cpList);
    }

    /**
     * Copy a directory or just a file to or from the server
     *
     * @param string $destinationServer [name of server from .ssh/config eg ken-dev]
     * @param string $path
     * @param int $direction [0=from server 1=to server]
     */
    public function copy(string $destinationServer, string $path, int $direction = 1)
    {
        $js = false;
        if (is_dir($this->pathToLocalSugarCRM . '/' . $path)) {
            if ($direction === 1) {
                $command = "scp -r {$this->pathToLocalSugarCRM}/{$path} {$destinationServer}:{$this->pathToRemoteSugarCRM}/{$path}";
            } else {
                $command = "scp -r {$destinationServer}:{$this->pathToRemoteSugarCRM}/{$path} {$this->pathToLocalSugarCRM}/{$path}";
            }
            $this->confirm($command);
            echo $command . PHP_EOL;
            exec($command, $output, $retVal);
            foreach ($output as $fileName) {
                if (stristr($path, '.js') !== false) {
                    $js = true;
                }
            }
            $numberOfFilesCopied = count($output);
            echo "Complete, {$numberOfFilesCopied} files copied to server (returned error code: [{$retVal}])" . PHP_EOL;
        } else {
            if ($direction === 1) {
                $command = "scp {$this->pathToLocalSugarCRM}/{$path} {$destinationServer}:{$this->pathToRemoteSugarCRM}/{$path}";
            } else {
                $command = "scp {$destinationServer}:{$this->pathToRemoteSugarCRM}/{$path} {$this->pathToLocalSugarCRM}/{$path}";
            }
            $this->confirm($command);
            if (stristr($path, '.js') !== false) {
                $js = true;
            }
            echo $command . PHP_EOL;
            exec($command, $output, $retVal);
            echo "Complete (returned error code: [{$retVal}])" . PHP_EOL;
        }
        if ($js === true && $direction === 1) {
            $this->clearJS($destinationServer);
        }
    }

    /**
     * Clears the JS Cache if any file changed is a javascript file
     *
     * @param string $destinationServer [name of server from .ssh/config eg ken-dev]
     */
    public function clearJS(string $destinationServer)
    {
        echo PHP_EOL;
        $command = "ssh $destinationServer \"cd {$this->pathToRemoteSugarCRM} && rm -Rfv cache/javascript/*\"";
        exec($command, $output, $retVal);
        foreach ($output as $message) {
            echo $message . PHP_EOL;
        }
    }

    /**
     * Checks out the same branch on the server as I have checked out locally
     *
     * @param string $destinationServer [name of server from .ssh/config eg ken-dev]
     */
    public function gitChange(string $destinationServer)
    {
        chdir($this->pathToLocalSugarCRM);
        $command = "git symbolic-ref --short HEAD";
        exec($command, $output, $retVal);
        $branch = $output[0];
        $command = "ssh $destinationServer \"cd {$this->pathToRemoteSugarCRM} && git fetch && git checkout -f {$branch} && git pull\"";
        exec($command, $output, $retVal);
        foreach ($output as $line) {
            echo $line . PHP_EOL;
        }
    }

    /**
     * Shows the `git status` from the server side
     *
     * @param string $destinationServer [name of server from .ssh/config eg ken-dev]
     */
    public function gitStatus(string $destinationServer)
    {
        $command = "ssh $destinationServer \"cd /var/www/sugarcrm && git status\"";
        exec($command, $output, $retVal);
        foreach ($output as $line) {
            echo $line . PHP_EOL;
        }
    }

    /**
     * Copy files on the current `git status` list to or from the server
     *
     * @param string $destinationServer [name of server from .ssh/config eg ken-dev]
     * @param int $copyToRemote
     */
    public function copyChangedFiles(string $destinationServer, int $copyToRemote = 0)
    {
        if ($copyToRemote === 1) {
            $command = "ssh {$destinationServer} \"cd {$this->pathToRemoteSugarCRM}; git status -s | cut -c4-\"";
        } else {
            $command = "cd {$this->pathToLocalSugarCRM};git status -s | cut -c4-";
        }
        exec($command, $output, $retVal);
        $numberOfFiles = count($output);
        $this->confirm("There are {$numberOfFiles} files to copy...", true);
        exec($command, $output, $retVal);
        foreach ($output as $path) {
            if ($copyToRemote) {
                $command = "scp {$this->pathToLocalSugarCRM}/{$path} {$destinationServer}:{$this->pathToRemoteSugarCRM}/{$path}";
            } else {
                $command = "scp {$destinationServer}:{$this->pathToRemoteSugarCRM}/{$path} {$this->pathToLocalSugarCRM}/{$path}";
            }
            exec($command, $output1, $retVal1);
            echo $command . " (Result code: {$retVal1})" . PHP_EOL;
        }
    }

    /**
     * @param $name
     */
    public function packageGIT($name)
    {
        $files = $this->copyGIT($name);
        $this->makeManifest($name, $files);
    }

    /**
     * @param $name
     * @param $files
     */
    private function makeManifest($name, $files)
    {
        echo "Creating Manifest." . PHP_EOL;
        $packageName = basename($name);
        $installDefs = array(
            'id' => 'CUSTOM' . date("U"),
            'copy' => array(),
            'pre_execute' => array()
        );
        $dop = date("Y-m-d H:i:s");
        foreach ($files as $file) {
            $installDefs['copy'][] =
                array('from' => '<basepath>' . DIRECTORY_SEPARATOR . $file, 'to' => $file);
        }
        $manifest = "<?php
\$manifest = array(
    'acceptable_sugar_flavors' => array('CE','PRO','CORP','ENT','ULT'),
    'acceptable_sugar_versions' => array(
        'exact_matches' => array(),
        'regex_matches' => array('(.*?)\\.(.*?)\\.(.*?)$'),
    ),
    'author' => 'Kenneth Brill',
    'description' => '{$packageName}',
    'icon' => '',
    'is_uninstallable' => true,
    'name' => '{$packageName}',
    'published_date' => '{$dop}',
    'type' => 'module',
    'version' => '1.0'
);\n\n\$installdefs =";
        $manifest .= var_export($installDefs, true);
        $manifest .= ";";
        $fh = fopen($name . DIRECTORY_SEPARATOR . "manifest.php", "w");
        fwrite($fh, $manifest);
        fclose($fh);
    }


    /**
     * This function copies everything in the current GIT changelist to a directory
     * in my home directory
     *
     * @param $name
     * @return array
     */
    public function copyGIT($name): array
    {
        $this->confirm('Create NEW Package?');
        echo "Deleting {$name}" . PHP_EOL;
        $this->rmTempDir($name);
        if (!mkdir("{$name}", 0777, true)) {
            exit("Cannot create {$name}");
        }
        $fileNames = array();
        //Get all the files currently on the GIT change list
        $command = "git status -s | cut -c4-";
        exec($command, $output, $retVal);
        foreach ($output as $path) {
            echo "Processing '{$path}'" . PHP_EOL;
            $fileNames[] = $path;
            $directory = dirname($path);
            //echo "Root Directory = '{$directory}'".PHP_EOL;
            if (!empty($directory) && $directory !== '.') {
                //if there is a directory structure then create that in the
                // new path
                if (!mkdir("{$name}/{$directory}", 0777, true)) {
                    exit("Can not create {$name}/{$directory}");
                }
            }
            $newPath = "{$name}/{$path}";
            if (!copy($path, $newPath)) {
                exit("Can not copy to  {$name}/{$path}");
            }
        }
        return $fileNames;
    }

    function rmTempDir($path): bool
    {
        if (is_file($path)) {
            return (unlink($path));
        }
        if (!is_dir($path)) {
            return false;
        }
        $status = true;
        $d = dir($path);
        while (($f = $d->read()) !== false) {
            if ($f == "." || $f == "..") {
                continue;
            }
            $status &= $this->rmTempDir("$path/$f");
        }
        $d->close();
        $rmOk = @rmdir($path);
        if ($rmOk === false) {
            return false;
        }
        return ($status);
    }

    /**
     * This allows up to confirm intentions before we do something destructive
     *
     * @param string $command
     * @param bool $found
     */
    private function confirm(string $command, bool $found = false)
    {
        //Check to see if we are doing this comment to a production server
        foreach ($this->serversRequiringConfirmation as $serverName) {
            if (stristr($command, $serverName . ':')) {
                $found = true;
            }
        }

        //if we are dealing with a production server then ask the question
        if ($found) {
            echo $command . PHP_EOL;
            echo "Are you sure you want to do this?  Type 'yes' to continue: ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            fclose($handle);
            if (trim(strtolower($line)) !== 'yes') {
                echo "ABORTING!\n";
                exit(-1);
            }
        }
    }
}
