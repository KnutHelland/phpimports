<?php

namespace PhpImports;

class DependencyResolver
{

    private $filename;

    private $projectRoot;

    private $autoloader;

    public function __construct($filename)
    {
        $this->filename = $filename;
        $this->projectRoot = self::getProjectRoot($filename);
        $this->autoloader = include $this->projectRoot . '/vendor/autoload.php';
    }

    public function resolve($names)
    {
        $classMap = $this->getClassMap();

        $resolutions = [];

        foreach ($names as $name) {
            foreach (array_keys($classMap) as $className) {
                $search = $name->toString();

                $pos = strpos($className, $search);

                // If the className ends with $search
                if ($pos === (strlen($className) - strlen($search))) {
                    if (!isset($resolutions[$search])) {
                        $resoultions[$search] = [];
                    }

                    $resolution = substr($className, 0, $pos + strlen($name->getFirst()));
                    $resolutions[$search][] = $resolution;
                }
            }
        }

        foreach ($resolutions as &$candidates) {
            $candidates = array_unique($candidates);

            if (count($candidates) > 0) {
                // Match those in this current namespace first
                // Then take the shallowest

                usort($candidates, function ($a, $b) {

                    $aParts = count(explode('\\', $a));
                    $bParts = count(explode('\\', $b));

                    if ($aParts == $bParts) {
                        return $a > $b;
                    }

                    return $aParts < $bParts ? -1 : 1;
                });
            }
        }

        $resolutions = array_map(function ($candidates) {
            return $candidates[0];
        }, $resolutions);

        return $resolutions;
    }

    private static function getProjectRoot($filename)
    {
        $filename = realpath($filename);

        if ($filename == '/') {
            return null;
        }

        if (file_exists(dirname($filename) . '/composer.json')) {
            return dirname($filename);
        }

        return self::getProjectRoot(dirname($filename));
    }

    private function getClassMap()
    {
        return $this->autoloader->getClassMap();
    }
}
