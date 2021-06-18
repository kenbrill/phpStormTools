<?php
//This is a work in progress but I find it useful

if (PHP_SAPI != "cli") {
    exit('Must be run from the CLI');
}
if ($argc === 1) {
    $scriptName = basename(__FILE__);
    $help = "Help {$scriptName}
            
             {$scriptName} copyGIT [DIRECTORY] [DELETE]
              - Copies everything in the GIT changelog to a directory in my home directory
                [DIRECTORY] = The name of the directory that will be created in my home directory
                [DELETE] = Delete the directory in my home directory and recreate (if left off 
                           it wont delete and will error out if the directory already exists.
                           
              {$scriptName} packageGIT [DIRECTORY] [DELETE]
              - Copies everything in the GIT changelog to a directory in my home directory and
                    adds a manifest.php file to it
                [DIRECTORY] = The name of the directory that will be created in my home directory
                [DELETE] = Delete the directory in my home directory and recreate (if left off 
                           it wont delete and will error out if the directory already exists.";


    exit($help);
}
$command = $argv[1];
$data = $argv[2];
$arguments = $argv[3];
$phpStormTools = new phpStormTools();
$homeDir = $phpStormTools->home;
switch ($command) {
    case 'copyGIT':
        $phpStormTools->copyGIT($homeDir.$data, $arguments);
        break;
    case 'packageGIT':
        $phpStormTools->packageGIT($homeDir.$data, $arguments);
        break;
    default:
        echo "Command not recognised" . PHP_EOL;
        break;
}

class phpStormTools
{
    public $home = "/Users/kbrill/";
    /**
     * @param $name
     * @param $delete
     */
    public function packageGIT($name, $delete)
    {
        $files = $this->copyGIT($name, $delete);
        $this->makeManifest($name, $files);
    }

    private function makeManifest($name, $files)
    {
        echo "Creating Manifest." . PHP_EOL;
        $packageName = basename($name);
        $installDefs = array(
            'id'          => 'CUSTOM' . date("U"),
            'copy'        => array(),
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
     * @param $delete
     * @return array
     */
    public function copyGIT($name, $delete): array
    {
        if (!empty($delete) && file_exists($name)) {
            echo "Deleting {$name}" . PHP_EOL;
            $this->rmTempDir("{$name}");
        }
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

    function rmTempDir($path)
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
}
